<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use RuntimeException;

/**
 * Administrative hierarchy (District/Block/Panchayat/Village) management.
 */
final class LocationService
{
    public function districts(int $stateId = 0, bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM districts WHERE 1=1';
        $params = [];
        if ($stateId > 0) {
            $sql .= ' AND state_id = :s';
            $params['s'] = $stateId;
        }
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY name';
        $stmt = Connection::instance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function blocks(int $districtId, bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM blocks WHERE district_id = :d';
        $params = ['d' => $districtId];
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY name';
        $stmt = Connection::instance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function panchayats(int $blockId, bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM panchayats WHERE block_id = :b';
        $params = ['b' => $blockId];
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY name';
        $stmt = Connection::instance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function villages(int $panchayatId, bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM villages WHERE panchayat_id = :p';
        $params = ['p' => $panchayatId];
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY name';
        $stmt = Connection::instance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function tree(): array
    {
        $tree = [];
        foreach ($this->districts() as $district) {
            $district['blocks'] = [];
            foreach ($this->blocks((int) $district['id']) as $block) {
                $block['panchayats'] = [];
                foreach ($this->panchayats((int) $block['id']) as $panchayat) {
                    $panchayat['villages'] = $this->villages((int) $panchayat['id']);
                    $block['panchayats'][] = $panchayat;
                }
                $district['blocks'][] = $block;
            }
            $tree[] = $district;
        }
        return $tree;
    }

    /** CSV import: columns = district, block, panchayat, village. Auto-creates hierarchy. */
    public function importCsv(string $path, int $userId): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('File not found.');
        }
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException('Unable to open file.');
        }

        $header = fgetcsv($handle);
        $pdo = Connection::instance();
        $pdo->beginTransaction();

        $stats = ['imported' => 0, 'skipped' => 0, 'errors' => 0];
        try {
            while (($row = fgetcsv($handle)) !== false) {
                if ($row === null || array_filter($row) === []) {
                    continue;
                }
                [$districtName, $blockName, $panchayatName, $villageName] = array_pad($row, 4, '');
                try {
                    $districtId = $this->ensureDistrict(trim((string) $districtName));
                    $blockId = $this->ensureBlock($districtId, trim((string) $blockName));
                    $panchayatId = $this->ensurePanchayat($blockId, trim((string) $panchayatName));
                    if (trim((string) $villageName) !== '') {
                        $this->ensureVillage($panchayatId, trim((string) $villageName));
                    }
                    $stats['imported']++;
                } catch (\Throwable) {
                    $stats['errors']++;
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        } finally {
            fclose($handle);
        }
        return $stats;
    }

    private function ensureDistrict(string $name): int
    {
        $pdo = Connection::instance();
        $stmt = $pdo->prepare('SELECT id FROM districts WHERE name = :n LIMIT 1');
        $stmt->execute(['n' => $name]);
        $id = (int) ($stmt->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }
        $pdo->prepare('INSERT INTO districts (state_id, code, name) VALUES (1, :c, :n)')
            ->execute(['c' => 'D' . time() . random_int(10, 99), 'n' => $name]);
        return (int) $pdo->lastInsertId();
    }

    private function ensureBlock(int $districtId, string $name): int
    {
        $pdo = Connection::instance();
        $stmt = $pdo->prepare('SELECT id FROM blocks WHERE district_id = :d AND name = :n LIMIT 1');
        $stmt->execute(['d' => $districtId, 'n' => $name]);
        $id = (int) ($stmt->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }
        $pdo->prepare('INSERT INTO blocks (district_id, code, name) VALUES (:d, :c, :n)')
            ->execute(['d' => $districtId, 'c' => 'B' . time() . random_int(10, 99), 'n' => $name]);
        return (int) $pdo->lastInsertId();
    }

    private function ensurePanchayat(int $blockId, string $name): int
    {
        $pdo = Connection::instance();
        $stmt = $pdo->prepare('SELECT id FROM panchayats WHERE block_id = :b AND name = :n LIMIT 1');
        $stmt->execute(['b' => $blockId, 'n' => $name]);
        $id = (int) ($stmt->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }
        $pdo->prepare('INSERT INTO panchayats (block_id, code, name) VALUES (:b, :c, :n)')
            ->execute(['b' => $blockId, 'c' => 'P' . time() . random_int(10, 99), 'n' => $name]);
        return (int) $pdo->lastInsertId();
    }

    private function ensureVillage(int $panchayatId, string $name): int
    {
        $pdo = Connection::instance();
        $stmt = $pdo->prepare('SELECT id FROM villages WHERE panchayat_id = :p AND name = :n LIMIT 1');
        $stmt->execute(['p' => $panchayatId, 'n' => $name]);
        $id = (int) ($stmt->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }
        $pdo->prepare('INSERT INTO villages (panchayat_id, code, name) VALUES (:p, :c, :n)')
            ->execute(['p' => $panchayatId, 'c' => 'V' . time() . random_int(10, 99), 'n' => $name]);
        return (int) $pdo->lastInsertId();
    }

    public function destroy(string $type, int $id): void
    {
        $tables = ['district' => 'districts', 'block' => 'blocks', 'panchayat' => 'panchayats', 'village' => 'villages'];
        $table = $tables[$type] ?? null;
        if ($table === null) {
            throw new RuntimeException('Invalid entity type.');
        }
        $stmt = Connection::instance()->prepare("DELETE FROM {$table} WHERE id = :id");
        $stmt->execute(['id' => $id]);
    }
}
