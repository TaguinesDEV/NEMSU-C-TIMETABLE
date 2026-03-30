<?php
require_once '../includes/auth.php';
requireAdmin();

$pdo = getDB();
$message = '';
$error = '';
$non_blocking_warnings = [];

$instructor_columns = [];
foreach ($pdo->query("SHOW COLUMNS FROM instructors")->fetchAll(PDO::FETCH_ASSOC) as $column) {
    $instructor_columns[$column['Field']] = true;
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS subject_instructor_assignments (
            subject_id INT NOT NULL,
            assignment_slot TINYINT NOT NULL DEFAULT 1,
            instructor_id INT NOT NULL,
            assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (subject_id, assignment_slot),
            UNIQUE KEY uq_subject_instructor_assignment_pair (subject_id, instructor_id),
            CONSTRAINT fk_subject_instructor_assignment_subject
                FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
            CONSTRAINT fk_subject_instructor_assignment_instructor
                FOREIGN KEY (instructor_id) REFERENCES instructors(id) ON DELETE CASCADE
        ) ENGINE=InnoDB
    ");
} catch (Exception $e) {
    // Keep page usable even if auto-migration is not allowed.
}

try {
    $assignmentColumns = [];
    foreach ($pdo->query("SHOW COLUMNS FROM subject_instructor_assignments")->fetchAll(PDO::FETCH_ASSOC) as $col) {
        $assignmentColumns[$col['Field']] = $col;
    }
    if (!isset($assignmentColumns['assignment_slot'])) {
        $pdo->exec("ALTER TABLE subject_instructor_assignments ADD COLUMN assignment_slot TINYINT NOT NULL DEFAULT 1 AFTER subject_id");
    }
    try {
        $pdo->exec("ALTER TABLE subject_instructor_assignments DROP PRIMARY KEY, ADD PRIMARY KEY (subject_id, assignment_slot)");
    } catch (Exception $e) {
        // Primary key already updated or cannot be altered in this environment.
    }
    try {
        $pdo->exec("ALTER TABLE subject_instructor_assignments ADD UNIQUE KEY uq_subject_instructor_assignment_pair (subject_id, instructor_id)");
    } catch (Exception $e) {
        // Unique key already exists.
    }
} catch (Exception $e) {
    // Keep page usable even if migration cannot run here.
}

function handleInstructorPhotoUpload(array $availableColumns, $fieldName = 'photo', $existingPath = null) {
    if (empty($_FILES[$fieldName]) || !is_array($_FILES[$fieldName]) || (int)($_FILES[$fieldName]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [$existingPath, null];
    }

    if (!isset($availableColumns['photo'])) {
        return [$existingPath, 'Profile photo upload was skipped because the `photo` column is not available in the database yet.'];
    }

    $upload = $_FILES[$fieldName];
    if ((int)($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Profile photo upload failed. Please try again.');
    }

    if ((int)($upload['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new RuntimeException('Profile photo must be 2MB or smaller.');
    }

    $extension = strtolower(pathinfo((string)($upload['name'] ?? ''), PATHINFO_EXTENSION));
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($extension, $allowed_extensions, true)) {
        throw new RuntimeException('Profile photo must be a JPG, PNG, GIF, or WEBP image.');
    }

    $targetDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'instructor_photos';
    if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
        throw new RuntimeException('Unable to create the instructor photo directory.');
    }

    $fileName = 'instructor_' . bin2hex(random_bytes(8)) . '.' . $extension;
    $targetPath = $targetDirectory . DIRECTORY_SEPARATOR . $fileName;
    if (!move_uploaded_file($upload['tmp_name'], $targetPath)) {
        throw new RuntimeException('Unable to save the uploaded profile photo.');
    }

    if (!empty($existingPath) && strpos((string)$existingPath, '../assets/instructor_photos/') === 0) {
        $existingAbsolutePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['../', '/'], ['', DIRECTORY_SEPARATOR], $existingPath);
        if (is_file($existingAbsolutePath)) {
            @unlink($existingAbsolutePath);
        }
    }

    return ['../assets/instructor_photos/' . $fileName, null];
}

function buildInstructorWriteData(array $availableColumns, array $baseData, array $optionalData = []) {
    $data = $baseData;
    foreach ($optionalData as $column => $value) {
        if (isset($availableColumns[$column])) {
            $data[$column] = $value;
        }
    }
    return $data;
}

// Fetch programs early so subject assignments can be matched to instructor program
$programs = $pdo->query("SELECT * FROM programs ORDER BY program_name")->fetchAll(PDO::FETCH_ASSOC);
$programCodeById = [];
foreach ($programs as $programRow) {
    $programCodeById[(int)$programRow['id']] = strtoupper(trim((string)($programRow['program_code'] ?? '')));
}

function normalizeInstructorProgramCodeFromProgramId($programId, array $programCodeById): string {
    $code = strtoupper(trim((string)($programCodeById[(int)$programId] ?? '')));
    if ($code === 'BSCS') {
        return 'CS';
    }
    if ($code === 'BSIT') {
        return 'IT';
    }
    if ($code === 'BSCPE') {
        return 'CPE';
    }
    return '';
}

function resolveSubjectProgramCodeForInstructorPage(array $subjectRow, array $programCodeById): string {
    $department = strtoupper(trim((string)($subjectRow['department'] ?? '')));
    if ($department === 'COMPUTER SCIENCE' || $department === 'BS COMPUTER SCIENCE') {
        return 'CS';
    }
    if ($department === 'INFORMATION TECHNOLOGY' || $department === 'BS INFORMATION TECHNOLOGY') {
        return 'IT';
    }
    if ($department === 'COMPUTER ENGINEERING' || $department === 'BS COMPUTER ENGINEERING') {
        return 'CPE';
    }
    return normalizeInstructorProgramCodeFromProgramId((int)($subjectRow['program_id'] ?? 0), $programCodeById);
}

// Fetch subjects for instructor subject-priority assignment (used for UI + validation)
$subjects_for_assignment = $pdo->query("
    SELECT s.id, s.subject_code, s.subject_name, s.department, s.program_id
    FROM subjects s
    ORDER BY s.subject_code
")->fetchAll(PDO::FETCH_ASSOC);

$valid_subject_code_map = [];
$subject_id_by_code = [];
$subject_program_code_by_code = [];
foreach ($subjects_for_assignment as $subject_row) {
    $code = strtoupper(trim((string)($subject_row['subject_code'] ?? '')));
    if ($code !== '') {
        // Keep canonical subject code as stored in DB
        $valid_subject_code_map[$code] = (string)$subject_row['subject_code'];
        $subject_id_by_code[$code] = (int)($subject_row['id'] ?? 0);
        $subject_program_code_by_code[$code] = resolveSubjectProgramCodeForInstructorPage($subject_row, $programCodeById);
    }
}

function normalizeDeloadText($value) {
    return trim((string)($value ?? ''));
}

function normalizeDeloadUnits($value) {
    if ($value === null || $value === '') {
        return 0.00;
    }
    $units = (float)$value;
    if ($units < 0) {
        $units = 0;
    }
    return round($units, 2);
}

function normalizeResearchExtensionType($value) {
    $normalized = strtolower(trim((string)($value ?? '')));
    $allowed = ['research', 'extension', 'both'];
    return in_array($normalized, $allowed, true) ? $normalized : '';
}

function formatResearchExtensionType($value) {
    $normalized = normalizeResearchExtensionType($value);
    if ($normalized === 'both') {
        return 'Research/Extension';
    }
    return $normalized ? ucfirst($normalized) : '-';
}

function normalizeInstructorStatus($value) {
    $normalized = strtolower(trim((string)($value ?? '')));
    if ($normalized === 'permanent') {
        return 'Permanent';
    }
    // Accept common typo but store canonical value.
    if ($normalized === 'contractual' || $normalized === 'contructual') {
        return 'Contractual';
    }
    if ($normalized === 'temporary') {
        return 'Temporary';
    }
    return '';
}

function normalizeSpecializationSelection(array $rawValues, array $validCodeMap) {
    $result = [];
    foreach ($rawValues as $raw) {
        $code = strtoupper(trim((string)$raw));
        if ($code === '') {
            continue;
        }
        if (!isset($validCodeMap[$code])) {
            continue;
        }
        $canonical = $validCodeMap[$code];
        if (!in_array($canonical, $result, true)) {
            $result[] = $canonical;
        }
    }
    return $result;
}

function filterSpecializationsByInstructorProgram(array $subjectCodes, string $requiredProgramCode, array $subjectProgramCodeByCode): array {
    if ($requiredProgramCode === '') {
        return $subjectCodes;
    }

    $filtered = [];
    foreach ($subjectCodes as $subjectCode) {
        $lookupKey = strtoupper(trim((string)$subjectCode));
        $subjectProgramCode = strtoupper(trim((string)($subjectProgramCodeByCode[$lookupKey] ?? '')));
        if ($subjectProgramCode === $requiredProgramCode) {
            $filtered[] = $subjectCode;
        }
    }
    return $filtered;
}

function syncInstructorSubjectAssignments(PDO $pdo, int $instructorId, array $subjectCodes, array $subjectIdByCode): void {
    $subjectIds = [];
    foreach ($subjectCodes as $subjectCode) {
        $lookupKey = strtoupper(trim((string)$subjectCode));
        $subjectId = (int)($subjectIdByCode[$lookupKey] ?? 0);
        if ($subjectId > 0) {
            $subjectIds[] = $subjectId;
        }
    }
    $subjectIds = array_values(array_unique($subjectIds));

    $delete = $pdo->prepare("DELETE FROM subject_instructor_assignments WHERE instructor_id = ?");
    $delete->execute([$instructorId]);

    if (!$subjectIds) {
        return;
    }

    $slotStmt = $pdo->prepare("
        SELECT assignment_slot
        FROM subject_instructor_assignments
        WHERE subject_id = ?
        ORDER BY assignment_slot
    ");
    $insertStmt = $pdo->prepare("
        INSERT INTO subject_instructor_assignments (subject_id, assignment_slot, instructor_id)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE instructor_id = VALUES(instructor_id)
    ");
    foreach ($subjectIds as $subjectId) {
        $slotStmt->execute([$subjectId]);
        $usedSlots = array_map('intval', $slotStmt->fetchAll(PDO::FETCH_COLUMN));
        $nextSlot = null;
        for ($slot = 1; $slot <= 4; $slot++) {
            if (!in_array($slot, $usedSlots, true)) {
                $nextSlot = $slot;
                break;
            }
        }
        if ($nextSlot === null) {
            continue;
        }
        $insertStmt->execute([$subjectId, $nextSlot, $instructorId]);
    }
}

// Handle Add/Edit/Delete operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_instructor'])) {
        // Add new instructor
        $username = $_POST['username'];
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $email = $_POST['email'];
        $full_name = $_POST['full_name'];
        $department = $_POST['department'];
        $status = normalizeInstructorStatus($_POST['status'] ?? '');
        $specializations = normalizeSpecializationSelection([
            $_POST['specialization_1'] ?? '',
            $_POST['specialization_2'] ?? '',
            $_POST['specialization_3'] ?? '',
            $_POST['specialization_4'] ?? '',
            $_POST['specialization_5'] ?? ''
        ], $valid_subject_code_map);
        $max_hours = $_POST['max_hours_per_week'];
        $program_id = !empty($_POST['program_id']) ? $_POST['program_id'] : null;
        $required_program_code = normalizeInstructorProgramCodeFromProgramId((int)$program_id, $programCodeById);
        $specializations = filterSpecializationsByInstructorProgram($specializations, $required_program_code, $subject_program_code_by_code);
        $designation = normalizeDeloadText($_POST['designation'] ?? '');
        $designation_units = normalizeDeloadUnits($_POST['designation_units'] ?? 0);
        $research_extension = normalizeResearchExtensionType($_POST['research_extension'] ?? '');
        $research_extension_units = normalizeDeloadUnits($_POST['research_extension_units'] ?? 0);
        $special_assignment = normalizeDeloadText($_POST['special_assignment'] ?? '');
        $special_assignment_units = normalizeDeloadUnits($_POST['special_assignment_units'] ?? 0);
        $rank = trim($_POST['rank'] ?? '');
        $education = trim($_POST['education'] ?? '');
        $eligibility = trim($_POST['eligibility'] ?? '');
        $service_years = trim($_POST['service_years'] ?? '');
        
        try {
            [$photo_path, $photo_warning] = handleInstructorPhotoUpload($instructor_columns, 'photo');
            if ($photo_warning) {
                $non_blocking_warnings[] = $photo_warning;
            }

            // Start transaction
            $pdo->beginTransaction();
            
            // Insert into users table
            $stmt = $pdo->prepare("INSERT INTO users (username, password, email, role, full_name) VALUES (?, ?, ?, 'instructor', ?)");
            $stmt->execute([$username, $password, $email, $full_name]);
            $user_id = $pdo->lastInsertId();
            
            $instructor_data = buildInstructorWriteData(
                $instructor_columns,
                [
                    'user_id' => $user_id,
                    'department' => $department,
                    'status' => $status,
                    'max_hours_per_week' => $max_hours,
                    'program_id' => $program_id,
                    'designation' => $designation,
                    'designation_units' => $designation_units,
                    'research_extension' => $research_extension,
                    'research_extension_units' => $research_extension_units,
                    'special_assignment' => $special_assignment,
                    'special_assignment_units' => $special_assignment_units
                ],
                [
                    'rank' => $rank,
                    'education' => $education,
                    'eligibility' => $eligibility,
                    'service_years' => $service_years,
                    'photo' => $photo_path
                ]
            );
            $columns = array_keys($instructor_data);
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $stmt = $pdo->prepare("INSERT INTO instructors (" . implode(', ', $columns) . ") VALUES ($placeholders)");
            $stmt->execute(array_values($instructor_data));

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

            syncInstructorSubjectAssignments($pdo, (int)$instructor_id, $specializations, $subject_id_by_code);
            
            $pdo->commit();
            $message = "Instructor added successfully!";
            if (!empty($non_blocking_warnings)) {
                $message .= ' ' . implode(' ', $non_blocking_warnings);
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Error adding instructor: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['edit_instructor'])) {
        $id = $_POST['instructor_id'];
        $email = $_POST['email'];
        $full_name = $_POST['full_name'];
        $department = $_POST['department'];
        $status = normalizeInstructorStatus($_POST['status'] ?? '');
        $specializations = normalizeSpecializationSelection([
            $_POST['specialization_1'] ?? '',
            $_POST['specialization_2'] ?? '',
            $_POST['specialization_3'] ?? '',
            $_POST['specialization_4'] ?? '',
            $_POST['specialization_5'] ?? ''
        ], $valid_subject_code_map);
        $max_hours = $_POST['max_hours_per_week'];
        $program_id = !empty($_POST['program_id']) ? $_POST['program_id'] : null;
        $required_program_code = normalizeInstructorProgramCodeFromProgramId((int)$program_id, $programCodeById);
        $specializations = filterSpecializationsByInstructorProgram($specializations, $required_program_code, $subject_program_code_by_code);
        $designation = normalizeDeloadText($_POST['designation'] ?? '');
        $designation_units = normalizeDeloadUnits($_POST['designation_units'] ?? 0);
        $research_extension = normalizeResearchExtensionType($_POST['research_extension'] ?? '');
        $research_extension_units = normalizeDeloadUnits($_POST['research_extension_units'] ?? 0);
        $special_assignment = normalizeDeloadText($_POST['special_assignment'] ?? '');
        $special_assignment_units = normalizeDeloadUnits($_POST['special_assignment_units'] ?? 0);
        $rank = trim($_POST['rank'] ?? '');
        $education = trim($_POST['education'] ?? '');
        $eligibility = trim($_POST['eligibility'] ?? '');
        $service_years = trim($_POST['service_years'] ?? '');
        
        try {
            // Start transaction
            $pdo->beginTransaction();
            
            // Get user_id from instructor
            $select_fields = 'user_id';
            if (isset($instructor_columns['photo'])) {
                $select_fields .= ', photo';
            }
            $stmt = $pdo->prepare("SELECT $select_fields FROM instructors WHERE id = ?");
            $stmt->execute([$id]);
            $instructor_row = $stmt->fetch(PDO::FETCH_ASSOC);
            $user_id = $instructor_row['user_id'] ?? null;
            $existing_photo = $instructor_row['photo'] ?? null;

            [$photo_path, $photo_warning] = handleInstructorPhotoUpload($instructor_columns, 'photo', $existing_photo);
            if ($photo_warning) {
                $non_blocking_warnings[] = $photo_warning;
            }
            
            // Update users table
            $stmt = $pdo->prepare("UPDATE users SET email = ?, full_name = ? WHERE id = ?");
            $stmt->execute([$email, $full_name, $user_id]);
            
            $instructor_data = buildInstructorWriteData(
                $instructor_columns,
                [
                    'department' => $department,
                    'status' => $status,
                    'max_hours_per_week' => $max_hours,
                    'program_id' => $program_id,
                    'designation' => $designation,
                    'designation_units' => $designation_units,
                    'research_extension' => $research_extension,
                    'research_extension_units' => $research_extension_units,
                    'special_assignment' => $special_assignment,
                    'special_assignment_units' => $special_assignment_units
                ],
                [
                    'rank' => $rank,
                    'education' => $education,
                    'eligibility' => $eligibility,
                    'service_years' => $service_years,
                    'photo' => $photo_path
                ]
            );
            $assignments = [];
            foreach (array_keys($instructor_data) as $column) {
                $assignments[] = $column . ' = ?';
            }
            $stmt = $pdo->prepare("UPDATE instructors SET " . implode(', ', $assignments) . " WHERE id = ?");
            $stmt->execute(array_merge(array_values($instructor_data), [$id]));

            
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

            syncInstructorSubjectAssignments($pdo, (int)$id, $specializations, $subject_id_by_code);
            
            $pdo->commit();
            $message = "Instructor updated successfully!";
            if (!empty($non_blocking_warnings)) {
                $message .= ' ' . implode(' ', $non_blocking_warnings);
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
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

    if (isset($_POST['set_all_instructors_availability'])) {
        try {
            $pdo->beginTransaction();

            $instructor_count = (int)$pdo->query("SELECT COUNT(*) FROM instructors")->fetchColumn();
            $slot_count = (int)$pdo->query("SELECT COUNT(*) FROM time_slots")->fetchColumn();

            $pdo->exec("DELETE FROM instructor_availability");

            if ($instructor_count > 0 && $slot_count > 0) {
                $pdo->exec("
                    INSERT INTO instructor_availability (instructor_id, time_slot_id, is_available)
                    SELECT i.id, ts.id, 1
                    FROM instructors i
                    CROSS JOIN time_slots ts
                ");
            }

            $pdo->commit();
            $message = "All instructors are now available for all time slots.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Error applying availability to all instructors: " . $e->getMessage();
        }
    }
}

// Fetch all instructors with details and specializations
$instructors = $pdo->query("
    SELECT i.id, i.user_id, i.department, i.status, i.max_hours_per_week, i.program_id, i.designation, i.designation_units, i.research_extension, i.research_extension_units, i.special_assignment, i.special_assignment_units, u.username, u.email, u.full_name 
    FROM instructors i 
    JOIN users u ON i.user_id = u.id 
    ORDER BY u.full_name
")->fetchAll();

// Fetch time slots for availability modal
$time_slots = $pdo->query("
    SELECT *, COALESCE(slot_type, 'regular') AS slot_type
    FROM time_slots
    ORDER BY FIELD(day,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'), start_time
")->fetchAll();

// Function to get specializations for an instructor
function getInstructorSpecializations($pdo, $instructor_id) {
    $assignedStmt = $pdo->prepare("
        SELECT sub.subject_code
        FROM subject_instructor_assignments sia
        JOIN subjects sub ON sia.subject_id = sub.id
        WHERE sia.instructor_id = ?
        ORDER BY sub.subject_code
    ");
    $assignedStmt->execute([$instructor_id]);
    $assignedSubjectCodes = $assignedStmt->fetchAll(PDO::FETCH_COLUMN);

    $stmt = $pdo->prepare("
        SELECT s.specialization_name 
        FROM instructor_specializations ism
        JOIN specializations s ON ism.specialization_id = s.id
        WHERE ism.instructor_id = ?
        ORDER BY ism.priority
    ");
    $stmt->execute([$instructor_id]);
    $specializations = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $merged = [];
    foreach (array_merge($assignedSubjectCodes, $specializations) as $subjectCode) {
        $subjectCode = trim((string)$subjectCode);
        if ($subjectCode !== '' && !in_array($subjectCode, $merged, true)) {
            $merged[] = $subjectCode;
        }
    }
    return array_slice($merged, 0, 5);
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
            flex-direction: column;
            align-items: flex-start;
            gap: 0;
        }

        .action-row {
            display: flex;
            gap: 5px;
        }

        .bottom-row {
            margin-top: 4px;
        }

        .action-buttons form {
            margin: 0;
            display: inline-flex;
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
        .btn-availability { background-color: #17a2b8; color: #fff; }
        .btn-availability:hover { background-color: #138496; }
        .btn-delete {
            background: linear-gradient(135deg, #dc2626, #ef4444);
            border-color: #b91c1c;
            color: #fff;
        }

        .btn-profile {
            background: linear-gradient(135deg, #3b82f6, #60a5fa);
            border-color: #2563eb;
            color: #fff;
        }
        .btn-profile:hover {
            background: linear-gradient(135deg, #2563eb, #3b82f6);
        }


        .search-toolbar {
            margin: 14px 0 18px;
        }

        .search-toolbar label {
            display: block;
            font-weight: 600;
            margin-bottom: 6px;
        }

        .page-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 16px;
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

        <div class="page-actions">
            <button class="btn-primary" onclick="openModal('addModal')">➕ Add New Instructor</button>
            <form method="POST" onsubmit="return confirm('Make every time slot available for every instructor? This will replace all current instructor availability settings.')">
                <button type="submit" name="set_all_instructors_availability" class="btn-secondary">Make All Instructors Fully Available</button>
            </form>
        </div>
        
        <!-- Instructors Table -->
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Department</th>
                    <th>Status</th>
                    <th>Program</th>
                    <th>Subject Assignments</th>
                    <th>Nature of Designation / Units Deloading</th>
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
                    $instructor['status'] . ' ' .
                    (getProgramName($pdo, $instructor['program_id']) ?: 'all programs') . ' ' .
                    $instructor['designation'] . ' ' .
                    $instructor['research_extension'] . ' ' .
                    $instructor['special_assignment'] . ' ' .
                    implode(' ', getInstructorSpecializations($pdo, $instructor['id']))
                )); ?>">
                    <td><?php echo htmlspecialchars($instructor['full_name']); ?></td>
                    <td><?php echo htmlspecialchars($instructor['username']); ?></td>
                    <td><?php echo htmlspecialchars($instructor['email']); ?></td>
                    <td><?php echo htmlspecialchars($instructor['department']); ?></td>
                    <td><?php echo htmlspecialchars($instructor['status'] ?: '-'); ?></td>
                    <td><?php echo htmlspecialchars(getProgramName($pdo, $instructor['program_id']) ?: 'All Programs'); ?></td>
                    <td><?php 
                        $specs = getInstructorSpecializations($pdo, $instructor['id']);
                        echo htmlspecialchars(implode(', ', $specs) ?: '(No subject assignment)'); 
                    ?></td>
                    <td>
                        <?php
                            $designation_units = (float)($instructor['designation_units'] ?? 0);
                            $research_extension_units = (float)($instructor['research_extension_units'] ?? 0);
                            $special_assignment_units = (float)($instructor['special_assignment_units'] ?? 0);
                            $research_extension_label = formatResearchExtensionType($instructor['research_extension'] ?? '');
                            $total_deload = $designation_units + $research_extension_units + $special_assignment_units;
                        ?>
                        <div><strong>Designation:</strong> <?php echo htmlspecialchars($instructor['designation'] ?: '-'); ?> (<?php echo number_format($designation_units, 2); ?>)</div>
                        <div><strong>Research/Extension:</strong> <?php echo htmlspecialchars($research_extension_label); ?> (<?php echo number_format($research_extension_units, 2); ?>)</div>
                        <div><strong>Special Assignment:</strong> <?php echo htmlspecialchars($instructor['special_assignment'] ?: '-'); ?> (<?php echo number_format($special_assignment_units, 2); ?>)</div>
                        <div><strong>Total Deload:</strong> <?php echo number_format($total_deload, 2); ?></div>
                    </td>
                    <td><?php echo $instructor['max_hours_per_week']; ?></td>
                    <td class="action-buttons">
                        <div class="action-row top-row">
                            <a href="view_instructor.php?id=<?php echo $instructor['id']; ?>" class="btn-icon btn-profile" title="View Profile"><i class="fas fa-user"></i> Profile</a>
                            <button class="btn-icon btn-edit" onclick="editInstructor(<?php echo $instructor['id']; ?>)"><i class="fas fa-edit"></i> Edit</button>
                        </div>
                        <div class="action-row bottom-row">
                            <button class="btn-icon btn-availability" onclick="setAvailability(<?php echo $instructor['id']; ?>, '<?php echo htmlspecialchars(addslashes($instructor['full_name'])); ?>')"><i class="fas fa-calendar-alt"></i> Availability</button>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this instructor? This action cannot be undone.')" >
                                <input type="hidden" name="instructor_id" value="<?php echo $instructor['id']; ?>">
                                <button type="submit" name="delete_instructor" class="btn-icon btn-delete"><i class="fas fa-trash-alt"></i> Delete</button>
                            </form>
                        </div>
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
            <datalist id="add_subject_code_options"></datalist>
            <form method="POST" enctype="multipart/form-data">
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
                    <label for="photo">Profile Photo:</label>
                    <input type="file" id="photo" name="photo" accept="image/*">
                    <small>Optional - JPG/PNG, max 2MB</small>
                </div>

                <h3>Profile Information</h3>
                <div class="form-group">
                    <label for="rank">Rank:</label>
                    <input type="text" id="rank" name="rank" placeholder="Instructor / Assistant Professor">
                </div>
                
                <div class="form-group">
                    <label for="education">Educational Background:</label>
                    <textarea id="education" name="education" rows="2" placeholder="e.g., MS Computer Science, University of ..."></textarea>
                </div>
                
                <div class="form-group">
                    <label for="eligibility">Eligibility:</label>
                    <input type="text" id="eligibility" name="eligibility" placeholder="PRC LET / Civil Service">
                </div>
                
                <div class="form-group">
                    <label for="service_years">Length of Service:</label>
                    <input type="text" id="service_years" name="service_years" placeholder="10 years">
                </div>
                
                <div class="form-group">
                    <label for="department">Department:</label>
                    <input type="text" id="department" name="department" required>
                </div>

                <div class="form-group">
                    <label for="status">Status:</label>
                    <select id="status" name="status" required>
                        <option value="">Select Status</option>
                        <option value="Permanent">Permanent</option>
                        <option value="Contractual">Contractual</option>
                        <option value="Temporary">Temporary</option>
                    </select>
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
                
                <h3>Preferred Subjects (up to 5, by priority)</h3>
                <div class="form-group">
                    <label for="specialization_1">Primary Subject:</label>
                    <input type="text" id="specialization_1" name="specialization_1" list="add_subject_code_options" placeholder="Search subject code...">
                </div>
                <div class="form-group">
                    <label for="specialization_2">Secondary Subject:</label>
                    <input type="text" id="specialization_2" name="specialization_2" list="add_subject_code_options" placeholder="Search subject code...">
                </div>
                <div class="form-group">
                    <label for="specialization_3">Tertiary Subject:</label>
                    <input type="text" id="specialization_3" name="specialization_3" list="add_subject_code_options" placeholder="Search subject code...">
                </div>
                <div class="form-group">
                    <label for="specialization_4">4th Subject:</label>
                    <input type="text" id="specialization_4" name="specialization_4" list="add_subject_code_options" placeholder="Search subject code...">
                </div>
                <div class="form-group">
                    <label for="specialization_5">5th Subject (Extra Subject):</label>
                    <input type="text" id="specialization_5" name="specialization_5" list="add_subject_code_options" placeholder="Search subject code...">
                </div>
                
                <div class="form-group">
                    <label for="max_hours_per_week">Max Hours per Week:</label>
                    <input type="number" id="max_hours_per_week" name="max_hours_per_week" min="1" max="40" value="20" required>
                </div>

                <h3>Nature of Designation / Units Deloading</h3>
                <div class="form-group">
                    <label for="designation">Designation:</label>
                    <input type="text" id="designation" name="designation" placeholder="e.g., Program Coordinator, BSCS">
                </div>
                <div class="form-group">
                    <label for="designation_units">Designation Units Deloading:</label>
                    <input type="number" id="designation_units" name="designation_units" min="0" step="0.5" value="0">
                </div>
                <div class="form-group">
                    <label for="research_extension">Research / Extension:</label>
                    <select id="research_extension" name="research_extension">
                        <option value="">Select Type</option>
                        <option value="research">Research</option>
                        <option value="extension">Extension</option>
                        <option value="both">Research/Extension</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="research_extension_units">Research / Extension Units Deloading:</label>
                    <input type="number" id="research_extension_units" name="research_extension_units" min="0" step="0.5" value="0">
                </div>
                <div class="form-group">
                    <label for="special_assignment">Special Assignment:</label>
                    <input type="text" id="special_assignment" name="special_assignment" placeholder="e.g., TBI Coordinator">
                </div>
                <div class="form-group">
                    <label for="special_assignment_units">Special Assignment Units Deloading:</label>
                    <input type="number" id="special_assignment_units" name="special_assignment_units" min="0" step="0.5" value="0">
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
            <datalist id="edit_subject_code_options"></datalist>
            <form method="POST" id="editForm" enctype="multipart/form-data">
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
                    <label for="edit_photo">Profile Photo:</label>
                    <input type="file" id="edit_photo" name="photo" accept="image/*">
                    <small>Optional - JPG/PNG, max 2MB (current photo remains if empty)</small>
                </div>

                <h3>Profile Information</h3>
                <div class="form-group">
                    <label for="edit_rank">Rank:</label>
                    <input type="text" id="edit_rank" name="rank">
                </div>
                
                <div class="form-group">
                    <label for="edit_education">Educational Background:</label>
                    <textarea id="edit_education" name="education" rows="2"></textarea>
                </div>
                
                <div class="form-group">
                    <label for="edit_eligibility">Eligibility:</label>
                    <input type="text" id="edit_eligibility" name="eligibility">
                </div>
                
                <div class="form-group">
                    <label for="edit_service_years">Length of Service:</label>
                    <input type="text" id="edit_service_years" name="service_years">
                </div>
                
                <div class="form-group">
                    <label for="edit_department">Department:</label>
                    <input type="text" id="edit_department" name="department" required>
                </div>

                <div class="form-group">
                    <label for="edit_status">Status:</label>
                    <select id="edit_status" name="status" required>
                        <option value="">Select Status</option>
                        <option value="Permanent">Permanent</option>
                        <option value="Contractual">Contractual</option>
                        <option value="Temporary">Temporary</option>
                    </select>
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
                
                <h3>Preferred Subjects (up to 5, by priority)</h3>
                <div class="form-group">
                    <label for="edit_specialization_1">Primary Subject:</label>
                    <input type="text" id="edit_specialization_1" name="specialization_1" list="edit_subject_code_options" placeholder="Search subject code...">
                </div>
                <div class="form-group">
                    <label for="edit_specialization_2">Secondary Subject:</label>
                    <input type="text" id="edit_specialization_2" name="specialization_2" list="edit_subject_code_options" placeholder="Search subject code...">
                </div>
                <div class="form-group">
                    <label for="edit_specialization_3">Tertiary Subject:</label>
                    <input type="text" id="edit_specialization_3" name="specialization_3" list="edit_subject_code_options" placeholder="Search subject code...">
                </div>
                <div class="form-group">
                    <label for="edit_specialization_4">4th Subject:</label>
                    <input type="text" id="edit_specialization_4" name="specialization_4" list="edit_subject_code_options" placeholder="Search subject code...">
                </div>
                <div class="form-group">
                    <label for="edit_specialization_5">5th Subject (Extra Subject):</label>
                    <input type="text" id="edit_specialization_5" name="specialization_5" list="edit_subject_code_options" placeholder="Search subject code...">
                </div>
                
                <div class="form-group">
                    <label for="edit_max_hours">Max Hours per Week:</label>
                    <input type="number" id="edit_max_hours" name="max_hours_per_week" min="1" max="40" required>
                </div>

                <h3>Nature of Designation / Units Deloading</h3>
                <div class="form-group">
                    <label for="edit_designation">Designation:</label>
                    <input type="text" id="edit_designation" name="designation">
                </div>
                <div class="form-group">
                    <label for="edit_designation_units">Designation Units Deloading:</label>
                    <input type="number" id="edit_designation_units" name="designation_units" min="0" step="0.5">
                </div>
                <div class="form-group">
                    <label for="edit_research_extension">Research / Extension:</label>
                    <select id="edit_research_extension" name="research_extension">
                        <option value="">Select Type</option>
                        <option value="research">Research</option>
                        <option value="extension">Extension</option>
                        <option value="both">Research/Extension</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="edit_research_extension_units">Research / Extension Units Deloading:</label>
                    <input type="number" id="edit_research_extension_units" name="research_extension_units" min="0" step="0.5">
                </div>
                <div class="form-group">
                    <label for="edit_special_assignment">Special Assignment:</label>
                    <input type="text" id="edit_special_assignment" name="special_assignment">
                </div>
                <div class="form-group">
                    <label for="edit_special_assignment_units">Special Assignment Units Deloading:</label>
                    <input type="number" id="edit_special_assignment_units" name="special_assignment_units" min="0" step="0.5">
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
                        <input
                            type="checkbox"
                            name="time_slots[]"
                            value="<?php echo $slot['id']; ?>"
                            id="slot_<?php echo $slot['id']; ?>"
                            data-day="<?php echo htmlspecialchars(strtolower((string)$slot['day'])); ?>"
                            data-slot-type="<?php echo htmlspecialchars(strtolower((string)($slot['slot_type'] ?? 'regular'))); ?>"
                        >
                        <?php echo $slot['day']; ?>: 
                        <?php echo date('g:i A', strtotime($slot['start_time'])); ?> - 
                        <?php echo date('g:i A', strtotime($slot['end_time'])); ?>
                        <?php if (($slot['slot_type'] ?? 'regular') !== 'regular'): ?>
                            (<?php echo htmlspecialchars(ucfirst((string)$slot['slot_type'])); ?>)
                        <?php endif; ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                
                <div class="form-actions">
                    <button type="submit" name="set_availability" class="btn-primary">Save Availability</button>
                    <button type="button" onclick="applyWeekdayDefaultAvailability()" class="btn-secondary">Weekdays Default</button>
                    <button type="button" onclick="enableSaturdayTypeAvailability(['makeup'])" class="btn-secondary">Saturday Makeup</button>
                    <button type="button" onclick="enableSaturdayTypeAvailability(['summer'])" class="btn-secondary">Saturday Summer</button>
                    <button type="button" onclick="selectAllAvailability()" class="btn-secondary">Select All</button>
                    <button type="button" onclick="deselectAllAvailability()" class="btn-secondary">Deselect All</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Program data for JavaScript
        const programs = <?php echo json_encode(array_map(function($p) { return ['id' => $p['id'], 'name' => $p['program_name']]; }, $programs)); ?>;
        const subjectAssignments = <?php echo json_encode(array_map(function($subject) use ($subject_program_code_by_code) {
            $lookupCode = strtoupper(trim((string)($subject['subject_code'] ?? '')));
            return [
                'code' => (string)$subject['subject_code'],
                'name' => (string)$subject['subject_name'],
                'program_code' => (string)($subject_program_code_by_code[$lookupCode] ?? ''),
            ];
        }, $subjects_for_assignment)); ?>;

        function normalizeInstructorProgramCode(programId) {
            const id = String(programId || '');
            const matched = programs.find(program => String(program.id) === id);
            if (!matched) {
                return '';
            }
            const name = String(matched.name || '').toLowerCase();
            if (name.includes('computer science')) {
                return 'CS';
            }
            if (name.includes('information technology')) {
                return 'IT';
            }
            if (name.includes('computer engineering')) {
                return 'CPE';
            }
            return '';
        }

        function populateSubjectOptions(datalistId, programSelectId) {
            const datalist = document.getElementById(datalistId);
            const programSelect = document.getElementById(programSelectId);
            if (!datalist || !programSelect) {
                return;
            }

            const requiredProgramCode = normalizeInstructorProgramCode(programSelect.value);
            const matchingSubjects = subjectAssignments.filter(subject => {
                return requiredProgramCode === '' || subject.program_code === requiredProgramCode;
            });

            datalist.innerHTML = '';
            matchingSubjects.forEach(subject => {
                const option = document.createElement('option');
                option.value = subject.code;
                option.label = `${subject.code} - ${subject.name}`;
                datalist.appendChild(option);
            });
        }
        
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
                    document.getElementById('edit_status').value = data.status || '';
                    document.getElementById('edit_specialization_1').value = data.specializations?.[0] || '';
                    document.getElementById('edit_specialization_2').value = data.specializations?.[1] || '';
                    document.getElementById('edit_specialization_3').value = data.specializations?.[2] || '';
                    document.getElementById('edit_specialization_4').value = data.specializations?.[3] || '';
                    document.getElementById('edit_specialization_5').value = data.specializations?.[4] || '';
                    document.getElementById('edit_max_hours').value = data.max_hours_per_week;
                    document.getElementById('edit_designation').value = data.designation || '';
                    document.getElementById('edit_designation_units').value = data.designation_units || 0;
                    document.getElementById('edit_research_extension').value = data.research_extension || '';
                    document.getElementById('edit_research_extension_units').value = data.research_extension_units || 0;
                    document.getElementById('edit_special_assignment').value = data.special_assignment || '';
                    document.getElementById('edit_special_assignment_units').value = data.special_assignment_units || 0;
                    document.getElementById('edit_rank').value = data.rank || '';
                    document.getElementById('edit_education').value = data.education || '';
                    document.getElementById('edit_eligibility').value = data.eligibility || '';
                    document.getElementById('edit_service_years').value = data.service_years || '';
                    
                    // Set program dropdown
                    const programSelect = document.getElementById('edit_program_id');
                    programSelect.value = data.program_id || '';
                    populateSubjectOptions('edit_subject_code_options', 'edit_program_id');
                    
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
                    
                    // If no saved availability yet, default to Monday-Friday only.
                    if (!Array.isArray(data) || data.length === 0) {
                        applyWeekdayDefaultAvailability();
                    } else {
                        // Check the available slots
                        data.forEach(slotId => {
                            const checkbox = document.getElementById(`slot_${slotId}`);
                            if (checkbox) checkbox.checked = true;
                        });
                    }
                    
                    openModal('availabilityModal');
                });
        }

        function getAvailabilityCheckboxes() {
            return Array.from(document.querySelectorAll('input[name="time_slots[]"]'));
        }

        function applyWeekdayDefaultAvailability() {
            getAvailabilityCheckboxes().forEach(cb => {
                const day = (cb.dataset.day || '').toLowerCase();
                cb.checked = day !== 'saturday';
            });
        }

        function enableSaturdayTypeAvailability(slotTypes) {
            const allowed = new Set((slotTypes || []).map(t => String(t).toLowerCase()));
            getAvailabilityCheckboxes().forEach(cb => {
                const day = (cb.dataset.day || '').toLowerCase();
                const slotType = (cb.dataset.slotType || '').toLowerCase();
                if (day === 'saturday') {
                    cb.checked = allowed.has(slotType);
                } else {
                    cb.checked = true;
                }
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
        const addProgramSelect = document.getElementById('program_id');
        if (addProgramSelect) {
            populateSubjectOptions('add_subject_code_options', 'program_id');
            addProgramSelect.addEventListener('change', function () {
                populateSubjectOptions('add_subject_code_options', 'program_id');
            });
        }

        const editProgramSelect = document.getElementById('edit_program_id');
        if (editProgramSelect) {
            populateSubjectOptions('edit_subject_code_options', 'edit_program_id');
            editProgramSelect.addEventListener('change', function () {
                populateSubjectOptions('edit_subject_code_options', 'edit_program_id');
            });
        }

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
