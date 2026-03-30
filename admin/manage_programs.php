<?php
require_once '../includes/auth.php';
requireAdmin();

$pdo = getDB();
$message = '';
$error = '';

try {
    $departmentColumns = [];
    foreach ($pdo->query("SHOW COLUMNS FROM programs")->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $departmentColumns[$column['Field']] = true;
    }
    if (!isset($departmentColumns['department_id'])) {
        $pdo->exec("ALTER TABLE programs ADD COLUMN department_id INT NULL AFTER program_code");
    }
} catch (Exception $e) {
    // Keep page usable even if schema auto-update is blocked.
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_department'])) {
        $deptName = trim((string)($_POST['dept_name'] ?? ''));
        $deptCode = strtoupper(trim((string)($_POST['dept_code'] ?? '')));

        if ($deptName === '' || $deptCode === '') {
            $error = 'Department name and code are required.';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id FROM departments WHERE dept_name = ? OR dept_code = ?");
                $stmt->execute([$deptName, $deptCode]);
                if ($stmt->fetch()) {
                    $error = 'Department name or code already exists.';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO departments (dept_name, dept_code) VALUES (?, ?)");
                    $stmt->execute([$deptName, $deptCode]);
                    $message = 'Department added successfully!';
                }
            } catch (Exception $e) {
                $error = 'Error adding department: ' . $e->getMessage();
            }
        }
    }

    if (isset($_POST['add_program'])) {
        $programName = trim((string)($_POST['program_name'] ?? ''));
        $programCode = strtoupper(trim((string)($_POST['program_code'] ?? '')));
        $departmentId = (int)($_POST['department_id'] ?? 0);

        if ($programName === '' || $programCode === '' || $departmentId <= 0) {
            $error = 'Program name, code, and department are required.';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id FROM departments WHERE id = ?");
                $stmt->execute([$departmentId]);
                if (!$stmt->fetch()) {
                    $error = 'Selected department does not exist.';
                } else {
                    $stmt = $pdo->prepare("SELECT id FROM programs WHERE program_code = ? OR program_name = ?");
                    $stmt->execute([$programCode, $programName]);
                    if ($stmt->fetch()) {
                        $error = 'Program name or code already exists.';
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO programs (program_name, program_code, department_id) VALUES (?, ?, ?)");
                        $stmt->execute([$programName, $programCode, $departmentId]);
                        $message = 'Program added successfully!';
                    }
                }
            } catch (Exception $e) {
                $error = 'Error adding program: ' . $e->getMessage();
            }
        }
    }

    if (isset($_POST['delete_program'])) {
        $programId = (int)($_POST['program_id'] ?? 0);
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM subjects WHERE program_id = ?");
            $stmt->execute([$programId]);
            $subjectCount = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM instructors WHERE program_id = ?");
            $stmt->execute([$programId]);
            $instructorCount = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM program_chairs WHERE program_id = ?");
            $stmt->execute([$programId]);
            $chairCount = (int)$stmt->fetchColumn();

            if ($subjectCount > 0 || $instructorCount > 0 || $chairCount > 0) {
                $error = 'Cannot delete program while it is assigned to subjects, instructors, or program chairs.';
            } else {
                $stmt = $pdo->prepare("DELETE FROM programs WHERE id = ?");
                $stmt->execute([$programId]);
                $message = 'Program deleted successfully!';
            }
        } catch (Exception $e) {
            $error = 'Error deleting program: ' . $e->getMessage();
        }
    }

    if (isset($_POST['delete_department'])) {
        $departmentId = (int)($_POST['department_id'] ?? 0);
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM programs WHERE department_id = ?");
            $stmt->execute([$departmentId]);
            $programCount = (int)$stmt->fetchColumn();

            if ($programCount > 0) {
                $error = 'Cannot delete department while it still has programs under it.';
            } else {
                $stmt = $pdo->prepare("DELETE FROM departments WHERE id = ?");
                $stmt->execute([$departmentId]);
                $message = 'Department deleted successfully!';
            }
        } catch (Exception $e) {
            $error = 'Error deleting department: ' . $e->getMessage();
        }
    }
}

$departments = $pdo->query("
    SELECT d.*,
           COUNT(p.id) AS program_count
    FROM departments d
    LEFT JOIN programs p ON p.department_id = d.id
    GROUP BY d.id, d.dept_name, d.dept_code
    ORDER BY d.dept_name
")->fetchAll(PDO::FETCH_ASSOC);

$programs = $pdo->query("
    SELECT p.*, d.dept_name, d.dept_code
    FROM programs p
    LEFT JOIN departments d ON p.department_id = d.id
    ORDER BY d.dept_name, p.program_name
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Departments - Academic Scheduling System</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .grid-2 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

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
        <h2>Manage Departments</h2>

        <?php if ($message): ?>
            <div class="success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="grid-2">
            <div class="form-section">
                <h3>Add New Department</h3>
                <form method="POST">
                    <div class="form-group">
                        <label for="dept_name">Department Name</label>
                        <input type="text" id="dept_name" name="dept_name" required placeholder="e.g., Department of Computer Studies">
                    </div>

                    <div class="form-group">
                        <label for="dept_code">Department Code</label>
                        <input type="text" id="dept_code" name="dept_code" required placeholder="e.g., DCS" maxlength="20">
                    </div>

                    <button type="submit" name="add_department" class="btn-primary">Add Department</button>
                </form>
            </div>

            <div class="form-section">
                <h3>Add New Program Under Department</h3>
                <form method="POST">
                    <div class="form-group">
                        <label for="department_id">Department</label>
                        <select id="department_id" name="department_id" required>
                            <option value="">Select department</option>
                            <?php foreach ($departments as $department): ?>
                                <option value="<?php echo (int)$department['id']; ?>">
                                    <?php echo htmlspecialchars($department['dept_name'] . ' (' . $department['dept_code'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="program_name">Program Name</label>
                        <input type="text" id="program_name" name="program_name" required placeholder="e.g., Computer Science">
                    </div>

                    <div class="form-group">
                        <label for="program_code">Program Code</label>
                        <input type="text" id="program_code" name="program_code" required placeholder="e.g., CS" maxlength="20">
                    </div>

                    <button type="submit" name="add_program" class="btn-primary">Add Program</button>
                </form>
            </div>
        </div>

        <div class="form-section">
            <h3>Existing Departments (<?php echo count($departments); ?>)</h3>

            <?php if (empty($departments)): ?>
                <p class="no-data">No departments created yet.</p>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Department Code</th>
                            <th>Department Name</th>
                            <th>Programs Under It</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($departments as $department): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($department['dept_code']); ?></strong></td>
                                <td><?php echo htmlspecialchars($department['dept_name']); ?></td>
                                <td><?php echo (int)$department['program_count']; ?></td>
                                <td>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this department?');">
                                        <input type="hidden" name="department_id" value="<?php echo (int)$department['id']; ?>">
                                        <button type="submit" name="delete_department" class="btn-danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="form-section">
            <h3>Programs Under Departments (<?php echo count($programs); ?>)</h3>

            <?php if (empty($programs)): ?>
                <p class="no-data">No programs created yet.</p>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Department</th>
                            <th>Program Code</th>
                            <th>Program Name</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($programs as $program): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(($program['dept_name'] ?? 'No Department') . (!empty($program['dept_code']) ? ' (' . $program['dept_code'] . ')' : '')); ?></td>
                                <td><strong><?php echo htmlspecialchars($program['program_code']); ?></strong></td>
                                <td><?php echo htmlspecialchars($program['program_name']); ?></td>
                                <td>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this program?');">
                                        <input type="hidden" name="program_id" value="<?php echo (int)$program['id']; ?>">
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
