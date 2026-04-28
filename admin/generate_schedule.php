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

function classifyRoomBucket(array $room): string {
    $roomType = strtolower(trim((string)($room['room_type'] ?? '')));
    $roomNumber = strtolower(trim((string)($room['room_number'] ?? '')));
    $building = strtolower(trim((string)($room['building'] ?? '')));
    $searchText = $roomType . ' ' . $roomNumber . ' ' . $building;

    if (
        strpos($searchText, 'network') !== false ||
        strpos($searchText, 'cisco') !== false ||
        strpos($searchText, 'router') !== false
    ) {
        return 'Networking Room';
    }

    if (
        strpos($roomType, 'lab') !== false ||
        strpos($searchText, 'laboratory') !== false ||
        strpos($searchText, 'laboratory room') !== false ||
        strpos($searchText, 'computer lab') !== false ||
        (int)($room['has_computers'] ?? 0) === 1
    ) {
        return 'Laboratory Room';
    }

    return 'Lecture Room';
}

$program_options = ['Computer Science', 'Information Technology', 'Computer Engineering'];
$selected_semester = normalizeSemester($_POST['semester'] ?? $_GET['semester'] ?? '1st Semester');
$selected_program = normalizeProgramSelection($_POST['program'] ?? $_GET['program'] ?? 'Computer Science');
$selected_year_level = normalizeYearLevelSelection($_POST['year_level'] ?? $_GET['year_level'] ?? 1);
$selected_job_name = trim((string)($_POST['job_name'] ?? $_GET['job_name'] ?? ''));
if ($selected_job_name === '') {
    $selected_job_name = 'Schedule Generation ' . date('Y-m-d H:i:s');
}
$selected_fast_paired_day_enabled = isset($_POST['preferred_day_enabled']) || isset($_POST['mirror_enabled']);
$selected_fast_paired_day_mode = strtolower(trim((string)($_POST['preferred_day_mode'] ?? 'strict')));
if (!in_array($selected_fast_paired_day_mode, ['soft', 'strict'], true)) {
    $selected_fast_paired_day_mode = 'strict';
}
$selected_individual_weekdays_enabled = isset($_POST['weekday_mode_enabled']);
$selected_allow_saturday = isset($_POST['allow_saturday']);
$selected_avoid_back_to_back = isset($_POST['avoid_back_to_back']);

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
$room_groups = [
    'Lecture Room' => [],
    'Laboratory Room' => [],
    'Networking Room' => [],
];
foreach ($rooms as $room) {
    $bucket = classifyRoomBucket($room);
    $room_groups[$bucket][] = $room;
}
$selected_room_ids = array_map('intval', $_POST['selected_rooms'] ?? []);
$subject_columns = [];
foreach ($pdo->query("SHOW COLUMNS FROM subjects")->fetchAll(PDO::FETCH_ASSOC) as $col) {
    $subject_columns[$col['Field']] = true;
}
$has_subject_semester = isset($subject_columns['semester']);
if ($has_subject_semester && isset($subject_columns['year_level'])) {
    $year_levels_to_fetch = [$selected_year_level];
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
    if (isAllProgramsSubject($subject)) return true;
    $department_match = normalizeProgramAlias($subject['department'] ?? '');
    $linked_program_match = normalizeProgramAlias($subject['linked_program_name'] ?? '');
    return $department_match === $selected_program || $linked_program_match === $selected_program;
}));
$subjects = preferScopedSubjects($subjects);

$subject_name_map = [];
foreach ($subjects as $s) {
    $subject_name_map[strtoupper(trim($s['subject_code']))] = $s['subject_name'];
}
$subject_by_code = [];
foreach ($subjects as $s) {
    $subject_by_code[strtoupper(trim($s['subject_code']))] = $s;
}
$available_subject_codes = array_fill_keys(array_keys($subject_by_code), true);

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
        if ($code === '') continue;
        if (!isset($instructor_subject_codes[$inst_id])) $instructor_subject_codes[$inst_id] = [];
        if (!in_array($code, $instructor_subject_codes[$inst_id], true)) {
            $instructor_subject_codes[$inst_id][] = $code;
        }
    }
} catch (Exception $e) {}

try {
    $stmt = $pdo->query("
        SELECT ism.instructor_id, s.specialization_name
        FROM instructor_specializations ism
        JOIN specializations s ON ism.specialization_id = s.id
        ORDER BY ism.instructor_id, ism.priority
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $inst_id = (int)$row['instructor_id'];
        $code = strtoupper(trim((string) $row['specialization_name']));
        if ($code === '') continue;
        if (!isset($instructor_subject_codes[$inst_id])) $instructor_subject_codes[$inst_id] = [];
        if (!in_array($code, $instructor_subject_codes[$inst_id], true)) {
            $instructor_subject_codes[$inst_id][] = $code;
        }
    }
} catch (Exception $e) {}

$cross_program_instructors = array_values(array_filter($cross_program_instructors, function ($inst) use ($instructor_subject_codes, $available_subject_codes) {
    $inst_id = (int)($inst['id'] ?? 0);
    foreach (($instructor_subject_codes[$inst_id] ?? []) as $code) {
        $normalized = strtoupper(trim((string)$code));
        if ($normalized !== '' && isset($available_subject_codes[$normalized])) {
            return true;
        }
    }
    return false;
}));

$all_time_slots = $pdo->query("
    SELECT * FROM time_slots
    ORDER BY FIELD(day,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'), start_time
")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['generate_schedule'])) {
        if ($activeJobSummary !== '') {
            $error = $activeJobSummary . ' Please wait for it to finish before generating a new schedule.';
        } else {
            $job_name = $_POST['job_name'] ?? 'Schedule Generation ' . date('Y-m-d H:i:s');
            $year_levels_to_schedule = [(int)($_POST['year_level'] ?? 1)];
            $num_sections = max(1, min(10, (int)($_POST['num_sections'] ?? 1)));

            $allow_saturday = isset($_POST['allow_saturday']);
            $fast_paired_day_enabled = isset($_POST['preferred_day_enabled']) || isset($_POST['mirror_enabled']);
            $individual_weekdays_enabled = isset($_POST['weekday_mode_enabled']);
            if ($individual_weekdays_enabled) {
                $fast_paired_day_enabled = false;
            }
            $fast_paired_day_mode = strtolower(trim((string)($_POST['preferred_day_mode'] ?? 'strict')));
            if (!in_array($fast_paired_day_mode, ['soft', 'strict'], true)) {
                $fast_paired_day_mode = 'strict';
            }
            $preferred_day_pairs = $fast_paired_day_enabled ? [
                ['day' => 'Monday', 'mirror' => 'Thursday'],
                ['day' => 'Tuesday', 'mirror' => 'Friday'],
            ] : [];
            $mirror_pairs = $preferred_day_pairs;
            $four_day_pattern = !$individual_weekdays_enabled && !empty($mirror_pairs);
            // Keep the solver's default Wednesday/non-mirror behavior enabled.
            $non_mirror_mode = 1;

            $filtered_time_slots = [];
            foreach ($all_time_slots as $ts) {
                if (strtolower($ts['day']) === 'saturday' && !$allow_saturday) continue;
                $filtered_time_slots[] = $ts;
            }

            $input_data = [
                'year_level' => $year_levels_to_schedule,
                'schedule_mode' => 'single',
                'num_sections' => $num_sections,
                'semester' => $selected_semester,
                'program' => $selected_program,
                'instructors' => [],
                'rooms' => [],
                'subjects' => [],
                'time_slots' => $filtered_time_slots,
                'constraints' => [
                    'max_classes_per_day' => $_POST['max_classes_per_day'] ?? 4,
                    'preferred_start_time' => $_POST['preferred_start_time'] ?? '08:00',
                    'avoid_back_to_back' => isset($_POST['avoid_back_to_back']),
                    'allow_saturday' => $allow_saturday,
                    'mirror_mode' => $four_day_pattern ? 'strict' : 'none',
                    'four_day_pattern' => $four_day_pattern,
                    'mirror_pairs' => $mirror_pairs,
                    'non_mirror_mode' => $non_mirror_mode,
                    'day_grouping_mode' => $individual_weekdays_enabled ? 'individual' : ($four_day_pattern ? 'paired' : 'standard'),
                    'individual_weekdays' => $individual_weekdays_enabled,
                    // Strict preferred-day mode must not fall back to non-mirrored placement.
                    'allow_non_mirror_fallback' => $four_day_pattern ? ($fast_paired_day_mode !== 'strict') : false,
                    'preferred_day_enabled' => $fast_paired_day_enabled,
                    'preferred_day_mode' => $fast_paired_day_enabled ? $fast_paired_day_mode : 'none',
                    'preferred_day_pairs' => $preferred_day_pairs,
                ]
            ];

            $selected_instructor_ids = array_map('intval', $_POST['selected_instructors'] ?? []);
            $selected_instructor_id_set = array_fill_keys($selected_instructor_ids, true);

            $approved_overload_hours = [];
            if (!empty($selected_instructor_ids)) {
                try {
                    $placeholders = implode(',', array_fill(0, count($selected_instructor_ids), '?'));
                    $approvalStmt = $pdo->prepare("
                        SELECT oa.instructor_id, oa.approved_hours
                        FROM instructor_overload_approvals oa
                        JOIN (SELECT instructor_id, MAX(created_at) latest FROM instructor_overload_approvals WHERE instructor_id IN ($placeholders) GROUP BY instructor_id) latest
                        ON oa.instructor_id = latest.instructor_id AND oa.created_at = latest.latest
                    ");
                    $approvalStmt->execute($selected_instructor_ids);
                    foreach ($approvalStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $approved_overload_hours[(int)$row['instructor_id']] = (float)$row['approved_hours'];
                    }
                } catch (Exception $e) {}
            }

            $input_data['instructor_subject_map'] = [];
            foreach ($selected_instructor_ids as $inst_id) {
                $raw_codes = $_POST['instructor_subject_map'][$inst_id] ?? [];
                $codes = array_filter(array_map(fn($c) => strtoupper(trim((string)$c)), $raw_codes), fn($code) => isset($available_subject_codes[$code]));
                if (!empty($codes)) {
                    $input_data['instructor_subject_map'][(string)$inst_id] = array_values($codes);
                }
            }

            $mapped_instructor_id_set = array_fill_keys(array_map('intval', array_keys($input_data['instructor_subject_map'])), true);
            foreach ($instructors as $inst) {
                $iid = (int)$inst['id'];
                if (isset($selected_instructor_id_set[$iid]) && isset($mapped_instructor_id_set[$iid])) {
                    $inst['max_hours_per_week'] = $approved_overload_hours[$iid] ?? $inst['max_hours_per_week'] ?? 0;
                    $input_data['instructors'][] = $inst;
                }
            }

            $selected_room_ids = array_map('intval', $_POST['selected_rooms'] ?? []);
            $selected_room_id_set = array_fill_keys($selected_room_ids, true);
            if (!empty($selected_room_id_set)) {
                foreach ($rooms as $room) {
                    if (isset($selected_room_id_set[(int)$room['id']])) {
                        $input_data['rooms'][] = $room;
                    }
                }
            }

            $selected_subject_code_map = [];
            foreach ($input_data['instructor_subject_map'] as $codes) {
                foreach ($codes as $code) {
                    $selected_subject_code_map[$code] = true;
                }
            }
            foreach (array_keys($selected_subject_code_map) as $code) {
                if (isset($subject_by_code[$code])) $input_data['subjects'][] = $subject_by_code[$code];
            }

            if (empty($input_data['subjects'])) {
                $error = "No valid subjects found for selected instructor assignments under {$selected_semester}. Please check semester and subject assignments.";
            }

            if (empty($error)) {
                $stmt = $pdo->prepare("INSERT INTO schedule_jobs (job_name, status, created_by, input_data) VALUES (?, 'pending', ?, ?)");
                $stmt->execute([$job_name, $_SESSION['user_id'], json_encode($input_data)]);
                $job_id = $pdo->lastInsertId();

                $script_path = realpath(PYTHON_SCRIPT_PATH) ?: PYTHON_SCRIPT_PATH;
                $python_path = PYTHON_PATH;
                if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                    $log_dir = __DIR__ . '/../logs';
                    @mkdir($log_dir, 0777, true);
                    $log_file = $log_dir . '/job_' . $job_id . '.log';
                    $command = 'start "" /B "' . str_replace('/', '\\', $python_path) . '" -u "' . str_replace('/', '\\', $script_path) . '" ' . $job_id . ' > "' . str_replace('/', '\\', $log_file) . '" 2>&1';
                    pclose(popen($command, "r"));
                } else {
                    exec(PYTHON_PATH . ' ' . escapeshellarg($script_path) . ' ' . $job_id . ' > /dev/null 2>&1 &');
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
    <style>
        /* Compact No-Scroll Design */
        .collapsible-section{margin-bottom:1.5rem}.collapsible-section summary{font-size:1.25rem;font-weight:700;padding:1rem;cursor:pointer;background:linear-gradient(135deg,#f8fafc,#e2e8f0);border-radius:.75rem;border:1px solid #e2e8f0}.collapsible-section summary:hover{background:linear-gradient(135deg,#f1f5f9,#e2e8f0)}.collapsible-section[open] summary{border-radius:.75rem .75rem 0 0}.form-section{border-radius:0 0 .75rem .75rem;border-top:none;margin:0;background:#fff;padding:1.5rem}.checkbox-row{display:flex;align-items:flex-start;gap:.75rem;padding:.625rem;border-radius:.5rem;cursor:pointer;font-size:.9375rem;margin-bottom:.25rem;transition:background .2s ease}.checkbox-row:hover{background:#f8fafc}input[type=checkbox]{transform:scale(1.2);margin:0;flex-shrink:0}.search-box{width:100%;padding:.625rem .75rem;border:1px solid #d1d5db;border-radius:.5rem;margin-bottom:.75rem;font-size:.9375rem}.count-text{font-size:.875rem;color:#64748b;margin-top:.375rem}.sticky-bar{position:sticky;bottom:1.25rem;background:#fff;padding:1.5rem;border-radius:1rem;box-shadow:0 -10px 40px rgba(0,0,0,.1);display:flex;gap:1rem;justify-content:center;margin-top:1.5rem;border:1px solid #e5e7eb;backdrop-filter:blur(20px)}@media (max-width:48rem){.sticky-bar{flex-direction:column;bottom:0;margin:1.5rem -1.25rem 0;border-radius:0}}.subject-group{margin-top:.5rem;padding-top:.5rem;border-top:1px solid #e5e7eb;font-size:.875rem;max-height:6rem;overflow-y:auto;padding-right:.25rem}.subject-row{padding:.375rem .5rem;border-radius:.25rem}.form-group{display:flex;flex-direction:column;gap:.375rem;margin-bottom:1rem}.form-group label{font-weight:600;font-size:.9375rem}.form-group input,.form-group select{padding:.75rem;border:1px solid #d1d5db;border-radius:.5rem;font-size:.9375rem}.rooms-table-wrap{overflow-x:auto;border:1px solid #e5e7eb;border-radius:.875rem;background:#fafbfc}.rooms-table{width:100%;border-collapse:separate;border-spacing:0;min-width:48rem}.rooms-table th,.rooms-table td{vertical-align:top;padding:1rem;border-bottom:1px solid #e5e7eb}.rooms-table th{background:#f8fafc;font-size:.95rem;text-align:left;color:#0f172a}.rooms-table td+td,.rooms-table th+th{border-left:1px solid #e5e7eb}.rooms-group-title{display:flex;align-items:center;justify-content:space-between;gap:.5rem}.rooms-badge{display:inline-flex;align-items:center;justify-content:center;min-width:1.75rem;padding:.2rem .45rem;border-radius:999px;background:#e2e8f0;color:#334155;font-size:.75rem;font-weight:700}.room-option-row{display:flex;align-items:flex-start;gap:.65rem;padding:.65rem 0;border-bottom:1px dashed #e2e8f0}.room-option-row:last-child{border-bottom:none}.room-option-meta{display:flex;flex-direction:column;gap:.15rem}.room-option-name{font-weight:600;color:#0f172a}.room-option-sub{font-size:.82rem;color:#64748b}.room-empty{font-size:.9rem;color:#94a3b8;font-style:italic;padding:.35rem 0}.room-column{min-width:15rem}.room-column[hidden]{display:none!important}.inst-option-row{display:flex;align-items:flex-start;gap:.65rem;padding:.65rem 0;border-bottom:1px dashed #e2e8f0}.inst-option-row:last-child{border-bottom:none}.inst-option-meta{display:flex;flex-direction:column;gap:.15rem;flex:1}.inst-option-name{display:flex;align-items:center;gap:.45rem;flex-wrap:wrap;font-weight:600;color:#0f172a}.inst-option-sub{font-size:.82rem;color:#64748b}.inst-program-tag{display:inline-flex;align-items:center;padding:.16rem .45rem;border-radius:999px;background:#dbeafe;color:#1d4ed8;font-size:.74rem;font-weight:700}.inst-subject-count{font-size:.8rem;color:#059669;font-weight:600}.inst-column{min-width:22rem}.inst-column[hidden]{display:none!important}@media (max-width:48rem){.rooms-table{min-width:0}.rooms-table thead{display:none}.rooms-table,.rooms-table tbody,.rooms-table tr,.rooms-table td{display:block;width:100%}.rooms-table tr{border-bottom:1px solid #e5e7eb}.rooms-table td{border-left:none!important}.room-column,.inst-column{min-width:0}.room-column::before,.inst-column::before{content:attr(data-title);display:block;font-weight:700;color:#334155;margin-bottom:.5rem}}
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
<h2>Generate Schedule - No Scroll Checkbox UI</h2>

<?php if ($message): ?><div class="success"><?php echo $message; ?></div><?php endif; ?>
<?php if ($error): ?><div class="error"><?php echo $error; ?></div><?php endif; ?>
<?php if ($activeJobSummary): ?><div class="error"><?php echo htmlspecialchars($activeJobSummary); ?> Please wait.</div><?php endif; ?>

<form method="POST">

<details class="collapsible-section" open>
<summary>📋 Job Information</summary>
<div class="form-section">
<div class="form-group">
<label>Job Name</label>
<input type="text" name="job_name" value="<?php echo htmlspecialchars($selected_job_name); ?>" required>
</div>
<div class="form-group">
<label>Year Level</label>
<select name="year_level" id="year_level">
<option value="1" <?php echo $selected_year_level==1?'selected':'';?>>1st Year</option>
<option value="2" <?php echo $selected_year_level==2?'selected':'';?>>2nd Year</option>
<option value="3" <?php echo $selected_year_level==3?'selected':'';?>>3rd Year</option>
<option value="4" <?php echo $selected_year_level==4?'selected':'';?>>4th Year</option>
<option value="5" <?php echo $selected_year_level==5?'selected':'';?>>5th Year</option>
</select>
</div>
<div class="form-group">
<label>Semester</label>
<select name="semester" id="semester">
<option value="1st Semester" <?php echo $selected_semester=='1st Semester'?'selected':'';?>>1st Semester</option>
<option value="2nd Semester" <?php echo $selected_semester=='2nd Semester'?'selected':'';?>>2nd Semester</option>
<option value="Summer" <?php echo $selected_semester=='Summer'?'selected':'';?>>Summer</option>
</select>
</div>
<div class="form-group">
<label>Number of Blocks</label>
<select name="num_sections">
<?php for($i=1;$i<=10;$i++): $last=chr(64+$i);?>
<option value="<?php echo $i;?>"><?php echo $i;?> Block<?php echo $i>1?'s':'';?> (A<?php echo $i>1?'-'.$last:'';?>)</option>
<?php endfor;?>
</select>
</div>
<div class="form-group">
<label>Program</label>
<select name="program" id="program">
<?php foreach($program_options as $p):?>
<option value="<?php echo htmlspecialchars($p);?>" <?php echo $selected_program===$p?'selected':'';?>><?php echo htmlspecialchars($p);?></option>
<?php endforeach;?>
</select>
</div>
</div>
</details>

<details class="collapsible-section">
<summary>👥 Instructors (<?php echo count($own_program_instructors)+count($cross_program_instructors);?>)</summary>
<div class="form-section">
<input type="text" id="instructorSearch" class="search-box" placeholder="Search instructors...">
<div class="rooms-table-wrap">
<table class="rooms-table" id="instructorList">
<thead>
<tr>
<th>Selected Program Instructor</th>
<th>Other Program Instructor</th>
</tr>
</thead>
<tbody>
<tr>
<?php
$instructor_groups = [
    'Selected Program Instructor' => $own_program_instructors,
    'Other Program Instructor' => $cross_program_instructors,
];
foreach($instructor_groups as $group_name => $group_instructors):
?>
<td class="inst-column" data-title="<?php echo htmlspecialchars($group_name); ?>">
<div class="rooms-group-title">
<span><?php echo htmlspecialchars($group_name); ?></span>
 <div style="display: flex; gap: 8px; align-items: center;">
 <label style="font-size: 11px; display: flex; align-items: center; gap: 4px; cursor: pointer; color: #1d4ed8; font-weight: 700; white-space: nowrap;">
 Select All <input type="checkbox" class="header-select-all" style="transform: scale(1.1); margin: 0;"></label>
<span class="rooms-badge"><?php echo count($group_instructors); ?></span>
</div>
</div>
<?php if (empty($group_instructors)): ?>
<div class="room-empty">No instructors found in this group.</div>
<?php else: ?>
<?php foreach($group_instructors as $i):
$inst_id = (int)$i['id'];
$subjects = array_values(array_unique(array_map(function ($code) {
    return strtoupper(trim((string)$code));
}, $instructor_subject_codes[$inst_id] ?? [])));
$valid_subjects = array_values(array_filter($subjects, fn($c) => $c !== '' && isset($available_subject_codes[$c])));
$auto = !empty($valid_subjects);
$disabled = empty($valid_subjects);
$inst_program = trim((string)($i['linked_program_name'] ?: $i['department']));
$inst_search = strtolower(trim(
    (string)($i['full_name'] ?? '') . ' ' .
    (string)($i['department'] ?? '') . ' ' .
    (string)$inst_program . ' ' .
    (string)$group_name
));
?>
<div class="inst-option-row checkbox-row" data-search="<?php echo htmlspecialchars($inst_search); ?>">
<input type="checkbox" name="selected_instructors[]" value="<?php echo $inst_id;?>" class="inst-cb" <?php echo $auto ? 'checked' : ''; ?> <?php echo $disabled ? 'disabled' : ''; ?>>
<span class="inst-option-meta">
<span class="inst-option-name">
<?php echo htmlspecialchars($i['full_name']);?>
<?php if ($group_name === 'Other Program Instructor' && $inst_program !== ''): ?>
<span class="inst-program-tag"><?php echo htmlspecialchars($inst_program); ?></span>
<?php endif; ?>
</span>
<span class="inst-option-sub"><?php echo htmlspecialchars((string)($i['department'] ?: 'No Department')); ?></span>
<?php if($auto):?><span class="inst-subject-count"><?php echo count($valid_subjects);?> matching subjects</span><?php endif;?>
<?php if($disabled):?><span class="inst-option-sub">No matching subjects for the current semester/program.</span><?php endif;?>
</span>
</div>
<?php if($auto):?>
<div class="subject-group" data-for="<?php echo $inst_id;?>" style="display:<?php echo $auto?'block':'none';?>">
<?php foreach($valid_subjects as $code):
$label = $subject_name_map[$code]??'';
?>
<div class="checkbox-row subject-row">
<input type="checkbox" name="instructor_subject_map[<?php echo $inst_id;?>][]" value="<?php echo htmlspecialchars($code);?>" checked>
<span><?php echo htmlspecialchars($code.($label?' - '.$label:''));?></span>
</div>
<?php endforeach;?>
</div>
<?php endif;?>
<?php endforeach;?>
<?php endif; ?>
</td>
<?php endforeach;?>
</tr>
</tbody>
</table>
</div>
<div class="count-text" id="instCount">0 selected</div>
</div>
</details>

<details class="collapsible-section">
<summary>🏠 Rooms (<?php echo count($rooms);?>)</summary>
<div class="form-section">
<input type="text" id="roomSearch" class="search-box" placeholder="Search rooms...">
<div class="rooms-table-wrap">
<table class="rooms-table" id="roomList">
<thead>
<tr>
<th>Lecture Room</th>
<th>Laboratory Room</th>
<th>Networking Room</th>
</tr>
</thead>
<tbody>
<tr>
<?php foreach($room_groups as $group_name => $group_rooms):?>
<td class="room-column" data-title="<?php echo htmlspecialchars($group_name); ?>">
<div class="rooms-group-title">
<span><?php echo htmlspecialchars($group_name); ?></span>
 <div style="display: flex; gap: 8px; align-items: center;">
 <label style="font-size: 11px; display: flex; align-items: center; gap: 4px; cursor: pointer; color: #1d4ed8; font-weight: 700; white-space: nowrap;">
 Select All <input type="checkbox" class="header-select-all" style="transform: scale(1.1); margin: 0;"></label>
<span class="rooms-badge"><?php echo count($group_rooms); ?></span>
</div>
</div>
<?php if (empty($group_rooms)): ?>
<div class="room-empty">No rooms found in this group.</div>
<?php else: ?>
<?php foreach($group_rooms as $room):?>
<?php
$room_id = (int)$room['id'];
$room_search = strtolower(trim(
    (string)$group_name . ' ' .
    (string)($room['room_number'] ?? '') . ' ' .
    (string)($room['building'] ?? '')
));
?>
<label class="room-option-row" data-search="<?php echo htmlspecialchars($room_search); ?>">
<input type="checkbox" name="selected_rooms[]" value="<?php echo $room_id;?>" class="room-cb" <?php echo in_array($room_id, $selected_room_ids, true) ? 'checked' : ''; ?>>
<span class="room-option-meta">
<span class="room-option-name"><?php echo htmlspecialchars((string)$room['room_number']);?></span>
<span class="room-option-sub"><?php echo htmlspecialchars((string)($room['building'] ?: 'No Building'));?> | <?php echo (int)$room['capacity'];?> seats</span>
</span>
</label>
<?php endforeach;?>
<?php endif; ?>
</td>
<?php endforeach;?>
</tr>
</tbody>
</table>
</div>
<div class="count-text" id="roomCount">0 selected</div>
</div>
</details>

<details class="collapsible-section">
<summary>⚙️ Constraints</summary>
<div class="form-section">
<div class="form-group">
<label>Max Classes/Day</label>
<input type="number" name="max_classes_per_day" value="4" min="1" max="8">
</div>
<div class="form-group">
<label>Preferred Start</label>
<select name="preferred_start_time">
<option value="07:00">7AM</option>
<option value="08:00" selected>8AM</option>
<option value="09:00">9AM</option>
</select>
</div>
<label class="checkbox-row"><input type="checkbox" name="avoid_back_to_back" <?php echo $selected_avoid_back_to_back?'checked':'';?>>
Avoid back-to-back</label>
<label class="checkbox-row"><input type="checkbox" name="allow_saturday" <?php echo $selected_allow_saturday?'checked':'';?>>
Saturday makeups</label>
<div style="display:flex;gap:1rem;flex-wrap:wrap">
<label class="checkbox-row"><input type="checkbox" id="fast_paired_day_cb" name="preferred_day_enabled" <?php echo $selected_fast_paired_day_enabled?'checked':'';?>>
Fast Paired Day</label>
<label class="checkbox-row"><input type="checkbox" id="weekday_cb" name="weekday_mode_enabled" <?php echo $selected_individual_weekdays_enabled?'checked':'';?>>
Mon-Fri only</label>
</div>
<div class="form-group" id="fast_paired_day_mode_group" <?php echo $selected_fast_paired_day_enabled ? '' : 'hidden'; ?>>
<label for="fast_paired_day_mode">Fast Paired Day Handling</label>
<select name="preferred_day_mode" id="fast_paired_day_mode">
<option value="soft" <?php echo $selected_fast_paired_day_mode==='soft'?'selected':''; ?>>Soft - try Mon/Thu and Tue/Fri first, then allow fallback</option>
<option value="strict" <?php echo $selected_fast_paired_day_mode==='strict'?'selected':''; ?>>Strict - only use Mon/Thu and Tue/Fri pairing</option>
</select>
</div>
<div class="form-group" id="fast_paired_day_help" <?php echo $selected_fast_paired_day_enabled ? '' : 'hidden'; ?>>
<div style="padding:10px 12px;background:#f8f9fa;border:1px solid #d8dee6;border-radius:8px;">
Uses fast paired-day anchor generation that mirrors Monday/Tuesday placements into Thursday/Friday and repairs room mismatches during generation.
</div>
</div>
</div>
</details>

<div class="sticky-bar">
<button type="submit" name="generate_schedule" class="btn-primary" style="padding:1rem 2rem;font-size:1.125rem;font-weight:700">
🚀 Generate Schedule
</button>
<a href="dashboard.php" class="btn-secondary" style="padding:1rem 1.5rem">Cancel</a>
</div>

</form>
</div>

<script>
document.addEventListener('DOMContentLoaded',()=>{
    const isRowVisible=(row)=>!!row && !row.hidden && row.offsetParent !== null;
    const getColumnCheckboxes=(column)=>{
        if(!column) return [];
        if(column.classList.contains('inst-column')) return Array.from(column.querySelectorAll('.inst-cb')).filter(cb=>!cb.disabled);
        if(column.classList.contains('room-column')) return Array.from(column.querySelectorAll('.room-cb')).filter(cb=>!cb.disabled);
        return Array.from(column.querySelectorAll('.inst-cb, .room-cb')).filter(cb=>!cb.disabled);
    };

    const updateCount=()=>{
        document.getElementById('instCount').textContent=`${document.querySelectorAll('.inst-cb:checked').length} selected`;
        document.getElementById('roomCount').textContent=`${document.querySelectorAll('.room-cb:checked').length} selected`;
    };

    const syncColumnHeaderState=(column)=>{
        if(!column) return;
        const header=column.querySelector('.header-select-all');
        if(!header) return;
        const visibleCbs=getColumnCheckboxes(column).filter(cb=>{
            const row=cb.closest('[data-search]');
            return isRowVisible(row);
        });
        if(visibleCbs.length===0){
            header.checked=false;
            header.indeterminate=false;
            return;
        }
        const checkedCount=visibleCbs.filter(cb=>cb.checked).length;
        header.checked=checkedCount===visibleCbs.length;
        header.indeterminate=checkedCount>0 && checkedCount<visibleCbs.length;
    };

    const syncAllHeaderStates=()=>{
        document.querySelectorAll('.inst-column, .room-column').forEach(syncColumnHeaderState);
    };

    const applyHeaderSelect=(headerCb)=>{
        const column=headerCb.closest('.inst-column, .room-column');
        if(!column) return;
        const targetChecked=headerCb.checked;
        getColumnCheckboxes(column).forEach(cb=>{
            const row=cb.closest('[data-search]');
            if(!isRowVisible(row)) return;
            cb.checked=targetChecked;
            cb.dispatchEvent(new Event('change', { bubbles: true }));
        });
        syncColumnHeaderState(column);
        updateCount();
    };

    // Keep backward compatibility with existing inline handler usage.
    window.selectRoomGroup = applyHeaderSelect;

    // Auto-show subject groups
    document.querySelectorAll('.inst-cb').forEach(cb=>{
        cb.addEventListener('change',()=>{
            const id=cb.value;
            const group=document.querySelector(`.subject-group[data-for="${id}"]`);
            if(group){
                group.style.display=cb.checked?'block':'none';
            }
            updateCount();
            syncColumnHeaderState(cb.closest('.inst-column'));
        });
    });
    document.querySelectorAll('.room-cb').forEach(cb=>{
        cb.addEventListener('change',()=>{
            updateCount();
            syncColumnHeaderState(cb.closest('.room-column'));
        });
    });
    document.querySelectorAll('.header-select-all').forEach(cb=>{
        cb.addEventListener('change',()=>applyHeaderSelect(cb));
    });
    
    // Search
    document.getElementById('instructorSearch').oninput=e=>{
        const q=e.target.value.toLowerCase();
        document.querySelectorAll('#instructorList .checkbox-row[data-search]').forEach(row=>{
            row.hidden=!row.dataset.search.includes(q);
        });
        document.querySelectorAll('#instructorList .inst-column').forEach(column=>{
            const visibleRows=column.querySelectorAll('.checkbox-row[data-search]:not([hidden])').length;
            const emptyState=column.querySelector('.room-empty');
            if(emptyState){
                emptyState.style.display=visibleRows===0?'block':'none';
                emptyState.textContent=q && visibleRows===0?'No matching instructors found.':'No instructors found in this group.';
            }
            column.hidden=q && visibleRows===0;
        });
        syncAllHeaderStates();
    };
    
    document.getElementById('roomSearch').oninput=e=>{
        const q=e.target.value.toLowerCase();
        document.querySelectorAll('#roomList .room-option-row[data-search]').forEach(row=>{
            row.hidden=!row.dataset.search.includes(q);
        });
        document.querySelectorAll('#roomList .room-column').forEach(column=>{
            const visibleRows=column.querySelectorAll('.room-option-row[data-search]:not([hidden])').length;
            const emptyState=column.querySelector('.room-empty');
            if(emptyState){
                emptyState.style.display=visibleRows===0?'block':'none';
                emptyState.textContent=q && visibleRows===0?'No matching rooms found.':'No rooms found in this group.';
            }
            column.hidden=q && visibleRows===0;
        });
        syncAllHeaderStates();
    };

    updateCount();
    syncAllHeaderStates();
    
    // Filter reload
    ['year_level','semester','program'].forEach(id=>{
        document.getElementById(id)?.addEventListener('change',()=>{
            const params=new URLSearchParams();
            params.set('year_level',document.getElementById('year_level').value);
            params.set('semester',document.getElementById('semester').value);
            params.set('program',document.getElementById('program').value);
            window.location.search=params.toString();
        });
    });

    const fastPairedDayCb=document.getElementById('fast_paired_day_cb');
    const fastPairedDayModeGroup=document.getElementById('fast_paired_day_mode_group');
    const fastPairedDayHelp=document.getElementById('fast_paired_day_help');
    const weekdayCb=document.getElementById('weekday_cb');
    const syncFastPairedDayUi=()=>{
        if(!fastPairedDayCb || !fastPairedDayModeGroup) return;
        const showPaired=fastPairedDayCb.checked;
        fastPairedDayModeGroup.hidden=!showPaired;
        if(fastPairedDayHelp){
            fastPairedDayHelp.hidden=!showPaired;
        }
    };
    fastPairedDayCb?.addEventListener('change',()=>{
        if(fastPairedDayCb.checked && weekdayCb){
            weekdayCb.checked=false;
        }
        syncFastPairedDayUi();
    });
    weekdayCb?.addEventListener('change',()=>{
        if(weekdayCb.checked && fastPairedDayCb){
            fastPairedDayCb.checked=false;
        }
        syncFastPairedDayUi();
    });
    syncFastPairedDayUi();
});
</script>
</body>
</html>
