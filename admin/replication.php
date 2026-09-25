<?php

declare(strict_types=1);

require dirname(__DIR__) . '/common/bootstrap.php';

use App\Auth\SessionAuth;
use App\Database\Connection;
use App\Models\User;
use App\Services\RecordService;
use App\Services\ReplicationService;
use App\Support\Crypto;

SessionAuth::requireAuth();

$user = SessionAuth::user();
if (!$user->isStateAdmin()) {
    exit('403');
}

$pdo = Connection::instance();

// Handle external DB config actions
if (($_POST['action'] ?? '') === 'save_db_config') {
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));
    $dbType = (string) ($_POST['db_type'] ?? '');
    $host = trim((string) ($_POST['host'] ?? ''));
    $port = $_POST['port'] !== '' ? (int) $_POST['port'] : null;
    $databaseName = trim((string) ($_POST['database_name'] ?? ''));
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = trim((string) ($_POST['password'] ?? ''));
    $enabled = isset($_POST['enabled']) ? 1 : 0;

    $validTypes = ['mssql', 'oracle', 'postgres', 'mysql'];
    if ($name === '' || !in_array($dbType, $validTypes, true) || $host === '') {
        flash('error', 'Name, database type, and host are required.');
        redirect('admin/replication.php');
    }

    $passwordEnc = $password !== '' ? Crypto::encrypt($password) : null;

    if ($id > 0) {
        // Update existing
        $stmt = $pdo->prepare('
            UPDATE external_db_configs
            SET name = :name, db_type = :db_type, host = :host, port = :port,
                database_name = :database_name, username = :username,
                password_enc = COALESCE(:password_enc, password_enc),
                enabled = :enabled
            WHERE id = :id
        ');
        $stmt->execute([
            'name' => $name, 'db_type' => $dbType, 'host' => $host, 'port' => $port,
            'database_name' => $databaseName, 'username' => $username,
            'password_enc' => $passwordEnc, 'enabled' => $enabled, 'id' => $id
        ]);
        flash('success', 'Database target updated.');
    } else {
        // Insert new
        $stmt = $pdo->prepare('
            INSERT INTO external_db_configs (name, db_type, host, port, database_name, username, password_enc, enabled)
            VALUES (:name, :db_type, :host, :port, :database_name, :username, :password_enc, :enabled)
        ');
        $stmt->execute([
            'name' => $name, 'db_type' => $dbType, 'host' => $host, 'port' => $port,
            'database_name' => $databaseName, 'username' => $username,
            'password_enc' => $passwordEnc, 'enabled' => $enabled
        ]);
        flash('success', 'Database target added.');
    }
    redirect('admin/replication.php');
}

// Handle delete database config
if (($_POST['action'] ?? '') === 'delete_db_config') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $pdo->prepare('DELETE FROM external_db_configs WHERE id = :id');
        $stmt->execute(['id' => $id]);
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true]);
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Config not found']);
        }
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid ID']);
    }
    exit;
}

if (($_GET['action'] ?? '') === 'retry') {
    $affected = (new \App\Services\ReplicationService())->retryFailed();
    flash('success', "Re-queued {$affected} failed job(s).");
    redirect('admin/replication.php');
}

if (($_GET['action'] ?? '') === 'drain') {
    $service = new \App\Services\ReplicationService();
    $count = 0;
    while ($service->processOne(fn() => true)) {
        $count++;
    }
    flash('success', "Processed {$count} job(s).");
    redirect('admin/replication.php');
}

// Handle test data insert
if (($_POST['action'] ?? '') === 'insert_test_data') {
    $output = [];
    $output[] = "=== Starting Test Data Insert ===";
    $output[] = "Time: " . date('Y-m-d H:i:s');

    try {
        // Get a surveyor user
        $stmt = $pdo->query("
            SELECT u.id FROM users u
            JOIN user_roles ur ON ur.user_id = u.id
            JOIN roles r ON r.id = ur.role_id
            WHERE r.code = 'surveyor' AND u.status = 'active' AND u.deleted_at IS NULL
            LIMIT 1
        ");
        $surveyorId = (int) $stmt->fetchColumn();

        if ($surveyorId === 0) {
            throw new \Exception("No active surveyor found.");
        }
        $output[] = "Surveyor ID: $surveyorId";

        // Get the GOVT_BUILDING_SURVEY form
        $stmt = $pdo->prepare("SELECT id, current_version FROM survey_forms WHERE code = 'GOVT_BUILDING_SURVEY' AND status = 'published' LIMIT 1");
        $stmt->execute();
        $form = $stmt->fetch();

        if ($form === false) {
            throw new \Exception("GOVT_BUILDING_SURVEY form not found.");
        }
        $formId = (int) $form['id'];
        $formVersionId = (int) $form['current_version'];
        $output[] = "Form ID: $formId, Version: $formVersionId";

        // Generate test data
        $departments = ['Education', 'Health', 'Police', 'Revenue', 'Rural Development'];
        $buildingCategories = ['Office', 'Education', 'Health', 'Police', 'Court', 'Community', 'Residential', 'Other'];
        $officeTypes = ['Directorate', 'Field Office', 'School', 'Hospital', 'Police Station', 'Court', 'Panchayat Bhavan', 'Residential'];
        $structureTypes = ['RCC', 'Steel', 'Brick Masonry', 'Stone Masonry', 'Mixed'];
        $yesNo = ['yes', 'no'];
        $occupancyStatuses = ['Occupied', 'Partially Occupied', 'Vacant', 'Under Construction', 'Under Repair'];

        $districtId = 20; // Ranchi
        $stmt = $pdo->prepare("SELECT id FROM blocks WHERE district_id = :did AND is_active = 1 ORDER BY name LIMIT 1");
        $stmt->execute(['did' => $districtId]);
        $blockId = (int) ($stmt->fetchColumn() ?? 0);

        $panchayatId = 0;
        if ($blockId) {
            $stmt = $pdo->prepare("SELECT id FROM panchayats WHERE block_id = :bid AND is_active = 1 ORDER BY name LIMIT 1");
            $stmt->execute(['bid' => $blockId]);
            $panchayatId = (int) ($stmt->fetchColumn() ?? 0);
        }

        $villageId = 0;
        if ($panchayatId) {
            $stmt = $pdo->prepare("SELECT id FROM villages WHERE panchayat_id = :pid AND is_active = 1 LIMIT 1");
            $stmt->execute(['pid' => $panchayatId]);
            $villageId = (int) ($stmt->fetchColumn() ?? 0);
        }

        $lat = 23.3441;
        $lng = 85.3096;

        // Generate building_level from department
        $buildingLevelMap = [
            'Education' => 'School',
            'Health' => 'Hospital',
            'Police' => 'Police Station',
            'Revenue' => 'Office',
            'Rural Development' => 'Office',
        ];
        $department = $departments[array_rand($departments)];
        $buildingLevel = $buildingLevelMap[$department] ?? 'Office';
        
        $constructionYear = rand(1980, 2020);
        $buildingAge = date('Y') - $constructionYear;
        $constructionCost = rand(500000, 50000000);
        
        // Dummy photo paths (relative to server upload directory)
        $photoPaths = [
            'photo_front' => 'uploads/survey/photos/test_front_' . date('His') . '.jpg',
            'photo_campus' => 'uploads/survey/photos/test_campus_' . date('His') . '.jpg',
            'photo_back' => 'uploads/survey/photos/test_back_' . date('His') . '.jpg',
        ];
        
        $data = [
            'survey_id' => 'TEST-' . date('Ymd') . '-' . rand(1000, 9999),
            'building_level' => $buildingLevel,
            'building_name' => 'Test Building ' . date('His'),
            'department' => $department,
            'office_name' => 'Test Office ' . date('His'),
            'office_incharge' => 'Test Officer ' . rand(1, 100),
            'contact_mobile' => '9876543210',
            'contact_email' => 'test@example.com',
            'location' => json_encode(['district_id' => $districtId, 'block_id' => $blockId, 'panchayat_id' => $panchayatId, 'village_id' => $villageId]),
            'address' => 'Test Address, Ranchi, Jharkhand',
            'landmark' => 'Test Landmark near NH-33',
            'approach_roads' => 'Pucca Road, 2-lane',
            'construction_year' => (string)$constructionYear,
            'building_age' => (string)$buildingAge,
            'construction_cost' => (string)$constructionCost,
            'physical_status' => 'Good',
            'occupancy_status' => 'Occupied',
            'remarks' => 'Test record inserted via admin panel for replication testing',
            'geo_location' => json_encode(['lat' => $lat, 'lng' => $lng]),
            'photo_front' => $photoPaths['photo_front'],
            'photo_campus' => $photoPaths['photo_campus'],
            'photo_back' => $photoPaths['photo_back'],
            'no_floors' => (string)rand(1, 4),
            'no_rooms' => (string)rand(5, 50),
            'toilets_male' => (string)rand(1, 10),
            'toilets_female' => (string)rand(1, 10),
        ];

        $output[] = "Creating record with data...";
        $output[] = "Form ID: $formId, Version: $formVersionId";

        $recordService = new RecordService();
        $result = $recordService->upsert((int)$surveyorId, [
            'form_id' => $formId,
            'form_version_id' => $formVersionId,
            'record_uuid' => bin2hex(random_bytes(16)),
            'device_id' => 'admin-test-' . time(),
            'status' => 'submitted',
            'answers' => $data,
            'gps' => [
                'latitude' => $lat,
                'longitude' => $lng,
                'accuracy' => 5.0,
                'captured_at' => date('Y-m-d H:i:s'),
            ],
        ]);

        $output[] = "Record created successfully!";
        $output[] = "Record ID: {$result['record_id']}";
        $output[] = "Survey Code: {$result['survey_code']}";
        $output[] = "Record UUID: {$result['record_uuid']}";

        // Enqueue for replication
        $replicationService = new ReplicationService();
        $targets = $pdo->query("SELECT id, name FROM external_db_configs WHERE enabled = 1")->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($targets)) {
            $output[] = "WARNING: No enabled external database targets configured!";
        } else {
            foreach ($targets as $target) {
                $replicationService->enqueue('survey_record', (string)$result['record_id'], 'upsert', [
                    'entity_type' => 'survey_record',
                    'operation' => 'upsert',
                    'form_id' => $formId,
                    'data' => $data,
                    'record_id' => $result['record_id'],
                ], (int)$target['id']);
                $output[] = "Enqueued for replication to target: {$target['name']} (ID: {$target['id']})";
            }
        }

        $output[] = "=== Test Data Insert Completed Successfully ===";

    } catch (\Throwable $e) {
        $output[] = "ERROR: " . $e->getMessage();
        $output[] = "File: " . $e->getFile() . ":" . $e->getLine();
        $output[] = "Trace: " . $e->getTraceAsString();
    }

    $output[] = "=== End ===";
    $output[] = "Time: " . date('Y-m-d H:i:s');

    // Store output in session for display
    $_SESSION['test_insert_output'] = $output;
    flash('success', 'Test data insert completed. See debug output below.');
    redirect('admin/replication.php');
}

$queue = $pdo->query(
    'SELECT id, entity_type, entity_id, operation, status, attempt_count, error_message, created_at, processed_at
     FROM replication_queue ORDER BY id DESC LIMIT 30'
)->fetchAll();
$configs = $pdo->query('SELECT id, name, db_type, host, port, database_name, username, enabled, last_success_at FROM external_db_configs ORDER BY id')->fetchAll();

// Prepare configs for JavaScript
$configsJs = json_encode($configs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

ob_start(); ?>
<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <h1 class="page-title mb-1"><i class="bi bi-arrow-repeat me-2"></i>Replication Monitor</h1>
        <div class="page-subtitle">Monitor external database sync and replication queue</div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="replication.php?action=retry" class="btn btn-sm btn-outline-danger" onclick="return confirm('Re-queue all failed jobs?')"><i class="bi bi-arrow-counterclockwise me-1"></i>Retry Failed</a>
        <a href="replication.php?action=drain" class="btn btn-sm btn-primary" onclick="return confirm('Drain the queue now?')"><i class="bi bi-play-fill me-1"></i>Drain Queue</a>
        <form method="post" action="replication.php" style="display:inline;" onsubmit="return confirm('Insert a test survey record and enqueue for replication?')">
            <input type="hidden" name="action" value="insert_test_data">
            <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-flask me-1"></i>Insert Test Data</button>
        </form>
    </div>
</div>

<?php if (isset($_SESSION['test_insert_output'])): ?>
<div class="card mb-3 border-warning">
    <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
        <span><i class="bi bi-bug me-2"></i>Test Data Insert Debug Output</span>
        <button type="button" class="btn btn-sm btn-outline-dark" onclick="this.closest('.card').remove()">Dismiss</button>
    </div>
    <div class="card-body p-0">
        <pre class="mb-0 p-3 bg-dark text-light small" style="max-height: 400px; overflow: auto;"><?= implode("\n", $_SESSION['test_insert_output']) ?></pre>
    </div>
</div>
<?php unset($_SESSION['test_insert_output']); endif; ?>

<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>External Database Targets</span>
        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#dbConfigModal" onclick="resetDbConfigForm()">
            <i class="bi bi-plus-circle me-1"></i>Add Target
        </button>
    </div>
    <div class="card-body table-responsive p-0">
        <table class="table table-sm table-hover align-middle mb-0 data-table">
            <thead><tr><th>Name</th><th>Type</th><th>Host</th><th>Port</th><th>Database</th><th>Username</th><th>Status</th><th>Last Success</th><th>Actions</th></tr></thead>
            <tbody>
<?php foreach ($configs as $c): ?>
                <tr>
                    <td><?= e($c['name']) ?></td>
                    <td><span class="badge bg-secondary text-uppercase"><?= e($c['db_type']) ?></span></td>
                    <td><?= e($c['host']) ?></td>
                    <td><?= $c['port'] ? e((string) $c['port']) : '—' ?></td>
                    <td><?= e((string) ($c['database_name'] ?? '—')) ?></td>
                    <td><?= e((string) ($c['username'] ?? '—')) ?></td>
                    <td><?= $c['enabled'] ? '<span class="badge bg-success">enabled</span>' : '<span class="badge bg-secondary">disabled</span>' ?></td>
                    <td class="text-muted small"><?= $c['last_success_at'] ? date('d M H:i', strtotime((string) $c['last_success_at'])) : '—' ?></td>
                    <td>
                        <button type="button" class="btn btn-sm btn-outline-primary edit-config-btn" data-config-id="<?= (int) $c['id'] ?>">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger delete-config-btn" data-config-id="<?= (int) $c['id'] ?>" data-config-name="<?= e($c['name']) ?>">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal for Add/Edit Database Config -->
<div class="modal fade" id="dbConfigModal" tabindex="-1" aria-labelledby="dbConfigModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" action="replication.php">
                <input type="hidden" name="action" value="save_db_config">
                <input type="hidden" name="id" id="dbConfigId" value="0">
                <div class="modal-header">
                    <h5 class="modal-title" id="dbConfigModalLabel">Add External Database Target</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="dbConfigName" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Database Type <span class="text-danger">*</span></label>
                            <select name="db_type" id="dbConfigType" class="form-select" required>
                                <option value="">— Select type —</option>
                                <option value="mssql">Microsoft SQL Server</option>
                                <option value="oracle">Oracle</option>
                                <option value="postgres">PostgreSQL</option>
                                <option value="mysql">MySQL</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Host <span class="text-danger">*</span></label>
                            <input type="text" name="host" id="dbConfigHost" class="form-control" required placeholder="e.g., 192.168.1.100 or server\\instance">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Port</label>
                            <input type="number" name="port" id="dbConfigPort" class="form-control" placeholder="Optional">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Database Name</label>
                            <input type="text" name="database_name" id="dbConfigDatabase" class="form-control" placeholder="Optional">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" id="dbConfigUsername" class="form-control" placeholder="Optional">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" id="dbConfigPassword" class="form-control" placeholder="Leave blank to keep existing">
                            <div class="form-text">Only required for new entries or when changing password.</div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check mt-4">
                                <input type="checkbox" name="enabled" id="dbConfigEnabled" class="form-check-input" value="1" checked>
                                <label class="form-check-label" for="dbConfigEnabled">Enabled</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">Replication Queue (last 30)</div>
    <div class="card-body table-responsive p-0">
        <table class="table table-sm table-hover align-middle mb-0 data-table">
            <thead><tr><th>#</th><th>Entity</th><th>Op</th><th>Status</th><th>Attempts</th><th>Error</th><th>Created</th></tr></thead>
            <tbody>
            <?php foreach ($queue as $q): ?>
                <tr>
                    <td><?= (int) $q['id'] ?></td>
                    <td><code><?= e($q['entity_type']) ?> #<?= e($q['entity_id']) ?></code></td>
                    <td><span class="badge bg-info text-dark"><?= e($q['operation']) ?></span></td>
                    <td>
                        <?php
                        $b = ['pending' => 'secondary', 'processing' => 'warning', 'success' => 'success', 'failed' => 'danger'][$q['status']] ?? 'secondary';
                        ?>
                        <span class="badge bg-<?= $b ?>"><?= e($q['status']) ?></span>
                    </td>
                    <td><?= (int) $q['attempt_count'] ?></td>
                    <td class="text-muted small" title="<?= e((string) $q['error_message']) ?>"><?= e(mb_strimwidth((string) ($q['error_message'] ?? '—'), 0, 40, '…')) ?></td>
                    <td class="text-muted small"><?= date('d M H:i', strtotime((string) $q['created_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php $content = ob_get_clean();

echo view('admin_layout', [
    'title'      => 'Replication Monitor',
    'content'    => $content,
    'user'       => $user,
    'page'       => 'replication',
    'breadcrumb' => [['Admin', 'dashboard.php'], ['Replication', '']],
    'extraScripts' => <<<JS
<script>
// Configs data loaded from PHP
const dbConfigs = {$configsJs};

// Build a lookup map for quick access
const dbConfigMap = new Map(dbConfigs.map(c => [c.id, c]));

function resetDbConfigForm() {
    document.getElementById('dbConfigModalLabel').textContent = 'Add External Database Target';
    document.getElementById('dbConfigId').value = '0';
    document.getElementById('dbConfigName').value = '';
    document.getElementById('dbConfigType').value = '';
    document.getElementById('dbConfigHost').value = '';
    document.getElementById('dbConfigPort').value = '';
    document.getElementById('dbConfigDatabase').value = '';
    document.getElementById('dbConfigUsername').value = '';
    document.getElementById('dbConfigPassword').value = '';
    document.getElementById('dbConfigEnabled').checked = true;
}

function editDbConfig(configId) {
    const config = dbConfigMap.get(parseInt(configId, 10));
    if (!config) {
        console.error('Config not found:', configId);
        return;
    }
    document.getElementById('dbConfigModalLabel').textContent = 'Edit External Database Target';
    document.getElementById('dbConfigId').value = config.id;
    document.getElementById('dbConfigName').value = config.name;
    document.getElementById('dbConfigType').value = config.db_type;
    document.getElementById('dbConfigHost').value = config.host;
    document.getElementById('dbConfigPort').value = config.port !== null ? config.port : '';
    document.getElementById('dbConfigDatabase').value = config.database_name !== null ? config.database_name : '';
    document.getElementById('dbConfigUsername').value = config.username !== null ? config.username : '';
    document.getElementById('dbConfigPassword').value = '';
    document.getElementById('dbConfigEnabled').checked = !!config.enabled;
    
    // Show the modal
    const modal = new bootstrap.Modal(document.getElementById('dbConfigModal'));
    modal.show();
}

function deleteDbConfig(configId) {
    fetch('replication.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'delete_db_config', id: configId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Failed to delete'));
        }
    })
    .catch(err => {
        console.error('Delete failed:', err);
        alert('Delete failed: ' + err.message);
    });
}

// Event delegation for edit buttons
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.edit-config-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const configId = this.dataset.configId;
            if (configId) {
                editDbConfig(configId);
            }
        });
    });

    // Event delegation for delete buttons
    document.querySelectorAll('.delete-config-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const configId = this.dataset.configId;
            const configName = this.dataset.configName;
            if (configId && confirm('Delete database target "' + configName + '"? This cannot be undone.')) {
                deleteDbConfig(configId);
            }
        });
    });
});
</script>
JS
]);