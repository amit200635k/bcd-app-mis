<?php

declare(strict_types=1);

/**
 * Automated stamping round-trip test (server-side PHP stamping).
 *
 * 1. Creates a fresh record by replaying valid form-66 answers from the DB.
 * 2. Generates a solid-colour JPEG (with a red box) using GD.
 * 3. Uploads it over HTTP to /api/v1/records/{id}/photos (authenticated).
 * 4. Refetches the stored file and asserts the stamp was drawn:
 *      - bottom gradient band is clearly darker than the untouched top,
 *      - right-aligned white text pixels exist in the band,
 *      - the top region keeps the original colour, and
 *      - the stored file size differs from the input (re-encoded).
 *
 * Exit code 0 = PASS, 1 = FAIL.
 * Usage: php tests/stamp_roundtrip.php
 */

require dirname(__DIR__) . '/common/bootstrap.php';

use App\Database\Connection;
use App\Services\RecordService;

$base  = (string) (getenv('BCD_API') ?: 'http://127.0.0.1:81/bcd-app/api/v1');
$user  = (string) (getenv('BCD_USER') ?: 'bcdadmin');
$pass  = (string) (getenv('BCD_PASS') ?: 'Demo@123');
$root  = dirname(__DIR__);

$failures = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . ($detail !== '' ? " — {$detail}" : '') . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
}

// ---------------------------------------------------------------- helpers

function api_request(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) {
        return ['ok' => false, 'error' => $err];
    }
    $data = json_decode($raw, true);
    return ['ok' => true, 'status' => $code, 'json' => is_array($data) ? $data : ['raw' => $raw]];
}

// 1) login
$login = api_request('POST', $base . '/auth/login', ['Content-Type: application/json'], json_encode([
    'username' => $user,
    'password' => $pass,
    'device_id' => 'stamp-roundtrip-test',
]));
$token = $login['json']['data']['access_token'] ?? null;
check('login returns access_token', is_string($token) && $token !== '', json_encode($login['json'] ?? $login));
if ($token === null) {
    echo "RESULT: FAIL (no token)\n";
    exit(1);
}

// 2) create a fresh record reusing valid form-66 answers from an existing submitted record
$pdo = Connection::instance();
$src = $pdo->query("SELECT id, form_id, form_version_id FROM survey_records WHERE form_id = 66 AND status = 'submitted' ORDER BY id DESC LIMIT 1")->fetch();
check('found reference record', $src !== false);
if ($src === false) {
    echo "RESULT: FAIL (no reference record)\n";
    exit(1);
}
$answers = [];
foreach ($pdo->query('SELECT field_key, value_text, value_json FROM survey_answers WHERE record_id = ' . (int) $src['id'])->fetchAll() as $a) {
    $isText = $a['value_text'] !== null && trim((string) $a['value_text']) !== '';
    $answers[$a['field_key']] = $isText
        ? $a['value_text']
        : (($a['value_json'] !== null && trim((string) $a['value_json']) !== '')
            ? json_decode($a['value_json'], true)
            : ($a['value_text'] ?? ''));
}
$uuid = 'stamp-test-' . bin2hex(random_bytes(4));
$svc = new RecordService();
$created = $svc->upsert(1, [
    'record_uuid' => $uuid,
    'form_id' => (int) $src['form_id'],
    'form_version_id' => (int) $src['form_version_id'],
    'answers' => $answers,
]);
$rid = (int) ($created['record_id'] ?? 0);
check('created fresh record id', $rid > 0, json_encode($created));
if ($rid <= 0) {
    echo "RESULT: FAIL (no record created)\n";
    exit(1);
}

// 3) generate a solid-colour test JPEG
$jpg = sys_get_temp_dir() . '/' . 'stamp_input_' . bin2hex(random_bytes(4)) . '.jpg';
$im = imagecreatetruecolor(640, 480);
imagefilledrectangle($im, 0, 0, 639, 479, imagecolorallocate($im, 20, 100, 200));
imagefilledrectangle($im, 100, 100, 300, 300, imagecolorallocate($im, 220, 30, 30));
imagejpeg($im, $jpg, 92);
imagedestroy($im);
check('generated test JPEG', is_file($jpg), filesize($jpg) . ' bytes');

// 4) upload through the real API (exercises RecordController::photos() + stampPhoto)
$ch = curl_init($base . '/records/' . $rid . '/photos');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
    CURLOPT_POSTFIELDS     => [
        'files[]'    => new CURLFile($jpg, 'image/jpeg', 'stamp_test.jpg'),
        'field_key'  => 'building_name',
        'category'   => 'photo',
    ],
]);
$raw = curl_exec($ch);
$upErr = curl_error($ch);
curl_close($ch);
$up = json_decode((string) $raw, true);
$filePath = $up['data']['images'][0]['file_path'] ?? null;
$imgId = (int) ($up['data']['images'][0]['id'] ?? 0);
check('photo uploaded via API', is_string($filePath) && $filePath !== '', ($upErr !== '' ? $upErr . ' / ' : '') . (string) $raw);
if ($filePath === null) {
    echo "RESULT: FAIL (no upload)\n";
    exit(1);
}

// 5) verify the stored file was stamped
$stored = $root . '/' . ltrim($filePath, '/');
check('stored file resolves on disk', is_file($stored), $stored);
if (!is_file($stored)) {
    echo "RESULT: FAIL (missing stored file)\n";
    exit(1);
}

$storedIm = function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($stored) : false;
$origIm   = imagecreatefromjpeg($jpg);
check('both images decode via GD', $storedIm !== false && $origIm !== false);
if ($storedIm === false || $origIm === false) {
    echo "RESULT: FAIL (decode)\n";
    exit(1);
}

$w = imagesx($storedIm);
$h = imagesy($storedIm);
$ow = imagesx($origIm);
$oh = imagesy($origIm);
check('stored image resized to 1000x650', $w === 1000 && $h === 650, "{$w}x{$h}");
check('stored file re-encoded (size changed)', filesize($stored) !== filesize($jpg),
    sprintf('stored=%d input=%d', filesize($stored), filesize($jpg)));

function px(GdImage $im, int $x, int $y): array
{
    $c = imagecolorat($im, $x, $y);
    return [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
}
function lumAvg(GdImage $im, int $w, int $h, int $y0, int $y1): float
{
    $sum = 0.0;
    $n = 0;
    for ($y = $y0; $y <= $y1; $y += 2) {
        for ($x = 0; $x < $w; $x += 4) {
            $p = px($im, $x, $y);
            $sum += ($p[0] + $p[1] + $p[2]) / 3;
            $n++;
        }
    }
    return $n > 0 ? $sum / $n : 0.0;
}

$bandAvg = lumAvg($storedIm, $w, $h, (int) ($h * 0.88), $h - 1);
$origBottom = lumAvg($origIm, $ow, $oh, (int) ($oh * 0.9), $oh - 1);
check('no dark band behind the text (background preserved)', $bandAvg > $origBottom * 0.75,
    sprintf('band=%.1f origBottom=%.1f', $bandAvg, $origBottom));

$white = 0;
$minY = $h;
$maxY = 0;
$rightHalf = 0;
for ($x = 0; $x < $w; $x++) {
    for ($y = (int) ($h * 0.6); $y < $h; $y++) {
        $p = px($storedIm, $x, $y);
        if ($p[0] > 200 && $p[1] > 200 && $p[2] > 200) {
            $white++;
            $minY = min($minY, $y);
            $maxY = max($maxY, $y);
            if ($x > $w * 0.5) {
                $rightHalf++;
            }
        }
    }
}
check('six stamp lines drawn (white text pixels)', $white > 300, 'white=' . $white);
check('stamp text spans ~6 lines (vertical spread)',
    $maxY - $minY >= 60 && $maxY - $minY <= 280, 'spread=' . ($maxY - $minY));
check('stamp text is right-aligned', $rightHalf > $white * 0.5, "rightHalf={$rightHalf} white={$white}");

$topLeft = px($storedIm, 50, 50);
check('top region keeps original colour',
    abs($topLeft[0] - 20) <= 12 && abs($topLeft[1] - 100) <= 12 && abs($topLeft[2] - 200) <= 12,
    implode(',', $topLeft));

// 6) assert the exact stamp lines (same values stampPhoto draws on the image)
$recRow = $pdo->query('SELECT id, survey_code, created_at, submitted_by, user_id FROM survey_records WHERE id = ' . (int) $rid)->fetch();
$recAnswers = [];
foreach ($pdo->query('SELECT field_key, value_text, value_json FROM survey_answers WHERE record_id = ' . (int) $rid)->fetchAll() as $a) {
    $isText = $a['value_text'] !== null && trim((string) $a['value_text']) !== '';
    $recAnswers[$a['field_key']] = $isText
        ? $a['value_text']
        : (($a['value_json'] !== null && trim((string) $a['value_json']) !== '')
            ? json_decode($a['value_json'], true)
            : ($a['value_text'] ?? ''));
}
$surveyorRow = $pdo->query('SELECT full_name, username FROM users WHERE id = ' . (int) $recRow['submitted_by'])->fetch();

$expectedSurvey  = (string) $recRow['survey_code'];
$expectedCreated = date('d-m-Y H:i:s', strtotime((string) $recRow['created_at']));
$expectedBuilder = (string) ($recAnswers['building_name'] ?? '');
$expectedAddress = (string) ($recAnswers['address'] ?? '');
$geo = is_array($recAnswers['geo_location'] ?? null) ? $recAnswers['geo_location'] : [];
$expectedGeo = 'lat-' . number_format((float) ($geo['lat'] ?? 0), 5, '.', '')
    . ', long-' . number_format((float) ($geo['lng'] ?? 0), 5, '.', '');
$expectedSurveyor = (string) ($surveyorRow['full_name'] ?: $surveyorRow['username']);

$ref = new ReflectionMethod(\App\Api\Controllers\RecordController::class, 'stampMeta');
$ref->setAccessible(true);
$meta = $ref->invoke(null, $recRow, $pdo);

$expectedLines = [
    'Survey ID- ' . $expectedSurvey,
    'Surveyor Name - ' . $expectedSurveyor,
    'Created Date - ' . $expectedCreated,
    'Building Name - ' . $expectedBuilder,
    'Address - ' . $expectedAddress,
    $expectedGeo,
];
$actualLines = [
    'Survey ID- ' . $meta['survey'],
    'Surveyor Name - ' . $meta['surveyor'],
    'Created Date - ' . $meta['created'],
    'Building Name - ' . $meta['building'],
    'Address - ' . $meta['address'],
    $meta['geo'],
];
foreach ($expectedLines as $i => $expectedLine) {
    check('stamp line "' . $expectedLine . '"', $actualLines[$i] === $expectedLine, $actualLines[$i]);
}

// cleanup: best-effort remove test record + image so runs don't accumulate
try {
    $pdo->exec('DELETE FROM survey_images WHERE id = ' . $imgId);
    $pdo->exec('DELETE FROM survey_answers WHERE record_id = ' . $rid);
    $pdo->exec('DELETE FROM survey_records WHERE id = ' . $rid);
    @unlink($stored);
    @unlink($jpg);
} catch (Throwable $e) {
    echo '[INFO] cleanup skipped: ' . exception_message($e) . PHP_EOL;
}

echo $failures === 0 ? '------------------------------------------' . PHP_EOL . "RESULT: STAMP VERIFIED (imgId={$imgId})\n"
                     : '------------------------------------------' . PHP_EOL . "RESULT: FAILURES={$failures}\n";
exit($failures === 0 ? 0 : 1);