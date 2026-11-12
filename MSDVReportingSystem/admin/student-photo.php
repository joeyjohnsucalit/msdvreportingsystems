<?php
// admin/student-photo.php  — serves the profile photo stored in students.profile_photo_path (admin only)
session_start();
require_once '../db/connection.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { http_response_code(403); exit; }
session_write_close();

$sid = (string)($_GET['sid'] ?? '');
$q = $pdo->prepare("SELECT profile_photo_path FROM students WHERE student_id=?");
$q->execute([$sid]);
$val = $q->fetchColumn();

// The column holds a data URI:  data:image/jpeg;base64,xxxx
if (!$val || !preg_match('#^data:(image/(?:jpeg|png|webp));base64,(.+)$#s', $val, $m)) {
    http_response_code(404); exit;
}
$bin = base64_decode($m[2], true);
if ($bin === false) { http_response_code(404); exit; }

header('Content-Type: ' . $m[1]);
header('Content-Length: ' . strlen($bin));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
echo $bin;
