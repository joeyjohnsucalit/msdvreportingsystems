<?php
// api/enroll-webhook.php  — receives Google Form submissions and enrolls the student
header('Content-Type: application/json');
require_once '../db/connection.php';

function out($code, $ok, $msg) {
    http_response_code($code);
    echo json_encode(['ok' => $ok, 'message' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(405, false, 'POST only');

// Shared secret (set FORM_SECRET as an environment variable on Render)
$secret = getenv('FORM_SECRET');
$in     = json_decode(file_get_contents('php://input'), true) ?: [];
if (!$secret || !hash_equals($secret, (string)($in['secret'] ?? ''))) out(403, false, 'Forbidden');

$sid        = trim($in['student_id'] ?? '');
$fname      = trim($in['full_name'] ?? '');
$courseName = trim($in['course'] ?? '');
preg_match('/[1-4]/', (string)($in['year_level'] ?? ''), $m);
$year = (int)($m[0] ?? 0);

if (!preg_match('/^\d{3}-\d{5}$/', $sid)) out(422, false, 'Invalid Student ID format (use 000-00000)');
if ($fname === '' || !$year)               out(422, false, 'Missing full name or year level');

// Course -> also gives us the department
$c = $pdo->prepare("SELECT id, department_id FROM courses WHERE LOWER(name)=LOWER(?)");
$c->execute([$courseName]);
$course = $c->fetch();
if (!$course) out(422, false, 'Unknown course: ' . $courseName);

// Duplicate checks
$d = $pdo->prepare("SELECT 1 FROM students WHERE student_id=?");
$d->execute([$sid]);
if ($d->fetch()) out(409, false, 'Student ID already enrolled');

$d = $pdo->prepare("SELECT 1 FROM users WHERE username=?");
$d->execute([$sid]);
if ($d->fetch()) out(409, false, 'A user account with this ID already exists');

$lastFive = substr(str_replace('-', '', $sid), -5);
$hashed   = password_hash($lastFive, PASSWORD_DEFAULT);

/* ── Profile photo (base64 sent by the Google Form script) ──
   Saved only if it is a real JPG/PNG/WEBP image of 5 MB or less.
   If the photo is missing or invalid, the student is still enrolled without one. */
$photoPath = null;
$photoFull = null;
$b64 = (string)($in['profile_photo'] ?? '');
if ($b64 !== '') {
    $bin  = base64_decode($b64, true);
    $info = $bin ? @getimagesizefromstring($bin) : false;
    $exts = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    if ($info && isset($exts[$info['mime']]) && strlen($bin) <= 5 * 1024 * 1024) {
        $dir = __DIR__ . '/../uploads/profiles/';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);

        $file      = $sid . '.' . $exts[$info['mime']];   // e.g. 123-45678.jpg
        $photoFull = $dir . $file;
        if (@file_put_contents($photoFull, $bin) !== false) {
            $photoPath = 'uploads/profiles/' . $file;
        } else {
            $photoFull = null;
        }
    }
}

try {
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO users (full_name,username,email,password,role,is_first_login) VALUES (?,?,'',?,'student',1)")
        ->execute([$fname, $sid, $hashed]);
    $uid = $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO students (student_id,full_name,course_id,year_level,department_id,user_id,profile_photo_path) VALUES (?,?,?,?,?,?,?)")
        ->execute([$sid, $fname, $course['id'], $year, $course['department_id'], $uid, $photoPath]);
    $pdo->commit();
    out(200, true, 'Student enrolled');
} catch (Exception $e) {
    $pdo->rollBack();
    if ($photoFull && is_file($photoFull)) @unlink($photoFull);   // don't leave an orphan photo
    error_log('Enroll webhook error: ' . $e->getMessage());
    out(500, false, 'Server error');
}
