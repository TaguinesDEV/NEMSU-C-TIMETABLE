<?php
require_once '../includes/auth.php';
requireAdmin();

$pdo = getDB();
$message = '';
$error = '';

// Ensure schema supports subject type and decimal hours.
try {
    $subjectColumns = [];
    foreach ($pdo->query("SHOW COLUMNS FROM subjects")->fetchAll(PDO::FETCH_ASSOC) as $col) {
        $subjectColumns[$col['Field']] = $col;
    }

    if (!isset($subjectColumns['subject_type'])) {
        $pdo->exec("ALTER TABLE subjects ADD COLUMN subject_type ENUM('major','minor') NOT NULL DEFAULT 'major' AFTER department");
    }

    $hoursType = strtolower((string)($subjectColumns['hours_per_week']['Type'] ?? ''));
    if (strpos($hoursType, 'decimal') === false) {
        $pdo->exec("ALTER TABLE subjects MODIFY hours_per_week DECIMAL(4,2) NOT NULL");
    }
} catch (Exception $e) {
    // Continue page load even if auto-migration is not allowed in this environment.
}

function hoursFromSubjectType($type) {
    return strtolower((string)$type) === 'minor' ? '1.50' : '2.50';
}

// Fetch departments for dropdown
$departments = $pdo->query("SELECT * FROM departments ORDER BY dept_name")->fetchAll();

// Handle Add/Edit/Delete operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_subject'])) {
        $subject_code = $_POST['subject_code'];
        $subject_name = $_POST['subject_name'];
        $credits = $_POST['credits'];
        $department = $_POST['department'];
        $subject_type = strtolower(trim((string)($_POST['subject_type'] ?? 'major')));
        if (!in_array($subject_type, ['major', 'minor'], true)) {
            $subject_type = 'major';
        }
        $hours_per_week = hoursFromSubjectType($subject_type);
        
        try {
            $stmt = $pdo->prepare("INSERT INTO subjects (subject_code, subject_name, credits, department, subject_type, hours_per_week) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$subject_code, $subject_name, $credits, $department, $subject_type, $hours_per_week]);
            $message = "Subject added successfully!";
        } catch (Exception $e) {
            $error = "Error adding subject: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['edit_subject'])) {
        $id = $_POST['subject_id'];
        $subject_code = $_POST['subject_code'];
        $subject_name = $_POST['subject_name'];
        $credits = $_POST['credits'];
        $department = $_POST['department'];
        $subject_type = strtolower(trim((string)($_POST['subject_type'] ?? 'major')));
        if (!in_array($subject_type, ['major', 'minor'], true)) {
            $subject_type = 'major';
        }
        $hours_per_week = hoursFromSubjectType($subject_type);
        
        try {
            $stmt = $pdo->prepare("UPDATE subjects SET subject_code = ?, subject_name = ?, credits = ?, department = ?, subject_type = ?, hours_per_week = ? WHERE id = ?");
            $stmt->execute([$subject_code, $subject_name, $credits, $department, $subject_type, $hours_per_week, $id]);
            $message = "Subject updated successfully!";
        } catch (Exception $e) {
            $error = "Error updating subject: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['delete_subject'])) {
        $id = $_POST['subject_id'];
        
        try {
            // Check if subject is used in schedules
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM schedules WHERE subject_id = ?");
            $stmt->execute([$id]);
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                $error = "Cannot delete subject because it is used in existing schedules.";
            } else {
                $stmt = $pdo->prepare("DELETE FROM subjects WHERE id = ?");
                $stmt->execute([$id]);
                $message = "Subject deleted successfully!";
            }
        } catch (Exception $e) {
            $error = "Error deleting subject: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['add_department'])) {
        $dept_name = $_POST['dept_name'];
        $dept_code = $_POST['dept_code'];
        
        try {
            $stmt = $pdo->prepare("INSERT INTO departments (dept_name, dept_code) VALUES (?, ?)");
            $stmt->execute([$dept_name, $dept_code]);
            $message = "Department added successfully!";
            
            // Refresh departments
            $departments = $pdo->query("SELECT * FROM departments ORDER BY dept_name")->fetchAll();
        } catch (Exception $e) {
            $error = "Error adding department: " . $e->getMessage();
        }
    }
}

// Fetch all subjects
$subjects = $pdo->query("
    SELECT *,
           COALESCE(subject_type, 'major') AS subject_type
    FROM subjects 
    ORDER BY department, subject_code
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Subjects - Academic Scheduling System</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: white;
            margin: 50px auto;
            padding: 20px;
            border-radius: 8px;
            width: 80%;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .close {
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        
        .close:hover {
            color: #666;
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
        }
        
        .btn-icon:hover {
            transform: translateY(-2px);
        }

        .btn-edit { background-color: #ffc107; color: #212529; }
        .btn-edit:hover { background-color: #e0a800; }
        .btn-delete { background-color: #dc3545; color: #fff; }
        .btn-delete:hover { background-color: #c82333; }

        .search-toolbar {
            margin: 14px 0 18px;
        }

        .search-toolbar label {
            display: block;
            font-weight: 600;
            margin-bottom: 6px;
        }

        .search-input-wrap {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .search-input-wrap input {
            flex: 1;
            min-width: 220px;
        }

        .btn-clear-search {
            border: 1px solid #cfd6df;
            background: #f8fafc;
            color: #334155;
            border-radius: 6px;
            padding: 9px 12px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
        }

        .btn-clear-search:disabled {
            opacity: 0.55;
            cursor: not-allowed;
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
        <h2>Manage Subjects</h2>
        <?php if ($message): ?>
            <div class="success"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>

        <div class="search-toolbar">
            <label for="subject_search">Search Subject</label>
            <div class="search-input-wrap">
                <input type="text" id="subject_search" placeholder="Type subject code, subject name, or program...">
                <button type="button" class="btn-clear-search" id="clear_subject_search" disabled>Clear</button>
            </div>
        </div>

        <div class="action-buttons">
            <button class="btn-primary" onclick="openModal('addSubjectModal')">➕ Add New Subject</button>
            <button class="btn-secondary" onclick="openModal('addDepartmentModal')">🏢 Add Program</button>
        </div>
        
        <!-- Subjects Table -->
        <table class="data-table">
            <thead>
                <tr>
                    <th>Subject Code</th>
                    <th>Subject Name</th>
                    <th>Program</th>
                    <th>Type</th>
                    <th>Credits</th>
                    <th>Hours/Week</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($subjects as $subject): ?>
                <tr data-search-text="<?php echo htmlspecialchars(strtolower(
                    $subject['subject_code'] . ' ' .
                    $subject['subject_name'] . ' ' .
                    $subject['department'] . ' ' .
                    $subject['subject_type']
                )); ?>">
                    <td><?php echo htmlspecialchars($subject['subject_code']); ?></td>
                    <td><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                    <td><?php echo htmlspecialchars($subject['department']); ?></td>
                    <td><?php echo ucfirst(htmlspecialchars($subject['subject_type'] ?? 'major')); ?></td>
                    <td><?php echo $subject['credits']; ?></td>
                    <td><?php echo number_format((float)$subject['hours_per_week'], 2); ?></td>
                    <td>
                        <button class="btn-icon btn-edit" onclick="editSubject(<?php echo $subject['id']; ?>)"><i class="fas fa-edit"></i> Edit</button>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this subject?')">
                            <input type="hidden" name="subject_id" value="<?php echo $subject['id']; ?>">
                            <button type="submit" name="delete_subject" class="btn-icon btn-delete"><i class="fas fa-trash-alt"></i> Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Add Subject Modal -->
    <div id="addSubjectModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('addSubjectModal')">&times;</span>
            <h2>Add New Subject</h2>
            <form method="POST">
                <div class="form-group">
                    <label for="subject_code">Subject Code:</label>
                    <input type="text" id="subject_code" name="subject_code" required placeholder="e.g., CS101">
                </div>
                
                <div class="form-group">
                    <label for="subject_name">Subject Name:</label>
                    <input type="text" id="subject_name" name="subject_name" required placeholder="e.g., Introduction to Programming">
                </div>
                
                <div class="form-group">
                    <label for="department">Program:</label>
                    <select id="department" name="department" required>
                        <option value="">Select Program</option>
                        <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo htmlspecialchars($dept['dept_name']); ?>">
                            <?php echo htmlspecialchars($dept['dept_name']); ?> (<?php echo $dept['dept_code']; ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="credits">Credits:</label>
                    <input type="number" id="credits" name="credits" min="1" max="6" required>
                </div>

                <div class="form-group">
                    <label for="subject_type">Subject Type:</label>
                    <select id="subject_type" name="subject_type" required>
                        <option value="major" selected>Major (2h 30m)</option>
                        <option value="minor">Minor (1h 30m)</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="hours_per_week">Hours per Week:</label>
                    <input type="number" id="hours_per_week" name="hours_per_week" min="1" max="10" step="0.5" value="2.5" readonly required>
                </div>
                
                <button type="submit" name="add_subject" class="btn-primary">Add Subject</button>
            </form>
        </div>
    </div>
    
    <!-- Add Department Modal -->
    <div id="addDepartmentModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('addDepartmentModal')">&times;</span>
            <h2>Add New Program</h2>
            <form method="POST">
                <div class="form-group">
                    <label for="dept_name">Program Name:</label>
                    <input type="text" id="dept_name" name="dept_name" required placeholder="e.g., Computer Science">
                </div>
                
                <div class="form-group">
                    <label for="dept_code">Program Code:</label>
                    <input type="text" id="dept_code" name="dept_code" required placeholder="e.g., CS">
                </div>
                
                <button type="submit" name="add_department" class="btn-primary">Add Program</button>
            </form>
        </div>
    </div>
    
    <!-- Edit Subject Modal -->
    <div id="editSubjectModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('editSubjectModal')">&times;</span>
            <h2>Edit Subject</h2>
            <form method="POST" id="editSubjectForm">
                <input type="hidden" id="edit_subject_id" name="subject_id">
                
                <div class="form-group">
                    <label for="edit_subject_code">Subject Code:</label>
                    <input type="text" id="edit_subject_code" name="subject_code" required>
                </div>
                
                <div class="form-group">
                    <label for="edit_subject_name">Subject Name:</label>
                    <input type="text" id="edit_subject_name" name="subject_name" required>
                </div>
                
                <div class="form-group">
                    <label for="edit_department">Program:</label>
                    <select id="edit_department" name="department" required>
                        <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo htmlspecialchars($dept['dept_name']); ?>">
                            <?php echo htmlspecialchars($dept['dept_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="edit_credits">Credits:</label>
                    <input type="number" id="edit_credits" name="credits" min="1" max="6" required>
                </div>

                <div class="form-group">
                    <label for="edit_subject_type">Subject Type:</label>
                    <select id="edit_subject_type" name="subject_type" required>
                        <option value="major">Major (2h 30m)</option>
                        <option value="minor">Minor (1h 30m)</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="edit_hours_per_week">Hours per Week:</label>
                    <input type="number" id="edit_hours_per_week" name="hours_per_week" min="1" max="10" step="0.5" readonly required>
                </div>
                
                <button type="submit" name="edit_subject" class="btn-primary">Update Subject</button>
            </form>
        </div>
    </div>
    
    <script>
        function getHoursBySubjectType(typeValue) {
            return typeValue === 'minor' ? '1.5' : '2.5';
        }

        function syncAddSubjectHours() {
            const typeEl = document.getElementById('subject_type');
            const hoursEl = document.getElementById('hours_per_week');
            if (!typeEl || !hoursEl) {
                return;
            }
            hoursEl.value = getHoursBySubjectType(typeEl.value);
        }

        function syncEditSubjectHours() {
            const typeEl = document.getElementById('edit_subject_type');
            const hoursEl = document.getElementById('edit_hours_per_week');
            if (!typeEl || !hoursEl) {
                return;
            }
            hoursEl.value = getHoursBySubjectType(typeEl.value);
        }

        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        function editSubject(id) {
            // Fetch subject data via AJAX
            fetch(`get_subject.php?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('edit_subject_id').value = data.id;
                    document.getElementById('edit_subject_code').value = data.subject_code;
                    document.getElementById('edit_subject_name').value = data.subject_name;
                    document.getElementById('edit_department').value = data.department;
                    document.getElementById('edit_credits').value = data.credits;
                    document.getElementById('edit_subject_type').value = (data.subject_type || 'major').toLowerCase();
                    syncEditSubjectHours();
                    openModal('editSubjectModal');
                });
        }

        const addSubjectType = document.getElementById('subject_type');
        if (addSubjectType) {
            addSubjectType.addEventListener('change', syncAddSubjectHours);
            syncAddSubjectHours();
        }

        const editSubjectType = document.getElementById('edit_subject_type');
        if (editSubjectType) {
            editSubjectType.addEventListener('change', syncEditSubjectHours);
        }
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }

        const subjectSearchInput = document.getElementById('subject_search');
        const clearSubjectSearchBtn = document.getElementById('clear_subject_search');
        if (subjectSearchInput) {
            const subjectRows = document.querySelectorAll('.data-table tbody tr');

            const filterSubjectRows = () => {
                const query = subjectSearchInput.value.trim().toLowerCase();
                subjectRows.forEach(row => {
                    const text = row.getAttribute('data-search-text') || '';
                    row.style.display = text.includes(query) ? '' : 'none';
                });
                if (clearSubjectSearchBtn) {
                    clearSubjectSearchBtn.disabled = query.length === 0;
                }
            };

            subjectSearchInput.addEventListener('input', filterSubjectRows);
            filterSubjectRows();

            if (clearSubjectSearchBtn) {
                clearSubjectSearchBtn.addEventListener('click', function () {
                    subjectSearchInput.value = '';
                    filterSubjectRows();
                    subjectSearchInput.focus();
                });
            }
        }
    </script>
</body>
</html>
