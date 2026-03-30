<?php
require_once '../includes/auth.php';
requireAdmin();

$pdo = getDB();
$job_id = $_GET['job_id'] ?? 0;

$has_scheduled_minutes = false;
try {
    $pdo->exec("ALTER TABLE schedules ADD COLUMN scheduled_minutes INT NULL AFTER scheduled_hours");
    $has_scheduled_minutes = true;
} catch (Exception $e) {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM schedules LIKE 'scheduled_minutes'");
        $has_scheduled_minutes = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $inner) {
        $has_scheduled_minutes = false;
    }
}

// Get job details
$stmt = $pdo->prepare("SELECT job_name FROM schedule_jobs WHERE id = ?");
$stmt->execute([$job_id]);
$job = $stmt->fetch();

if (!$job) {
    header('Location: view_schedules.php?error=' . urlencode('Schedule job not found.'));
    exit();
}

// Get schedule data
$stmt = $pdo->prepare("
    SELECT 
        sub.subject_code,
        sub.subject_name,
        u.full_name as instructor,
        r.room_number,
        ts.day,
        ts.start_time,
        ts.end_time,
        " . ($has_scheduled_minutes ? "s.scheduled_minutes" : "NULL") . " AS scheduled_minutes,
        s.department
    FROM schedules s
    JOIN subjects sub ON s.subject_id = sub.id
    JOIN instructors i ON s.instructor_id = i.id
    JOIN users u ON i.user_id = u.id
    JOIN rooms r ON s.room_id = r.id
    JOIN time_slots ts ON s.time_slot_id = ts.id
    WHERE s.job_id = ?
    ORDER BY FIELD(ts.day, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'), ts.start_time
");
$stmt->execute([$job_id]);
$schedules = $stmt->fetchAll();

// Set headers for CSV download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="schedule_' . $job['job_name'] . '_' . date('Y-m-d') . '.csv"');

// Create output stream
$output = fopen('php://output', 'w');

// Add UTF-8 BOM for Excel
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Add headers
fputcsv($output, ['Day', 'Start Time', 'End Time', 'Subject Code', 'Subject Name', 'Instructor', 'Room', 'Department']);

// Add data
foreach ($schedules as $schedule) {
    fputcsv($output, [
        $schedule['day'],
        date('h:i A', strtotime($schedule['start_time'])),
        date('h:i A', strtotime(!empty($schedule['scheduled_minutes']) ? $schedule['start_time'] . ' +' . (int)$schedule['scheduled_minutes'] . ' minutes' : $schedule['end_time'])),
        $schedule['subject_code'],
        $schedule['subject_name'],
        $schedule['instructor'],
        $schedule['room_number'],
        $schedule['department']
    ]);
}

fclose($output);
exit();
?>
