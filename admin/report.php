<?php
require_once '../includes/auth.php';
requireAdmin();

$pdo = getDB();
$message = '';
$error = '';
$weekly_hour_limit = 30.0;

// Ensure overload approval table exists
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS instructor_overload_approvals (
            id INT PRIMARY KEY AUTO_INCREMENT,
            instructor_id INT NOT NULL,
            approved_by INT NOT NULL,
            approved_hours DECIMAL(6,2) NOT NULL,
            threshold_hours DECIMAL(6,2) NOT NULL DEFAULT 30.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_instructor_created (instructor_id, created_at),
            FOREIGN KEY (instructor_id) REFERENCES instructors(id) ON DELETE CASCADE,
            FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
} catch (Exception $e) {
    // Keep report working even if table creation is restricted.
}

$signatory_defaults = [
    'header_country' => 'Republic of the Philippines',
    'header_university' => 'North Eastern Mindanao State University',
    'header_department' => 'Department of Computer Studies',
    'header_title' => 'CLASS PROGRAM',
    'header_term' => '2ND SEM A.Y. 2025-2026',
    'prepared_by_label' => 'Prepared by:',
    'prepared_by_name' => 'SHARON A. BUCALON, MIT',
    'prepared_by_title' => 'Program Coordinator - IT',
    'recommending_label' => 'Recommending Approval:',
    'recommending_name' => 'RAMONALIZA A. ESPENIDO, MST-SS',
    'recommending_title' => 'Registrar III',
    'noted_by_label' => 'Noted by:',
    'noted_by_name' => 'ENGR. NELYNE LOURDES Y. PLAZA, Ph.D.',
    'noted_by_title' => 'Dept. Chair, Dept. of Computer Studies',
    'approved_by_label' => 'Approved:',
    'approved_by_name' => 'JUANCHO A. INTANO, Ph.D.',
    'approved_by_title' => 'Campus Director',
    'document_code' => 'FM-ACAD-024/Rev002/01.26.2026/Page1',
    'contact_address' => 'Cantilan, Surigao del Sur 8317',
    'contact_phone' => '086-212-2723',
    'contact_website' => 'www.nemsu.edu.ph',
    'footer_logo_1' => '../assets/logo.png',
    'footer_logo_2' => '',
    'footer_logo_3' => '',
];
$signatory_file = __DIR__ . '/../config/report_signatories.json';
$report_logo_dir = __DIR__ . '/../assets/report_logos';
$report_logo_web_path = '../assets/report_logos';
$signatories = $signatory_defaults;

$formatResearchExtensionType = static function ($value): string {
    $normalized = strtolower(trim((string) $value));
    if ($normalized === 'both') {
        return 'Research/Extension';
    }
    if ($normalized === 'research') {
        return 'Research';
    }
    if ($normalized === 'extension') {
        return 'Extension';
    }
    return '-';
};

if (is_file($signatory_file)) {
    $stored_signatories = json_decode((string) file_get_contents($signatory_file), true);
    if (is_array($stored_signatories)) {
        $signatories = array_merge($signatory_defaults, $stored_signatories);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_overload'])) {
    $approve_instructor_id = (int)($_POST['instructor_id'] ?? 0);
    $approve_total_hours = (float)($_POST['total_hours'] ?? 0);

    if ($approve_instructor_id <= 0) {
        $error = 'Unable to approve overload: invalid instructor.';
    } elseif ($approve_total_hours <= $weekly_hour_limit) {
        $error = 'No overload to approve. Instructor hours are within the 30-hour limit.';
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO instructor_overload_approvals (instructor_id, approved_by, approved_hours, threshold_hours)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$approve_instructor_id, (int)($_SESSION['user_id'] ?? 0), $approve_total_hours, $weekly_hour_limit]);
            $message = 'Overload hours approved successfully.';
        } catch (Exception $e) {
            $error = 'Unable to approve overload hours: ' . $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_report_signatories'])) {
    foreach ($signatory_defaults as $key => $default_value) {
        $signatories[$key] = trim((string) ($_POST[$key] ?? ''));
        if ($signatories[$key] === '') {
            $signatories[$key] = $default_value;
        }
    }

    if (!is_dir($report_logo_dir) && !mkdir($report_logo_dir, 0777, true) && !is_dir($report_logo_dir)) {
        $error = 'Unable to create the report logo upload folder.';
    }

    $allowed_extensions = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'];
    foreach (['footer_logo_1', 'footer_logo_2', 'footer_logo_3'] as $logo_key) {
        if (!isset($_FILES[$logo_key . '_upload']) || (int) $_FILES[$logo_key . '_upload']['error'] === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        $upload = $_FILES[$logo_key . '_upload'];
        if ((int) $upload['error'] !== UPLOAD_ERR_OK) {
            $error = 'One of the logo uploads failed. Please try again.';
            break;
        }

        $extension = strtolower(pathinfo((string) $upload['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $allowed_extensions, true)) {
            $error = 'Logo files must be PNG, JPG, JPEG, GIF, WEBP, or SVG.';
            break;
        }

        $safe_name = $logo_key . '_' . time() . '.' . $extension;
        $target_path = $report_logo_dir . '/' . $safe_name;
        if (!move_uploaded_file($upload['tmp_name'], $target_path)) {
            $error = 'Unable to save the uploaded logo file.';
            break;
        }

        $signatories[$logo_key] = $report_logo_web_path . '/' . $safe_name;
    }

    if ($error === '') {
        $saved = file_put_contents(
            $signatory_file,
            json_encode($signatories, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );

        if ($saved === false) {
            $error = 'Unable to save report footer details.';
        } else {
            $message = 'Report header and footer details updated.';
        }
    }
}

// Handle filters
$department = $_GET['department'] ?? '';
$year_level = $_GET['year_level'] ?? '';
$instructor_id = $_GET['instructor_id'] ?? '';

// Build query (include subject credits/hours for report format)
$query = "
    SELECT s.*, sub.subject_code, sub.subject_name, sub.credits, sub.hours_per_week,
           sub.lecture_hours, sub.lab_hours,
           COALESCE(s.scheduled_hours, sub.hours_per_week) AS scheduled_hours,
           i.id as instructor_id, u.full_name as instructor_name,
           r.room_number, r.capacity AS room_capacity, r.has_computers, ts.day, ts.start_time, ts.end_time,
           j.job_name, j.input_data
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
$day_short = [
    'Monday' => 'Mon',
    'Tuesday' => 'Tue',
    'Wednesday' => 'Wed',
    'Thursday' => 'Thu',
    'Friday' => 'Fri',
    'Saturday' => 'Sat'
];

$format_block_label = static function ($yearLevel, $section): string {
    $year = (int) $yearLevel;
    $block = strtoupper(trim((string) $section));
    return $block === '' ? "{$year} BLOCK" : "{$year} BLOCK ({$block})";
};

$normalizeProgramCode = static function ($value): string {
    $text = strtoupper(trim((string) $value));
    if ($text === '') {
        return '';
    }
    if (strpos($text, 'BSCS') !== false || $text === 'CS' || strpos($text, 'COMPUTER SCIENCE') !== false) {
        return 'BSCS';
    }
    if (strpos($text, 'BSIT') !== false || $text === 'IT' || strpos($text, 'INFORMATION TECHNOLOGY') !== false) {
        return 'BSIT';
    }
    if (strpos($text, 'BSCPE') !== false || $text === 'CPE' || strpos($text, 'COMPUTER ENGINEERING') !== false) {
        return 'BSCPE';
    }
    return '';
};

$format_course_code = static function (array $row, array &$job_input_cache) use ($normalizeProgramCode): string {
    $jobId = (int) ($row['job_id'] ?? 0);
    if (!array_key_exists($jobId, $job_input_cache)) {
        $raw = (string) ($row['input_data'] ?? '');
        $decoded = json_decode($raw, true);
        $job_input_cache[$jobId] = is_array($decoded) ? $decoded : [];
    }
    $jobInput = $job_input_cache[$jobId] ?? [];
    $programCode = $normalizeProgramCode($jobInput['program'] ?? '');
    if ($programCode === '' && !empty($jobInput['program_id'])) {
        $programCode = $normalizeProgramCode((string) $jobInput['program_id']);
    }
    if ($programCode === '') {
        $programCode = $normalizeProgramCode($row['department'] ?? '');
    }

    $year = (int) ($row['year_level'] ?? 0);
    $section = strtoupper(trim((string) ($row['section'] ?? '')));
    $suffix = trim($year . $section);
    if ($programCode === '') {
        return $suffix;
    }
    return trim($programCode . ' ' . $suffix);
};

$format_workload_time = static function ($startTime, $endTime): string {
    return date('g:i', strtotime((string) $startTime)) . '-' . date('g:i', strtotime((string) $endTime));
};

$format_subject_description = static function (array $row): string {
    $subjectName = trim((string) ($row['subject_name'] ?? ''));
    $meetingKind = strtolower(trim((string) ($row['meeting_kind'] ?? '')));
    if ($meetingKind === 'lecture') {
        return $subjectName . ' (Lec)';
    }
    if ($meetingKind === 'lab') {
        return $subjectName . ' (Lab)';
    }

    $lectureHours = (float) ($row['lecture_hours'] ?? 0);
    $labHours = (float) ($row['lab_hours'] ?? 0);
    if ($labHours > 0 && $lectureHours <= 0) {
        return $subjectName . ' (Lab)';
    }
    if ($lectureHours > 0 && $labHours <= 0) {
        return $subjectName . ' (Lec)';
    }
    if ($lectureHours > 0 && $labHours > 0) {
        return $subjectName . ((int) ($row['has_computers'] ?? 0) === 1 ? ' (Lab)' : ' (Lec)');
    }
    return $subjectName;
};

$workload_group_titles = [
    'MTh/Morning' => 'MTH/Morning',
    'MTh/Afternoon' => 'MTH/Afternoon',
    'Wed/Morning' => 'WED/Morning',
    'Wed/Afternoon' => 'WED/Afternoon',
    'TF/Morning' => 'TF/Morning',
    'TF/Afternoon' => 'TF/Afternoon',
    'Saturday' => 'SATURDAY',
];

$by_section = [];
foreach ($schedules as $row) {
    $sec = (string)($row['section'] ?? '');
    $key = (int)$row['year_level'] . $sec;
    if (!isset($by_section[$key])) {
        $by_section[$key] = ['label' => $format_block_label($row['year_level'], $sec), 'rows' => []];
    }
    $by_section[$key]['rows'][] = $row;
}

// Within each section, group by day group (e.g. MTh/A.M.) and sort by time
foreach ($by_section as $key => &$section) {
    $by_day = [];
    $section_total_units = 0.0;
    $counted_subject_units = [];
    foreach ($section['rows'] as $row) {
        $dg = $day_to_group[$row['day']] ?? $row['day'];
        if ($dg === 'Saturday') {
            $group_key = 'Saturday';
        } else {
            $period = (strtotime($row['start_time']) < strtotime('12:00:00')) ? 'A.M.' : 'P.M.';
            $group_key = $dg . '/' . $period;
        }
        if (!isset($by_day[$group_key])) {
            $by_day[$group_key] = [];
        }
        $row['report_time_label'] = $format_workload_time($row['start_time'], $row['end_time']);
        $by_day[$group_key][] = $row;
        $subject_unit_key = (int) ($row['subject_id'] ?? 0);
        if ($subject_unit_key <= 0) {
            $subject_unit_key = trim((string) ($row['subject_code'] ?? ''));
        }
        if ($subject_unit_key !== '' && !isset($counted_subject_units[$subject_unit_key])) {
            $section_total_units += (float) ($row['credits'] ?? 0);
            $counted_subject_units[$subject_unit_key] = true;
        }
    }
    foreach ($by_day as $gk => $rows) {
        usort($by_day[$gk], function ($a, $b) {
            $t = strcmp($a['day'], $b['day']);
            if ($t !== 0) return $t;
            return strcmp($a['start_time'], $b['start_time']);
        });
    }
    $section['by_day_group'] = $by_day;
    $section['total_units'] = round($section_total_units, 2);
}
unset($section);

// Sort blocks for output by year level then block letter.
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
$total_units_with_deloading = 0.0;
$is_overloaded = false;
$overload_approved = false;
$overload_approval = null;
if (!empty($instructor_id)) {
    $stmt = $pdo->prepare("
        SELECT i.id, u.full_name, i.department, i.status,
               i.designation, i.designation_units,
               i.research_extension, i.research_extension_units,
               i.special_assignment, i.special_assignment_units
        FROM instructors i
        JOIN users u ON i.user_id = u.id
        WHERE i.id = ?
    ");
    $stmt->execute([$instructor_id]);
    $selected_instructor = $stmt->fetch(PDO::FETCH_ASSOC);
    $job_input_cache = [];

    $counted_instructor_subject_units = [];
    foreach ($schedules as $row) {
        $dg = $day_to_group[$row['day']] ?? $row['day'];
        if ($dg === 'Saturday') {
            $group_key = 'Saturday';
        } else {
            $period = (strtotime($row['start_time']) < strtotime('12:00:00')) ? 'Morning' : 'Afternoon';
            $group_key = $dg . '/' . $period;
        }
        if (!isset($instructor_workload[$group_key])) {
            $instructor_workload[$group_key] = [];
        }
        $row['report_course_code'] = $format_course_code($row, $job_input_cache);
        $row['report_time_label'] = $format_workload_time($row['start_time'], $row['end_time']);
        $row['report_students'] = (int) ($row['room_capacity'] ?? 0) > 0 ? (int) $row['room_capacity'] : '';
        $instructor_workload[$group_key][] = $row;
        $subject_unit_key = (int) ($row['subject_id'] ?? 0);
        if ($subject_unit_key <= 0) {
            $subject_unit_key = trim((string) ($row['subject_code'] ?? ''));
        }
        if ($subject_unit_key !== '' && !isset($counted_instructor_subject_units[$subject_unit_key])) {
            $total_units += (float)($row['credits'] ?? 0);
            $counted_instructor_subject_units[$subject_unit_key] = true;
        }
        $total_hours += (float)($row['scheduled_hours'] ?? $row['hours_per_week'] ?? 0);
    }
    $total_hours = round($total_hours, 2);
    $total_units_with_deloading = $total_units
        + (float)($selected_instructor['designation_units'] ?? 0)
        + (float)($selected_instructor['research_extension_units'] ?? 0)
        + (float)($selected_instructor['special_assignment_units'] ?? 0);

    foreach ($instructor_workload as $gk => $rows) {
        usort($instructor_workload[$gk], function ($a, $b) {
            return strcmp($a['start_time'], $b['start_time']);
        });
    }

    $unique_subject_ids = [];
    foreach ($schedules as $row) {
        $sid = (int)($row['subject_id'] ?? 0);
        if ($sid > 0) {
            $unique_subject_ids[$sid] = true;
        }
    }
    $total_preparations = count($unique_subject_ids);

    $is_overloaded = $total_hours > $weekly_hour_limit;
    if ($is_overloaded) {
        try {
            $stmt = $pdo->prepare("
                SELECT oa.*, u.full_name AS approver_name
                FROM instructor_overload_approvals oa
                JOIN users u ON oa.approved_by = u.id
                WHERE oa.instructor_id = ?
                ORDER BY oa.created_at DESC
                LIMIT 1
            ");
            $stmt->execute([$instructor_id]);
            $overload_approval = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($overload_approval) {
                $approved_hours = (float)($overload_approval['approved_hours'] ?? 0);
                $approved_threshold = (float)($overload_approval['threshold_hours'] ?? $weekly_hour_limit);
                $overload_approved = $approved_hours >= $total_hours && $approved_threshold == $weekly_hour_limit;
            }
        } catch (Exception $e) {
            $overload_approval = null;
            $overload_approved = false;
        }
    }
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
            padding: 10px 14px;
            border-bottom: 1px solid #333;
            font-weight: 700;
            font-size: 15px;
        }
        .schedule-report-table { width: 100%; border-collapse: collapse; margin-bottom: 0; }
        .schedule-report-table th, .schedule-report-table td { border: 1px solid #000; padding: 6px 7px; text-align: left; }
        .schedule-report-table thead th { background: #f2f2f2; }
        .schedule-report-table .day-group-header td { background: #f0f0f0; font-weight: bold; }
        .schedule-report-table .section-total-row td {
            font-weight: 700;
            background: #fafafa;
        }
        .workload-sheet {
            border: 1px solid #333;
            padding: 18px 18px 14px;
            page-break-inside: avoid;
        }
        .workload-header {
            text-align: center;
            margin-bottom: 12px;
            line-height: 1.35;
        }
        .report-main-header {
            text-align: center;
            margin-bottom: 18px;
            line-height: 1.15;
        }
        .report-main-header img {
            width: 76px;
            height: auto;
            margin-bottom: 6px;
        }
        .report-main-header .country-line {
            font-size: 15px;
        }
        .report-main-header .university-line {
            font-size: 22px;
            font-weight: 700;
        }
        .report-main-header .department-line {
            font-size: 20px;
            font-weight: 700;
            color: #2f4a68;
        }
        .report-main-header .title-line {
            font-size: 17px;
            font-weight: 700;
            letter-spacing: 0.03em;
        }
        .report-main-header .term-line {
            font-size: 15px;
            font-style: italic;
        }
        .workload-header h2, .workload-header h3, .workload-header h4 {
            margin: 2px 0;
        }
        .workload-meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px 16px;
            margin-bottom: 14px;
            font-size: 14px;
        }
        .workload-meta .meta-line {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            align-items: baseline;
        }
        .workload-meta .meta-label {
            font-weight: 700;
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
        .workload-table .blank-row td {
            height: 28px;
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
        .workload-summary .summary-label {
            font-weight: 700;
            width: 28%;
        }
        .faculty-signatures {
            margin-top: 26px;
            page-break-inside: avoid;
        }
        .faculty-signature-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 42px;
            margin-top: 28px;
        }
        .faculty-signature-row.single {
            grid-template-columns: 1fr;
            max-width: 420px;
            margin-left: auto;
            margin-right: auto;
        }
        .faculty-signature-block {
            text-align: center;
        }
        .faculty-signature-label {
            text-align: left;
            font-weight: 700;
            margin-bottom: 30px;
        }
        .faculty-signature-name {
            font-weight: 700;
            text-transform: uppercase;
            border-top: 1px solid #222;
            display: inline-block;
            min-width: 280px;
            padding-top: 6px;
        }
        .faculty-signature-title {
            margin-top: 4px;
        }
        .report-signatory-settings {
            margin-top: 24px;
            padding: 16px;
            border: 1px solid #d0d7de;
            border-radius: 8px;
            background: #f8fafc;
        }
        .report-signatory-settings h3 {
            margin-top: 0;
        }
        .report-signatory-settings .form-hint {
            margin-top: -4px;
            margin-bottom: 14px;
            color: #475569;
            font-size: 13px;
        }
        .signatory-settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 12px 16px;
        }
        .signatory-settings-grid label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 4px;
        }
        .signatory-settings-grid input {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            box-sizing: border-box;
        }
        .logo-setting {
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background: #fff;
        }
        .logo-setting-preview {
            min-height: 72px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 10px;
            border: 1px dashed #cbd5e1;
            border-radius: 6px;
            background: #f8fafc;
        }
        .logo-setting-preview img {
            max-width: 100%;
            max-height: 56px;
            object-fit: contain;
        }
        .logo-setting-preview span {
            color: #64748b;
            font-size: 13px;
        }
        .logo-setting-upload {
            margin-top: 10px;
        }
        .report-signature-sheet {
            margin-top: 28px;
            border: 1px solid #333;
            padding: 18px 20px;
            page-break-inside: avoid;
        }
        .report-signature-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 18px;
        }
        .report-signature-grid.single {
            grid-template-columns: 1fr;
            text-align: center;
        }
        .signature-label {
            font-size: 14px;
            margin-bottom: 28px;
        }
        .signature-name {
            font-weight: 700;
            font-size: 20px;
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        .signature-title {
            font-size: 14px;
        }
        .report-signature-meta {
            margin-top: 22px;
            text-align: center;
            font-size: 13px;
            color: #0f766e;
            font-style: italic;
        }
        .report-contact-footer {
            margin-top: 20px;
            padding-top: 14px;
            border-top: 1px solid #999;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 16px;
        }
        .report-contact-lines div {
            margin-bottom: 4px;
        }
        .report-contact-lines a {
            color: #1d4ed8;
            text-decoration: underline;
        }
        .report-contact-logo img {
            width: 68px;
            height: auto;
        }
        .report-contact-logos {
            display: flex;
            align-items: flex-end;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }
        .report-contact-logos img {
            width: 58px;
            height: auto;
            object-fit: contain;
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
        .report-signature-sheet { border: 1px solid #000; }
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
        
        <!-- Schedule Report: one block per Course/Year/Sec (e.g. 1 BLOCK (A), 2 BLOCK (A)) -->
        <div class="schedule-report">
            <?php if (empty($schedules)): ?>
                <p>No schedules found matching the criteria. Generate and publish a schedule first.</p>
            <?php elseif (!empty($instructor_id)): ?>
                <?php
                    $workload_order = ['MTh/Morning', 'MTh/Afternoon', 'Wed/Morning', 'Wed/Afternoon', 'TF/Morning', 'TF/Afternoon', 'Saturday'];
                ?>
                <div class="workload-sheet">
                    <div class="report-main-header">
                        <img src="../assets/logo.png" alt="NEMSU logo">
                        <div class="country-line"><?php echo htmlspecialchars($signatories['header_country']); ?></div>
                        <div class="university-line"><?php echo htmlspecialchars($signatories['header_university']); ?></div>
                        <div class="department-line"><?php echo htmlspecialchars($signatories['header_department']); ?></div>
                        <div class="title-line">FACULTY WORKLOAD</div>
                        <div class="term-line"><?php echo htmlspecialchars($signatories['header_term']); ?></div>
                    </div>

                    <div class="workload-meta">
                        <div class="meta-line"><span class="meta-label">Name:</span><span><?php echo htmlspecialchars($selected_instructor['full_name'] ?? ''); ?></span></div>
                        <div class="meta-line"><span class="meta-label">Educ'l Qualification:</span><span>-</span></div>
                        <div class="meta-line"><span class="meta-label">Years in Service:</span><span>-</span></div>
                        <div class="meta-line"><span class="meta-label">Major:</span><span>-</span></div>
                        <div class="meta-line"><span class="meta-label">Status:</span><span><?php echo htmlspecialchars($selected_instructor['status'] ?? 'Instructor'); ?></span></div>
                        <div class="meta-line"><span class="meta-label">Eligibility/PRC:</span><span>-</span></div>
                    </div>

                    <?php if ($is_overloaded && !$overload_approved && !isset($print_mode)): ?>
                        <div class="error" style="margin-bottom: 12px;">
                            <strong>Overload Warning:</strong>
                            This instructor has <strong><?php echo number_format($total_hours, 2); ?> hours</strong>,
                            which exceeds the 30-hour weekly limit.
                            <form method="POST" style="margin-top: 10px;">
                                <input type="hidden" name="instructor_id" value="<?php echo (int)$instructor_id; ?>">
                                <input type="hidden" name="total_hours" value="<?php echo htmlspecialchars((string)$total_hours); ?>">
                                <button type="submit" name="approve_overload" class="btn-primary">OK - Approve Exceed Hours</button>
                            </form>
                        </div>
                    <?php elseif ($is_overloaded && $overload_approved): ?>
                        <div class="success" style="margin-bottom: 12px;">
                            <strong>Overload Approved:</strong>
                            <?php echo number_format($total_hours, 2); ?> hours approved by
                            <?php echo htmlspecialchars($overload_approval['approver_name'] ?? 'Admin'); ?>
                            on <?php echo htmlspecialchars(date('F j, Y g:i A', strtotime((string)($overload_approval['created_at'] ?? 'now')))); ?>.
                        </div>
                    <?php endif; ?>

                    <table class="workload-table">
                        <thead>
                            <tr>
                                <th>TIME/DAY</th>
                                <th>Subject Code</th>
                                <th>Description</th>
                                <th>Course Code</th>
                                <th>No. of Students</th>
                                <th>Units</th>
                                <th>No. of Hours</th>
                                <th>Room No.</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($workload_order as $group_key): ?>
                                <?php if (empty($instructor_workload[$group_key])) continue; ?>
                                <tr class="workload-group">
                                    <td colspan="8"><?php echo htmlspecialchars($workload_group_titles[$group_key] ?? $group_key); ?></td>
                                </tr>
                                <?php foreach ($instructor_workload[$group_key] as $r): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($r['report_time_label']); ?></td>
                                        <td><?php echo htmlspecialchars($r['subject_code']); ?></td>
                                        <td><?php echo htmlspecialchars($format_subject_description($r)); ?></td>
                                        <td><?php echo htmlspecialchars($r['report_course_code']); ?></td>
                                        <td><?php echo htmlspecialchars((string) $r['report_students']); ?></td>
                                        <td><?php echo htmlspecialchars($r['credits']); ?></td>
                                        <td><?php echo htmlspecialchars(number_format((float)($r['scheduled_hours'] ?? $r['hours_per_week'] ?? 0), 2)); ?></td>
                                        <td><?php echo htmlspecialchars($r['room_number']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <table class="workload-summary">
                        <tr>
                            <td class="summary-label">No. of Units</td>
                            <td><?php echo number_format($total_units_with_deloading, 2); ?></td>
                            <td class="summary-label">No. of Hours</td>
                            <td><?php echo number_format($total_hours, 2); ?></td>
                        </tr>
                        <tr>
                            <td class="summary-label">Designation</td>
                            <td><?php echo htmlspecialchars(($selected_instructor['designation'] ?? '') !== '' ? $selected_instructor['designation'] : '-'); ?></td>
                            <td class="summary-label">Units Deloading</td>
                            <td><?php echo number_format((float)($selected_instructor['designation_units'] ?? 0), 2); ?></td>
                        </tr>
                        <tr>
                            <td class="summary-label">Research/Extension</td>
                            <td><?php echo htmlspecialchars($formatResearchExtensionType($selected_instructor['research_extension'] ?? '')); ?></td>
                            <td class="summary-label">Units Deloading</td>
                            <td><?php echo number_format((float)($selected_instructor['research_extension_units'] ?? 0), 2); ?></td>
                        </tr>
                        <tr>
                            <td class="summary-label">Add: Special Assignment</td>
                            <td><?php echo htmlspecialchars(($selected_instructor['special_assignment'] ?? '') !== '' ? $selected_instructor['special_assignment'] : '-'); ?></td>
                            <td class="summary-label">Units Deloading</td>
                            <td><?php echo number_format((float)($selected_instructor['special_assignment_units'] ?? 0), 2); ?></td>
                        </tr>
                        <tr>
                            <td class="summary-label">No. of Preparation</td>
                            <td><?php echo (int)$total_preparations; ?></td>
                            <td class="summary-label">Total No. of Units</td>
                            <td><?php echo number_format($total_units_with_deloading, 2); ?></td>
                        </tr>
                    </table>

                    <div class="faculty-signatures">
                        <div class="faculty-signature-row">
                            <div class="faculty-signature-block">
                                <div class="faculty-signature-label">Prepared by:</div>
                                <div class="faculty-signature-name"><?php echo htmlspecialchars($signatories['noted_by_name']); ?></div>
                                <div class="faculty-signature-title"><?php echo htmlspecialchars($signatories['noted_by_title']); ?></div>
                            </div>
                            <div class="faculty-signature-block">
                                <div class="faculty-signature-label">Conformed:</div>
                                <div class="faculty-signature-name"><?php echo htmlspecialchars($selected_instructor['full_name'] ?? ''); ?></div>
                                <div class="faculty-signature-title"><?php echo htmlspecialchars(($selected_instructor['status'] ?? 'Instructor') ?: 'Instructor'); ?></div>
                            </div>
                        </div>
                        <div class="faculty-signature-row single">
                            <div class="faculty-signature-block">
                                <div class="faculty-signature-label">Certified Correct:</div>
                                <div class="faculty-signature-name"><?php echo htmlspecialchars($signatories['recommending_name']); ?></div>
                                <div class="faculty-signature-title"><?php echo htmlspecialchars($signatories['recommending_title']); ?></div>
                            </div>
                        </div>
                        <div class="faculty-signature-row">
                            <div class="faculty-signature-block">
                                <div class="faculty-signature-label">Recommending Approval:</div>
                                <div class="faculty-signature-name"><?php echo htmlspecialchars($signatories['approved_by_name']); ?></div>
                                <div class="faculty-signature-title"><?php echo htmlspecialchars($signatories['approved_by_title']); ?></div>
                            </div>
                            <div class="faculty-signature-block">
                                <div class="faculty-signature-label">Approved:</div>
                                <div class="faculty-signature-name"><?php echo htmlspecialchars($signatories['prepared_by_name']); ?></div>
                                <div class="faculty-signature-title"><?php echo htmlspecialchars($signatories['prepared_by_title']); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($by_section as $sectionKey => $section): ?>
                <div class="schedule-section-block">
                    <div class="report-main-header" style="padding: 16px 16px 0;">
                        <img src="../assets/logo.png" alt="NEMSU logo">
                        <div class="country-line"><?php echo htmlspecialchars($signatories['header_country']); ?></div>
                        <div class="university-line"><?php echo htmlspecialchars($signatories['header_university']); ?></div>
                        <div>|</div>
                        <div class="department-line"><?php echo htmlspecialchars($signatories['header_department']); ?></div>
                        <div class="title-line"><?php echo htmlspecialchars($signatories['header_title']); ?></div>
                        <div class="term-line"><?php echo htmlspecialchars($signatories['header_term']); ?></div>
                    </div>
                    <div class="schedule-section-header">Course/Year/Sec. <?php echo htmlspecialchars(str_replace([' BLOCK (', ')', ' BLOCK'], ['', '', ''], $section['label'])); ?></div>
                    <table class="schedule-report-table">
                        <thead>
                            <tr>
                                <th>TIME/DAY</th>
                                <th>Subject Code</th>
                                <th>Description</th>
                                <th>No. of Units</th>
                                <th>No. of Hours</th>
                                <th>Instructor</th>
                                <th>Room</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $order = ['MTh/A.M.', 'MTh/P.M.', 'TF/A.M.', 'TF/P.M.', 'Wed/A.M.', 'Wed/P.M.', 'Saturday'];
                            foreach ($order as $groupKey):
                                if (empty($section['by_day_group'][$groupKey])) continue;
                                $rows = $section['by_day_group'][$groupKey];
                            ?>
                            <tr class="day-group-header">
                                <td colspan="7"><strong><?php echo htmlspecialchars($groupKey === 'Saturday' ? 'SATURDAY' : $groupKey); ?></strong></td>
                            </tr>
                            <?php if ($groupKey === 'MTh/A.M.'): ?>
                            <tr>
                                <td>7:00-7:30</td>
                                <td colspan="6" style="text-align:center;font-weight:600;">Flag Raising Ceremony</td>
                            </tr>
                            <?php endif; ?>
                            <?php foreach ($rows as $r): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($r['report_time_label']); ?></td>
                                <td><?php echo htmlspecialchars($r['subject_code']); ?></td>
                                <td><?php echo htmlspecialchars($format_subject_description($r)); ?></td>
                                <td><?php echo (int)($r['credits'] ?? 0); ?></td>
                                <td><?php echo number_format((float)($r['scheduled_hours'] ?? $r['hours_per_week'] ?? 0), 2); ?></td>
                                <td><?php echo htmlspecialchars($r['instructor_name']); ?></td>
                                <td><?php echo htmlspecialchars($r['room_number'] ?? ''); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endforeach; ?>
                            <tr class="section-total-row">
                                <td colspan="3" style="text-align:right;">TOTAL UNITS</td>
                                <td><?php echo number_format((float)($section['total_units'] ?? 0), 2); ?></td>
                                <td colspan="3"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if (!isset($print_mode)): ?>
        <div class="report-signatory-settings">
            <h3>Report Header and Footer Details</h3>
            <div class="form-hint">
                Update the header text, signatories, contact details, and footer logo image paths here. Change the logo paths anytime if the official logos are replaced in the future.
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="signatory-settings-grid">
                    <?php foreach ($signatory_defaults as $key => $default_value): ?>
                    <?php if (in_array($key, ['footer_logo_1', 'footer_logo_2', 'footer_logo_3'], true)) continue; ?>
                    <div class="form-group">
                        <label for="<?php echo htmlspecialchars($key); ?>">
                            <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $key))); ?>
                        </label>
                        <input
                            type="text"
                            id="<?php echo htmlspecialchars($key); ?>"
                            name="<?php echo htmlspecialchars($key); ?>"
                            value="<?php echo htmlspecialchars($signatories[$key] ?? ''); ?>"
                        >
                    </div>
                    <?php endforeach; ?>
                    <?php foreach (['footer_logo_1', 'footer_logo_2', 'footer_logo_3'] as $logo_key): ?>
                    <div class="logo-setting">
                        <label for="<?php echo htmlspecialchars($logo_key); ?>">
                            <?php echo htmlspecialchars(strtoupper(str_replace('_', ' ', $logo_key))); ?>
                        </label>
                        <div class="logo-setting-preview">
                            <?php $current_logo = trim((string) ($signatories[$logo_key] ?? '')); ?>
                            <?php if ($current_logo !== ''): ?>
                                <img src="<?php echo htmlspecialchars($current_logo); ?>" alt="Current logo">
                            <?php else: ?>
                                <span>No logo selected</span>
                            <?php endif; ?>
                        </div>
                        <input
                            type="text"
                            id="<?php echo htmlspecialchars($logo_key); ?>"
                            name="<?php echo htmlspecialchars($logo_key); ?>"
                            value="<?php echo htmlspecialchars($current_logo); ?>"
                            placeholder="../assets/logo.png or upload below"
                        >
                        <input
                            class="logo-setting-upload"
                            type="file"
                            id="<?php echo htmlspecialchars($logo_key . '_upload'); ?>"
                            name="<?php echo htmlspecialchars($logo_key . '_upload'); ?>"
                            accept=".png,.jpg,.jpeg,.gif,.webp,.svg"
                        >
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="form-actions" style="margin-top: 16px;">
                    <button type="submit" name="save_report_signatories" class="btn-primary">Save Report Settings</button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <?php if (empty($instructor_id)): ?>
        <div class="report-signature-sheet">
            <div class="report-signature-grid">
                <div>
                    <div class="signature-label"><?php echo htmlspecialchars($signatories['prepared_by_label']); ?></div>
                    <div class="signature-name"><?php echo htmlspecialchars($signatories['prepared_by_name']); ?></div>
                    <div class="signature-title"><?php echo htmlspecialchars($signatories['prepared_by_title']); ?></div>
                </div>
                <div>
                    <div class="signature-label"><?php echo htmlspecialchars($signatories['recommending_label']); ?></div>
                    <div class="signature-name"><?php echo htmlspecialchars($signatories['recommending_name']); ?></div>
                    <div class="signature-title"><?php echo htmlspecialchars($signatories['recommending_title']); ?></div>
                </div>
            </div>

            <div class="report-signature-grid single">
                <div>
                    <div class="signature-label"><?php echo htmlspecialchars($signatories['noted_by_label']); ?></div>
                    <div class="signature-name"><?php echo htmlspecialchars($signatories['noted_by_name']); ?></div>
                    <div class="signature-title"><?php echo htmlspecialchars($signatories['noted_by_title']); ?></div>
                </div>
            </div>

            <div class="report-signature-grid single">
                <div>
                    <div class="signature-label"><?php echo htmlspecialchars($signatories['approved_by_label']); ?></div>
                    <div class="signature-name"><?php echo htmlspecialchars($signatories['approved_by_name']); ?></div>
                    <div class="signature-title"><?php echo htmlspecialchars($signatories['approved_by_title']); ?></div>
                </div>
            </div>

            <div class="report-signature-meta"><?php echo htmlspecialchars($signatories['document_code']); ?></div>

            <div class="report-contact-footer">
                <div class="report-contact-lines">
                    <div><?php echo htmlspecialchars($signatories['contact_address']); ?></div>
                    <div><?php echo htmlspecialchars($signatories['contact_phone']); ?></div>
                    <div><a href="https://<?php echo htmlspecialchars($signatories['contact_website']); ?>" target="_blank"><?php echo htmlspecialchars($signatories['contact_website']); ?></a></div>
                </div>
                <div class="report-contact-logos">
                    <?php foreach (['footer_logo_1', 'footer_logo_2', 'footer_logo_3'] as $logo_key): ?>
                        <?php $logo_src = trim((string) ($signatories[$logo_key] ?? '')); ?>
                        <?php if ($logo_src !== ''): ?>
                            <img src="<?php echo htmlspecialchars($logo_src); ?>" alt="Footer logo">
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="report-contact-footer">
            <div class="report-contact-lines">
                <div><?php echo htmlspecialchars($signatories['contact_address']); ?></div>
                <div><?php echo htmlspecialchars($signatories['contact_phone']); ?></div>
                <div><a href="https://<?php echo htmlspecialchars($signatories['contact_website']); ?>" target="_blank"><?php echo htmlspecialchars($signatories['contact_website']); ?></a></div>
            </div>
            <div class="report-contact-logos">
                <?php foreach (['footer_logo_1', 'footer_logo_2', 'footer_logo_3'] as $logo_key): ?>
                    <?php $logo_src = trim((string) ($signatories[$logo_key] ?? '')); ?>
                    <?php if ($logo_src !== ''): ?>
                        <img src="<?php echo htmlspecialchars($logo_src); ?>" alt="Footer logo">
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
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
