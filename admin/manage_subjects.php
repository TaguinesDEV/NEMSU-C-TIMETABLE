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
    if (!isset($subjectColumns['meetings_per_week'])) {
        $pdo->exec("ALTER TABLE subjects ADD COLUMN meetings_per_week TINYINT NOT NULL DEFAULT 2 AFTER lab_hours");
    }
    if (!isset($subjectColumns['lecture_minutes_per_meeting'])) {
        $pdo->exec("ALTER TABLE subjects ADD COLUMN lecture_minutes_per_meeting INT NOT NULL DEFAULT 0 AFTER meetings_per_week");
    }
    if (!isset($subjectColumns['lab_minutes_per_meeting'])) {
        $pdo->exec("ALTER TABLE subjects ADD COLUMN lab_minutes_per_meeting INT NOT NULL DEFAULT 0 AFTER lecture_minutes_per_meeting");
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
        SET meetings_per_week = CASE
                WHEN COALESCE(meetings_per_week, 0) <= 0 THEN
                    CASE WHEN LOWER(COALESCE(semester, '')) = 'summer' THEN 1 ELSE 2 END
                ELSE meetings_per_week
            END,
            lecture_minutes_per_meeting = CASE
                WHEN COALESCE(lecture_minutes_per_meeting, 0) > 0 THEN lecture_minutes_per_meeting
                WHEN COALESCE(lab_minutes_per_meeting, 0) > 0 AND COALESCE(lecture_hours, 0) > 0 THEN ROUND((COALESCE(lecture_hours, 0) * 60) / GREATEST(COALESCE(meetings_per_week, 2), 1))
                WHEN COALESCE(lecture_hours, 0) > 0 THEN ROUND((COALESCE(lecture_hours, 0) * 60) / GREATEST(COALESCE(meetings_per_week, 2), 1))
                WHEN COALESCE(hours_per_week, 0) > 0 AND COALESCE(lab_hours, 0) <= 0 THEN ROUND((COALESCE(hours_per_week, 0) * 60) / GREATEST(COALESCE(meetings_per_week, 2), 1))
                ELSE lecture_minutes_per_meeting
            END,
            lab_minutes_per_meeting = CASE
                WHEN COALESCE(lab_minutes_per_meeting, 0) > 0 THEN lab_minutes_per_meeting
                WHEN COALESCE(lecture_hours, 0) = 2.00 AND COALESCE(lab_hours, 0) = 3.00 THEN 145
                WHEN COALESCE(lab_hours, 0) > 0 THEN ROUND((COALESCE(lab_hours, 0) * 60) / GREATEST(COALESCE(meetings_per_week, 2), 1))
                ELSE lab_minutes_per_meeting
            END
    ");
} catch (Exception $e) {
    // Keep the page usable even if legacy minute conversion cannot run here.
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
    // Continue page load even if auto-migration is not allowed in this environment.
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
    // Keep the page usable even if assignment table adjustments cannot run here.
}

function defaultHoursFromSubjectType($type) {
    return strtolower((string)$type) === 'minor' ? 3.00 : 4.00;
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

function normalizeMinutes($rawMinutes) {
    $minutes = (int)($rawMinutes ?? 0);
    if ($minutes < 0) {
        $minutes = 0;
    }
    return $minutes;
}

function normalizeMeetingsPerWeek($rawMeetings) {
    $meetings = (int)($rawMeetings ?? 2);
    if ($meetings < 1) {
        return 1;
    }
    if ($meetings > 7) {
        return 7;
    }
    return $meetings;
}

function minutesToDecimalHours($minutes) {
    return round(((int)$minutes) / 60, 2);
}

function formatMinutesAsClock($minutes) {
    $minutes = max(0, (int)$minutes);
    $hours = intdiv($minutes, 60);
    $mins = $minutes % 60;
    return sprintf('%d:%02d', $hours, $mins);
}

function computeSubjectTimeValues($subjectType, $meetingsPerWeek, $lectureMinutesPerMeeting, $labMinutesPerMeeting, $fallbackHoursPerWeek = null) {
    $subjectType = strtolower((string)$subjectType);
    $meetingsPerWeek = normalizeMeetingsPerWeek($meetingsPerWeek);
    $lectureMinutesPerMeeting = normalizeMinutes($lectureMinutesPerMeeting);
    $labMinutesPerMeeting = normalizeMinutes($labMinutesPerMeeting);

    if ($subjectType === 'minor') {
        if ($lectureMinutesPerMeeting <= 0) {
            $lectureMinutesPerMeeting = 90;
        }
        $labMinutesPerMeeting = 0;
    } elseif ($lectureMinutesPerMeeting <= 0 && $labMinutesPerMeeting <= 0) {
        $lectureMinutesPerMeeting = 120;
    }

    $weeklyLectureMinutes = $lectureMinutesPerMeeting * $meetingsPerWeek;
    $weeklyLabMinutes = $labMinutesPerMeeting * $meetingsPerWeek;
    $weeklyTotalMinutes = $weeklyLectureMinutes + $weeklyLabMinutes;

    if ($weeklyTotalMinutes <= 0 && $fallbackHoursPerWeek !== null && $fallbackHoursPerWeek !== '') {
        $weeklyTotalMinutes = max(0, (int)round(((float)$fallbackHoursPerWeek) * 60));
        $weeklyLectureMinutes = $weeklyTotalMinutes;
        $lectureMinutesPerMeeting = (int)round($weeklyLectureMinutes / max($meetingsPerWeek, 1));
        $weeklyLabMinutes = 0;
        $labMinutesPerMeeting = 0;
    }

    return [
        'meetings_per_week' => $meetingsPerWeek,
        'lecture_minutes_per_meeting' => $lectureMinutesPerMeeting,
        'lab_minutes_per_meeting' => $labMinutesPerMeeting,
        'hours_per_week' => minutesToDecimalHours($weeklyTotalMinutes),
        'lecture_hours' => minutesToDecimalHours($weeklyLectureMinutes),
        'lab_hours' => minutesToDecimalHours($weeklyLabMinutes),
        'weekly_total_minutes' => $weeklyTotalMinutes,
    ];
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

function normalizeProgramLabel($value) {
    return strtolower((string)preg_replace('/[^A-Za-z0-9]+/', '', (string)$value));
}

function resolveProgramCodeFromValue($value) {
    $normalized = normalizeProgramLabel($value);
    $map = [
        'cs' => 'CS',
        'computerscience' => 'CS',
        'bscomputerscience' => 'CS',
        'bachelorofscienceincomputerscience' => 'CS',
        'it' => 'IT',
        'informationtechnology' => 'IT',
        'bsinformationtechnology' => 'IT',
        'bachelorofscienceininformationtechnology' => 'IT',
        'cpe' => 'CPE',
        'computerengineering' => 'CPE',
        'bscomputerengineering' => 'CPE',
        'bachelorofscienceincomputerengineering' => 'CPE',
        'bscpe' => 'CPE',
        'bscpe' => 'CPE',
    ];
    return $map[$normalized] ?? 'OTHER';
}

function resolveSubjectProgramCode($subject) {
    $programSignals = [
        $subject['department'] ?? '',
        $subject['linked_program_name'] ?? '',
        $subject['program_display_name'] ?? '',
    ];
    foreach ($programSignals as $signal) {
        $code = resolveProgramCodeFromValue($signal);
        if ($code !== 'OTHER') {
            return $code;
        }
    }
    return resolveProgramCodeFromValue($subject['subject_code'] ?? '');
}

function getProgramDisplayNameFromCode($value) {
    $normalized = strtoupper(trim((string)$value));
    $map = [
        'CS' => 'Computer Science',
        'IT' => 'Information Technology',
        'CPE' => 'Computer Engineering',
    ];
    return $map[$normalized] ?? trim((string)$value);
}

function normalizeInstructorAssignments(array $rawInstructorValues, array $instructorNameById, array $instructorIdByName): array {
    $normalized = [];
    foreach ($rawInstructorValues as $rawInstructorValue) {
        $rawInstructorValue = trim((string)$rawInstructorValue);
        if ($rawInstructorValue === '') {
            continue;
        }
        $instructorId = ctype_digit($rawInstructorValue)
            ? (int)$rawInstructorValue
            : (int)($instructorIdByName[strtoupper($rawInstructorValue)] ?? 0);
        if ($instructorId <= 0 || !isset($instructorNameById[$instructorId])) {
            continue;
        }
        if (!in_array($instructorId, $normalized, true)) {
            $normalized[] = $instructorId;
        }
        if (count($normalized) >= 4) {
            break;
        }
    }
    return $normalized;
}

// Fetch departments for dropdown
$departments = $pdo->query("SELECT * FROM departments ORDER BY dept_name")->fetchAll();
$programs = $pdo->query("SELECT id, program_name, program_code FROM programs ORDER BY program_name")->fetchAll(PDO::FETCH_ASSOC);
$programNameById = [];
foreach ($programs as $programRow) {
    $programNameById[(int)$programRow['id']] = (string)$programRow['program_name'];
}
$instructors = $pdo->query("
    SELECT i.id, u.full_name
    FROM instructors i
    JOIN users u ON i.user_id = u.id
    ORDER BY u.full_name
")->fetchAll(PDO::FETCH_ASSOC);
$instructorNameById = [];
$instructorIdByName = [];
foreach ($instructors as $instructorRow) {
    $instructorId = (int)$instructorRow['id'];
    $instructorName = (string)$instructorRow['full_name'];
    $instructorNameById[$instructorId] = $instructorName;
    $instructorIdByName[strtoupper(trim($instructorName))] = $instructorId;
}
$quickProgramTargets = [
    'Computer Science(CS)' => [
        'code' => 'CS',
        'aliases' => ['cs', 'computerscience', 'bscomputerscience', 'bscs'],
        'display_html' => 'Computer Science (CS)',
    ],
    'Information Technology (IT)' => [
        'code' => 'IT',
        'aliases' => ['it', 'informationtechnology', 'bsinformationtechnology', 'bsit'],
        'display_html' => 'Information Technology (IT)',
    ],
    'Computer Engineering(CPE)' => [
        'code' => 'CPE',
        'aliases' => ['cpe', 'computerengineering', 'bscomputerengineering', 'bscpe'],
        'display_html' => 'Computer Engineering (CPE)',
    ],
];
$quickProgramButtons = [];
foreach ($quickProgramTargets as $label => $config) {
    $aliases = $config['aliases'];
    $matchedProgramId = null;
    foreach ($programs as $programRow) {
        $normalizedName = normalizeProgramLabel($programRow['program_name'] ?? '');
        $normalizedCode = normalizeProgramLabel($programRow['program_code'] ?? '');
        if (in_array($normalizedName, $aliases, true) || in_array($normalizedCode, $aliases, true)) {
            $matchedProgramId = (int)$programRow['id'];
            break;
        }
    }
    $quickProgramButtons[] = [
        'label' => $label,
        'code' => $config['code'],
        'id' => $matchedProgramId,
        'aliases' => $aliases,
        'display_html' => $config['display_html'],
    ];
}
$selectedProgramCode = strtoupper(trim((string)($_GET['program'] ?? '')));
if (!in_array($selectedProgramCode, ['CS', 'IT', 'CPE'], true)) {
    $selectedProgramCode = '';
}
$selectedSemester = trim((string)($_GET['semester'] ?? ''));
if (!in_array($selectedSemester, ['1st Semester', '2nd Semester'], true)) {
    $selectedSemester = '';
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
        $timeValues = computeSubjectTimeValues(
            $subject_type,
            $_POST['meetings_per_week'] ?? 2,
            $_POST['lecture_minutes_per_meeting'] ?? 0,
            $_POST['lab_minutes_per_meeting'] ?? 0,
            $_POST['hours_per_week'] ?? null
        );
        
        try {
            $stmt = $pdo->prepare("INSERT INTO subjects (subject_code, subject_name, credits, department, program_id, subject_type, semester, year_level, prerequisites, hours_per_week, lecture_hours, lab_hours, meetings_per_week, lecture_minutes_per_meeting, lab_minutes_per_meeting) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $subject_code,
                $subject_name,
                $credits,
                $department,
                $program_id,
                $subject_type,
                $semester,
                $year_level,
                $prerequisites,
                $timeValues['hours_per_week'],
                $timeValues['lecture_hours'],
                $timeValues['lab_hours'],
                $timeValues['meetings_per_week'],
                $timeValues['lecture_minutes_per_meeting'],
                $timeValues['lab_minutes_per_meeting'],
            ]);
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
        $timeValues = computeSubjectTimeValues(
            $subject_type,
            $_POST['meetings_per_week'] ?? 2,
            $_POST['lecture_minutes_per_meeting'] ?? 0,
            $_POST['lab_minutes_per_meeting'] ?? 0,
            $_POST['hours_per_week'] ?? null
        );
        
        try {
            $stmt = $pdo->prepare("UPDATE subjects SET subject_code = ?, subject_name = ?, credits = ?, department = ?, program_id = ?, subject_type = ?, semester = ?, year_level = ?, prerequisites = ?, hours_per_week = ?, lecture_hours = ?, lab_hours = ?, meetings_per_week = ?, lecture_minutes_per_meeting = ?, lab_minutes_per_meeting = ? WHERE id = ?");
            $stmt->execute([
                $subject_code,
                $subject_name,
                $credits,
                $department,
                $program_id,
                $subject_type,
                $semester,
                $year_level,
                $prerequisites,
                $timeValues['hours_per_week'],
                $timeValues['lecture_hours'],
                $timeValues['lab_hours'],
                $timeValues['meetings_per_week'],
                $timeValues['lecture_minutes_per_meeting'],
                $timeValues['lab_minutes_per_meeting'],
                $id
            ]);
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

    if (isset($_POST['assign_instructor'])) {
        $subjectId = (int)($_POST['subject_id'] ?? 0);
        $instructorIds = normalizeInstructorAssignments($_POST['instructor_ids'] ?? [], $instructorNameById, $instructorIdByName);

        if ($subjectId <= 0) {
            $error = "Please choose a valid subject.";
        } else {
            try {
                $pdo->beginTransaction();
                $deleteStmt = $pdo->prepare("DELETE FROM subject_instructor_assignments WHERE subject_id = ?");
                $deleteStmt->execute([$subjectId]);

                if (!empty($instructorIds)) {
                    $insertStmt = $pdo->prepare("
                        INSERT INTO subject_instructor_assignments (subject_id, assignment_slot, instructor_id)
                        VALUES (?, ?, ?)
                    ");
                    foreach ($instructorIds as $index => $instructorId) {
                        $insertStmt->execute([$subjectId, $index + 1, $instructorId]);
                    }
                }

                $pdo->commit();
                $message = "Instructor assignment updated successfully!";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = "Error assigning instructor: " . $e->getMessage();
            }
        }
    }
}

// Fetch all subjects
$subjects = $pdo->query("
    SELECT s.*,
           p.program_name AS linked_program_name,
           COALESCE(NULLIF(s.department, ''), p.program_name, 'All Programs') AS raw_program_name,
           COALESCE(NULLIF(s.semester, ''), '1st Semester') AS normalized_semester,
           COALESCE(subject_type, 'major') AS subject_type
    FROM subjects s
    LEFT JOIN programs p ON s.program_id = p.id
    ORDER BY raw_program_name, subject_code
")->fetchAll();
$subjectAssignments = [];
$assignmentRows = $pdo->query("
    SELECT sia.subject_id, sia.assignment_slot, sia.instructor_id, u.full_name
    FROM subject_instructor_assignments sia
    JOIN instructors ai ON sia.instructor_id = ai.id
    JOIN users u ON ai.user_id = u.id
    ORDER BY sia.subject_id, sia.assignment_slot, u.full_name
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($assignmentRows as $assignmentRow) {
    $subjectId = (int)($assignmentRow['subject_id'] ?? 0);
    if ($subjectId <= 0) {
        continue;
    }
    if (!isset($subjectAssignments[$subjectId])) {
        $subjectAssignments[$subjectId] = [
            'ids' => [],
            'names' => [],
        ];
    }
    $subjectAssignments[$subjectId]['ids'][] = (int)($assignmentRow['instructor_id'] ?? 0);
    $subjectAssignments[$subjectId]['names'][] = (string)($assignmentRow['full_name'] ?? '');
}
foreach ($subjects as &$subject) {
    $meetingCount = normalizeMeetingsPerWeek($subject['meetings_per_week'] ?? 2);
    $lectureMinutesPerMeeting = normalizeMinutes($subject['lecture_minutes_per_meeting'] ?? 0);
    $labMinutesPerMeeting = normalizeMinutes($subject['lab_minutes_per_meeting'] ?? 0);
    if ($lectureMinutesPerMeeting <= 0 && $labMinutesPerMeeting <= 0) {
        $fallback = computeSubjectTimeValues(
            $subject['subject_type'] ?? 'major',
            $meetingCount,
            0,
            0,
            $subject['hours_per_week'] ?? 0
        );
        $meetingCount = $fallback['meetings_per_week'];
        $lectureMinutesPerMeeting = $fallback['lecture_minutes_per_meeting'];
        $labMinutesPerMeeting = $fallback['lab_minutes_per_meeting'];
    }
    $subject['meetings_per_week'] = $meetingCount;
    $subject['lecture_minutes_per_meeting'] = $lectureMinutesPerMeeting;
    $subject['lab_minutes_per_meeting'] = $labMinutesPerMeeting;
    $subject['meeting_pattern_text'] = trim(implode(' + ', array_values(array_filter([
        $lectureMinutesPerMeeting > 0 ? ('Lecture ' . formatMinutesAsClock($lectureMinutesPerMeeting)) : '',
        $labMinutesPerMeeting > 0 ? ('Laboratory ' . formatMinutesAsClock($labMinutesPerMeeting)) : '',
    ]))));
    if ($subject['meeting_pattern_text'] === '') {
        $subject['meeting_pattern_text'] = formatMinutesAsClock((int)round(((float)($subject['hours_per_week'] ?? 0)) * 60));
    }
    $weeklyMinutes = ($lectureMinutesPerMeeting + $labMinutesPerMeeting) * $meetingCount;
    $subject['weekly_time_text'] = formatMinutesAsClock($weeklyMinutes);
    $subjectProgramCode = resolveSubjectProgramCode($subject);
    $subject['resolved_program_code'] = $subjectProgramCode;
    if (in_array($subjectProgramCode, ['CS', 'IT', 'CPE'], true)) {
        $subject['program_display_name'] = getProgramDisplayNameFromCode($subjectProgramCode);
    } elseif (!empty($subject['raw_program_name'])) {
        $subject['program_display_name'] = $subject['raw_program_name'];
    } elseif (!empty($subject['linked_program_name'])) {
        $subject['program_display_name'] = $subject['linked_program_name'];
    }
    $assignmentInfo = $subjectAssignments[(int)$subject['id']] ?? ['ids' => [], 'names' => []];
    $subject['assigned_instructor_ids'] = $assignmentInfo['ids'];
    $subject['assigned_instructor_names'] = array_values(array_filter($assignmentInfo['names']));
    $subject['assigned_instructor_names_text'] = !empty($subject['assigned_instructor_names'])
        ? implode(', ', $subject['assigned_instructor_names'])
        : 'Unassigned';
}
unset($subject);
if ($selectedProgramCode !== '') {
    $subjects = array_values(array_filter($subjects, function ($subject) use ($selectedProgramCode) {
        return ($subject['resolved_program_code'] ?? '') === $selectedProgramCode;
    }));
}
if ($selectedSemester !== '') {
    $subjects = array_values(array_filter($subjects, function ($subject) use ($selectedSemester) {
        return ($subject['normalized_semester'] ?? '') === $selectedSemester;
    }));
}
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

        .btn-assign {
            background: linear-gradient(135deg, #0284c7, #38bdf8);
            border-color: #0369a1;
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

        .action-buttons {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
            justify-content: space-between;
            margin-bottom: 18px;
        }

        .action-buttons-left,
        .action-buttons-right {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }

        .action-buttons button,
        .action-buttons a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            line-height: 1.25;
            text-decoration: none;
        }

        .btn-program-quick {
            min-height: 40px;
            min-width: 170px;
            padding: 8px 14px;
            cursor: pointer;
            border-radius: 8px;
            border: 1px solid #cbd5e1;
            background: #fff;
            color: #1e293b;
            font-size: 14px;
            font-weight: 600;
            white-space: nowrap;
        }

        .action-buttons .btn-primary,
        .action-buttons .btn-secondary {
            min-width: 134px;
        }

        .btn-filter-all {
            min-height: 40px;
            padding: 8px 14px;
            border-radius: 8px;
            border: 1px solid #cbd5e1;
            background: #f8fafc;
            color: #334155;
            font-size: 14px;
            font-weight: 600;
            white-space: nowrap;
        }

        .btn-program-quick:hover {
            background: #f8fafc;
            border-color: #94a3b8;
        }

        .btn-program-quick.is-active {
            box-shadow: inset 0 0 0 1px currentColor;
        }

        .btn-program-cs {
            background: #eff6ff;
            border-color: #bfdbfe;
            color: #1d4ed8;
        }

        .btn-program-cs:hover,
        .btn-program-cs.is-active {
            background: #dbeafe;
        }

        .btn-program-it {
            background: #ecfeff;
            border-color: #a5f3fc;
            color: #0f766e;
        }

        .btn-program-it:hover,
        .btn-program-it.is-active {
            background: #cffafe;
        }

        .btn-program-cpe {
            background: #f0fdf4;
            border-color: #bbf7d0;
            color: #15803d;
        }

        .btn-program-cpe:hover,
        .btn-program-cpe.is-active {
            background: #dcfce7;
        }

        .btn-filter-all:hover,
        .btn-filter-all.is-active {
            background: #e2e8f0;
            border-color: #94a3b8;
            color: #0f172a;
        }

        .btn-semester-filter {
            min-height: 40px;
            padding: 8px 14px;
            border-radius: 8px;
            border: 1px solid #d8b4fe;
            background: #faf5ff;
            color: #7c3aed;
            font-size: 14px;
            font-weight: 600;
            white-space: nowrap;
        }

        .btn-semester-filter:hover,
        .btn-semester-filter.is-active {
            background: #f3e8ff;
            border-color: #c084fc;
            color: #6d28d9;
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
            <div class="action-buttons-left">
                <button type="button" class="btn-primary" onclick="openAddSubjectModal()">Add New Subject</button>
                <button type="button" class="btn-secondary" onclick="openModal('addDepartmentModal')">Add Program</button>
                <a
                    href="manage_subjects.php<?php echo $selectedSemester !== '' ? '?semester=' . urlencode($selectedSemester) : ''; ?>"
                    class="btn-filter-all<?php echo $selectedProgramCode === '' ? ' is-active' : ''; ?>"
                >
                    Show all
                </a>
                <?php foreach ($quickProgramButtons as $quickProgramButton): ?>
                <a
                    href="?program=<?php echo urlencode($quickProgramButton['code']); ?><?php echo $selectedSemester !== '' ? '&semester=' . urlencode($selectedSemester) : ''; ?>"
                    class="btn-program-quick btn-program-<?php echo strtolower($quickProgramButton['code']); ?><?php echo $selectedProgramCode === $quickProgramButton['code'] ? ' is-active' : ''; ?>"
                >
                    <?php echo $quickProgramButton['display_html']; ?>
                </a>
                <?php endforeach; ?>
            </div>
            <div class="action-buttons-right">
                <a
                    href="manage_subjects.php<?php echo $selectedProgramCode !== '' ? '?program=' . urlencode($selectedProgramCode) : ''; ?>"
                    class="btn-semester-filter<?php echo $selectedSemester === '' ? ' is-active' : ''; ?>"
                >
                    All Semesters
                </a>
                <a
                    href="?<?php echo http_build_query(array_filter(['program' => $selectedProgramCode ?: null, 'semester' => '1st Semester'])); ?>"
                    class="btn-semester-filter<?php echo $selectedSemester === '1st Semester' ? ' is-active' : ''; ?>"
                >
                    1st Semester
                </a>
                <a
                    href="?<?php echo http_build_query(array_filter(['program' => $selectedProgramCode ?: null, 'semester' => '2nd Semester'])); ?>"
                    class="btn-semester-filter<?php echo $selectedSemester === '2nd Semester' ? ' is-active' : ''; ?>"
                >
                    2nd Semester
                </a>
            </div>
        </div>
        <!-- Subjects Table -->
        <table class="data-table">
            <thead>
                <tr>
                    <th>Subject Code</th>
                    <th>Subject Name</th>
                    <th>Program</th>
                    <th>Assigned Instructor</th>
                    <th>Year</th>
                    <th>Semester</th>
                    <th>Type</th>
                    <th>Credits</th>
                    <th>Meeting Pattern</th>
                    <th>Weekly Time</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($subjects as $subject): ?>
                <tr data-search-text="<?php echo htmlspecialchars(strtolower(
                    $subject['subject_code'] . ' ' .
                    $subject['subject_name'] . ' ' .
                    ($subject['program_display_name'] ?? $subject['department']) . ' ' .
                    ($subject['assigned_instructor_names_text'] ?? '') . ' ' .
                    ($subject['semester'] ?? '') . ' ' .
                    $subject['subject_type'] . ' ' .
                    ($subject['meeting_pattern_text'] ?? '') . ' ' .
                    ($subject['weekly_time_text'] ?? '')
                )); ?>"
                    data-program-code="<?php echo htmlspecialchars(resolveSubjectProgramCode($subject)); ?>">
                    <td><?php echo htmlspecialchars($subject['subject_code']); ?></td>
                    <td><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                    <td><?php echo htmlspecialchars($subject['program_display_name'] ?? $subject['department']); ?></td>
                    <td><?php echo htmlspecialchars($subject['assigned_instructor_names_text']); ?></td>
                    <td><?php echo htmlspecialchars((string)($subject['year_level'] ?? 1)); ?></td>
                    <td><?php echo htmlspecialchars($subject['normalized_semester'] ?? '1st Semester'); ?></td>
                    <td><?php echo ucfirst(htmlspecialchars($subject['subject_type'] ?? 'major')); ?></td>
                    <td><?php echo $subject['credits']; ?></td>
                    <td><?php echo htmlspecialchars($subject['meeting_pattern_text']); ?></td>
                    <td><?php echo htmlspecialchars($subject['weekly_time_text']); ?></td>
                    <td>
                        <div class="row-actions">
                            <button
                                type="button"
                                class="btn-icon btn-assign"
                                onclick='openAssignInstructorModal(<?php echo (int)$subject["id"]; ?>, <?php echo json_encode($subject["subject_code"] . " - " . $subject["subject_name"], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>, <?php echo json_encode($subject["assigned_instructor_ids"], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>)'
                            >
                                <i class="fas fa-user-plus"></i> Choose Instructor
                            </button>
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
                        <label for="meetings_per_week">Meetings Per Week:</label>
                        <input type="number" id="meetings_per_week" name="meetings_per_week" min="1" max="7" step="1" value="2">
                    </div>
                    <div class="form-group">
                        <label for="lecture_minutes_per_meeting">Lecture Per Meeting (minutes):</label>
                        <input type="number" id="lecture_minutes_per_meeting" name="lecture_minutes_per_meeting" min="0" max="600" step="5" value="120">
                    </div>
                    <div class="form-group">
                        <label for="lab_minutes_per_meeting">Laboratory Per Meeting (minutes):</label>
                        <input type="number" id="lab_minutes_per_meeting" name="lab_minutes_per_meeting" min="0" max="600" step="5" value="0">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="hours_per_week">Weekly Total:</label>
                    <input type="text" id="hours_per_week" name="hours_per_week" value="4:00" readonly>
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
                        <label for="edit_meetings_per_week">Meetings Per Week:</label>
                        <input type="number" id="edit_meetings_per_week" name="meetings_per_week" min="1" max="7" step="1" value="2">
                    </div>
                    <div class="form-group">
                        <label for="edit_lecture_minutes_per_meeting">Lecture Per Meeting (minutes):</label>
                        <input type="number" id="edit_lecture_minutes_per_meeting" name="lecture_minutes_per_meeting" min="0" max="600" step="5" value="120">
                    </div>
                    <div class="form-group">
                        <label for="edit_lab_minutes_per_meeting">Laboratory Per Meeting (minutes):</label>
                        <input type="number" id="edit_lab_minutes_per_meeting" name="lab_minutes_per_meeting" min="0" max="600" step="5" value="0">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="edit_hours_per_week">Weekly Total:</label>
                    <input type="text" id="edit_hours_per_week" name="hours_per_week" readonly>
                </div>
                
                <button type="submit" name="edit_subject" class="btn-primary">Update Subject</button>
            </form>
        </div>
    </div>

    <div id="assignInstructorModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('assignInstructorModal')">&times;</span>
            <h2>Choose Instructor</h2>
            <datalist id="assign_instructor_options">
                <?php foreach ($instructors as $instructor): ?>
                <option value="<?php echo htmlspecialchars($instructor['full_name']); ?>"></option>
                <?php endforeach; ?>
            </datalist>
            <form method="POST">
                <input type="hidden" id="assign_subject_id" name="subject_id">
                <div class="form-group">
                    <label for="assign_subject_label">Subject:</label>
                    <input type="text" id="assign_subject_label" readonly>
                </div>
                <div class="form-group">
                    <label for="assign_instructor_id_1">Instructor 1:</label>
                    <input type="text" id="assign_instructor_id_1" name="instructor_ids[]" list="assign_instructor_options" placeholder="Search instructor name...">
                </div>
                <div class="form-group">
                    <label for="assign_instructor_id_2">Instructor 2:</label>
                    <input type="text" id="assign_instructor_id_2" name="instructor_ids[]" list="assign_instructor_options" placeholder="Search instructor name...">
                </div>
                <div class="form-group">
                    <label for="assign_instructor_id_3">Instructor 3:</label>
                    <input type="text" id="assign_instructor_id_3" name="instructor_ids[]" list="assign_instructor_options" placeholder="Search instructor name...">
                </div>
                <div class="form-group">
                    <label for="assign_instructor_id_4">Instructor 4:</label>
                    <input type="text" id="assign_instructor_id_4" name="instructor_ids[]" list="assign_instructor_options" placeholder="Search instructor name...">
                </div>
                <button type="submit" name="assign_instructor" class="btn-primary">Save Instructor</button>
            </form>
        </div>
    </div>
    
    <script>
        const assignableInstructorsById = <?php echo json_encode(array_map('strval', $instructorNameById), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

        function getDefaultSubjectPattern(typeValue) {
            if (typeValue === 'minor') {
                return {
                    meetingsPerWeek: 2,
                    lectureMinutes: 90,
                    labMinutes: 0,
                };
            }
            return {
                meetingsPerWeek: 2,
                lectureMinutes: 120,
                labMinutes: 0,
            };
        }

        function updateMajorBreakdownVisibility(typeEl, breakdownWrap, hoursEl) {
            if (!typeEl || !breakdownWrap || !hoursEl) {
                return;
            }
            breakdownWrap.style.display = 'block';
            hoursEl.readOnly = true;
            const prefix = typeEl.id && typeEl.id.startsWith('edit_') ? 'edit_' : '';
            const labEl = document.getElementById(`${prefix}lab_minutes_per_meeting`);
            if (labEl) {
                labEl.disabled = typeEl.value === 'minor';
                if (typeEl.value === 'minor') {
                    labEl.value = '0';
                }
            }
        }

        function formatClockMinutes(totalMinutes) {
            const minutes = Math.max(0, parseInt(totalMinutes || 0, 10));
            const hours = Math.floor(minutes / 60);
            const mins = minutes % 60;
            return `${hours}:${String(mins).padStart(2, '0')}`;
        }

        function syncMajorTotal(meetingsEl, lectureEl, labEl, hoursEl) {
            if (!meetingsEl || !lectureEl || !labEl || !hoursEl) {
                return;
            }
            const meetings = parseInt(meetingsEl.value || 0, 10);
            const lec = parseInt(lectureEl.value || 0, 10);
            const lab = parseInt(labEl.value || 0, 10);
            const total = (Number.isNaN(meetings) ? 0 : meetings) * ((Number.isNaN(lec) ? 0 : lec) + (Number.isNaN(lab) ? 0 : lab));
            hoursEl.value = formatClockMinutes(total);
        }

        function syncHoursDefault(typeEl, hoursEl, meetingsEl, lectureEl, labEl) {
            if (!typeEl || !hoursEl || !meetingsEl || !lectureEl || !labEl) {
                return;
            }
            const prevDefault = getDefaultSubjectPattern(typeEl.dataset.previousType || 'major');
            const currentPatternMatchesPrevious = (
                parseInt(meetingsEl.value || 0, 10) === prevDefault.meetingsPerWeek
                && parseInt(lectureEl.value || 0, 10) === prevDefault.lectureMinutes
                && parseInt(labEl.value || 0, 10) === prevDefault.labMinutes
            );
            if (currentPatternMatchesPrevious || !hoursEl.value) {
                const nextDefault = getDefaultSubjectPattern(typeEl.value);
                meetingsEl.value = String(nextDefault.meetingsPerWeek);
                lectureEl.value = String(nextDefault.lectureMinutes);
                labEl.value = String(nextDefault.labMinutes);
            }
            syncMajorTotal(meetingsEl, lectureEl, labEl, hoursEl);
            typeEl.dataset.previousType = typeEl.value;
        }

        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
        }

        function normalizeProgramOptionLabel(value) {
            return String(value || '').toLowerCase().replace(/[^a-z0-9]+/g, '');
        }

        function openAddSubjectModal(programId, aliases = []) {
            const programSelect = document.getElementById('program_id');
            if (programSelect) {
                if (programId) {
                    programSelect.value = String(programId);
                } else {
                    const aliasList = Array.isArray(aliases) ? aliases : [];
                    const matchingOption = Array.from(programSelect.options).find(option => {
                        const normalizedText = normalizeProgramOptionLabel(option.textContent);
                        const normalizedValue = normalizeProgramOptionLabel(option.value);
                        return aliasList.some(alias => alias === normalizedText || alias === normalizedValue || normalizedText.includes(alias));
                    });
                    programSelect.value = matchingOption ? matchingOption.value : 'all';
                }
            }
            openModal('addSubjectModal');
        }

        function openAssignInstructorModal(subjectId, subjectLabel, instructorIds) {
            const subjectIdEl = document.getElementById('assign_subject_id');
            const subjectLabelEl = document.getElementById('assign_subject_label');
            const selectedInstructorNames = Array.isArray(instructorIds)
                ? instructorIds.map(id => assignableInstructorsById[String(id)] || '')
                : [];
            if (subjectIdEl) {
                subjectIdEl.value = String(subjectId || '');
            }
            if (subjectLabelEl) {
                subjectLabelEl.value = subjectLabel || '';
            }
            for (let slot = 1; slot <= 4; slot += 1) {
                const instructorSelectEl = document.getElementById(`assign_instructor_id_${slot}`);
                if (instructorSelectEl) {
                    instructorSelectEl.value = selectedInstructorNames[slot - 1] || '';
                }
            }
            openModal('assignInstructorModal');
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
                    const editMeetingsEl = document.getElementById('edit_meetings_per_week');
                    const editLectureEl = document.getElementById('edit_lecture_minutes_per_meeting');
                    const editLabEl = document.getElementById('edit_lab_minutes_per_meeting');
                    const editBreakdown = document.getElementById('edit_major_breakdown');
                    editTypeEl.value = (data.subject_type || 'major').toLowerCase();
                    editTypeEl.dataset.previousType = editTypeEl.value;
                    editMeetingsEl.value = String(data.meetings_per_week || 2);
                    editLectureEl.value = String(data.lecture_minutes_per_meeting || 0);
                    editLabEl.value = String(data.lab_minutes_per_meeting || 0);
                    syncMajorTotal(editMeetingsEl, editLectureEl, editLabEl, editHoursEl);
                    updateMajorBreakdownVisibility(editTypeEl, editBreakdown, editHoursEl);
                    openModal('editSubjectModal');
                });
        }

        const addSubjectType = document.getElementById('subject_type');
        if (addSubjectType) {
            const addHoursEl = document.getElementById('hours_per_week');
            const addMeetingsEl = document.getElementById('meetings_per_week');
            const addLectureEl = document.getElementById('lecture_minutes_per_meeting');
            const addLabEl = document.getElementById('lab_minutes_per_meeting');
            const addBreakdown = document.getElementById('add_major_breakdown');
            addSubjectType.dataset.previousType = addSubjectType.value;
            addSubjectType.addEventListener('change', function () {
                syncHoursDefault(addSubjectType, addHoursEl, addMeetingsEl, addLectureEl, addLabEl);
                updateMajorBreakdownVisibility(addSubjectType, addBreakdown, addHoursEl);
            });
            if (addMeetingsEl && addLectureEl && addLabEl) {
                addMeetingsEl.addEventListener('input', function () {
                    syncMajorTotal(addMeetingsEl, addLectureEl, addLabEl, addHoursEl);
                });
                addLectureEl.addEventListener('input', function () {
                    syncMajorTotal(addMeetingsEl, addLectureEl, addLabEl, addHoursEl);
                });
                addLabEl.addEventListener('input', function () {
                    syncMajorTotal(addMeetingsEl, addLectureEl, addLabEl, addHoursEl);
                });
            }
            updateMajorBreakdownVisibility(addSubjectType, addBreakdown, addHoursEl);
            syncMajorTotal(addMeetingsEl, addLectureEl, addLabEl, addHoursEl);
        }

        const editSubjectType = document.getElementById('edit_subject_type');
        if (editSubjectType) {
            const editHoursEl = document.getElementById('edit_hours_per_week');
            const editMeetingsEl = document.getElementById('edit_meetings_per_week');
            const editLectureEl = document.getElementById('edit_lecture_minutes_per_meeting');
            const editLabEl = document.getElementById('edit_lab_minutes_per_meeting');
            const editBreakdown = document.getElementById('edit_major_breakdown');
            editSubjectType.addEventListener('change', function () {
                syncHoursDefault(editSubjectType, editHoursEl, editMeetingsEl, editLectureEl, editLabEl);
                updateMajorBreakdownVisibility(editSubjectType, editBreakdown, editHoursEl);
            });
            if (editMeetingsEl && editLectureEl && editLabEl) {
                editMeetingsEl.addEventListener('input', function () {
                    syncMajorTotal(editMeetingsEl, editLectureEl, editLabEl, editHoursEl);
                });
                editLectureEl.addEventListener('input', function () {
                    syncMajorTotal(editMeetingsEl, editLectureEl, editLabEl, editHoursEl);
                });
                editLabEl.addEventListener('input', function () {
                    syncMajorTotal(editMeetingsEl, editLectureEl, editLabEl, editHoursEl);
                });
            }
            updateMajorBreakdownVisibility(editSubjectType, editBreakdown, editHoursEl);
            syncMajorTotal(editMeetingsEl, editLectureEl, editLabEl, editHoursEl);
        }
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }

        const subjectSearchInput = document.getElementById('subject_search');
        const clearSubjectSearchBtn = document.getElementById('clear_subject_search');
        const subjectRows = document.querySelectorAll('.data-table tbody tr');

        function applySubjectFilters() {
            const query = subjectSearchInput ? subjectSearchInput.value.trim().toLowerCase() : '';
            subjectRows.forEach(row => {
                const text = row.getAttribute('data-search-text') || '';
                const matchesSearch = text.includes(query);
                row.style.display = matchesSearch ? '' : 'none';
            });
            if (clearSubjectSearchBtn) {
                clearSubjectSearchBtn.disabled = query.length === 0;
            }
        }

        if (subjectSearchInput) {
            subjectSearchInput.addEventListener('input', applySubjectFilters);
            applySubjectFilters();

            if (clearSubjectSearchBtn) {
                clearSubjectSearchBtn.addEventListener('click', function () {
                    subjectSearchInput.value = '';
                    applySubjectFilters();
                    subjectSearchInput.focus();
                });
            }
        } else {
            applySubjectFilters();
        }
    </script>
</body>
</html>

