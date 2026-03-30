<?php
require_once '../includes/auth.php';
requireAdmin();

$pdo = getDB();
$message = '';
$error = '';

$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

// Handle Add/Edit/Delete operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_time_slot'])) {
        $day = $_POST['day'];
        $start_time = $_POST['start_time'];
        $end_time = $_POST['end_time'];
        $slot_type = $_POST['slot_type'] ?? 'regular';
        
        // If Saturday, default to makeup if not specified
        if ($day === 'Saturday' && $slot_type === 'regular') {
            $slot_type = 'makeup';
        }
        
        try {
            $stmt = $pdo->prepare("INSERT INTO time_slots (day, start_time, end_time, slot_type) VALUES (?, ?, ?, ?)");
            $stmt->execute([$day, $start_time, $end_time, $slot_type]);
            $message = "Time slot added successfully!";
        } catch (Exception $e) {
            $error = "Error adding time slot: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['edit_time_slot'])) {
        $id = $_POST['slot_id'];
        $day = $_POST['day'];
        $start_time = $_POST['start_time'];
        $end_time = $_POST['end_time'];
        $slot_type = $_POST['slot_type'] ?? 'regular';
        
        try {
            $stmt = $pdo->prepare("UPDATE time_slots SET day = ?, start_time = ?, end_time = ?, slot_type = ? WHERE id = ?");
            $stmt->execute([$day, $start_time, $end_time, $slot_type, $id]);
            $message = "Time slot updated successfully!";
        } catch (Exception $e) {
            $error = "Error updating time slot: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['delete_time_slot'])) {
        $id = $_POST['slot_id'];
        
        try {
            // Check if time slot is used in schedules
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM schedules WHERE time_slot_id = ?");
            $stmt->execute([$id]);
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                $error = "Cannot delete time slot because it is used in existing schedules.";
            } else {
                $stmt = $pdo->prepare("DELETE FROM time_slots WHERE id = ?");
                $stmt->execute([$id]);
                $message = "Time slot deleted successfully!";
            }
        } catch (Exception $e) {
            $error = "Error deleting time slot: " . $e->getMessage();
        }
    }
}

// Fetch all time slots
$time_slots = $pdo->query("SELECT * FROM time_slots ORDER BY FIELD(day, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'), start_time")->fetchAll();

// Group by day
$grouped_slots = [];
foreach ($time_slots as $slot) {
    $grouped_slots[$slot['day']][] = $slot;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Time Slots - Academic Scheduling System</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: white;
            margin: 50px auto;
            padding: 20px;
            border-radius: 8px;
            width: 80%;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .close {
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        
        .close:hover {
            color: #666;
        }

        .time-slots-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .day-card {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .day-header {
            background: #667eea;
            color: white;
            padding: 10px;
            font-weight: bold;
            text-align: center;
        }
        
        .slot-list {
            padding: 10px;
        }
        
        .slot-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px;
            border-bottom: 1px solid #dee2e6;
        }
        
        .slot-item:last-child {
            border-bottom: none;
        }
        
        .slot-time {
            font-weight: 500;
        }
        
        .slot-actions {
            display: flex;
            gap: 5px;
        }
        
        .predefined-slots {
            margin-top: 20px;
            padding: 15px;
            background: #e8f4fd;
            border-radius: 5px;
        }
        
        .predefined-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 10px;
        }
        
        .predefined-btn {
            padding: 8px 15px;
            background: white;
            border: 1px solid #17a2b8;
            color: #17a2b8;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .predefined-btn:hover {
            background: #17a2b8;
            color: white;
        }
        
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
        }
        
        .btn-icon:hover {
            transform: translateY(-2px);
        }

        .btn-edit { background-color: #ffc107; color: #212529; }
        .btn-edit:hover { background-color: #e0a800; }
        .btn-delete { background-color: #dc3545; color: #fff; }
        .btn-delete:hover { background-color: #c82333; }
        
        .slot-type-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: bold;
            margin-left: 8px;
        }
        .slot-type-makeup {
            background-color: #ffc107;
            color: #000;
        }
        .slot-type-summer {
            background-color: #17a2b8;
            color: #fff;
        }
        .slot-type-lunch {
            background-color: #28a745;
            color: #fff;
        }
        .slot-item > div {
            display: flex;
            align-items: center;
        }
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
        <h2>Manage Time Slots</h2>
        <?php if ($message): ?>
            <div class="success"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <!-- Add Time Slot Button -->
        <button class="btn-primary" onclick="openModal('addModal')">➕ Add New Time Slot</button>
        
        <!-- Predefined Templates -->
        <div class="predefined-slots">
            <h3>Quick Add Templates</h3>
            <div class="predefined-buttons">
                <button class="predefined-btn" onclick="addPredefinedSlots('morning')">🌅 Morning Slots (7:00 AM - 1:00 PM)</button>
                <button class="predefined-btn" onclick="addPredefinedSlots('afternoon')">☀️ Afternoon Slots (1:00 PM - 5:30 PM)</button>
                <button class="predefined-btn" onclick="addPredefinedSlots('full')">📅 Full Day Schedule (7:00 AM - 5:30 PM)</button>
            </div>
        </div>
        
        <!-- Time Slots Display -->
        <div class="time-slots-grid">
            <?php foreach ($days as $day): ?>
                <?php if (isset($grouped_slots[$day])): ?>
                <div class="day-card">
                    <div class="day-header"><?php echo $day; ?></div>
                    <div class="slot-list">
                        <?php foreach ($grouped_slots[$day] as $slot): ?>
                        <div class="slot-item">
                            <div>
                                <span class="slot-time">
                                    <?php echo date('g:i A', strtotime($slot['start_time'])); ?> - 
                                    <?php echo date('g:i A', strtotime($slot['end_time'])); ?>
                                </span>
                                <?php if (!empty($slot['slot_type']) && $slot['slot_type'] !== 'regular'): ?>
                                <span class="slot-type-badge slot-type-<?php echo htmlspecialchars($slot['slot_type']); ?>">
                                    <?php 
                                    $type_labels = ['makeup' => 'Makeup Class', 'summer' => 'Summer Class', 'lunch' => 'Lunch Break'];
                                    echo $type_labels[$slot['slot_type']] ?? ucfirst($slot['slot_type']); 
                                    ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <div class="slot-actions">
                                <button class="btn-icon btn-edit" onclick="editTimeSlot(<?php echo $slot['id']; ?>)"><i class="fas fa-edit"></i> Edit</button>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this time slot?')">
                                    <input type="hidden" name="slot_id" value="<?php echo $slot['id']; ?>">
                                    <button type="submit" name="delete_time_slot" class="btn-icon btn-delete"><i class="fas fa-trash-alt"></i> Delete</button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- Add Time Slot Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('addModal')">&times;</span>
            <h2>Add New Time Slot</h2>
            <form method="POST">
                <div class="form-group">
                    <label for="day">Day:</label>
                    <select id="day" name="day" required>
                        <?php foreach ($days as $day): ?>
                        <option value="<?php echo $day; ?>"><?php echo $day; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="start_time">Start Time:</label>
                    <input type="time" id="start_time" name="start_time" required>
                </div>
                
                <div class="form-group">
                    <label for="end_time">End Time:</label>
                    <input type="time" id="end_time" name="end_time" required>
                </div>
                
                <div class="form-group">
                    <label for="slot_type">Slot Type:</label>
                    <select id="slot_type" name="slot_type">
                        <option value="regular">Regular Class</option>
                        <option value="makeup">Makeup Class</option>
                        <option value="summer">Summer Class</option>
                        <option value="lunch">Lunch Break</option>
                    </select>
                    <small class="form-hint">For Saturday slots, consider using "Makeup Class" or "Summer Class"</small>
                </div>
                
                <button type="submit" name="add_time_slot" class="btn-primary">Add Time Slot</button>
            </form>
        </div>
    </div>
    
    <!-- Edit Time Slot Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('editModal')">&times;</span>
            <h2>Edit Time Slot</h2>
            <form method="POST" id="editForm">
                <input type="hidden" id="edit_slot_id" name="slot_id">
                
                <div class="form-group">
                    <label for="edit_day">Day:</label>
                    <select id="edit_day" name="day" required>
                        <?php foreach ($days as $day): ?>
                        <option value="<?php echo $day; ?>"><?php echo $day; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="edit_start_time">Start Time:</label>
                    <input type="time" id="edit_start_time" name="start_time" required>
                </div>
                
                <div class="form-group">
                    <label for="edit_end_time">End Time:</label>
                    <input type="time" id="edit_end_time" name="end_time" required>
                </div>
                
                <div class="form-group">
                    <label for="edit_slot_type">Slot Type:</label>
                    <select id="edit_slot_type" name="slot_type">
                        <option value="regular">Regular Class</option>
                        <option value="makeup">Makeup Class</option>
                        <option value="summer">Summer Class</option>
                        <option value="lunch">Lunch Break</option>
                    </select>
                </div>
                
                <button type="submit" name="edit_time_slot" class="btn-primary">Update Time Slot</button>
            </form>
        </div>
    </div>
    
    <script>
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        function editTimeSlot(id) {
            // Fetch time slot data via AJAX
            fetch(`get_time_slot.php?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('edit_slot_id').value = data.id;
                    document.getElementById('edit_day').value = data.day;
                    document.getElementById('edit_start_time').value = data.start_time;
                    document.getElementById('edit_end_time').value = data.end_time;
                    document.getElementById('edit_slot_type').value = data.slot_type || 'regular';
                    openModal('editModal');
                });
        }
        
        // Auto-select slot type for Saturday
        document.getElementById('day').addEventListener('change', function() {
            if (this.value === 'Saturday') {
                document.getElementById('slot_type').value = 'makeup';
            }
        });
        
        document.getElementById('edit_day').addEventListener('change', function() {
            if (this.value === 'Saturday' && document.getElementById('edit_slot_type').value === 'regular') {
                document.getElementById('edit_slot_type').value = 'makeup';
            }
        });
        
        function addPredefinedSlots(type) {
            let slots = [];
            
            if (type === 'morning') {
                slots = [
                    {day: 'Monday', start: '08:00', end: '09:30'},
                    {day: 'Monday', start: '09:45', end: '11:15'},
                    {day: 'Monday', start: '11:30', end: '13:00'},
                    // Repeat for other days...
                ];
            } else if (type === 'afternoon') {
                slots = [
                    {day: 'Monday', start: '14:00', end: '15:30'},
                    {day: 'Monday', start: '15:45', end: '17:15'},
                    // Add more...
                ];
            }
            
            // You would send these to the server via AJAX
            if (confirm(`Add predefined ${type} slots for all days?`)) {
                // Redirect to a script that adds these slots
                window.location.href = `add_predefined_slots.php?type=${type}`;
            }
        }
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>
</html>
