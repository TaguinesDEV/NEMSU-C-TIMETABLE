<?php
require_once '../includes/auth.php';
requireAdmin();

$pdo = getDB();
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    header('Location: manage_instructors.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT i.*, u.full_name, u.email, u.username
    FROM instructors i
    JOIN users u ON i.user_id = u.id
    WHERE i.id = ?
");
$stmt->execute([$id]);
$instructor = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$instructor) {
    die("Instructor not found");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($instructor['full_name']); ?> - Profile | Academic Scheduling</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .profile-container {
            max-width: 500px;
            margin: 40px auto;
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .profile-header {
            text-align: center;
            padding: 40px 30px 30px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .profile-photo {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 5px solid white;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            margin: 0 auto 20px;
            display: block;
        }
        .profile-name {
            font-size: 28px;
            font-weight: 700;
            margin: 0 0 5px 0;
        }
        .profile-rank {
            font-size: 18px;
            opacity: 0.9;
            margin: 0;
        }
        .profile-info {
            padding: 30px;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 15px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .info-label {
            font-weight: 600;
            color: #64748b;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .info-value {
            font-weight: 500;
            color: #1e293b;
            font-size: 16px;
        }
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 20px;
            padding: 12px 24px;
            background: #f8fafc;
            color: #334155;
            text-decoration: none;
            border-radius: 10px;
            border: 2px solid #e2e8f0;
            transition: all 0.2s;
            font-weight: 600;
        }
        .back-btn:hover {
            background: #e2e8f0;
            transform: translateY(-1px);
        }
        @media (max-width: 600px) {
            .profile-container {
                margin: 20px;
                border-radius: 12px;
            }
            .profile-info {
                padding: 20px;
            }
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
                <div class="header-inline">
                    <a href="manage_instructors.php" class="page-link"><i class="fas fa-arrow-left"></i> Instructors</a>
                    <span class="sep"> | </span>
                    <a href="../logout.php" class="btn-logout">Logout</a>
                </div>
            </div>
        </div>
    </div>
    
    <div class="container">
        <div class="profile-container">
            <div class="profile-header">
                <img src="<?php echo htmlspecialchars($instructor['photo'] ?? '../assets/logo.png'); ?>" alt="Profile" class="profile-photo" onerror="this.src='../assets/logo.png'">
                <h1 class="profile-name"><?php echo htmlspecialchars($instructor['full_name']); ?></h1>
                <p class="profile-rank"><?php echo htmlspecialchars($instructor['rank'] ?? 'Instructor'); ?></p>
            </div>
            
            <div class="profile-info">
                <div class="info-row">
                    <span class="info-label">Designation</span>
                    <span class="info-value"><?php echo htmlspecialchars($instructor['designation'] ?: 'Not specified'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Educational Background</span>
                    <span class="info-value"><?php echo htmlspecialchars($instructor['education'] ?? 'Not specified'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Eligibility</span>
                    <span class="info-value"><?php echo htmlspecialchars($instructor['eligibility'] ?? 'Not specified'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Length of Service</span>
                    <span class="info-value"><?php echo htmlspecialchars($instructor['service_years'] ?? 'Not specified'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Status</span>
                    <span class="info-value"><?php echo htmlspecialchars($instructor['status'] ?? 'Not specified'); ?></span>
                </div>
                
            </div>
        </div>
    </div>
</body>
</xai:function_call >

<attempt_completion>
<parameter name="result">

</html>
