<?php
require_once '../includes/auth.php';
requireAdmin();

$pdo = getDB();
$job_id = $_GET['job_id'] ?? 0;
$message = '';
$error = '';
$conflict_ids = [];

// Load conflict info from session and then clear it
if (isset($_SESSION['publish_error'])) {
    $error = $_SESSION['publish_error'];
    unset($_SESSION['publish_error']);
}
if (isset($_SESSION['publish_conflict_ids'])) {
    // Flip for faster lookups: [id1, id2] -> [id1 => true, id2 => true]
    $conflict_ids = array_flip($_SESSION['publish_conflict_ids']);
    unset($_SESSION['publish_conflict_ids']);
}

if (isset($_GET['message'])) {
    $message = $_GET['message'];
}
// Do not overwrite the session error if a GET error is also present
if (isset($_GET['error']) && empty($error)) {
    $error = $_GET['error'];
}

// Handle schedule actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['approve_schedule'])) {
        // Block publishing if this job conflicts with already-published schedules from other jobs
        $stmt = $pdo->prepare("
            SELECT
                sn.id AS new_schedule_id,
                sp.id AS published_schedule_id,
                ts.day,
                ts.start_time,
                ts.end_time,
                r1.room_number AS new_room,
                r2.room_number AS published_room,
                u1.full_name AS new_instructor,
                u2.full_name AS published_instructor,
                CASE
                    WHEN sn.room_id = sp.room_id THEN 'room'
                    ELSE 'instructor'
                END AS conflict_type
            FROM schedules sn
            JOIN schedules sp
              ON sn.time_slot_id = sp.time_slot_id
             AND sp.is_published = 1
             AND sp.job_id <> sn.job_id
             AND (sn.room_id = sp.room_id OR sn.instructor_id = sp.instructor_id)
            JOIN time_slots ts ON sn.time_slot_id = ts.id
            JOIN rooms r1 ON sn.room_id = r1.id
            JOIN rooms r2 ON sp.room_id = r2.id
            JOIN instructors i1 ON sn.instructor_id = i1.id
            JOIN users u1 ON i1.user_id = u1.id
            JOIN instructors i2 ON sp.instructor_id = i2.id
            JOIN users u2 ON i2.user_id = u2.id
            WHERE sn.job_id = ?
            LIMIT 5
        ");
        $stmt->execute([$job_id]);
        $publish_conflicts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($publish_conflicts)) {
            $parts = [];
            foreach ($publish_conflicts as $c) {
                $time_text = date('g:i A', strtotime($c['start_time'])) . '-' . date('g:i A', strtotime($c['end_time']));
                if ($c['conflict_type'] === 'room') {
                    $parts[] = "Room conflict on {$c['day']} {$time_text} ({$c['new_room']})";
                } else {
                    $parts[] = "Instructor conflict on {$c['day']} {$time_text} ({$c['new_instructor']})";
                }
            }
            // Store error and conflict IDs in session, then redirect
            $_SESSION['publish_error'] = "Cannot publish schedule due to conflicts with already published schedules: " . implode('; ', $parts) . ".";
            $_SESSION['publish_conflict_ids'] = array_column($publish_conflicts, 'new_schedule_id');
            
            header("Location: view_schedules.php?job_id=$job_id");
            exit();

        } else {
            $stmt = $pdo->prepare("UPDATE schedules SET is_published = 1 WHERE job_id = ?");
            $stmt->execute([$job_id]);

            // Redirect with success message
            header("Location: view_schedules.php?job_id=$job_id&message=" . urlencode("Schedule has been approved and published successfully!"));
            exit();
        }
    }
    
    if (isset($_POST['modify_schedule'])) {
        // Redirect to generate page with pre-filled data
        header("Location: generate_schedule.php?modify_job=$job_id");
        exit();
    }
    
    if (isset($_POST['regenerate_schedule'])) {
        // Create a new job based on this one
        $stmt = $pdo->prepare("SELECT * FROM schedule_jobs WHERE id = ?");
        $stmt->execute([$job_id]);
        $old_job = $stmt->fetch();
        
        // Create new job
        $stmt = $pdo->prepare("
            INSERT INTO schedule_jobs (job_name, status, created_by, input_data) 
            VALUES (?, 'pending', ?, ?)
        ");
        $new_job_name = $old_job['job_name'] . ' (Regenerated)';
        $stmt->execute([$new_job_name, $_SESSION['user_id'], $old_job['input_data']]);
        $new_job_id = $pdo->lastInsertId();
        
        // Trigger GA script (same as generate_schedule.php)
        $script_path = PYTHON_SCRIPT_PATH;
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $script_path = str_replace('/', '\\', $script_path);
            $script_path = '"' . $script_path . '"';
        }
        $command = PYTHON_PATH . ' ' . $script_path . ' ' . (int) $new_job_id;
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            pclose(popen('start /B cmd /c ' . $command . ' 1> nul 2>&1', 'r'));
        } else {
            exec($command . ' > /dev/null 2>&1 &');
        }
        
        header("Location: view_schedules.php?job_id=$job_id&message=" . urlencode("New schedule generation job has been started based on this schedule."));
        exit();
    }
}

// Get job details
$stmt = $pdo->prepare("
    SELECT j.*, u.full_name as created_by_name 
    FROM schedule_jobs j 
    JOIN users u ON j.created_by = u.id 
    WHERE j.id = ?
");
$stmt->execute([$job_id]);
$job = $stmt->fetch();

if (!$job) {
    header('Location: dashboard.php');
    exit();
}

function formatDurationCompact($seconds) {
    $seconds = max(0, (int)$seconds);
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $secs = $seconds % 60;
    if ($hours > 0) {
        return $hours . 'h ' . $minutes . 'm';
    }
    if ($minutes > 0) {
        return $minutes . 'm ' . $secs . 's';
    }
    return $secs . 's';
}

// Get schedule entries with all details
$stmt = $pdo->prepare("
    SELECT 
        s.*,
        sub.subject_code,
        sub.subject_name,
        sub.credits,
        sub.hours_per_week,
        i.id as instructor_id,
        u.full_name as instructor_name,
        i.department as instructor_dept,
        r.room_number,
        r.capacity,
        r.building,
        ts.day,
        ts.start_time,
        ts.end_time
    FROM schedules s
    JOIN subjects sub ON s.subject_id = sub.id
    JOIN instructors i ON s.instructor_id = i.id
    JOIN users u ON i.user_id = u.id
    JOIN rooms r ON s.room_id = r.id
    JOIN time_slots ts ON s.time_slot_id = ts.id
    WHERE s.job_id = ?
    ORDER BY ts.day, ts.start_time
");
$stmt->execute([$job_id]);
$schedules = $stmt->fetchAll();

// Group schedules by day for better display
$grouped_schedules = [];
foreach ($schedules as $schedule) {
    $day = $schedule['day'];
    if (!isset($grouped_schedules[$day])) {
        $grouped_schedules[$day] = [];
    }
    $grouped_schedules[$day][] = $schedule;
}

// Define day order - dynamically based on available schedule days
$day_order = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
// Add Saturday only if there are schedules for Saturday
if (!empty($grouped_schedules) && isset($grouped_schedules['Saturday'])) {
    $day_order[] = 'Saturday';
}

// Check for conflicts in the schedule
$conflicts = [];
$time_room_usage = [];
$time_instructor_usage = [];

foreach ($schedules as $schedule) {
    // Check room conflicts
    $room_key = $schedule['time_slot_id'] . '_' . $schedule['room_id'];
    if (isset($time_room_usage[$room_key])) {
        $conflicts[] = "Room {$schedule['room_number']} is double-booked at " . 
                       date('h:i A', strtotime($schedule['start_time'])) . " on {$schedule['day']}";
    } else {
        $time_room_usage[$room_key] = true;
    }
    
    // Check instructor conflicts
    $instructor_key = $schedule['time_slot_id'] . '_' . $schedule['instructor_id'];
    if (isset($time_instructor_usage[$instructor_key])) {
        $conflicts[] = "Instructor {$schedule['instructor_name']} is double-booked at " . 
                       date('h:i A', strtotime($schedule['start_time'])) . " on {$schedule['day']}";
    } else {
        $time_instructor_usage[$instructor_key] = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Schedule - Academic Scheduling System</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        /* Additional styles for view_schedules.php */
        .job-info-card {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .job-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .schedule-actions {
            margin: 20px 0;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 5px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .day-schedule {
            margin-bottom: 30px;
        }
        
        .day-schedule h3 {
            background: #667eea;
            color: white;
            padding: 10px;
            border-radius: 5px 5px 0 0;
            margin: 0;
        }
        
        .schedule-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .schedule-table th {
            background: #f2f2f2;
            padding: 12px;
            text-align: left;
        }
        
        .schedule-table td {
            padding: 12px;
            border-bottom: 1px solid #dee2e6;
        }
        
        .published-row {
            background-color: #d4edda;
        }

        .conflict-row {
            background-color: #f8d7da !important;
            font-weight: bold;
            border: 1px solid #dc3545;
        }
        
        .published-badge {
            background: #28a745;
            color: white;
            padding: 10px 20px;
            border-radius: 5px;
            display: inline-block;
        }
        
        .processing-message,
        .pending-message {
            text-align: center;
            padding: 50px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .progress-bar {
            width: 100%;
            height: 20px;
            background: #e9ecef;
            border-radius: 10px;
            margin: 20px 0;
            overflow: hidden;
        }
        
        .progress {
            height: 100%;
            background: #667eea;
            animation: progress-animation 2s ease-in-out infinite;
        }
        
        @keyframes progress-animation {
            0% { width: 0%; }
            50% { width: 70%; }
            100% { width: 100%; }
        }
        
        .summary-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .stat {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            text-align: center;
        }
        
        .stat-label {
            display: block;
            font-size: 14px;
            color: #6c757d;
            margin-bottom: 5px;
        }
        
        .stat-value {
            display: block;
            font-size: 24px;
            font-weight: bold;
            color: #2c3e50;
        }
        
        .input-data-section {
            margin-top: 30px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 5px;
        }
        
        details {
            cursor: pointer;
        }
        
        details summary {
            padding: 10px;
            background: #e9ecef;
            border-radius: 5px;
        }
        
        details[open] summary {
            margin-bottom: 15px;
        }
        
        .input-data {
            padding: 15px;
            background: white;
            border-radius: 5px;
        }
        
        .btn-icon {
            padding: 8px 12px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: background-color 0.3s, transform 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            text-decoration: none;
            color: white;
        }
        
        .btn-icon:hover {
            transform: translateY(-2px);
        }

        .btn-edit { background-color: #ffc107; color: #212529; }
        .btn-edit:hover { background-color: #e0a800; }
        .btn-publish { background-color: #28a745; color: #fff; }
        .btn-publish:hover { background-color: #218838; }

        @media print {
            .header, .nav-links, .schedule-actions, .btn-logout, 
            .input-data-section, .job-info-card, .btn-icon {
                display: none;
            }
            
            .day-schedule {
                page-break-inside: avoid;
            }
            
            .schedule-table {
                font-size: 10pt;
            }
            
            .published-badge {
                background: none;
                color: black;
                border: 1px solid black;
            }
        }
        /* Edit Modal Styles */
        .edit-modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.5);
            animation: modalFadeIn 0.3s ease-out;
        }
        
        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 0;
            border-radius: 12px;
            width: 90%;
            max-width: 600px;
            max-height: 85vh;
            overflow: hidden;
            transform: scale(0.8) translateY(-50px);
            animation: modalSlideIn 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94) forwards;
            box-shadow: 0 25px 50px rgba(0,0,0,0.25);
        }
        
        .modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h3 {
            margin: 0;
            font-size: 22px;
        }
        
        .close-modal {
            color: white;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            background: none;
            border: none;
            padding: 0;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background 0.2s;
        }
        
        .close-modal:hover {
            background: rgba(255,255,255,0.2);
        }
        
        .modal-body {
            padding: 25px;
            max-height: 60vh;
            overflow-y: auto;
        }
        
        .modal-footer {
            padding: 20px 25px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }
        
        .loading {
            text-align: center;
            padding: 40px;
            color: #6b7280;
        }
        
        .spinner {
            border: 3px solid #f3f4f6;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 15px;
        }
        
        @keyframes modalFadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes modalSlideIn {
            to {
                transform: scale(1) translateY(0);
            }
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        @media (max-width: 768px) {
            .modal-content {
                width: 95%;
                margin: 10% auto;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-content">
            <h1>View Generated Schedule</h1>
            <div class="nav-links">
                <a href="dashboard.php">Dashboard</a>
                <a href="generate_schedule.php">New Schedule</a>
                <a href="report.php">Reports</a>
                <a href="../logout.php">Logout</a>
            </div>
        </div>
    </div>
    
    <div class="container">
        <!-- Messages -->
        <?php if ($message): ?>
            <div class="success"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="error-overlay" style="position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.85); z-index: 9999; display: flex; align-items: center; justify-content: center; color: white; font-size: 18px; padding: 20px; text-align: center;">
                <div style="max-width: 90%; max-height: 90%; overflow: auto; background: rgba(255,255,255,0.1); border-radius: 12px; padding: 40px; backdrop-filter: blur(10px);">
                    <h2 style="color: #fee2e2; margin-bottom: 20px;">⚠️ Publish Conflict Detected</h2>
                    <p style="line-height: 1.6; margin-bottom: 20px;"><?php echo htmlspecialchars($error); ?></p>
                    <button onclick="this.parentElement.parentElement.style.display='none'" style="background: #ef4444; color: white; border: none; padding: 12px 24px; border-radius: 8px; font-size: 16px; cursor: pointer;">Close</button>
                </div>
            </div>
            <script>
                // Auto-focus close button and restore scroll/conflict highlights
                document.addEventListener('DOMContentLoaded', function() {
                    const overlay = document.querySelector('.error-overlay');
                    if (overlay) {
                        overlay.querySelector('button').focus();
                        document.body.style.overflow = 'hidden';
                        overlay.addEventListener('click', function(e) {
                            if (e.target === this || e.target.matches('button')) {
                                this.style.display = 'none';
                                document.body.style.overflow = '';
                                // Scroll to top and highlight conflicts
                                window.scrollTo(0, 0);
                                setTimeout(() => {
                                    const conflictRows = document.querySelectorAll('.conflict-row');
                                    if (conflictRows.length > 0) {
                                        conflictRows[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
                                    }
                                }, 200);
                            }
                        });
                        // Close on ESC key
                        document.addEventListener('keydown', function(e) {
                            if (e.key === 'Escape') {
                                overlay.style.display = 'none';
                                document.body.style.overflow = '';
                                window.scrollTo(0, 0);
                            }
                        });
                    }
                });
            </script>
        <?php endif; ?>
        
        <!-- Job Information -->
        <div class="job-info-card">
            <h2>Schedule Generation Job Details</h2>
            <div class="job-details">
                <p><strong>Job Name:</strong> <?php echo htmlspecialchars($job['job_name']); ?></p>
                <p><strong>Status:</strong> 
                    <span class="status-badge status-<?php echo $job['status']; ?>">
                        <?php echo $job['status']; ?>
                    </span>
                </p>
                <p><strong>Created By:</strong> <?php echo $job['created_by_name']; ?></p>
                <p><strong>Created At:</strong> <?php echo date('F j, Y g:i A', strtotime($job['created_at'])); ?></p>
                <?php if ($job['completed_at']): ?>
                <p><strong>Completed At:</strong> <?php echo date('F j, Y g:i A', strtotime($job['completed_at'])); ?></p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Conflict Warning -->
        <?php if (!empty($conflicts) && $job['status'] == 'completed'): ?>
            <div class="error">
                <h3>⚠️ Conflicts Detected in Generated Schedule</h3>
                <ul>
                    <?php foreach ($conflicts as $conflict): ?>
                        <li><?php echo $conflict; ?></li>
                    <?php endforeach; ?>
                </ul>
                <p>The genetic algorithm may need to run longer or constraints may need adjustment.</p>
            </div>
        <?php endif; ?>
        
        <!-- Schedule Display -->
        <?php if ($job['status'] == 'completed'): ?>
            <div class="schedule-view">
                <h2>Generated Schedule</h2>
                
                <!-- Action Buttons -->
                <div class="schedule-actions">
                    <?php if (empty($schedules) || !$schedules[0]['is_published']): ?>
                        <form method="POST" style="display: inline;">
                            <button type="submit" name="approve_schedule" class="btn-icon btn-publish" 
                                    onclick="return confirm('Approve and publish this schedule?')">
                                <i class="fas fa-check"></i> Approve Schedule
                            </button>
                        </form>
                        
                        <form method="POST" style="display: inline;">
                            <button type="submit" name="modify_schedule" class="btn-icon btn-edit">
                                <i class="fas fa-pencil-alt"></i> Modify Schedule
                            </button>
                        </form>
                        
                        <form method="POST" style="display: inline;">
                            <button type="submit" name="regenerate_schedule" class="btn-icon">
                                <i class="fas fa-sync-alt"></i> Regenerate Schedule
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="published-badge">
                            <i class="fas fa-check-circle"></i> This schedule is published and approved
                        </div>
                    <?php endif; ?>
                    
                    <a href="javascript:window.print()" class="btn-icon"><i class="fas fa-print"></i> Print Schedule</a>
                    <a href="export_schedule.php?job_id=<?php echo $job_id; ?>" class="btn-icon"><i class="fas fa-file-csv"></i> Export CSV</a>
                </div>
                
                <!-- Schedule by Day -->
                <?php foreach ($day_order as $day): ?>
                    <?php if (isset($grouped_schedules[$day])): ?>
                        <div class="day-schedule">
                            <h3><?php echo $day; ?></h3>
                            <table class="schedule-table">
                                <thead>
                                    <tr>
                                        <th>Time</th>
                                        <th>Subject</th>
                                        <th>Instructor</th>
                                        <th>Room</th>
                                        <th>Department</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    // Sort by time
                                    usort($grouped_schedules[$day], function($a, $b) {
                                        return strtotime($a['start_time']) - strtotime($b['start_time']);
                                    });
                                    
                                    foreach ($grouped_schedules[$day] as $schedule): 
                                    ?>
                                    <tr class="<?php echo $schedule['is_published'] ? 'published-row' : ''; ?> <?php echo isset($conflict_ids[$schedule['id']]) ? 'conflict-row' : ''; ?>">
                                        <td>
                                            <?php echo date('g:i A', strtotime($schedule['start_time'])); ?> - 
                                            <?php echo date('g:i A', strtotime($schedule['end_time'])); ?>
                                        </td>
                                        <td>
                                            <strong><?php echo $schedule['subject_code']; ?></strong><br>
                                            <small><?php echo $schedule['subject_name']; ?></small>
                                        </td>
                                        <td><?php echo $schedule['instructor_name']; ?></td>
                                        <td>
                                            <?php echo $schedule['room_number']; ?><br>
                                            <small><?php echo $schedule['building']; ?> (Cap: <?php echo $schedule['capacity']; ?>)</small>
                                        </td>
                                        <td><?php echo $schedule['department']; ?></td>
                                        <td>
<a href="#" onclick="loadEditModal(<?php echo $schedule['id']; ?>, '<?php echo $job_id; ?>')" class="btn-icon btn-edit"><i class="fas fa-edit"></i> Edit</a>
                                            <?php if (!$schedule['is_published']): ?>
                                            <a href="publish_entry.php?id=<?php echo $schedule['id']; ?>&job_id=<?php echo $job_id; ?>" 
                                               class="btn-icon btn-publish"><i class="fas fa-check"></i> Publish</a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
                
                <!-- Schedule Summary -->
                <div class="schedule-summary">
                    <h3>Schedule Summary</h3>
                    <div class="summary-stats">
                        <div class="stat">
                            <span class="stat-label">Total Classes:</span>
                            <span class="stat-value"><?php echo count($schedules); ?></span>
                        </div>
                        <div class="stat">
                            <span class="stat-label">Unique Instructors:</span>
                            <span class="stat-value">
                                <?php 
                                $unique_instructors = array_unique(array_column($schedules, 'instructor_id'));
                                echo count($unique_instructors);
                                ?>
                            </span>
                        </div>
                        <div class="stat">
                            <span class="stat-label">Rooms Used:</span>
                            <span class="stat-value">
                                <?php 
                                $unique_rooms = array_unique(array_column($schedules, 'room_id'));
                                echo count($unique_rooms);
                                ?>
                            </span>
                        </div>
                        <div class="stat">
                            <span class="stat-label">Days Used:</span>
                            <span class="stat-value"><?php echo count($grouped_schedules); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        <?php elseif ($job['status'] == 'processing'): ?>
            <?php $progress_percent = max(1, min(99, (int)($job['progress_percent'] ?? 50))); ?>
            <?php
                $current_generation = max(0, (int)($job['current_generation'] ?? 0));
                $total_generations = max(0, (int)($job['total_generations'] ?? 0));
                $best_fitness = max(0, min(100, (int)($job['best_fitness'] ?? 0)));
            ?>
            <?php
                $created_ts = strtotime((string)($job['created_at'] ?? ''));
                $now_ts = time();
                $elapsed_seconds = ($created_ts && $created_ts > 0) ? max(0, $now_ts - $created_ts) : 0;
                $eta_seconds = null;
                $eta_finish_ts = null;
                if ($progress_percent >= 5 && $elapsed_seconds > 0) {
                    $estimated_total_seconds = (int)round(($elapsed_seconds * 100) / $progress_percent);
                    $eta_seconds = max(0, $estimated_total_seconds - $elapsed_seconds);
                    $eta_finish_ts = $now_ts + $eta_seconds;
                }
            ?>
            <div class="processing-message">
                <h2>Schedule is still being generated...</h2>
                <p>The genetic algorithm is running. This may take a few minutes.</p>
                <p><strong>Progress: <?php echo $progress_percent; ?>%</strong></p>
                <p><strong>Generation:</strong> <?php echo $current_generation; ?><?php echo $total_generations > 0 ? ' / ' . $total_generations : ''; ?></p>
                <p><strong>Best fitness so far:</strong> <?php echo $best_fitness; ?>%</p>
                <?php if ($current_generation === 0): ?>
                    <p><strong>Phase:</strong> Initializing population (preparing candidates before Generation 1)</p>
                <?php endif; ?>
                <p><strong>Elapsed:</strong> <?php echo htmlspecialchars(formatDurationCompact($elapsed_seconds)); ?></p>
                <?php if ($eta_seconds !== null): ?>
                    <p><strong>Estimated time remaining:</strong> <?php echo htmlspecialchars(formatDurationCompact($eta_seconds)); ?></p>
                    <p><strong>Estimated completion:</strong> <?php echo date('g:i A', $eta_finish_ts); ?></p>
                <?php else: ?>
                    <p><strong>Estimated time remaining:</strong> Calculating...</p>
                <?php endif; ?>
                <div class="progress-bar">
                    <div class="progress" style="width: <?php echo $progress_percent; ?>%; animation: none;"></div>
                </div>
                <p>Please wait or <a href="view_schedules.php?job_id=<?php echo $job_id; ?>">refresh</a> the page.</p>
            </div>
        <?php elseif ($job['status'] == 'pending'): ?>
            <div class="pending-message">
                <h2>Schedule generation is queued</h2>
                <p>The job is waiting for the genetic algorithm script to run. If it stays pending:</p>
                <ul>
                    <li><strong>Run the script manually</strong> to see any errors. Open Command Prompt and run:<br>
                        <code>python "<?php echo str_replace('/', '\\', PYTHON_SCRIPT_PATH); ?>" <?php echo (int)$job_id; ?></code></li>
                    <li>Ensure <strong>Python</strong> is installed and in your system PATH (and that Apache/PHP can use it).</li>
                    <li>Ensure <strong>MySQL</strong> is running and the script can connect (user <code>root</code>, no password, database <code>academic_scheduling</code>).</li>
                    <li>Install Python packages if needed: <code>pip install mysql-connector-python numpy</code></li>
                </ul>
                <p><a href="view_schedules.php?job_id=<?php echo $job_id; ?>">Refresh</a> after the script finishes.</p>
            </div>
        <?php elseif ($job['status'] == 'failed'): ?>
            <div class="error">
                <h2>Schedule Generation Failed</h2>
                <p><?php echo htmlspecialchars(trim((string)($job['error_message'] ?? '')) !== '' ? (string)$job['error_message'] : 'There was an error generating the schedule. Please try again.'); ?></p>
                <a href="generate_schedule.php" class="btn-primary">Create New Schedule</a>
            </div>
        <?php endif; ?>
        
        <!-- Input Data Display -->
        <?php if ($job['input_data']): 
            $input_data = json_decode($job['input_data'], true);
        ?>
        <div class="input-data-section">
            <h3>Generation Parameters</h3>
            <details>
                <summary>Click to view input parameters and constraints</summary>
                <div class="input-data">
                    <h4>Selected Instructors: <?php echo count($input_data['instructors'] ?? []); ?></h4>
                    <h4>Selected Rooms: <?php echo count($input_data['rooms'] ?? []); ?></h4>
                    <h4>Selected Subjects: <?php echo count($input_data['subjects'] ?? []); ?></h4>
                    
                    <?php if (isset($input_data['constraints'])): ?>
                    <h4>Constraints:</h4>
                    <ul>
                        <?php foreach ($input_data['constraints'] as $key => $value): ?>
                            <li><strong><?php echo htmlspecialchars(str_replace('_', ' ', $key)); ?>:</strong>
                                <?php
                                if (is_bool($value)) {
                                    echo $value ? 'Yes' : 'No';
                                } elseif (is_array($value)) {
                                    if ($key === 'mirror_pairs') {
                                        if (empty($value)) {
                                            echo 'None';
                                        } else {
                                            $pairs = [];
                                            foreach ($value as $pair) {
                                                $day = trim((string)($pair['day'] ?? ''));
                                                $mirror = trim((string)($pair['mirror'] ?? ''));
                                                if ($day !== '' && $mirror !== '') {
                                                    $pairs[] = $day . ' -> ' . $mirror;
                                                }
                                            }
                                            echo htmlspecialchars(!empty($pairs) ? implode(', ', $pairs) : 'None');
                                        }
                                    } else {
                                        echo htmlspecialchars(json_encode($value, JSON_UNESCAPED_SLASHES));
                                    }
                                } else {
                                    echo htmlspecialchars((string)$value);
                                }
                                ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
            </details>
        </div>
        <?php endif; ?>
    </div>
    
    <style>
        /* Additional styles for view_schedules.php */
        .job-info-card {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .job-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .schedule-actions {
            margin: 20px 0;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 5px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .day-schedule {
            margin-bottom: 30px;
        }
        
        .day-schedule h3 {
            background: #667eea;
            color: white;
            padding: 10px;
            border-radius: 5px 5px 0 0;
            margin: 0;
        }
        
        .schedule-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .schedule-table th {
            background: #f2f2f2;
            padding: 12px;
            text-align: left;
        }
        
        .schedule-table td {
            padding: 12px;
            border-bottom: 1px solid #dee2e6;
        }
        
        .published-row {
            background-color: #d4edda;
        }

        .conflict-row {
            background-color: #f8d7da !important;
            font-weight: bold;
            border: 1px solid #dc3545;
        }
        
        .published-badge {
            background: #28a745;
            color: white;
            padding: 10px 20px;
            border-radius: 5px;
            display: inline-block;
        }
        
        .processing-message,
        .pending-message {
            text-align: center;
            padding: 50px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .progress-bar {
            width: 100%;
            height: 20px;
            background: #e9ecef;
            border-radius: 10px;
            margin: 20px 0;
            overflow: hidden;
        }
        
        .progress {
            height: 100%;
            background: #667eea;
            animation: progress-animation 2s ease-in-out infinite;
        }
        
        @keyframes progress-animation {
            0% { width: 0%; }
            50% { width: 70%; }
            100% { width: 100%; }
        }
        
        .summary-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .stat {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            text-align: center;
        }
        
        .stat-label {
            display: block;
            font-size: 14px;
            color: #6c757d;
            margin-bottom: 5px;
        }
        
        .stat-value {
            display: block;
            font-size: 24px;
            font-weight: bold;
            color: #2c3e50;
        }
        
        .input-data-section {
            margin-top: 30px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 5px;
        }
        
        details {
            cursor: pointer;
        }
        
        details summary {
            padding: 10px;
            background: #e9ecef;
            border-radius: 5px;
        }
        
        details[open] summary {
            margin-bottom: 15px;
        }
        
        .input-data {
            padding: 15px;
            background: white;
            border-radius: 5px;
        }
        
        @media print {
            .header, .nav-links, .schedule-actions, .btn-logout, 
            .input-data-section, .job-info-card, .btn-small {
                display: none;
            }
            
            .day-schedule {
                page-break-inside: avoid;
            }
            
            .schedule-table {
                font-size: 10pt;
            }
            
            .published-badge {
                background: none;
                color: black;
                border: 1px solid black;
            }
        }
    </style>
    <?php if ($job['status'] === 'processing'): ?>
    <script>
        setTimeout(function () {
            window.location.reload();
        }, 5000);
    </script>
    <?php endif; ?>

    <script>
    // Modal functionality
    function loadEditModal(id, job_id) {
        const modal = document.getElementById('editModal');
        const body = document.querySelector('#editModal .modal-body');
        
        // Show modal with loading
        modal.style.display = 'block';
        body.innerHTML = '<div class="loading"><div class="spinner"></div>Loading edit form...</div>';
        
        // Fetch form via AJAX
        fetch(`edit_schedule_entry.php?id=${id}&job_id=${job_id}`, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.text())
        .then(html => {
            body.innerHTML = html;
            // Re-init JS for room availability
            if (typeof initRoomAvailability === 'function') {
                initRoomAvailability();
            }
        })
        .catch(error => {
            body.innerHTML = '<div class="error">Error loading form. Please refresh and try again.</div>';
            console.error('Error:', error);
        });
    }
    
    function closeModal() {
        document.getElementById('editModal').style.display = 'none';
    }
    
    // Handle form submit via AJAX
    document.addEventListener('DOMContentLoaded', function() {
        document.addEventListener('submit', function(e) {
            if (e.target.matches('.edit-form')) {
                e.preventDefault();
                
                const form = e.target;
                const modalBody = form.closest('.modal-body');
                const submitBtn = form.querySelector('button[type="submit"]');
                
                // Show loading on button
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
                submitBtn.disabled = true;
                
                fetch(form.action || window.location.href, {
                    method: 'POST',
                    body: new FormData(form),
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Close modal and reload page to refresh highlights/table
                        setTimeout(() => {
                            closeModal();
                            window.location.reload();
                        }, 1200);
                        
                        // Show success in modal briefly
                        modalBody.innerHTML = '<div class="success" style="padding: 40px; text-align: center;">' + 
                            '<i class="fas fa-check-circle" style="font-size: 48px; color: #10b981;"></i><br>' +
                            data.message + '<br><br><small>Refreshing schedule...</small></div>';
                    } else {
                        modalBody.innerHTML = '<div class="error" style="padding: 20px;">' + data.error + '</div>';
                    }
                })
                .catch(error => {
                    modalBody.innerHTML = '<div class="error" style="padding: 20px;">Save failed. Please try again.</div>';
                    console.error('Error:', error);
                })
                .finally(() => {
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                });
            }
        });
        
        // Close modal on outside click
        window.onclick = function(event) {
            const modal = document.getElementById('editModal');
            if (event.target == modal) {
                closeModal();
            }
        }
        
        const errorDiv = document.querySelector('.error');
        if (errorDiv && errorDiv.textContent.trim() !== '') {
            alert(errorDiv.textContent.trim());
        }
    });
    </script>

    <!-- Edit Modal -->
    <div id="editModal" class="edit-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Edit Schedule Entry</h3>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <!-- Form loaded here via AJAX -->
            </div>
        </div>
    </div>
</body>
</html>
