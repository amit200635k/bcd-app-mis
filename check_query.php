<?php
require 'D:/Xampp/htdocs/bcd-app/common/bootstrap.php';
use App\Database\Connection;

$pdo = Connection::instance();

// Check what data exists for block_id=77 (Ranchi block)
$stmt = $pdo->prepare("
    SELECT COUNT(*) as cnt
    FROM survey_records sr
    JOIN survey_answers sa ON sa.record_id = sr.id
    WHERE sr.form_id = 66
    AND sa.field_key = 'location'
    AND JSON_UNQUOTE(JSON_EXTRACT(sa.value_json, '\$.block_id')) = '77'
");
$stmt->execute();
echo "Records for block_id=77: " . $stmt->fetchColumn() . "\n";

// Check with district_id filter
$stmt = $pdo->prepare("
    SELECT COUNT(*) as cnt
    FROM survey_records sr
    JOIN survey_answers sa ON sa.record_id = sr.id
    WHERE sr.form_id = 66
    AND sa.field_key = 'location'
    AND JSON_UNQUOTE(JSON_EXTRACT(sa.value_json, '\$.district_id')) = '20'
    AND JSON_UNQUOTE(JSON_EXTRACT(sa.value_json, '\$.block_id')) = '77'
");
$stmt->execute();
echo "Records for district_id=20 AND block_id=77: " . $stmt->fetchColumn() . "\n";

// Check what the query returns for block level with district filter
$stmt = $pdo->prepare("
    SELECT 
        JSON_UNQUOTE(JSON_EXTRACT(sa.value_json, '\$.block_id')) AS unit_id,
        blocks.name AS unit_name,
        COUNT(r.id) AS total
    FROM survey_records r
    JOIN survey_answers sa ON sa.record_id = r.id AND sa.field_key = 'location'
    LEFT JOIN blocks ON blocks.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(sa.value_json, '\$.block_id')) AS UNSIGNED)
    WHERE r.form_id = 66
    AND JSON_UNQUOTE(JSON_EXTRACT(sa.value_json, '\$.district_id')) = '20'
    AND JSON_UNQUOTE(JSON_EXTRACT(sa.value_json, '\$.block_id')) = '77'
    GROUP BY unit_id, blocks.name
");
$stmt->execute();
$rows = $stmt->fetchAll();
echo "\nRecords for district_id=20 AND block_id=77:\n";
foreach ($rows as $row) {
    echo "  unit_id: {$row['unit_id']}, unit_name: {$row['unit_name']}, total: {$row['total']}\n";
}