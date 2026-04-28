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
$dashboardPreviewLimit = 5;
$dashboardTransientParams = ['save_job', 'delete_job', 'restore_saved', 'delete_saved', 'saved', 'deleted', 'restored'];

function buildDashboardUrl(array $overrides = [], array $exclude = []): string {
    $params = $_GET;

    foreach ($exclude as $key) {
        unset($params[$key]);
    }

    foreach ($overrides as $key => $value) {
        if ($value === null || $value === false || $value === '') {
            unset($params[$key]);
            continue;
        }
        $params[$key] = $value;
    }

    $query = http_build_query($params);
    return 'dashboard.php' . ($query !== '' ? '?' . $query : '');
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS saved_schedule_backups (
            id INT PRIMARY KEY AUTO_INCREMENT,
            original_job_id INT NULL,
            job_name VARCHAR(255) NOT NULL,
            created_by INT NULL,
            program_id INT NULL,
            saved_by INT NOT NULL,
            job_data LONGTEXT NOT NULL,
            schedules_data LONGTEXT NOT NULL,
            saved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_saved_schedule_backup_job (original_job_id)
        )
    ");
} catch (Exception $e) {
    // Keep dashboard usable even if backup table creation is restricted.
}

function buildJobBackupPayload(PDO $pdo, int $jobId, int $programId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM schedule_jobs WHERE id = ? AND program_id = ?");
    $stmt->execute([$jobId, $programId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$job) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT * FROM schedules WHERE job_id = ? ORDER BY id");
    $stmt->execute([$jobId]);
    $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'job' => $job,
        'schedules' => $schedules,
    ];
}

function restoreSavedBackup(PDO $pdo, array $backup, int $programId): ?int {
    $jobData = json_decode((string)($backup['job_data'] ?? ''), true);
    $schedulesData = json_decode((string)($backup['schedules_data'] ?? ''), true);
    if (!is_array($jobData) || !is_array($schedulesData)) {
        return null;
    }
    if ((int)($jobData['program_id'] ?? 0) !== $programId) {
        return null;
    }

    $jobName = trim((string)($jobData['job_name'] ?? 'Restored Schedule'));
    if ($jobName === '') {
        $jobName = 'Restored Schedule';
    }

    $stmt = $pdo->prepare("
        INSERT INTO schedule_jobs (job_name, status, created_by, program_id, input_data, completed_at, error_message, progress_percent, current_generation, total_generations, best_fitness)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $jobName . ' (Restored)',
        $jobData['status'] ?? 'completed',
        $jobData['created_by'] ?? null,
        $jobData['program_id'] ?? null,
        $jobData['input_data'] ?? '{}',
        $jobData['completed_at'] ?? null,
        $jobData['error_message'] ?? null,
        $jobData['progress_percent'] ?? 100,
        $jobData['current_generation'] ?? 0,
        $jobData['total_generations'] ?? 0,
        $jobData['best_fitness'] ?? 0,
    ]);
    $newJobId = (int)$pdo->lastInsertId();

    foreach ($schedulesData as $scheduleRow) {
        if (!is_array($scheduleRow)) {
            continue;
        }
        $scheduleRow['job_id'] = $newJobId;
        unset($scheduleRow['id']);

        $columns = array_keys($scheduleRow);
        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $sql = "INSERT INTO schedules (" . implode(',', $columns) . ") VALUES ($placeholders)";
        $insert = $pdo->prepare($sql);
        $insert->execute(array_values($scheduleRow));
    }

    return $newJobId;
}

// Get program chair's program info
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

// Get program-specific stats
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM instructors
    WHERE program_id = ?
       OR program_id IS NULL
       OR program_id = 0
");
$stmt->execute([$program_id]);
$instructor_count = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM subjects WHERE program_id = ?");
$stmt->execute([$program_id]);
$subject_count = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM schedules s
    JOIN subjects sub ON s.subject_id = sub.id
    WHERE sub.program_id = ?
      AND s.is_published = 1
");
$stmt->execute([$program_id]);
$schedule_count = (int) $stmt->fetchColumn();

$room_count = (int) $pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn();

$stats = [
    'instructors' => $instructor_count,
    'subjects' => $subject_count,
    'schedules' => $schedule_count,
    'rooms' => $room_count // Rooms are shared across programs
];

// Get recent jobs for this program
$stmt = $pdo->prepare("
    SELECT *
    FROM schedule_jobs
    WHERE program_id = ?
    ORDER BY created_at DESC
");
$stmt->execute([$program_id]);
$recentJobs = $stmt->fetchAll();
$jobCount = count($recentJobs);

$stmt = $pdo->prepare("
    SELECT * FROM saved_schedule_backups
    WHERE program_id = ?
    ORDER BY saved_at DESC
");
$stmt->execute([$program_id]);
$savedBackups = $stmt->fetchAll();
$backupCount = count($savedBackups);

// Handle delete job
$delete_message = '';
if (isset($_GET['save_job'])) {
    $job_id = (int) $_GET['save_job'];
    if ($job_id > 0) {
        $payload = buildJobBackupPayload($pdo, $job_id, (int)$program_id);
        if ($payload !== null) {
            $stmt = $pdo->prepare("
                INSERT INTO saved_schedule_backups (original_job_id, job_name, created_by, program_id, saved_by, job_data, schedules_data)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    job_name = VALUES(job_name),
                    created_by = VALUES(created_by),
                    program_id = VALUES(program_id),
                    saved_by = VALUES(saved_by),
                    job_data = VALUES(job_data),
                    schedules_data = VALUES(schedules_data),
                    saved_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([
                $payload['job']['id'],
                $payload['job']['job_name'],
                $payload['job']['created_by'] ?? null,
                $payload['job']['program_id'] ?? null,
                $_SESSION['user_id'],
                json_encode($payload['job']),
                json_encode($payload['schedules']),
            ]);
            header('Location: ' . buildDashboardUrl(['saved' => 1], $dashboardTransientParams));
            exit;
        }
    }
}
if (isset($_GET['delete_job'])) {
    $job_id = (int) $_GET['delete_job'];
    if ($job_id > 0) {
        $stmt = $pdo->prepare("DELETE FROM schedule_jobs WHERE id = ? AND program_id = ?");
        $stmt->execute([$job_id, $program_id]);
        $delete_message = $stmt->rowCount() ? 'Job and its schedules deleted.' : '';
        header('Location: ' . buildDashboardUrl(['deleted' => 1], $dashboardTransientParams));
        exit;
    }
}
if (isset($_GET['deleted'])) {
    $delete_message = 'Job and its schedules have been deleted.';
} elseif (isset($_GET['saved'])) {
    $delete_message = 'Schedule backup saved successfully.';
} elseif (isset($_GET['restored'])) {
    $delete_message = 'Saved schedule restored successfully.';
}

if (isset($_GET['restore_saved'])) {
    $backup_id = (int) $_GET['restore_saved'];
    if ($backup_id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM saved_schedule_backups WHERE id = ? AND program_id = ?");
        $stmt->execute([$backup_id, $program_id]);
        $backup = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($backup) {
            $newJobId = restoreSavedBackup($pdo, $backup, (int)$program_id);
            if ($newJobId) {
                header('Location: ' . buildDashboardUrl(['restored' => 1], $dashboardTransientParams));
                exit;
            }
        }
    }
}

if (isset($_GET['delete_saved'])) {
    $backup_id = (int) $_GET['delete_saved'];
    if ($backup_id > 0) {
        $stmt = $pdo->prepare("DELETE FROM saved_schedule_backups WHERE id = ? AND program_id = ?");
        $stmt->execute([$backup_id, $program_id]);
        header('Location: ' . buildDashboardUrl(['deleted' => 1], $dashboardTransientParams));
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Program Chair Dashboard - Academic Scheduling System</title>
    <link rel="stylesheet" href="../css/style.css">
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
        <?php if ($delete_message): ?>
            <div class="success"><?php echo htmlspecialchars($delete_message); ?></div>
        <?php endif; ?>
        
        <div class="dashboard-stats">
            <div class="stat-card">
                <h3>Instructors</h3>
                <p class="stat-number"><?php echo $stats['instructors']; ?></p>
            </div>
            <div class="stat-card">
                <h3>Subjects</h3>
                <p class="stat-number"><?php echo $stats['subjects']; ?></p>
            </div>
            <div class="stat-card">
                <h3>Published Classes</h3>
                <p class="stat-number"><?php echo $stats['schedules']; ?></p>
            </div>
            <div class="stat-card">
                <h3>Available Rooms</h3>
                <p class="stat-number"><?php echo $stats['rooms']; ?></p>
            </div>
        </div>
        
        <div class="dashboard-menu">
            <h2>Schedule Management</h2>
            
            <div class="menu-grid">
                <a href="generate_schedule.php" class="menu-card">
                    <div class="menu-icon">📅</div>
                    <h3>Generate Schedule</h3>
                    <p>Create new schedules for your program</p>
                </a>
                
                <a href="view_schedule.php" class="menu-card">
                    <div class="menu-icon">📋</div>
                    <h3>View Schedules</h3>
                    <p>View generated schedules</p>
                </a>
            </div>
        </div>
        
        <div class="recent-jobs">
            <div class="dashboard-section-header">
                <h2>Recent Schedule Generation Jobs</h2>
            </div>
            <?php if (empty($recentJobs)): ?>
                <p class="no-data">No schedule jobs yet. Click "Generate Schedule" to create one.</p>
            <?php else: ?>
                <div class="dashboard-table-panel" data-preview-rows="<?php echo $dashboardPreviewLimit; ?>">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Job Name</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentJobs as $index => $job): ?>
                            <tr<?php echo $index >= $dashboardPreviewLimit ? ' hidden' : ''; ?>>
                                <td><?php echo htmlspecialchars($job['job_name']); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo $job['status']; ?>">
                                        <?php echo $job['status']; ?>
                                    </span>
                                </td>
                                <td><?php echo date('M j, Y g:i A', strtotime($job['created_at'])); ?></td>
                                <td>
                                    <a href="view_schedule.php?job_id=<?php echo $job['id']; ?>" class="btn-small">View</a>
                                    <a href="<?php echo htmlspecialchars(buildDashboardUrl(['save_job' => $job['id']], $dashboardTransientParams)); ?>" class="btn-small" onclick="return confirm('Save a recoverable backup of this schedule job?');">Save</a>
                                    <a href="<?php echo htmlspecialchars(buildDashboardUrl(['delete_job' => $job['id']], $dashboardTransientParams)); ?>" class="btn-small btn-danger" onclick="return confirm('Delete this job and all its generated schedules?');">Delete</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($jobCount > $dashboardPreviewLimit): ?>
                    <div class="dashboard-table-footer" data-toggle-panel>
                        <p class="dashboard-section-meta">
                            Showing <span data-preview-count><?php echo min($dashboardPreviewLimit, $jobCount); ?></span> of <?php echo $jobCount; ?> jobs
                        </p>
                        <button type="button" class="dashboard-toggle-btn" aria-expanded="false">See All</button>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="recent-jobs">
            <div class="dashboard-section-header">
                <h2>Saved Schedule Backups</h2>
            </div>
            <?php if (empty($savedBackups)): ?>
                <p class="no-data">No saved schedule backups yet.</p>
            <?php else: ?>
                <div class="dashboard-table-panel" data-preview-rows="<?php echo $dashboardPreviewLimit; ?>">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Job Name</th>
                                <th>Saved At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($savedBackups as $index => $backup): ?>
                            <tr<?php echo $index >= $dashboardPreviewLimit ? ' hidden' : ''; ?>>
                                <td><?php echo htmlspecialchars($backup['job_name']); ?></td>
                                <td><?php echo date('M j, Y g:i A', strtotime((string)$backup['saved_at'])); ?></td>
                                <td>
                                    <a href="<?php echo htmlspecialchars(buildDashboardUrl(['restore_saved' => (int)$backup['id']], $dashboardTransientParams)); ?>" class="btn-small" onclick="return confirm('Restore this saved schedule as a new job?');">Restore</a>
                                    <a href="<?php echo htmlspecialchars(buildDashboardUrl(['delete_saved' => (int)$backup['id']], $dashboardTransientParams)); ?>" class="btn-small btn-danger" onclick="return confirm('Delete this saved backup permanently?');">Delete</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($backupCount > $dashboardPreviewLimit): ?>
                    <div class="dashboard-table-footer" data-toggle-panel>
                        <p class="dashboard-section-meta">
                            Showing <span data-preview-count><?php echo min($dashboardPreviewLimit, $backupCount); ?></span> of <?php echo $backupCount; ?> backups
                        </p>
                        <button type="button" class="dashboard-toggle-btn" aria-expanded="false">See All</button>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.recent-jobs').forEach(function (section) {
            const footer = section.querySelector('[data-toggle-panel]');
            const button = footer ? footer.querySelector('.dashboard-toggle-btn') : null;
            const previewCount = footer ? footer.querySelector('[data-preview-count]') : null;
            const table = section.querySelector('.dashboard-table-panel table');
            const rows = table ? Array.from(table.querySelectorAll('tbody tr')) : [];
            const previewRows = parseInt(section.querySelector('.dashboard-table-panel')?.getAttribute('data-preview-rows') || '5', 10);

            if (!button || rows.length <= previewRows) {
                return;
            }

            function setExpanded(expand) {
                section.classList.toggle('is-expanded', expand);
                button.textContent = expand ? 'Show Less' : 'See All';
                button.setAttribute('aria-expanded', expand ? 'true' : 'false');

                rows.forEach(function (row, index) {
                    row.hidden = !expand && index >= previewRows;
                });

                if (previewCount) {
                    previewCount.textContent = expand ? rows.length : Math.min(previewRows, rows.length);
                }
            }

            button.addEventListener('click', function () {
                setExpanded(!section.classList.contains('is-expanded'));
            });

            setExpanded(false);
        });
    });
    </script>
</body>
</html>
