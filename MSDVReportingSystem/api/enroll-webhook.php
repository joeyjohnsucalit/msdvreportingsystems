<?php
/*
 * PASTE THIS INTO api/enroll-webhook.php
 *
 * Put it AFTER you decode the JSON body and read $student_id,
 * and BEFORE your INSERT/UPDATE into `students`.
 *
 * Then add `profile_photo_path` to that INSERT/UPDATE and pass $photoPath.
 * Example:
 *   INSERT INTO students (..., profile_photo_path) VALUES (..., ?)
 *   ON DUPLICATE KEY UPDATE ..., profile_photo_path = COALESCE(VALUES(profile_photo_path), profile_photo_path)
 *
 * (COALESCE keeps the old photo if a re-submission has no photo.)
 */

$photoPath = null;
$b64 = $data['profile_photo'] ?? '';          // $data = json_decode(file_get_contents('php://input'), true)

if ($b64 !== '') {
    $bin  = base64_decode($b64, true);
    $info = $bin ? @getimagesizefromstring($bin) : false;
    $ext  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    // Accept only real images up to 5 MB
    if ($info && isset($ext[$info['mime']]) && strlen($bin) <= 5 * 1024 * 1024) {
        $dir = __DIR__ . '/../uploads/profiles/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        // File name = student ID (digits and dashes only), e.g. 123-45678.jpg
        $file = preg_replace('/[^0-9\-]/', '', $student_id) . '.' . $ext[$info['mime']];
        file_put_contents($dir . $file, $bin);
        $photoPath = 'uploads/profiles/' . $file;
    }
}
