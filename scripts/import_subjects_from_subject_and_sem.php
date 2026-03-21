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
    $programs = [
        'CS' => ['Bachelor of Science in Computer Science', 'BSCS'],
        'IT' => ['Bachelor of Science in Information Technology', 'BSIT'],
        'CPE' => ['Bachelor of Science in Computer Engineering', 'BSCPE'],
    ];

    $stmt = $pdo->prepare("
        INSERT INTO programs (program_name, program_code)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE program_name = VALUES(program_name)
    ");
    foreach ($programs as [$name, $code]) {
        $stmt->execute([$name, $code]);
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
            department VARCHAR(20) NOT NULL
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

function buildDepartmentLabel(string $departmentCode): string
{
    return strtoupper(trim($departmentCode));
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
            lab_hours
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
            lab_hours = VALUES(lab_hours)
    ");

    $rows = $pdo->query("
        SELECT course_code, subject_name, lec_hours, lab_hours, units, semester, year_level, prerequisites, department
        FROM temp_curriculum_subjects
        ORDER BY id
    ")->fetchAll(PDO::FETCH_ASSOC);

    $inserted = 0;
    foreach ($rows as $row) {
        $departmentCode = strtoupper(trim((string)$row['department']));
        if (!isset($programIdByCode[$departmentCode === 'CPE' ? 'BSCPE' : 'BS' . $departmentCode])) {
            if ($departmentCode === 'CS' && isset($programIdByCode['BSCS'])) {
                $programId = $programIdByCode['BSCS'];
            } elseif ($departmentCode === 'IT' && isset($programIdByCode['BSIT'])) {
                $programId = $programIdByCode['BSIT'];
            } elseif ($departmentCode === 'CPE' && isset($programIdByCode['BSCPE'])) {
                $programId = $programIdByCode['BSCPE'];
            } else {
                continue;
            }
        } else {
            $programId = $programIdByCode[$departmentCode === 'CPE' ? 'BSCPE' : 'BS' . $departmentCode];
        }

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
        $department = buildDepartmentLabel($departmentCode);

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
