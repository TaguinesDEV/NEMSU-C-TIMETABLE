<?php
require_once '../includes/auth.php';
requireAdmin();

$pdo = getDB();
$message = '';
$error = '';
$activeJobSummary = '';

try {
    $activeJobStmt = $pdo->query("
        SELECT job_name, status, created_at
        FROM schedule_jobs
        WHERE status IN ('pending', 'processing')
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $activeJob = $activeJobStmt->fetch(PDO::FETCH_ASSOC);
    if ($activeJob) {
        $activeJobSummary = sprintf(
            '%s job "%s" started on %s is still active.',
            ucfirst((string)$activeJob['status']),
            (string)$activeJob['job_name'],
            date('F j, Y g:i A', strtotime((string)$activeJob['created_at']))
        );
    }
} catch (Exception $e) {
    $activeJobSummary = '';
}

function normalizeSemester($value) {
    $semester = trim((string)($value ?? ''));
    $allowed = ['1st Semester', '2nd Semester', 'Summer'];
    return in_array($semester, $allowed, true) ? $semester : '1st Semester';
}

function normalizeYearLevelSelection($value) {
    $yearLevel = (int)($value ?? 1);
    if ($yearLevel < 1 || $yearLevel > 5) {
        return 1;
    }
    return $yearLevel;
}

function normalizeScheduleMode($value) {
    $mode = trim((string)($value ?? 'single'));
    $allowed = ['single', '1_3', '2_4'];
    return in_array($mode, $allowed, true) ? $mode : 'single';
}

function normalizeWeekdayValue($value) {
    $day = trim((string)($value ?? ''));
    $allowed = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
    return in_array($day, $allowed, true) ? $day : '';
}

function normalizeProgramSelection($value) {
    $program = trim((string)($value ?? ''));
    $allowed = ['Computer Science', 'Information Technology', 'Computer Engineering'];
    return in_array($program, $allowed, true) ? $program : 'Computer Science';
}

function normalizeProgramAlias($value) {
    $text = strtolower(trim((string)$value));
    if ($text === '') {
        return '';
    }
    if (strpos($text, 'computer science') !== false || strpos($text, 'bscs') !== false || $text === 'cs') {
        return 'Computer Science';
    }
    if (strpos($text, 'information technology') !== false || strpos($text, 'bsit') !== false || $text === 'it') {
        return 'Information Technology';
    }
    if (strpos($text, 'computer engineering') !== false || strpos($text, 'bscpe') !== false || strpos($text, 'bscoe') !== false || $text === 'cpe' || $text === 'coe') {
        return 'Computer Engineering';
    }
    return '';
}

function isAllProgramsSubject($subject) {
    $programId = $subject['program_id'] ?? null;
    if ($programId === null || $programId === '' || (int)$programId === 0) {
        $departmentText = strtolower(trim((string)($subject['department'] ?? '')));
        $linkedProgramText = strtolower(trim((string)($subject['linked_program_name'] ?? '')));
        if ($departmentText === '' || strpos($departmentText, 'all program') !== false || $linkedProgramText === '') {
            return true;
        }
    }
    $departmentText = strtolower(trim((string)($subject['department'] ?? '')));
    return strpos($departmentText, 'all program') !== false;
}

function preferScopedSubjects(array $subjects): array {
    $bestByCode = [];
    foreach ($subjects as $subject) {
        $code = strtoupper(trim((string)($subject['subject_code'] ?? '')));
        if ($code === '') {
            continue;
        }
        $isScoped = !isAllProgramsSubject($subject);
        if (!isset($bestByCode[$code]) || ($isScoped && !$bestByCode[$code]['is_scoped'])) {
            $bestByCode[$code] = [
                'is_scoped' => $isScoped,
                'subject' => $subject,
            ];
        }
    }

    return array_values(array_map(function ($entry) {
        return $entry['subject'];
    }, $bestByCode));
}

$program_options = ['Computer Science', 'Information Technology', 'Computer Engineering'];
$selected_semester = normalizeSemester($_POST['semester'] ?? $_GET['semester'] ?? '1st Semester');
$selected_program = normalizeProgramSelection($_POST['program'] ?? $_GET['program'] ?? 'Computer Science');
$selected_schedule_mode = normalizeScheduleMode($_POST['schedule_mode'] ?? $_GET['schedule_mode'] ?? 'single');
$selected_year_level = normalizeYearLevelSelection($_POST['year_level'] ?? $_GET['year_level'] ?? 1);
$selected_mirror_pair1_day = normalizeWeekdayValue($_POST['mirror_pair1_day'] ?? '');
$selected_mirror_pair1_mirror = normalizeWeekdayValue($_POST['mirror_pair1_mirror'] ?? '');
$selected_mirror_pair2_day = normalizeWeekdayValue($_POST['mirror_pair2_day'] ?? '');
$selected_mirror_pair2_mirror = normalizeWeekdayValue($_POST['mirror_pair2_mirror'] ?? '');
$selected_non_mirror_mode = (string)($_POST['non_mirror_mode'] ?? '1') === '0' ? '0' : '1';
$selected_allow_saturday = isset($_POST['allow_saturday']);
$selected_avoid_back_to_back = isset($_POST['avoid_back_to_back']);
$selected_respect_availability = !isset($_POST['generate_schedule']) || isset($_POST['respect_availability']);

// Fetch data for dropdowns
$all_instructors = $pdo->query("
    SELECT i.*, u.full_name, p.program_name AS linked_program_name
    FROM instructors i 
    JOIN users u ON i.user_id = u.id
    LEFT JOIN programs p ON i.program_id = p.id
")->fetchAll();
$instructors = $all_instructors;
$own_program_instructors = [];
$cross_program_instructors = [];
foreach ($all_instructors as $inst) {
    $department_match = normalizeProgramAlias($inst['department'] ?? '');
    $linked_program_match = normalizeProgramAlias($inst['linked_program_name'] ?? '');
    if ($department_match === $selected_program || $linked_program_match === $selected_program) {
        $own_program_instructors[] = $inst;
    } else {
        $cross_program_instructors[] = $inst;
    }
}

$rooms = $pdo->query("SELECT * FROM rooms")->fetchAll();
$subject_columns = [];
foreach ($pdo->query("SHOW COLUMNS FROM subjects")->fetchAll(PDO::FETCH_ASSOC) as $col) {
    $subject_columns[$col['Field']] = true;
}
$has_subject_semester = isset($subject_columns['semester']);
if ($has_subject_semester && isset($subject_columns['year_level'])) {
    $year_levels_to_fetch = [];
    if ($selected_schedule_mode === '1_3') {
        $year_levels_to_fetch = [1, 3];
    } elseif ($selected_schedule_mode === '2_4') {
        $year_levels_to_fetch = [2, 4];
    } else {
        $year_levels_to_fetch = [$selected_year_level];
    }

    $placeholders = implode(',', array_fill(0, count($year_levels_to_fetch), '?'));
    $query = "
        SELECT s.*, p.program_name AS linked_program_name
        FROM subjects s
        LEFT JOIN programs p ON s.program_id = p.id
        WHERE (s.semester = ? OR s.semester IS NULL)
          AND (s.year_level IN ($placeholders) OR s.year_level IS NULL)
    ";
    $subjects_stmt = $pdo->prepare($query);
    $params = array_merge([$selected_semester], $year_levels_to_fetch);
    $subjects_stmt->execute($params);
    $subjects = $subjects_stmt->fetchAll();
} elseif ($has_subject_semester) {
    $subjects_stmt = $pdo->prepare("
        SELECT s.*, p.program_name AS linked_program_name
        FROM subjects s
        LEFT JOIN programs p ON s.program_id = p.id
        WHERE s.semester = ? OR s.semester IS NULL
    ");
    $subjects_stmt->execute([$selected_semester]);
    $subjects = $subjects_stmt->fetchAll();
} else {
    $subjects = $pdo->query("
        SELECT s.*, p.program_name AS linked_program_name
        FROM subjects s
        LEFT JOIN programs p ON s.program_id = p.id
    ")->fetchAll();
}
$subjects = array_values(array_filter($subjects, function ($subject) use ($selected_program) {
    if (isAllProgramsSubject($subject)) {
        return true;
    }
    $department_match = normalizeProgramAlias($subject['department'] ?? '');
    $linked_program_match = normalizeProgramAlias($subject['linked_program_name'] ?? '');
    return $department_match === $selected_program || $linked_program_match === $selected_program;
}));
$subjects = preferScopedSubjects($subjects);
$departments = $pdo->query("SELECT * FROM departments")->fetchAll();

// Subject code -> name map
$subject_name_map = [];
foreach ($subjects as $s) {
    $subject_name_map[strtoupper(trim($s['subject_code']))] = $s['subject_name'];
}
$subject_by_code = [];
foreach ($subjects as $s) {
    $subject_by_code[strtoupper(trim($s['subject_code']))] = $s;
}
$available_subject_codes = array_fill_keys(array_keys($subject_by_code), true);

// Instructor -> assigned subject codes from Manage Subjects assignments,
// then extended with instructor specializations as fallback/extra teachable subjects.
$instructor_subject_codes = [];
try {
    $stmt = $pdo->query("
        SELECT sia.instructor_id, sub.subject_code
        FROM subject_instructor_assignments sia
        JOIN subjects sub ON sia.subject_id = sub.id
        ORDER BY sia.instructor_id, sia.assignment_slot, sub.subject_code
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $inst_id = (int) $row['instructor_id'];
        $code = strtoupper(trim((string) $row['subject_code']));
        if ($code === '') {
            continue;
        }
        if (!isset($instructor_subject_codes[$inst_id])) {
            $instructor_subject_codes[$inst_id] = [];
        }
        if (!in_array($code, $instructor_subject_codes[$inst_id], true)) {
            $instructor_subject_codes[$inst_id][] = $code;
        }
    }
} catch (Exception $e) {
    // Keep the page usable if the assignment table is not present yet.
}

try {
    $stmt = $pdo->query("
        SELECT ism.instructor_id, s.specialization_name
        FROM instructor_specializations ism
        JOIN specializations s ON ism.specialization_id = s.id
        ORDER BY ism.instructor_id, ism.priority
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $inst_id = (int) $row['instructor_id'];
        $code = strtoupper(trim((string) $row['specialization_name']));
        if ($code === '') {
            continue;
        }
        if (!isset($instructor_subject_codes[$inst_id])) {
            $instructor_subject_codes[$inst_id] = [];
        }
        if (!in_array($code, $instructor_subject_codes[$inst_id], true)) {
            $instructor_subject_codes[$inst_id][] = $code;
        }
    }
} catch (Exception $e) {
    // Specializations are optional fallback data.
}

/* 
    IMPORTANT:
    Fetch ALL time slots (including Saturday),
    filtering will happen based on checkbox
*/
$all_time_slots = $pdo->query("
    SELECT * FROM time_slots
    ORDER BY FIELD(day,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'),
             start_time
")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['generate_schedule'])) {
        if ($activeJobSummary !== '') {
            $error = $activeJobSummary . ' Please wait for it to finish before generating a new schedule.';
        } else {

        $job_name = $_POST['job_name'] ?? 'Schedule Generation ' . date('Y-m-d H:i:s');
        $schedule_mode = normalizeScheduleMode($_POST['schedule_mode'] ?? 'single');

        $year_levels_to_schedule = [];
        if ($schedule_mode === '1_3') {
            $year_levels_to_schedule = [1, 3];
        } elseif ($schedule_mode === '2_4') {
            $year_levels_to_schedule = [2, 4];
        } else {
            $year_levels_to_schedule = [(int)($_POST['year_level'] ?? 1)];
        }

        $num_sections = max(1, min(10, (int)($_POST['num_sections'] ?? 1)));

        // Saturday control
        $allow_saturday = isset($_POST['allow_saturday']);
        $mirror_pairs = [];
        $pair_candidates = [
            [
                'day' => normalizeWeekdayValue($_POST['mirror_pair1_day'] ?? ''),
                'mirror' => normalizeWeekdayValue($_POST['mirror_pair1_mirror'] ?? '')
            ],
            [
                'day' => normalizeWeekdayValue($_POST['mirror_pair2_day'] ?? ''),
                'mirror' => normalizeWeekdayValue($_POST['mirror_pair2_mirror'] ?? '')
            ]
        ];
        foreach ($pair_candidates as $pair) {
            if ($pair['day'] !== '' && $pair['mirror'] !== '' && $pair['day'] !== $pair['mirror']) {
                $mirror_pairs[] = $pair;
            }
        }
        $non_mirror_mode = (string)($_POST['non_mirror_mode'] ?? '1') === '0' ? 0 : 1;
        $four_day_pattern = !empty($mirror_pairs);

        // 🔹 Filter time slots
        $filtered_time_slots = [];
        foreach ($all_time_slots as $ts) {
            $day = strtolower((string)$ts['day']);
            if ($day === 'saturday' && !$allow_saturday) {
                continue; // Exclude Saturday unless allowed
            }
            $filtered_time_slots[] = $ts;
        }

        // Prepare GA input
        $input_data = [
            'year_level'   => $year_levels_to_schedule,
            'schedule_mode' => $schedule_mode,
            'num_sections' => $num_sections,
            'semester'     => $selected_semester,
            'program'      => $selected_program,
            'instructors'  => [],
            'rooms'        => [],
            'subjects'     => [],
            'time_slots'   => $filtered_time_slots,
            'constraints'  => [
                'max_classes_per_day' => $_POST['max_classes_per_day'] ?? 4,
                'preferred_start_time' => $_POST['preferred_start_time'] ?? '08:00',
                'avoid_back_to_back' => isset($_POST['avoid_back_to_back']),
                'respect_availability' => isset($_POST['respect_availability']),
                'allow_saturday' => $allow_saturday,
                'four_day_pattern' => $four_day_pattern,
                'mirror_pairs' => $mirror_pairs,
                'non_mirror_mode' => $non_mirror_mode
            ]
        ];

        // Selected instructors
        $selected_instructor_ids = array_map('intval', $_POST['selected_instructors'] ?? []);
        if (!empty($_POST['selected_instructors'])) {
            foreach ($instructors as $inst) {
                if (in_array((int)$inst['id'], $selected_instructor_ids, true)) {
                    $input_data['instructors'][] = $inst;
                }
            }
        }

        // Per-job instructor -> subject-code selection (checkboxes from UI)
        $input_data['instructor_subject_map'] = [];
        foreach ($selected_instructor_ids as $inst_id) {
            $raw_codes = $_POST['instructor_subject_map'][$inst_id] ?? null;
            if (is_array($raw_codes)) {
                $codes = [];
                foreach ($raw_codes as $c) {
                    $code = strtoupper(trim((string)$c));
                    if ($code !== '' && isset($available_subject_codes[$code]) && !in_array($code, $codes, true)) {
                        $codes[] = $code;
                    }
                }
                $input_data['instructor_subject_map'][(string)$inst_id] = $codes;
            } elseif (!empty($instructor_subject_codes[$inst_id])) {
                // Default to all assigned subjects if none explicitly posted
                $fallback_codes = array_values(array_filter($instructor_subject_codes[$inst_id], function ($code) use ($available_subject_codes) {
                    return isset($available_subject_codes[strtoupper(trim((string)$code))]);
                }));
                $input_data['instructor_subject_map'][(string)$inst_id] = $fallback_codes;
            }
        }

        // Selected rooms
        if (!empty($_POST['selected_rooms'])) {
            foreach ($rooms as $room) {
                if (in_array($room['id'], $_POST['selected_rooms'])) {
                    $input_data['rooms'][] = $room;
                }
            }
        }

        // Subjects now come from instructor subject selections (no global subject picker)
        $selected_subject_codes = [];
        foreach ($input_data['instructor_subject_map'] as $codes) {
            foreach ($codes as $code) {
                if (!in_array($code, $selected_subject_codes, true)) {
                    $selected_subject_codes[] = $code;
                }
            }
        }
        foreach ($selected_subject_codes as $code) {
            if (isset($subject_by_code[$code])) {
                $input_data['subjects'][] = $subject_by_code[$code];
            }
        }

        if (empty($input_data['subjects'])) {
            $error = "No valid subjects found for selected instructor assignments under {$selected_semester}. Please check semester and subject assignments.";
        }

        if (empty($error)) {
            // Save job
            $stmt = $pdo->prepare("
                INSERT INTO schedule_jobs (job_name, status, created_by, input_data)
                VALUES (?, 'pending', ?, ?)
            ");
            $stmt->execute([
                $job_name,
                $_SESSION['user_id'],
                json_encode($input_data)
            ]);

            $job_id = $pdo->lastInsertId();

            // Run Python GA
            $script_path = PYTHON_SCRIPT_PATH;

            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $script_path = '"' . str_replace('/', '\\', $script_path) . '"';
                $command = PYTHON_PATH . ' ' . $script_path . ' ' . (int)$job_id;
                pclose(popen("start /B cmd /c $command 1> nul 2>&1", "r"));
            } else {
                exec(PYTHON_PATH . ' ' . $script_path . ' ' . (int)$job_id . ' > /dev/null 2>&1 &');
            }

            $message = "Schedule generation job '{$job_name}' has been started.";
        }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Generate Schedule</title>
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
<h2>Generate Schedule (Genetic Algorithm)</h2>

<?php if ($message): ?>
    <div class="success"><?= $message ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="error"><?= $error ?></div>
<?php endif; ?>

<?php if ($activeJobSummary): ?>
    <div class="error"><?php echo htmlspecialchars($activeJobSummary); ?> Please wait for it to finish before starting another schedule generation.</div>
<?php endif; ?>

<form method="POST" class="schedule-form">

<!-- JOB INFO -->
<div class="form-section">
    <h3>Job Information</h3>

    <div class="form-group">
        <label>Job Name</label>
        <input type="text" name="job_name"
               value="Schedule Generation <?= date('Y-m-d H:i:s') ?>" required>
    </div>

    <div class="form-group">
        <label>Schedule Mode</label>
        <select name="schedule_mode" id="schedule_mode_select">
            <option value="single" <?php echo $selected_schedule_mode === 'single' ? 'selected' : ''; ?>>Single Year</option>
            <option value="1_3" <?php echo $selected_schedule_mode === '1_3' ? 'selected' : ''; ?>>1st & 3rd Year</option>
            <option value="2_4" <?php echo $selected_schedule_mode === '2_4' ? 'selected' : ''; ?>>2nd & 4th Year</option>
        </select>
    </div>

    <div class="form-group" id="year_level_group">
        <label>Year Level</label>
        <select name="year_level" id="year_level_select">
            <option value="1" <?php echo $selected_year_level === 1 ? 'selected' : ''; ?>>1st Year</option>
            <option value="2" <?php echo $selected_year_level === 2 ? 'selected' : ''; ?>>2nd Year</option>
            <option value="3" <?php echo $selected_year_level === 3 ? 'selected' : ''; ?>>3rd Year</option>
            <option value="4" <?php echo $selected_year_level === 4 ? 'selected' : ''; ?>>4th Year</option>
            <option value="5" <?php echo $selected_year_level === 5 ? 'selected' : ''; ?>>5th Year</option>
        </select>
    </div>

    <div class="form-group">
        <label>Semester</label>
        <select name="semester" id="semester_select">
            <option value="1st Semester" <?php echo $selected_semester === '1st Semester' ? 'selected' : ''; ?>>1st Semester</option>
            <option value="2nd Semester" <?php echo $selected_semester === '2nd Semester' ? 'selected' : ''; ?>>2nd Semester</option>
            <option value="Summer" <?php echo $selected_semester === 'Summer' ? 'selected' : ''; ?>>Summer</option>
        </select>
    </div>

    <div class="form-group">
        <label>Number of Blocks</label>
        <select name="num_sections">
            <?php for ($i = 1; $i <= 10; $i++): ?>
                <?php $last_block = chr(64 + $i); ?>
                <option value="<?= $i ?>">
                    <?= $i ?> Block<?= $i > 1 ? 's' : '' ?> (A<?= $i > 1 ? '-' . $last_block : '' ?>)
                </option>
            <?php endfor; ?>
        </select>
    </div>

    <div class="form-group">
        <label>Program</label>
        <select name="program" id="program_select">
            <?php foreach ($program_options as $program_option): ?>
                <option value="<?php echo htmlspecialchars($program_option); ?>" <?php echo $selected_program === $program_option ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($program_option); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<!-- INSTRUCTORS -->
<div class="form-section">
    <h3>Select Instructors (<?php echo count($instructors); ?>)</h3>
    <div class="form-group">
        <label for="instructor_search">Search Instructor</label>
        <input type="text" id="instructor_search" placeholder="Type instructor name or department...">
    </div>

    <!-- Select All Checkbox -->
    <label class="checkbox-label">
        <input type="checkbox" id="select_all_instructors">
        <strong>Select All Instructors</strong>
    </label>

    <h4 style="margin-top:12px;"><?php echo htmlspecialchars($selected_program); ?> Instructors</h4>
    <div class="checkbox-grid">
        <?php foreach ($own_program_instructors as $i): ?>
        <?php
            $inst_id = (int)$i['id'];
            $all_assigned_codes = array_values(array_unique(array_map(function ($code) {
                return strtoupper(trim((string)$code));
            }, $instructor_subject_codes[$inst_id] ?? [])));
            $assigned_codes = array_values(array_filter($all_assigned_codes, function ($code) use ($available_subject_codes) {
                return $code !== '' && isset($available_subject_codes[$code]);
            }));
            $unavailable_codes = array_values(array_filter($all_assigned_codes, function ($code) use ($available_subject_codes) {
                return $code !== '' && !isset($available_subject_codes[$code]);
            }));
            $auto_select_instructor = !empty($assigned_codes);
        ?>
        <div class="instructor-card" data-search-text="<?php echo htmlspecialchars(strtolower($i['full_name'] . ' ' . $i['department'])); ?>" style="padding: 10px; border: 1px solid #dee2e6; border-radius: 6px;">
            <label>
                <input type="checkbox" class="instructor-checkbox" name="selected_instructors[]" value="<?= $i['id'] ?>" <?php echo $auto_select_instructor ? 'checked' : ''; ?>>
                <?= htmlspecialchars($i['full_name']) ?> (<?= $i['department'] ?>)
            </label>
            <div class="instructor-subject-map" data-instructor-id="<?php echo $inst_id; ?>" style="display:none; margin-top: 8px; padding-top: 8px; border-top: 1px dashed #ced4da;">
                <?php if (empty($assigned_codes) && empty($unavailable_codes)): ?>
                    <div class="form-hint">No assigned subjects configured for this instructor.</div>
                <?php else: ?>
                    <?php foreach ($assigned_codes as $code): ?>
                        <?php $label_name = $subject_name_map[$code] ?? ''; ?>
                        <label style="display:block; margin-bottom: 4px;">
                            <input type="checkbox" class="instructor-subject-checkbox" name="instructor_subject_map[<?php echo $inst_id; ?>][]" value="<?php echo htmlspecialchars($code); ?>" checked>
                            <?php echo htmlspecialchars($code . ($label_name ? ' - ' . $label_name : '')); ?>
                        </label>
                    <?php endforeach; ?>
                    <?php if (!empty($unavailable_codes)): ?>
                        <div class="form-hint" style="margin-top:6px; color:#6c757d;">
                            Assigned but unavailable for current semester/program:
                            <?php echo htmlspecialchars(implode(', ', $unavailable_codes)); ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <h4 style="margin-top:16px;">Cross Program Instructors</h4>
    <?php if (empty($cross_program_instructors)): ?>
        <p class="no-data">No cross program instructors found.</p>
    <?php else: ?>
        <div class="checkbox-grid">
            <?php foreach ($cross_program_instructors as $i): ?>
            <?php
                $inst_id = (int)$i['id'];
                $all_assigned_codes = array_values(array_unique(array_map(function ($code) {
                    return strtoupper(trim((string)$code));
                }, $instructor_subject_codes[$inst_id] ?? [])));
                $assigned_codes = array_values(array_filter($all_assigned_codes, function ($code) use ($available_subject_codes) {
                    return $code !== '' && isset($available_subject_codes[$code]);
                }));
                $unavailable_codes = array_values(array_filter($all_assigned_codes, function ($code) use ($available_subject_codes) {
                    return $code !== '' && !isset($available_subject_codes[$code]);
                }));
                $inst_program_label = $i['linked_program_name'] ?: 'Other Program';
                $auto_select_instructor = !empty($assigned_codes);
            ?>
            <div class="instructor-card" data-search-text="<?php echo htmlspecialchars(strtolower($i['full_name'] . ' ' . $i['department'] . ' ' . $inst_program_label)); ?>" style="padding: 10px; border: 1px solid #dee2e6; border-radius: 6px;">
                <label>
                    <input type="checkbox" class="instructor-checkbox" name="selected_instructors[]" value="<?= $i['id'] ?>" <?php echo $auto_select_instructor ? 'checked' : ''; ?>>
                    <?= htmlspecialchars($i['full_name']) ?> (<?= htmlspecialchars($i['department']) ?>) - <small><?php echo htmlspecialchars($inst_program_label); ?></small>
                </label>
                <div class="instructor-subject-map" data-instructor-id="<?php echo $inst_id; ?>" style="display:none; margin-top: 8px; padding-top: 8px; border-top: 1px dashed #ced4da;">
                    <?php if (empty($assigned_codes) && empty($unavailable_codes)): ?>
                        <div class="form-hint">No assigned subjects configured for this instructor.</div>
                    <?php else: ?>
                        <?php foreach ($assigned_codes as $code): ?>
                            <?php $label_name = $subject_name_map[$code] ?? ''; ?>
                            <label style="display:block; margin-bottom: 4px;">
                                <input type="checkbox" class="instructor-subject-checkbox" name="instructor_subject_map[<?php echo $inst_id; ?>][]" value="<?php echo htmlspecialchars($code); ?>" checked>
                                <?php echo htmlspecialchars($code . ($label_name ? ' - ' . $label_name : '')); ?>
                            </label>
                        <?php endforeach; ?>
                        <?php if (!empty($unavailable_codes)): ?>
                            <div class="form-hint" style="margin-top:6px; color:#6c757d;">
                                Assigned but unavailable for current semester/program:
                                <?php echo htmlspecialchars(implode(', ', $unavailable_codes)); ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- ROOMS -->
<div class="form-section">
    <h3>Select Rooms</h3>

    <!-- Select All Checkbox -->
    <label class="checkbox-label">
        <input type="checkbox" id="select_all_rooms">
        <strong>Select All Rooms</strong>
    </label>

    <div class="checkbox-grid">
        <?php foreach ($rooms as $r): ?>
        <label>
            <input type="checkbox" class="room-checkbox" name="selected_rooms[]" value="<?= $r['id'] ?>">
            <?= $r['room_number'] ?> (<?= $r['capacity'] ?>)
        </label>
        <?php endforeach; ?>
    </div>
</div>

<!-- CONSTRAINTS -->
<div class="form-section">
    <h3>Constraints & Preferences</h3>

    <div class="form-group">
        <label>Max Classes per Day</label>
        <input type="number" name="max_classes_per_day" value="4" min="1" max="8">
    </div>

    <div class="form-group">
        <label>Preferred Start Time</label>
        <select name="preferred_start_time">
            <option value="07:00">7:00 AM</option>
            <option value="08:00" selected>8:00 AM</option>
            <option value="09:00">9:00 AM</option>
        </select>
    </div>

    <div class="form-group checkbox">
        <label>
            <input type="checkbox" name="avoid_back_to_back" <?php echo $selected_avoid_back_to_back ? 'checked' : ''; ?>>
            Avoid back-to-back classes
        </label>
    </div>

    <div class="form-group checkbox">
        <label>
            <input type="checkbox" name="respect_availability" <?php echo $selected_respect_availability ? 'checked' : ''; ?>>
            Respect instructor availability
        </label>
    </div>

    <!-- 🔥 SATURDAY CONTROL -->
    <div class="form-group checkbox">
        <label>
            <input type="checkbox" name="allow_saturday" <?php echo $selected_allow_saturday ? 'checked' : ''; ?>>
            Allow Saturday (Make-up Classes Only)
        </label>
    </div>

    <div class="form-group mirror-pair">
        <label>Mirror Pair 1</label>
        <select name="mirror_pair1_day" style="width: 48%; display: inline-block; margin-right: 4%;">
            <option value="">Day</option>
            <option value="Monday" <?php echo $selected_mirror_pair1_day === 'Monday' ? 'selected' : ''; ?>>Monday</option>
            <option value="Tuesday" <?php echo $selected_mirror_pair1_day === 'Tuesday' ? 'selected' : ''; ?>>Tuesday</option>
            <option value="Wednesday" <?php echo $selected_mirror_pair1_day === 'Wednesday' ? 'selected' : ''; ?>>Wednesday</option>
            <option value="Thursday" <?php echo $selected_mirror_pair1_day === 'Thursday' ? 'selected' : ''; ?>>Thursday</option>
            <option value="Friday" <?php echo $selected_mirror_pair1_day === 'Friday' ? 'selected' : ''; ?>>Friday</option>
        </select>
        <select name="mirror_pair1_mirror" style="width: 48%; display: inline-block;">
            <option value="">→ Mirror</option>
            <option value="Monday" <?php echo $selected_mirror_pair1_mirror === 'Monday' ? 'selected' : ''; ?>>Monday</option>
            <option value="Tuesday" <?php echo $selected_mirror_pair1_mirror === 'Tuesday' ? 'selected' : ''; ?>>Tuesday</option>
            <option value="Wednesday" <?php echo $selected_mirror_pair1_mirror === 'Wednesday' ? 'selected' : ''; ?>>Wednesday</option>
            <option value="Thursday" <?php echo $selected_mirror_pair1_mirror === 'Thursday' ? 'selected' : ''; ?>>Thursday</option>
            <option value="Friday" <?php echo $selected_mirror_pair1_mirror === 'Friday' ? 'selected' : ''; ?>>Friday</option>
        </select>
    </div>
    
    <div class="form-group mirror-pair">
        <label>Mirror Pair 2</label>
        <select name="mirror_pair2_day" style="width: 48%; display: inline-block; margin-right: 4%;">
            <option value="">Day</option>
            <option value="Monday" <?php echo $selected_mirror_pair2_day === 'Monday' ? 'selected' : ''; ?>>Monday</option>
            <option value="Tuesday" <?php echo $selected_mirror_pair2_day === 'Tuesday' ? 'selected' : ''; ?>>Tuesday</option>
            <option value="Wednesday" <?php echo $selected_mirror_pair2_day === 'Wednesday' ? 'selected' : ''; ?>>Wednesday</option>
            <option value="Thursday" <?php echo $selected_mirror_pair2_day === 'Thursday' ? 'selected' : ''; ?>>Thursday</option>
            <option value="Friday" <?php echo $selected_mirror_pair2_day === 'Friday' ? 'selected' : ''; ?>>Friday</option>
        </select>
        <select name="mirror_pair2_mirror" style="width: 48%; display: inline-block;">
            <option value="">→ Mirror</option>
            <option value="Monday" <?php echo $selected_mirror_pair2_mirror === 'Monday' ? 'selected' : ''; ?>>Monday</option>
            <option value="Tuesday" <?php echo $selected_mirror_pair2_mirror === 'Tuesday' ? 'selected' : ''; ?>>Tuesday</option>
            <option value="Wednesday" <?php echo $selected_mirror_pair2_mirror === 'Wednesday' ? 'selected' : ''; ?>>Wednesday</option>
            <option value="Thursday" <?php echo $selected_mirror_pair2_mirror === 'Thursday' ? 'selected' : ''; ?>>Thursday</option>
            <option value="Friday" <?php echo $selected_mirror_pair2_mirror === 'Friday' ? 'selected' : ''; ?>>Friday</option>
        </select>
    </div>
    

    <div class="form-group" id="non_mirror_day_group" style="display: none;">
        <label id="non_mirror_label">Non-Mirror Day:</label>
        <select name="non_mirror_mode" style="flex: 1;">
            <option value="1" <?php echo $selected_non_mirror_mode === '1' ? 'selected' : ''; ?>>1 subject per block</option>
            <option value="0" <?php echo $selected_non_mirror_mode === '0' ? 'selected' : ''; ?>>No class</option>
        </select>
    </div>


</div>

<div class="form-actions">
    <button type="submit" name="generate_schedule" class="btn-primary">
        Generate Schedule
    </button>
    <a href="dashboard.php" class="btn-secondary">Cancel</a>
</div>

</form>

</div>

<!-- JavaScript for Select All functionality -->
<script>
document.getElementById('select_all_instructors').addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.instructor-checkbox');
    checkboxes.forEach(cb => cb.checked = this.checked);
    syncInstructorSubjectVisibility();
});

document.getElementById('select_all_rooms').addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.room-checkbox');
    checkboxes.forEach(cb => cb.checked = this.checked);
});

function syncInstructorSubjectVisibility() {
    const selected = new Set(
        Array.from(document.querySelectorAll('.instructor-checkbox:checked')).map(cb => cb.value)
    );
    document.querySelectorAll('.instructor-subject-map').forEach(block => {
        const id = block.getAttribute('data-instructor-id');
        block.style.display = selected.has(id) ? 'block' : 'none';
    });
}

document.querySelectorAll('.instructor-checkbox').forEach(cb => {
    cb.addEventListener('change', syncInstructorSubjectVisibility);
});

const instructorSearchInput = document.getElementById('instructor_search');
if (instructorSearchInput) {
    instructorSearchInput.addEventListener('input', function () {
        const query = this.value.trim().toLowerCase();
        document.querySelectorAll('.instructor-card').forEach(card => {
            const text = card.getAttribute('data-search-text') || '';
            card.style.display = text.includes(query) ? 'block' : 'none';
        });
    });
}

const semesterSelect = document.getElementById('semester_select');
function buildFilterUrl() {
    const url = new URL(window.location.href);
    const semesterSelectEl = document.getElementById('semester_select');
    const programSelectEl = document.getElementById('program_select');
    const yearLevelSelectEl = document.getElementById('year_level_select');
    const scheduleModeSelectEl = document.getElementById('schedule_mode_select');

    if (semesterSelectEl) {
        url.searchParams.set('semester', semesterSelectEl.value);
    }
    if (programSelectEl) {
        url.searchParams.set('program', programSelectEl.value);
    }
    if (yearLevelSelectEl) {
        url.searchParams.set('year_level', yearLevelSelectEl.value);
    }
    if (scheduleModeSelectEl) {
        url.searchParams.set('schedule_mode', scheduleModeSelectEl.value);
    }

    return url;
}

if (semesterSelect) {
    semesterSelect.addEventListener('change', function () {
        window.location.href = buildFilterUrl().toString();
    });
}

const programSelect = document.getElementById('program_select');
if (programSelect) {
    programSelect.addEventListener('change', function () {
        window.location.href = buildFilterUrl().toString();
    });
}

const yearLevelSelect = document.getElementById('year_level_select');
if (yearLevelSelect) {
    yearLevelSelect.addEventListener('change', function () {
        window.location.href = buildFilterUrl().toString();
    });
}

syncInstructorSubjectVisibility();

const scheduleModeSelect = document.getElementById('schedule_mode_select');
const yearLevelGroup = document.getElementById('year_level_group');

if (scheduleModeSelect) {
    scheduleModeSelect.addEventListener('change', function () {
        window.location.href = buildFilterUrl().toString();
    });

    // Initial check
    if (scheduleModeSelect.value !== 'single') {
        yearLevelGroup.style.display = 'none';
    }
}

    function updateNonMirrorDay() {
        const pair1Day = document.querySelector('[name="mirror_pair1_day"]').value;
        const pair1Mirror = document.querySelector('[name="mirror_pair1_mirror"]').value;
        const pair2Day = document.querySelector('[name="mirror_pair2_day"]').value;
        const pair2Mirror = document.querySelector('[name="mirror_pair2_mirror"]').value;
        
        const days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        const usedDays = new Set();
        
        if (pair1Day) usedDays.add(pair1Day);
        if (pair1Mirror) usedDays.add(pair1Mirror);
        if (pair2Day) usedDays.add(pair2Day);
        if (pair2Mirror) usedDays.add(pair2Mirror);
        
        const nonMirrorGroup = document.getElementById('non_mirror_day_group');
        const nonMirrorLabel = document.getElementById('non_mirror_label');
        
        const remainingDays = days.filter(day => !usedDays.has(day));
        if (remainingDays.length === 1) {
            nonMirrorLabel.textContent = remainingDays[0] + ':';
            nonMirrorGroup.style.display = 'flex';
        } else {
            nonMirrorGroup.style.display = 'none';
        }
    }

    // Listen for mirror pair changes
    ['mirror_pair1_day', 'mirror_pair1_mirror', 'mirror_pair2_day', 'mirror_pair2_mirror'].forEach(name => {
        const el = document.querySelector('[name="' + name + '"]');
        if (el) el.addEventListener('change', updateNonMirrorDay);
    });

    // Initial check
    updateNonMirrorDay();
</script>

</body>
</html>

