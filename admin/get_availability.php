<?php
require_once '../includes/auth.php';
requireAdmin();

$pdo = getDB();
$instructor_id = $_GET['instructor_id'] ?? 0;

$stmt = $pdo->prepare("SELECT time_slot_id FROM instructor_availability WHERE instructor_id = ? AND is_available = 1");
$stmt->execute([$instructor_id]);
$availability = $stmt->fetchAll(PDO::FETCH_COLUMN);

header('Content-Type: application/json');
echo json_encode($availability);
?>