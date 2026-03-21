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

    if (!isset($subjectColumns['lecture_hours'])) {
        $pdo->exec("ALTER TABLE subjects ADD COLUMN lecture_hours DECIMAL(4,2) NOT NULL DEFAULT 0.00 AFTER hours_per_week");
    }
    if (!isset($subjectColumns['lab_hours'])) {
        $pdo->exec("ALTER TABLE subjects ADD COLUMN lab_hours DECIMAL(4,2) NOT NULL DEFAULT 0.00 AFTER lecture_hours");
    }
    if (!isset($subjectColumns['semester'])) {
        $pdo->exec("ALTER TABLE subjects ADD COLUMN semester ENUM('1st Semester','2nd Semester','Summer') NOT NULL DEFAULT '1st Semester' AFTER subject_type");
    } else {
        $pdo->exec("ALTER TABLE subjects MODIFY COLUMN semester ENUM('1st Semester','2nd Semester','Summer') NOT NULL DEFAULT '1st Semester'");
    }
    if (!isset($subjectColumns['program_id'])) {
        $pdo->exec("ALTER TABLE subjects ADD COLUMN program_id INT NULL AFTER department");
    }
    if (!isset($subjectColumns['year_level'])) {
        $pdo->exec("ALTER TABLE subjects ADD COLUMN year_level INT NULL AFTER semester");
    }
    if (!isset($subjectColumns['prerequisites'])) {
        $pdo->exec("ALTER TABLE subjects ADD COLUMN prerequisites TEXT NULL AFTER year_level");
    }
} catch (Exception $e) {
    // Continue page load even if auto-migration is not allowed in this environment.
}

try {
    $pdo->exec("
        UPDATE subjects
        SET semester = '1st Semester'
        WHERE semester IS NULL OR semester = '' OR semester NOT IN ('1st Semester','2nd Semester','Summer')
    ");
} catch (Exception $e) {
    // Keep the page usable even if legacy cleanup cannot run here.
}

function defaultHoursFromSubjectType($type) {
    return strtolower((string)$type) === 'minor' ? 1.50 : 4.25;
}

function normalizeHoursPerWeek($rawHours, $subjectType) {
    if ($rawHours === null || $rawHours === '') {
        return defaultHoursFromSubjectType($subjectType);
    }
    $hours = (float)$rawHours;
    if ($hours <= 0) {
        return defaultHoursFromSubjectType($subjectType);
    }
    return round($hours, 2);
}

function normalizeNonNegativeHours($rawHours) {
    $hours = (float)($rawHours ?? 0);
    if ($hours < 0) {
        $hours = 0;
    }
    return round($hours, 2);
}

function normalizeSemester($rawSemester) {
    $semester = trim((string)($rawSemester ?? ''));
    $allowed = ['1st Semester', '2nd Semester', 'Summer'];
    return in_array($semester, $allowed, true) ? $semester : '1st Semester';
}

function normalizeYearLevel($rawYearLevel) {
    $yearLevel = (int)($rawYearLevel ?? 1);
    if ($yearLevel < 1 || $yearLevel > 5) {
        return 1;
    }
    return $yearLevel;
}

function normalizeProgramScope($rawProgramId, $programNameById) {
    $raw = trim((string)($rawProgramId ?? ''));
    if ($raw === '' || strtolower($raw) === 'all') {
        return [null, 'All Programs'];
    }
    $programId = (int)$raw;
    if ($programId <= 0 || !isset($programNameById[$programId])) {
        return [null, 'All Programs'];
    }
    return [$programId, $programNameById[$programId]];
}

// Fetch departments for dropdown
$departments = $pdo->query("SELECT * FROM departments ORDER BY dept_name")->fetchAll();
$programs = $pdo->query("SELECT id, program_name, program_code FROM programs ORDER BY program_name")->fetchAll(PDO::FETCH_ASSOC);
$programNameById = [];
foreach ($programs as $programRow) {
    $programNameById[(int)$programRow['id']] = (string)$programRow['program_name'];
}

// Handle Add/Edit/Delete operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_subject'])) {
        $subject_code = $_POST['subject_code'];
        $subject_name = $_POST['subject_name'];
        $credits = $_POST['credits'];
        [$program_id, $department] = normalizeProgramScope($_POST['program_id'] ?? 'all', $programNameById);
        $subject_type = strtolower(trim((string)($_POST['subject_type'] ?? 'major')));
        $semester = normalizeSemester($_POST['semester'] ?? '1st Semester');
        $year_level = normalizeYearLevel($_POST['year_level'] ?? 1);
        $prerequisites = trim((string)($_POST['prerequisites'] ?? ''));
        if (!in_array($subject_type, ['major', 'minor'], true)) {
            $subject_type = 'major';
        }
        $lecture_hours = normalizeNonNegativeHours($_POST['lecture_hours'] ?? 0);
        $lab_hours = normalizeNonNegativeHours($_POST['lab_hours'] ?? 0);
        if ($subject_type === 'major') {
            $hours_per_week = round($lecture_hours + $lab_hours, 2);
            if ($hours_per_week <= 0) {
                $lecture_hours = 2.00;
                $lab_hours = 2.25;
                $hours_per_week = 4.25;
            }
        } else {
            $hours_per_week = normalizeHoursPerWeek($_POST['hours_per_week'] ?? null, $subject_type);
            $lecture_hours = $hours_per_week;
            $lab_hours = 0.00;
        }
        
        try {
            $stmt = $pdo->prepare("INSERT INTO subjects (subject_code, subject_name, credits, department, program_id, subject_type, semester, year_level, prerequisites, hours_per_week, lecture_hours, lab_hours) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$subject_code, $subject_name, $credits, $department, $program_id, $subject_type, $semester, $year_level, $prerequisites, $hours_per_week, $lecture_hours, $lab_hours]);
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
        [$program_id, $department] = normalizeProgramScope($_POST['program_id'] ?? 'all', $programNameById);
        $subject_type = strtolower(trim((string)($_POST['subject_type'] ?? 'major')));
        $semester = normalizeSemester($_POST['semester'] ?? '1st Semester');
        $year_level = normalizeYearLevel($_POST['year_level'] ?? 1);
        $prerequisites = trim((string)($_POST['prerequisites'] ?? ''));
        if (!in_array($subject_type, ['major', 'minor'], true)) {
            $subject_type = 'major';
        }
        $lecture_hours = normalizeNonNegativeHours($_POST['lecture_hours'] ?? 0);
        $lab_hours = normalizeNonNegativeHours($_POST['lab_hours'] ?? 0);
        if ($subject_type === 'major') {
            $hours_per_week = round($lecture_hours + $lab_hours, 2);
            if ($hours_per_week <= 0) {
                $lecture_hours = 2.00;
                $lab_hours = 2.25;
                $hours_per_week = 4.25;
            }
        } else {
            $hours_per_week = normalizeHoursPerWeek($_POST['hours_per_week'] ?? null, $subject_type);
            $lecture_hours = $hours_per_week;
            $lab_hours = 0.00;
        }
        
        try {
            $stmt = $pdo->prepare("UPDATE subjects SET subject_code = ?, subject_name = ?, credits = ?, department = ?, program_id = ?, subject_type = ?, semester = ?, year_level = ?, prerequisites = ?, hours_per_week = ?, lecture_hours = ?, lab_hours = ? WHERE id = ?");
            $stmt->execute([$subject_code, $subject_name, $credits, $department, $program_id, $subject_type, $semester, $year_level, $prerequisites, $hours_per_week, $lecture_hours, $lab_hours, $id]);
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
    SELECT s.*,
           p.program_name AS linked_program_name,
           COALESCE(p.program_name, s.department, 'All Programs') AS program_display_name,
           COALESCE(NULLIF(s.semester, ''), '1st Semester') AS normalized_semester,
           COALESCE(subject_type, 'major') AS subject_type
    FROM subjects s
    LEFT JOIN programs p ON s.program_id = p.id
    ORDER BY program_display_name, subject_code
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
            padding: 9px 14px;
            border: 1px solid transparent;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            line-height: 1;
            letter-spacing: 0.01em;
            transition: transform 0.18s ease, box-shadow 0.18s ease, filter 0.18s ease, background-color 0.18s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
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
                    <th>Year</th>
                    <th>Semester</th>
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
                    ($subject['program_display_name'] ?? $subject['department']) . ' ' .
                    ($subject['semester'] ?? '') . ' ' .
                    $subject['subject_type']
                )); ?>">
                    <td><?php echo htmlspecialchars($subject['subject_code']); ?></td>
                    <td><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                    <td><?php echo htmlspecialchars($subject['program_display_name'] ?? $subject['department']); ?></td>
                    <td><?php echo htmlspecialchars((string)($subject['year_level'] ?? 1)); ?></td>
                    <td><?php echo htmlspecialchars($subject['normalized_semester'] ?? '1st Semester'); ?></td>
                    <td><?php echo ucfirst(htmlspecialchars($subject['subject_type'] ?? 'major')); ?></td>
                    <td><?php echo $subject['credits']; ?></td>
                    <td><?php echo number_format((float)$subject['hours_per_week'], 2); ?></td>
                    <td>
                        <div class="row-actions">
                            <button class="btn-icon btn-edit" onclick="editSubject(<?php echo $subject['id']; ?>)"><i class="fas fa-edit"></i> Edit</button>
                            <form method="POST" onsubmit="return confirm('Are you sure you want to delete this subject?')">
                                <input type="hidden" name="subject_id" value="<?php echo $subject['id']; ?>">
                                <button type="submit" name="delete_subject" class="btn-icon btn-delete"><i class="fas fa-trash-alt"></i> Delete</button>
                            </form>
                        </div>
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
                    <label for="program_id">Program:</label>
                    <select id="program_id" name="program_id" required>
                        <option value="all" selected>All Programs</option>
                        <?php foreach ($programs as $program): ?>
                        <option value="<?php echo (int)$program['id']; ?>">
                            <?php echo htmlspecialchars($program['program_name']); ?> (<?php echo htmlspecialchars($program['program_code']); ?>)
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
                        <option value="major" selected>Major</option>
                        <option value="minor">Minor</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="semester">Semester:</label>
                    <select id="semester" name="semester" required>
                        <option value="1st Semester" selected>1st Semester</option>
                        <option value="2nd Semester">2nd Semester</option>
                        <option value="Summer">Summer</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="year_level">Year Level:</label>
                    <select id="year_level" name="year_level" required>
                        <option value="1">1st Year</option>
                        <option value="2">2nd Year</option>
                        <option value="3">3rd Year</option>
                        <option value="4">4th Year</option>
                        <option value="5">5th Year</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="prerequisites">Prerequisites:</label>
                    <input type="text" id="prerequisites" name="prerequisites" placeholder="Optional prerequisite text">
                </div>

                <div id="add_major_breakdown">
                    <div class="form-group">
                        <label for="lecture_hours">Lecture Hours:</label>
                        <input type="number" id="lecture_hours" name="lecture_hours" min="0" max="10" step="0.25" value="2.0">
                    </div>
                    <div class="form-group">
                        <label for="lab_hours">Lab Hours:</label>
                        <input type="number" id="lab_hours" name="lab_hours" min="0" max="10" step="0.25" value="2.25">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="hours_per_week">Hours per Week:</label>
                    <input type="number" id="hours_per_week" name="hours_per_week" min="0.5" max="10" step="0.25" value="4.25" required>
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
                    <label for="edit_program_id">Program:</label>
                    <select id="edit_program_id" name="program_id" required>
                        <option value="all">All Programs</option>
                        <?php foreach ($programs as $program): ?>
                        <option value="<?php echo (int)$program['id']; ?>">
                            <?php echo htmlspecialchars($program['program_name']); ?>
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
                        <option value="major">Major</option>
                        <option value="minor">Minor</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="edit_semester">Semester:</label>
                    <select id="edit_semester" name="semester" required>
                        <option value="1st Semester">1st Semester</option>
                        <option value="2nd Semester">2nd Semester</option>
                        <option value="Summer">Summer</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="edit_year_level">Year Level:</label>
                    <select id="edit_year_level" name="year_level" required>
                        <option value="1">1st Year</option>
                        <option value="2">2nd Year</option>
                        <option value="3">3rd Year</option>
                        <option value="4">4th Year</option>
                        <option value="5">5th Year</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="edit_prerequisites">Prerequisites:</label>
                    <input type="text" id="edit_prerequisites" name="prerequisites">
                </div>

                <div id="edit_major_breakdown">
                    <div class="form-group">
                        <label for="edit_lecture_hours">Lecture Hours:</label>
                        <input type="number" id="edit_lecture_hours" name="lecture_hours" min="0" max="10" step="0.25" value="2.0">
                    </div>
                    <div class="form-group">
                        <label for="edit_lab_hours">Lab Hours:</label>
                        <input type="number" id="edit_lab_hours" name="lab_hours" min="0" max="10" step="0.25" value="2.25">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="edit_hours_per_week">Hours per Week:</label>
                    <input type="number" id="edit_hours_per_week" name="hours_per_week" min="0.5" max="10" step="0.25" required>
                </div>
                
                <button type="submit" name="edit_subject" class="btn-primary">Update Subject</button>
            </form>
        </div>
    </div>
    
    <script>
        function getHoursBySubjectType(typeValue) {
            return typeValue === 'minor' ? 1.5 : 4.25;
        }

        function updateMajorBreakdownVisibility(typeEl, breakdownWrap, hoursEl) {
            if (!typeEl || !breakdownWrap || !hoursEl) {
                return;
            }
            const isMajor = typeEl.value === 'major';
            breakdownWrap.style.display = isMajor ? 'block' : 'none';
            hoursEl.readOnly = isMajor;
        }

        function syncMajorTotal(lectureEl, labEl, hoursEl) {
            if (!lectureEl || !labEl || !hoursEl) {
                return;
            }
            const lec = parseFloat(lectureEl.value || 0);
            const lab = parseFloat(labEl.value || 0);
            const total = (Number.isNaN(lec) ? 0 : lec) + (Number.isNaN(lab) ? 0 : lab);
            hoursEl.value = total.toFixed(2);
        }

        function syncHoursDefault(typeEl, hoursEl) {
            if (!typeEl || !hoursEl) {
                return;
            }
            const current = parseFloat(hoursEl.value);
            const prevDefault = getHoursBySubjectType(typeEl.dataset.previousType || 'major');
            // Auto-adjust only if the user still has the previous default.
            if (Number.isNaN(current) || Math.abs(current - prevDefault) < 0.001) {
                hoursEl.value = getHoursBySubjectType(typeEl.value).toFixed(2);
            }
            typeEl.dataset.previousType = typeEl.value;
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
                    document.getElementById('edit_program_id').value = data.program_id ? String(data.program_id) : 'all';
                    document.getElementById('edit_credits').value = data.credits;
                    document.getElementById('edit_semester').value = data.semester || '1st Semester';
                    document.getElementById('edit_year_level').value = String(data.year_level || 1);
                    document.getElementById('edit_prerequisites').value = data.prerequisites || '';
                    const editTypeEl = document.getElementById('edit_subject_type');
                    const editHoursEl = document.getElementById('edit_hours_per_week');
                    const editLectureEl = document.getElementById('edit_lecture_hours');
                    const editLabEl = document.getElementById('edit_lab_hours');
                    const editBreakdown = document.getElementById('edit_major_breakdown');
                    editTypeEl.value = (data.subject_type || 'major').toLowerCase();
                    editTypeEl.dataset.previousType = editTypeEl.value;
                    editLectureEl.value = Number.parseFloat(data.lecture_hours || 0).toFixed(2);
                    editLabEl.value = Number.parseFloat(data.lab_hours || 0).toFixed(2);
                    editHoursEl.value = Number.parseFloat(data.hours_per_week || getHoursBySubjectType(editTypeEl.value)).toFixed(2);
                    if (editTypeEl.value === 'major' && (parseFloat(editLectureEl.value) > 0 || parseFloat(editLabEl.value) > 0)) {
                        syncMajorTotal(editLectureEl, editLabEl, editHoursEl);
                    }
                    updateMajorBreakdownVisibility(editTypeEl, editBreakdown, editHoursEl);
                    openModal('editSubjectModal');
                });
        }

        const addSubjectType = document.getElementById('subject_type');
        if (addSubjectType) {
            const addHoursEl = document.getElementById('hours_per_week');
            const addLectureEl = document.getElementById('lecture_hours');
            const addLabEl = document.getElementById('lab_hours');
            const addBreakdown = document.getElementById('add_major_breakdown');
            addSubjectType.dataset.previousType = addSubjectType.value;
            addSubjectType.addEventListener('change', function () {
                syncHoursDefault(addSubjectType, addHoursEl);
                updateMajorBreakdownVisibility(addSubjectType, addBreakdown, addHoursEl);
                if (addSubjectType.value === 'major') {
                    syncMajorTotal(addLectureEl, addLabEl, addHoursEl);
                }
            });
            if (!addHoursEl.value) {
                addHoursEl.value = getHoursBySubjectType(addSubjectType.value).toFixed(2);
            }
            if (addLectureEl && addLabEl) {
                addLectureEl.addEventListener('input', function () {
                    if (addSubjectType.value === 'major') {
                        syncMajorTotal(addLectureEl, addLabEl, addHoursEl);
                    }
                });
                addLabEl.addEventListener('input', function () {
                    if (addSubjectType.value === 'major') {
                        syncMajorTotal(addLectureEl, addLabEl, addHoursEl);
                    }
                });
            }
            updateMajorBreakdownVisibility(addSubjectType, addBreakdown, addHoursEl);
            syncMajorTotal(addLectureEl, addLabEl, addHoursEl);
        }

        const editSubjectType = document.getElementById('edit_subject_type');
        if (editSubjectType) {
            const editHoursEl = document.getElementById('edit_hours_per_week');
            const editLectureEl = document.getElementById('edit_lecture_hours');
            const editLabEl = document.getElementById('edit_lab_hours');
            const editBreakdown = document.getElementById('edit_major_breakdown');
            editSubjectType.addEventListener('change', function () {
                syncHoursDefault(editSubjectType, editHoursEl);
                updateMajorBreakdownVisibility(editSubjectType, editBreakdown, editHoursEl);
                if (editSubjectType.value === 'major') {
                    syncMajorTotal(editLectureEl, editLabEl, editHoursEl);
                }
            });
            if (editLectureEl && editLabEl) {
                editLectureEl.addEventListener('input', function () {
                    if (editSubjectType.value === 'major') {
                        syncMajorTotal(editLectureEl, editLabEl, editHoursEl);
                    }
                });
                editLabEl.addEventListener('input', function () {
                    if (editSubjectType.value === 'major') {
                        syncMajorTotal(editLectureEl, editLabEl, editHoursEl);
                    }
                });
            }
            updateMajorBreakdownVisibility(editSubjectType, editBreakdown, editHoursEl);
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
