<?php
require_once '../includes/auth.php';
requireAdmin();

$pdo = getDB();

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

function buildJobBackupPayload(PDO $pdo, int $jobId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM schedule_jobs WHERE id = ?");
    $stmt->execute([$jobId]);
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

function restoreSavedBackup(PDO $pdo, array $backup): ?int {
    $jobData = json_decode((string)($backup['job_data'] ?? ''), true);
    $schedulesData = json_decode((string)($backup['schedules_data'] ?? ''), true);
    if (!is_array($jobData) || !is_array($schedulesData)) {
        return null;
    }

    $jobName = trim((string)($jobData['job_name'] ?? 'Restored Schedule'));
    if ($jobName === '') {
        $jobName = 'Restored Schedule';
    }

    $tableColumns = [];
    foreach (['schedule_jobs', 'schedules'] as $tableName) {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$tableName}`");
        $tableColumns[$tableName] = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $columnRow) {
            $fieldName = (string)($columnRow['Field'] ?? '');
            if ($fieldName !== '') {
                $tableColumns[$tableName][$fieldName] = true;
            }
        }
    }

    $jobColumnValues = [
        'job_name' => $jobName . ' (Restored)',
        'status' => $jobData['status'] ?? 'completed',
        'created_by' => $jobData['created_by'] ?? null,
        'program_id' => $jobData['program_id'] ?? null,
        'input_data' => $jobData['input_data'] ?? '{}',
        'completed_at' => $jobData['completed_at'] ?? null,
        'error_message' => $jobData['error_message'] ?? null,
        'progress_percent' => $jobData['progress_percent'] ?? 100,
        'current_generation' => $jobData['current_generation'] ?? 0,
        'total_generations' => $jobData['total_generations'] ?? 0,
        'best_fitness' => $jobData['best_fitness'] ?? 0,
    ];

    $jobInsertColumns = [];
    $jobInsertValues = [];
    foreach ($jobColumnValues as $columnName => $value) {
        if (!isset($tableColumns['schedule_jobs'][$columnName])) {
            continue;
        }
        $jobInsertColumns[] = $columnName;
        $jobInsertValues[] = $value;
    }

    if (empty($jobInsertColumns)) {
        return null;
    }

    $jobPlaceholders = implode(',', array_fill(0, count($jobInsertColumns), '?'));
    $jobSql = "INSERT INTO schedule_jobs (" . implode(',', $jobInsertColumns) . ") VALUES ($jobPlaceholders)";
    $stmt = $pdo->prepare($jobSql);
    $stmt->execute($jobInsertValues);
    $newJobId = (int)$pdo->lastInsertId();

    foreach ($schedulesData as $scheduleRow) {
        if (!is_array($scheduleRow)) {
            continue;
        }
        $scheduleRow['job_id'] = $newJobId;
        unset($scheduleRow['id']);

        $columns = array_values(array_filter(array_keys($scheduleRow), static function (string $columnName) use ($tableColumns): bool {
            return isset($tableColumns['schedules'][$columnName]);
        }));
        if (empty($columns)) {
            continue;
        }
        $values = [];
        foreach ($columns as $columnName) {
            $values[] = $scheduleRow[$columnName];
        }
        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $sql = "INSERT INTO schedules (" . implode(',', $columns) . ") VALUES ($placeholders)";
        $insert = $pdo->prepare($sql);
        $insert->execute($values);
    }

    return $newJobId;
}

// Handle delete job
$delete_message = '';
if (isset($_GET['save_job'])) {
    $job_id = (int) $_GET['save_job'];
    if ($job_id > 0) {
        $payload = buildJobBackupPayload($pdo, $job_id);
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
            header('Location: dashboard.php?saved=1');
            exit;
        }
    }
}
if (isset($_GET['delete_job'])) {
    $job_id = (int) $_GET['delete_job'];
    if ($job_id > 0) {
        $stmt = $pdo->prepare("DELETE FROM schedule_jobs WHERE id = ?");
        $stmt->execute([$job_id]);
        $delete_message = $stmt->rowCount() ? 'Job and its schedules deleted.' : '';
        header('Location: dashboard.php?deleted=1');
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
        $stmt = $pdo->prepare("SELECT * FROM saved_schedule_backups WHERE id = ?");
        $stmt->execute([$backup_id]);
        $backup = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($backup) {
            $newJobId = restoreSavedBackup($pdo, $backup);
            if ($newJobId) {
                header('Location: dashboard.php?restored=1');
                exit;
            }
        }
    }
}

if (isset($_GET['delete_saved'])) {
    $backup_id = (int) $_GET['delete_saved'];
    if ($backup_id > 0) {
        $stmt = $pdo->prepare("DELETE FROM saved_schedule_backups WHERE id = ?");
        $stmt->execute([$backup_id]);
        header('Location: dashboard.php?deleted=1');
        exit;
    }
}

// Get counts for dashboard
$stats = [
    'instructors' => $pdo->query("SELECT COUNT(*) FROM instructors")->fetchColumn(),
    'rooms' => $pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn(),
    'subjects' => $pdo->query("SELECT COUNT(*) FROM subjects")->fetchColumn(),
    'schedules' => $pdo->query("SELECT COUNT(*) FROM schedules WHERE is_published = 1")->fetchColumn(),
    'pending_jobs' => $pdo->query("SELECT COUNT(*) FROM schedule_jobs WHERE status = 'pending'")->fetchColumn()
];
$savedBackups = $pdo->query("SELECT * FROM saved_schedule_backups ORDER BY saved_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Academic Scheduling System</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <div class="header">
        <div class="header-content">
            <div class="brand">
                <img src="../assets/logo.png" alt="Academic Scheduling" class="logo">
                <h1>NEMSU-CANTILAN </h1>
            </div>
            <div class="user-info">
                <div class="user-meta">
                    <div class="header-inline">
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
                <h3>Rooms</h3>
                <p class="stat-number"><?php echo $stats['rooms']; ?></p>
            </div>
            <div class="stat-card">
                <h3>Subjects</h3>
                <p class="stat-number"><?php echo $stats['subjects']; ?></p>
            </div>
            <div class="stat-card">
                <h3>Published Schedules</h3>
                <p class="stat-number"><?php echo $stats['schedules']; ?></p>
            </div>
            <div class="stat-card">
                <h3>Pending Jobs</h3>
                <p class="stat-number"><?php echo $stats['pending_jobs']; ?></p>
            </div>
        </div>
        
        <div class="dashboard-menu">
            <h2>Main Modules</h2>
            
            <div class="menu-grid">
                <a href="generate_schedule.php" class="menu-card">
                    <div class="menu-icon">📅</div>
                    <h3>Generate Schedule</h3>
                    <p>Create new schedules using Genetic Algorithm</p>
                </a>
                
                <a href="report.php" class="menu-card">
                    <div class="menu-icon">📊</div>
                    <h3>Reports</h3>
                    <p>View and filter generated schedules</p>
                </a>
                
                <a href="manage_instructors.php" class="menu-card">
                    <div class="menu-icon">👥</div>
                    <h3>Manage Instructors</h3>
                    <p>Add, edit, or remove instructors</p>
                </a>
                
                <a href="manage_rooms.php" class="menu-card">
                    <div class="menu-icon">🏛️</div>
                    <h3>Manage Rooms</h3>
                    <p>Configure room availability and capacity</p>
                </a>
                
                <a href="manage_subjects.php" class="menu-card">
                    <div class="menu-icon">📚</div>
                    <h3>Manage Subjects</h3>
                    <p>Add or modify course subjects</p>
                </a>
                
                <a href="manage_time_slots.php" class="menu-card">
                    <div class="menu-icon">⏰</div>
                    <h3>Time Slots</h3>
                    <p>Configure available time slots</p>
                </a>
                
                <a href="manage_programs.php" class="menu-card">
                    <div class="menu-icon">🎓</div>
                    <h3>Departments</h3>
                    <p>Manage departments and the programs under them</p>
                </a>
                
                <a href="manage_program_chairs.php" class="menu-card">
                    <div class="menu-icon">👔</div>
                    <h3>Program Chairs</h3>
                    <p>Manage program chair accounts</p>
                </a>
            </div>
        </div>
        
        <div class="recent-jobs">
            <h2>Recent Schedule Generation Jobs</h2>
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
                    <?php
                    $jobs = $pdo->query("SELECT * FROM schedule_jobs ORDER BY created_at DESC LIMIT 5")->fetchAll();
                    foreach ($jobs as $job):
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($job['job_name']); ?></td>
                        <td>
                            <span class="status-badge status-<?php echo $job['status']; ?>">
                                <?php echo $job['status']; ?>
                            </span>
                        </td>
                        <td><?php echo $job['created_at']; ?></td>
                        <td>
                            <a href="view_schedules.php?job_id=<?php echo $job['id']; ?>" class="btn-small">View</a>
                            <a href="dashboard.php?save_job=<?php echo $job['id']; ?>" class="btn-small" onclick="return confirm('Save a recoverable backup of this schedule job?');">Save</a>
                            <a href="dashboard.php?delete_job=<?php echo $job['id']; ?>" class="btn-small btn-danger" onclick="return confirm('Delete this job and all its generated schedules?');">Delete</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="recent-jobs">
            <h2>Saved Schedule Backups</h2>
            <?php if (empty($savedBackups)): ?>
                <p class="no-data">No saved schedule backups yet.</p>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Job Name</th>
                            <th>Saved At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($savedBackups as $backup): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($backup['job_name']); ?></td>
                            <td><?php echo htmlspecialchars((string)$backup['saved_at']); ?></td>
                            <td>
                                <a href="dashboard.php?restore_saved=<?php echo (int)$backup['id']; ?>" class="btn-small" onclick="return confirm('Restore this saved schedule as a new job?');">Restore</a>
                                <a href="dashboard.php?delete_saved=<?php echo (int)$backup['id']; ?>" class="btn-small btn-danger" onclick="return confirm('Delete this saved backup permanently?');">Delete</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
