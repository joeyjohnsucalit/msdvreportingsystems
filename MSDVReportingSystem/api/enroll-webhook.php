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
   Stored INSIDE the students table (profile_photo_path) as a data URI, so it
   survives Render restarts. Accepts only real JPG/PNG/WEBP images up to 3 MB.
   If the photo is missing or invalid, the student is still enrolled without one. */
$photoData = null;
$b64 = (string)($in['profile_photo'] ?? '');
if ($b64 !== '') {
    $bin  = base64_decode($b64, true);
    $info = $bin ? @getimagesizefromstring($bin) : false;
    $okMime = ['image/jpeg', 'image/png', 'image/webp'];
    if ($info && in_array($info['mime'], $okMime, true) && strlen($bin) <= 3 * 1024 * 1024) {
        $photoData = 'data:' . $info['mime'] . ';base64,' . base64_encode($bin);
    }
}

try {
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO users (full_name,username,email,password,role,is_first_login) VALUES (?,?,'',?,'student',1)")
        ->execute([$fname, $sid, $hashed]);
    $uid = $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO students (student_id,full_name,course_id,year_level,department_id,user_id,profile_photo_path) VALUES (?,?,?,?,?,?,?)")
        ->execute([$sid, $fname, $course['id'], $year, $course['department_id'], $uid, $photoData]);
    $pdo->commit();
    out(200, true, 'Student enrolled');
} catch (Exception $e) {
    $pdo->rollBack();
    error_log('Enroll webhook error: ' . $e->getMessage());
    out(500, false, 'Server error');
}
