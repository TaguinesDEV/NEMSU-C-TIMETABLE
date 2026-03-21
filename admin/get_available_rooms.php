<?php
require_once '../includes/auth.php';
requireAdmin();

$pdo = getDB();

$time_slot_id = isset($_GET['time_slot_id']) ? (int)$_GET['time_slot_id'] : 0;
$current_schedule_id = isset($_GET['schedule_id']) ? (int)$_GET['schedule_id'] : 0;

if ($time_slot_id === 0) {
    header("HTTP/1.1 400 Bad Request");
    echo json_encode(['error' => 'Time Slot ID is required.']);
    exit();
}

// Find rooms that are already booked in PUBLISHED schedules at this time slot.
// We exclude the current schedule entry we are editing from this check.
$stmt = $pdo->prepare("
    SELECT DISTINCT room_id
    FROM schedules
    WHERE time_slot_id = :time_slot_id
      AND id <> :schedule_id
");
$stmt->execute([
    ':time_slot_id' => $time_slot_id,
    ':schedule_id' => $current_schedule_id
]);
$booked_room_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Get all rooms
$all_rooms_stmt = $pdo->query("SELECT id, room_number, building, capacity FROM rooms ORDER BY room_number ASC");
$all_rooms = $all_rooms_stmt->fetchAll(PDO::FETCH_ASSOC);

// Filter out the booked rooms
$available_rooms = [];
if (empty($booked_room_ids)) {
    $available_rooms = $all_rooms;
} else {
    $booked_ids_map = array_flip($booked_room_ids);
    foreach ($all_rooms as $room) {
        if (!isset($booked_ids_map[$room['id']])) {
            $available_rooms[] = $room;
        }
    }
}

header('Content-Type: application/json');
echo json_encode($available_rooms);
?>