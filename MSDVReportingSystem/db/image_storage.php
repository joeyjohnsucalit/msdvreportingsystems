<?php
// Stores report images (evidence, face capture, signature) inside TiDB so they
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
