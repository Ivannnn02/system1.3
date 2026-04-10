<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function smartenroll_auth_start_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function smartenroll_auth_db(): mysqli
{
    $conn = new mysqli('127.0.0.1', 'root', '', 'smartenroll');
    $conn->set_charset('utf8mb4');

    return $conn;
}

function smartenroll_ensure_users_table(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS users (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            full_name VARCHAR(150) NOT NULL,
            email VARCHAR(150) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(20) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_users_email (email),
            UNIQUE KEY uniq_users_role (role)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
}

function smartenroll_find_user_by_email(mysqli $conn, string $email): ?array
{
    $stmt = $conn->prepare('SELECT id, full_name, email, password_hash, role FROM users WHERE email = ? LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    return $user ?: null;
}

function smartenroll_find_user_by_role(mysqli $conn, string $role): ?array
{
    $stmt = $conn->prepare('SELECT id, full_name, email, role FROM users WHERE role = ? LIMIT 1');
    $stmt->bind_param('s', $role);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    return $user ?: null;
}

function smartenroll_login_user(array $user): void
{
    smartenroll_auth_start_session();
    session_regenerate_id(true);
    $_SESSION['auth_user'] = [
        'id' => (int) $user['id'],
        'full_name' => $user['full_name'],
        'email' => $user['email'],
        'role' => $user['role'],
    ];
}

function smartenroll_current_user(): ?array
{
    smartenroll_auth_start_session();

    return isset($_SESSION['auth_user']) && is_array($_SESSION['auth_user'])
        ? $_SESSION['auth_user']
        : null;
}

function smartenroll_require_login(): array
{
    $user = smartenroll_current_user();
    if ($user === null) {
        header('Location: login.php');
        exit;
    }

    return $user;
}

function smartenroll_logout_user(): void
{
    smartenroll_auth_start_session();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    session_destroy();
}
