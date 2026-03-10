<?php
require_once '../includes/auth.php';
requireAdmin();

$pdo = getDB();
$id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare("SELECT *, COALESCE(slot_type, 'regular') as slot_type FROM time_slots WHERE id = ?");
$stmt->execute([$id]);
$slot = $stmt->fetch(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode($slot);
?>