<?php
require_once __DIR__ . '/../config/database.php';

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function isInstructor() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'instructor';
}

function isProgramChair() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'program_chair';
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: /index.php');
        exit();
    }
}

function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header('Location: /instructor/dashboard.php');
        exit();
    }
}

function requireProgramChair() {
    requireLogin();
    if (!isProgramChair()) {
        header('Location: /index.php');
        exit();
    }
}

function login($username, $password) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['full_name'] = $user['full_name'];
        
        // Load program_id for program_chair role
        if ($user['role'] === 'program_chair') {
            $stmt = $pdo->prepare("SELECT program_id FROM program_chairs WHERE user_id = ?");
            $stmt->execute([$user['id']]);
            $pc = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($pc) {
                $_SESSION['program_id'] = $pc['program_id'];
            }
        }
        
        return true;
    }
    return false;
}

function logout() {
    // Clear all session variables
    $_SESSION = array();
    
    // Destroy the session cookie if it exists
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
    
    // Destroy the session
    session_destroy();
    
    // Redirect to login page
    header('Location: index.php');
    exit();
}
?>