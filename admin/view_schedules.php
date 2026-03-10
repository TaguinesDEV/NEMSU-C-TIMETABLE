<?php
require_once '../includes/auth.php';
requireAdmin();

$pdo = getDB();
$job_id = $_GET['job_id'] ?? 0;
$message = '';
$error = '';

if (isset($_GET['message'])) {
    $message = $_GET['message'];
}
if (isset($_GET['error'])) {
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
            $error = "Cannot publish schedule due to conflicts with already published schedules: " . implode('; ', $parts) . ".";
        } else {
            $stmt = $pdo->prepare("UPDATE schedules SET is_published = 1 WHERE job_id = ?");
            $stmt->execute([$job_id]);
            $message = "Schedule has been approved and published successfully!";
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
        
        $message = "New schedule generation job has been started based on this schedule.";
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
            <div class="error"><?php echo $error; ?></div>
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
                                    <tr class="<?php echo $schedule['is_published'] ? 'published-row' : ''; ?>">
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
                                            <a href="edit_schedule_entry.php?id=<?php echo $schedule['id']; ?>" 
                                               class="btn-icon btn-edit"><i class="fas fa-edit"></i> Edit</a>
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
            <div class="processing-message">
                <h2>Schedule is still being generated...</h2>
                <p>The genetic algorithm is running. This may take a few minutes.</p>
                <div class="progress-bar">
                    <div class="progress" style="width: 50%;"></div>
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
                <p>There was an error generating the schedule. Please try again.</p>
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
                            <li><strong><?php echo str_replace('_', ' ', $key); ?>:</strong> 
                                <?php echo is_bool($value) ? ($value ? 'Yes' : 'No') : $value; ?>
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
</body>
</html>
