<?php
declare(strict_types=1);

function github_persistence_enabled(): bool
{
    return DB_DRIVER === 'sqlite'
        && GITHUB_PERSISTENCE_TOKEN !== ''
        && GITHUB_PERSISTENCE_REPO !== '';
}

function github_marker_path(): string
{
    return STORAGE_PATH . '/.github-db-sha';
}

function github_uploads_marker_path(): string
{
    return STORAGE_PATH . '/.github-uploads-sha';
}

function github_api(string $method, string $path, ?array $payload = null): array
{
    $curl = curl_init('https://api.github.com' . $path);
    $headers = [
        'Accept: application/vnd.github+json',
        'Authorization: Bearer ' . GITHUB_PERSISTENCE_TOKEN,
        'User-Agent: tch-orgchart-render',
        'X-GitHub-Api-Version: 2022-11-28',
    ];
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($payload !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
    }
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);

    $data = $body ? json_decode((string) $body, true) : null;
    if ($status >= 400) {
        $message = is_array($data) && isset($data['message']) ? (string) $data['message'] : (string) $body;
        throw new RuntimeException("GitHub persistence failed ($status): " . ($message ?: $error));
    }
    return ['status' => $status, 'data' => is_array($data) ? $data : [], 'body' => (string) $body];
}

function github_api_optional(string $method, string $path, ?array $payload = null): ?array
{
    try {
        return github_api($method, $path, $payload);
    } catch (RuntimeException $e) {
        if (str_contains($e->getMessage(), '(404)')) {
            return null;
        }
        throw $e;
    }
}

function github_repo_path(string $path): string
{
    return '/repos/' . GITHUB_PERSISTENCE_REPO . $path;
}

function github_ensure_branch(): void
{
    if (GITHUB_PERSISTENCE_BRANCH === GITHUB_PERSISTENCE_BASE_BRANCH) {
        return;
    }
    $branch = rawurlencode(GITHUB_PERSISTENCE_BRANCH);
    if (github_api_optional('GET', github_repo_path("/git/ref/heads/$branch"))) {
        return;
    }
    $base = github_api('GET', github_repo_path('/git/ref/heads/' . rawurlencode(GITHUB_PERSISTENCE_BASE_BRANCH)));
    $sha = $base['data']['object']['sha'] ?? null;
    if (!$sha) {
        throw new RuntimeException('Unable to find GitHub base branch for persistence.');
    }
    github_api('POST', github_repo_path('/git/refs'), [
        'ref' => 'refs/heads/' . GITHUB_PERSISTENCE_BRANCH,
        'sha' => $sha,
    ]);
}

function github_content_metadata(?string $repoPath = null): ?array
{
    github_ensure_branch();
    $repoPath ??= GITHUB_PERSISTENCE_DB_PATH;
    $path = implode('/', array_map('rawurlencode', explode('/', $repoPath)));
    $branch = rawurlencode(GITHUB_PERSISTENCE_BRANCH);
    $response = github_api_optional('GET', github_repo_path("/contents/$path?ref=$branch"));
    return $response['data'] ?? null;
}

function github_restore_database_if_configured(): void
{
    if (!github_persistence_enabled()) {
        return;
    }

    try {
        $metadata = github_content_metadata(GITHUB_PERSISTENCE_DB_PATH);
        if (!$metadata || empty($metadata['content']) || empty($metadata['sha'])) {
            return;
        }
        $remoteSha = (string) $metadata['sha'];
        $marker = is_file(github_marker_path()) ? trim((string) file_get_contents(github_marker_path())) : '';
        if ($marker === $remoteSha && is_file(DB_PATH)) {
            return;
        }
        $content = base64_decode(str_replace(["\n", "\r"], '', (string) $metadata['content']), true);
        if ($content === false || $content === '') {
            return;
        }
        file_put_contents(DB_PATH, $content, LOCK_EX);
        file_put_contents(github_marker_path(), $remoteSha, LOCK_EX);
    } catch (Throwable $e) {
        error_log($e->getMessage());
    }
}

function github_persist_database_if_configured(string $reason = 'admin update'): void
{
    if (!github_persistence_enabled() || !is_file(DB_PATH)) {
        return;
    }

    try {
        if (function_exists('db') && !db_is_pgsql()) {
            db()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        }
        $response = github_persist_file(GITHUB_PERSISTENCE_DB_PATH, DB_PATH, 'Persist org chart database: ' . $reason);
        $newSha = $response['data']['content']['sha'] ?? null;
        if ($newSha) {
            file_put_contents(github_marker_path(), (string) $newSha, LOCK_EX);
        }
    } catch (Throwable $e) {
        error_log($e->getMessage());
        throw new RuntimeException('Saved locally, but GitHub persistence failed: ' . $e->getMessage());
    }
}

function github_persist_file(string $repoPath, string $localPath, string $message): array
{
    if (!github_persistence_enabled() || !is_file($localPath)) {
        return ['status' => 0, 'data' => []];
    }

    $metadata = github_content_metadata($repoPath);
    $payload = [
        'message' => $message,
        'content' => base64_encode((string) file_get_contents($localPath)),
        'branch' => GITHUB_PERSISTENCE_BRANCH,
    ];
    if ($metadata && !empty($metadata['sha'])) {
        $payload['sha'] = $metadata['sha'];
    }
    $path = implode('/', array_map('rawurlencode', explode('/', $repoPath)));
    return github_api('PUT', github_repo_path("/contents/$path"), $payload);
}

function github_persist_upload_if_configured(string $relativePath, string $localPath): void
{
    if (!github_persistence_enabled() || !str_starts_with($relativePath, 'uploads/')) {
        return;
    }

    github_persist_file($relativePath, $localPath, 'Persist org chart upload: ' . basename($relativePath));
}

function github_restore_upload_if_configured(string $relativePath): bool
{
    if (!github_persistence_enabled() || !str_starts_with($relativePath, 'uploads/')) {
        return false;
    }

    $target = UPLOAD_PATH . '/' . basename($relativePath);
    if (is_file($target)) {
        return true;
    }

    try {
        $metadata = github_content_metadata($relativePath);
        if (!$metadata || empty($metadata['content'])) {
            return false;
        }
        $content = base64_decode(str_replace(["\n", "\r"], '', (string) $metadata['content']), true);
        if ($content === false || $content === '') {
            return false;
        }
        file_put_contents($target, $content, LOCK_EX);
        return true;
    } catch (Throwable $e) {
        error_log($e->getMessage());
        return false;
    }
}

function github_restore_referenced_uploads_if_configured(): void
{
    if (!github_persistence_enabled() || !function_exists('db') || db_is_pgsql() || !is_file(DB_PATH)) {
        return;
    }

    $dbMarker = is_file(github_marker_path()) ? trim((string) file_get_contents(github_marker_path())) : '';
    if ($dbMarker !== '' && is_file(github_uploads_marker_path()) && trim((string) file_get_contents(github_uploads_marker_path())) === $dbMarker) {
        return;
    }

    try {
        $stmt = db()->query("SELECT DISTINCT photo_path FROM personnel WHERE photo_path LIKE 'uploads/%'");
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $path) {
            if (is_string($path) && $path !== '') {
                github_restore_upload_if_configured($path);
            }
        }
        if ($dbMarker !== '') {
            file_put_contents(github_uploads_marker_path(), $dbMarker, LOCK_EX);
        }
    } catch (Throwable $e) {
        error_log($e->getMessage());
    }
}
