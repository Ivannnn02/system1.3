<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SMARTENROLL | Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="description" content="Staff login page for SMARTENROLL.">

    <!-- CSS -->
    <link rel="stylesheet" href="css/style.css">

    <!-- Google Font -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Bodoni+Moda:opsz,wght@6..96,600;6..96,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body class="login-page">

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
        </div>

        <div class="login-card">
            <div class="login-brand-mark">
                <img src="assets/logo.png" alt="SMARTENROLL Logo">
            </div>
            <p class="login-subtitle login-subtitle-centered">Use your SMARTENROLL credentials.</p>
            <form class="login-form" id="loginForm" action="dashboard.php" method="post">
                <label>
                    Email Address
                    <input type="email" name="email" id="loginEmail" placeholder="adreomontessori@gmail.com" required>
                </label>
                <label>
                    Password
                    <div class="password-field">
                        <input type="password" name="password" placeholder="adreo" required>
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
        </div>
    </div>
</main>

<div class="login-popup-overlay" id="loginErrorPopup">
    <div class="login-popup-box">
        <div class="login-popup-icon" id="loginPopupIcon">
            <img src="assets/logo.png" alt="SMARTENROLL Logo" class="login-popup-logo" id="loginPopupLogo">
            <i class="fa-solid fa-circle-exclamation" id="loginPopupAlert"></i>
        </div>
        <h3>Invalid Login</h3>
        <p>The email address or password is incorrect. Please try again.</p>
        <button type="button" class="login-popup-btn" id="closeLoginPopup">OK</button>
    </div>
</div>

<script src="js/script.js"></script>
<script src="js/login.js"></script>
</body>
</html>
