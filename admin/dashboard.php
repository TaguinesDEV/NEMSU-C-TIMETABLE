<?php
require_once '../includes/auth.php';
requireAdmin();

$pdo = getDB();

// Handle delete job
$delete_message = '';
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
}

// Get counts for dashboard
$stats = [
    'instructors' => $pdo->query("SELECT COUNT(*) FROM instructors")->fetchColumn(),
    'rooms' => $pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn(),
    'subjects' => $pdo->query("SELECT COUNT(*) FROM subjects")->fetchColumn(),
    'schedules' => $pdo->query("SELECT COUNT(*) FROM schedules WHERE is_published = 1")->fetchColumn(),
    'pending_jobs' => $pdo->query("SELECT COUNT(*) FROM schedule_jobs WHERE status = 'pending'")->fetchColumn()
];
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
                    <h3>Programs</h3>
                    <p>Manage academic programs</p>
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
                            <a href="dashboard.php?delete_job=<?php echo $job['id']; ?>" class="btn-small btn-danger" onclick="return confirm('Delete this job and all its generated schedules?');">Delete</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
