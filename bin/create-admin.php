<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/bootstrap.php';

$email = strtolower(trim((string) getenv('INITIAL_ADMIN_EMAIL')));
$password = (string) getenv('INITIAL_ADMIN_PASSWORD');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 16) {
    fwrite(STDERR, "Set INITIAL_ADMIN_EMAIL and a unique INITIAL_ADMIN_PASSWORD (at least 16 characters).\n");
    exit(2);
}

$pdo = database_connection();
$existingAdmin = $pdo->query(
    'SELECT 1 FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id
     WHERE r.name = "admin" LIMIT 1'
);
if ($existingAdmin->fetchColumn()) {
    fwrite(STDERR, "An administrator already exists. Initial admin setup is one-time only.\n");
    exit(1);
}

$pdo->beginTransaction();
try {
    $user = $pdo->prepare(
        'INSERT INTO users (email, password_hash, account_status) VALUES (:email, :password_hash, "active")'
    );
    $user->execute(['email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
    $userId = (int) $pdo->lastInsertId();

    $profile = $pdo->prepare(
        'INSERT INTO user_profiles (user_id, full_name) VALUES (:user_id, :full_name)'
    );
    $profile->execute(['user_id' => $userId, 'full_name' => 'Platform administrator']);

    $role = $pdo->prepare(
        'INSERT INTO user_roles (user_id, role_id) SELECT :user_id, id FROM roles WHERE name = "admin"'
    );
    $role->execute(['user_id' => $userId]);
    if ($role->rowCount() !== 1) {
        throw new RuntimeException('Run database/seed.sql before creating an administrator.');
    }

    audit_event($userId, 'initial_admin_created', 'user', $userId);
    $pdo->commit();
    fwrite(STDOUT, "Administrator account created. Remove the setup credentials from the environment.\n");
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Initial admin setup failed: ' . $exception::class);
    fwrite(STDERR, "Administrator setup failed. Check the database schema and ensure this email is not already registered.\n");
    exit(1);
}
