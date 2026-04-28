<?php
function academicSchedulingEnv(string $key, string $default = ''): string {
    $value = getenv($key);
    if ($value === false) {
        return $default;
    }

    $value = trim((string)$value);
    return $value !== '' ? $value : $default;
}

define('DB_HOST', academicSchedulingEnv('ACADEMIC_SCHEDULING_DB_HOST', 'localhost'));
define('DB_USER', academicSchedulingEnv('ACADEMIC_SCHEDULING_DB_USER', 'root'));
define('DB_PASS', academicSchedulingEnv('ACADEMIC_SCHEDULING_DB_PASS', ''));
define('DB_NAME', academicSchedulingEnv('ACADEMIC_SCHEDULING_DB_NAME', 'academic_scheduling'));
define('DB_CHARSET', academicSchedulingEnv('ACADEMIC_SCHEDULING_DB_CHARSET', 'utf8mb4'));

// Python script path
// Uses CP-SAT (OR-Tools) when available for faster feasibility; falls back to GA.
define('PYTHON_SCRIPT_PATH', academicSchedulingEnv('ACADEMIC_SCHEDULING_PYTHON_SCRIPT', __DIR__ . '/../python_solver/run_solver.py'));

function detect_python_executable() {
    // Allow override (useful if Apache/PHP can't see your PATH).
    $override = getenv('ACADEMIC_SCHEDULING_PYTHON');
    if (is_string($override) && trim($override) !== '' && file_exists($override)) {
        return $override;
    }

    if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        return 'python3';
    }

    $candidates = [];

    $localAppData = getenv('LOCALAPPDATA');
    if (is_string($localAppData) && trim($localAppData) !== '') {
        // Typical per-user installs.
        foreach (glob($localAppData . '\\Programs\\Python\\Python*\\python.exe') as $path) {
            $candidates[] = $path;
        }
        // Python launcher (if present).
        $pyLauncher = $localAppData . '\\Programs\\Python\\Launcher\\py.exe';
        if (file_exists($pyLauncher)) {
            $candidates[] = $pyLauncher;
        }
    }

    // Common system installs.
    $candidates[] = 'C:\\Python313\\python.exe';
    $candidates[] = 'C:\\Python312\\python.exe';
    $candidates[] = 'C:\\Python311\\python.exe';

    foreach ($candidates as $path) {
        if (is_string($path) && $path !== '' && file_exists($path)) {
            return $path;
        }
    }

    // Fallback to PATH lookup.
    return 'python';
}

define('PYTHON_PATH', detect_python_executable()); // or 'python3' on Linux/Mac

function getDB() {
    try {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        die('Database connection failed: ' . $e->getMessage());
    }
}

session_start();
?>
