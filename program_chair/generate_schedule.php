<?php
require_once '../includes/auth.php';
requireLogin();

// Check if user is program chair
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'program_chair') {
    header('Location: ../index.php');
    exit();
}

$pdo = getDB();
$message = '';
$error = '';

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

function preferProgramSubjects(array $subjects): array {
    $bestByCode = [];
    foreach ($subjects as $subject) {
        $code = strtoupper(trim((string)($subject['subject_code'] ?? '')));
        if ($code === '') {
            continue;
        }
        $isScoped = !empty($subject['program_id']);
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

$selected_semester = normalizeSemester($_POST['semester'] ?? $_GET['semester'] ?? '1st Semester');
$selected_schedule_mode = $_POST['schedule_mode'] ?? $_GET['schedule_mode'] ?? 'single';
$selected_year_level = normalizeYearLevelSelection($_POST['year_level'] ?? $_GET['year_level'] ?? 1);

// Get program chair's program
$stmt = $pdo->prepare("
    SELECT pc.*, p.program_name 
    FROM program_chairs pc 
    JOIN programs p ON pc.program_id = p.id 
    WHERE pc.user_id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$programChair = $stmt->fetch();

if (!$programChair) {
    die('Program chair profile not found.');
}

$program_id = $programChair['program_id'];

// Fetch data for dropdowns - include all instructors so cross-program sharing is possible
$instructors = $pdo->prepare("
    SELECT i.*, u.full_name, p.program_name
    FROM instructors i 
    JOIN users u ON i.user_id = u.id
    LEFT JOIN programs p ON i.program_id = p.id
    ORDER BY u.full_name
");
$instructors->execute();
$instructors = $instructors->fetchAll();

$own_program_instructors = [];
$cross_program_instructors = [];
foreach ($instructors as $inst) {
    $inst_program_id = (int)($inst['program_id'] ?? 0);
    if ($inst_program_id === (int)$program_id) {
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
    $params = array_merge([$program_id, $selected_semester], $year_levels_to_fetch);
    $subjects = $pdo->prepare("SELECT * FROM subjects WHERE (program_id = ? OR program_id IS NULL) AND (semester = ? OR semester IS NULL) AND (year_level IN ($placeholders) OR year_level IS NULL)");
    $subjects->execute($params);
} elseif ($has_subject_semester) {
    $subjects = $pdo->prepare("SELECT * FROM subjects WHERE (program_id = ? OR program_id IS NULL) AND (semester = ? OR semester IS NULL)");
    $subjects->execute([$program_id, $selected_semester]);
} else {
    $subjects = $pdo->prepare("SELECT * FROM subjects WHERE program_id = ? OR program_id IS NULL");
    $subjects->execute([$program_id]);
}
$subjects = $subjects->fetchAll();
$subjects = preferProgramSubjects($subjects);

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

// Instructor -> assigned subject codes
$instructor_ids = array_map(function ($inst) { return (int)$inst['id']; }, $instructors);
$instructor_subject_codes = [];
if (!empty($instructor_ids)) {
    $placeholders = implode(',', array_fill(0, count($instructor_ids), '?'));
    $stmt = $pdo->prepare("
        SELECT ism.instructor_id, s.specialization_name
        FROM instructor_specializations ism
        JOIN specializations s ON ism.specialization_id = s.id
        WHERE ism.instructor_id IN ($placeholders)
        ORDER BY ism.instructor_id, ism.priority
    ");
    $stmt->execute($instructor_ids);
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
}

// Fetch ALL time slots (including Saturday), filtering will happen based on checkbox
$all_time_slots = $pdo->query("
    SELECT * FROM time_slots
    ORDER BY FIELD(day,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'),
             start_time
")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['generate_schedule'])) {

        $job_name = $_POST['job_name'] ?? 'Schedule Generation ' . date('Y-m-d H:i:s');
        $schedule_mode = $_POST['schedule_mode'] ?? 'single';

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
$mirror_mode = $_POST['mirror_mode'] ?? 'strict';
$four_day_pattern = $mirror_mode !== 'none';

        // Filter time slots
        $filtered_time_slots = [];
        foreach ($all_time_slots as $ts) {
            $day = strtolower((string)$ts['day']);
            if ($day === 'saturday' && !$allow_saturday) {
                continue; // exclude Saturday unless allowed
            }
            $filtered_time_slots[] = $ts;
        }

        // Prepare GA input
        $input_data = [
            'year_level' => $year_levels_to_schedule,
            'schedule_mode' => $schedule_mode,
            'num_sections' => $num_sections,
            'program_id' => $program_id,
            'semester' => $selected_semester,
            'instructors' => [],
            'rooms' => [],
            'subjects' => [],
            'time_slots' => $filtered_time_slots,
            'constraints' => [
                'max_classes_per_day' => $_POST['max_classes_per_day'] ?? 4,
                'preferred_start_time' => $_POST['preferred_start_time'] ?? '08:00',
                'avoid_back_to_back' => isset($_POST['avoid_back_to_back']),
                'respect_availability' => isset($_POST['respect_availability']),
                'allow_saturday' => $allow_saturday,
'mirror_mode' => $mirror_mode,
'four_day_pattern' => $four_day_pattern
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

        // Per-job instructor -> subject-code selection
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
                INSERT INTO schedule_jobs (job_name, status, created_by, program_id, input_data)
                VALUES (?, 'pending', ?, ?, ?)
            ");
            $stmt->execute([
                $job_name,
                $_SESSION['user_id'],
                $program_id,
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate Schedule - Academic Scheduling System</title>
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
                    <a href="generate_schedule.php">Generate Schedule</a>
                    <span class="sep">/</span>
                    <a href="view_schedule.php">View Schedules</a>
                    <span class="sep">/</span>
                    <span class="welcome-text">Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                </div>
            </div>
            <a href="../logout.php" class="btn-logout">Logout</a>
        </div>
    </div>
</div>

<div class="container">

<?php if ($message): ?>
    <div class="success"><?= $message ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="error"><?= $error ?></div>
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
            <option value="single" selected>Single Year</option>
            <option value="1_3">1st & 3rd Year</option>
            <option value="2_4">2nd & 4th Year</option>
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
        <label>Semester</label>
        <select name="semester" id="semester_select">
            <option value="1st Semester" <?php echo $selected_semester === '1st Semester' ? 'selected' : ''; ?>>1st Semester</option>
            <option value="2nd Semester" <?php echo $selected_semester === '2nd Semester' ? 'selected' : ''; ?>>2nd Semester</option>
            <option value="Summer" <?php echo $selected_semester === 'Summer' ? 'selected' : ''; ?>>Summer</option>
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

    <?php if (empty($instructors)): ?>
        <p class="no-data">No instructors found for your program. Please contact admin to add instructors or set them to All Programs.</p>
    <?php else: ?>
        <!-- Select All Checkbox -->
        <label class="checkbox-label">
            <input type="checkbox" id="select_all_instructors">
            <strong>Select All Instructors</strong>
        </label>

        <h4 style="margin-top:12px;"><?php echo htmlspecialchars($programChair['program_name']); ?> Instructors</h4>
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
                    $inst_program_label = $i['program_name'] ?: 'All Programs';
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
    <?php endif; ?>
</div>

<!-- ROOMS -->
<div class="form-section">
    <h3>Select Rooms (<?php echo count($rooms); ?>)</h3>

    <?php if (empty($rooms)): ?>
        <p class="no-data">No rooms available. Please contact admin to add rooms.</p>
    <?php else: ?>
        <!-- Select All Checkbox -->
        <label class="checkbox-label">
            <input type="checkbox" id="select_all_rooms">
            <strong>Select All Rooms</strong>
        </label>

        <div class="checkbox-grid">
            <?php foreach ($rooms as $r): ?>
            <label>
                <input type="checkbox" class="room-checkbox" name="selected_rooms[]" value="<?= $r['id'] ?>" checked>
                <?= $r['room_number'] ?> (<?= $r['capacity'] ?>)
            </label>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
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
            <input type="checkbox" name="avoid_back_to_back">
            Avoid back-to-back classes
        </label>
    </div>

    <div class="form-group checkbox">
        <label>
            <input type="checkbox" name="respect_availability" checked>
            Respect instructor availability
        </label>
    </div>

    <!-- Saturday Control -->
    <div class="form-group checkbox">
        <label>
            <input type="checkbox" name="allow_saturday">
            Allow Saturday (Make-up Classes Only)
        </label>
    </div>

    <div class="form-group">
        <label>Day Pairing (Mon/Thu → Tue/Fri → 1 Wed/subject)</label>
        <select name="pairing_mode" required>
            <option value="standard">Standard (Mon/Thu + Tue/Fri + 1 Wed)</option>
            <option value="mon_wed">Mon/Wed + Tue/Fri + 1 Thu</option>
            <option value="mon_tue">Mon/Tue + Wed/Fri + 1 Thu</option>
            <option value="flex_none">No Pairing (Free placement)</option>
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
if (semesterSelect) {
    semesterSelect.addEventListener('change', function () {
        const url = new URL(window.location.href);
        url.searchParams.set('semester', semesterSelect.value);
        const yearLevelSelectEl = document.getElementById('year_level_select');
        if (yearLevelSelectEl) {
            url.searchParams.set('year_level', yearLevelSelectEl.value);
        }
        window.location.href = url.toString();
    });
}

const yearLevelSelect = document.getElementById('year_level_select');
if (yearLevelSelect) {
    yearLevelSelect.addEventListener('change', function () {
        const url = new URL(window.location.href);
        const semesterSelectEl = document.getElementById('semester_select');
        if (semesterSelectEl) {
            url.searchParams.set('semester', semesterSelectEl.value);
        }
        url.searchParams.set('year_level', yearLevelSelect.value);
        window.location.href = url.toString();
    });
}

syncInstructorSubjectVisibility();

const scheduleModeSelect = document.getElementById('schedule_mode_select');
const yearLevelGroup = document.getElementById('year_level_group');

if (scheduleModeSelect) {
    scheduleModeSelect.addEventListener('change', function () {
        if (this.value === 'single') {
            yearLevelGroup.style.display = 'block';
        } else {
            yearLevelGroup.style.display = 'none';
        }
    });

    // Initial check
    if (scheduleModeSelect.value !== 'single') {
        yearLevelGroup.style.display = 'none';
    }
}
</script>

</body>
</html>
