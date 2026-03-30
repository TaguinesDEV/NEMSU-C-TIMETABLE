<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$sourceFile = $root . DIRECTORY_SEPARATOR . 'subject and sem.sql';
$replaceAllSubjects = in_array('--replace-all', $argv ?? [], true);

if (!is_file($sourceFile)) {
    fwrite(STDERR, "Source file not found: {$sourceFile}\n");
    exit(1);
}

$pdo = new PDO('mysql:host=localhost;dbname=academic_scheduling;charset=utf8mb4', 'root', '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function ensurePrograms(PDO $pdo): array
{
    $pdo->exec("
        INSERT INTO departments (dept_name, dept_code)
        VALUES ('Department of Computer Studies', 'DCS')
        ON DUPLICATE KEY UPDATE dept_name = VALUES(dept_name)
    ");

    $departmentId = (int)$pdo->query("SELECT id FROM departments WHERE dept_code = 'DCS' LIMIT 1")->fetchColumn();
    if ($departmentId <= 0) {
        throw new RuntimeException('Unable to resolve DCS department.');
    }

    $programs = [
        ['Computer Science', 'CS'],
        ['Information Technology', 'IT'],
        ['Computer Engineering', 'CPE'],
    ];

    $stmt = $pdo->prepare("
        INSERT INTO programs (program_name, program_code, department_id)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE
            program_name = VALUES(program_name),
            department_id = VALUES(department_id)
    ");
    foreach ($programs as [$name, $code]) {
        $stmt->execute([$name, $code, $departmentId]);
    }

    $programIdByCode = [];
    $lookup = $pdo->query("SELECT id, program_code FROM programs");
    foreach ($lookup->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $programIdByCode[strtoupper((string)$row['program_code'])] = (int)$row['id'];
    }

    return $programIdByCode;
}

function ensureSubjectSchema(PDO $pdo): void
{
    $pdo->exec("ALTER TABLE subjects MODIFY COLUMN semester ENUM('1st Semester','2nd Semester','Summer') NOT NULL DEFAULT '1st Semester'");

    $columns = [];
    foreach ($pdo->query("SHOW COLUMNS FROM subjects")->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $columns[$column['Field']] = true;
    }

    if (!isset($columns['program_id'])) {
        $pdo->exec("ALTER TABLE subjects ADD COLUMN program_id INT NULL AFTER department");
    }
    if (!isset($columns['subject_type'])) {
        $pdo->exec("ALTER TABLE subjects ADD COLUMN subject_type ENUM('major','minor') NOT NULL DEFAULT 'major' AFTER program_id");
    }
    if (!isset($columns['year_level'])) {
        $pdo->exec("ALTER TABLE subjects ADD COLUMN year_level INT NULL AFTER semester");
    }
    if (!isset($columns['prerequisites'])) {
        $pdo->exec("ALTER TABLE subjects ADD COLUMN prerequisites TEXT NULL AFTER year_level");
    }
    if (!isset($columns['lecture_hours'])) {
        $pdo->exec("ALTER TABLE subjects ADD COLUMN lecture_hours DECIMAL(6,2) NOT NULL DEFAULT 0.00 AFTER hours_per_week");
    }
    if (!isset($columns['lab_hours'])) {
        $pdo->exec("ALTER TABLE subjects ADD COLUMN lab_hours DECIMAL(6,2) NOT NULL DEFAULT 0.00 AFTER lecture_hours");
    }
    if (!isset($columns['meetings_per_week'])) {
        $pdo->exec("ALTER TABLE subjects ADD COLUMN meetings_per_week TINYINT NOT NULL DEFAULT 2 AFTER lab_hours");
    }
    if (!isset($columns['lecture_minutes_per_meeting'])) {
        $pdo->exec("ALTER TABLE subjects ADD COLUMN lecture_minutes_per_meeting INT NOT NULL DEFAULT 0 AFTER meetings_per_week");
    }
    if (!isset($columns['lab_minutes_per_meeting'])) {
        $pdo->exec("ALTER TABLE subjects ADD COLUMN lab_minutes_per_meeting INT NOT NULL DEFAULT 0 AFTER lecture_minutes_per_meeting");
    }

    $pdo->exec("ALTER TABLE subjects MODIFY hours_per_week DECIMAL(6,2) NOT NULL");
    $pdo->exec("ALTER TABLE subjects MODIFY lecture_hours DECIMAL(6,2) NOT NULL DEFAULT 0.00");
    $pdo->exec("ALTER TABLE subjects MODIFY lab_hours DECIMAL(6,2) NOT NULL DEFAULT 0.00");

    $indexes = $pdo->query("SHOW INDEX FROM subjects")->fetchAll(PDO::FETCH_ASSOC);
    $hasCompositeUnique = false;
    $hasSubjectCodeIndex = false;
    foreach ($indexes as $index) {
        if (($index['Key_name'] ?? '') === 'uq_subject_scope') {
            $hasCompositeUnique = true;
        }
        if (($index['Key_name'] ?? '') === 'subject_code') {
            $pdo->exec("ALTER TABLE subjects DROP INDEX subject_code");
        }
        if (($index['Key_name'] ?? '') === 'idx_subject_code') {
            $hasSubjectCodeIndex = true;
        }
    }
    if (!$hasCompositeUnique) {
        $pdo->exec("ALTER TABLE subjects ADD UNIQUE KEY uq_subject_scope (subject_code, program_id, semester, year_level)");
    }
    if (!$hasSubjectCodeIndex) {
        $pdo->exec("ALTER TABLE subjects ADD INDEX idx_subject_code (subject_code)");
    }
}

function assertSafeToReplaceSubjects(PDO $pdo): void
{
    $scheduleCount = (int)$pdo->query("SELECT COUNT(*) FROM schedules")->fetchColumn();
    $jobCount = (int)$pdo->query("SELECT COUNT(*) FROM schedule_jobs")->fetchColumn();

    if ($scheduleCount > 0 || $jobCount > 0) {
        throw new RuntimeException(
            "Cannot rebuild subjects while schedules or schedule jobs exist. " .
            "Please clear them first or run without --replace-all."
        );
    }
}

function replaceAllSubjects(PDO $pdo): void
{
    assertSafeToReplaceSubjects($pdo);
    $pdo->exec("DELETE FROM subjects");
    $pdo->exec("ALTER TABLE subjects AUTO_INCREMENT = 1");
}

function loadIntoTempTable(PDO $pdo, string $sourceFile): void
{
    $pdo->exec("DROP TEMPORARY TABLE IF EXISTS temp_curriculum_subjects");
    $pdo->exec("
        CREATE TEMPORARY TABLE temp_curriculum_subjects (
            id INT NOT NULL,
            course_code VARCHAR(50) NOT NULL,
            subject_name VARCHAR(255) NOT NULL,
            lec_hours DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            lab_hours DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            units DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            semester VARCHAR(30) NOT NULL,
            year_level INT NOT NULL,
            prerequisites TEXT NULL,
            program VARCHAR(100) NOT NULL
        )
    ");

    $sql = file_get_contents($sourceFile);
    if ($sql === false) {
        throw new RuntimeException("Unable to read source file.");
    }

    $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql);
    $sql = preg_replace('/INSERT\s+INTO\s+`?subjects`?/i', 'INSERT INTO temp_curriculum_subjects', $sql, 1);
    if ($sql === null) {
        throw new RuntimeException("Unable to prepare import SQL.");
    }

    $pdo->exec($sql);
}

function normalizeSemesterValue(string $semester): string
{
    $value = strtoupper(trim($semester));
    if ($value === '1ST SEM' || $value === '1ST SEMESTER') {
        return '1st Semester';
    }
    if ($value === '2ND SEM' || $value === '2ND SEMESTER') {
        return '2nd Semester';
    }
    if ($value === 'SUMMER') {
        return 'Summer';
    }
    return '1st Semester';
}

function detectSubjectType(string $code): string
{
    $normalized = strtoupper(trim($code));
    foreach (['CS', 'CPE', 'IT'] as $majorPrefix) {
        if (strpos($normalized, $majorPrefix) === 0) {
            return 'major';
        }
    }
    return 'minor';
}

function resolveProgramCode(string $programLabel): string
{
    $normalized = strtolower(trim($programLabel));
    if ($normalized === 'computer science' || $normalized === 'cs') {
        return 'CS';
    }
    if ($normalized === 'information technology' || $normalized === 'it') {
        return 'IT';
    }
    if ($normalized === 'computer engineering' || $normalized === 'cpe') {
        return 'CPE';
    }
    return '';
}

function buildDepartmentLabel(string $programLabel): string
{
    $programCode = resolveProgramCode($programLabel);
    if ($programCode === 'CS') {
        return 'Computer Science';
    }
    if ($programCode === 'IT') {
        return 'Information Technology';
    }
    if ($programCode === 'CPE') {
        return 'Computer Engineering';
    }
    return trim($programLabel);
}

function determineMeetingDefaults(float $lectureHours, float $labHours, string $subjectType): array
{
    $meetingsPerWeek = 2;
    $lectureMinutes = 0;
    $labMinutes = 0;

    if ($subjectType === 'minor') {
        $lectureMinutes = (int) round(($lectureHours + $labHours) * 60 / $meetingsPerWeek);
        return [$meetingsPerWeek, $lectureMinutes, 0];
    }

    if (abs($lectureHours - 2.0) < 0.01 && abs($labHours - 3.0) < 0.01) {
        return [$meetingsPerWeek, 120, 145];
    }

    if ($lectureHours > 0) {
        $lectureMinutes = (int) round(($lectureHours * 60) / $meetingsPerWeek);
    }
    if ($labHours > 0) {
        $labMinutes = (int) round(($labHours * 60) / $meetingsPerWeek);
    }

    if ($lectureMinutes <= 0 && $labMinutes <= 0) {
        $lectureMinutes = $subjectType === 'minor' ? 90 : 120;
    }

    return [$meetingsPerWeek, $lectureMinutes, $labMinutes];
}

try {
    $programIdByCode = ensurePrograms($pdo);
    ensureSubjectSchema($pdo);
    if ($replaceAllSubjects) {
        replaceAllSubjects($pdo);
    }
    loadIntoTempTable($pdo, $sourceFile);

    $upsert = $pdo->prepare("
        INSERT INTO subjects (
            subject_code,
            subject_name,
            credits,
            department,
            program_id,
            subject_type,
            semester,
            year_level,
            prerequisites,
            hours_per_week,
            lecture_hours,
            lab_hours,
            meetings_per_week,
            lecture_minutes_per_meeting,
            lab_minutes_per_meeting
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            subject_name = VALUES(subject_name),
            credits = VALUES(credits),
            department = VALUES(department),
            program_id = VALUES(program_id),
            subject_type = VALUES(subject_type),
            semester = VALUES(semester),
            year_level = VALUES(year_level),
            prerequisites = VALUES(prerequisites),
            hours_per_week = VALUES(hours_per_week),
            lecture_hours = VALUES(lecture_hours),
            lab_hours = VALUES(lab_hours),
            meetings_per_week = VALUES(meetings_per_week),
            lecture_minutes_per_meeting = VALUES(lecture_minutes_per_meeting),
            lab_minutes_per_meeting = VALUES(lab_minutes_per_meeting)
    ");

    $rows = $pdo->query("
        SELECT course_code, subject_name, lec_hours, lab_hours, units, semester, year_level, prerequisites, program
        FROM temp_curriculum_subjects
        ORDER BY id
    ")->fetchAll(PDO::FETCH_ASSOC);

    $inserted = 0;
    foreach ($rows as $row) {
        $programLabel = trim((string)$row['program']);
        $departmentCode = resolveProgramCode($programLabel);
        if ($departmentCode === '') {
            continue;
        }

        $programLookupCode = $departmentCode;
        if (!isset($programIdByCode[$programLookupCode])) {
            continue;
        }
        $programId = $programIdByCode[$programLookupCode];

        $subjectCode = trim((string)$row['course_code']);
        $subjectName = trim((string)$row['subject_name']);
        $credits = (int)round((float)$row['units']);
        $lectureHours = round((float)$row['lec_hours'], 2);
        $labHours = round((float)$row['lab_hours'], 2);
        $hoursPerWeek = round($lectureHours + $labHours, 2);
        $semester = normalizeSemesterValue((string)$row['semester']);
        $yearLevel = (int)$row['year_level'];
        $prerequisites = trim((string)($row['prerequisites'] ?? ''));
        $subjectType = detectSubjectType($subjectCode);
        $department = buildDepartmentLabel($programLabel);
        [$meetingsPerWeek, $lectureMinutes, $labMinutes] = determineMeetingDefaults($lectureHours, $labHours, $subjectType);

        $upsert->execute([
            $subjectCode,
            $subjectName,
            $credits,
            $department,
            $programId,
            $subjectType,
            $semester,
            $yearLevel,
            $prerequisites,
            $hoursPerWeek,
            $lectureHours,
            $labHours,
            $meetingsPerWeek,
            $lectureMinutes,
            $labMinutes,
        ]);
        $inserted++;
    }

    fwrite(
        STDOUT,
        sprintf(
            "Imported or updated %d curriculum subject rows%s.\n",
            $inserted,
            $replaceAllSubjects ? ' after replacing all existing subjects' : ''
        )
    );
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
