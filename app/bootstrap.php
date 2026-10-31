<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function app_settings(): array
{
    static $settings = null;
    if ($settings === null) {
        $settings = require __DIR__ . '/../config/app.php';
    }

    return $settings;
}

function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $settings = app_settings();
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    $secure = $settings['session_secure']
        || (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off');
    session_name('second_story_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if (!session_start()) {
        throw new RuntimeException('Unable to start a secure session.');
    }
}

function send_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; script-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    header('Cache-Control: no-store');
    if ((!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || app_settings()['session_secure']) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}

function request_data(): array
{
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'application/json')) {
        $body = file_get_contents('php://input');
        try {
            $decoded = json_decode($body === false ? '' : $body, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            json_response(['error' => 'Request body must be valid JSON.'], 400);
        }
        if (!$decoded instanceof stdClass) {
            json_response(['error' => 'Request body must be a JSON object.'], 400);
        }
        $data = get_object_vars($decoded);
        foreach ($data as $value) {
            if (is_array($value) || is_object($value)) {
                json_response(['error' => 'Nested request values are not supported.'], 400);
            }
        }

        return $data;
    }

    foreach ($_POST as $value) {
        if (is_array($value)) {
            json_response(['error' => 'Nested request values are not supported.'], 400);
        }
    }

    return $_POST;
}

function csrf_token(): string
{
    start_app_session();
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function require_csrf(): void
{
    start_app_session();
    $token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? request_data()['_csrf'] ?? '');
    if ($token === '' || !hash_equals(csrf_token(), $token)) {
        json_response(['error' => 'Your session has expired. Refresh the page and try again.'], 419);
    }

    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    $host = $_SERVER['HTTP_HOST'] ?? null;
    if ($origin !== null && $host !== null) {
        $originHost = parse_url($origin, PHP_URL_HOST);
        $requestHost = strtolower(explode(':', $host)[0]);
        if (!is_string($originHost) || !hash_equals($requestHost, strtolower($originHost))) {
            json_response(['error' => 'Request origin was not accepted.'], 403);
        }
    }
}

function current_user_id(): ?int
{
    start_app_session();
    $id = $_SESSION['user_id'] ?? null;
    return is_int($id) && $id > 0 ? $id : null;
}

function require_user(): int
{
    $userId = current_user_id();
    if ($userId === null) {
        json_response(['error' => 'Please sign in to continue.'], 401);
    }

    $statement = database_connection()->prepare(
        'SELECT account_status FROM users WHERE id = :id LIMIT 1'
    );
    $statement->execute(['id' => $userId]);
    if ($statement->fetchColumn() !== 'active') {
        $_SESSION = [];
        session_destroy();
        json_response(['error' => 'This account is not available.'], 403);
    }

    return $userId;
}

function user_roles(int $userId): array
{
    $statement = database_connection()->prepare(
        'SELECT r.name FROM roles r INNER JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = :user_id'
    );
    $statement->execute(['user_id' => $userId]);

    return array_column($statement->fetchAll(), 'name');
}

function require_role(string $role): int
{
    $userId = require_user();
    if (!in_array($role, user_roles($userId), true)) {
        json_response(['error' => 'You are not allowed to perform this action.'], 403);
    }

    return $userId;
}

function require_dashboard_role(string $role): int
{
    if (!in_array($role, ['buyer', 'seller'], true)) {
        throw new InvalidArgumentException('Unknown dashboard role.');
    }

    $userId = current_user_id();
    if ($userId === null) {
        header('Location: /login?mode=' . $role, true, 303);
        exit;
    }

    $status = database_connection()->prepare(
        'SELECT account_status FROM users WHERE id = :id LIMIT 1'
    );
    $status->execute(['id' => $userId]);
    if ($status->fetchColumn() !== 'active') {
        $_SESSION = [];
        session_destroy();
        header('Location: /login?mode=' . $role, true, 303);
        exit;
    }

    if (!in_array($role, user_roles($userId), true)) {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Mode unavailable — CampusLoop</title><body><main><h1>That mode is not active on this account.</h1><p>Choose an available account mode or enable seller mode from your buyer dashboard.</p><a href="/buyer/dashboard">Go to buyer dashboard</a></main></body></html>';
        exit;
    }

    return $userId;
}

function escape_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function audit_event(?int $actorId, string $action, string $entityType, ?int $entityId, array $metadata = []): void
{
    $statement = database_connection()->prepare(
        'INSERT INTO admin_audit_logs (actor_user_id, action, entity_type, entity_id, metadata_json, ip_fingerprint)
         VALUES (:actor, :action, :entity_type, :entity_id, :metadata, :ip_fingerprint)'
    );
    $statement->execute([
        'actor' => $actorId,
        'action' => $action,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        'ip_fingerprint' => ip_fingerprint(),
    ]);
}

function ip_fingerprint(): string
{
    $secret = app_settings()['app_secret'];
    if ($secret === '') {
        return hash('sha256', 'development-only:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    }

    return hash_hmac('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), $secret);
}
