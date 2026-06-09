<?php
declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
    db()->prepare("DELETE FROM login_attempts WHERE attempted_at < datetime('now', '-20 minutes')")->execute();
    $check = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE (identifier = ? OR ip_address = ?) AND attempted_at > datetime('now', '-15 minutes')");
    $check->execute([mb_strtolower($email), $ip]);
    if ((int) $check->fetchColumn() >= 8) {
        return false;
    }

    $stmt = db()->prepare("SELECT * FROM users WHERE email = ? COLLATE NOCASE AND status = 'active'");
    $stmt->execute([$email]);
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
    $name = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_PATH . '/' . $name)) {
        throw new RuntimeException('Unable to store the photo.');
    }
    return 'uploads/' . $name;
}

