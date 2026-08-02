<?php

declare(strict_types=1);

require dirname(__DIR__) . '/common/bootstrap.php';

use App\Auth\SessionAuth;
use App\Database\Connection;

SessionAuth::requireAuth();
SessionAuth::requirePermission('gis.view');

$user = SessionAuth::user();
$pdo = Connection::instance();
$forms = array_values(array_filter(
    $pdo->query('SELECT id, title FROM survey_forms WHERE status = "published" ORDER BY title')->fetchAll(),
    fn(array $f) => $user->canAccessForm((int) $f['id'])
));

ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-geo-alt me-2"></i>GIS Dashboard</h4>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small mb-1">Form</label>
                <select id="gisForm" class="form-select form-select-sm">
                    <option value="">All Forms</option>
                    <?php foreach ($forms as $f): ?>
                    <option value="<?= (int) $f['id'] ?>"><?= e($f['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Status</label>
                <select id="gisStatus" class="form-select form-select-sm">
                    <option value="">All Status</option>
                    <option value="submitted">Submitted</option>
                    <option value="block_verified">Block Verified</option>
                    <option value="district_verified">District Verified</option>
                    <option value="approved">Approved</option>
                    <option value="published">Published</option>
                    <option value="rejected">Rejected</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Layer</label>
                <select id="gisLayer" class="form-select form-select-sm">
                    <option value="markers">Markers</option>
                    <option value="heatmap">Heatmap</option>
                    <option value="cluster">Clusters</option>
                </select>
            </div>
            <div class="col-md-3">
                <button class="btn btn-sm btn-primary w-100" id="btnRefresh"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</button>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <div id="map" style="height:600px; border-radius:.375rem; background:#e8ecef;"></div>
        <div class="mt-2 text-muted small" id="gisStats">Loading…</div>
    </div>
</div>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet.heat@0.2.0/dist/leaflet-heat.js"></script>
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css">

<script>
const map = L.map('map').setView([24.8781, 78.6298], 5);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap contributors'
}).addTo(map);

let heatLayer = null;
let clusterLayer = null;
let markerLayer = null;

function statusBadge(s) {
    const colors = { submitted:'#0dcaf0', block_verified:'#0d6efd', district_verified:'#ffc107', approved:'#198754', published:'#198754', rejected:'#dc3545' };
    return colors[s] || '#6c757d';
}

async function load() {
    const formId = document.getElementById('gisForm').value;
    const status = document.getElementById('gisStatus').value;
    const params = new URLSearchParams();
    if (formId) params.set('form_id', formId);
    if (status) params.set('status', status);

    const res = await fetch('<?= url('api/index.php') ?>' + '?route=/v1/gis/points&' + params.toString(), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    // Fallback: direct API call with token not available in browser session; use MIS-backed proxy below.
    const data = await loadPoints(formId, status);
    render(data);
}

async function loadPoints(formId, status) {
    const q = new URLSearchParams();
    if (formId) q.set('form_id', formId);
    if (status) q.set('status', status);
    const res = await fetch('gis_data.php?' + q.toString());
    return res.json();
}

function render(data) {
    if (heatLayer) { map.removeLayer(heatLayer); heatLayer = null; }
    if (clusterLayer) { map.removeLayer(clusterLayer); clusterLayer = null; }
    if (markerLayer) { map.removeLayer(markerLayer); markerLayer = null; }

    const layer = document.getElementById('gisLayer').value;
    const points = data.points || [];

    if (layer === 'heatmap') {
        const pts = points.filter(p => p.latitude && p.longitude).map(p => [parseFloat(p.latitude), parseFloat(p.longitude), 1]);
        if (pts.length) {
            heatLayer = L.heatLayer(pts, { radius: 25, blur: 15, maxZoom: 17 }).addTo(map);
        }
    } else if (layer === 'cluster') {
        clusterLayer = L.markerClusterGroup();
        points.forEach(p => {
            if (!p.latitude || !p.longitude) return;
            const m = L.marker([parseFloat(p.latitude), parseFloat(p.longitude)])
                .bindPopup(`<b>${esc(p.form_title)}</b><br>Status: ${p.status}<br>#${p.record_uuid?.slice(0,8)}`);
            clusterLayer.addLayer(m);
        });
        map.addLayer(clusterLayer);
    } else {
        markerLayer = L.layerGroup();
        points.forEach(p => {
            if (!p.latitude || !p.longitude) return;
            const m = L.circleMarker([parseFloat(p.latitude), parseFloat(p.longitude)], {
                radius: 7, color: statusBadge(p.status), fillColor: statusBadge(p.status), fillOpacity: 0.7
            }).bindPopup(`<b>${esc(p.form_title)}</b><br>Status: ${p.status}<br>ID: #${p.id}`);
            markerLayer.addLayer(m);
        });
        map.addLayer(markerLayer);
    }

    document.getElementById('gisStats').textContent = `${points.length} survey points shown.`;
    if (points.length) {
        map.fitBounds(points.filter(p => p.latitude && p.longitude).map(p => [p.latitude, p.longitude]), { padding: [20,20] });
    }
}

function esc(s) {
    const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML;
}

document.getElementById('btnRefresh').addEventListener('click', load);
document.getElementById('gisLayer').addEventListener('change', load);
load();
</script>
<?php $content = ob_get_clean();

echo view('layout', [
    'title'   => 'GIS Dashboard',
    'content' => $content,
    'user'    => $user,
    'page'    => 'gis',
]);
