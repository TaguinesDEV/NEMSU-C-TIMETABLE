<?php
require_once '../includes/auth.php';
requireAdmin();

$pdo = getDB();
$message = '';
$error = '';

// Handle Add/Edit/Delete operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_room'])) {
        $room_number = $_POST['room_number'];
        $capacity = $_POST['capacity'];
        $building = $_POST['building'];
        $has_projector = isset($_POST['has_projector']) ? 1 : 0;
        $has_computers = isset($_POST['has_computers']) ? 1 : 0;
        
        try {
            $stmt = $pdo->prepare("INSERT INTO rooms (room_number, capacity, building, has_projector, has_computers) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$room_number, $capacity, $building, $has_projector, $has_computers]);
            $message = "Room added successfully!";
        } catch (Exception $e) {
            $error = "Error adding room: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['edit_room'])) {
        $id = $_POST['room_id'];
        $room_number = $_POST['room_number'];
        $capacity = $_POST['capacity'];
        $building = $_POST['building'];
        $has_projector = isset($_POST['has_projector']) ? 1 : 0;
        $has_computers = isset($_POST['has_computers']) ? 1 : 0;
        
        try {
            $stmt = $pdo->prepare("UPDATE rooms SET room_number = ?, capacity = ?, building = ?, has_projector = ?, has_computers = ? WHERE id = ?");
            $stmt->execute([$room_number, $capacity, $building, $has_projector, $has_computers, $id]);
            $message = "Room updated successfully!";
        } catch (Exception $e) {
            $error = "Error updating room: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['delete_room'])) {
        $id = $_POST['room_id'];
        
        try {
            // Check if room is used in schedules
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM schedules WHERE room_id = ?");
            $stmt->execute([$id]);
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                $error = "Cannot delete room because it is used in existing schedules.";
            } else {
                $stmt = $pdo->prepare("DELETE FROM rooms WHERE id = ?");
                $stmt->execute([$id]);
                $message = "Room deleted successfully!";
            }
        } catch (Exception $e) {
            $error = "Error deleting room: " . $e->getMessage();
        }
    }
}

// Fetch all rooms
$rooms = $pdo->query("SELECT * FROM rooms ORDER BY building, room_number")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Rooms - Academic Scheduling System</title>
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

        .room-facilities {
            display: flex;
            gap: 10px;
            justify-content: center;
        }
        
        .facility-badge {
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: bold;
        }
        
        .facility-projector {
            background: #cce5ff;
            color: #004085;
        }
        
        .facility-computers {
            background: #d4edda;
            color: #155724;
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
        <h2>Manage Rooms</h2>
        <?php if ($message): ?>
            <div class="success"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <!-- Add Room Button -->
        <button class="btn-primary" onclick="openModal('addModal')">➕ Add New Room</button>
        
        <!-- Rooms Table -->
        <table class="data-table">
            <thead>
                <tr>
                    <th>Room Number</th>
                    <th>Building</th>
                    <th>Capacity</th>
                    <th>Facilities</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rooms as $room): ?>
                <tr>
                    <td><?php echo htmlspecialchars($room['room_number']); ?></td>
                    <td><?php echo htmlspecialchars($room['building']); ?></td>
                    <td><?php echo $room['capacity']; ?></td>
                    <td>
                        <div class="room-facilities">
                            <?php if ($room['has_projector']): ?>
                                <span class="facility-badge facility-projector">📽️ Projector</span>
                            <?php endif; ?>
                            <?php if ($room['has_computers']): ?>
                                <span class="facility-badge facility-computers">💻 Computers</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <button class="btn-icon btn-edit" onclick="editRoom(<?php echo $room['id']; ?>)"><i class="fas fa-edit"></i> Edit</button>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this room?')">
                            <input type="hidden" name="room_id" value="<?php echo $room['id']; ?>">
                            <button type="submit" name="delete_room" class="btn-icon btn-delete"><i class="fas fa-trash-alt"></i> Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Add Room Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('addModal')">&times;</span>
            <h2>Add New Room</h2>
            <form method="POST">
                <div class="form-group">
                    <label for="room_number">Room Number:</label>
                    <input type="text" id="room_number" name="room_number" required placeholder="e.g., A101">
                </div>
                
                <div class="form-group">
                    <label for="building">Building:</label>
                    <input type="text" id="building" name="building" required placeholder="e.g., Main Building">
                </div>
                
                <div class="form-group">
                    <label for="capacity">Capacity:</label>
                    <input type="number" id="capacity" name="capacity" min="1" max="500" required>
                </div>
                
                <div class="form-group checkbox">
                    <label>
                        <input type="checkbox" name="has_projector" value="1"> Has Projector
                    </label>
                </div>
                
                <div class="form-group checkbox">
                    <label>
                        <input type="checkbox" name="has_computers" value="1"> Has Computers
                    </label>
                </div>
                
                <button type="submit" name="add_room" class="btn-primary">Add Room</button>
            </form>
        </div>
    </div>
    
    <!-- Edit Room Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('editModal')">&times;</span>
            <h2>Edit Room</h2>
            <form method="POST" id="editForm">
                <input type="hidden" id="edit_room_id" name="room_id">
                
                <div class="form-group">
                    <label for="edit_room_number">Room Number:</label>
                    <input type="text" id="edit_room_number" name="room_number" required>
                </div>
                
                <div class="form-group">
                    <label for="edit_building">Building:</label>
                    <input type="text" id="edit_building" name="building" required>
                </div>
                
                <div class="form-group">
                    <label for="edit_capacity">Capacity:</label>
                    <input type="number" id="edit_capacity" name="capacity" min="1" max="500" required>
                </div>
                
                <div class="form-group checkbox">
                    <label>
                        <input type="checkbox" id="edit_has_projector" name="has_projector" value="1"> Has Projector
                    </label>
                </div>
                
                <div class="form-group checkbox">
                    <label>
                        <input type="checkbox" id="edit_has_computers" name="has_computers" value="1"> Has Computers
                    </label>
                </div>
                
                <button type="submit" name="edit_room" class="btn-primary">Update Room</button>
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
        
        function editRoom(id) {
            // Fetch room data via AJAX
            fetch(`get_room.php?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('edit_room_id').value = data.id;
                    document.getElementById('edit_room_number').value = data.room_number;
                    document.getElementById('edit_building').value = data.building;
                    document.getElementById('edit_capacity').value = data.capacity;
                    document.getElementById('edit_has_projector').checked = data.has_projector == 1;
                    document.getElementById('edit_has_computers').checked = data.has_computers == 1;
                    openModal('editModal');
                });
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
