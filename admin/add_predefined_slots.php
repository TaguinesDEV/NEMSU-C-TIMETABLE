<?php
require_once '../includes/auth.php';
requireAdmin();

$pdo = getDB();
$type = $_GET['type'] ?? '';

$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

// All times: 7:00 AM - 5:30 PM
$slot_templates = [
    'morning' => [
        ['07:00', '08:30'],
        ['08:30', '10:00'],
        ['10:00', '11:30'],
        ['11:30', '13:00']
    ],
    'afternoon' => [
        ['13:00', '14:30'],
        ['14:30', '16:00'],
        ['16:00', '17:30']
    ],
    'full' => [
        ['07:00', '08:30'],
        ['08:30', '10:00'],
        ['10:00', '11:30'],
        ['11:30', '13:00'],
        ['13:00', '14:30'],
        ['14:30', '16:00'],
        ['16:00', '17:30']
    ]
];

if (!isset($slot_templates[$type])) {
    header('Location: manage_time_slots.php?error=Invalid template type');
    exit();
}

$count = 0;
foreach ($days as $day) {
    foreach ($slot_templates[$type] as $slot) {
        try {
            $slot_type = ($day !== 'Saturday' && $slot[0] === '11:30' && $slot[1] === '13:00') ? 'lunch' : 'regular';
            if ($day === 'Saturday') {
                $slot_type = 'makeup';
            }
            $stmt = $pdo->prepare("INSERT IGNORE INTO time_slots (day, start_time, end_time, slot_type) VALUES (?, ?, ?, ?)");
            $stmt->execute([$day, $slot[0], $slot[1], $slot_type]);
            $count += $stmt->rowCount();
        } catch (Exception $e) {
            // Skip duplicates
        }
    }
}

header("Location: manage_time_slots.php?message=Added $count predefined time slots");
exit();
?>
