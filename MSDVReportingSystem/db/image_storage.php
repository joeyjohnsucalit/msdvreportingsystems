<?php
// Stores report images (evidence, face capture, signature, profile photo) inside TiDB so they
// survive Render redeploys. Render's disk is wiped on every restart, so the
// old uploads/ folder lost files.
//
// Each image becomes one row in `stored_images`; the violations table keeps a
// path like "image.php?id=42", which image.php turns back into the picture.

// TiDB rejects a single row larger than ~6 MB by default, so stay under it.
const STORED_IMAGE_MAX_BYTES = 5 * 1024 * 1024;

const STORED_IMAGE_TYPES = [
    'image/jpeg' => true,
    'image/png'  => true,
    'image/gif'  => true,
    'image/webp' => true,
];

function ensureStoredImagesTable(PDO $pdo): void {
    static $ready = false;
    if ($ready) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS stored_images (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        kind       VARCHAR(20)  NOT NULL,
        mime       VARCHAR(50)  NOT NULL,
        data       MEDIUMBLOB   NOT NULL,
        created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    )");
    $ready = true;
}

/**
 * Validates raw image bytes and saves them to TiDB.
 * Returns the path to store in the violations table.
 * Throws RuntimeException with a user-facing message on failure.
 */
function storeImage(PDO $pdo, string $bytes, string $kind): string {
    if ($bytes === '') {
        throw new RuntimeException('The image is empty.');
    }
    if (strlen($bytes) > STORED_IMAGE_MAX_BYTES) {
        throw new RuntimeException('The image is too large (max 5 MB).');
    }
    $info = @getimagesizefromstring($bytes);
    $mime = $info['mime'] ?? '';
    if ($info === false || !isset(STORED_IMAGE_TYPES[$mime])) {
        throw new RuntimeException('Please use a JPG, PNG, GIF, or WEBP image.');
    }

    ensureStoredImagesTable($pdo);
    // Sent as hex + UNHEX() so raw binary bytes pass safely through the
    // emulated prepared statements used by the connection.
    $pdo->prepare("INSERT INTO stored_images (kind, mime, data) VALUES (?, ?, UNHEX(?))")
        ->execute([$kind, $mime, bin2hex($bytes)]);

    return 'image.php?id=' . $pdo->lastInsertId();
}

/** Decodes a "data:image/...;base64,..." URL from a canvas. Returns null if invalid. */
function decodeImageDataUrl(string $dataUrl): ?string {
    if (!preg_match('/^data:image\/(png|jpeg|webp);base64,([A-Za-z0-9+\/]+=*)$/', $dataUrl, $m)) {
        return null;
    }
    $bytes = base64_decode($m[2], true);
    return $bytes === false ? null : $bytes;
}

/* ───────────── helpers for deleting / reading stored images ───────────── */

/** "image.php?id=42" -> 42 (null if the path is not a stored-image path). */
function storedImageId(?string $path): ?int {
    return ($path && preg_match('/^image\.php\?id=(\d+)$/', $path, $m)) ? (int)$m[1] : null;
}

/** File extension for a mime type. */
function storedImageExt(string $mime): string {
    return ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'][$mime] ?? 'jpg';
}

/**
 * Deletes an image by its stored path: removes the stored_images row, or
 * (for old records) the legacy file under uploads/. Safe to call with null.
 */
function removeStoredImage(PDO $pdo, ?string $path): void {
    if (!$path) return;
    $id = storedImageId($path);
    if ($id !== null) {
        $pdo->prepare("DELETE FROM stored_images WHERE id=?")->execute([$id]);
        return;
    }
    $root = realpath(__DIR__ . '/..');
    $full = realpath($root . '/' . ltrim($path, '/'));
    if ($full && strpos($full, $root . DIRECTORY_SEPARATOR) === 0 && is_file($full)) @unlink($full);
}

/**
 * Loads an image by its stored path. Returns ['mime'=>..., 'data'=>...] or null.
 * Works for stored_images paths and for old uploads/ file paths.
 */
function loadStoredImage(PDO $pdo, ?string $path, bool $withData = true): ?array {
    if (!$path) return null;
    $id = storedImageId($path);
    if ($id !== null) {
        $q = $pdo->prepare($withData ? "SELECT mime, data FROM stored_images WHERE id=?" : "SELECT mime FROM stored_images WHERE id=?");
        $q->execute([$id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $data = null;
        if ($withData) $data = is_resource($row['data']) ? stream_get_contents($row['data']) : $row['data'];
        return ['mime' => $row['mime'], 'data' => $data];
    }
    $root = realpath(__DIR__ . '/..');
    $full = realpath($root . '/' . ltrim($path, '/'));
    if (!$full || strpos($full, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($full)) return null;
    return ['mime' => mime_content_type($full) ?: 'application/octet-stream', 'data' => $withData ? file_get_contents($full) : null];
}
