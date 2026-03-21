<?php
require_once '../includes/auth.php';
requireAdmin();

$pdo = getDB();
$entry_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$message = '';
$error = '';

$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function fetchScheduleEntry(PDO $pdo, int $entryId): ?array
{
    $stmt = $pdo->prepare("\n        SELECT s.*, sub.subject_code, sub.subject_name\n        FROM schedules s\n        JOIN subjects sub ON s.subject_id = sub.id\n        WHERE s.id = ?\n    ");
    $stmt->execute([$entryId]);
    $entry = $stmt->fetch(PDO::FETCH_ASSOC);
    return $entry ?: null;
}

function fetchInstructors(PDO $pdo): array
{
    return $pdo->query("\n        SELECT i.id, u.full_name\n        FROM instructors i\n        JOIN users u ON i.user_id = u.id\n        ORDER BY u.full_name\n    ")->fetchAll(PDO::FETCH_ASSOC);
}

function fetchRooms(PDO $pdo): array
{
    return $pdo->query("SELECT id, room_number, building, capacity FROM rooms ORDER BY room_number, building")
        ->fetchAll(PDO::FETCH_ASSOC);
}

function fetchTimeSlots(PDO $pdo): array
{
    return $pdo->query("\n        SELECT id, day, start_time, end_time\n        FROM time_slots\n        ORDER BY FIELD(day,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'), start_time\n    ")->fetchAll(PDO::FETCH_ASSOC);
}

function roomLabel(array $room, bool $isConflict = false): string
{
    $label = $room['room_number'] . ' (' . ($room['building'] ?: 'No Building') . ', Capacity: ' . $room['capacity'] . ')';
    if ($isConflict) {
        $label .= ' - CURRENT CONFLICT';
    }
    return $label;
}

function timeSlotLabel(array $slot, bool $isConflict = false): string
{
    $label = $slot['day'] . ': ' . date('g:i A', strtotime((string)$slot['start_time'])) . ' - ' . date('g:i A', strtotime((string)$slot['end_time']));
    if ($isConflict) {
        $label .= ' - CURRENT CONFLICT';
    }
    return $label;
}

function getAvailableRooms(PDO $pdo, int $timeSlotId, int $entryId, int $currentRoomId): array
{
    $rooms = fetchRooms($pdo);
    $stmt = $pdo->prepare("\n        SELECT DISTINCT room_id\n        FROM schedules\n        WHERE time_slot_id = ?\n          AND id <> ?\n    ");
    $stmt->execute([$timeSlotId, $entryId]);
    $blocked = array_flip(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));

    $available = [];
    $currentRoom = null;
    foreach ($rooms as $room) {
        $roomId = (int)$room['id'];
        if ($roomId === $currentRoomId) {
            $currentRoom = $room;
        }
        if (!isset($blocked[$roomId])) {
            $available[] = [
                'id' => $roomId,
                'label' => roomLabel($room),
            ];
        }
    }

    $hasCurrent = false;
    foreach ($available as $room) {
        if ((int)$room['id'] === $currentRoomId) {
            $hasCurrent = true;
            break;
        }
    }

    if (!$hasCurrent && $currentRoom !== null) {
        array_unshift($available, [
            'id' => $currentRoomId,
            'label' => roomLabel($currentRoom, true),
        ]);
    }

    return $available;
}

function getAvailableTimeSlots(PDO $pdo, int $instructorId, int $roomId, int $entryId, int $currentTimeSlotId): array
{
    $timeSlots = fetchTimeSlots($pdo);
    $stmt = $pdo->prepare("\n        SELECT DISTINCT time_slot_id\n        FROM schedules\n        WHERE id <> ?\n          AND (room_id = ? OR instructor_id = ?)\n    ");
    $stmt->execute([$entryId, $roomId, $instructorId]);
    $blocked = array_flip(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));

    $available = [];
    $currentSlot = null;
    foreach ($timeSlots as $slot) {
        $slotId = (int)$slot['id'];
        if ($slotId === $currentTimeSlotId) {
            $currentSlot = $slot;
        }
        if (!isset($blocked[$slotId])) {
            $available[] = [
                'id' => $slotId,
                'label' => timeSlotLabel($slot),
            ];
        }
    }

    $hasCurrent = false;
    foreach ($available as $slot) {
        if ((int)$slot['id'] === $currentTimeSlotId) {
            $hasCurrent = true;
            break;
        }
    }

    if (!$hasCurrent && $currentSlot !== null) {
        array_unshift($available, [
            'id' => $currentTimeSlotId,
            'label' => timeSlotLabel($currentSlot, true),
        ]);
    }

    return $available;
}

function renderOptions(array $options, int $selectedId): string
{
    $html = '';
    foreach ($options as $option) {
        $isSelected = (int)$option['id'] === $selectedId ? ' selected' : '';
        $html .= '<option value="' . (int)$option['id'] . '"' . $isSelected . '>' . h($option['label']) . '</option>';
    }
    return $html;
}

function renderEditForm(array $entry, array $instructors, array $roomOptions, array $timeSlotOptions, string $message, string $error, bool $isAjax): void
{
    ?>
    <div class="entry-info">
        <h3>Editing: <?php echo h($entry['subject_code'] ?? 'Unknown'); ?> - <?php echo h($entry['subject_name'] ?? 'N/A'); ?></h3>
        <p style="margin: 8px 0 0; color: #6b7280;">Only available rooms and time slots are shown for the selected combination.</p>
    </div>

    <?php if ($message !== ''): ?>
        <div class="success"><?php echo h($message); ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo h($error); ?></div>
    <?php endif; ?>

    <form method="POST" class="edit-form" action="edit_schedule_entry.php?id=<?php echo (int)$entry['id']; ?>">
        <div class="form-group">
            <label for="instructor_id">Instructor:</label>
            <select id="instructor_id" name="instructor_id" required>
                <?php foreach ($instructors as $instructor): ?>
                    <option value="<?php echo (int)$instructor['id']; ?>" <?php echo (int)$instructor['id'] === (int)$entry['instructor_id'] ? 'selected' : ''; ?>>
                        <?php echo h($instructor['full_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="room_id">Room:</label>
            <select id="room_id" name="room_id" required>
                <?php echo renderOptions($roomOptions, (int)$entry['room_id']); ?>
            </select>
        </div>

        <div class="form-group">
            <label for="time_slot_id">Time Slot:</label>
            <select id="time_slot_id" name="time_slot_id" required>
                <?php echo renderOptions($timeSlotOptions, (int)$entry['time_slot_id']); ?>
            </select>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn-icon btn-primary" <?php echo $isAjax ? 'style="margin-left:auto;"' : ''; ?>>
                <i class="fas fa-save"></i> Update Entry
            </button>
            <?php if ($isAjax): ?>
                <a href="#" onclick="closeModal(); return false;" class="btn-icon btn-secondary">
                    <i class="fas fa-times"></i> Cancel
                </a>
            <?php else: ?>
                <a href="view_schedules.php?job_id=<?php echo (int)$entry['job_id']; ?>" class="btn-icon btn-secondary">
                    <i class="fas fa-times"></i> Cancel
                </a>
            <?php endif; ?>
        </div>
    </form>

    <script>
    (function () {
        const form = document.querySelector('.edit-form');
        if (!form) {
            return;
        }

        const instructorSelect = document.getElementById('instructor_id');
        const roomSelect = document.getElementById('room_id');
        const timeSlotSelect = document.getElementById('time_slot_id');
        const scheduleId = <?php echo (int)$entry['id']; ?>;

        function refillSelect(select, options, preferredValue) {
            const preferred = String(preferredValue || '');
            select.innerHTML = '';
            options.forEach(optionData => {
                const option = document.createElement('option');
                option.value = String(optionData.id);
                option.textContent = optionData.label;
                if (String(optionData.id) === preferred) {
                    option.selected = true;
                }
                select.appendChild(option);
            });

            if (select.options.length > 0 && !Array.from(select.options).some(option => option.selected)) {
                select.options[0].selected = true;
            }
        }

        async function refreshAvailability(changedField) {
            const currentInstructor = instructorSelect.value;
            const currentRoom = roomSelect.value;
            const currentTimeSlot = timeSlotSelect.value;
            const params = new URLSearchParams({
                id: String(scheduleId),
                availability: '1',
                instructor_id: currentInstructor,
                room_id: currentRoom,
                time_slot_id: currentTimeSlot
            });

            const response = await fetch(`edit_schedule_entry.php?${params.toString()}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (!response.ok) {
                throw new Error('Failed to load availability options.');
            }

            const data = await response.json();
            refillSelect(roomSelect, data.rooms || [], currentRoom);
            refillSelect(timeSlotSelect, data.time_slots || [], currentTimeSlot);
        }

        instructorSelect.addEventListener('change', function () {
            refreshAvailability('instructor').catch(error => console.error(error));
        });

        roomSelect.addEventListener('change', function () {
            refreshAvailability('room').catch(error => console.error(error));
        });

        timeSlotSelect.addEventListener('change', function () {
            refreshAvailability('time').catch(error => console.error(error));
        });
    })();
    </script>
    <?php
}

$entry = fetchScheduleEntry($pdo, $entry_id);
if ($entry === null) {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Schedule entry not found']);
        exit();
    }
    header('Location: dashboard.php');
    exit();
}

if (isset($_GET['availability'])) {
    $instructorId = isset($_GET['instructor_id']) ? (int)$_GET['instructor_id'] : (int)$entry['instructor_id'];
    $roomId = isset($_GET['room_id']) ? (int)$_GET['room_id'] : (int)$entry['room_id'];
    $timeSlotId = isset($_GET['time_slot_id']) ? (int)$_GET['time_slot_id'] : (int)$entry['time_slot_id'];

    header('Content-Type: application/json');
    echo json_encode([
        'rooms' => getAvailableRooms($pdo, $timeSlotId, $entry_id, $roomId),
        'time_slots' => getAvailableTimeSlots($pdo, $instructorId, $roomId, $entry_id, $timeSlotId),
    ]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $instructor_id = isset($_POST['instructor_id']) ? (int)$_POST['instructor_id'] : 0;
    $room_id = isset($_POST['room_id']) ? (int)$_POST['room_id'] : 0;
    $time_slot_id = isset($_POST['time_slot_id']) ? (int)$_POST['time_slot_id'] : 0;

    if (!$instructor_id || !$room_id || !$time_slot_id) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Missing required fields']);
            exit();
        }
        $error = 'All fields are required.';
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM schedules WHERE time_slot_id = ? AND room_id = ? AND id != ?");
        $stmt->execute([$time_slot_id, $room_id, $entry_id]);
        $room_conflict = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM schedules WHERE time_slot_id = ? AND instructor_id = ? AND id != ?");
        $stmt->execute([$time_slot_id, $instructor_id, $entry_id]);
        $instructor_conflict = (int)$stmt->fetchColumn();

        if ($room_conflict > 0) {
            $error = 'This room is already booked for the selected time slot.';
        } elseif ($instructor_conflict > 0) {
            $error = 'This instructor is already assigned to another class at this time.';
        } else {
            $stmt = $pdo->prepare("\n                UPDATE schedules\n                SET instructor_id = ?, room_id = ?, time_slot_id = ?\n                WHERE id = ?\n            ");
            if ($stmt->execute([$instructor_id, $room_id, $time_slot_id, $entry_id])) {
                if ($is_ajax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => 'Schedule entry updated successfully!']);
                    exit();
                }
                $message = 'Schedule entry updated successfully!';
                $entry = fetchScheduleEntry($pdo, $entry_id);
            } else {
                $error = 'Update failed. Please try again.';
            }
        }

        if ($is_ajax && $error !== '') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $error]);
            exit();
        }
    }
}

$instructors = fetchInstructors($pdo);
$roomOptions = getAvailableRooms($pdo, (int)$entry['time_slot_id'], $entry_id, (int)$entry['room_id']);
$timeSlotOptions = getAvailableTimeSlots($pdo, (int)$entry['instructor_id'], (int)$entry['room_id'], $entry_id, (int)$entry['time_slot_id']);

if ($is_ajax) {
    renderEditForm($entry, $instructors, $roomOptions, $timeSlotOptions, $message, $error, true);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Schedule Entry - Academic Scheduling System</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        .btn-icon {
            padding: 8px 12px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: background-color 0.3s, transform 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            text-decoration: none;
            color: white;
        }
        .btn-icon:hover {
            transform: translateY(-2px);
        }
        .btn-primary {
            background-color: #667eea;
            color: #fff;
        }
        .btn-primary:hover {
            background-color: #5a6edc;
        }
        .btn-secondary {
            background-color: #6c757d;
            color: #fff;
        }
        .btn-secondary:hover {
            background-color: #5a6268;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-content">
            <h1>Edit Schedule Entry</h1>
            <div class="nav-links">
                <a href="view_schedules.php?job_id=<?php echo (int)$entry['job_id']; ?>">Back to Schedule</a>
                <a href="../logout.php">Logout</a>
            </div>
        </div>
    </div>

    <div class="container">
        <?php renderEditForm($entry, $instructors, $roomOptions, $timeSlotOptions, $message, $error, false); ?>
    </div>
</body>
</html>
