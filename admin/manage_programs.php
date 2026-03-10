<?php
require_once '../includes/auth.php';
requireAdmin();

$pdo = getDB();
$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Add new program
    if (isset($_POST['add_program'])) {
        $program_name = trim($_POST['program_name']);
        $program_code = strtoupper(trim($_POST['program_code']));
        
        if (empty($program_name) || empty($program_code)) {
            $error = 'Program name and code are required.';
        } else {
            // Check if program code exists
            $stmt = $pdo->prepare("SELECT id FROM programs WHERE program_code = ?");
            $stmt->execute([$program_code]);
            if ($stmt->fetch()) {
                $error = 'Program code already exists.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO programs (program_name, program_code) VALUES (?, ?)");
                $stmt->execute([$program_name, $program_code]);
                $message = 'Program added successfully!';
            }
        }
    }
    
    // Delete program
    if (isset($_POST['delete_program'])) {
        $prog_id = (int)$_POST['prog_id'];
        
        // Check if program has subjects
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM subjects WHERE program_id = ?");
        $stmt->execute([$prog_id]);
        $subject_count = $stmt->fetchColumn();
        
        if ($subject_count > 0) {
            $error = 'Cannot delete program that has subjects assigned. Remove subjects first.';
        } else {
            $stmt = $pdo->prepare("DELETE FROM programs WHERE id = ?");
            $stmt->execute([$prog_id]);
            $message = 'Program deleted successfully!';
        }
    }
}

// Get all programs
$programs = $pdo->query("SELECT * FROM programs ORDER BY program_name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Programs - Academic Scheduling System</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .form-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        
        .form-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        
        .btn-danger:hover {
            background: #c82333;
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
                        <span class="welcome-text">Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                    </div>
                </div>
                <a href="../logout.php" class="btn-logout">Logout</a>
            </div>
        </div>
    </div>
    
    <div class="container">
        <h2>Manage Programs</h2>
        
        <?php if ($message): ?>
            <div class="success"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <!-- Add Program Form -->
        <div class="form-section">
            <h3>Add New Program</h3>
            <form method="POST">
                <div class="form-group">
                    <label>Program Name</label>
                    <input type="text" name="program_name" required placeholder="e.g., Bachelor of Science in Computer Science">
                </div>
                
                <div class="form-group">
                    <label>Program Code</label>
                    <input type="text" name="program_code" required placeholder="e.g., BSCS" maxlength="20">
                </div>
                
                <button type="submit" name="add_program" class="btn-primary">
                    Add Program
                </button>
            </form>
        </div>
        
        <!-- Existing Programs -->
        <div class="form-section">
            <h3>Existing Programs (<?php echo count($programs); ?>)</h3>
            
            <?php if (empty($programs)): ?>
                <p class="no-data">No programs created yet.</p>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Program Code</th>
                            <th>Program Name</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($programs as $prog): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($prog['program_code']); ?></strong></td>
                            <td><?php echo htmlspecialchars($prog['program_name']); ?></td>
                            <td>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this program?');">
                                    <input type="hidden" name="prog_id" value="<?php echo $prog['id']; ?>">
                                    <button type="submit" name="delete_program" class="btn-danger">Delete</button>
                                </form>
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
