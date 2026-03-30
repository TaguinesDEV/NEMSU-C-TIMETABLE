<?php
require_once '../includes/auth.php';
requireAdmin();

$pdo = getDB();
$id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare("
    SELECT i.*, u.email, u.full_name, i.program_id 
    FROM instructors i 
    JOIN users u ON i.user_id = u.id 
    WHERE i.id = ?
");
$stmt->execute([$id]);
$instructor = $stmt->fetch(PDO::FETCH_ASSOC);

// Get assigned subject codes first so Edit Instructor reflects Choose Instructor assignments
$stmt = $pdo->prepare("
    SELECT sub.subject_code
    FROM subject_instructor_assignments sia
    JOIN subjects sub ON sia.subject_id = sub.id
    WHERE sia.instructor_id = ?
    ORDER BY sub.subject_code
");
$stmt->execute([$id]);
$assigned_subjects = $stmt->fetchAll(PDO::FETCH_COLUMN);

$stmt = $pdo->prepare("
    SELECT s.specialization_name 
    FROM instructor_specializations ism
    JOIN specializations s ON ism.specialization_id = s.id
    WHERE ism.instructor_id = ?
    ORDER BY ism.priority
");
$stmt->execute([$id]);
$specializations = $stmt->fetchAll(PDO::FETCH_COLUMN);

$merged_specializations = [];
foreach (array_merge($assigned_subjects, $specializations) as $subjectCode) {
    $subjectCode = trim((string)$subjectCode);
    if ($subjectCode !== '' && !in_array($subjectCode, $merged_specializations, true)) {
        $merged_specializations[] = $subjectCode;
    }
}

$instructor['specializations'] = array_slice($merged_specializations, 0, 5);

header('Content-Type: application/json');
echo json_encode($instructor);
?>
