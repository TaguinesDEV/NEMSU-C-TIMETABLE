<?php
require_once 'includes/auth.php';

/* =========================================
   Redirect already logged-in users by role
========================================= */
if (isLoggedIn()) {
    if (isAdmin()) {
        header('Location: admin/dashboard.php');
    } elseif (isProgramChair()) {
        header('Location: program_chair/dashboard.php');
    } else {
        header('Location: instructor/dashboard.php');
    }
    exit();
}

$error = '';

/* =========================================
   Handle login submission
========================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (login($username, $password)) {
        if (isAdmin()) {
            header('Location: admin/dashboard.php');
            exit();
        } elseif (isProgramChair()) {
            header('Location: program_chair/dashboard.php');
            exit();
        } else {
            header('Location: instructor/dashboard.php');
            exit();
        }
    } else {
        $error = 'Invalid username or password';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Scheduling System - Login</title>
    <link rel="stylesheet" href="css/login.css">
</head>
<body>
    <div class="auth-stage">
        <div class="auth-container">
            <section class="auth-form-container">
                <form class="auth-form" method="POST" action="">
                    <h1>Log In</h1>
                    <span>Use your account credentials</span>

                    <?php if ($error): ?>
                        <div class="auth-error"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>

                    <input type="text" id="username" name="username" placeholder="Username" required>
                    <input type="password" id="password" name="password" placeholder="Password" required>

                    <button type="submit">Log In</button>

                   
                </form>
            </section>

            <section class="auth-overlay-container">
                <div class="auth-overlay">
                    <div class="auth-overlay-panel">
                        <img src="assets/logo.png" alt="Academic Scheduling Logo" class="auth-logo">
                        <h2>Academic Scheduling</h2>
                        <p>Secure portal for administrators, program chairs, and instructors.</p>
                    </div>
                </div>
            </section>
        </div>
    </div>
    <canvas id="canvas1"></canvas>
    <script src="assets/js/login.js"></script>
</body>
</html>
