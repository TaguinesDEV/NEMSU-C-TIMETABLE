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

// Handle approve/delete actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_schedule_entry'])) {
        $schedule_id = (int)($_POST['schedule_id'] ?? 0);

        if ($schedule_id <= 0) {
            $error = 'Invalid schedule entry selected for deletion.';
        } else {
            $stmt = $pdo->prepare("
                SELECT s.id
                FROM schedules s
                JOIN subjects sub ON s.subject_id = sub.id
                WHERE s.id = ? AND s.job_id = ? AND sub.program_id = ?
            ");
            $stmt->execute([$schedule_id, $job_id, $program_id]);
            $existing_entry = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$existing_entry) {
                $error = 'Schedule entry not found for this program.';
            } else {
                $stmt = $pdo->prepare("DELETE FROM schedules WHERE id = ? AND job_id = ?");
                $stmt->execute([$schedule_id, $job_id]);
                $message = 'Schedule entry deleted successfully. Published reports will reflect the change.';
            }
        }
    } elseif (isset($_POST['approve_schedule'])) {
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

// Get schedule entries
$stmt = $pdo->prepare("
    SELECT 
        s.*,
        sub.subject_code,
        sub.subject_name,
        i.id as instructor_id,
        u.full_name as instructor_name,
        i.max_hours_per_week,
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

$instructor_overloads = [];
foreach ($schedules as $schedule) {
    $instId = (int)($schedule['instructor_id'] ?? 0);
    if ($instId <= 0) {
        continue;
    }
    if (!isset($instructor_overloads[$instId])) {
        $instructor_overloads[$instId] = [
            'instructor_name' => (string)($schedule['instructor_name'] ?? ''),
            'max_hours_per_week' => (float)($schedule['max_hours_per_week'] ?? 0),
            'total_hours' => 0.0,
            'subjects' => [],
        ];
    }

    $rowHours = (float)($schedule['scheduled_hours'] ?? $schedule['hours_per_week'] ?? 0);
    $instructor_overloads[$instId]['total_hours'] += $rowHours;

    $subjectKey = (int)($schedule['subject_id'] ?? 0);
    if ($subjectKey <= 0) {
        $subjectKey = strtoupper(trim((string)($schedule['subject_code'] ?? '')));
    }
    if (!isset($instructor_overloads[$instId]['subjects'][$subjectKey])) {
        $instructor_overloads[$instId]['subjects'][$subjectKey] = [
            'subject_code' => (string)($schedule['subject_code'] ?? ''),
            'subject_name' => (string)($schedule['subject_name'] ?? ''),
            'hours' => 0.0,
        ];
    }
    $instructor_overloads[$instId]['subjects'][$subjectKey]['hours'] += $rowHours;
}

foreach ($instructor_overloads as $instId => &$overloadRow) {
    $overloadRow['total_hours'] = round((float)$overloadRow['total_hours'], 2);
    foreach ($overloadRow['subjects'] as &$subjectRow) {
        $subjectRow['hours'] = round((float)$subjectRow['hours'], 2);
    }
    unset($subjectRow);
    uasort($overloadRow['subjects'], function ($a, $b) {
        $hoursCompare = (float)($b['hours'] ?? 0) <=> (float)($a['hours'] ?? 0);
        if ($hoursCompare !== 0) {
            return $hoursCompare;
        }
        return strcmp((string)($a['subject_code'] ?? ''), (string)($b['subject_code'] ?? ''));
    });
    if ($overloadRow['max_hours_per_week'] <= 0 || $overloadRow['total_hours'] <= $overloadRow['max_hours_per_week']) {
        unset($instructor_overloads[$instId]);
    }
}
unset($overloadRow);
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

        .schedule-search-panel {
            margin: 0 0 20px;
            padding: 14px 16px;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
        }

        .schedule-search-panel label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .schedule-search-input {
            width: 100%;
            max-width: 420px;
            padding: 10px 12px;
            border: 1px solid #ced4da;
            border-radius: 6px;
            font-size: 14px;
        }

        .schedule-search-help {
            margin-top: 8px;
            color: #6c757d;
            font-size: 13px;
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
            padding: 9px 14px;
            border: 1px solid transparent;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            line-height: 1;
            letter-spacing: 0.01em;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            color: white;
            transition: transform 0.18s ease, box-shadow 0.18s ease, filter 0.18s ease, background-color 0.18s ease;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.12);
        }

        .btn-icon:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 14px rgba(15, 23, 42, 0.18);
            filter: brightness(1.03);
        }

        .btn-icon:active {
            transform: translateY(0);
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.12);
        }

        .btn-icon:focus-visible {
            outline: 3px solid rgba(59, 130, 246, 0.35);
            outline-offset: 2px;
        }

        .btn-edit {
            background: linear-gradient(135deg, #f59e0b, #fbbf24);
            border-color: #d97706;
            color: #1f2937;
        }

        .btn-delete {
            background: linear-gradient(135deg, #dc2626, #ef4444);
            border-color: #b91c1c;
            color: #fff;
        }

        .btn-publish {
            background: linear-gradient(135deg, #16a34a, #22c55e);
            border-color: #15803d;
            color: #fff;
        }

        .row-actions {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            flex-wrap: nowrap;
            white-space: nowrap;
        }

        .row-actions form {
            margin: 0;
            display: inline-flex;
        }
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
                <?php if (!empty($instructor_overloads)): ?>
                    <div class="error" style="margin-bottom: 16px;">
                        <strong>Instructor Overload Summary:</strong>
                        <ul style="margin-top: 8px;">
                            <?php foreach ($instructor_overloads as $overloadRow): ?>
                                <li>
                                    <?php echo htmlspecialchars($overloadRow['instructor_name']); ?>:
                                    <?php echo number_format((float)$overloadRow['total_hours'], 2); ?>h assigned,
                                    limit <?php echo number_format((float)$overloadRow['max_hours_per_week'], 2); ?>h.
                                    Subjects:
                                    <?php
                                        $subjectLabels = [];
                                        foreach ($overloadRow['subjects'] as $subjectRow) {
                                            $subjectLabels[] = trim(($subjectRow['subject_code'] ?? '') . ' - ' . ($subjectRow['subject_name'] ?? ''), ' -') . ' (' . number_format((float)$subjectRow['hours'], 2) . 'h)';
                                        }
                                        echo htmlspecialchars(implode(', ', $subjectLabels));
                                    ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

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

                <?php if (!empty($schedules) && !empty($schedules[0]['is_published'])): ?>
                    <div class="schedule-search-panel">
                        <label for="scheduleSearch">Find In Published Schedule</label>
                        <input type="text" id="scheduleSearch" class="schedule-search-input" placeholder="Search subject, instructor, room, building, or day..." autocomplete="off">
                        <div class="schedule-search-help">Type to quickly jump to the row you want to review.</div>
                    </div>
                <?php endif; ?>
                
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
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    usort($grouped[$day], function($a, $b) {
                                        return strtotime($a['start_time']) - strtotime($b['start_time']);
                                    });
                                    
                                    foreach ($grouped[$day] as $s): 
                                    ?>
                                    <tr class="schedule-search-row <?php echo $s['is_published'] ? 'published-row' : ''; ?>"
                                        data-search="<?php echo htmlspecialchars(strtolower(implode(' ', [
                                            (string)$day,
                                            (string)($s['subject_code'] ?? ''),
                                            (string)($s['subject_name'] ?? ''),
                                            (string)($s['instructor_name'] ?? ''),
                                            (string)($s['room_number'] ?? ''),
                                            (string)($s['building'] ?? '')
                                        ]))); ?>">
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
                                        <td>
                                            <div class="row-actions">
                                                <form method="POST">
                                                    <input type="hidden" name="schedule_id" value="<?php echo (int)$s['id']; ?>">
                                                    <button type="submit" name="delete_schedule_entry" class="btn-icon btn-delete" onclick="return confirm('Delete this schedule entry? This will also remove it from published reports.');">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
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
                <h2>Schedule is being generated...</h2>
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
                <div class="progress-bar" style="width:100%;height:20px;background:#e9ecef;border-radius:10px;overflow:hidden;margin:12px 0;">
                    <div class="progress" style="height:100%;background:#667eea;width:<?php echo $progress_percent; ?>%;"></div>
                </div>
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
                <p><?php echo htmlspecialchars(trim((string)($job['error_message'] ?? '')) !== '' ? (string)$job['error_message'] : 'There was an error. Please try again.'); ?></p>
                <a href="generate_schedule.php" class="btn-primary">Create New Schedule</a>
            </div>
        <?php endif; ?>
    </div>
    <?php if ($job['status'] === 'processing'): ?>
    <script>
        setTimeout(function () {
            window.location.reload();
        }, 5000);
    </script>
    <?php endif; ?>
    <script>
        (function () {
            const searchInput = document.getElementById('scheduleSearch');
            if (!searchInput) {
                return;
            }

            const rows = Array.from(document.querySelectorAll('.schedule-search-row'));
            const dayBlocks = Array.from(document.querySelectorAll('.day-schedule'));

            const applyScheduleFilter = () => {
                const query = searchInput.value.trim().toLowerCase();

                rows.forEach((row) => {
                    const haystack = row.getAttribute('data-search') || '';
                    row.style.display = query === '' || haystack.includes(query) ? '' : 'none';
                });

                dayBlocks.forEach((block) => {
                    const hasVisibleRows = Array.from(block.querySelectorAll('.schedule-search-row'))
                        .some((row) => row.style.display !== 'none');
                    block.style.display = hasVisibleRows ? '' : 'none';
                });
            };

            searchInput.addEventListener('input', applyScheduleFilter);
        })();
    </script>
</body>
</html>
