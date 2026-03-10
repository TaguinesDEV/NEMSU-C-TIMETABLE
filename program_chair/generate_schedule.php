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

// Fetch data for dropdowns - include program-specific + all-program instructors
$instructors = $pdo->prepare("
    SELECT i.*, u.full_name 
    FROM instructors i 
    JOIN users u ON i.user_id = u.id
    WHERE i.program_id = ?
       OR i.program_id IS NULL
       OR i.program_id = 0
    ORDER BY u.full_name
");
$instructors->execute([$program_id]);
$instructors = $instructors->fetchAll();

$rooms = $pdo->query("SELECT * FROM rooms")->fetchAll();
$subjects = $pdo->prepare("SELECT * FROM subjects WHERE program_id = ?");
$subjects->execute([$program_id]);
$subjects = $subjects->fetchAll();

// Subject code -> name map
$subject_name_map = [];
foreach ($subjects as $s) {
    $subject_name_map[strtoupper(trim($s['subject_code']))] = $s['subject_name'];
}
$subject_by_code = [];
foreach ($subjects as $s) {
    $subject_by_code[strtoupper(trim($s['subject_code']))] = $s;
}

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
        $year_level = (int)($_POST['year_level'] ?? 1);
        $num_sections = max(1, min(10, (int)($_POST['num_sections'] ?? 1)));

        // Saturday control
        $allow_saturday = isset($_POST['allow_saturday']);

        // Filter time slots
        $filtered_time_slots = [];
        foreach ($all_time_slots as $ts) {
            if (strtolower($ts['day']) === 'saturday' && !$allow_saturday) {
                continue; // exclude Saturday unless allowed
            }
            $filtered_time_slots[] = $ts;
        }

        // Prepare GA input
        $input_data = [
            'year_level' => $year_level,
            'num_sections' => $num_sections,
            'program_id' => $program_id,
            'instructors' => [],
            'rooms' => [],
            'subjects' => [],
            'time_slots' => $filtered_time_slots,
            'constraints' => [
                'max_classes_per_day' => $_POST['max_classes_per_day'] ?? 4,
                'preferred_start_time' => $_POST['preferred_start_time'] ?? '08:00',
                'avoid_back_to_back' => isset($_POST['avoid_back_to_back']),
                'respect_availability' => isset($_POST['respect_availability']),
                'allow_saturday' => $allow_saturday
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
                    if ($code !== '' && !in_array($code, $codes, true)) {
                        $codes[] = $code;
                    }
                }
                $input_data['instructor_subject_map'][(string)$inst_id] = $codes;
            } elseif (!empty($instructor_subject_codes[$inst_id])) {
                $input_data['instructor_subject_map'][(string)$inst_id] = $instructor_subject_codes[$inst_id];
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
            $error = "No subjects selected from instructor assignments. Please select at least one subject under selected instructors.";
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
        <label>Year Level</label>
        <select name="year_level">
            <option value="1">1st Year</option>
            <option value="2">2nd Year</option>
            <option value="3">3rd Year</option>
            <option value="4">4th Year</option>
        </select>
    </div>

    <div class="form-group">
        <label>Number of Sections</label>
        <select name="num_sections">
            <?php for ($i=1;$i<=10;$i++): ?>
                <option value="<?= $i ?>"><?= $i ?> Section<?= $i>1?'s':'' ?></option>
            <?php endfor; ?>
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

        <div class="checkbox-grid">
            <?php foreach ($instructors as $i): ?>
            <?php
                $inst_id = (int)$i['id'];
                $assigned_codes = $instructor_subject_codes[$inst_id] ?? [];
            ?>
            <div class="instructor-card" data-search-text="<?php echo htmlspecialchars(strtolower($i['full_name'] . ' ' . $i['department'])); ?>" style="padding: 10px; border: 1px solid #dee2e6; border-radius: 6px;">
                <label>
                    <input type="checkbox" class="instructor-checkbox" name="selected_instructors[]" value="<?= $i['id'] ?>" checked>
                    <?= htmlspecialchars($i['full_name']) ?> (<?= $i['department'] ?>)
                </label>
                <div class="instructor-subject-map" data-instructor-id="<?php echo $inst_id; ?>" style="display:none; margin-top: 8px; padding-top: 8px; border-top: 1px dashed #ced4da;">
                    <?php if (empty($assigned_codes)): ?>
                        <div class="form-hint">No assigned subjects configured for this instructor.</div>
                    <?php else: ?>
                        <?php foreach ($assigned_codes as $code): ?>
                            <?php $label_name = $subject_name_map[$code] ?? ''; ?>
                            <label style="display:block; margin-bottom: 4px;">
                                <input type="checkbox" class="instructor-subject-checkbox" name="instructor_subject_map[<?php echo $inst_id; ?>][]" value="<?php echo htmlspecialchars($code); ?>" checked>
                                <?php echo htmlspecialchars($code . ($label_name ? ' - ' . $label_name : '')); ?>
                            </label>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
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

syncInstructorSubjectVisibility();
</script>

</body>
</html>
