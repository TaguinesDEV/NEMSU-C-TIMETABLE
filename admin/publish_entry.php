<?php
require_once '../includes/auth.php';
requireAdmin();

$pdo = getDB();
$id = $_GET['id'] ?? 0;
$job_id = $_GET['job_id'] ?? 0;

if ($id) {
    // Prevent publishing an entry that conflicts with already-published schedules
    $stmt = $pdo->prepare("
        SELECT s.id, s.room_id, s.instructor_id, s.time_slot_id
        FROM schedules s
        WHERE s.id = ?
    ");
    $stmt->execute([$id]);
    $entry = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($entry) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM schedules sp
            WHERE sp.is_published = 1
              AND sp.id <> ?
              AND sp.time_slot_id = ?
              AND (sp.room_id = ? OR sp.instructor_id = ?)
        ");
        $stmt->execute([
            $entry['id'],
            $entry['time_slot_id'],
            $entry['room_id'],
            $entry['instructor_id']
        ]);
        $has_conflict = (int) $stmt->fetchColumn() > 0;

        if ($has_conflict) {
            header("Location: view_schedules.php?job_id=$job_id&error=Cannot publish entry due to conflict with an already published schedule");
            exit();
        }
    }

    $stmt = $pdo->prepare("UPDATE schedules SET is_published = 1 WHERE id = ?");
    $stmt->execute([$id]);
}

header("Location: view_schedules.php?job_id=$job_id&message=Schedule entry published");
exit();
?>
