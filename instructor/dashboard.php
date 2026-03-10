<?php
require_once '../includes/auth.php';
requireLogin();

if (isAdmin()) {
    header('Location: /admin/dashboard.php');
    exit();
}

$pdo = getDB();
$user_id = $_SESSION['user_id'];

// Get instructor info
$stmt = $pdo->prepare("
    SELECT i.*, u.full_name 
    FROM instructors i 
    JOIN users u ON i.user_id = u.id 
    WHERE i.user_id = ?
");
$stmt->execute([$user_id]);
$instructor = $stmt->fetch();

// Get instructor's specializations
$stmt = $pdo->prepare("
    SELECT s.specialization_name 
    FROM instructor_specializations ism
    JOIN specializations s ON ism.specialization_id = s.id
    WHERE ism.instructor_id = ?
    ORDER BY ism.priority
");
$stmt->execute([$instructor['id']]);
$specializations = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Get instructor's schedule
$stmt = $pdo->prepare("
    SELECT s.*, sub.subject_code, sub.subject_name, 
           r.room_number, ts.day, ts.start_time, ts.end_time
    FROM schedules s
    JOIN subjects sub ON s.subject_id = sub.id
    JOIN rooms r ON s.room_id = r.id
    JOIN time_slots ts ON s.time_slot_id = ts.id
    WHERE s.instructor_id = ? AND s.is_published = 1
    ORDER BY ts.day, ts.start_time
");
$stmt->execute([$instructor['id']]);
$schedule = $stmt->fetchAll();
// Compute summary stats
$total_classes = count($schedule);
$total_hours_per_week = 0.0;
$days_taught = [];
foreach ($schedule as $c) {
    $start = strtotime($c['start_time']);
    $end = strtotime($c['end_time']);
    $duration = ($end - $start) / 3600.0;
    if ($duration > 0) {
        $total_hours_per_week += $duration;
    }
    $days_taught[] = $c['day'];
}
$days_taught = array_values(array_unique($days_taught));

// Determine next upcoming class based on weekday ordering
$dayOrder = [
    'Sunday' => 0, 'Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3,
    'Thursday' => 4, 'Friday' => 5, 'Saturday' => 6
];
$todayIndex = date('w'); // 0 (Sun) - 6 (Sat)
$nextClass = null;
$bestOffset = 8; // max 7 days
foreach ($schedule as $c) {
    $d = isset($dayOrder[$c['day']]) ? $dayOrder[$c['day']] : null;
    if ($d === null) continue;
    $offset = ($d - $todayIndex + 7) % 7;
    // prefer same-day classes that haven't passed yet
    $startTs = strtotime($c['start_time']);
    if ($offset === 0) {
        $now = time();
        $todayStart = strtotime(date('Y-m-d') . ' ' . date('H:i:s', $startTs));
        if ($todayStart <= $now) {
            // class already started today; treat as later in week
            $offset = 7;
        }
    }
    if ($offset < $bestOffset) {
        $bestOffset = $offset;
        $nextClass = $c;
    } elseif ($offset === $bestOffset && $nextClass) {
        // tie-breaker: earlier start time
        if (strtotime($c['start_time']) < strtotime($nextClass['start_time'])) {
            $nextClass = $c;
        }
    }
}

// Group schedule by day
$grouped = [];
foreach ($schedule as $c) {
    $grouped[$c['day']][] = $c;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instructor Dashboard - Academic Scheduling System</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <div class="header">
        <div class="header-content">
            <h1>Instructor Dashboard</h1>
            <div class="user-info">
                <div class="user-meta">
                    <div class="header-inline">
                        <a href="set_availability.php">Manage Availability</a>
                        <span class="sep">/</span>
                        <span class="welcome-text">Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                    </div>
                </div>
                <a href="../logout.php" class="btn-logout">Logout</a>
            </div>
        </div>
    </div>
    
    <div class="container">
        <div class="instructor-info">
            <h2>Your Information</h2>
            <p><strong>Department:</strong> <?php echo $instructor['department']; ?></p>
            <p><strong>Specializations:</strong> <?php echo !empty($specializations) ? htmlspecialchars(implode(', ', $specializations)) : '(No specialization)'; ?></p>
        </div>
        
        <h2>Your Teaching Schedule</h2>

        <div class="dashboard-stats">
            <div class="stat-card">
                <h3>Total Classes</h3>
                <div class="stat-number"><?php echo $total_classes; ?></div>
            </div>
            <div class="stat-card">
                <h3>Hours / Week</h3>
                <div class="stat-number"><?php echo number_format($total_hours_per_week, 1); ?></div>
            </div>
            <div class="stat-card">
                <h3>Days Taught</h3>
                <div class="stat-number"><?php echo htmlspecialchars(implode(', ', $days_taught)); ?></div>
            </div>
            <div class="stat-card">
                <h3>Next Class</h3>
                <div class="stat-number">
                    <?php if ($nextClass): ?>
                        <?php echo htmlspecialchars($nextClass['subject_code'] . ' - ' . $nextClass['subject_name']); ?><br>
                        <small><?php echo htmlspecialchars($nextClass['day'] . ' ' .
                            date('h:i A', strtotime($nextClass['start_time']))); ?></small>
                    <?php else: ?>
                        <small>No upcoming class</small>
                    <?php endif; ?>
                </div>
            </div>
        </div>

            <?php if (empty($schedule)): ?>
                <p>No schedule assigned yet.</p>
            <?php else: ?>
                <?php 
                // Build day order dynamically - Saturday only shows if there are classes
                $orderedDays = ['Monday','Tuesday','Wednesday','Thursday','Friday'];
                $hasSaturday = false;
                foreach ($grouped as $day => $classes) {
                    if ($day === 'Saturday') {
                        $hasSaturday = true;
                    }
                }
                if ($hasSaturday) {
                    $orderedDays[] = 'Saturday';
                }
                ?>
            <?php foreach ($orderedDays as $day): ?>
                <?php if (!empty($grouped[$day])): ?>
                    <h3><?php echo $day; ?></h3>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Subject</th>
                                <th>Room</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($grouped[$day] as $class): ?>
                            <tr>
                                <td><?php echo $class['subject_code'] . ' - ' . $class['subject_name']; ?></td>
                                <td><?php echo $class['room_number']; ?></td>
                                <td><?php echo date('h:i A', strtotime($class['start_time'])) . ' - ' . date('h:i A', strtotime($class['end_time'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <div class="availability-section">
            <h3>Set Your Availability</h3>
            <p>Click here to manage your available time slots for future scheduling.</p>
            <a href="set_availability.php" class="btn-primary">Manage Availability</a>
        </div>
    </div>
</body>
</html>
