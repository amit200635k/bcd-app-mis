<?php
require 'D:/Xampp/htdocs/bcd-app/common/bootstrap.php';
use App\Database\Connection;

$pdo = Connection::instance();

// Test the exact query that getChartData uses for block level with parent district_id=20
$parentId = 20;
$formId = 66;

$dataSql = "
    SELECT 
        JSON_UNQUOTE(JSON_EXTRACT(sa.value_json, '\$.block_id')) AS unit_id,
        blocks.name AS unit_name,
        COUNT(r.id) AS total,
        SUM(r.status = 'submitted') AS submitted,
        SUM(r.status IN ('block_verified','district_verified')) AS verified,
        SUM(r.status IN ('approved','published')) AS approved,
        SUM(r.status = 'rejected') AS rejected
    FROM survey_records r
    JOIN survey_answers sa ON sa.record_id = r.id AND sa.field_key = 'location'
    LEFT JOIN blocks ON blocks.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(sa.value_json, '\$.block_id')) AS UNSIGNED)
    WHERE CAST(JSON_UNQUOTE(JSON_EXTRACT(sa.value_json, '\$.block_id')) AS UNSIGNED) = :parent_id
    AND r.form_id = :form_id
    GROUP BY unit_id, blocks.name
";

$stmt = $pdo->prepare($dataSql);
$stmt->execute(['parent_id' => 20, 'form_id' => 66]);
$rows = $stmt->fetchAll();

echo "Data query results:\n";
foreach ($rows as $row) {
    echo "unit_id: {$row['unit_id']}, unit_name: {$row['unit_name']}, total: {$row['total']}\n";
}