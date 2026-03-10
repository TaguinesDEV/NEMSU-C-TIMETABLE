<?php
require_once '../includes/auth.php';
requireAdmin();

$pdo = getDB();
$entry_id = $_GET['id'] ?? 0;
$message = '';
$error = '';

// Fetch the schedule entry
$stmt = $pdo->prepare("
    SELECT s.*, sub.subject_code, sub.subject_name
    FROM schedules s
    JOIN subjects sub ON s.subject_id = sub.id
    WHERE s.id = ?
");
$stmt->execute([$entry_id]);
$entry = $stmt->fetch();

if (!$entry) {
    header('Location: dashboard.php');
    exit();
}

// Fetch dropdown data
$instructors = $pdo->query("
    SELECT i.id, u.full_name 
    FROM instructors i 
    JOIN users u ON i.user_id = u.id
")->fetchAll();

$rooms = $pdo->query("SELECT * FROM rooms")->fetchAll();
$time_slots = $pdo->query("SELECT * FROM time_slots ORDER BY day, start_time")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $instructor_id = $_POST['instructor_id'];
    $room_id = $_POST['room_id'];
    $time_slot_id = $_POST['time_slot_id'];
    
    // Check for conflicts
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM schedules 
        WHERE time_slot_id = ? AND room_id = ? AND id != ?
    ");
    $stmt->execute([$time_slot_id, $room_id, $entry_id]);
    $room_conflict = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM schedules 
        WHERE time_slot_id = ? AND instructor_id = ? AND id != ?
    ");
    $stmt->execute([$time_slot_id, $instructor_id, $entry_id]);
    $instructor_conflict = $stmt->fetchColumn();
    
    if ($room_conflict > 0) {
        $error = "This room is already booked for the selected time slot.";
    } elseif ($instructor_conflict > 0) {
        $error = "This instructor is already assigned to another class at this time.";
    } else {
        $stmt = $pdo->prepare("
            UPDATE schedules 
            SET instructor_id = ?, room_id = ?, time_slot_id = ?
            WHERE id = ?
        ");
        $stmt->execute([$instructor_id, $room_id, $time_slot_id, $entry_id]);
        $message = "Schedule entry updated successfully!";
        
        // Refresh entry data
        $stmt = $pdo->prepare("SELECT s.* FROM schedules s WHERE s.id = ?");
        $stmt->execute([$entry_id]);
        $entry = $stmt->fetch();
    }
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
                <a href="view_schedules.php?job_id=<?php echo $entry['job_id']; ?>">Back to Schedule</a>
                <a href="../logout.php">Logout</a>
            </div>
        </div>
    </div>
    
    <div class="container">
        <?php if ($message): ?>
            <div class="success"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <div class="entry-info">
            <h3>Editing: <?php echo $entry['subject_code']; ?> - <?php echo $entry['subject_name']; ?></h3>
        </div>
        
        <form method="POST" class="edit-form">
            <div class="form-group">
                <label for="instructor_id">Instructor:</label>
                <select id="instructor_id" name="instructor_id" required>
                    <?php foreach ($instructors as $instructor): ?>
                    <option value="<?php echo $instructor['id']; ?>" 
                            <?php echo $instructor['id'] == $entry['instructor_id'] ? 'selected' : ''; ?>>
                        <?php echo $instructor['full_name']; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="room_id">Room:</label>
                <select id="room_id" name="room_id" required>
                    <?php foreach ($rooms as $room): ?>
                    <option value="<?php echo $room['id']; ?>" 
                            <?php echo $room['id'] == $entry['room_id'] ? 'selected' : ''; ?>>
                        <?php echo $room['room_number']; ?> (Capacity: <?php echo $room['capacity']; ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="time_slot_id">Time Slot:</label>
                <select id="time_slot_id" name="time_slot_id" required>
                    <?php foreach ($time_slots as $slot): ?>
                    <option value="<?php echo $slot['id']; ?>" 
                            <?php echo $slot['id'] == $entry['time_slot_id'] ? 'selected' : ''; ?>>
                        <?php echo $slot['day']; ?>: 
                        <?php echo date('g:i A', strtotime($slot['start_time'])); ?> - 
                        <?php echo date('g:i A', strtotime($slot['end_time'])); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn-icon btn-primary"><i class="fas fa-save"></i> Update Entry</button>
                <a href="view_schedules.php?job_id=<?php echo $entry['job_id']; ?>" class="btn-icon btn-secondary"><i class="fas fa-times"></i> Cancel</a>
            </div>
        </form>
    </div>
</body>
</html>