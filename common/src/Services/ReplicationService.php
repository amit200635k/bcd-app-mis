<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Support\ExternalDbConnector;
use RuntimeException;

/**
 * Queue-based replication to external databases.
 * App writes only to MySQL; the replication service drains the queue
 * and applies changes to target databases with retry/failure recovery.
 */
final class ReplicationService
{
    /**
     * Enqueue a change for replication.
     */
    public function enqueue(string $entityType, string $entityId, string $operation, array $payload, ?int $targetDbId = null): void
    {
        Connection::instance()->prepare(
            'INSERT INTO replication_queue (entity_type, entity_id, operation, payload_json, target_db_id)
             VALUES (:et, :ei, :op, :pj, :td)'
        )->execute([
            'et' => $entityType,
            'ei' => $entityId,
            'op' => $operation,
            'pj' => json_encode($payload),
            'td' => $targetDbId,
        ]);
    }

    /** @return array<string,mixed>|null next pending job */
    public function nextPending(): ?array
    {
        $pdo = Connection::instance();
        $stmt = $pdo->query(
            'SELECT * FROM replication_queue WHERE status = "pending" ORDER BY id LIMIT 1'
        );
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $pdo->prepare('UPDATE replication_queue SET status = "processing", attempt_count = attempt_count + 1 WHERE id = :id')
            ->execute(['id' => $row['id']]);
        $row['payload'] = json_decode((string) $row['payload_json'], true);
        return $row;
    }

    /**
     * Process a single job by applying it to the target database.
     */
    public function processOne(int $maxAttempts = 5): bool
    {
        $job = $this->nextPending();
        if ($job === null) {
            return false;
        }

        $pdo = Connection::instance();
        try {
            $ok = $this->applyToTarget($job['payload'], $job['target_db_id'] ? (int) $job['target_db_id'] : null);
            if ($ok) {
                $pdo->prepare('UPDATE replication_queue SET status = "success", processed_at = NOW() WHERE id = :id')
                    ->execute(['id' => $job['id']]);
            } else {
                throw new RuntimeException('Replication apply returned false.');
            }
        } catch (\Throwable $e) {
            $failed = (int) $job['attempt_count'] >= $maxAttempts;
            $pdo->prepare(
                'UPDATE replication_queue SET status = :s, error_message = :m WHERE id = :id'
            )->execute([
                's' => $failed ? 'failed' : 'pending',
                'm' => substr($e->getMessage(), 0, 500),
                'id' => $job['id'],
            ]);
        }
        return true;
    }

    /**
     * Apply a payload to the target database.
     */
    private function applyToTarget(array $payload, ?int $targetDbId): bool
    {
        if ($targetDbId === null) {
            // No specific target - just mark success (internal only)
            return true;
        }

        // Get target database config
        $pdo = Connection::instance();
        $stmt = $pdo->prepare('
            SELECT id, name, db_type, host, port, database_name, username, password_enc, enabled
            FROM external_db_configs WHERE id = :id
        ');
        $stmt->execute(['id' => $targetDbId]);
        $config = $stmt->fetch();

        if ($config === false || !$config['enabled']) {
            throw new RuntimeException('Target database not found or disabled.');
        }

        // Decrypt password
        $password = \App\Support\Crypto::decrypt($config['password_enc']);
        if ($password === false) {
            throw new RuntimeException('Failed to decrypt password for target database.');
        }

        // Build config array for connector
        $connectorConfig = [
            'db_type' => $config['db_type'],
            'host' => $config['host'],
            'port' => $config['port'] ?? null,
            'database_name' => $config['database_name'],
            'username' => $config['username'],
            'password' => $password,
        ];

        // Connect and apply
        $connector = new ExternalDbConnector($connectorConfig);

        $entityType = $payload['entity_type'] ?? '';
        $operation = $payload['operation'] ?? '';

        if ($entityType === 'survey_record') {
            return $this->applySurveyRecord($connector, $payload, $operation);
        }

        // Default: just log and return true
        return true;
    }

    /**
     * Apply survey record to target database (MSSQL/ArcGIS).
     */
    private function applySurveyRecord(ExternalDbConnector $connector, array $payload, string $operation): bool
    {
        $data = $payload['data'] ?? [];
        $formId = $data['form_id'] ?? 0;

        if ($formId === 0) {
            return true; // Skip if no form
        }

        // Get form code to identify the target table
        $pdo = Connection::instance();
        $stmt = $pdo->prepare('SELECT code FROM survey_forms WHERE id = :id');
        $stmt->execute(['id' => $formId]);
        $formCode = $stmt->fetchColumn() ?? '';

        if ($formCode === '') {
            return true;
        }

        // Map form code to target table name
        $tableName = $this->getTargetTableName($formCode);
        if ($tableName === '') {
            return true; // No mapping, skip
        }

        $recordId = $data['record_id'] ?? $data['id'] ?? 0;
        if ($recordId === 0) {
            return true; // No record ID
        }

        $connector->beginTransaction();
        try {
            if ($operation === 'delete') {
                $connector->execute("DELETE FROM building_survey_data WHERE OBJECTID = :id", ['id' => $recordId]);
            } else {
                // Get next OBJECTID from SDE
                $objectId = $this->getNextObjectId($connector);

                // Build WKT point for Shape column
                $shapeWkt = $this->buildShapeWkt($data);

                // Get photo paths from survey_images table
                $photoPaths = $this->getPhotoPaths($recordId);

                // Map form data to ArcGIS columns
                $fields = $this->mapToArcGisColumns($data, $photoPaths, $recordId);

                if ($operation === 'delete') {
                    $connector->execute("DELETE FROM building_survey_data WHERE OBJECTID = :id", ['id' => $recordId]);
                } elseif ($operation === 'update' || $operation === 'upsert') {
                    // Check if record exists
                    $exists = $this->recordExists($connector, $recordId);
                    if ($exists) {
                        // Update existing record
                        $setParts = [];
                        foreach ($fields as $key => $value) {
                            if ($key !== 'OBJECTID') {
                                $setParts[] = "{$key} = :{$key}";
                            }
                        }
                        if ($setParts !== []) {
                            $fields['OBJECTID'] = $recordId;
                            $sql = "UPDATE building_survey_data SET " . implode(', ', $setParts) . " WHERE OBJECTID = :OBJECTID";
                            $connector->execute($sql, $fields);
                        }
                    } else {
                        // Insert new record with OBJECTID and Shape
                        $fields['OBJECTID'] = $objectId;
                        $columns = implode(', ', array_keys($fields));
                        $placeholders = ':' . implode(', :', array_keys($fields));
                        
                        // Handle Shape column separately with geometry::STGeomFromText
                        $shapeWkt = $this->buildShapeWkt($fields);
                        unset($fields['Shape']); // Will be handled separately
                        
                        $columns = implode(', ', array_keys($fields));
                        $placeholders = ':' . implode(', :', array_keys($fields));
                        
                        $sql = "INSERT INTO building_survey_data ({$columns}, Shape) VALUES ({$placeholders}, geometry::STGeomFromText(:shape_wkt, 4326))";
                        $fields['shape_wkt'] = $this->buildShapeWkt($fields);
                        $connector->execute($sql, $fields);
                    }
                }
            }

            $connector->commit();
            return true;
        } catch (\Throwable $e) {
            $connector->rollBack();
            throw $e;
        }
    }

    /**
     * Get next OBJECTID from SDE.
     */
    private function getNextObjectId(ExternalDbConnector $connector): int
    {
        $pdo = $connector->getPdo();
        $objectId = 0;
        
        $stmt = $pdo->prepare("{CALL [sde].[next_rowid](?, ?, ?)}");
        $params = [
            ['SDE', SQLSRV_PARAM_IN],
            ['building_survey_data', SQLSRV_PARAM_IN],
            [&$objectId, SQLSRV_PARAM_OUT, SQLSRV_PHPTYPE_INT, SQLSRV_SQLTYPE_INT]
        ];
        
        $stmt->execute($params);
        
        // Consume all result sets so the OUTPUT parameter is available
        while (true) {
            $nextResult = $stmt->nextResult();
            if ($nextResult === null) {
                break; // No more result sets
            }
            if ($nextResult === false) {
                throw new RuntimeException(
                    "Error while completing sde.next_rowid: " .
                    print_r($pdo->errorInfo(), true)
                );
            }
        }
        
        if ($objectId <= 0) {
            throw new RuntimeException("sde.next_rowid did not return a valid OBJECTID.");
        }
        
        return $objectId;
    }

    /**
     * Build WKT point for Shape column from GPS data.
     */
    private function buildShapeWkt(array $data): ?string
    {
        // Try multiple possible GPS field locations
        $lat = null;
        $lng = null;
        
        // From gps_point JSON
        if (isset($data['gps_point']) && is_array($data['gps_point'])) {
            $lat = $data['gps_point']['lat'] ?? $data['gps_point']['latitude'] ?? null;
            $lng = $data['gps_point']['lng'] ?? $data['gps_point']['longitude'] ?? null;
        }
        
        // From location JSON (geo_location)
        if ($lat === null && isset($data['geo_location']) && is_array($data['geo_location'])) {
            $lat = $data['geo_location']['lat'] ?? $data['geo_location']['latitude'] ?? null;
            $lng = $data['geo_location']['lng'] ?? $data['geo_location']['longitude'] ?? null;
        }
        
        // From location cascade
        if ($lat === null && isset($data['location']) && is_array($data['location'])) {
            $lat = $data['location']['latitude'] ?? null;
            $lng = $data['location']['longitude'] ?? null;
        }
        
        // Direct latitude/longitude fields
        if ($lat === null) {
            $lat = $data['latitude'] ?? null;
        }
        if ($lng === null) {
            $lng = $data['longitude'] ?? null;
        }

        if ($lat !== null && $lng !== null && is_numeric($lat) && is_numeric($lng)) {
            // WKT order is Longitude Latitude = X Y
            return sprintf('POINT(%.8F %.8F)', (float)$lng, (float)$lat);
        }
        
        return null;
    }

    /**
     * Get photo paths from survey_images table for the record.
     */
    private function getPhotoPaths(int $recordId): array
    {
        $pdo = Connection::instance();
        $stmt = $pdo->prepare("
            SELECT sa.field_key, si.file_path
            FROM survey_images si
            JOIN survey_answers sa ON sa.id = si.answer_id
            WHERE si.record_id = :rid 
              AND sa.field_key IN ('photo_front','photo_entrance','photo_left','photo_rear','photo_campus','photo_back')
            ORDER BY si.id
        ");
        $stmt->execute(['rid' => $recordId]);
        $paths = [];
        foreach ($stmt->fetchAll() as $row) {
            $fieldKey = $row['field_key'];
            $filePath = $row['file_path'];
            
            // Map form field keys to ArcGIS column names
            $map = [
                'photo_front' => 'photo_front',
                'photo_entrance' => 'photo_campus',
                'photo_left' => 'photo_campus',
                'photo_rear' => 'photo_back',
                'photo_campus' => 'photo_campus',
                'photo_back' => 'photo_back',
            ];
            
            if (isset($map[$row['field_key']])) {
                $targetKey = $map[$row['field_key']];
                // Only set if not already set (first photo wins)
                if (!isset($paths[$targetKey]) && !empty($filePath)) {
                    $paths[$targetKey] = $filePath;
                }
            }
        }
        
        // Ensure all three keys exist
        $defaults = ['photo_front' => '', 'photo_campus' => '', 'photo_back' => ''];
        return array_merge($defaults, $paths);
    }

    /**
     * Map form data to ArcGIS building_survey_data columns.
     */
    private function mapToArcGisColumns(array $data, array $photoPaths, int $recordId): array
    {
        $constructionYear = (int)($data['construction_year'] ?? date('Y'));
        $buildingAge = date('Y') - $constructionYear;
        
        $department = $data['department'] ?? '';
        $buildingLevelMap = [
            'Education' => 'School',
            'Health' => 'Hospital',
            'Police' => 'Police Station',
            'Revenue' => 'Office',
            'Rural Development' => 'Office',
        ];
        $buildingLevel = $buildingLevelMap[$department] ?? 'Office';

        $physicalStatus = $data['current_condition'] ?? $data['structural_condition'] ?? 'Good';
        $occupancyStatus = $data['occupancy_status'] ?? 'Occupied';

        // Get lat/lng for geo_location
        $lat = $data['latitude'] ?? $data['gps_point']['lat'] ?? $data['geo_location']['lat'] ?? $data['location']['latitude'] ?? 0;
        $lng = $data['longitude'] ?? $data['gps_point']['lng'] ?? $data['geo_location']['lng'] ?? $data['location']['longitude'] ?? 0;

        // Get location JSON
        $locationJson = $data['location'] ?? [];
        if (!is_array($locationJson)) {
            $locationJson = [];
        }

        return [
            'survey_id' => $data['survey_id'] ?? '',
            'building_level' => $buildingLevelMap[$department] ?? 'Office',
            'building_name' => $data['building_name'] ?? '',
            'department' => $data['department'] ?? '',
            'office_name' => $data['controlling_authority'] ?? $data['office_name'] ?? '',
            'office_incharge' => $data['head_of_office'] ?? $data['office_incharge'] ?? '',
            'contact_mobile' => $data['contact_number'] ?? $data['contact_mobile'] ?? '',
            'contact_email' => $data['email'] ?? $data['contact_email'] ?? '',
            'location' => json_encode($locationJson),
            'address' => $data['address'] ?? '',
            'landmark' => $data['landmark'] ?? '',
            'approach_roads' => $data['approach_roads'] ?? $data['nearest_road'] ?? '',
            'construction_year' => (string)$constructionYear,
            'building_age' => (string)$buildingAge,
            'construction_cost' => $data['construction_cost'] ?? '',
            'physical_status' => $physicalStatus,
            'occupancy_status' => $occupancyStatus,
            'remarks' => $data['general_remarks'] ?? $data['remarks'] ?? '',
            'geo_location' => json_encode(['lat' => (float)$lat, 'lng' => (float)$lng]),
            'photo_front' => $photoPaths['photo_front'] ?? '',
            'photo_campus' => $photoPaths['photo_campus'] ?? '',
            'photo_back' => $photoPaths['photo_back'] ?? '',
            'no_floors' => (string)($data['no_floors'] ?? $data['num_floors'] ?? 1),
            'no_rooms' => (string)($data['no_rooms'] ?? $data['num_rooms'] ?? 1),
            'toilets_male' => (string)($data['toilets_male'] ?? (($data['num_toilets'] ?? 2) / 2)),
            'toilets_female' => (string)($data['toilets_female'] ?? (($data['num_toilets'] ?? 2) / 2)),
        ];
    }

    /**
     * Check if record exists in target table.
     */
    private function recordExists(ExternalDbConnector $connector, int $recordId): bool
    {
        $row = $connector->fetchOne("SELECT 1 FROM building_survey_data WHERE OBJECTID = :id", ['id' => $recordId]);
        return $row !== null;
    }

    /**
     * Get target table name based on form code.
     */
    private function getTargetTableName(string $formCode): string
    {
        return match ($formCode) {
            'GOVT_BUILDING_SURVEY' => 'building_survey_data',
            default => '',
        };
    }

    public function stats(): array
    {
        return Connection::instance()->query(
            'SELECT status, COUNT(*) AS c FROM replication_queue GROUP BY status'
        )->fetchAll();
    }

    public function retryFailed(): int
    {
        return (int) Connection::instance()->exec(
            'UPDATE replication_queue SET status = "pending", error_message = NULL WHERE status = "failed"'
        );
    }
}