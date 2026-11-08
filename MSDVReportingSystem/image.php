<?php
// Serves a report image stored in TiDB (see db/image_storage.php).
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit;
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    http_response_code(400);
    exit;
}

require_once __DIR__ . '/db/connection.php';

$stmt = $pdo->prepare("SELECT mime, data FROM stored_images WHERE id = ?");
$stmt->execute([$id]);
$img = $stmt->fetch();
if (!$img) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $img['mime']);
header('Content-Length: ' . strlen($img['data']));
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
echo $img['data'];
