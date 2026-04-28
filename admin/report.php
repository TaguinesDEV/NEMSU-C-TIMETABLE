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

// Optional consultation slots for vacant workload times.
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS instructor_consultation_slots (
            id INT PRIMARY KEY AUTO_INCREMENT,
            instructor_id INT NOT NULL,
            day_group VARCHAR(32) NOT NULL,
            time_label VARCHAR(32) NOT NULL,
            note VARCHAR(120) NOT NULL DEFAULT 'Consultation',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_instructor_consultation_slot (instructor_id, day_group, time_label),
            INDEX idx_instructor_consultation (instructor_id),
            FOREIGN KEY (instructor_id) REFERENCES instructors(id) ON DELETE CASCADE
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
    'instructor_prepared_by_name' => 'NELYNE LOURDES Y. PLAZA, Ph.D., PCpE',
    'instructor_prepared_by_title' => 'Chair, Dept. Computer Studies',
    'instructor_recommending_1_name' => 'JUANCHO A. INTANO, Ph.D.',
    'instructor_recommending_1_title' => 'Campus Director',
    'instructor_recommending_2_name' => 'ENGR. ALEX S. LADAGA, Ph.D.',
    'instructor_recommending_2_title' => 'Dean, CITE',
    'instructor_approved_by_name' => 'MARIA LADY SOL A. SUAZO, Ph.D.',
    'instructor_approved_by_title' => 'VP - Academic Affairs',
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

$renderReportFooter = static function (array $signatories): void {
    ?>
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
    <?php
};

$renderInstructorFooter = static function (array $selectedInstructor, array $footer): void {
    $conformedName = trim((string)($selectedInstructor['full_name'] ?? ''));
    if ($conformedName === '') {
        $conformedName = (string)($footer['instructor_prepared_by_name'] ?? '');
    }
    $conformedTitle = trim((string)($selectedInstructor['status'] ?? 'Instructor'));
    if ($conformedTitle === '') {
        $conformedTitle = 'Instructor';
    }
    ?>
        <div class="faculty-signatures instructor-signatures">
        <div class="faculty-signature-row">
            <div class="faculty-signature-block">
                <div class="faculty-signature-label">Prepared by:</div>
                <div class="faculty-signature-name"><?php echo htmlspecialchars($footer['instructor_prepared_by_name'] ?? ''); ?></div>
                <div class="faculty-signature-title"><?php echo htmlspecialchars($footer['instructor_prepared_by_title'] ?? ''); ?></div>
            </div>
            <div class="faculty-signature-block">
                <div class="faculty-signature-label">Conformed:</div>
                <div class="faculty-signature-name"><?php echo htmlspecialchars($conformedName); ?></div>
                <div class="faculty-signature-title"><?php echo htmlspecialchars($conformedTitle); ?></div>
            </div>
        </div>
        <div class="faculty-signature-row-label">Recommending Approval:</div>
        <div class="faculty-signature-row">
            <div class="faculty-signature-block">
                <div class="faculty-signature-name"><?php echo htmlspecialchars($footer['instructor_recommending_1_name'] ?? ''); ?></div>
                <div class="faculty-signature-title"><?php echo htmlspecialchars($footer['instructor_recommending_1_title'] ?? ''); ?></div>
            </div>
            <div class="faculty-signature-block">
                <div class="faculty-signature-name"><?php echo htmlspecialchars($footer['instructor_recommending_2_name'] ?? ''); ?></div>
                <div class="faculty-signature-title"><?php echo htmlspecialchars($footer['instructor_recommending_2_title'] ?? ''); ?></div>
            </div>
        </div>
        <div class="faculty-signature-row single">
            <div class="faculty-signature-block">
                <div class="faculty-signature-label faculty-signature-label-centered">Approved:</div>
                <div class="faculty-signature-name"><?php echo htmlspecialchars($footer['instructor_approved_by_name'] ?? ''); ?></div>
                <div class="faculty-signature-title"><?php echo htmlspecialchars($footer['instructor_approved_by_title'] ?? ''); ?></div>
            </div>
        </div>
    </div>
    <?php
};
$build_document_code_for_page = static function (string $documentCode, int $pageNumber): string {
    $pageLabel = 'Page' . max(1, $pageNumber);
    $trimmed = trim($documentCode);
    if ($trimmed === '') {
        return $pageLabel;
    }
    if (preg_match('/Page\d+\s*$/i', $trimmed)) {
        return (string)preg_replace('/Page\d+\s*$/i', $pageLabel, $trimmed);
    }
    return rtrim($trimmed, '/') . '/' . $pageLabel;
};

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
$department_lookup = trim((string)($_GET['department'] ?? ''));
$program_lookup = trim((string)($_GET['program'] ?? ''));
$year_level = $_GET['year_level'] ?? '';
$instructor_lookup = trim((string)($_GET['instructor'] ?? ''));
$normalize_subject_selection_keys = static function ($raw): array {
    if (!is_array($raw)) {
        if ($raw === null || $raw === '') {
            return [];
        }
        $raw = [$raw];
    }

    $normalized = [];
    foreach ($raw as $value) {
        $key = strtoupper(trim((string)$value));
        if ($key === '') {
            continue;
        }
        $normalized[$key] = $key;
    }
    return array_values($normalized);
};
$special_selection_applied = isset($_GET['special_selection_applied']) || isset($_POST['special_selection_applied']);
$requested_overload_subject_keys = $normalize_subject_selection_keys($_GET['selected_overload_subjects'] ?? ($_POST['selected_overload_subjects'] ?? []));
$requested_praise_subject_keys = $normalize_subject_selection_keys($_GET['selected_praise_subjects'] ?? ($_POST['selected_praise_subjects'] ?? []));

$departments = $pdo->query("
    SELECT d.id, d.dept_name, d.dept_code
    FROM departments d
    ORDER BY d.dept_name
")->fetchAll(PDO::FETCH_ASSOC);
$programs = $pdo->query("
    SELECT p.id, p.program_name, p.program_code, p.department_id, d.dept_name, d.dept_code
    FROM programs p
    LEFT JOIN departments d ON p.department_id = d.id
    ORDER BY d.dept_name, p.program_name
")->fetchAll(PDO::FETCH_ASSOC);
$instructors = $pdo->query("
    SELECT i.id, u.full_name 
    FROM instructors i 
    JOIN users u ON i.user_id = u.id
    ORDER BY u.full_name
")->fetchAll(PDO::FETCH_ASSOC);

$departmentIdByLookup = [];
foreach ($departments as $deptRow) {
    $departmentIdByLookup[strtoupper($deptRow['dept_name'])] = (int)$deptRow['id'];
    $departmentIdByLookup[strtoupper($deptRow['dept_code'])] = (int)$deptRow['id'];
    $departmentIdByLookup[strtoupper($deptRow['dept_name'] . ' (' . $deptRow['dept_code'] . ')')] = (int)$deptRow['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_consultation_slot'])) {
    $targetInstructorId = (int)($_POST['consultation_instructor_id'] ?? 0);
    $targetDayGroup = trim((string)($_POST['consultation_day_group'] ?? ''));
    $targetTimeLabel = trim((string)($_POST['consultation_time_label'] ?? ''));
    $targetNote = trim((string)($_POST['consultation_note'] ?? 'Consultation'));
    if ($targetNote === '') {
        $targetNote = 'Consultation';
    }

    if ($targetInstructorId <= 0 || $targetDayGroup === '' || $targetTimeLabel === '') {
        $error = 'Unable to save consultation slot: missing required fields.';
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO instructor_consultation_slots (instructor_id, day_group, time_label, note)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE note = VALUES(note)
            ");
            $stmt->execute([$targetInstructorId, $targetDayGroup, $targetTimeLabel, $targetNote]);
            $message = 'Consultation slot saved.';
        } catch (Exception $e) {
            $error = 'Unable to save consultation slot: ' . $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_consultation_slot'])) {
    $consultationSlotId = (int)($_POST['consultation_slot_id'] ?? 0);
    if ($consultationSlotId <= 0) {
        $error = 'Unable to delete consultation slot: invalid id.';
    } else {
        try {
            $stmt = $pdo->prepare("DELETE FROM instructor_consultation_slots WHERE id = ?");
            $stmt->execute([$consultationSlotId]);
            $message = 'Consultation slot removed.';
        } catch (Exception $e) {
            $error = 'Unable to delete consultation slot: ' . $e->getMessage();
        }
    }
}

$programIdByLookup = [];
foreach ($programs as $programRow) {
    $programIdByLookup[strtoupper($programRow['program_name'])] = (int)$programRow['id'];
    $programIdByLookup[strtoupper($programRow['program_code'])] = (int)$programRow['id'];
    $programIdByLookup[strtoupper($programRow['program_name'] . ' (' . $programRow['program_code'] . ')')] = (int)$programRow['id'];
}

$instructorIdByLookup = [];
foreach ($instructors as $instructorRow) {
    $instructorIdByLookup[strtoupper($instructorRow['full_name'])] = (int)$instructorRow['id'];
}

$department_id = (int)($departmentIdByLookup[strtoupper($department_lookup)] ?? 0);
$program_id = (int)($programIdByLookup[strtoupper($program_lookup)] ?? 0);
$instructor_id = (int)($instructorIdByLookup[strtoupper($instructor_lookup)] ?? 0);

$scheduleColumns = [];
try {
    $scheduleColumnRows = $pdo->query("SHOW COLUMNS FROM schedules")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($scheduleColumnRows as $scheduleColumnRow) {
        $columnName = (string)($scheduleColumnRow['Field'] ?? '');
        if ($columnName !== '') {
            $scheduleColumns[$columnName] = true;
        }
    }
} catch (Exception $e) {
    $scheduleColumns = [];
}

$hasScheduledHoursColumn = isset($scheduleColumns['scheduled_hours']);
$hasScheduledMinutesColumn = isset($scheduleColumns['scheduled_minutes']);

// Prefer the actual saved meeting duration from schedules when available.
$scheduledHoursExpression = $hasScheduledHoursColumn
    ? 'COALESCE(s.scheduled_hours, sub.hours_per_week)'
    : 'sub.hours_per_week';
$scheduledMinutesExpression = $hasScheduledMinutesColumn
    ? 'COALESCE(s.scheduled_minutes, 0)'
    : '0';
$reportEndTimeExpression = $hasScheduledMinutesColumn
    ? "CASE
            WHEN COALESCE(s.scheduled_minutes, 0) > 0 THEN ADDTIME(ts.start_time, SEC_TO_TIME(s.scheduled_minutes * 60))
            ELSE ts.end_time
       END"
    : 'ts.end_time';

// Build query (include subject credits/hours for report format)
$query = "
    SELECT s.*, sub.subject_code, sub.subject_name, sub.credits, sub.lecture_credits, sub.lab_credits, sub.hours_per_week,
           sub.lecture_hours, sub.lab_hours, sub.subject_type,
           {$scheduledHoursExpression} AS scheduled_hours,
           {$scheduledMinutesExpression} AS report_scheduled_minutes,
           i.id as instructor_id, u.full_name as instructor_name, i.status as instructor_status,
           r.room_number, r.capacity AS room_capacity, r.has_computers, ts.day, ts.start_time, ts.end_time,
           {$reportEndTimeExpression} AS report_end_time,
           p.id AS program_id, p.program_name, p.program_code,
           d.id AS resolved_department_id, d.dept_name, d.dept_code,
           j.job_name, j.input_data
    FROM schedules s
    JOIN subjects sub ON s.subject_id = sub.id
    LEFT JOIN programs p ON sub.program_id = p.id
    LEFT JOIN departments d ON p.department_id = d.id
    JOIN instructors i ON s.instructor_id = i.id
    JOIN users u ON i.user_id = u.id
    JOIN rooms r ON s.room_id = r.id
    JOIN time_slots ts ON s.time_slot_id = ts.id
    JOIN schedule_jobs j ON s.job_id = j.id
    WHERE s.is_published = 1
";

$params = [];

if ($department_id > 0) {
    $query .= " AND d.id = ?";
    $params[] = $department_id;
}

if ($program_id > 0) {
    $query .= " AND p.id = ?";
    $params[] = $program_id;
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

$conflict_summary = [
    'published_rows' => 0,
    'published_jobs' => 0,
    'completed_jobs' => 0,
    'room_conflicts' => 0,
    'instructor_conflicts' => 0,
    'section_conflicts' => 0,
    'sample_rows' => [],
    'checked_at' => date('F j, Y g:i A'),
];
try {
    $publishedSummary = $pdo->query("
        SELECT
            COUNT(*) AS published_rows,
            COUNT(DISTINCT job_id) AS published_jobs
        FROM schedules
        WHERE is_published = 1
    ")->fetch(PDO::FETCH_ASSOC) ?: [];
    $conflict_summary['published_rows'] = (int)($publishedSummary['published_rows'] ?? 0);
    $conflict_summary['published_jobs'] = (int)($publishedSummary['published_jobs'] ?? 0);
    $conflict_summary['completed_jobs'] = (int)$pdo->query("SELECT COUNT(*) FROM schedule_jobs WHERE status = 'completed'")->fetchColumn();

    $conflictCountQueries = [
        'room_conflicts' => "
            SELECT COUNT(*) FROM (
                SELECT 1
                FROM schedules s
                WHERE s.is_published = 1
                GROUP BY s.time_slot_id, s.room_id
                HAVING COUNT(*) > 1
            ) AS room_conflicts
        ",
        'instructor_conflicts' => "
            SELECT COUNT(*) FROM (
                SELECT 1
                FROM schedules s
                WHERE s.is_published = 1
                GROUP BY s.time_slot_id, s.instructor_id
                HAVING COUNT(*) > 1
            ) AS instructor_conflicts
        ",
        'section_conflicts' => "
            SELECT COUNT(*) FROM (
                SELECT 1
                FROM schedules s
                WHERE s.is_published = 1
                GROUP BY s.time_slot_id, s.department, s.year_level, s.section
                HAVING COUNT(*) > 1
            ) AS section_conflicts
        ",
    ];
    foreach ($conflictCountQueries as $key => $sql) {
        $conflict_summary[$key] = (int)$pdo->query($sql)->fetchColumn();
    }

    $conflict_summary['sample_rows'] = $pdo->query("
        SELECT *
        FROM (
            SELECT
                'Room' AS conflict_group,
                ts.day,
                ts.start_time,
                ts.end_time,
                r.room_number AS resource_label,
                NULL AS section_label,
                COUNT(*) AS hits
            FROM schedules s
            JOIN time_slots ts ON s.time_slot_id = ts.id
            JOIN rooms r ON s.room_id = r.id
            WHERE s.is_published = 1
            GROUP BY s.time_slot_id, s.room_id, ts.day, ts.start_time, ts.end_time, r.room_number
            HAVING COUNT(*) > 1

            UNION ALL

            SELECT
                'Instructor' AS conflict_group,
                ts.day,
                ts.start_time,
                ts.end_time,
                u.full_name AS resource_label,
                NULL AS section_label,
                COUNT(*) AS hits
            FROM schedules s
            JOIN time_slots ts ON s.time_slot_id = ts.id
            JOIN instructors i ON s.instructor_id = i.id
            JOIN users u ON i.user_id = u.id
            WHERE s.is_published = 1
            GROUP BY s.time_slot_id, s.instructor_id, ts.day, ts.start_time, ts.end_time, u.full_name
            HAVING COUNT(*) > 1

            UNION ALL

            SELECT
                'Section' AS conflict_group,
                ts.day,
                ts.start_time,
                ts.end_time,
                CONCAT(COALESCE(NULLIF(s.department, ''), 'Program'), ' ', s.year_level, '-', s.section) AS resource_label,
                CONCAT('Year ', s.year_level, ' Section ', s.section) AS section_label,
                COUNT(*) AS hits
            FROM schedules s
            JOIN time_slots ts ON s.time_slot_id = ts.id
            WHERE s.is_published = 1
            GROUP BY s.time_slot_id, s.department, s.year_level, s.section, ts.day, ts.start_time, ts.end_time
            HAVING COUNT(*) > 1
        ) AS conflict_samples
        ORDER BY hits DESC, day, start_time
        LIMIT 6
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $conflict_summary['sample_rows'] = [];
}
$conflict_summary['total_conflicts'] =
    (int)$conflict_summary['room_conflicts']
    + (int)$conflict_summary['instructor_conflicts']
    + (int)$conflict_summary['section_conflicts'];
$conflict_summary['is_clear'] = $conflict_summary['total_conflicts'] === 0;
$conflict_summary['visible_rows'] = count($schedules);

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

$format_block_label = static function (array $row, array &$job_input_cache) use ($normalizeProgramCode): string {
    $jobId = (int) ($row['job_id'] ?? 0);
    if (!array_key_exists($jobId, $job_input_cache)) {
        $raw = (string) ($row['input_data'] ?? '');
        $decoded = json_decode($raw, true);
        $job_input_cache[$jobId] = is_array($decoded) ? $decoded : [];
    }

    $jobInput = $job_input_cache[$jobId] ?? [];
    $programCode = $normalizeProgramCode($row['program_code'] ?? '');
    if ($programCode === '') {
        $programCode = $normalizeProgramCode($row['program_name'] ?? '');
    }
    if ($programCode === '') {
        $programCode = $normalizeProgramCode($jobInput['program'] ?? '');
    }
    if ($programCode === '' && !empty($jobInput['program_id'])) {
        $programCode = $normalizeProgramCode((string) $jobInput['program_id']);
    }
    if ($programCode === '') {
        $programCode = $normalizeProgramCode($row['department'] ?? '');
    }

    $year = (int) ($row['year_level'] ?? 0);
    $block = strtoupper(trim((string) ($row['section'] ?? '')));
    $suffix = $block === '' ? (string) $year : ($year . $block);

    if ($programCode === '') {
        return $suffix;
    }
    return trim($programCode . ' ' . $suffix);
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
$format_slot_label_with_day = static function (string $baseLabel, array $row, int $slotRowCount, bool $forceDayLabel = false): string {
    $label = trim((string)$baseLabel);
    if ($label === '') {
        return $label;
    }
    if ($slotRowCount <= 1) {
        if (!$forceDayLabel) {
            return $label;
        }
    }
    $day = trim((string)($row['day'] ?? ''));
    if ($day === '') {
        return $label;
    }
    return $label . ' (' . $day . ')';
};

$resolve_row_meeting_kind = static function (array $row): string {
    $meetingKind = strtolower(trim((string) ($row['meeting_kind'] ?? '')));
    if ($meetingKind === 'lecture' || $meetingKind === 'lab') {
        return $meetingKind;
    }

    $lectureHours = (float) ($row['lecture_hours'] ?? 0);
    $labHours = (float) ($row['lab_hours'] ?? 0);
    if ($labHours > 0 && $lectureHours <= 0) {
        return 'lab';
    }
    if ($lectureHours > 0 && $labHours <= 0) {
        return 'lecture';
    }
    if ($lectureHours > 0 && $labHours > 0) {
        return ((int) ($row['has_computers'] ?? 0) === 1) ? 'lab' : 'lecture';
    }

    return '';
};

$format_subject_description = static function (array $row) use ($resolve_row_meeting_kind): string {
    $subjectName = trim((string) ($row['subject_name'] ?? ''));
    $meetingKind = $resolve_row_meeting_kind($row);
    if ($meetingKind === 'lecture') {
        return $subjectName . ' (Lec)';
    }
    if ($meetingKind === 'lab') {
        return $subjectName . ' (Lab)';
    }
    return $subjectName;
};

$build_section_row_signature = static function (array $row, bool $includeRoom = true) use ($resolve_row_meeting_kind): string {
    $subjectKey = strtoupper(trim((string) ($row['subject_code'] ?? '')));
    if ($subjectKey === '') {
        $subjectKey = (string) ((int) ($row['subject_id'] ?? 0));
    }

    $meetingKind = $resolve_row_meeting_kind($row);

    $signatureParts = [
        (string) $subjectKey,
        strtoupper(trim((string) ($row['subject_name'] ?? ''))),
        $meetingKind,
        (string) ($row['start_time'] ?? ''),
        (string) ($row['end_time'] ?? ''),
        strtoupper(trim((string) ($row['instructor_name'] ?? ''))),
        (string) ($row['credits'] ?? ''),
        (string) ($row['scheduled_hours'] ?? $row['hours_per_week'] ?? ''),
    ];

    if ($includeRoom) {
        $signatureParts[] = strtoupper(trim((string) ($row['room_number'] ?? '')));
    }

    return implode('|', $signatureParts);
};
$merge_section_row_room_labels = static function (array &$targetRow, array $sourceRow): void {
    $dayOrder = [
        'Monday' => 1,
        'Tuesday' => 2,
        'Wednesday' => 3,
        'Thursday' => 4,
        'Friday' => 5,
        'Saturday' => 6,
    ];
    $dayShortLabels = [
        'Monday' => 'Mon',
        'Tuesday' => 'Tue',
        'Wednesday' => 'Wed',
        'Thursday' => 'Thu',
        'Friday' => 'Fri',
        'Saturday' => 'Sat',
    ];

    $roomMap = $targetRow['_report_room_map'] ?? [];
    if (!is_array($roomMap)) {
        $roomMap = [];
    }

    foreach ([$targetRow, $sourceRow] as $row) {
        $day = trim((string)($row['day'] ?? ''));
        $room = trim((string)($row['room_number'] ?? ''));
        if ($day !== '' && $room !== '') {
            $roomMap[$day] = $room;
        }
    }

    if (empty($roomMap)) {
        $targetRow['report_room_label'] = trim((string)($targetRow['room_number'] ?? ''));
        return;
    }

    uksort($roomMap, static function (string $a, string $b) use ($dayOrder): int {
        return ($dayOrder[$a] ?? 99) <=> ($dayOrder[$b] ?? 99);
    });

    $targetRow['_report_room_map'] = $roomMap;
    $uniqueRooms = array_values(array_unique(array_filter(array_values($roomMap), static fn($room): bool => $room !== '')));
    if (count($uniqueRooms) <= 1) {
        $targetRow['report_room_label'] = $uniqueRooms[0] ?? trim((string)($targetRow['room_number'] ?? ''));
        return;
    }

    $roomParts = [];
    foreach ($roomMap as $day => $room) {
        $roomParts[] = ($dayShortLabels[$day] ?? $day) . ': ' . $room;
    }
    $targetRow['report_room_label'] = implode(' / ', $roomParts);
};

$workload_group_titles = [
    'MTh/Morning' => 'MTH/Morning',
    'MTh/Afternoon' => 'MTH/Afternoon',
    'Wed/Morning' => 'WED/Morning',
    'Wed/Afternoon' => 'WED/Afternoon',
    'TF/Morning' => 'TF/Morning',
    'TF/Afternoon' => 'TF/Afternoon',
    'Monday/Morning' => 'MONDAY/Morning',
    'Monday/Afternoon' => 'MONDAY/Afternoon',
    'Tuesday/Morning' => 'TUESDAY/Morning',
    'Tuesday/Afternoon' => 'TUESDAY/Afternoon',
    'Wednesday/Morning' => 'WEDNESDAY/Morning',
    'Wednesday/Afternoon' => 'WEDNESDAY/Afternoon',
    'Thursday/Morning' => 'THURSDAY/Morning',
    'Thursday/Afternoon' => 'THURSDAY/Afternoon',
    'Friday/Morning' => 'FRIDAY/Morning',
    'Friday/Afternoon' => 'FRIDAY/Afternoon',
    'Saturday' => 'SATURDAY',
];

$section_group_titles = [
    'MTh/A.M.' => 'MTh/Morning',
    'MTh/P.M.' => 'MTh/Afternoon',
    'TF/A.M.' => 'TF/Morning',
    'TF/P.M.' => 'TF/Afternoon',
    'Wed/A.M.' => 'Wed/Morning',
    'Wed/P.M.' => 'Wed/Afternoon',
    'Monday/A.M.' => 'Monday/Morning',
    'Monday/P.M.' => 'Monday/Afternoon',
    'Tuesday/A.M.' => 'Tuesday/Morning',
    'Tuesday/P.M.' => 'Tuesday/Afternoon',
    'Wednesday/A.M.' => 'Wednesday/Morning',
    'Wednesday/P.M.' => 'Wednesday/Afternoon',
    'Thursday/A.M.' => 'Thursday/Morning',
    'Thursday/P.M.' => 'Thursday/Afternoon',
    'Friday/A.M.' => 'Friday/Morning',
    'Friday/P.M.' => 'Friday/Afternoon',
    'Saturday' => 'SATURDAY',
];

$format_schedule_time_label = static function ($startTime, $endTime): string {
    return date('g:i', strtotime((string) $startTime)) . '-' . date('g:i', strtotime((string) $endTime));
};
$break_time_label = '11:30-1:00';
$meeting_kind_rank = static function (array $row) use ($resolve_row_meeting_kind): int {
    $meetingKind = $resolve_row_meeting_kind($row);
    if ($meetingKind === 'lecture') {
        return 0;
    }
    if ($meetingKind === 'lab') {
        return 1;
    }
    return 2;
};

$sanitize_export_filename = static function (string $value): string {
    $value = preg_replace('/[^A-Za-z0-9_-]+/', '_', trim($value));
    $value = trim((string) $value, '_');
    return $value !== '' ? $value : 'report';
};

$job_input_cache = [];
$resolve_section_grouping_mode = static function (array $row, array &$job_input_cache): string {
    $jobId = (int)($row['job_id'] ?? 0);
    if (!array_key_exists($jobId, $job_input_cache)) {
        $raw = (string)($row['input_data'] ?? '');
        $decoded = json_decode($raw, true);
        $job_input_cache[$jobId] = is_array($decoded) ? $decoded : [];
    }

    $jobInput = $job_input_cache[$jobId] ?? [];
    $constraints = is_array($jobInput['constraints'] ?? null) ? $jobInput['constraints'] : [];
    if (($constraints['day_grouping_mode'] ?? '') === 'individual') {
        return 'individual';
    }
    if (!empty($constraints['individual_weekdays'])) {
        return 'individual';
    }

    return 'paired';
};
$get_paired_group_multiplier = static function (string $groupKey, string $groupMode): float {
    if ($groupMode !== 'paired') {
        return 1.0;
    }
    return preg_match('/^(MTh|TF)\//i', trim($groupKey)) ? 2.0 : 1.0;
};
$get_effective_slot_multiplier = static function (float $baseMultiplier, int $slotRowCount): float {
    // If a paired bucket already has multiple explicit rows (typically different days/subjects),
    // avoid doubling each row; treat each as an explicit single schedule entry.
    if ($baseMultiplier > 1.0 && $slotRowCount > 1) {
        return 1.0;
    }
    return $baseMultiplier;
};
$build_subject_unit_key = static function (array $row) use ($resolve_row_meeting_kind): string {
    $subjectKey = (int)($row['subject_id'] ?? 0);
    if ($subjectKey > 0) {
        $subjectKey = (string)$subjectKey;
    } else {
        $subjectKey = strtoupper(trim((string)($row['subject_code'] ?? '')));
    }
    if ($subjectKey === '') {
        return '';
    }

    $meetingKind = $resolve_row_meeting_kind($row);
    if ($meetingKind === '') {
        $meetingKind = 'general';
    }

    return strtoupper(trim((string)$subjectKey)) . '|' . $meetingKind;
};
$get_row_units = static function (array $row) use ($resolve_row_meeting_kind): float {
    $meetingKind = $resolve_row_meeting_kind($row);
    $lectureCredits = (float)($row['lecture_credits'] ?? 0);
    $labCredits = (float)($row['lab_credits'] ?? 0);
    $lectureHours = (float)($row['lecture_hours'] ?? 0);
    $labHours = (float)($row['lab_hours'] ?? 0);

    if ($meetingKind === 'lecture') {
        if ($lectureCredits > 0) {
            return $lectureCredits;
        }
        if ($lectureHours > 0) {
            return $lectureHours;
        }
    } elseif ($meetingKind === 'lab') {
        if ($labCredits > 0) {
            return $labCredits;
        }
        if ($labHours > 0) {
            return $labHours;
        }
    }

    if ($lectureCredits > 0 && $labCredits <= 0) {
        return $lectureCredits;
    }
    if ($labCredits > 0 && $lectureCredits <= 0) {
        return $labCredits;
    }

    $credits = (float)($row['credits'] ?? 0);
    $hours = (float)($row['scheduled_hours'] ?? $row['hours_per_week'] ?? 0);

    if ($credits <= 0 && $hours > 0) {
        return $hours;
    }
    if ($hours > $credits) {
        return $hours;
    }
    return $credits;
};
$build_special_approval_key = static function (array $row) use ($format_course_code, &$job_input_cache): string {
    $subjectKey = (int)($row['subject_id'] ?? 0);
    if ($subjectKey > 0) {
        $subjectKey = (string)$subjectKey;
    } else {
        $subjectKey = strtoupper(trim((string)($row['subject_code'] ?? '')));
    }
    $subjectKey = strtoupper(trim((string)$subjectKey));
    if ($subjectKey === '') {
        return '';
    }

    $courseCode = strtoupper(trim((string)($row['report_course_code'] ?? '')));
    if ($courseCode === '') {
        $courseCode = strtoupper(trim($format_course_code($row, $job_input_cache)));
    }
    if ($courseCode === '') {
        $courseCode = strtoupper(trim((string)(($row['year_level'] ?? '') . ($row['section'] ?? ''))));
    }

    return $subjectKey . '|' . $courseCode;
};
$format_special_approval_subject_label = static function (array $subject): string {
    $base = trim((string)($subject['subject_code'] ?? '') . ' - ' . (string)($subject['subject_name'] ?? ''), ' -');
    $courseCode = trim((string)($subject['course_code'] ?? ''));
    if ($courseCode === '') {
        return $base;
    }
    if ($base === '') {
        return $courseCode;
    }
    return $base . ' (' . $courseCode . ')';
};
$build_instructor_load_snapshot = static function (array $rows) use (
    $resolve_section_grouping_mode,
    &$job_input_cache,
    $day_to_group,
    $format_workload_time,
    $get_paired_group_multiplier,
    $get_effective_slot_multiplier,
    $build_subject_unit_key,
    $get_row_units
): array {
    $workloadMode = 'paired';
    foreach ($rows as $row) {
        if ($resolve_section_grouping_mode($row, $job_input_cache) === 'individual') {
            $workloadMode = 'individual';
            break;
        }
    }

    $slotCounts = [];
    foreach ($rows as $slotProbeRow) {
        if (($slotProbeRow['day'] ?? '') === 'Saturday') {
            $probeGroupKey = 'Saturday';
        } elseif ($workloadMode === 'individual') {
            $probePeriod = (strtotime((string)$slotProbeRow['start_time']) < strtotime('12:00:00')) ? 'Morning' : 'Afternoon';
            $probeGroupKey = (string)$slotProbeRow['day'] . '/' . $probePeriod;
        } else {
            $probeDayGroup = $day_to_group[$slotProbeRow['day']] ?? $slotProbeRow['day'];
            $probePeriod = (strtotime((string)$slotProbeRow['start_time']) < strtotime('12:00:00')) ? 'Morning' : 'Afternoon';
            $probeGroupKey = $probeDayGroup . '/' . $probePeriod;
        }

        $probeEnd = (string)($slotProbeRow['report_end_time'] ?? $slotProbeRow['end_time'] ?? '');
        $probeTimeLabel = $format_workload_time($slotProbeRow['start_time'] ?? '', $probeEnd);
        if (!isset($slotCounts[$probeGroupKey])) {
            $slotCounts[$probeGroupKey] = [];
        }
        $slotCounts[$probeGroupKey][$probeTimeLabel] = (int)($slotCounts[$probeGroupKey][$probeTimeLabel] ?? 0) + 1;
    }

    $countedSubjectUnits = [];
    $instructorWorkload = [];
    $subjectCandidates = [];
    $totalUnits = 0.0;
    $totalHours = 0.0;

    foreach ($rows as $row) {
        if (($row['day'] ?? '') === 'Saturday') {
            $groupKey = 'Saturday';
        } elseif ($workloadMode === 'individual') {
            $period = (strtotime((string)$row['start_time']) < strtotime('12:00:00')) ? 'Morning' : 'Afternoon';
            $groupKey = (string)$row['day'] . '/' . $period;
        } else {
            $dayGroup = $day_to_group[$row['day']] ?? $row['day'];
            $period = (strtotime((string)$row['start_time']) < strtotime('12:00:00')) ? 'Morning' : 'Afternoon';
            $groupKey = $dayGroup . '/' . $period;
        }

        $basePairMultiplier = $get_paired_group_multiplier((string)$groupKey, (string)$workloadMode);
        $row['report_end_time'] = (string)($row['report_end_time'] ?? $row['end_time'] ?? '');
        $row['report_time_label'] = $format_workload_time($row['start_time'] ?? '', $row['report_end_time']);
        $slotRowCount = (int)($slotCounts[$groupKey][$row['report_time_label']] ?? 1);
        $effectivePairMultiplier = $get_effective_slot_multiplier((float)$basePairMultiplier, $slotRowCount);
        $row['report_pair_multiplier'] = (float)$effectivePairMultiplier;
        $instructorWorkload[$groupKey][] = $row;

        $subjectUnitKey = $build_subject_unit_key($row);
        if ($subjectUnitKey !== '' && !isset($countedSubjectUnits[$subjectUnitKey])) {
            $totalUnits += $get_row_units($row) * $effectivePairMultiplier;
            $countedSubjectUnits[$subjectUnitKey] = true;
        }

        $rowHours = (float)($row['scheduled_hours'] ?? $row['hours_per_week'] ?? 0) * $effectivePairMultiplier;
        $totalHours += $rowHours;

        $subjectKey = (int)($row['subject_id'] ?? 0);
        if ($subjectKey <= 0) {
            $subjectKey = strtoupper(trim((string)($row['subject_code'] ?? '')));
        } else {
            $subjectKey = (string)$subjectKey;
        }
        $subjectKey = strtoupper(trim((string)$subjectKey));
        if (!isset($subjectCandidates[$subjectKey])) {
            $subjectCandidates[$subjectKey] = [
                'subject_code' => (string)($row['subject_code'] ?? ''),
                'subject_name' => (string)($row['subject_name'] ?? ''),
                'hours' => 0.0,
                'units' => 0.0,
                'unit_keys' => [],
                'rows' => [],
            ];
        }
        $subjectCandidates[$subjectKey]['hours'] += $rowHours;
        $subjectCandidates[$subjectKey]['rows'][] = $row;
        if ($subjectUnitKey !== '') {
            $subjectCandidates[$subjectKey]['unit_keys'][$subjectUnitKey] = true;
        }
    }

    foreach ($subjectCandidates as &$candidateSubject) {
        $candidateSubject['hours'] = round((float)$candidateSubject['hours'], 2);
        foreach (array_keys($candidateSubject['unit_keys']) as $unitKey) {
            $unitRow = $candidateSubject['rows'][0] ?? [];
            foreach ($candidateSubject['rows'] as $candidateRow) {
                if ($build_subject_unit_key($candidateRow) === $unitKey) {
                    $unitRow = $candidateRow;
                    break;
                }
            }
            $unitPairMultiplier = (float)($unitRow['report_pair_multiplier'] ?? 1.0);
            $candidateSubject['units'] += $get_row_units($unitRow) * $unitPairMultiplier;
        }
        $candidateSubject['units'] = round((float)$candidateSubject['units'], 2);
    }
    unset($candidateSubject);

    uasort($subjectCandidates, static function (array $a, array $b): int {
        $hoursCompare = (float)($b['hours'] ?? 0) <=> (float)($a['hours'] ?? 0);
        if ($hoursCompare !== 0) {
            return $hoursCompare;
        }
        return strcmp((string)($a['subject_code'] ?? ''), (string)($b['subject_code'] ?? ''));
    });

    return [
        'mode' => $workloadMode,
        'total_units' => round($totalUnits, 2),
        'total_hours' => round($totalHours, 2),
        'subject_candidates' => $subjectCandidates,
        'workload' => $instructorWorkload,
    ];
};
$is_instructor_report = !empty($instructor_id);
$by_section = [];

$preprocessed_schedules = [];
foreach ($schedules as $row) {
    $row['report_end_time'] = (string)($row['report_end_time'] ?? $row['end_time'] ?? '');
    $row['report_time_label'] = $format_schedule_time_label($row['start_time'] ?? '', $row['report_end_time']);
    $preprocessed_schedules[] = $row;
}
$schedules = $preprocessed_schedules;

$praise_unit_limit = 24.0;
$special_approval_index = [
    'praise' => [],
    'overload' => [],
];
$build_instructor_report_url = static function (string $instructorName) use ($department_lookup, $program_lookup, $year_level): string {
    $query = [];
    if ($department_lookup !== '') {
        $query['department'] = $department_lookup;
    }
    if ($program_lookup !== '') {
        $query['program'] = $program_lookup;
    }
    if ((string)$year_level !== '') {
        $query['year_level'] = $year_level;
    }
    $query['instructor'] = $instructorName;
    return 'report.php?' . http_build_query($query);
};

if (!$is_instructor_report && !empty($schedules)) {
    $schedulesByInstructor = [];
    foreach ($schedules as $row) {
        $summaryInstructorId = (int)($row['instructor_id'] ?? 0);
        if ($summaryInstructorId <= 0) {
            continue;
        }
        if (!isset($schedulesByInstructor[$summaryInstructorId])) {
            $schedulesByInstructor[$summaryInstructorId] = [];
        }
        $schedulesByInstructor[$summaryInstructorId][] = $row;
    }

    $summaryDayOrder = [
        'Monday' => 1,
        'Tuesday' => 2,
        'Wednesday' => 3,
        'Thursday' => 4,
        'Friday' => 5,
        'Saturday' => 6,
    ];

    foreach ($schedulesByInstructor as $summaryInstructorId => $instructorRows) {
        $firstInstructorRow = $instructorRows[0] ?? [];
        $instructorName = trim((string)($firstInstructorRow['instructor_name'] ?? ''));
        $instructorStatus = trim((string)($firstInstructorRow['instructor_status'] ?? ''));
        if ($instructorName === '') {
            continue;
        }

        $loadSnapshot = $build_instructor_load_snapshot($instructorRows);
        $subjectCandidates = $loadSnapshot['subject_candidates'] ?? [];

        if ($instructorStatus === 'Permanent' && $loadSnapshot['total_units'] > $praise_unit_limit && !empty($subjectCandidates)) {
            $remainingPraiseUnits = round(max(0, (float)$loadSnapshot['total_units'] - $praise_unit_limit), 2);
            $selectedPraiseSubjects = [];
            foreach ($subjectCandidates as $candidateSubject) {
                if ($remainingPraiseUnits <= 0) {
                    break;
                }
                $selectedPraiseSubjects[] = [
                    'subject_code' => (string)($candidateSubject['subject_code'] ?? ''),
                    'subject_name' => (string)($candidateSubject['subject_name'] ?? ''),
                    'units' => round((float)($candidateSubject['units'] ?? 0), 2),
                ];
                $remainingPraiseUnits = round($remainingPraiseUnits - (float)($candidateSubject['units'] ?? 0), 2);
            }

            $special_approval_index['praise'][] = [
                'instructor_name' => $instructorName,
                'total_units' => round((float)$loadSnapshot['total_units'], 2),
                'excess_units' => round(max(0, (float)$loadSnapshot['total_units'] - $praise_unit_limit), 2),
                'subjects' => $selectedPraiseSubjects,
                'report_url' => $build_instructor_report_url($instructorName),
            ];
        }

        if ($instructorStatus !== 'Permanent' && $loadSnapshot['total_hours'] > $weekly_hour_limit && !empty($subjectCandidates)) {
            $remainingOverload = round(max(0, (float)$loadSnapshot['total_hours'] - $weekly_hour_limit), 2);
            $candidateRows = [];
            foreach (($loadSnapshot['workload'] ?? []) as $groupKey => $groupRows) {
                foreach ($groupRows as $workloadRow) {
                    $rowPairMultiplier = (float)($workloadRow['report_pair_multiplier'] ?? $get_paired_group_multiplier((string)$groupKey, (string)($loadSnapshot['mode'] ?? 'paired')));
                    $rowHours = round((float)($workloadRow['scheduled_hours'] ?? $workloadRow['hours_per_week'] ?? 0) * $rowPairMultiplier, 2);
                    if ($rowHours <= 0) {
                        continue;
                    }

                    $subjectKey = (int)($workloadRow['subject_id'] ?? 0);
                    if ($subjectKey <= 0) {
                        $subjectKey = strtoupper(trim((string)($workloadRow['subject_code'] ?? '')));
                    } else {
                        $subjectKey = (string)$subjectKey;
                    }
                    $subjectKey = strtoupper(trim((string)$subjectKey));

                    $candidateRows[] = [
                        'subject_key' => $subjectKey,
                        'row_hours' => $rowHours,
                        'subject_total_hours' => (float)($subjectCandidates[$subjectKey]['hours'] ?? 0),
                        'day_rank' => (int)($summaryDayOrder[$workloadRow['day'] ?? ''] ?? 99),
                        'start_time' => (string)($workloadRow['start_time'] ?? ''),
                    ];
                }
            }

            usort($candidateRows, static function (array $a, array $b): int {
                $subjectCompare = (float)$b['subject_total_hours'] <=> (float)$a['subject_total_hours'];
                if ($subjectCompare !== 0) {
                    return $subjectCompare;
                }
                $hoursCompare = (float)$b['row_hours'] <=> (float)$a['row_hours'];
                if ($hoursCompare !== 0) {
                    return $hoursCompare;
                }
                $dayCompare = (int)$b['day_rank'] <=> (int)$a['day_rank'];
                if ($dayCompare !== 0) {
                    return $dayCompare;
                }
                return strcmp((string)$b['start_time'], (string)$a['start_time']);
            });

            $selectedOverloadSubjectKeys = [];
            foreach ($candidateRows as $candidateRow) {
                if ($remainingOverload <= 0) {
                    break;
                }
                $selectedOverloadSubjectKeys[(string)$candidateRow['subject_key']] = (string)$candidateRow['subject_key'];
                $remainingOverload = round($remainingOverload - (float)$candidateRow['row_hours'], 2);
            }

            $selectedOverloadSubjects = [];
            foreach (array_values($selectedOverloadSubjectKeys) as $subjectKey) {
                if (!isset($subjectCandidates[$subjectKey])) {
                    continue;
                }
                $selectedOverloadSubjects[] = [
                    'subject_code' => (string)($subjectCandidates[$subjectKey]['subject_code'] ?? ''),
                    'subject_name' => (string)($subjectCandidates[$subjectKey]['subject_name'] ?? ''),
                    'hours' => round((float)($subjectCandidates[$subjectKey]['hours'] ?? 0), 2),
                ];
            }

            $special_approval_index['overload'][] = [
                'instructor_name' => $instructorName,
                'total_hours' => round((float)$loadSnapshot['total_hours'], 2),
                'excess_hours' => round(max(0, (float)$loadSnapshot['total_hours'] - $weekly_hour_limit), 2),
                'subjects' => $selectedOverloadSubjects,
                'report_url' => $build_instructor_report_url($instructorName),
            ];
        }
    }

    usort($special_approval_index['praise'], static function (array $a, array $b): int {
        $excessCompare = (float)($b['excess_units'] ?? 0) <=> (float)($a['excess_units'] ?? 0);
        if ($excessCompare !== 0) {
            return $excessCompare;
        }
        return strcmp((string)($a['instructor_name'] ?? ''), (string)($b['instructor_name'] ?? ''));
    });
    usort($special_approval_index['overload'], static function (array $a, array $b): int {
        $excessCompare = (float)($b['excess_hours'] ?? 0) <=> (float)($a['excess_hours'] ?? 0);
        if ($excessCompare !== 0) {
            return $excessCompare;
        }
        return strcmp((string)($a['instructor_name'] ?? ''), (string)($b['instructor_name'] ?? ''));
    });
}

$export_type = strtolower(trim((string) ($_GET['export'] ?? '')));
if (in_array($export_type, ['csv', 'excel'], true)) {
    $day_sort_order = [
        'Monday' => 1,
        'Tuesday' => 2,
        'Wednesday' => 3,
        'Thursday' => 4,
        'Friday' => 5,
        'Saturday' => 6,
    ];
    $sorted_export_source = $schedules;
    usort($sorted_export_source, static function (array $a, array $b) use ($day_sort_order): int {
        $programA = strtoupper(trim((string) ($a['program_code'] ?: $a['program_name'] ?: '')));
        $programB = strtoupper(trim((string) ($b['program_code'] ?: $b['program_name'] ?: '')));
        if ($programA !== $programB) {
            return strcmp($programA, $programB);
        }

        $yearA = (int) ($a['year_level'] ?? 0);
        $yearB = (int) ($b['year_level'] ?? 0);
        if ($yearA !== $yearB) {
            return $yearA <=> $yearB;
        }

        $sectionA = strtoupper(trim((string) ($a['section'] ?? '')));
        $sectionB = strtoupper(trim((string) ($b['section'] ?? '')));
        if ($sectionA !== $sectionB) {
            return strcmp($sectionA, $sectionB);
        }

        $dayA = $day_sort_order[(string) ($a['day'] ?? '')] ?? 99;
        $dayB = $day_sort_order[(string) ($b['day'] ?? '')] ?? 99;
        if ($dayA !== $dayB) {
            return $dayA <=> $dayB;
        }

        return strcmp((string) ($a['start_time'] ?? ''), (string) ($b['start_time'] ?? ''));
    });

    $export_headers = ['Department', 'Program', 'Year Level', 'Section', 'Day', 'Start Time', 'End Time', 'Subject Code', 'Subject Name', 'Description', 'Units', 'Hours', 'Instructor', 'Room', 'Block Label'];
    $export_rows = [];
    $last_program = null;
    foreach ($sorted_export_source as $row) {
        $current_program = (string) ($row['program_code'] ?: $row['program_name'] ?: 'Unassigned');
        if ($last_program !== null && strcasecmp($last_program, $current_program) !== 0) {
            $export_rows[] = array_fill_keys($export_headers, '');
        }
        $last_program = $current_program;

        $export_rows[] = [
            'Department' => (string) ($row['dept_code'] ?: $row['dept_name'] ?: ''),
            'Program' => $current_program,
            'Year Level' => (string) ($row['year_level'] ?? ''),
            'Section' => (string) ($row['section'] ?? ''),
            'Day' => (string) ($row['day'] ?? ''),
            'Start Time' => date('h:i A', strtotime((string) ($row['start_time'] ?? ''))),
            'End Time' => date('h:i A', strtotime((string) ($row['end_time'] ?? ''))),
            'Subject Code' => (string) ($row['subject_code'] ?? ''),
            'Subject Name' => (string) ($row['subject_name'] ?? ''),
            'Description' => $format_subject_description($row),
            'Units' => number_format($get_row_units($row), 2),
            'Hours' => number_format((float) ($row['scheduled_hours'] ?? $row['hours_per_week'] ?? 0), 2),
            'Instructor' => (string) ($row['instructor_name'] ?? ''),
            'Room' => (string) ($row['room_number'] ?? ''),
            'Block Label' => $format_block_label($row, $job_input_cache),
        ];
    }

    $file_parts = ['schedule_report'];
    if ($program_lookup !== '') {
        $file_parts[] = $sanitize_export_filename($program_lookup);
    }
    if ($instructor_lookup !== '') {
        $file_parts[] = $sanitize_export_filename($instructor_lookup);
    }
    if ($year_level !== '') {
        $file_parts[] = 'year_' . $sanitize_export_filename((string) $year_level);
    }
    $file_parts[] = date('Y-m-d');
    $filename = implode('_', array_filter($file_parts));

    if ($export_type === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($output, $export_headers);
        foreach ($export_rows as $export_row) {
            fputcsv($output, $export_row);
        }
        fclose($output);
        exit();
    }

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    echo "\xEF\xBB\xBF";
    echo '<table border="1"><thead><tr>';
    foreach ($export_headers as $header_label) {
        echo '<th>' . htmlspecialchars($header_label) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($export_rows as $export_row) {
        echo '<tr>';
        foreach ($export_row as $value) {
            echo '<td>' . htmlspecialchars((string) $value) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table>';
    exit();
}

if (!$is_instructor_report) {
    foreach ($schedules as $row) {
        $sec = (string)($row['section'] ?? '');
        $programKey = strtoupper(trim((string)($row['program_code'] ?? '')));
        if ($programKey === '') {
            $programKey = strtoupper(trim((string)($row['program_name'] ?? '')));
        }
        if ($programKey === '') {
            $programKey = strtoupper(trim((string)($row['department'] ?? '')));
        }
        $key = $programKey . '|' . (int)$row['year_level'] . '|' . strtoupper(trim($sec));
        if (!isset($by_section[$key])) {
            $by_section[$key] = ['label' => $format_block_label($row, $job_input_cache), 'rows' => []];
        }
        $by_section[$key]['rows'][] = $row;
    }

    // Within each section, group by day group (e.g. MTh/A.M.) and sort by time
    foreach ($by_section as $key => &$section) {
        $by_day = [];
        $section_seen_rows = [];
        $section_total_units = 0.0;
        $counted_subject_units = [];
        $section_group_mode = 'paired';
        $section_slot_counts = [];
        foreach ($section['rows'] as $slotProbeRow) {
            $probe_mode = $resolve_section_grouping_mode($slotProbeRow, $job_input_cache);
            if (($slotProbeRow['day'] ?? '') === 'Saturday') {
                $probe_group_key = 'Saturday';
            } elseif ($probe_mode === 'individual') {
                $probe_period = (strtotime($slotProbeRow['start_time']) < strtotime('12:00:00')) ? 'A.M.' : 'P.M.';
                $probe_group_key = $slotProbeRow['day'] . '/' . $probe_period;
            } else {
                $probe_dg = $day_to_group[$slotProbeRow['day']] ?? $slotProbeRow['day'];
                $probe_period = (strtotime($slotProbeRow['start_time']) < strtotime('12:00:00')) ? 'A.M.' : 'P.M.';
                $probe_group_key = $probe_dg . '/' . $probe_period;
            }
            $probe_time_label = (string)($slotProbeRow['report_time_label'] ?? '');
            if (!isset($section_slot_counts[$probe_group_key])) {
                $section_slot_counts[$probe_group_key] = [];
            }
            $section_slot_counts[$probe_group_key][$probe_time_label] = (int)($section_slot_counts[$probe_group_key][$probe_time_label] ?? 0) + 1;
        }
        foreach ($section['rows'] as $row) {
            $section_group_mode = $resolve_section_grouping_mode($row, $job_input_cache);
            if (($row['day'] ?? '') === 'Saturday') {
                $group_key = 'Saturday';
            } elseif ($section_group_mode === 'individual') {
                $period = (strtotime($row['start_time']) < strtotime('12:00:00')) ? 'A.M.' : 'P.M.';
                $group_key = $row['day'] . '/' . $period;
            } else {
                $dg = $day_to_group[$row['day']] ?? $row['day'];
                $period = (strtotime($row['start_time']) < strtotime('12:00:00')) ? 'A.M.' : 'P.M.';
                $group_key = $dg . '/' . $period;
            }
            if (!isset($by_day[$group_key])) {
                $by_day[$group_key] = [];
            }
            $rowTimeLabel = (string)($row['report_time_label'] ?? '');
            $rawSlotRowCount = (int)($section_slot_counts[$group_key][$rowTimeLabel] ?? 1);
            $includeRoomInSignature = ($section_group_mode !== 'paired' || $group_key === 'Saturday');
            $row_signature = $group_key . '|' . $build_section_row_signature($row, $includeRoomInSignature);
            if (!isset($section_seen_rows[$row_signature])) {
                $row['report_room_label'] = trim((string)($row['room_number'] ?? ''));
                $row['_report_slot_row_count'] = $rawSlotRowCount;
                if (!$includeRoomInSignature) {
                    $merge_section_row_room_labels($row, $row);
                }
                $by_day[$group_key][] = $row;
                $section_seen_rows[$row_signature] = count($by_day[$group_key]) - 1;
            } elseif (!$includeRoomInSignature) {
                $existingIndex = (int)$section_seen_rows[$row_signature];
                if (isset($by_day[$group_key][$existingIndex])) {
                    $by_day[$group_key][$existingIndex]['_report_slot_row_count'] = max(
                        (int)($by_day[$group_key][$existingIndex]['_report_slot_row_count'] ?? 1),
                        $rawSlotRowCount
                    );
                    $merge_section_row_room_labels($by_day[$group_key][$existingIndex], $row);
                }
            }
            $subject_unit_key = $build_subject_unit_key($row);
            if ($subject_unit_key !== '' && !isset($counted_subject_units[$subject_unit_key])) {
                $basePairMultiplier = $get_paired_group_multiplier((string)$group_key, (string)$section_group_mode);
                $slotRowCount = (int)($section_slot_counts[$group_key][$rowTimeLabel] ?? 1);
                $effectivePairMultiplier = $get_effective_slot_multiplier((float)$basePairMultiplier, $slotRowCount);
                $section_total_units += $get_row_units($row) * $effectivePairMultiplier;
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
        $section['day_group_mode'] = $section_group_mode;
        $section['total_units'] = round($section_total_units, 2);
    }
    unset($section);

    // Sort blocks for output by year level then block letter.
    uksort($by_section, function ($a, $b) {
        $partsA = explode('|', (string)$a, 3);
        $partsB = explode('|', (string)$b, 3);
        $programA = $partsA[0] ?? '';
        $programB = $partsB[0] ?? '';
        $yearA = (int)($partsA[1] ?? 0);
        $yearB = (int)($partsB[1] ?? 0);
        $sectionA = $partsA[2] ?? '';
        $sectionB = $partsB[2] ?? '';

        if ($programA !== $programB) {
            return strcmp($programA, $programB);
        }
        if ($yearA !== $yearB) {
            return $yearA - $yearB;
        }
        return strcmp($sectionA, $sectionB);
    });
}

// Instructor-specific workload view data
$paired_workload_order = ['MTh/Morning', 'MTh/Afternoon', 'Wed/Morning', 'Wed/Afternoon', 'TF/Morning', 'TF/Afternoon', 'Saturday'];
$individual_workload_order = ['Monday/Morning', 'Monday/Afternoon', 'Tuesday/Morning', 'Tuesday/Afternoon', 'Wednesday/Morning', 'Wednesday/Afternoon', 'Thursday/Morning', 'Thursday/Afternoon', 'Friday/Morning', 'Friday/Afternoon', 'Saturday'];
$workload_order = $paired_workload_order;
$instructor_workload_mode = 'paired';
$selected_instructor = null;
$instructor_workload = [];
$workload_time_template = [
    'Morning' => [],
    'Afternoon' => [],
];
$total_units = 0;
$total_hours = 0;
$total_preparations = 0;
$total_units_with_deloading = 0.0;
$is_overloaded = false;
$overload_approved = false;
$overload_approval = null;
$overload_subjects = [];
$overload_subject_rows = [];
$selected_overload_workload_rows = [];
$praise_subjects = [];
$praise_subject_rows = [];
$selected_praise_workload_rows = [];
$is_praise = false;
$workload_subject_candidates = [];
$selected_overload_subject_keys = [];
$selected_praise_subject_keys = [];
$actual_total_units = 0.0;
$actual_total_hours = 0.0;
$actual_total_units_with_deloading = 0.0;
$actual_total_preparations = 0;
$consultation_slot_map = [];
$consultation_slot_list = [];
$special_approval_pages = [];

try {
    $timeSlotRows = $pdo->query("
        SELECT DISTINCT start_time, end_time
        FROM time_slots
        WHERE COALESCE(slot_type, 'regular') = 'regular'
        ORDER BY start_time, end_time
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($timeSlotRows as $slotRow) {
        $startTime = (string)($slotRow['start_time'] ?? '');
        $endTime = (string)($slotRow['end_time'] ?? '');
        if ($startTime === '' || $endTime === '') {
            continue;
        }
        $slotLabel = $format_workload_time($startTime, $endTime);
        $period = (strtotime($startTime) < strtotime('12:00:00')) ? 'Morning' : 'Afternoon';
        if (!in_array($slotLabel, $workload_time_template[$period], true)) {
            $workload_time_template[$period][] = $slotLabel;
        }
    }
} catch (Exception $e) {
    // Fallback template for workload table when time slot lookup fails.
}

if (empty($workload_time_template['Morning'])) {
    $workload_time_template['Morning'] = ['7:00-8:30', '8:30-10:00', '10:00-11:30'];
}
if (empty($workload_time_template['Afternoon'])) {
    $workload_time_template['Afternoon'] = ['1:00-2:30', '2:30-4:00', '4:00-5:30'];
}
if (!in_array($break_time_label, $workload_time_template['Morning'], true)) {
    $workload_time_template['Morning'][] = $break_time_label;
}
$section_time_slots_by_group = [
    'MTh/A.M.' => $workload_time_template['Morning'],
    'MTh/P.M.' => $workload_time_template['Afternoon'],
    'TF/A.M.' => $workload_time_template['Morning'],
    'TF/P.M.' => $workload_time_template['Afternoon'],
    'Wed/A.M.' => $workload_time_template['Morning'],
    'Wed/P.M.' => $workload_time_template['Afternoon'],
    'Monday/A.M.' => $workload_time_template['Morning'],
    'Monday/P.M.' => $workload_time_template['Afternoon'],
    'Tuesday/A.M.' => $workload_time_template['Morning'],
    'Tuesday/P.M.' => $workload_time_template['Afternoon'],
    'Wednesday/A.M.' => $workload_time_template['Morning'],
    'Wednesday/P.M.' => $workload_time_template['Afternoon'],
    'Thursday/A.M.' => $workload_time_template['Morning'],
    'Thursday/P.M.' => $workload_time_template['Afternoon'],
    'Friday/A.M.' => $workload_time_template['Morning'],
    'Friday/P.M.' => $workload_time_template['Afternoon'],
    'Saturday' => array_values(array_unique(array_merge($workload_time_template['Morning'], $workload_time_template['Afternoon']))),
];
$consultation_time_options_by_group = [];

if ($is_instructor_report) {
    $stmt = $pdo->prepare("
        SELECT i.id, u.full_name, i.department, i.specialization, i.status,
               i.education, i.eligibility, i.service_years,
               i.designation, i.designation_units,
               i.research_extension, i.research_extension_units,
               i.special_assignment, i.special_assignment_units
        FROM instructors i
        JOIN users u ON i.user_id = u.id
        WHERE i.id = ?
    ");
    $stmt->execute([$instructor_id]);
    $selected_instructor = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($selected_instructor) {
        $selected_instructor['major_display'] = trim((string)($selected_instructor['specialization'] ?? ''));
    }
    try {
        $stmt = $pdo->prepare("
            SELECT id, day_group, time_label, note
            FROM instructor_consultation_slots
            WHERE instructor_id = ?
            ORDER BY day_group, time_label
        ");
        $stmt->execute([$instructor_id]);
        $consultation_slot_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($consultation_slot_list as $slotRow) {
            $groupKey = (string)($slotRow['day_group'] ?? '');
            $timeKey = (string)($slotRow['time_label'] ?? '');
            if ($groupKey !== '' && $timeKey !== '') {
                if (!isset($consultation_slot_map[$groupKey])) {
                    $consultation_slot_map[$groupKey] = [];
                }
                $consultation_slot_map[$groupKey][$timeKey] = $slotRow;
            }
        }
    } catch (Exception $e) {
        $consultation_slot_map = [];
        $consultation_slot_list = [];
    }

    $counted_instructor_subject_units = [];
    foreach ($schedules as $row) {
        if ($resolve_section_grouping_mode($row, $job_input_cache) === 'individual') {
            $instructor_workload_mode = 'individual';
            break;
        }
    }
    $workload_order = ($instructor_workload_mode === 'individual') ? $individual_workload_order : $paired_workload_order;
    $instructor_slot_counts = [];
    foreach ($schedules as $slotProbeRow) {
        if (($slotProbeRow['day'] ?? '') === 'Saturday') {
            $probe_group_key = 'Saturday';
        } elseif ($instructor_workload_mode === 'individual') {
            $probe_period = (strtotime($slotProbeRow['start_time']) < strtotime('12:00:00')) ? 'Morning' : 'Afternoon';
            $probe_group_key = $slotProbeRow['day'] . '/' . $probe_period;
        } else {
            $probe_dg = $day_to_group[$slotProbeRow['day']] ?? $slotProbeRow['day'];
            $probe_period = (strtotime($slotProbeRow['start_time']) < strtotime('12:00:00')) ? 'Morning' : 'Afternoon';
            $probe_group_key = $probe_dg . '/' . $probe_period;
        }
        $probe_end = (string)($slotProbeRow['report_end_time'] ?? $slotProbeRow['end_time'] ?? '');
        $probe_time_label = $format_workload_time($slotProbeRow['start_time'], $probe_end);
        if (!isset($instructor_slot_counts[$probe_group_key])) {
            $instructor_slot_counts[$probe_group_key] = [];
        }
        $instructor_slot_counts[$probe_group_key][$probe_time_label] = (int)($instructor_slot_counts[$probe_group_key][$probe_time_label] ?? 0) + 1;
    }

    foreach ($schedules as $row) {
        if (($row['day'] ?? '') === 'Saturday') {
            $group_key = 'Saturday';
        } elseif ($instructor_workload_mode === 'individual') {
            $period = (strtotime($row['start_time']) < strtotime('12:00:00')) ? 'Morning' : 'Afternoon';
            $group_key = $row['day'] . '/' . $period;
        } else {
            $dg = $day_to_group[$row['day']] ?? $row['day'];
            $period = (strtotime($row['start_time']) < strtotime('12:00:00')) ? 'Morning' : 'Afternoon';
            $group_key = $dg . '/' . $period;
        }
        $basePairMultiplier = $get_paired_group_multiplier((string)$group_key, (string)$instructor_workload_mode);
        if (!isset($instructor_workload[$group_key])) {
            $instructor_workload[$group_key] = [];
        }
        $row['report_course_code'] = $format_course_code($row, $job_input_cache);
        $row['report_end_time'] = (string)($row['report_end_time'] ?? $row['end_time'] ?? '');
        $row['report_time_label'] = $format_workload_time($row['start_time'], $row['report_end_time']);
        $slotRowCount = (int)($instructor_slot_counts[$group_key][$row['report_time_label']] ?? 1);
        $effectivePairMultiplier = $get_effective_slot_multiplier((float)$basePairMultiplier, $slotRowCount);
        $row['report_pair_multiplier'] = (float)$effectivePairMultiplier;
        $row['report_students'] = (int) ($row['room_capacity'] ?? 0) > 0 ? (int) $row['room_capacity'] : '';
        $instructor_workload[$group_key][] = $row;
        $subject_unit_key = $build_subject_unit_key($row);
        if ($subject_unit_key !== '' && !isset($counted_instructor_subject_units[$subject_unit_key])) {
            $total_units += $get_row_units($row) * $effectivePairMultiplier;
            $counted_instructor_subject_units[$subject_unit_key] = true;
        }
        $row_hours = (float)($row['scheduled_hours'] ?? $row['hours_per_week'] ?? 0) * $effectivePairMultiplier;
        $total_hours += $row_hours;

        $overload_subject_key = $build_special_approval_key($row);
        if (!isset($workload_subject_candidates[$overload_subject_key])) {
            $workload_subject_candidates[$overload_subject_key] = [
                'subject_code' => (string)($row['subject_code'] ?? ''),
                'subject_name' => (string)($row['subject_name'] ?? ''),
                'course_code' => (string)($row['report_course_code'] ?? ''),
                'hours' => 0.0,
                'units' => 0.0,
                'unit_keys' => [],
                'rows' => [],
            ];
        }
        $workload_subject_candidates[$overload_subject_key]['hours'] += $row_hours;
        $workload_subject_candidates[$overload_subject_key]['rows'][] = $row;
        if ($subject_unit_key !== '') {
            $workload_subject_candidates[$overload_subject_key]['unit_keys'][$subject_unit_key] = true;
        }
    }
    $total_hours = round($total_hours, 2);
    foreach ($workload_subject_candidates as $subjectKey => &$candidateSubject) {
        $candidateSubject['hours'] = round((float)$candidateSubject['hours'], 2);
        foreach (array_keys($candidateSubject['unit_keys']) as $unitKey) {
            $unitRow = $candidateSubject['rows'][0] ?? [];
            foreach ($candidateSubject['rows'] as $candidateRow) {
                if ($build_subject_unit_key($candidateRow) === $unitKey) {
                    $unitRow = $candidateRow;
                    break;
                }
            }
            $unitPairMultiplier = (float)($unitRow['report_pair_multiplier'] ?? 1.0);
            $candidateSubject['units'] += $get_row_units($unitRow) * $unitPairMultiplier;
        }
        $candidateSubject['units'] = round((float)$candidateSubject['units'], 2);
    }
    unset($candidateSubject);
    uasort($workload_subject_candidates, function ($a, $b) {
        $hoursCompare = (float)($b['hours'] ?? 0) <=> (float)($a['hours'] ?? 0);
        if ($hoursCompare !== 0) {
            return $hoursCompare;
        }
        return strcmp((string)($a['subject_code'] ?? ''), (string)($b['subject_code'] ?? ''));
    });
    $overload_subjects = $workload_subject_candidates;
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
    if ($is_overloaded && !empty($overload_subjects)) {
        $remainingOverload = round(max(0, $total_hours - $weekly_hour_limit), 2);
        $dayOrder = [
            'Monday' => 1,
            'Tuesday' => 2,
            'Wednesday' => 3,
            'Thursday' => 4,
            'Friday' => 5,
            'Saturday' => 6,
        ];
        $candidateRows = [];
        foreach ($instructor_workload as $groupKey => $groupRows) {
            foreach ($groupRows as $workloadRow) {
                $rowPairMultiplier = (float)($workloadRow['report_pair_multiplier'] ?? $get_paired_group_multiplier((string)$groupKey, (string)$instructor_workload_mode));
                $rowHours = round((float)($workloadRow['scheduled_hours'] ?? $workloadRow['hours_per_week'] ?? 0) * $rowPairMultiplier, 2);
                if ($rowHours <= 0) {
                    continue;
                }
                $subjectKey = $build_special_approval_key($workloadRow);
                $subjectTotal = (float)($overload_subjects[$subjectKey]['hours'] ?? 0);
                $candidateRows[] = [
                    'row' => $workloadRow,
                    'row_hours' => $rowHours,
                    'subject_key' => $subjectKey,
                    'subject_total_hours' => $subjectTotal,
                    'day_rank' => (int)($dayOrder[$workloadRow['day'] ?? ''] ?? 99),
                ];
            }
        }
        usort($candidateRows, static function (array $a, array $b): int {
            $subjectCompare = (float)$b['subject_total_hours'] <=> (float)$a['subject_total_hours'];
            if ($subjectCompare !== 0) {
                return $subjectCompare;
            }
            $hoursCompare = (float)$b['row_hours'] <=> (float)$a['row_hours'];
            if ($hoursCompare !== 0) {
                return $hoursCompare;
            }
            $dayCompare = (int)$b['day_rank'] <=> (int)$a['day_rank'];
            if ($dayCompare !== 0) {
                return $dayCompare;
            }
            return strcmp((string)($b['row']['start_time'] ?? ''), (string)($a['row']['start_time'] ?? ''));
        });

        $selectedOverloadRows = [];
        foreach ($candidateRows as $candidateRow) {
            if ($remainingOverload <= 0) {
                break;
            }
            $selectedOverloadRows[] = $candidateRow;
            $remainingOverload = round($remainingOverload - (float)$candidateRow['row_hours'], 2);
        }
        $defaultOverloadSubjectKeys = [];
        foreach ($selectedOverloadRows as $selectedRow) {
            $defaultOverloadSubjectKeys[(string)$selectedRow['subject_key']] = (string)$selectedRow['subject_key'];
        }
        $selected_overload_subject_keys = $special_selection_applied
            ? array_values(array_intersect($requested_overload_subject_keys, array_keys($overload_subjects)))
            : array_values($defaultOverloadSubjectKeys);

        $selectedOverloadSubjects = [];
        foreach ($selected_overload_subject_keys as $subjectKey) {
            if (!isset($overload_subjects[$subjectKey])) {
                continue;
            }
            $selectedOverloadSubjects[$subjectKey] = [
                'subject_code' => (string)($overload_subjects[$subjectKey]['subject_code'] ?? ''),
                'subject_name' => (string)($overload_subjects[$subjectKey]['subject_name'] ?? ''),
                'course_code' => (string)($overload_subjects[$subjectKey]['course_code'] ?? ''),
                'hours' => round((float)($overload_subjects[$subjectKey]['hours'] ?? 0), 2),
                'units' => round((float)($overload_subjects[$subjectKey]['units'] ?? 0), 2),
            ];

            foreach (($overload_subjects[$subjectKey]['rows'] ?? []) as $row) {
                $selected_overload_workload_rows[] = $row;
                $overload_subject_rows[] = [
                    'schedule_id' => (int)($row['id'] ?? 0),
                    'subject_key' => $subjectKey,
                    'subject_code' => (string)($row['subject_code'] ?? ''),
                    'subject_name' => (string)($row['subject_name'] ?? ''),
                    'time_label' => (string)($row['report_time_label'] ?? ''),
                    'course_code' => (string)($row['report_course_code'] ?? ''),
                    'students' => (string)($row['report_students'] ?? ''),
                    'units' => $get_row_units($row),
                    'overload_hours' => (float)($row['scheduled_hours'] ?? $row['hours_per_week'] ?? 0) * (float)($row['report_pair_multiplier'] ?? 1.0),
                    'room_number' => (string)($row['room_number'] ?? ''),
                ];
            }
        }
        $overload_subjects = $selectedOverloadSubjects;
    }
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

    // Default "actual load" values (before optional overload removal).
    $actual_total_units = $total_units;
    $actual_total_hours = $total_hours;
    $actual_total_preparations = $total_preparations;
    $actual_total_units_with_deloading = $total_units_with_deloading;

    // If overload is approved, move overload subjects out of actual-load table.
    if ($is_overloaded && $overload_approved && !empty($overload_subject_rows)) {
        $overload_schedule_ids = [];
        foreach ($overload_subject_rows as $orow) {
            $scheduleId = (int)($orow['schedule_id'] ?? 0);
            if ($scheduleId > 0) {
                $overload_schedule_ids[$scheduleId] = true;
            }
        }

        foreach ($instructor_workload as $groupKey => $groupRows) {
            $instructor_workload[$groupKey] = array_values(array_filter($groupRows, static function (array $row) use ($overload_schedule_ids): bool {
                $scheduleId = (int)($row['id'] ?? 0);
                if ($scheduleId <= 0) {
                    return true;
                }
                return !isset($overload_schedule_ids[$scheduleId]);
            }));
        }

        $actual_total_units = 0.0;
        $actual_total_hours = 0.0;
        $actual_subject_keys = [];
        $actual_preparations = [];
        foreach ($instructor_workload as $groupKey => $groupRows) {
            foreach ($groupRows as $row) {
                $rowPairMultiplier = (float)($row['report_pair_multiplier'] ?? $get_paired_group_multiplier((string)$groupKey, (string)$instructor_workload_mode));
                $actual_total_hours += (float)($row['scheduled_hours'] ?? $row['hours_per_week'] ?? 0) * $rowPairMultiplier;
                $subjectUnitKey = $build_subject_unit_key($row);
                if ($subjectUnitKey !== '' && !isset($actual_subject_keys[$subjectUnitKey])) {
                    $actual_total_units += $get_row_units($row) * $rowPairMultiplier;
                    $actual_subject_keys[$subjectUnitKey] = true;
                }
                $subjectPrepKey = (int)($row['subject_id'] ?? 0);
                if ($subjectPrepKey > 0) {
                    $actual_preparations[$subjectPrepKey] = true;
                }
            }
        }
        $actual_total_units = round($actual_total_units, 2);
        $actual_total_hours = round($actual_total_hours, 2);
        $actual_total_preparations = count($actual_preparations);
        $actual_total_units_with_deloading = $actual_total_units
            + (float)($selected_instructor['designation_units'] ?? 0)
            + (float)($selected_instructor['research_extension_units'] ?? 0)
            + (float)($selected_instructor['special_assignment_units'] ?? 0);
    }

    // PRAISE is a report-side selection for permanent instructors whose scheduled
    // teaching load exceeds the allowed unit ceiling.
    $instructor_status = (string)($selected_instructor['status'] ?? '');
    if ($instructor_status === 'Permanent') {
        $is_praise = $total_units > $praise_unit_limit;
        if ($is_praise && !empty($workload_subject_candidates)) {
            $remainingPraiseUnits = round(max(0, $total_units - $praise_unit_limit), 2);
            $defaultPraiseSubjectKeys = [];
            foreach ($workload_subject_candidates as $subjectKey => $candidateSubject) {
                if ($remainingPraiseUnits <= 0) {
                    break;
                }
                $defaultPraiseSubjectKeys[(string)$subjectKey] = (string)$subjectKey;
                $remainingPraiseUnits = round($remainingPraiseUnits - (float)($candidateSubject['units'] ?? 0), 2);
            }

            $selected_praise_subject_keys = $special_selection_applied
                ? array_values(array_intersect($requested_praise_subject_keys, array_keys($workload_subject_candidates)))
                : array_values($defaultPraiseSubjectKeys);

            foreach ($selected_praise_subject_keys as $subjectKey) {
                if (!isset($workload_subject_candidates[$subjectKey])) {
                    continue;
                }
                $candidateSubject = $workload_subject_candidates[$subjectKey];
                $praise_subjects[$subjectKey] = [
                    'subject_code' => (string)($candidateSubject['subject_code'] ?? ''),
                    'subject_name' => (string)($candidateSubject['subject_name'] ?? ''),
                    'course_code' => (string)($candidateSubject['course_code'] ?? ''),
                    'units' => round((float)($candidateSubject['units'] ?? 0), 2),
                    'hours' => round((float)($candidateSubject['hours'] ?? 0), 2),
                ];

                foreach (($candidateSubject['rows'] ?? []) as $row) {
                    $selected_praise_workload_rows[] = $row;
                    $praise_subject_rows[] = [
                        'schedule_id' => (int)($row['id'] ?? 0),
                        'subject_key' => $subjectKey,
                        'subject_code' => (string)($row['subject_code'] ?? ''),
                        'subject_name' => (string)($row['subject_name'] ?? ''),
                        'time_label' => $format_schedule_time_label($row['start_time'], $row['report_end_time'] ?? $row['end_time']),
                        'course_code' => (string)($row['report_course_code'] ?? $format_course_code($row, $job_input_cache)),
                        'students' => (string)((int)($row['room_capacity'] ?? 0) > 0 ? (int)$row['room_capacity'] : ''),
                        'units' => $get_row_units($row),
                        'praise_hours' => (float)($row['scheduled_hours'] ?? $row['hours_per_week'] ?? 0) * (float)($row['report_pair_multiplier'] ?? 1.0),
                        'room_number' => (string)($row['room_number'] ?? ''),
                    ];
                }
            }

            uasort($praise_subjects, function ($a, $b) {
                $unitsCompare = (float)($b['units'] ?? 0) <=> (float)($a['units'] ?? 0);
                if ($unitsCompare !== 0) {
                    return $unitsCompare;
                }
                return strcmp((string)($a['subject_code'] ?? ''), (string)($b['subject_code'] ?? ''));
            });
        }
    }

    if (empty($selected_praise_workload_rows) && empty($selected_overload_workload_rows) && !empty($schedules)) {
        $legacyRowLoadTotal = 0.0;
        $legacyFlaggedSubjectKeys = [];
        foreach ($schedules as $legacyRow) {
            $legacyRowLoad = ($instructor_status === 'Permanent')
                ? (float)($legacyRow['credits'] ?? 0)
                : (float)($legacyRow['scheduled_hours'] ?? $legacyRow['hours_per_week'] ?? 0);
            $legacyRowLoadTotal += $legacyRowLoad;

            $legacyThresholdExceeded = ($instructor_status === 'Permanent')
                ? ($legacyRowLoadTotal > $praise_unit_limit)
                : ($legacyRowLoadTotal > $weekly_hour_limit);
            if (!$legacyThresholdExceeded) {
                continue;
            }

            $legacySubjectKey = $build_special_approval_key($legacyRow);
            if ($legacySubjectKey !== '') {
                $legacyFlaggedSubjectKeys[$legacySubjectKey] = $legacySubjectKey;
            }
        }

        if (!empty($legacyFlaggedSubjectKeys)) {
            if ($instructor_status === 'Permanent') {
                $is_praise = true;
                $selected_praise_subject_keys = array_values(array_intersect(array_values($legacyFlaggedSubjectKeys), array_keys($workload_subject_candidates)));
                foreach ($selected_praise_subject_keys as $subjectKey) {
                    if (!isset($workload_subject_candidates[$subjectKey]) || isset($praise_subjects[$subjectKey])) {
                        continue;
                    }
                    $candidateSubject = $workload_subject_candidates[$subjectKey];
                    $praise_subjects[$subjectKey] = [
                        'subject_code' => (string)($candidateSubject['subject_code'] ?? ''),
                        'subject_name' => (string)($candidateSubject['subject_name'] ?? ''),
                        'course_code' => (string)($candidateSubject['course_code'] ?? ''),
                        'units' => round((float)($candidateSubject['units'] ?? 0), 2),
                        'hours' => round((float)($candidateSubject['hours'] ?? 0), 2),
                    ];

                    foreach (($candidateSubject['rows'] ?? []) as $row) {
                        $selected_praise_workload_rows[] = $row;
                        $praise_subject_rows[] = [
                            'schedule_id' => (int)($row['id'] ?? 0),
                            'subject_key' => $subjectKey,
                            'subject_code' => (string)($row['subject_code'] ?? ''),
                            'subject_name' => (string)($row['subject_name'] ?? ''),
                            'time_label' => $format_schedule_time_label($row['start_time'], $row['report_end_time'] ?? $row['end_time']),
                            'course_code' => (string)($row['report_course_code'] ?? $format_course_code($row, $job_input_cache)),
                            'students' => (string)((int)($row['room_capacity'] ?? 0) > 0 ? (int)$row['room_capacity'] : ''),
                            'units' => $get_row_units($row),
                            'praise_hours' => (float)($row['scheduled_hours'] ?? $row['hours_per_week'] ?? 0) * (float)($row['report_pair_multiplier'] ?? 1.0),
                            'room_number' => (string)($row['room_number'] ?? ''),
                        ];
                    }
                }

                uasort($praise_subjects, function ($a, $b) {
                    $unitsCompare = (float)($b['units'] ?? 0) <=> (float)($a['units'] ?? 0);
                    if ($unitsCompare !== 0) {
                        return $unitsCompare;
                    }
                    return strcmp((string)($a['subject_code'] ?? ''), (string)($b['subject_code'] ?? ''));
                });
            } else {
                $is_overloaded = true;
                $selected_overload_subject_keys = array_values(array_intersect(array_values($legacyFlaggedSubjectKeys), array_keys($workload_subject_candidates)));
                foreach ($selected_overload_subject_keys as $subjectKey) {
                    if (!isset($workload_subject_candidates[$subjectKey]) || isset($overload_subjects[$subjectKey])) {
                        continue;
                    }
                    $candidateSubject = $workload_subject_candidates[$subjectKey];
                    $overload_subjects[$subjectKey] = [
                        'subject_code' => (string)($candidateSubject['subject_code'] ?? ''),
                        'subject_name' => (string)($candidateSubject['subject_name'] ?? ''),
                        'course_code' => (string)($candidateSubject['course_code'] ?? ''),
                        'hours' => round((float)($candidateSubject['hours'] ?? 0), 2),
                        'units' => round((float)($candidateSubject['units'] ?? 0), 2),
                    ];

                    foreach (($candidateSubject['rows'] ?? []) as $row) {
                        $selected_overload_workload_rows[] = $row;
                        $overload_subject_rows[] = [
                            'schedule_id' => (int)($row['id'] ?? 0),
                            'subject_key' => $subjectKey,
                            'subject_code' => (string)($row['subject_code'] ?? ''),
                            'subject_name' => (string)($row['subject_name'] ?? ''),
                            'time_label' => (string)($row['report_time_label'] ?? ''),
                            'course_code' => (string)($row['report_course_code'] ?? ''),
                            'students' => (string)($row['report_students'] ?? ''),
                            'units' => $get_row_units($row),
                            'overload_hours' => (float)($row['scheduled_hours'] ?? $row['hours_per_week'] ?? 0) * (float)($row['report_pair_multiplier'] ?? 1.0),
                            'room_number' => (string)($row['room_number'] ?? ''),
                        ];
                    }
                }
            }
        }
    }

    $build_special_approval_page = static function (string $approvalType, array $selectedRows, array $subjectSummaries, int $pageNumber) use (
        $build_instructor_load_snapshot,
        $signatories,
        $build_document_code_for_page
    ): ?array {
        if (empty($selectedRows)) {
            return null;
        }

        $pageSnapshot = $build_instructor_load_snapshot($selectedRows);
        $pageWorkload = $pageSnapshot['workload'] ?? [];
        foreach ($pageWorkload as $groupKey => $groupRows) {
            usort($pageWorkload[$groupKey], static function (array $a, array $b): int {
                return strcmp((string)($a['start_time'] ?? ''), (string)($b['start_time'] ?? ''));
            });
        }

        $pagePreparations = [];
        foreach ($selectedRows as $pageRow) {
            $subjectId = (int)($pageRow['subject_id'] ?? 0);
            if ($subjectId > 0) {
                $pagePreparations[$subjectId] = true;
            }
        }

        $pageFooter = $signatories;
        $pageFooter['document_code'] = $build_document_code_for_page((string)($signatories['document_code'] ?? ''), $pageNumber);

        if ($approvalType === 'overload') {
            return [
                'type' => 'overload',
                'heading' => 'OVERLOAD SUBJECTS',
                'summary_title' => 'Hours Requiring Special Approval',
                'summary_text' => 'These subject assignments were selected as the instructor overload load.',
                'subject_metric_label' => 'hours',
                'subjects' => array_values($subjectSummaries),
                'workload' => $pageWorkload,
                'mode' => (string)($pageSnapshot['mode'] ?? 'paired'),
                'total_units' => round((float)($pageSnapshot['total_units'] ?? 0), 2),
                'total_hours' => round((float)($pageSnapshot['total_hours'] ?? 0), 2),
                'total_preparations' => count($pagePreparations),
                'footer' => $pageFooter,
            ];
        }

        return [
            'type' => 'praise',
            'heading' => 'PRAISE SUBJECTS',
            'summary_title' => 'Units Requiring Special Approval (PRAISE)',
            'summary_text' => 'These subject assignments were selected as the instructor PRAISE load.',
            'subject_metric_label' => 'units',
            'subjects' => array_values($subjectSummaries),
            'workload' => $pageWorkload,
            'mode' => (string)($pageSnapshot['mode'] ?? 'paired'),
            'total_units' => round((float)($pageSnapshot['total_units'] ?? 0), 2),
            'total_hours' => round((float)($pageSnapshot['total_hours'] ?? 0), 2),
            'total_preparations' => count($pagePreparations),
            'footer' => $pageFooter,
        ];
    };

    $nextSpecialApprovalPageNumber = 2;
    if (!empty($selected_overload_workload_rows)) {
        $overloadPage = $build_special_approval_page('overload', $selected_overload_workload_rows, $overload_subjects, $nextSpecialApprovalPageNumber);
        if ($overloadPage !== null) {
            $special_approval_pages[] = $overloadPage;
            $nextSpecialApprovalPageNumber++;
        }
    }
    if (!empty($selected_praise_workload_rows)) {
        $praisePage = $build_special_approval_page('praise', $selected_praise_workload_rows, $praise_subjects, $nextSpecialApprovalPageNumber);
        if ($praisePage !== null) {
            $special_approval_pages[] = $praisePage;
            $nextSpecialApprovalPageNumber++;
        }
    }

    foreach ($workload_order as $workloadGroupKey) {
        if ($workloadGroupKey === 'Saturday') {
            $consultation_time_options_by_group[$workloadGroupKey] = [];
            continue;
        }
        $periodForGroup = (strpos($workloadGroupKey, 'Afternoon') !== false) ? 'Afternoon' : 'Morning';
        $consultation_time_options_by_group[$workloadGroupKey] = array_values(array_filter(
            $workload_time_template[$periodForGroup] ?? [],
            static function ($label) use ($break_time_label): bool {
                return (string)$label !== $break_time_label;
            }
        ));
    }
}

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
            border: 1px solid #000;
            margin-bottom: 2rem;
            background: #fff;
            page-break-inside: avoid;
        }
        .schedule-section-header {
            padding: 10px 14px;
            border-bottom: 1px solid #000;
            font-weight: 700;
            font-size: 15px;
        }
        .schedule-report-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin-bottom: 0;
            font-size: 13px;
        }
        .schedule-report-table th,
        .schedule-report-table td {
            border: 1px solid #000;
            padding: 6px 7px;
            vertical-align: middle;
        }
        .schedule-report-table thead th {
            background: #f5f5f5;
            text-align: center;
            font-weight: 700;
        }
        .schedule-report-table .col-time { width: 12%; }
        .schedule-report-table .col-code { width: 10%; }
        .schedule-report-table .col-description { width: 34%; }
        .schedule-report-table .col-units { width: 8%; }
        .schedule-report-table .col-hours { width: 8%; }
        .schedule-report-table .col-instructor { width: 18%; }
        .schedule-report-table .col-room { width: 10%; }
        .schedule-report-table td.col-center,
        .schedule-report-table th.col-center {
            text-align: center;
        }
        .schedule-report-table .day-group-header td {
            background: #fff;
            font-weight: 700;
            font-size: 19px;
            padding: 4px 8px;
        }
        .schedule-report-table .day-group-header td:first-child {
            text-align: left;
        }
        .schedule-report-table .day-group-header td:not(:first-child) {
            background: #fff;
        }
        .schedule-report-table .special-row,
        .schedule-report-table .special-row td {
            text-align: center;
            font-weight: 700;
            background: #fff;
        }
        .schedule-report-table .lunch-row td {
            text-align: center;
            font-weight: 700;
            background: #fff;
            padding: 3px 8px;
        }
        .schedule-report-table .subject-code-cell {
            background: #fff;
            font-weight: 700;
            text-align: center;
        }
        .schedule-report-table .instructor-cell {
            text-align: center;
        }
        .schedule-report-table .description-cell {
            text-align: center;
            line-height: 1.25;
        }
        .schedule-report-table .section-total-row td {
            font-weight: 700;
            background: #fafafa;
        }
        .workload-sheet {
            border: 1px solid #333;
            padding: 18px 18px 14px;
            page-break-inside: avoid;
        }
        .workload-report-group {
            display: grid;
            gap: 16px;
        }
        .workload-sheet-supplemental {
            margin-top: 4px;
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
        .faculty-signatures.instructor-signatures {
            margin-top: 16px;
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
        .faculty-signature-label-centered {
            text-align: center;
        }
        .faculty-signature-row-label {
            margin-top: 22px;
            margin-bottom: 8px;
            font-weight: 700;
            text-align: left;
        }
        .instructor-signatures .faculty-signature-row {
            margin-top: 14px;
        }
        .instructor-signatures .faculty-signature-name {
            border-top: none;
            min-width: 300px;
            padding-top: 0;
        }
        .instructor-signatures .faculty-signature-label {
            margin-bottom: 12px;
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
        .conflict-summary {
            margin: 16px 0 20px;
            border: 1px solid #d0d7de;
            border-radius: 10px;
            padding: 16px;
            background: #fff;
        }
        .conflict-summary.is-clear {
            border-color: #b7ebc6;
            background: #f0fff4;
        }
        .conflict-summary.is-warning {
            border-color: #f3c7a7;
            background: #fff7ed;
        }
        .conflict-summary-header {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: flex-start;
            margin-bottom: 14px;
        }
        .conflict-summary-header h3 {
            margin: 0 0 6px;
        }
        .conflict-summary-status {
            font-weight: 700;
            padding: 6px 10px;
            border-radius: 999px;
            white-space: nowrap;
        }
        .conflict-summary.is-clear .conflict-summary-status {
            color: #166534;
            background: #dcfce7;
        }
        .conflict-summary.is-warning .conflict-summary-status {
            color: #9a3412;
            background: #ffedd5;
        }
        .conflict-summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
            margin-bottom: 12px;
        }
        .conflict-summary-card {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            background: #fff;
            padding: 12px;
        }
        .conflict-summary-card strong {
            display: block;
            font-size: 22px;
            margin-bottom: 4px;
        }
        .conflict-summary-card span {
            color: #475569;
            font-size: 13px;
        }
        .conflict-summary-details {
            margin: 0;
            padding-left: 18px;
        }
        .conflict-summary-note {
            margin-top: 10px;
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
            border: none;
            padding: 18px 0 0;
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
            padding-top: 6px;
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

        /* Per-block print buttons (screen only) */
        .block-print-container {
            position: relative;
            margin-bottom: 16px;
        }
.block-print-btn {
            position: absolute;
            top: 12px;
            right: 16px;
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
            border: none;
            border-radius: 20px;
            padding: 8px 16px;
            font-size: 14px;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
            transition: all 0.2s ease;
            z-index: 10;
            display: flex;
            align-items: center;
            gap: 6px;
            font-weight: 600;
            text-decoration: none;
            white-space: nowrap;
        }

        .block-print-btn:hover {
            transform: scale(1.08);
            box-shadow: 0 6px 20px rgba(59, 130, 246, 0.6);
        }
        body.printing-block .no-print {
            display: none !important;
        }
        @media print {
            @page {
                size: Letter portrait;
                margin: 0.2in;
            }
            html,
            body.report-page {
                width: auto;
                min-height: auto;
                margin: 0;
                padding: 0;
                font-size: 9px;
                background: #fff;
            }
            body.report-page .container {
                width: 100%;
                max-width: 8.1in;
                margin: 0 auto;
                padding: 0;
            }
            .block-print-btn {
                display: none !important;
            }
            .no-print {
                display: none !important;
            }
            body.printing-block .schedule-section-block:not(.printing),
            body.printing-block .workload-sheet:not(.printing) {
                display: none !important;
            }
            body.printing-block .header,
            body.printing-block .filter-section,
            body.printing-block .container > *:not(.printing),
            body.printing-block .report-signatory-settings {
                display: none !important;
            }
            body.printing-block .schedule-report.printing,
            body.printing-block .schedule-section-block.printing,
            body.printing-block .workload-sheet.printing {
                width: 100%;
                max-width: 8.1in;
                margin: 0 auto;
                box-sizing: border-box;
                break-inside: avoid;
                page-break-inside: avoid;
                page-break-after: avoid;
            }
            body.printing-block .schedule-report.printing {
                display: block !important;
            }
            .schedule-section-block,
            .workload-sheet,
            .report-signature-sheet {
                break-inside: avoid;
                page-break-inside: avoid;
            }
            .workload-sheet-supplemental {
                break-before: page;
                page-break-before: always;
            }
            .schedule-section-block {
                margin-bottom: 0;
            }
            .workload-sheet {
                margin-bottom: 0;
                border-width: 1px;
                padding: 6px 8px 4px;
            }
            .report-main-header {
                margin-bottom: 3px;
                line-height: 1.05;
            }
            .report-main-header img {
                width: 36px;
                margin-bottom: 1px;
            }
            .report-main-header .country-line {
                font-size: 7px;
            }
            .report-main-header .university-line {
                font-size: 10px;
            }
            .report-main-header .department-line {
                font-size: 8px;
            }
            .report-main-header .title-line {
                font-size: 8px;
            }
            .report-main-header .term-line {
                font-size: 7px;
            }
            .workload-meta {
                margin: 0 0 2px;
                gap: 1px 8px;
                font-size: 7px;
            }
            .workload-meta .meta-line {
                padding-bottom: 1px;
            }
            .schedule-section-header {
                padding: 2px 6px;
                font-size: 8px;
            }
            .schedule-report-table,
            .workload-table,
            .workload-summary {
                font-size: 7px;
            }
            .schedule-report-table th,
            .schedule-report-table td,
            .workload-table th,
            .workload-table td,
            .workload-summary td {
                padding: 0.5px 1.5px;
            }
            .schedule-report-table .day-group-header td {
                font-size: 7px;
                padding: 1px 2px;
            }
            .schedule-report-table .description-cell {
                line-height: 1.05;
            }
            .report-signature-sheet {
                margin-top: 2px;
                padding: 2px 0 0;
            }
            .report-signature-grid {
                gap: 8px;
                margin-bottom: 2px;
            }
            .signature-label {
                font-size: 7px;
                margin-bottom: 2px;
            }
            .signature-name {
                font-size: 8px;
            }
            .signature-title,
            .report-signature-meta,
            .report-contact-lines div,
            .report-contact-lines a {
                font-size: 7px;
            }
            .report-contact-footer {
                margin-top: 1px;
                padding-top: 1px;
                gap: 8px;
            }
            .report-contact-logos img {
                width: 24px;
            }
            .print-footer {
                display: none !important;
            }
        }
    </style>

    <?php if (isset($print_mode)): ?>
    <style>
        @page { size: auto; margin: 0.3in; }
        body { font-family: Arial, sans-serif; }
        .no-print { display: none; }
        .print-only { display: block; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #000; padding: 3px; text-align: left; }
        th { background-color: #f2f2f2; }
        .container { width: 100%; max-width: 7.35in; margin: 0 auto; padding: 0; }
        .schedule-section-block,
        .workload-sheet { border: 1px solid #000; margin-bottom: 0.5rem; page-break-inside: avoid; break-inside: avoid; }
        .workload-sheet-supplemental { page-break-before: always; break-before: page; }
        .schedule-section-header { background: #333 !important; color: #fff !important; padding: 4px 8px; font-size: 10px; }
        .schedule-report-table,
        .workload-table,
        .workload-summary { font-size: 8px; }
        .report-main-header img { width: 46px; }
        .report-main-header .country-line { font-size: 9px; }
        .report-main-header .university-line { font-size: 13px; }
        .report-main-header .department-line { font-size: 11px; }
        .report-main-header .title-line { font-size: 10px; }
        .report-main-header .term-line { font-size: 9px; }
        .report-signature-sheet { border: none; padding: 8px 0 0; margin-top: 8px; }
        .signature-label { font-size: 8px; margin-bottom: 10px; }
        .signature-name { font-size: 10px; }
        .signature-title,
        .report-signature-meta,
        .report-contact-lines div,
        .report-contact-lines a { font-size: 8px; }
        .report-contact-footer { margin-top: 6px; padding-top: 2px; border-top: none; }
        .report-contact-logos img { width: 30px; }
    </style>
    <?php endif; ?>
</head>
<body class="report-page">
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

        <?php if ($message !== ''): ?>
            <div class="success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if (!isset($print_mode)): ?>
        <!-- Filter Section -->
        <div class="filter-section">
            <h3>Filter Schedules</h3>
            <form method="GET" action="" class="filter-form">
                <datalist id="report_department_options">
                    <?php foreach ($departments as $dept): ?>
                    <option value="<?php echo htmlspecialchars($dept['dept_name'] . ' (' . $dept['dept_code'] . ')'); ?>"></option>
                    <option value="<?php echo htmlspecialchars($dept['dept_code']); ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                <datalist id="report_program_options">
                    <?php foreach ($programs as $prog): ?>
                    <option value="<?php echo htmlspecialchars($prog['program_name'] . ' (' . $prog['program_code'] . ')'); ?>" data-department-id="<?php echo (int)($prog['department_id'] ?? 0); ?>"></option>
                    <option value="<?php echo htmlspecialchars($prog['program_code']); ?>" data-department-id="<?php echo (int)($prog['department_id'] ?? 0); ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                <datalist id="report_instructor_options">
                    <?php foreach ($instructors as $inst): ?>
                    <option value="<?php echo htmlspecialchars($inst['full_name']); ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                <div class="form-group">
                    <label for="department">Department:</label>
                    <input type="text" id="department" name="department" list="report_department_options" value="<?php echo htmlspecialchars($department_lookup); ?>" placeholder="Type department...">
                </div>

                <div class="form-group">
                    <label for="program">Program:</label>
                    <input type="text" id="program" name="program" list="report_program_options" value="<?php echo htmlspecialchars($program_lookup); ?>" placeholder="Type program...">
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
                    <label for="instructor">Instructor:</label>
                    <input type="text" id="instructor" name="instructor" list="report_instructor_options" value="<?php echo htmlspecialchars($instructor_lookup); ?>" placeholder="Type instructor name...">
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn-primary">Apply Filters</button>
                    <a href="report.php" class="btn-secondary">Clear Filters</a>
                    <a href="report.php?print=1&<?php echo http_build_query($_GET); ?>" 
                       class="btn-primary" target="_blank">Print Report</a>
                    <a href="report.php?export=csv&<?php echo http_build_query($_GET); ?>" class="btn-secondary">Download CSV</a>
                    <a href="report.php?export=excel&<?php echo http_build_query($_GET); ?>" class="btn-secondary">Download Excel</a>
                </div>
            </form>
        </div>

        <div class="conflict-summary <?php echo $conflict_summary['is_clear'] ? 'is-clear' : 'is-warning'; ?>">
            <div class="conflict-summary-header">
                <div>
                    <h3>Conflict Check Summary</h3>
                    <div>
                        <?php if ($conflict_summary['is_clear']): ?>
                            All published schedules are currently conflict-free.
                        <?php else: ?>
                            Published schedules have conflicts that should be fixed before adding more published schedules.
                        <?php endif; ?>
                    </div>
                </div>
                <div class="conflict-summary-status">
                    <?php echo $conflict_summary['is_clear'] ? 'No Conflicts Found' : number_format((int)$conflict_summary['total_conflicts']) . ' Conflict Group(s)'; ?>
                </div>
            </div>

            <div class="conflict-summary-grid">
                <div class="conflict-summary-card">
                    <strong><?php echo number_format((int)$conflict_summary['published_rows']); ?></strong>
                    <span>Published schedule rows</span>
                </div>
                <div class="conflict-summary-card">
                    <strong><?php echo number_format((int)$conflict_summary['published_jobs']); ?></strong>
                    <span>Published jobs</span>
                </div>
                <div class="conflict-summary-card">
                    <strong><?php echo number_format((int)$conflict_summary['completed_jobs']); ?></strong>
                    <span>Completed jobs</span>
                </div>
                <div class="conflict-summary-card">
                    <strong><?php echo number_format((int)$conflict_summary['room_conflicts']); ?></strong>
                    <span>Room conflict groups</span>
                </div>
                <div class="conflict-summary-card">
                    <strong><?php echo number_format((int)$conflict_summary['instructor_conflicts']); ?></strong>
                    <span>Instructor conflict groups</span>
                </div>
                <div class="conflict-summary-card">
                    <strong><?php echo number_format((int)$conflict_summary['section_conflicts']); ?></strong>
                    <span>Section conflict groups</span>
                </div>
            </div>

            <?php if (!empty($conflict_summary['sample_rows'])): ?>
                <div><strong>Sample conflicts</strong></div>
                <ul class="conflict-summary-details">
                    <?php foreach ($conflict_summary['sample_rows'] as $sample): ?>
                        <li>
                            <?php echo htmlspecialchars((string)($sample['conflict_group'] ?? 'Conflict')); ?>:
                            <?php echo htmlspecialchars((string)($sample['resource_label'] ?? '')); ?>
                            on <?php echo htmlspecialchars((string)($sample['day'] ?? '')); ?>
                            <?php echo htmlspecialchars(date('g:i A', strtotime((string)($sample['start_time'] ?? '00:00:00')))); ?>
                            -
                            <?php echo htmlspecialchars(date('g:i A', strtotime((string)($sample['end_time'] ?? '00:00:00')))); ?>
                            (<?php echo (int)($sample['hits'] ?? 0); ?> entries)
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <div class="conflict-summary-note">
                Checked at <?php echo htmlspecialchars((string)$conflict_summary['checked_at']); ?>.
                Current report view contains <?php echo number_format((int)$conflict_summary['visible_rows']); ?> row(s) after filters.
            </div>
        </div>
        <?php endif; ?>

        <?php if (!isset($print_mode) && !$is_instructor_report && (!empty($special_approval_index['praise']) || !empty($special_approval_index['overload']))): ?>
        <div style="margin-bottom: 18px; border: 1px solid #d8dee6; background: #fff; border-radius: 10px; overflow: hidden;">
            <div style="padding: 14px 16px; border-bottom: 1px solid #e2e8f0; background: linear-gradient(135deg, #fff7ed, #f8fafc);">
                <h3 style="margin: 0 0 6px; color: #1f2937;">Special Approval Instructor Pages</h3>
                <p style="margin: 0; color: #475569;">
                    These instructors currently exceed the report limits in the published schedules shown by your active filters.
                    Open any name to jump directly to that faculty workload page.
                </p>
            </div>

            <?php if (!empty($special_approval_index['praise'])): ?>
                <div style="padding: 14px 16px; border-bottom: <?php echo !empty($special_approval_index['overload']) ? '1px solid #e2e8f0' : '0'; ?>;">
                    <div style="font-weight: 700; color: #c2410c; margin-bottom: 8px;">PRAISE Required</div>
                    <ul style="margin: 0; padding-left: 20px;">
                        <?php foreach ($special_approval_index['praise'] as $entry): ?>
                            <?php
                                $subjectBits = [];
                                foreach (($entry['subjects'] ?? []) as $subject) {
                                    $subjectBits[] = $format_special_approval_subject_label($subject)
                                        . ' (' . number_format((float)($subject['units'] ?? 0), 2) . ' units)';
                                }
                            ?>
                            <li style="margin-bottom: 8px;">
                                <a href="<?php echo htmlspecialchars($entry['report_url'] ?? '#'); ?>" style="font-weight: 700;">
                                    <?php echo htmlspecialchars((string)($entry['instructor_name'] ?? '')); ?>
                                </a>
                                :
                                <?php echo number_format((float)($entry['total_units'] ?? 0), 2); ?> total units,
                                <?php echo number_format((float)($entry['excess_units'] ?? 0), 2); ?> above the 24.00-unit ceiling.
                                <?php if (!empty($subjectBits)): ?>
                                    Suggested PRAISE subject(s): <?php echo htmlspecialchars(implode('; ', $subjectBits)); ?>.
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($special_approval_index['overload'])): ?>
                <div style="padding: 14px 16px;">
                    <div style="font-weight: 700; color: #b91c1c; margin-bottom: 8px;">OVERLOAD Required</div>
                    <ul style="margin: 0; padding-left: 20px;">
                        <?php foreach ($special_approval_index['overload'] as $entry): ?>
                            <?php
                                $subjectBits = [];
                                foreach (($entry['subjects'] ?? []) as $subject) {
                                    $subjectBits[] = $format_special_approval_subject_label($subject)
                                        . ' (' . number_format((float)($subject['hours'] ?? 0), 2) . ' hours)';
                                }
                            ?>
                            <li style="margin-bottom: 8px;">
                                <a href="<?php echo htmlspecialchars($entry['report_url'] ?? '#'); ?>" style="font-weight: 700;">
                                    <?php echo htmlspecialchars((string)($entry['instructor_name'] ?? '')); ?>
                                </a>
                                :
                                <?php echo number_format((float)($entry['total_hours'] ?? 0), 2); ?> total hours,
                                <?php echo number_format((float)($entry['excess_hours'] ?? 0), 2); ?> above the 30.00-hour ceiling.
                                <?php if (!empty($subjectBits)): ?>
                                    Suggested overload subject(s): <?php echo htmlspecialchars(implode('; ', $subjectBits)); ?>.
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Schedule Report: one block per Course/Year/Sec (e.g. 1 BLOCK (A), 2 BLOCK (A)) -->
        <div class="schedule-report">
            <?php if (empty($schedules)): ?>
                <p>No schedules found matching the criteria. Generate and publish a schedule first.</p>
            <?php elseif (!empty($instructor_id)): ?>
                <div class="workload-report-group">
                <div class="workload-sheet">
                    <div class="block-print-container">
                        <div class="report-main-header">
                            <img src="../assets/logo.png" alt="NEMSU logo">
                            <div class="country-line"><?php echo htmlspecialchars($signatories['header_country']); ?></div>
                            <div class="university-line"><?php echo htmlspecialchars($signatories['header_university']); ?></div>
                            <div class="department-line"><?php echo htmlspecialchars($signatories['header_department']); ?></div>
                            <div class="title-line">FACULTY WORKLOAD</div>
                            <div class="term-line"><?php echo htmlspecialchars($signatories['header_term']); ?></div>
                        </div>
                        <button class="block-print-btn" onclick="printBlock(this)" title="Print this workload">Print</button>
                    </div>

                    <div class="workload-meta">
                        <div class="meta-line"><span class="meta-label">Name:</span><span><?php echo htmlspecialchars($selected_instructor['full_name'] ?? ''); ?></span></div>
                        <div class="meta-line"><span class="meta-label">Educ'l Qualification:</span><span><?php echo htmlspecialchars((string)(trim((string)($selected_instructor['education'] ?? '')) !== '' ? $selected_instructor['education'] : '-')); ?></span></div>
                        <div class="meta-line"><span class="meta-label">Years in Service:</span><span><?php echo htmlspecialchars((string)(trim((string)($selected_instructor['service_years'] ?? '')) !== '' ? $selected_instructor['service_years'] : '-')); ?></span></div>
                        <div class="meta-line"><span class="meta-label">Major:</span><span><?php echo htmlspecialchars((string)(trim((string)($selected_instructor['major_display'] ?? '')) !== '' ? $selected_instructor['major_display'] : '-')); ?></span></div>
                        <div class="meta-line"><span class="meta-label">Status:</span><span><?php echo htmlspecialchars($selected_instructor['status'] ?? 'Instructor'); ?></span></div>
                        <div class="meta-line"><span class="meta-label">Eligibility/PRC:</span><span><?php echo htmlspecialchars((string)(trim((string)($selected_instructor['eligibility'] ?? '')) !== '' ? $selected_instructor['eligibility'] : '-')); ?></span></div>
                    </div>

                    <?php if (!isset($print_mode) && (($is_overloaded && !empty($workload_subject_candidates)) || ($is_praise && !empty($workload_subject_candidates)))): ?>
                        <div class="no-print" style="margin-bottom: 12px; border: 1px solid #d8dee6; background: #f8fafc; padding: 14px; border-radius: 8px;">
                            <strong>Special Approval Subject Selection</strong>
                            <form method="GET" style="margin-top: 10px;">
                                <input type="hidden" name="special_selection_applied" value="1">
                                <input type="hidden" name="department" value="<?php echo htmlspecialchars($department_lookup); ?>">
                                <input type="hidden" name="program" value="<?php echo htmlspecialchars($program_lookup); ?>">
                                <input type="hidden" name="year_level" value="<?php echo htmlspecialchars((string)$year_level); ?>">
                                <input type="hidden" name="instructor" value="<?php echo htmlspecialchars($instructor_lookup); ?>">

                                <?php if ($is_overloaded): ?>
                                    <div style="margin-bottom: 12px;">
                                        <div style="font-weight: 700; margin-bottom: 6px;">Select overload subject(s)</div>
                                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 8px;">
                                            <?php foreach ($workload_subject_candidates as $subjectKey => $candidateSubject): ?>
                                                <label style="display: flex; gap: 8px; align-items: flex-start; padding: 8px 10px; border: 1px solid #dbe4ee; border-radius: 6px; background: #fff;">
                                                    <input type="checkbox" name="selected_overload_subjects[]" value="<?php echo htmlspecialchars((string)$subjectKey); ?>" <?php echo in_array((string)$subjectKey, $selected_overload_subject_keys, true) ? 'checked' : ''; ?>>
                                                    <span>
                                                        <strong><?php echo htmlspecialchars($format_special_approval_subject_label($candidateSubject)); ?></strong><br>
                                                        <span style="color: #475569;"><?php echo number_format((float)($candidateSubject['hours'] ?? 0), 2); ?> hour(s)</span>
                                                    </span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($is_praise): ?>
                                    <div style="margin-bottom: 12px;">
                                        <div style="font-weight: 700; margin-bottom: 6px;">Select PRAISE subject(s)</div>
                                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 8px;">
                                            <?php foreach ($workload_subject_candidates as $subjectKey => $candidateSubject): ?>
                                                <label style="display: flex; gap: 8px; align-items: flex-start; padding: 8px 10px; border: 1px solid #f8d7a1; border-radius: 6px; background: #fffdf5;">
                                                    <input type="checkbox" name="selected_praise_subjects[]" value="<?php echo htmlspecialchars((string)$subjectKey); ?>" <?php echo in_array((string)$subjectKey, $selected_praise_subject_keys, true) ? 'checked' : ''; ?>>
                                                    <span>
                                                        <strong><?php echo htmlspecialchars($format_special_approval_subject_label($candidateSubject)); ?></strong><br>
                                                        <span style="color: #9a3412;"><?php echo number_format((float)($candidateSubject['units'] ?? 0), 2); ?> unit(s)</span>
                                                    </span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <button type="submit" class="btn-primary">Update Special Approval Selection</button>
                            </form>
                        </div>
                    <?php endif; ?>

                    <?php if ($is_overloaded && !$overload_approved && !isset($print_mode)): ?>
                        <div class="error no-print" style="margin-bottom: 12px;">
                            <strong>Overload Warning:</strong>
                            This instructor has <strong><?php echo number_format($total_hours, 2); ?> hours</strong>,
                            which exceeds the 30-hour weekly limit by
                            <strong><?php echo number_format(max(0, $total_hours - $weekly_hour_limit), 2); ?> hours</strong>.
                            <?php if (!empty($overload_subjects)): ?>
                                <div style="margin-top: 10px;">
                                    <strong>Subjects included in this overload:</strong>
                                    <ul style="margin-top: 6px;">
                                        <?php foreach ($overload_subjects as $overload_subject): ?>
                                            <li>
                                                <?php echo htmlspecialchars($format_special_approval_subject_label($overload_subject)); ?>
                                                : <?php echo number_format((float)($overload_subject['hours'] ?? 0), 2); ?> hour(s)
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                            <form method="POST" style="margin-top: 10px;">
                                <input type="hidden" name="special_selection_applied" value="1">
                                <input type="hidden" name="instructor_id" value="<?php echo (int)$instructor_id; ?>">
                                <input type="hidden" name="total_hours" value="<?php echo htmlspecialchars((string)$total_hours); ?>">
                                <?php foreach ($selected_overload_subject_keys as $selectedSubjectKey): ?>
                                    <input type="hidden" name="selected_overload_subjects[]" value="<?php echo htmlspecialchars((string)$selectedSubjectKey); ?>">
                                <?php endforeach; ?>
                                <?php foreach ($selected_praise_subject_keys as $selectedSubjectKey): ?>
                                    <input type="hidden" name="selected_praise_subjects[]" value="<?php echo htmlspecialchars((string)$selectedSubjectKey); ?>">
                                <?php endforeach; ?>
                                <button type="submit" name="approve_overload" class="btn-primary">OK - Approve Exceed Hours</button>
                            </form>
                        </div>
                    <?php elseif ($is_overloaded && $overload_approved): ?>
                        <div class="success no-print" style="margin-bottom: 12px;">
                            <strong>Overload Approved:</strong>
                            <?php echo number_format($total_hours, 2); ?> hours approved by
                            <?php echo htmlspecialchars($overload_approval['approver_name'] ?? 'Admin'); ?>
                            on <?php echo htmlspecialchars(date('F j, Y g:i A', strtotime((string)($overload_approval['created_at'] ?? 'now')))); ?>.
                            <?php if (!empty($overload_subjects)): ?>
                                <div style="margin-top: 10px;">
                                    <strong>Overload subjects:</strong>
                                    <ul style="margin-top: 6px;">
                                        <?php foreach ($overload_subjects as $overload_subject): ?>
                                            <li>
                                                <?php echo htmlspecialchars($format_special_approval_subject_label($overload_subject)); ?>
                                                : <?php echo number_format((float)($overload_subject['hours'] ?? 0), 2); ?> hour(s)
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($is_praise && !empty($praise_subjects)): ?>
                        <div style="margin-bottom: 12px; border: 1px solid #f4b183; background: #fff7ed; padding: 14px; border-radius: 8px;">
                            <h3 style="color: #c2410c; margin-top: 0;">⚠️ Units Requiring Special Approval (PRAISE)</h3>
                            <p style="margin-bottom: 8px;">The following assignments exceed the maximum permitted units for Permanent instructors and require special approval:</p>
                            <ul style="margin: 0; padding-left: 20px;">
                                <?php foreach ($praise_subjects as $praise_subject): ?>
                                    <li>
                                        <?php echo htmlspecialchars((string)($selected_instructor['full_name'] ?? '')); ?>:
                                        <?php echo htmlspecialchars($format_special_approval_subject_label($praise_subject)); ?>
                                        - <?php echo number_format((float)($praise_subject['units'] ?? 0), 2); ?> units
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if (!isset($print_mode)): ?>
                    <div class="no-print" style="margin-bottom: 10px; border: 1px solid #ddd; padding: 10px;">
                        <strong>Vacant Slot Consultation</strong>
                        <form method="POST" style="margin-top: 8px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center;">
                            <input type="hidden" name="consultation_instructor_id" value="<?php echo (int)$instructor_id; ?>">
                            <select id="consultation_day_group" name="consultation_day_group" required>
                                <?php foreach ($workload_order as $groupKey): ?>
                                    <option value="<?php echo htmlspecialchars($groupKey); ?>"><?php echo htmlspecialchars($workload_group_titles[$groupKey] ?? $groupKey); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select id="consultation_time_label" name="consultation_time_label" required></select>
                            <input type="text" name="consultation_note" value="Consultation" maxlength="120" placeholder="Consultation note">
                            <button type="submit" name="add_consultation_slot" class="btn-primary">Save Consultation Slot</button>
                        </form>
                        <?php if (!empty($consultation_slot_list)): ?>
                        <table class="workload-summary" style="margin-top:8px;">
                            <tr>
                                <td class="summary-label">Day/Group</td>
                                <td>Time</td>
                                <td>Note</td>
                                <td>Action</td>
                            </tr>
                            <?php foreach ($consultation_slot_list as $slotRow): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)($workload_group_titles[$slotRow['day_group']] ?? $slotRow['day_group'])); ?></td>
                                <td><?php echo htmlspecialchars((string)($slotRow['time_label'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars((string)($slotRow['note'] ?? 'Consultation')); ?></td>
                                <td>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="consultation_slot_id" value="<?php echo (int)($slotRow['id'] ?? 0); ?>">
                                        <button type="submit" name="delete_consultation_slot" class="btn-icon btn-delete">Remove</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                        <?php endif; ?>
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
                                <?php
                                $groupRows = $instructor_workload[$group_key] ?? [];
                                $groupPairMultiplier = $get_paired_group_multiplier((string)$group_key, (string)$instructor_workload_mode);
                                $periodForGroup = (strpos($group_key, 'Afternoon') !== false) ? 'Afternoon' : 'Morning';
                                $slotLabels = $workload_time_template[$periodForGroup] ?? [];
                                if ($group_key === 'Saturday') {
                                    $slotLabels = [''];
                                }
                                if (!empty($groupRows)) {
                                    foreach ($groupRows as $fallbackRow) {
                                        $actualLabel = (string)($fallbackRow['report_time_label'] ?? '');
                                        if ($actualLabel !== '' && !in_array($actualLabel, $slotLabels, true)) {
                                            $slotLabels[] = $actualLabel;
                                        }
                                    }
                                    $slotLabels = array_values(array_unique(array_filter($slotLabels)));
                                }
                                ?>
                                <tr class="workload-group">
                                    <td colspan="8"><?php echo htmlspecialchars($workload_group_titles[$group_key] ?? $group_key); ?></td>
                                </tr>
                                <?php foreach ($slotLabels as $slotLabel): ?>
                                    <?php
                                    if ($group_key === 'Saturday') {
                                        $slotRows = !empty($groupRows) ? [reset($groupRows)] : [];
                                    } else {
                                        $slotRows = array_values(array_filter($groupRows, static function (array $row) use ($slotLabel): bool {
                                            return (string)($row['report_time_label'] ?? '') === (string)$slotLabel;
                                        }));
                                    }
                                    $displaySlotLabel = ($group_key === 'Saturday') ? '' : (string)$slotLabel;
                                    $slotPairMultiplier = $get_effective_slot_multiplier((float)$groupPairMultiplier, count($slotRows));
                                    $forceDayLabel = (bool)preg_match('/^(MTh|TF)\//', (string)$group_key);
                                    ?>
                                    <?php if ($slotLabel === $break_time_label): ?>
                                        <tr class="lunch-row">
                                            <td><?php echo htmlspecialchars($displaySlotLabel); ?></td>
                                            <td colspan="7">BREAK TIME</td>
                                        </tr>
                                    <?php elseif (empty($slotRows)): ?>
                                        <?php $consultationRow = $consultation_slot_map[$group_key][$slotLabel] ?? null; ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($displaySlotLabel); ?></td>
                                            <td></td>
                                            <td><?php echo htmlspecialchars((string)($consultationRow['note'] ?? '')); ?></td>
                                            <td></td>
                                            <td></td>
                                            <td></td>
                                            <td></td>
                                            <td></td>
                                        </tr>
                                    <?php else: ?>
                                        <?php
                                        // Group subjects by subject_type
                                        $subjsByType = [];
                                        foreach ($slotRows as $slotRow) {
                                            $subjType = (string)($slotRow['subject_type'] ?? 'major');
                                            if (!isset($subjsByType[$subjType])) {
                                                $subjsByType[$subjType] = [];
                                            }
                                            $subjsByType[$subjType][] = $slotRow;
                                        }
                                        // Order: major first, then minor
                                        $typeOrder = ['major', 'minor'];
                                        foreach ($typeOrder as $typeKey) {
                                            if (isset($subjsByType[$typeKey])) {
                                                $typeLabel = (strtoupper($typeKey) . ' SUBJECT');
                                                $typeSubjects = $subjsByType[$typeKey];
                                                usort($typeSubjects, static function (array $a, array $b) use ($meeting_kind_rank): int {
                                                    $meetingCompare = $meeting_kind_rank($a) <=> $meeting_kind_rank($b);
                                                    if ($meetingCompare !== 0) {
                                                        return $meetingCompare;
                                                    }
                                                    return strcmp((string)($a['subject_code'] ?? ''), (string)($b['subject_code'] ?? ''));
                                                });
                                                foreach ($typeSubjects as $slotRow):
                                                    $slotTimeLabel = $format_slot_label_with_day((string)$displaySlotLabel, $slotRow, count($slotRows), $forceDayLabel);
                                                    ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($slotTimeLabel); ?></td>
                                                        <td><?php echo htmlspecialchars((string)($slotRow['subject_code'] ?? '')); ?></td>
                                                        <td><?php echo htmlspecialchars($format_subject_description($slotRow)); ?></td>
                                                        <td><?php echo htmlspecialchars((string)($slotRow['report_course_code'] ?? '')); ?></td>
                                                        <td><?php echo htmlspecialchars((string)($slotRow['report_students'] ?? '')); ?></td>
                                                        <td><?php echo htmlspecialchars(number_format($get_row_units($slotRow) * $slotPairMultiplier, 2)); ?></td>
                                                        <td><?php echo htmlspecialchars(number_format((float)($slotRow['scheduled_hours'] ?? $slotRow['hours_per_week'] ?? 0) * $slotPairMultiplier, 2)); ?></td>
                                                        <td><?php echo htmlspecialchars((string)($slotRow['report_room_label'] ?? $slotRow['room_number'] ?? '')); ?></td>
                                                    </tr>
                                                    <?php
                                                endforeach;
                                            }
                                        }
                                        ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <table class="workload-summary">
                        <tr>
                            <td class="summary-label">No. of Units</td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td><?php echo number_format($actual_total_units_with_deloading, 2); ?></td>
                            <td><?php echo number_format($actual_total_hours, 2); ?></td>
                            <td></td>
                        </tr>
                        <?php if ($selected_instructor['status'] === 'Permanent'): ?>
                        <tr>
                            <td class="summary-label">Designation</td>
                            <td><?php echo htmlspecialchars(($selected_instructor['designation'] ?? '') !== '' ? $selected_instructor['designation'] : '-'); ?></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td><?php echo number_format((float)($selected_instructor['designation_units'] ?? 0), 2); ?></td>
                            <td></td>
                            <td></td>
                        </tr>
                        <tr>
                            <td class="summary-label">Research/Extension</td>
                            <td><?php echo htmlspecialchars($formatResearchExtensionType($selected_instructor['research_extension'] ?? '')); ?></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td><?php echo number_format((float)($selected_instructor['research_extension_units'] ?? 0), 2); ?></td>
                            <td></td>
                            <td></td>
                        </tr>
                        <tr>
                            <td class="summary-label">Add: Special Assignment</td>
                            <td><?php echo htmlspecialchars(($selected_instructor['special_assignment'] ?? '') !== '' ? $selected_instructor['special_assignment'] : '-'); ?></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td><?php echo number_format((float)($selected_instructor['special_assignment_units'] ?? 0), 2); ?></td>
                            <td></td>
                            <td></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td class="summary-label">No. of Preparation</td>
                            <td><?php echo (int)$actual_total_preparations; ?></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                        </tr>
                        <tr>
                            <td class="summary-label">Total No. of Units</td>
                            <td>Regular Load</td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td><?php echo number_format($selected_instructor['status'] === 'Permanent' ? $actual_total_units_with_deloading : $actual_total_units, 2); ?></td>
                            <td><?php echo number_format($actual_total_hours, 2); ?></td>
                            <td></td>
                        </tr>
                    </table>

                    <?php $renderInstructorFooter($selected_instructor, $signatories); ?>
                </div>
                <?php foreach ($special_approval_pages as $specialPage): ?>
                <div class="workload-sheet workload-sheet-supplemental">
                    <div class="block-print-container">
                        <div class="report-main-header">
                            <img src="../assets/logo.png" alt="NEMSU logo">
                            <div class="country-line"><?php echo htmlspecialchars($signatories['header_country']); ?></div>
                            <div class="university-line"><?php echo htmlspecialchars($signatories['header_university']); ?></div>
                            <div class="department-line"><?php echo htmlspecialchars($signatories['header_department']); ?></div>
                            <div class="title-line">FACULTY WORKLOAD</div>
                            <div class="term-line"><?php echo htmlspecialchars($signatories['header_term']); ?></div>
                        </div>
                        <button class="block-print-btn" onclick="printBlock(this)" title="Print this workload">Print</button>
                    </div>

                    <div class="workload-meta">
                        <div class="meta-line"><span class="meta-label">Name:</span><span><?php echo htmlspecialchars($selected_instructor['full_name'] ?? ''); ?></span></div>
                        <div class="meta-line"><span class="meta-label">Educ'l Qualification:</span><span><?php echo htmlspecialchars((string)(trim((string)($selected_instructor['education'] ?? '')) !== '' ? $selected_instructor['education'] : '-')); ?></span></div>
                        <div class="meta-line"><span class="meta-label">Years in Service:</span><span><?php echo htmlspecialchars((string)(trim((string)($selected_instructor['service_years'] ?? '')) !== '' ? $selected_instructor['service_years'] : '-')); ?></span></div>
                        <div class="meta-line"><span class="meta-label">Major:</span><span><?php echo htmlspecialchars((string)(trim((string)($selected_instructor['major_display'] ?? '')) !== '' ? $selected_instructor['major_display'] : '-')); ?></span></div>
                        <div class="meta-line"><span class="meta-label">Status:</span><span><?php echo htmlspecialchars($selected_instructor['status'] ?? 'Instructor'); ?></span></div>
                        <div class="meta-line"><span class="meta-label">Eligibility/PRC:</span><span><?php echo htmlspecialchars((string)(trim((string)($selected_instructor['eligibility'] ?? '')) !== '' ? $selected_instructor['eligibility'] : '-')); ?></span></div>
                    </div>

                    <div style="margin-bottom: 12px; border: 1px solid <?php echo $specialPage['type'] === 'praise' ? '#f4b183' : '#f5c2c7'; ?>; background: <?php echo $specialPage['type'] === 'praise' ? '#fff7ed' : '#fff5f5'; ?>; padding: 14px; border-radius: 8px;">
                        <h3 style="margin: 0 0 6px; color: <?php echo $specialPage['type'] === 'praise' ? '#c2410c' : '#b91c1c'; ?>;">
                            <?php echo htmlspecialchars((string)($specialPage['summary_title'] ?? 'Special Approval Subjects')); ?>
                        </h3>
                        <p style="margin: 0 0 8px;">
                            <?php echo htmlspecialchars((string)($specialPage['summary_text'] ?? '')); ?>
                        </p>
                        <?php if (!empty($specialPage['subjects'])): ?>
                            <ul style="margin: 0; padding-left: 20px;">
                                <?php foreach ($specialPage['subjects'] as $specialSubject): ?>
                                    <?php $specialMetric = ($specialPage['type'] === 'praise') ? (float)($specialSubject['units'] ?? 0) : (float)($specialSubject['hours'] ?? 0); ?>
                                    <li>
                                        <?php echo htmlspecialchars($format_special_approval_subject_label($specialSubject)); ?>
                                        : <?php echo number_format($specialMetric, 2); ?> <?php echo htmlspecialchars((string)($specialPage['subject_metric_label'] ?? '')); ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>

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
                                <?php
                                $groupRows = $specialPage['workload'][$group_key] ?? [];
                                if (empty($groupRows)) {
                                    continue;
                                }
                                $specialPageMode = (string)($specialPage['mode'] ?? $instructor_workload_mode);
                                $groupPairMultiplier = $get_paired_group_multiplier((string)$group_key, $specialPageMode);
                                $slotLabels = [];
                                foreach ($groupRows as $groupRow) {
                                    $actualLabel = (string)($groupRow['report_time_label'] ?? '');
                                    if ($actualLabel !== '' && !in_array($actualLabel, $slotLabels, true)) {
                                        $slotLabels[] = $actualLabel;
                                    }
                                }
                                if ($group_key === 'Saturday' && empty($slotLabels)) {
                                    $slotLabels = [''];
                                }
                                ?>
                                <tr class="workload-group">
                                    <td colspan="8"><?php echo htmlspecialchars($workload_group_titles[$group_key] ?? $group_key); ?></td>
                                </tr>
                                <?php foreach ($slotLabels as $slotLabel): ?>
                                    <?php
                                    $slotRows = array_values(array_filter($groupRows, static function (array $row) use ($slotLabel, $group_key): bool {
                                        if ($group_key === 'Saturday' && $slotLabel === '') {
                                            return true;
                                        }
                                        return (string)($row['report_time_label'] ?? '') === (string)$slotLabel;
                                    }));
                                    if (empty($slotRows)) {
                                        continue;
                                    }
                                    $displaySlotLabel = ($group_key === 'Saturday') ? '' : (string)$slotLabel;
                                    $slotPairMultiplier = $get_effective_slot_multiplier((float)$groupPairMultiplier, count($slotRows));
                                    $forceDayLabel = (bool)preg_match('/^(MTh|TF)\//', (string)$group_key);
                                    ?>
                                    <?php
                                    $subjsByType = [];
                                    foreach ($slotRows as $slotRow) {
                                        $subjType = (string)($slotRow['subject_type'] ?? 'major');
                                        if (!isset($subjsByType[$subjType])) {
                                            $subjsByType[$subjType] = [];
                                        }
                                        $subjsByType[$subjType][] = $slotRow;
                                    }
                                    $typeOrder = ['major', 'minor'];
                                    foreach ($typeOrder as $typeKey) {
                                        if (isset($subjsByType[$typeKey])) {
                                            $typeSubjects = $subjsByType[$typeKey];
                                            usort($typeSubjects, static function (array $a, array $b) use ($meeting_kind_rank): int {
                                                $meetingCompare = $meeting_kind_rank($a) <=> $meeting_kind_rank($b);
                                                if ($meetingCompare !== 0) {
                                                    return $meetingCompare;
                                                }
                                                return strcmp((string)($a['subject_code'] ?? ''), (string)($b['subject_code'] ?? ''));
                                            });
                                            foreach ($typeSubjects as $slotRow):
                                                $slotTimeLabel = $format_slot_label_with_day((string)$displaySlotLabel, $slotRow, count($slotRows), $forceDayLabel);
                                                ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($slotTimeLabel); ?></td>
                                                    <td><?php echo htmlspecialchars((string)($slotRow['subject_code'] ?? '')); ?></td>
                                                    <td><?php echo htmlspecialchars($format_subject_description($slotRow)); ?></td>
                                                    <td><?php echo htmlspecialchars((string)($slotRow['report_course_code'] ?? '')); ?></td>
                                                    <td><?php echo htmlspecialchars((string)($slotRow['report_students'] ?? '')); ?></td>
                                                    <td><?php echo htmlspecialchars(number_format($get_row_units($slotRow) * $slotPairMultiplier, 2)); ?></td>
                                                    <td><?php echo htmlspecialchars(number_format((float)($slotRow['scheduled_hours'] ?? $slotRow['hours_per_week'] ?? 0) * $slotPairMultiplier, 2)); ?></td>
                                                    <td><?php echo htmlspecialchars((string)($slotRow['report_room_label'] ?? $slotRow['room_number'] ?? '')); ?></td>
                                                </tr>
                                                <?php
                                            endforeach;
                                        }
                                    }
                                    ?>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <table class="workload-summary">
                        <tr>
                            <td class="summary-label">Approval Type</td>
                            <td><?php echo htmlspecialchars((string)($specialPage['heading'] ?? 'SPECIAL APPROVAL')); ?></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td><?php echo number_format((float)($specialPage['total_units'] ?? 0), 2); ?></td>
                            <td><?php echo number_format((float)($specialPage['total_hours'] ?? 0), 2); ?></td>
                            <td></td>
                        </tr>
                        <tr>
                            <td class="summary-label">No. of Preparation</td>
                            <td><?php echo (int)($specialPage['total_preparations'] ?? 0); ?></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                        </tr>
                        <tr>
                            <td class="summary-label">Total Special Approval Load</td>
                            <td><?php echo htmlspecialchars((string)($specialPage['heading'] ?? 'SPECIAL APPROVAL')); ?></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td><?php echo number_format((float)($specialPage['total_units'] ?? 0), 2); ?></td>
                            <td><?php echo number_format((float)($specialPage['total_hours'] ?? 0), 2); ?></td>
                            <td></td>
                        </tr>
                    </table>

                    <?php $renderInstructorFooter($selected_instructor, $specialPage['footer']); ?>
                </div>
                <?php endforeach; ?>
                </div>
            <?php else: ?>
                <?php foreach ($by_section as $sectionKey => $section): ?>
                <div class="schedule-section-block">
                    <div class="block-print-container">
                        <div class="report-main-header" style="padding: 16px 16px 0;">
                            <img src="../assets/logo.png" alt="NEMSU logo">
                            <div class="country-line"><?php echo htmlspecialchars($signatories['header_country']); ?></div>
                            <div class="university-line"><?php echo htmlspecialchars($signatories['header_university']); ?></div>
                            <div>|</div>
                            <div class="department-line"><?php echo htmlspecialchars($signatories['header_department']); ?></div>
                            <div class="title-line"><?php echo htmlspecialchars($signatories['header_title']); ?></div>
                            <div class="term-line"><?php echo htmlspecialchars($signatories['header_term']); ?></div>
                        </div>
                        <button class="block-print-btn" onclick="printBlock(this)" title="Print Schedule Block">Print</button>
                    </div>
                    <div class="schedule-section-header">Course/Year/Sec. <?php echo htmlspecialchars(str_replace([' BLOCK (', ')', ' BLOCK'], ['', '', ''], $section['label'])); ?></div>
                    <table class="schedule-report-table">
                        <thead>
                            <tr>
                                <th class="col-time">TIME/DAY</th>
                                <th class="col-code">Subject Code</th>
                                <th class="col-description">Description</th>
                                <th class="col-units col-center">No. of Units</th>
                                <th class="col-hours col-center">No. of Hours</th>
                                <th class="col-instructor">Instructor</th>
                                <th class="col-room">Room No.</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $order = (($section['day_group_mode'] ?? 'paired') === 'individual')
                                ? ['Monday/A.M.', 'Monday/P.M.', 'Tuesday/A.M.', 'Tuesday/P.M.', 'Wednesday/A.M.', 'Wednesday/P.M.', 'Thursday/A.M.', 'Thursday/P.M.', 'Friday/A.M.', 'Friday/P.M.', 'Saturday']
                                : ['MTh/A.M.', 'MTh/P.M.', 'TF/A.M.', 'TF/P.M.', 'Wed/A.M.', 'Wed/P.M.', 'Saturday'];
                            foreach ($order as $groupKey):
                                $rows = $section['by_day_group'][$groupKey] ?? [];
                                $sectionPairMultiplier = $get_paired_group_multiplier((string)$groupKey, (string)($section['day_group_mode'] ?? 'paired'));
                                $groupTitle = $section_group_titles[$groupKey] ?? $groupKey;
                                
                                // Group rows by time slot
                                $rows_by_time = [];
                                foreach ($rows as $row_for_slot) {
                                    $slot_label = (string)($row_for_slot['report_time_label'] ?? '');
                                    if (!isset($rows_by_time[$slot_label])) {
                                        $rows_by_time[$slot_label] = [];
                                    }
                                    $rows_by_time[$slot_label][] = $row_for_slot;
                                }
                                
                                // Get time slots for this group
                                $time_slots = $section_time_slots_by_group[$groupKey] ?? [];
                                if ($groupKey === 'Saturday') {
                                    $time_slots = [''];
                                }
                                if (!empty($rows_by_time)) {
                                    foreach (array_keys($rows_by_time) as $actualSlotLabel) {
                                        if ($actualSlotLabel !== '' && !in_array($actualSlotLabel, $time_slots, true)) {
                                            $time_slots[] = $actualSlotLabel;
                                        }
                                    }
                                    $time_slots = array_values(array_unique(array_filter($time_slots, static function ($label) use ($groupKey) {
                                        return $groupKey === 'Saturday' ? true : (string)$label !== '';
                                    })));
                                }
                                if (empty($time_slots)) {
                                    $time_slots = [''];
                                }
                            ?>
                            <tr class="day-group-header">
                                <td><?php echo htmlspecialchars($groupTitle); ?></td>
                                <td colspan="6"></td>
                            </tr>
                            <?php foreach ($time_slots as $time_slot_label): ?>
                                <?php
                                $slot_rows = ($groupKey === 'Saturday') ? ($rows ?? []) : ($rows_by_time[$time_slot_label] ?? []);
                                $display_time_slot = ($groupKey === 'Saturday') ? '' : (string)$time_slot_label;
                                $slotExplicitRowCount = 0;
                                foreach ($slot_rows as $slot_row_meta) {
                                    $slotExplicitRowCount = max($slotExplicitRowCount, (int)($slot_row_meta['_report_slot_row_count'] ?? 1));
                                }
                                $slotPairMultiplier = $get_effective_slot_multiplier(
                                    (float)$sectionPairMultiplier,
                                    $slotExplicitRowCount > 0 ? $slotExplicitRowCount : count($slot_rows)
                                );
                                $forceDayLabel = (bool)preg_match('/^(MTh|TF)\//', (string)$groupKey);
                                ?>
                                <?php if ($time_slot_label === $break_time_label): ?>
                                    <tr class="lunch-row">
                                        <td class="col-center"><?php echo htmlspecialchars($display_time_slot); ?></td>
                                        <td colspan="6">BREAK TIME</td>
                                    </tr>
                                <?php elseif (!empty($slot_rows)): ?>
                                    <?php
                                    // Group by subject type
                                    $subj_by_type = [];
                                    foreach ($slot_rows as $sr) {
                                        $subjType = (string)($sr['subject_type'] ?? 'major');
                                        if (!isset($subj_by_type[$subjType])) {
                                            $subj_by_type[$subjType] = [];
                                        }
                                        $subj_by_type[$subjType][] = $sr;
                                    }
                                    $type_order = ['major', 'minor'];
                                    ?>
                                    <?php foreach ($type_order as $typeKey): ?>
                                        <?php if (isset($subj_by_type[$typeKey])): ?>
                                            <?php
                                            $type_rows = $subj_by_type[$typeKey];
                                            usort($type_rows, static function (array $a, array $b) use ($meeting_kind_rank): int {
                                                $meetingCompare = $meeting_kind_rank($a) <=> $meeting_kind_rank($b);
                                                if ($meetingCompare !== 0) {
                                                    return $meetingCompare;
                                                }
                                                return strcmp((string)($a['subject_code'] ?? ''), (string)($b['subject_code'] ?? ''));
                                            });
                                            ?>
                                            <?php foreach ($type_rows as $r): ?>
                                            <?php $slotTimeLabel = $format_slot_label_with_day((string)$display_time_slot, $r, count($slot_rows), $forceDayLabel); ?>
                                            <tr>
                                                <td class="col-center"><?php echo htmlspecialchars($slotTimeLabel); ?></td>
                                                <td class="subject-code-cell"><?php echo htmlspecialchars($r['subject_code'] ?? ''); ?></td>
                                                <td class="description-cell"><?php echo htmlspecialchars($r ? $format_subject_description($r) : ''); ?></td>
                                                <td class="col-center"><?php echo $r ? number_format($get_row_units($r) * $slotPairMultiplier, 2) : ''; ?></td>
                                                <td class="col-center"><?php echo $r ? number_format((float)($r['scheduled_hours'] ?? $r['hours_per_week'] ?? 0) * $slotPairMultiplier, 2) : ''; ?></td>
                                                <td class="instructor-cell"><?php echo htmlspecialchars($r['instructor_name'] ?? ''); ?></td>
                                                <td class="col-center"><?php echo htmlspecialchars((string)($r['report_room_label'] ?? $r['room_number'] ?? '')); ?></td>
                                            </tr>
                                            <?php ?>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td class="col-center"><?php echo htmlspecialchars($display_time_slot); ?></td>
                                        <td class="subject-code-cell"></td>
                                        <td class="description-cell"></td>
                                        <td class="col-center"></td>
                                        <td class="col-center"></td>
                                        <td class="instructor-cell"></td>
                                        <td class="col-center"></td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php endforeach; ?>
                            <tr class="section-total-row">
                                <td colspan="3" style="text-align:right;">TOTAL UNITS</td>
                                <td><?php echo number_format((float)($section['total_units'] ?? 0), 2); ?></td>
                                <td colspan="3"></td>
                            </tr>
                        </tbody>
                    </table>
                    <?php $renderReportFooter($signatories); ?>
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

        <?php if (isset($print_mode)): ?>
        <div class="print-footer">
            <p>Generated on: <?php echo date('Y-m-d H:i:s'); ?></p>
        </div>
        <?php endif; ?>
        <script>
            function printBlock(btn) {
                const workloadGroup = btn.closest('.workload-report-group');
                if (workloadGroup) {
                    document.body.classList.add('printing-block');
                    workloadGroup.closest('.schedule-report')?.classList.add('printing');
                    workloadGroup.querySelectorAll('.workload-sheet').forEach(function (node) {
                        node.classList.add('printing');
                    });
                    setTimeout(() => window.print(), 100);
                    return;
                }

                const block = btn.closest('.schedule-section-block') || btn.closest('.workload-sheet');
                if (!block) {
                    window.print();
                    return;
                }

                document.body.classList.add('printing-block');
                block.closest('.schedule-report')?.classList.add('printing');
                block.classList.add('printing');
                setTimeout(() => window.print(), 100);
            }

            window.addEventListener('afterprint', function () {
                document.body.classList.remove('printing-block');
                document.querySelectorAll('.printing').forEach(function (node) {
                    node.classList.remove('printing');
                });
            });

            const departmentInput = document.getElementById('department');
            const programInput = document.getElementById('program');
            const departmentOptions = <?php echo json_encode(array_map(function ($dept) {
                return [
                    'id' => (int)$dept['id'],
                    'name' => (string)$dept['dept_name'],
                    'code' => (string)$dept['dept_code'],
                    'label' => (string)($dept['dept_name'] . ' (' . $dept['dept_code'] . ')'),
                ];
            }, $departments), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
            const programOptions = <?php echo json_encode(array_map(function ($prog) {
                return [
                    'name' => (string)$prog['program_name'],
                    'code' => (string)$prog['program_code'],
                    'label' => (string)($prog['program_name'] . ' (' . $prog['program_code'] . ')'),
                    'department_id' => (int)($prog['department_id'] ?? 0),
                ];
            }, $programs), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

            function resolveDepartmentId() {
                if (!departmentInput) {
                    return 0;
                }
                const current = String(departmentInput.value || '').trim().toLowerCase();
                const match = departmentOptions.find(option => {
                    return option.name.toLowerCase() === current
                        || option.code.toLowerCase() === current
                        || option.label.toLowerCase() === current;
                });
                return match ? Number(match.id || 0) : 0;
            }

            function syncProgramOptions() {
                const dataList = document.getElementById('report_program_options');
                if (!programInput || !dataList) {
                    return;
                }
                const selectedDepartmentId = resolveDepartmentId();
                const currentValue = String(programInput.value || '').trim().toLowerCase();
                const allowedPrograms = programOptions.filter(option => selectedDepartmentId === 0 || Number(option.department_id) === selectedDepartmentId);
                dataList.innerHTML = '';
                allowedPrograms.forEach(option => {
                    const byLabel = document.createElement('option');
                    byLabel.value = option.label;
                    dataList.appendChild(byLabel);

                    const byCode = document.createElement('option');
                    byCode.value = option.code;
                    dataList.appendChild(byCode);
                });
                if (currentValue !== '') {
                    const stillValid = allowedPrograms.some(option =>
                        option.label.toLowerCase() === currentValue || option.code.toLowerCase() === currentValue || option.name.toLowerCase() === currentValue
                    );
                    if (!stillValid) {
                        programInput.value = '';
                    }
                }
            }

            if (departmentInput && programInput) {
                departmentInput.addEventListener('input', syncProgramOptions);
                syncProgramOptions();
            }

            const consultationTimeOptionsByGroup = <?php echo json_encode($consultation_time_options_by_group, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
            const consultationDayGroupSelect = document.getElementById('consultation_day_group');
            const consultationTimeLabelSelect = document.getElementById('consultation_time_label');
            function syncConsultationTimeOptions() {
                if (!consultationDayGroupSelect || !consultationTimeLabelSelect) {
                    return;
                }
                const selectedGroup = consultationDayGroupSelect.value || '';
                const options = consultationTimeOptionsByGroup[selectedGroup] || [];
                consultationTimeLabelSelect.innerHTML = '';
                options.forEach(label => {
                    const opt = document.createElement('option');
                    opt.value = label;
                    opt.textContent = label;
                    consultationTimeLabelSelect.appendChild(opt);
                });
            }
            if (consultationDayGroupSelect && consultationTimeLabelSelect) {
                consultationDayGroupSelect.addEventListener('change', syncConsultationTimeOptions);
                syncConsultationTimeOptions();
            }

            <?php if (isset($print_mode)): ?>
            window.onload = function () { window.print(); };
            <?php endif; ?>
        </script>
    </div>
</body>
</html>

