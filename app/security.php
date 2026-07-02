<?php
declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function upload_paths(string $path): array
{
    if (filter_var($path, FILTER_VALIDATE_URL)) {
        return [];
    }
    if (!str_starts_with($path, 'uploads/')) {
        return [];
    }

    $name = basename($path);
    return array_values(array_unique([
        UPLOAD_PATH . '/' . $name,
        APP_ROOT . '/uploads/' . $name,
    ]));
}

function upload_file_exists(string $path): bool
{
    if (filter_var($path, FILTER_VALIDATE_URL)) {
        return true;
    }
    foreach (upload_paths($path) as $candidate) {
        if (is_file($candidate)) {
            return true;
        }
    }
    return false;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        throw new RuntimeException('Your session token expired. Refresh and try again.');
    }
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $stmt = db()->prepare("SELECT id, name, email, status, force_password_change, last_login_at FROM users WHERE id = ? AND status = 'active'");
    $stmt->execute([(int) $_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

function require_admin(): array
{
    $user = current_user();
    if (!$user) {
        header('Location: /admin/');
        exit;
    }
    return $user;
}

function login_user(string $email, string $password): bool
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (db_is_pgsql()) {
        db()->prepare("DELETE FROM login_attempts WHERE attempted_at < CURRENT_TIMESTAMP - INTERVAL '20 minutes'")->execute();
        $check = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE (identifier = ? OR ip_address = ?) AND attempted_at > CURRENT_TIMESTAMP - INTERVAL '15 minutes'");
    } else {
        db()->prepare("DELETE FROM login_attempts WHERE attempted_at < datetime('now', '-20 minutes')")->execute();
        $check = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE (identifier = ? OR ip_address = ?) AND attempted_at > datetime('now', '-15 minutes')");
    }
    $check->execute([mb_strtolower($email), $ip]);
    if ((int) $check->fetchColumn() >= 8) {
        return false;
    }

    $stmt = db()->prepare(db_is_pgsql()
        ? "SELECT * FROM users WHERE lower(email) = lower(?) AND status = 'active'"
        : "SELECT * FROM users WHERE email = ? COLLATE NOCASE AND status = 'active'");
    $stmt->execute([trim($email)]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        db()->prepare('INSERT INTO login_attempts(identifier, ip_address) VALUES(?, ?)')->execute([mb_strtolower($email), $ip]);
        usleep(350000);
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['last_activity'] = time();
    db()->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$user['id']]);
    db()->prepare('DELETE FROM login_attempts WHERE identifier = ? OR ip_address = ?')->execute([mb_strtolower($email), $ip]);
    audit('login', 'user', (int) $user['id'], null, ['email' => $email]);
    return true;
}

function audit(string $action, string $targetType, ?int $targetId, ?array $before, ?array $after): void
{
    $stmt = db()->prepare('INSERT INTO audit_logs(user_id, action, target_type, target_id, before_json, after_json, ip_address) VALUES(?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $_SESSION['user_id'] ?? null,
        $action,
        $targetType,
        $targetId,
        $before ? json_encode($before, JSON_UNESCAPED_SLASHES) : null,
        $after ? json_encode($after, JSON_UNESCAPED_SLASHES) : null,
        $_SERVER['REMOTE_ADDR'] ?? '',
    ]);
}

function json_response(mixed $data = null, int $status = 200, ?array $errors = null): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => $status < 400, 'data' => $data, 'errors' => $errors], JSON_UNESCAPED_SLASHES);
    exit;
}

function safe_upload(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if (($file['error'] ?? null) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new InvalidArgumentException('Photo must be a valid image under 5 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime]) || !getimagesize($file['tmp_name'])) {
        throw new InvalidArgumentException('Only valid JPG, PNG, and WebP images are accepted.');
    }
    if (cloudinary_is_configured()) {
        return cloudinary_upload($file['tmp_name']);
    }
    $name = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_PATH . '/' . $name)) {
        throw new RuntimeException('Unable to store the photo.');
    }
    github_persist_upload_if_configured('uploads/' . $name, UPLOAD_PATH . '/' . $name);
    return 'uploads/' . $name;
}

function cloudinary_is_configured(): bool
{
    return (bool) (getenv('CLOUDINARY_CLOUD_NAME') && getenv('CLOUDINARY_API_KEY') && getenv('CLOUDINARY_API_SECRET'));
}

function cloudinary_upload(string $tmpPath): string
{
    $cloudName = (string) getenv('CLOUDINARY_CLOUD_NAME');
    $apiKey = (string) getenv('CLOUDINARY_API_KEY');
    $apiSecret = (string) getenv('CLOUDINARY_API_SECRET');
    $folder = trim((string) (getenv('CLOUDINARY_FOLDER') ?: 'orgchart/personnel'), '/');
    $timestamp = time();
    $publicId = 'person_' . bin2hex(random_bytes(10));
    $signatureParams = [
        'folder' => $folder,
        'public_id' => $publicId,
        'timestamp' => $timestamp,
    ];
    ksort($signatureParams);
    $signatureBase = implode('&', array_map(
        fn($key, $value) => $key . '=' . $value,
        array_keys($signatureParams),
        array_values($signatureParams)
    ));
    $signature = sha1($signatureBase . $apiSecret);

    $curl = curl_init("https://api.cloudinary.com/v1_1/$cloudName/image/upload");
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_POSTFIELDS => [
            'file' => new CURLFile($tmpPath),
            'api_key' => $apiKey,
            'timestamp' => (string) $timestamp,
            'folder' => $folder,
            'public_id' => $publicId,
            'signature' => $signature,
        ],
    ]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($response === false || $status >= 400) {
        throw new RuntimeException('Unable to upload the photo to Cloudinary.' . ($error ? " $error" : ''));
    }
    $payload = json_decode((string) $response, true);
    if (!is_array($payload) || empty($payload['secure_url'])) {
        throw new RuntimeException('Cloudinary did not return a usable photo URL.');
    }
    return (string) $payload['secure_url'];
}
