<?php
require_once __DIR__ . '/../config/database.php';

function getAppBasePath() {
    static $basePath = null;
    if ($basePath !== null) {
        return $basePath;
    }

    $documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    $appRoot = realpath(__DIR__ . '/..');

    if ($documentRoot && $appRoot) {
        $normalizedDocumentRoot = str_replace('\\', '/', $documentRoot);
        $normalizedAppRoot = str_replace('\\', '/', $appRoot);

        if (strpos($normalizedAppRoot, $normalizedDocumentRoot) === 0) {
            $basePath = substr($normalizedAppRoot, strlen($normalizedDocumentRoot));
            $basePath = '/' . trim((string) $basePath, '/');
            if ($basePath === '//') {
                $basePath = '';
            }
            return $basePath;
        }
    }

    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $scriptDir = trim((string) dirname($scriptName), '/');
    if ($scriptDir === '' || $scriptDir === '.') {
        $basePath = '';
    } else {
        $segments = explode('/', $scriptDir);
        $basePath = '/' . $segments[0];
    }

    return $basePath;
}

function buildAppUrl($path = '') {
    $basePath = getAppBasePath();
    $normalizedPath = '/' . ltrim((string) $path, '/');
    if ($normalizedPath === '/') {
        return $basePath !== '' ? $basePath . '/' : '/';
    }
    return ($basePath !== '' ? $basePath : '') . $normalizedPath;
}

function redirectToApp($path) {
    header('Location: ' . buildAppUrl($path));
    exit();
}

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
        redirectToApp('index.php');
    }
}

function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        if (isProgramChair()) {
            redirectToApp('program_chair/dashboard.php');
        }
        redirectToApp('instructor/dashboard.php');
    }
}

function requireProgramChair() {
    requireLogin();
    if (!isProgramChair()) {
        if (isAdmin()) {
            redirectToApp('admin/dashboard.php');
        }
        redirectToApp('index.php');
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
    redirectToApp('index.php');
}
?>
