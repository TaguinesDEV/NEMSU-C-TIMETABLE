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
    SELECT * FROM schedule_jobs 
    WHERE program_id = ?
    ORDER BY created_at DESC 
    LIMIT 5
");
$stmt->execute([$program_id]);
$recentJobs = $stmt->fetchAll();

// Handle delete job
$delete_message = '';
if (isset($_GET['delete_job'])) {
    $job_id = (int) $_GET['delete_job'];
    if ($job_id > 0) {
        $stmt = $pdo->prepare("DELETE FROM schedule_jobs WHERE id = ? AND program_id = ?");
        $stmt->execute([$job_id, $program_id]);
        $delete_message = $stmt->rowCount() ? 'Job and its schedules deleted.' : '';
        header('Location: dashboard.php?deleted=1');
        exit;
    }
}
if (isset($_GET['deleted'])) {
    $delete_message = 'Job and its schedules have been deleted.';
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
            <h2>Recent Schedule Generation Jobs</h2>
            <?php if (empty($recentJobs)): ?>
                <p class="no-data">No schedule jobs yet. Click "Generate Schedule" to create one.</p>
            <?php else: ?>
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
                        <?php foreach ($recentJobs as $job): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($job['job_name']); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo $job['status']; ?>">
                                    <?php echo $job['status']; ?>
                                </span>
                            </td>
                            <td><?php echo date('M j, Y g:i A', strtotime($job['created_at'])); ?></td>
                            <td>
                                <a href="view_schedule.php?job_id=<?php echo $job['id']; ?>" class="btn-small">View</a>
                                <a href="dashboard.php?delete_job=<?php echo $job['id']; ?>" class="btn-small btn-danger" onclick="return confirm('Delete this job and all its generated schedules?');">Delete</a>
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
