<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

smartenroll_auth_start_session();

$currentUser = smartenroll_current_user();
if ($currentUser !== null && ($_GET['action'] ?? '') !== 'logout') {
    header('Location: dashboard.php');
    exit;
}

if (($_GET['action'] ?? '') === 'logout') {
    smartenroll_logout_user();
    header('Location: login.php?status=logged_out');
    exit;
}

$activeTab = 'login';
$errorMessage = '';
$successMessage = '';
$loginEmailValue = '';
$registerNameValue = '';
$registerEmailValue = '';
$registerRoleValue = 'registrar';
$loginCsrfToken = smartenroll_csrf_token('login_form');
$registerCsrfToken = smartenroll_csrf_token('register_form');

if (($_GET['status'] ?? '') === 'logged_out') {
    $successMessage = 'You have been logged out successfully.';
}

try {
    $conn = smartenroll_auth_db();
    smartenroll_ensure_users_table($conn);
} catch (Throwable $e) {
    $conn = null;
    $errorMessage = 'Database connection failed. Please check the MySQL server and database.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $conn instanceof mysqli) {
    $authAction = $_POST['auth_action'] ?? 'login';

    if ($authAction === 'login') {
        $activeTab = 'login';
        $csrfToken = trim((string)($_POST['csrf_token'] ?? ''));
        $loginEmailValue = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $attemptKey = smartenroll_login_attempt_key($loginEmailValue);

        if (!smartenroll_verify_csrf($csrfToken, 'login_form')) {
            $errorMessage = 'Session verification failed. Please refresh and try again.';
        } elseif (!smartenroll_login_is_allowed($attemptKey, $retryAfterSeconds)) {
            $errorMessage = 'Too many login attempts. Try again in ' . max(1, (int)ceil(((int)$retryAfterSeconds) / 60)) . ' minute(s).';
        } elseif ($loginEmailValue === '' || $password === '') {
            $errorMessage = 'Please enter your email address and password.';
        } else {
            $user = smartenroll_find_user_by_email($conn, $loginEmailValue);

            if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
                smartenroll_record_login_failure($attemptKey);
                $errorMessage = 'The email address or password is incorrect.';
            } else {
                smartenroll_clear_login_failures($attemptKey);
                smartenroll_login_user($user);
                header('Location: dashboard.php');
                exit;
            }
        }
    }

    if ($authAction === 'register') {
        $activeTab = 'register';
        $csrfToken = trim((string)($_POST['csrf_token'] ?? ''));
        $registerNameValue = trim((string) ($_POST['full_name'] ?? ''));
        $registerEmailValue = trim((string) ($_POST['register_email'] ?? ''));
        $registerRoleValue = strtolower(trim((string) ($_POST['role'] ?? 'registrar')));
        $password = (string) ($_POST['register_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
        $allowedRoles = ['admin', 'registrar'];

        if (!smartenroll_verify_csrf($csrfToken, 'register_form')) {
            $errorMessage = 'Session verification failed. Please refresh and try again.';
        } elseif ($registerNameValue === '' || $registerEmailValue === '' || $password === '' || $confirmPassword === '') {
            $errorMessage = 'Please complete all registration fields.';
        } elseif (!filter_var($registerEmailValue, FILTER_VALIDATE_EMAIL)) {
            $errorMessage = 'Please enter a valid email address for registration.';
        } elseif (!in_array($registerRoleValue, $allowedRoles, true)) {
            $errorMessage = 'Only admin and registrar accounts can be registered.';
        } elseif (strlen($password) < 6) {
            $errorMessage = 'Password must be at least 6 characters long.';
        } elseif ($password !== $confirmPassword) {
            $errorMessage = 'Password confirmation does not match.';
        } elseif (smartenroll_find_user_by_email($conn, $registerEmailValue) !== null) {
            $errorMessage = 'That email is already registered.';
        } elseif (smartenroll_find_user_by_role($conn, $registerRoleValue) !== null) {
            $errorMessage = 'A unique ' . ucfirst($registerRoleValue) . ' account already exists.';
        } else {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare('INSERT INTO users (full_name, email, password_hash, role) VALUES (?, ?, ?, ?)');
            $stmt->bind_param('ssss', $registerNameValue, $registerEmailValue, $passwordHash, $registerRoleValue);
            $stmt->execute();
            $stmt->close();

            $successMessage = ucfirst($registerRoleValue) . ' account registered successfully. You can sign in now.';
            $activeTab = 'login';
            $loginEmailValue = $registerEmailValue;
            $registerNameValue = '';
            $registerEmailValue = '';
            $registerRoleValue = 'registrar';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SMARTENROLL | Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="description" content="Staff login page for SMARTENROLL.">
    <link rel="stylesheet" href="css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Bodoni+Moda:opsz,wght@6..96,600;6..96,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body class="login-page">

<header class="landing-header">
    <a href="index.php#main-screen" class="logo" aria-label="Go to main screen">
        <img src="assets/logo.png" alt="Adreo Montessori Inc. Logo">
        <span>SMARTENROLL</span>
    </a>
</header>

<main class="login-main">
    <div class="login-panel">
        <div class="login-intro">
            <h1>Welcome to SMARTENROLL</h1>
            <p>Your official platform for accessing enrollment records, academic resources, and collaboration tools.</p>
            <p class="login-intro-sub">
                Built for Adreo Montessori Inc. to keep admissions organized, accurate, and responsive.
            </p>
            <p class="login-intro-sub">
                Sign in to manage applications, verify requirements, and guide families through each enrollment step.
            </p>
            <div class="login-role-note">
                <h3>Account rules</h3>
                <p>Only one unique <strong>admin</strong> account and one unique <strong>registrar</strong> account can be registered.</p>
            </div>
        </div>

        <div class="login-card" data-active-tab="<?php echo htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="login-brand-mark">
                <img src="assets/logo.png" alt="SMARTENROLL Logo">
            </div>

            <div class="auth-switch" role="tablist" aria-label="Authentication forms">
                <button type="button" class="auth-switch-btn <?php echo $activeTab === 'login' ? 'active' : ''; ?>" data-auth-target="login">Sign In</button>
                <button type="button" class="auth-switch-btn <?php echo $activeTab === 'register' ? 'active' : ''; ?>" data-auth-target="register">Register</button>
            </div>

            <?php if ($errorMessage !== ''): ?>
                <div class="auth-alert auth-alert-error"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <?php if ($successMessage !== ''): ?>
                <div class="auth-alert auth-alert-success"><?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <section class="auth-panel <?php echo $activeTab === 'login' ? 'active' : ''; ?>" data-auth-panel="login">
                <p class="login-subtitle login-subtitle-centered">Use your SMARTENROLL credentials.</p>
                <form class="login-form" id="loginForm" action="login.php" method="post">
                    <input type="hidden" name="auth_action" value="login">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($loginCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <label>
                        Email Address
                        <input type="email" name="email" id="loginEmail" value="<?php echo htmlspecialchars($loginEmailValue, ENT_QUOTES, 'UTF-8'); ?>" placeholder="adreomontessori@gmail.com" required>
                    </label>
                    <label>
                        Password
                        <div class="password-field">
                            <input type="password" name="password" placeholder="Enter your password" required>
                            <span class="password-icon"><i class="fa-solid fa-eye"></i></span>
                        </div>
                    </label>
                    <div class="login-meta">
                        <label class="remember">
                            <input type="checkbox" name="remember" id="rememberLogin">
                            Remember me
                        </label>
                    </div>
                    <button class="login-submit" type="submit">Sign In</button>
                    <div class="login-help">
                        <p>Use the official school account to continue to the SMARTENROLL dashboard.</p>
                    </div>
                </form>
            </section>

            <section class="auth-panel <?php echo $activeTab === 'register' ? 'active' : ''; ?>" data-auth-panel="register">
                <p class="login-subtitle login-subtitle-centered">Create a unique admin or registrar account.</p>
                <form class="login-form" id="registerForm" action="login.php" method="post">
                    <input type="hidden" name="auth_action" value="register">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($registerCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <label>
                        Full Name
                        <input type="text" name="full_name" value="<?php echo htmlspecialchars($registerNameValue, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Enter full name" required>
                    </label>
                    <label>
                        Role
                        <select name="role" required>
                            <option value="admin" <?php echo $registerRoleValue === 'admin' ? 'selected' : ''; ?>>Admin</option>
                            <option value="registrar" <?php echo $registerRoleValue === 'registrar' ? 'selected' : ''; ?>>Registrar</option>
                        </select>
                    </label>
                    <label>
                        Email Address
                        <input type="email" name="register_email" value="<?php echo htmlspecialchars($registerEmailValue, ENT_QUOTES, 'UTF-8'); ?>" placeholder="name@example.com" required>
                    </label>
                    <label>
                        Password
                        <div class="password-field">
                            <input type="password" name="register_password" placeholder="At least 6 characters" required>
                            <span class="password-icon"><i class="fa-solid fa-eye"></i></span>
                        </div>
                    </label>
                    <label>
                        Confirm Password
                        <div class="password-field">
                            <input type="password" name="confirm_password" placeholder="Re-enter password" required>
                            <span class="password-icon"><i class="fa-solid fa-eye"></i></span>
                        </div>
                    </label>
                    <button class="login-submit" type="submit">Create Account</button>
                    <div class="login-help">
                        <p>Each role is limited to one registered account only.</p>
                    </div>
                </form>
            </section>
        </div>
    </div>
</main>

<script src="js/script.js"></script>
<script src="js/login.js"></script>
</body>
</html>
