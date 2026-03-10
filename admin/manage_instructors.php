<?php
require_once '../includes/auth.php';
requireAdmin();

$pdo = getDB();
$message = '';
$error = '';

// Handle Add/Edit/Delete operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_instructor'])) {
        // Add new instructor
        $username = $_POST['username'];
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $email = $_POST['email'];
        $full_name = $_POST['full_name'];
        $department = $_POST['department'];
        $specializations = array_values(array_unique(array_filter([$_POST['specialization_1'] ?? '', $_POST['specialization_2'] ?? '', $_POST['specialization_3'] ?? ''])));
        $max_hours = $_POST['max_hours_per_week'];
        $program_id = !empty($_POST['program_id']) ? $_POST['program_id'] : null;
        
        try {
            // Start transaction
            $pdo->beginTransaction();
            
            // Insert into users table
            $stmt = $pdo->prepare("INSERT INTO users (username, password, email, role, full_name) VALUES (?, ?, ?, 'instructor', ?)");
            $stmt->execute([$username, $password, $email, $full_name]);
            $user_id = $pdo->lastInsertId();
            
            // Insert into instructors table with program_id
            $stmt = $pdo->prepare("INSERT INTO instructors (user_id, department, max_hours_per_week, program_id) VALUES (?, ?, ?, ?)");
            $stmt->execute([$user_id, $department, $max_hours, $program_id]);
            $instructor_id = $pdo->lastInsertId();
            
            // Insert specializations
            foreach ($specializations as $priority => $spec) {
                // Create specialization if it doesn't exist
                $stmt = $pdo->prepare("INSERT IGNORE INTO specializations (specialization_name) VALUES (?)");
                $stmt->execute([$spec]);
                
                // Get specialization ID
                $stmt = $pdo->prepare("SELECT id FROM specializations WHERE specialization_name = ?");
                $stmt->execute([$spec]);
                $spec_id = $stmt->fetchColumn();
                
                // Link to instructor
                $stmt = $pdo->prepare("INSERT INTO instructor_specializations (instructor_id, specialization_id, priority) VALUES (?, ?, ?)");
                $stmt->execute([$instructor_id, $spec_id, $priority + 1]);
            }
            
            $pdo->commit();
            $message = "Instructor added successfully!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error adding instructor: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['edit_instructor'])) {
        $id = $_POST['instructor_id'];
        $email = $_POST['email'];
        $full_name = $_POST['full_name'];
        $department = $_POST['department'];
        $specializations = array_values(array_unique(array_filter([$_POST['specialization_1'] ?? '', $_POST['specialization_2'] ?? '', $_POST['specialization_3'] ?? ''])));
        $max_hours = $_POST['max_hours_per_week'];
        $program_id = !empty($_POST['program_id']) ? $_POST['program_id'] : null;
        
        try {
            // Start transaction
            $pdo->beginTransaction();
            
            // Get user_id from instructor
            $stmt = $pdo->prepare("SELECT user_id FROM instructors WHERE id = ?");
            $stmt->execute([$id]);
            $user_id = $stmt->fetchColumn();
            
            // Update users table
            $stmt = $pdo->prepare("UPDATE users SET email = ?, full_name = ? WHERE id = ?");
            $stmt->execute([$email, $full_name, $user_id]);
            
            // Update instructors table with program_id
            $stmt = $pdo->prepare("UPDATE instructors SET department = ?, max_hours_per_week = ?, program_id = ? WHERE id = ?");
            $stmt->execute([$department, $max_hours, $program_id, $id]);
            
            // Delete existing specializations
            $stmt = $pdo->prepare("DELETE FROM instructor_specializations WHERE instructor_id = ?");
            $stmt->execute([$id]);
            
            // Insert new specializations
            foreach ($specializations as $priority => $spec) {
                // Create specialization if it doesn't exist
                $stmt = $pdo->prepare("INSERT IGNORE INTO specializations (specialization_name) VALUES (?)");
                $stmt->execute([$spec]);
                
                // Get specialization ID
                $stmt = $pdo->prepare("SELECT id FROM specializations WHERE specialization_name = ?");
                $stmt->execute([$spec]);
                $spec_id = $stmt->fetchColumn();
                
                // Link to instructor
                $stmt = $pdo->prepare("INSERT INTO instructor_specializations (instructor_id, specialization_id, priority) VALUES (?, ?, ?)");
                $stmt->execute([$id, $spec_id, $priority + 1]);
            }
            
            $pdo->commit();
            $message = "Instructor updated successfully!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error updating instructor: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['delete_instructor'])) {
        $id = $_POST['instructor_id'];
        
        try {
            // Check if instructor has schedules
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM schedules WHERE instructor_id = ?");
            $stmt->execute([$id]);
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                $error = "Cannot delete instructor because they have assigned schedules.";
            } else {
                // Get user_id
                $stmt = $pdo->prepare("SELECT user_id FROM instructors WHERE id = ?");
                $stmt->execute([$id]);
                $user_id = $stmt->fetchColumn();
                
                // Delete from instructors (cascade will delete user)
                $stmt = $pdo->prepare("DELETE FROM instructors WHERE id = ?");
                $stmt->execute([$id]);
                
                $message = "Instructor deleted successfully!";
            }
        } catch (Exception $e) {
            $error = "Error deleting instructor: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['set_availability'])) {
        $instructor_id = $_POST['instructor_id'];
        $time_slots = $_POST['time_slots'] ?? [];
        
        try {
            // Clear existing availability
            $stmt = $pdo->prepare("DELETE FROM instructor_availability WHERE instructor_id = ?");
            $stmt->execute([$instructor_id]);
            
            // Insert new availability
            $stmt = $pdo->prepare("INSERT INTO instructor_availability (instructor_id, time_slot_id, is_available) VALUES (?, ?, 1)");
            foreach ($time_slots as $time_slot_id) {
                $stmt->execute([$instructor_id, $time_slot_id]);
            }
            
            $message = "Availability updated successfully!";
        } catch (Exception $e) {
            $error = "Error updating availability: " . $e->getMessage();
        }
    }
}

// Fetch all instructors with details and specializations
$instructors = $pdo->query("
    SELECT i.id, i.user_id, i.department, i.max_hours_per_week, i.program_id, u.username, u.email, u.full_name 
    FROM instructors i 
    JOIN users u ON i.user_id = u.id 
    ORDER BY u.full_name
")->fetchAll();

// Fetch programs for dropdown
$programs = $pdo->query("SELECT * FROM programs ORDER BY program_name")->fetchAll();

// Fetch subjects for instructor subject-priority assignment
$subjects_for_assignment = $pdo->query("
    SELECT id, subject_code, subject_name
    FROM subjects
    ORDER BY subject_code
")->fetchAll();

// Fetch time slots for availability modal
$time_slots = $pdo->query("SELECT * FROM time_slots ORDER BY day, start_time")->fetchAll();

// Function to get specializations for an instructor
function getInstructorSpecializations($pdo, $instructor_id) {
    $stmt = $pdo->prepare("
        SELECT s.specialization_name 
        FROM instructor_specializations ism
        JOIN specializations s ON ism.specialization_id = s.id
        WHERE ism.instructor_id = ?
        ORDER BY ism.priority
    ");
    $stmt->execute([$instructor_id]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Function to get program name by ID
function getProgramName($pdo, $program_id) {
    if (!$program_id) return '';
    $stmt = $pdo->prepare("SELECT program_name FROM programs WHERE id = ?");
    $stmt->execute([$program_id]);
    return $stmt->fetchColumn() ?? '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Instructors - Academic Scheduling System</title>
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
        
        .availability-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 10px;
            margin: 15px 0;
            max-height: 400px;
            overflow-y: auto;
        }
        
        .availability-item {
            display: flex;
            align-items: center;
            gap: 5px;
            padding: 5px;
            border: 1px solid #dee2e6;
            border-radius: 4px;
        }
        
        .action-buttons {
            display: flex;
            gap: 5px;
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
        .btn-availability { background-color: #17a2b8; color: #fff; }
        .btn-availability:hover { background-color: #138496; }
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
        <h2>Manage Instructors</h2>
        <?php if ($message): ?>
            <div class="success"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <div class="search-toolbar">
            <label for="instructor_search">Search Instructor</label>
            <div class="search-input-wrap">
                <input type="text" id="instructor_search" placeholder="Type name, username, email, department, program, or subject...">
                <button type="button" class="btn-clear-search" id="clear_instructor_search" disabled>Clear</button>
            </div>
        </div>

        <!-- Add Instructor Button -->
        <button class="btn-primary" onclick="openModal('addModal')">➕ Add New Instructor</button>
        
        <!-- Instructors Table -->
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Department</th>
                    <th>Program</th>
                    <th>Subject Assignments</th>
                    <th>Max Hours/Week</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($instructors as $instructor): ?>
                <tr data-search-text="<?php echo htmlspecialchars(strtolower(
                    $instructor['full_name'] . ' ' .
                    $instructor['username'] . ' ' .
                    $instructor['email'] . ' ' .
                    $instructor['department'] . ' ' .
                    (getProgramName($pdo, $instructor['program_id']) ?: 'all programs') . ' ' .
                    implode(' ', getInstructorSpecializations($pdo, $instructor['id']))
                )); ?>">
                    <td><?php echo htmlspecialchars($instructor['full_name']); ?></td>
                    <td><?php echo htmlspecialchars($instructor['username']); ?></td>
                    <td><?php echo htmlspecialchars($instructor['email']); ?></td>
                    <td><?php echo htmlspecialchars($instructor['department']); ?></td>
                    <td><?php echo htmlspecialchars(getProgramName($pdo, $instructor['program_id']) ?: 'All Programs'); ?></td>
                    <td><?php 
                        $specs = getInstructorSpecializations($pdo, $instructor['id']);
                        echo htmlspecialchars(implode(', ', $specs) ?: '(No subject assignment)'); 
                    ?></td>
                    <td><?php echo $instructor['max_hours_per_week']; ?></td>
                    <td class="action-buttons">
                        <button class="btn-icon btn-edit" onclick="editInstructor(<?php echo $instructor['id']; ?>)"><i class="fas fa-edit"></i> Edit</button>
                        <button class="btn-icon btn-availability" onclick="setAvailability(<?php echo $instructor['id']; ?>, '<?php echo htmlspecialchars(addslashes($instructor['full_name'])); ?>')"><i class="fas fa-calendar-alt"></i> Availability</button>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this instructor? This action cannot be undone.')">
                            <input type="hidden" name="instructor_id" value="<?php echo $instructor['id']; ?>">
                            <button type="submit" name="delete_instructor" class="btn-icon btn-delete"><i class="fas fa-trash-alt"></i> Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Add Instructor Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('addModal')">&times;</span>
            <h2>Add New Instructor</h2>
            <form method="POST">
                <div class="form-group">
                    <label for="username">Username:</label>
                    <input type="text" id="username" name="username" required>
                </div>
                
                <div class="form-group">
                    <label for="password">Password:</label>
                    <input type="password" id="password" name="password" required>
                </div>
                
                <div class="form-group">
                    <label for="email">Email:</label>
                    <input type="email" id="email" name="email" required>
                </div>
                
                <div class="form-group">
                    <label for="full_name">Full Name:</label>
                    <input type="text" id="full_name" name="full_name" required>
                </div>
                
                <div class="form-group">
                    <label for="department">Department:</label>
                    <input type="text" id="department" name="department" required>
                </div>
                
                <div class="form-group">
                    <label for="program_id">Program (for Program Chair filtering):</label>
                    <select id="program_id" name="program_id">
                        <option value="">All Programs</option>
                        <?php foreach ($programs as $program): ?>
                            <option value="<?php echo $program['id']; ?>">
                                <?php echo htmlspecialchars($program['program_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <h3>Preferred Subjects (up to 3, by priority)</h3>
                <div class="form-group">
                    <label for="specialization_1">Primary Subject:</label>
                    <select id="specialization_1" name="specialization_1">
                        <option value="">Select Subject</option>
                        <?php foreach ($subjects_for_assignment as $subject): ?>
                            <option value="<?php echo htmlspecialchars($subject['subject_code']); ?>">
                                <?php echo htmlspecialchars($subject['subject_code'] . ' - ' . $subject['subject_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="specialization_2">Secondary Subject:</label>
                    <select id="specialization_2" name="specialization_2">
                        <option value="">Select Subject</option>
                        <?php foreach ($subjects_for_assignment as $subject): ?>
                            <option value="<?php echo htmlspecialchars($subject['subject_code']); ?>">
                                <?php echo htmlspecialchars($subject['subject_code'] . ' - ' . $subject['subject_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="specialization_3">Tertiary Subject:</label>
                    <select id="specialization_3" name="specialization_3">
                        <option value="">Select Subject</option>
                        <?php foreach ($subjects_for_assignment as $subject): ?>
                            <option value="<?php echo htmlspecialchars($subject['subject_code']); ?>">
                                <?php echo htmlspecialchars($subject['subject_code'] . ' - ' . $subject['subject_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="max_hours_per_week">Max Hours per Week:</label>
                    <input type="number" id="max_hours_per_week" name="max_hours_per_week" min="1" max="40" value="20" required>
                </div>
                
                <button type="submit" name="add_instructor" class="btn-primary">Add Instructor</button>
            </form>
        </div>
    </div>
    
    <!-- Edit Instructor Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('editModal')">&times;</span>
            <h2>Edit Instructor</h2>
            <form method="POST" id="editForm">
                <input type="hidden" id="edit_id" name="instructor_id">
                
                <div class="form-group">
                    <label for="edit_email">Email:</label>
                    <input type="email" id="edit_email" name="email" required>
                </div>
                
                <div class="form-group">
                    <label for="edit_full_name">Full Name:</label>
                    <input type="text" id="edit_full_name" name="full_name" required>
                </div>
                
                <div class="form-group">
                    <label for="edit_department">Department:</label>
                    <input type="text" id="edit_department" name="department" required>
                </div>
                
                <div class="form-group">
                    <label for="edit_program_id">Program (for Program Chair filtering):</label>
                    <select id="edit_program_id" name="program_id">
                        <option value="">All Programs</option>
                        <?php foreach ($programs as $program): ?>
                            <option value="<?php echo $program['id']; ?>">
                                <?php echo htmlspecialchars($program['program_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <h3>Preferred Subjects (up to 3, by priority)</h3>
                <div class="form-group">
                    <label for="edit_specialization_1">Primary Subject:</label>
                    <select id="edit_specialization_1" name="specialization_1">
                        <option value="">Select Subject</option>
                        <?php foreach ($subjects_for_assignment as $subject): ?>
                            <option value="<?php echo htmlspecialchars($subject['subject_code']); ?>">
                                <?php echo htmlspecialchars($subject['subject_code'] . ' - ' . $subject['subject_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="edit_specialization_2">Secondary Subject:</label>
                    <select id="edit_specialization_2" name="specialization_2">
                        <option value="">Select Subject</option>
                        <?php foreach ($subjects_for_assignment as $subject): ?>
                            <option value="<?php echo htmlspecialchars($subject['subject_code']); ?>">
                                <?php echo htmlspecialchars($subject['subject_code'] . ' - ' . $subject['subject_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="edit_specialization_3">Tertiary Subject:</label>
                    <select id="edit_specialization_3" name="specialization_3">
                        <option value="">Select Subject</option>
                        <?php foreach ($subjects_for_assignment as $subject): ?>
                            <option value="<?php echo htmlspecialchars($subject['subject_code']); ?>">
                                <?php echo htmlspecialchars($subject['subject_code'] . ' - ' . $subject['subject_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="edit_max_hours">Max Hours per Week:</label>
                    <input type="number" id="edit_max_hours" name="max_hours_per_week" min="1" max="40" required>
                </div>
                
                <button type="submit" name="edit_instructor" class="btn-primary">Update Instructor</button>
            </form>
        </div>
    </div>
    
    <!-- Availability Modal -->
    <div id="availabilityModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('availabilityModal')">&times;</span>
            <h2>Set Availability for <span id="availability_instructor_name"></span></h2>
            <form method="POST" id="availabilityForm">
                <input type="hidden" id="availability_instructor_id" name="instructor_id">
                
                <div class="availability-grid">
                    <?php foreach ($time_slots as $slot): ?>
                    <label class="availability-item">
                        <input type="checkbox" name="time_slots[]" value="<?php echo $slot['id']; ?>" id="slot_<?php echo $slot['id']; ?>">
                        <?php echo $slot['day']; ?>: 
                        <?php echo date('g:i A', strtotime($slot['start_time'])); ?> - 
                        <?php echo date('g:i A', strtotime($slot['end_time'])); ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                
                <div class="form-actions">
                    <button type="submit" name="set_availability" class="btn-primary">Save Availability</button>
                    <button type="button" onclick="selectAllAvailability()" class="btn-secondary">Select All</button>
                    <button type="button" onclick="deselectAllAvailability()" class="btn-secondary">Deselect All</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Program data for JavaScript
        const programs = <?php echo json_encode(array_map(function($p) { return ['id' => $p['id'], 'name' => $p['program_name']]; }, $programs)); ?>;
        
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        function editInstructor(id) {
            // Fetch instructor data via AJAX
            fetch(`get_instructor.php?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('edit_id').value = data.id;
                    document.getElementById('edit_email').value = data.email;
                    document.getElementById('edit_full_name').value = data.full_name;
                    document.getElementById('edit_department').value = data.department;
                    document.getElementById('edit_specialization_1').value = data.specializations?.[0] || '';
                    document.getElementById('edit_specialization_2').value = data.specializations?.[1] || '';
                    document.getElementById('edit_specialization_3').value = data.specializations?.[2] || '';
                    document.getElementById('edit_max_hours').value = data.max_hours_per_week;
                    
                    // Set program dropdown
                    const programSelect = document.getElementById('edit_program_id');
                    programSelect.value = data.program_id || '';
                    
                    openModal('editModal');
                });
        }
        
        function setAvailability(id, name) {
            document.getElementById('availability_instructor_id').value = id;
            document.getElementById('availability_instructor_name').textContent = name;
            
            // Fetch current availability
            fetch(`get_availability.php?instructor_id=${id}`)
                .then(response => response.json())
                .then(data => {
                    // Uncheck all checkboxes first
                    document.querySelectorAll('input[name="time_slots[]"]').forEach(cb => cb.checked = false);
                    
                    // Check the available slots
                    data.forEach(slotId => {
                        const checkbox = document.getElementById(`slot_${slotId}`);
                        if (checkbox) checkbox.checked = true;
                    });
                    
                    openModal('availabilityModal');
                });
        }
        
        function selectAllAvailability() {
            document.querySelectorAll('input[name="time_slots[]"]').forEach(cb => cb.checked = true);
        }
        
        function deselectAllAvailability() {
            document.querySelectorAll('input[name="time_slots[]"]').forEach(cb => cb.checked = false);
        }
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }

        // Live table search
        const instructorSearchInput = document.getElementById('instructor_search');
        const clearInstructorSearchBtn = document.getElementById('clear_instructor_search');
        if (instructorSearchInput) {
            const instructorRows = document.querySelectorAll('.data-table tbody tr');

            const filterInstructorRows = () => {
                const query = instructorSearchInput.value.trim().toLowerCase();
                instructorRows.forEach(row => {
                    const text = row.getAttribute('data-search-text') || '';
                    row.style.display = text.includes(query) ? '' : 'none';
                });
                if (clearInstructorSearchBtn) {
                    clearInstructorSearchBtn.disabled = query.length === 0;
                }
            };

            instructorSearchInput.addEventListener('input', filterInstructorRows);
            filterInstructorRows();

            if (clearInstructorSearchBtn) {
                clearInstructorSearchBtn.addEventListener('click', function () {
                    instructorSearchInput.value = '';
                    filterInstructorRows();
                    instructorSearchInput.focus();
                });
            }
        }
    </script>
</body>
</html>
