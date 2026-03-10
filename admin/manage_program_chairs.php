<?php
require_once '../includes/auth.php';
requireAdmin();

$pdo = getDB();
$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Add new program chair
    if (isset($_POST['add_program_chair'])) {
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        $full_name = trim($_POST['full_name']);
        $email = trim($_POST['email']);
        $program_id = (int)$_POST['program_id'];
        
        // Validation
        if (empty($username) || empty($password) || empty($full_name) || empty($program_id)) {
            $error = 'All fields are required.';
        } else {
            // Check if username exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $error = 'Username already exists.';
            } else {
                // Hash password
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                // Insert user
                $stmt = $pdo->prepare("INSERT INTO users (username, password, email, role, full_name) VALUES (?, ?, ?, 'program_chair', ?)");
                $stmt->execute([$username, $hashed_password, $email, $full_name]);
                $user_id = $pdo->lastInsertId();
                
                // Insert program chair
                $stmt = $pdo->prepare("INSERT INTO program_chairs (user_id, program_id) VALUES (?, ?)");
                $stmt->execute([$user_id, $program_id]);
                
                $message = 'Program chair account created successfully!';
            }
        }
    }
    
    // Delete program chair
    if (isset($_POST['delete_program_chair'])) {
        $pc_id = (int)$_POST['pc_id'];
        
        // Get user_id first
        $stmt = $pdo->prepare("SELECT user_id FROM program_chairs WHERE id = ?");
        $stmt->execute([$pc_id]);
        $pc = $stmt->fetch();
        
        if ($pc) {
            // Delete program chair
            $stmt = $pdo->prepare("DELETE FROM program_chairs WHERE id = ?");
            $stmt->execute([$pc_id]);
            
            // Delete user
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$pc['user_id']]);
            
            $message = 'Program chair deleted successfully!';
        }
    }
}

// Get all programs
$programs = $pdo->query("SELECT * FROM programs ORDER BY program_name")->fetchAll();

// Get all program chairs with their programs
$program_chairs = $pdo->query("
    SELECT pc.*, u.username, u.email, u.full_name, p.program_name, p.program_code
    FROM program_chairs pc
    JOIN users u ON pc.user_id = u.id
    JOIN programs p ON pc.program_id = p.id
    ORDER BY p.program_name, u.full_name
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Program Chairs - Academic Scheduling System</title>
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
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        .checkbox-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }
        
        .checkbox-grid label {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px;
            background: white;
            border: 1px solid #ddd;
            border-radius: 4px;
            cursor: pointer;
        }
        
        .checkbox-grid label:hover {
            background: #f0f0f0;
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
        <h2>Manage Program Chairs</h2>
        
        <?php if ($message): ?>
            <div class="success"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <!-- Add Program Chair Form -->
        <div class="form-section">
            <h3>Create New Program Chair Account</h3>
            <form method="POST">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" required placeholder="Enter username">
                </div>
                
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="full_name" required placeholder="Enter full name">
                </div>
                
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" placeholder="Enter email (optional)">
                </div>
                
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" required placeholder="Enter password">
                </div>
                
                <div class="form-group">
                    <label>Program</label>
                    <select name="program_id" required>
                        <option value="">-- Select Program --</option>
                        <?php foreach ($programs as $prog): ?>
                            <option value="<?php echo $prog['id']; ?>">
                                <?php echo htmlspecialchars($prog['program_name'] . ' (' . $prog['program_code'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <button type="submit" name="add_program_chair" class="btn-primary">
                    Create Program Chair Account
                </button>
            </form>
        </div>
        
        <!-- Existing Program Chairs -->
        <div class="form-section">
            <h3>Existing Program Chairs (<?php echo count($program_chairs); ?>)</h3>
            
            <?php if (empty($program_chairs)): ?>
                <p class="no-data">No program chairs created yet.</p>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Program</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($program_chairs as $pc): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($pc['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($pc['username']); ?></td>
                            <td><?php echo htmlspecialchars($pc['email'] ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars($pc['program_name'] . ' (' . $pc['program_code'] . ')'); ?></td>
                            <td>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this program chair?');">
                                    <input type="hidden" name="pc_id" value="<?php echo $pc['id']; ?>">
                                    <button type="submit" name="delete_program_chair" class="btn-danger">Delete</button>
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
