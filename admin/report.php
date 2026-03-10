<?php
require_once '../includes/auth.php';
requireAdmin();

$pdo = getDB();

// Handle filters
$department = $_GET['department'] ?? '';
$year_level = $_GET['year_level'] ?? '';
$instructor_id = $_GET['instructor_id'] ?? '';

// Build query (include subject credits/hours for report format)
$query = "
    SELECT s.*, sub.subject_code, sub.subject_name, sub.credits, sub.hours_per_week,
           i.id as instructor_id, u.full_name as instructor_name,
           r.room_number, ts.day, ts.start_time, ts.end_time,
           j.job_name
    FROM schedules s
    JOIN subjects sub ON s.subject_id = sub.id
    JOIN instructors i ON s.instructor_id = i.id
    JOIN users u ON i.user_id = u.id
    JOIN rooms r ON s.room_id = r.id
    JOIN time_slots ts ON s.time_slot_id = ts.id
    JOIN schedule_jobs j ON s.job_id = j.id
    WHERE s.is_published = 1
";

$params = [];

if ($department) {
    $query .= " AND s.department = ?";
    $params[] = $department;
}

if ($year_level) {
    $query .= " AND s.year_level = ?";
    $params[] = $year_level;
}

if ($instructor_id) {
    $query .= " AND s.instructor_id = ?";
    $params[] = $instructor_id;
}

$query .= " ORDER BY ts.day, ts.start_time";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$schedules = $stmt->fetchAll();

// Group schedules by Course/Year/Sec (year_level + section) for template-style report
$day_group_order = ['MTh/A.M.', 'MTh/P.M.', 'TF/A.M.', 'TF/P.M.', 'Wed/A.M.', 'Wed/P.M.', 'Saturday/A.M.', 'Saturday/P.M.'];
$day_to_group = [
    'Monday' => 'MTh', 'Thursday' => 'MTh',
    'Tuesday' => 'TF', 'Friday' => 'TF',
    'Wednesday' => 'Wed', 'Saturday' => 'Saturday'
];

$by_section = [];
foreach ($schedules as $row) {
    $sec = (string)($row['section'] ?? '');
    $key = (int)$row['year_level'] . $sec;
    if (!isset($by_section[$key])) {
        $by_section[$key] = ['label' => (int)$row['year_level'] . $sec, 'rows' => []];
    }
    $by_section[$key]['rows'][] = $row;
}

// Within each section, group by day group (e.g. MTh/A.M.) and sort by time
foreach ($by_section as $key => &$section) {
    $by_day = [];
    foreach ($section['rows'] as $row) {
        $dg = $day_to_group[$row['day']] ?? $row['day'];
        $period = (strtotime($row['start_time']) < strtotime('12:00:00')) ? 'A.M.' : 'P.M.';
        if ($dg === 'Saturday') {
            $group_key = 'Saturday/' . $period;
        } else {
            $group_key = $dg . '/' . $period;
        }
        if (!isset($by_day[$group_key])) {
            $by_day[$group_key] = [];
        }
        $by_day[$group_key][] = $row;
    }
    foreach ($by_day as $gk => $rows) {
        usort($by_day[$gk], function ($a, $b) {
            $t = strcmp($a['day'], $b['day']);
            if ($t !== 0) return $t;
            return strcmp($a['start_time'], $b['start_time']);
        });
    }
    $section['by_day_group'] = $by_day;
}
unset($section);

// Sort sections for output: 1A, 1B, 1C, 2A, 2B, ... (by year_level then section letter)
uksort($by_section, function ($a, $b) {
    preg_match('/^(\d+)(.*)$/', $a, $ma);
    preg_match('/^(\d+)(.*)$/', $b, $mb);
    $year_a = (int)($ma[1] ?? 0);
    $year_b = (int)($mb[1] ?? 0);
    if ($year_a !== $year_b) return $year_a - $year_b;
    return strcmp($ma[2] ?? '', $mb[2] ?? '');
});

// Instructor-specific workload view data
$selected_instructor = null;
$instructor_workload = [];
$total_units = 0;
$total_hours = 0;
$total_preparations = 0;
if (!empty($instructor_id)) {
    $stmt = $pdo->prepare("
        SELECT i.id, u.full_name, i.department
        FROM instructors i
        JOIN users u ON i.user_id = u.id
        WHERE i.id = ?
    ");
    $stmt->execute([$instructor_id]);
    $selected_instructor = $stmt->fetch(PDO::FETCH_ASSOC);

    foreach ($schedules as $row) {
        $dg = $day_to_group[$row['day']] ?? $row['day'];
        $period = (strtotime($row['start_time']) < strtotime('12:00:00')) ? 'Morning' : 'Afternoon';
        $group_key = $dg . '/' . $period;
        if (!isset($instructor_workload[$group_key])) {
            $instructor_workload[$group_key] = [];
        }
        $instructor_workload[$group_key][] = $row;
        $total_units += (float)($row['credits'] ?? 0);
        $total_hours += (float)($row['hours_per_week'] ?? 0);
    }

    foreach ($instructor_workload as $gk => $rows) {
        usort($instructor_workload[$gk], function ($a, $b) {
            return strcmp($a['start_time'], $b['start_time']);
        });
    }

    $unique_subjects = array_unique(array_column($schedules, 'subject_code'));
    $total_preparations = count($unique_subjects);
}

// Get filter options
$departments = $pdo->query("SELECT DISTINCT department FROM schedules WHERE department IS NOT NULL")->fetchAll();
$instructors = $pdo->query("
    SELECT i.id, u.full_name 
    FROM instructors i 
    JOIN users u ON i.user_id = u.id
")->fetchAll();

// Handle print request
if (isset($_GET['print'])) {
    // Set up print-friendly view
    $print_mode = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule Reports - Academic Scheduling System</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .schedule-section-block {
            border: 1px solid #333;
            margin-bottom: 2rem;
            page-break-inside: avoid;
        }
        .schedule-section-header {
            background: #2c3e50;
            color: #fff;
            font-weight: bold;
            font-size: 1.1rem;
            padding: 10px 14px;
            border-bottom: 2px solid #1a252f;
        }
        .schedule-report-table { width: 100%; border-collapse: collapse; margin-bottom: 0; }
        .schedule-report-table th, .schedule-report-table td { border: 1px solid #000; padding: 8px; text-align: left; }
        .schedule-report-table thead th { background: #f2f2f2; }
        .schedule-report-table .day-group-header td { background: #f0f0f0; font-weight: bold; }
        .workload-sheet {
            border: 1px solid #333;
            padding: 16px;
            page-break-inside: avoid;
        }
        .workload-header {
            text-align: center;
            margin-bottom: 12px;
            line-height: 1.35;
        }
        .workload-header h2, .workload-header h3, .workload-header h4 {
            margin: 2px 0;
        }
        .workload-meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px 16px;
            margin-bottom: 12px;
            font-size: 14px;
        }
        .workload-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .workload-table th,
        .workload-table td {
            border: 1px solid #333;
            padding: 6px;
            vertical-align: top;
        }
        .workload-table thead th {
            background: #f2f2f2;
        }
        .workload-group td {
            background: #fafafa;
            font-weight: 600;
        }
        .workload-summary {
            margin-top: 12px;
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .workload-summary td {
            border: 1px solid #333;
            padding: 6px;
        }
    </style>
    <?php if (isset($print_mode)): ?>
    <style>
        body { font-family: Arial, sans-serif; }
        .no-print { display: none; }
        .print-only { display: block; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #000; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .schedule-section-block { border: 1px solid #000; margin-bottom: 1.5rem; page-break-inside: avoid; }
        .schedule-section-header { background: #333 !important; color: #fff !important; padding: 8px 12px; }
    </style>
    <?php endif; ?>
</head>
<body>
    <?php if (!isset($print_mode)): ?>
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
    <?php endif; ?>
    
    <div class="container <?php echo isset($print_mode) ? 'print-only' : ''; ?>">
        <h2><?php echo !empty($instructor_id) ? 'Faculty Workload Report' : 'Generated Schedules'; ?></h2>
        
        <?php if (!isset($print_mode)): ?>
        <!-- Filter Section -->
        <div class="filter-section">
            <h3>Filter Schedules</h3>
            <form method="GET" action="" class="filter-form">
                <div class="form-group">
                    <label for="department">Department:</label>
                    <select id="department" name="department">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo $dept['department']; ?>" 
                                <?php echo $department == $dept['department'] ? 'selected' : ''; ?>>
                            <?php echo $dept['department']; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="year_level">Year Level:</label>
                    <select id="year_level" name="year_level">
                        <option value="">All Years</option>
                        <option value="1" <?php echo $year_level == '1' ? 'selected' : ''; ?>>1st Year</option>
                        <option value="2" <?php echo $year_level == '2' ? 'selected' : ''; ?>>2nd Year</option>
                        <option value="3" <?php echo $year_level == '3' ? 'selected' : ''; ?>>3rd Year</option>
                        <option value="4" <?php echo $year_level == '4' ? 'selected' : ''; ?>>4th Year</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="instructor_id">Instructor:</label>
                    <select id="instructor_id" name="instructor_id">
                        <option value="">All Instructors</option>
                        <?php foreach ($instructors as $inst): ?>
                        <option value="<?php echo $inst['id']; ?>" 
                                <?php echo $instructor_id == $inst['id'] ? 'selected' : ''; ?>>
                            <?php echo $inst['full_name']; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn-primary">Apply Filters</button>
                    <a href="report.php" class="btn-secondary">Clear Filters</a>
                    <a href="report.php?print=1&<?php echo http_build_query($_GET); ?>" 
                       class="btn-primary" target="_blank">Print Report</a>
                </div>
            </form>
        </div>
        <?php endif; ?>
        
        <!-- Schedule Report: one block per Course/Year/Sec (e.g. 1A, 2A, 2B) -->
        <div class="schedule-report">
            <?php if (empty($schedules)): ?>
                <p>No schedules found matching the criteria. Generate and publish a schedule first.</p>
            <?php elseif (!empty($instructor_id)): ?>
                <?php
                    $workload_order = ['MTh/Morning', 'MTh/Afternoon', 'TF/Morning', 'TF/Afternoon', 'Wed/Morning', 'Wed/Afternoon', 'Saturday/Morning', 'Saturday/Afternoon'];
                ?>
                <div class="workload-sheet">
                    <div class="workload-header">
                        <div>Republic of the Philippines</div>
                        <h3>North Eastern Mindanao State University</h3>
                        <h2>Faculty Workload</h2>
                        <h4>Second Semester</h4>
                    </div>

                    <div class="workload-meta">
                        <div><strong>Name:</strong> <?php echo htmlspecialchars($selected_instructor['full_name'] ?? ''); ?></div>
                        <div><strong>Department:</strong> <?php echo htmlspecialchars($selected_instructor['department'] ?? ''); ?></div>
                        <div><strong>Status:</strong> Instructor</div>
                        <div><strong>Major:</strong> -</div>
                    </div>

                    <table class="workload-table">
                        <thead>
                            <tr>
                                <th>TIME/DAY</th>
                                <th>Subject Code</th>
                                <th>Description</th>
                                <th>Course</th>
                                <th>Units</th>
                                <th>No. of Hours</th>
                                <th>Room No.</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($workload_order as $group_key): ?>
                                <?php if (empty($instructor_workload[$group_key])) continue; ?>
                                <tr class="workload-group">
                                    <td colspan="7"><?php echo htmlspecialchars($group_key); ?></td>
                                </tr>
                                <?php foreach ($instructor_workload[$group_key] as $r): ?>
                                    <tr>
                                        <td><?php echo date('g:i', strtotime($r['start_time'])) . '-' . date('g:i', strtotime($r['end_time'])); ?></td>
                                        <td><?php echo htmlspecialchars($r['subject_code']); ?></td>
                                        <td><?php echo htmlspecialchars($r['subject_name']); ?></td>
                                        <td><?php echo htmlspecialchars(((int)$r['year_level']) . ($r['section'] ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars($r['credits']); ?></td>
                                        <td><?php echo htmlspecialchars($r['hours_per_week']); ?></td>
                                        <td><?php echo htmlspecialchars($r['room_number']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <table class="workload-summary">
                        <tr>
                            <td><strong>No. of Preparation</strong></td>
                            <td><?php echo (int)$total_preparations; ?></td>
                            <td><strong>Total No. of Units</strong></td>
                            <td><?php echo number_format($total_units, 2); ?></td>
                            <td><strong>Total No. of Hours</strong></td>
                            <td><?php echo number_format($total_hours, 2); ?></td>
                        </tr>
                    </table>
                </div>
            <?php else: ?>
                <?php foreach ($by_section as $sectionKey => $section): ?>
                <div class="schedule-section-block">
                    <div class="schedule-section-header">Course/Year/Sec. <?php echo htmlspecialchars($section['label']); ?></div>
                    <table class="schedule-report-table">
                        <thead>
                            <tr>
                                <th>TIME/DAY</th>
                                <th>Subject Code</th>
                                <th>Description</th>
                                <th>No. of Units</th>
                                <th>No. of Hours</th>
                                <th>Instructor</th>
                                <th>Room No.</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $order = ['MTh/A.M.', 'MTh/P.M.', 'TF/A.M.', 'TF/P.M.', 'Wed/A.M.', 'Wed/P.M.', 'Saturday/A.M.', 'Saturday/P.M.'];
                            foreach ($order as $groupKey):
                                if (empty($section['by_day_group'][$groupKey])) continue;
                                $rows = $section['by_day_group'][$groupKey];
                            ?>
                            <tr class="day-group-header">
                                <td colspan="7"><strong><?php echo htmlspecialchars($groupKey); ?></strong></td>
                            </tr>
                            <?php foreach ($rows as $r): ?>
                            <tr>
                                <td><?php echo date('g:i', strtotime($r['start_time'])); ?>-<?php echo date('g:i', strtotime($r['end_time'])); ?></td>
                                <td><?php echo htmlspecialchars($r['subject_code']); ?></td>
                                <td><?php echo htmlspecialchars($r['subject_name']); ?></td>
                                <td><?php echo (int)($r['credits'] ?? 0); ?></td>
                                <td><?php echo number_format((float)($r['hours_per_week'] ?? 0), 2); ?></td>
                                <td><?php echo htmlspecialchars($r['instructor_name']); ?></td>
                                <td><?php echo htmlspecialchars($r['room_number'] ?? ''); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <?php if (isset($print_mode)): ?>
        <div class="print-footer">
            <p>Generated on: <?php echo date('Y-m-d H:i:s'); ?></p>
        </div>
        <script>
            window.onload = function() { window.print(); }
        </script>
        <?php endif; ?>
    </div>
</body>
</html>
