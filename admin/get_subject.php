<?php
require_once '../includes/auth.php';
requireAdmin();

$pdo = getDB();
$id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare("SELECT * FROM subjects WHERE id = ?");
$stmt->execute([$id]);
$subject = $stmt->fetch(PDO::FETCH_ASSOC);

if ($subject) {
    $allowedSemesters = ['1st Semester', '2nd Semester', 'Summer'];
    $semester = trim((string)($subject['semester'] ?? ''));
    $subject['semester'] = in_array($semester, $allowedSemesters, true) ? $semester : '1st Semester';

    $yearLevel = (int)($subject['year_level'] ?? 1);
    $subject['year_level'] = ($yearLevel >= 1 && $yearLevel <= 5) ? $yearLevel : 1;
}

header('Content-Type: application/json');
echo json_encode($subject);
?>
