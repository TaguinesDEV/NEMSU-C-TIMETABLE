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

// Get specializations
$stmt = $pdo->prepare("
    SELECT s.specialization_name 
    FROM instructor_specializations ism
    JOIN specializations s ON ism.specialization_id = s.id
    WHERE ism.instructor_id = ?
    ORDER BY ism.priority
");
$stmt->execute([$id]);
$specializations = $stmt->fetchAll(PDO::FETCH_COLUMN);

$instructor['specializations'] = $specializations;

header('Content-Type: application/json');
echo json_encode($instructor);
?>
