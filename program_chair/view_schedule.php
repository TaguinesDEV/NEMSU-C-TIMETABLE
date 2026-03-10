<?php
require_once '../includes/auth.php';
requireLogin();

// Check if user is program chair
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'program_chair') {
    header('Location: ../index.php');
    exit();
}

$pdo = getDB();
$user_id = $_SESSION['user_id'];

// Get program chair's program
$stmt = $pdo->prepare("
    SELECT pc.*, p.program_name 
    FROM program_chairs pc 
    JOIN programs p ON pc.program_id = p.id 
    WHERE pc.user_id = ?
");
$stmt->execute([$user_id]);
$programChair = $stmt->fetch();

if (!$programChair) {
    die('Program chair profile not found.');
}

$program_id = $programChair['program_id'];
$job_id = $_GET['job_id'] ?? 0;
$message = '';
$error = '';

// Handle approve action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_schedule'])) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM schedules sn
        JOIN schedules sp
          ON sn.time_slot_id = sp.time_slot_id
         AND sp.is_published = 1
         AND sp.job_id <> sn.job_id
         AND (sn.room_id = sp.room_id OR sn.instructor_id = sp.instructor_id)
        WHERE sn.job_id = ?
    ");
    $stmt->execute([$job_id]);
    $has_conflict = (int) $stmt->fetchColumn() > 0;

    if ($has_conflict) {
        $error = "Cannot publish this schedule because it conflicts with already published schedules (room or instructor overlap).";
    } else {
        $stmt = $pdo->prepare("UPDATE schedules SET is_published = 1 WHERE job_id = ?");
        $stmt->execute([$job_id]);
        $message = "Schedule has been approved and published successfully!";
    }
}

// Get job details
$stmt = $pdo->prepare("
    SELECT j.*, u.full_name as created_by_name 
    FROM schedule_jobs j 
    JOIN users u ON j.created_by = u.id 
    WHERE j.id = ? AND j.program_id = ?
");
$stmt->execute([$job_id, $program_id]);
$job = $stmt->fetch();

if (!$job) {
    header('Location: dashboard.php');
    exit();
}

// Get schedule entries
$stmt = $pdo->prepare("
    SELECT 
        s.*,
        sub.subject_code,
        sub.subject_name,
        i.id as instructor_id,
        u.full_name as instructor_name,
        r.room_number,
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

// Group by day
$grouped = [];
foreach ($schedules as $s) {
    $day = $s['day'];
    if (!isset($grouped[$day])) {
        $grouped[$day] = [];
    }
    $grouped[$day][] = $s;
}

// Dynamic day order - Saturday only if there's a schedule
$day_order = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
if (isset($grouped['Saturday'])) {
    $day_order[] = 'Saturday';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Schedule - Academic Scheduling System</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .schedule-view { margin-top: 20px; }
        
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
        
        .schedule-actions {
            margin: 20px 0;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 5px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
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
        
        .btn-icon {
            padding: 8px 12px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            text-decoration: none;
            color: white;
        }
        
        .btn-publish { background-color: #28a745; }
        .btn-publish:hover { background-color: #218838; }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-content">
            <div class="brand">
                <img src="../assets/logo.png" alt="Academic Scheduling" class="logo">
                <h1>NEMSU-CANTILAN</h1>
            </div>
            <div class="user-info">
                <div class="user-meta">
                    <div class="header-inline">
                        <a href="dashboard.php">Dashboard</a>
                        <span class="sep">/</span>
                        <a href="generate_schedule.php">Generate Schedule</a>
                        <span class="sep">/</span>
                        <a href="view_schedule.php">View Schedules</a>
                        <span class="sep">/</span>
                        <span class="welcome-text">Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                    </div>
                </div>
                <a href="../logout.php" class="btn-logout">Logout</a>
            </div>
        </div>
    </div>
    
    <div class="container">
        <?php if ($message): ?>
            <div class="success"><?php echo $message; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <!-- Job Info -->
        <div class="job-info-card">
            <h2>Schedule: <?php echo htmlspecialchars($job['job_name']); ?></h2>
            <p><strong>Status:</strong> 
                <span class="status-badge status-<?php echo $job['status']; ?>">
                    <?php echo $job['status']; ?>
                </span>
            </p>
            <p><strong>Created:</strong> <?php echo date('F j, Y g:i A', strtotime($job['created_at'])); ?></p>
            <?php if ($job['completed_at']): ?>
                <p><strong>Completed:</strong> <?php echo date('F j, Y g:i A', strtotime($job['completed_at'])); ?></p>
            <?php endif; ?>
        </div>
        
        <?php if ($job['status'] == 'completed'): ?>
            <div class="schedule-view">
                <!-- Actions -->
                <div class="schedule-actions">
                    <?php if (!empty($schedules) && !$schedules[0]['is_published']): ?>
                        <form method="POST" style="display: inline;">
                            <button type="submit" name="approve_schedule" class="btn-icon btn-publish" 
                                    onclick="return confirm('Approve and publish this schedule?')">
                                <i class="fas fa-check"></i> Approve Schedule
                            </button>
                        </form>
                    <?php elseif (!empty($schedules) && $schedules[0]['is_published']): ?>
                        <div class="published-badge">
                            <i class="fas fa-check-circle"></i> This schedule is published
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Schedule by Day -->
                <?php foreach ($day_order as $day): ?>
                    <?php if (isset($grouped[$day])): ?>
                        <div class="day-schedule">
                            <h3><?php echo $day; ?></h3>
                            <table class="schedule-table">
                                <thead>
                                    <tr>
                                        <th>Time</th>
                                        <th>Subject</th>
                                        <th>Instructor</th>
                                        <th>Room</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    usort($grouped[$day], function($a, $b) {
                                        return strtotime($a['start_time']) - strtotime($b['start_time']);
                                    });
                                    
                                    foreach ($grouped[$day] as $s): 
                                    ?>
                                    <tr class="<?php echo $s['is_published'] ? 'published-row' : ''; ?>">
                                        <td>
                                            <?php echo date('g:i A', strtotime($s['start_time'])); ?> - 
                                            <?php echo date('g:i A', strtotime($s['end_time'])); ?>
                                        </td>
                                        <td>
                                            <strong><?php echo $s['subject_code']; ?></strong><br>
                                            <small><?php echo $s['subject_name']; ?></small>
                                        </td>
                                        <td><?php echo $s['instructor_name']; ?></td>
                                        <td><?php echo $s['room_number']; ?> (<?php echo $s['building']; ?>)</td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
                
                <!-- Summary -->
                <div class="schedule-summary">
                    <h3>Summary</h3>
                    <div class="summary-stats">
                        <div class="stat">
                            <span class="stat-label">Total Classes:</span>
                            <span class="stat-value"><?php echo count($schedules); ?></span>
                        </div>
                        <div class="stat">
                            <span class="stat-label">Days:</span>
                            <span class="stat-value"><?php echo count($grouped); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        <?php elseif ($job['status'] == 'processing'): ?>
            <div class="processing-message">
                <h2>Schedule is being generated...</h2>
                <p>Please wait or <a href="view_schedule.php?job_id=<?php echo $job_id; ?>">refresh</a> the page.</p>
            </div>
        <?php elseif ($job['status'] == 'pending'): ?>
            <div class="pending-message">
                <h2>Schedule generation is queued</h2>
                <p>Please wait for the genetic algorithm to run.</p>
                <p><a href="view_schedule.php?job_id=<?php echo $job_id; ?>">Refresh</a></p>
            </div>
        <?php elseif ($job['status'] == 'failed'): ?>
            <div class="error">
                <h2>Schedule Generation Failed</h2>
                <p>There was an error. Please try again.</p>
                <a href="generate_schedule.php" class="btn-primary">Create New Schedule</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
