<?php
// admin/migrate-photos.php  — ONE-TIME script (admin only)
//
// Moves old profile photos that were saved as files (profile_photo_path = 'uploads/profiles/...')
// into the stored_images table and changes the path to 'image.php?id=N',
// so they survive Render restarts.
//
// Open it once in the browser while logged in as admin, read the report,
// then DELETE this file from your project and redeploy.
session_start();
require_once '../db/connection.php';
require_once '../db/image_storage.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { http_response_code(403); exit('Admin only'); }
header('Content-Type: text/plain; charset=utf-8');

$root = realpath(__DIR__ . '/..');
$rows = $pdo->query("SELECT student_id, profile_photo_path FROM students WHERE profile_photo_path LIKE 'uploads/%'")
            ->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) { echo "Nothing to migrate.\n"; exit; }

$upd = $pdo->prepare("UPDATE students SET profile_photo_path=? WHERE student_id=?");
$done = 0; $missing = 0; $bad = 0;

foreach ($rows as $r) {
    $sid  = $r['student_id'];
    $full = realpath($root . '/' . ltrim($r['profile_photo_path'], '/'));

    if (!$full || strpos($full, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($full)) {
        echo "MISSING FILE  $sid  ({$r['profile_photo_path']}) - the file was lost; student must resubmit or be re-enrolled\n";
        $missing++; continue;
    }
    try {
        $path = storeImage($pdo, file_get_contents($full), 'profile');
        $upd->execute([$path, $sid]);
        echo "MIGRATED      $sid  ->  $path\n";
        $done++;
    } catch (RuntimeException $e) {
        echo "SKIPPED       $sid  (" . $e->getMessage() . ")\n";
        $bad++;
    }
}
echo "\nDone. Migrated: $done, missing files: $missing, skipped: $bad\n";
echo "Now delete admin/migrate-photos.php from your project.\n";
