<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'owner') {
    header('Location: login.php');
    exit();
}

require_once 'config.php';

// Handle update room price
if (isset($_POST['update_room'])) {
    $room_number = $_POST['room_number'];
    $price_option = $_POST['price_option'];
    
    // Get max occupancy based on price
    $max_occupancy = 4;
    if ($price_option == '5000') $max_occupancy = 1;
    elseif ($price_option == '2500') $max_occupancy = 2;
    elseif ($price_option == '2000') $max_occupancy = 3;
    elseif ($price_option == '1500') $max_occupancy = 4;
    
    $stmt = $pdo->prepare("UPDATE rooms SET price_option = ?, max_occupancy = ? WHERE room_number = ?");
    $stmt->execute([$price_option, $max_occupancy, $room_number]);
    
    $message = "✅ Room $room_number updated!";
}

// Fetch all rooms
$stmt = $pdo->query("SELECT * FROM rooms ORDER BY room_number");
$rooms = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Room Settings</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        /* ===== BACKGROUND IMAGE ===== */
        body {
            background-image: url('images/Background1.jpg') !important;
            background-size: cover !important;
            background-position: center !important;
            background-attachment: fixed !important;
            background-repeat: no-repeat !important;
            margin: 0;
            padding: 0;
            min-height: 100vh;
        }
        
        /* Dark overlay */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 0;
            pointer-events: none;
        }
        
        .navbar, .rooms-container {
            position: relative;
            z-index: 1;
        }
        
        /* Hide video elements */
        .video-background { display: none !important; }
        .overlay { display: none !important; }
        .unmute-btn { display: none !important; }

        /* ===== ROOM SETTINGS STYLES ===== */
        .rooms-container {
            max-width: 1200px;
            margin: 100px auto 50px;
            padding: 30px;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            border-radius: 15px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: white;
        }
        
        .rooms-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin-top: 30px;
        }
        
        .room-card {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 12px;
            padding: 20px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: all 0.3s;
        }
        
        .room-card:hover {
            transform: translateY(-5px);
            border-color: #667eea;
        }
        
        .room-title {
            font-size: 24px;
            font-weight: bold;
            color: #ffd700;
            margin-bottom: 15px;
            text-align: center;
        }
        
        .price-select {
            width: 100%;
            padding: 10px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            color: white;
            margin: 15px 0;
        }
        
        .price-select option {
            background: #1a1a2e;
            color: white;
        }
        
        .occupancy-info {
            text-align: center;
            padding: 8px;
            border-radius: 8px;
            margin: 10px 0;
            font-size: 14px;
        }
        
        .occupancy-1 { background: rgba(220, 53, 69, 0.2); color: #dc3545; }
        .occupancy-2 { background: rgba(255, 193, 7, 0.2); color: #ffc107; }
        .occupancy-3 { background: rgba(23, 162, 184, 0.2); color: #17a2b8; }
        .occupancy-4 { background: rgba(40, 167, 69, 0.2); color: #28a745; }
        
        .save-btn {
            width: 100%;
            padding: 10px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
            transition: all 0.3s;
        }
        
        .save-btn:hover {
            background: #218838;
            transform: translateY(-2px);
        }
        
        .back-btn {
            background: rgba(108, 117, 125, 0.2);
            color: white;
            padding: 10px 20px;
            border-radius: 20px;
            text-decoration: none;
            border: 1px solid #6c757d;
            transition: all 0.3s;
        }
        
        .back-btn:hover {
            background: #6c757d;
            transform: scale(1.05);
        }
        
        .message {
            background: #28a745;
            color: white;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .navbar {
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(8px);
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 100;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .navbar h2 {
            color: white;
            margin: 0;
        }
        
        h1 {
            color: white;
            text-align: center;
        }
        
        @media (max-width: 768px) {
            .rooms-container {
                margin: 80px 10px 20px;
                padding: 15px;
            }
            .rooms-grid {
                grid-template-columns: 1fr;
            }
            .navbar {
                padding: 10px 15px;
                flex-direction: column;
                gap: 10px;
            }
        }
    </style>
</head>
<body>

    <!-- REMOVED: Video Background -->
    <!-- REMOVED: Overlay -->
    <!-- REMOVED: Unmute Button -->

    <div class="navbar">
        <h2>🏠 Room Pricing Settings</h2>
        <a href="owner_dashboard.php" class="back-btn">← Back to Dashboard</a>
    </div>

    <div class="rooms-container">
        <h1>Per-Room Pricing Configuration</h1>
        
        <?php if (isset($message)): ?>
            <div class="message"><?php echo $message; ?></div>
        <?php endif; ?>

        <div class="rooms-grid">
            <?php foreach ($rooms as $room): 
                $occupancy_class = '';
                if ($room['max_occupancy'] == 1) $occupancy_class = 'occupancy-1';
                elseif ($room['max_occupancy'] == 2) $occupancy_class = 'occupancy-2';
                elseif ($room['max_occupancy'] == 3) $occupancy_class = 'occupancy-3';
                else $occupancy_class = 'occupancy-4';
            ?>
                <div class="room-card">
                    <div class="room-title">Room <?php echo $room['room_number']; ?></div>
                    
                    <form method="POST">
                        <input type="hidden" name="room_number" value="<?php echo $room['room_number']; ?>">
                        
                        <select name="price_option" class="price-select" onchange="this.form.submit()">
                            <option value="1500" <?php echo $room['price_option'] == '1500' ? 'selected' : ''; ?>>₱1,500 - Quad (Max 4 persons)</option>
                            <option value="2000" <?php echo $room['price_option'] == '2000' ? 'selected' : ''; ?>>₱2,000 - Triple (Max 3 persons)</option>
                            <option value="2500" <?php echo $room['price_option'] == '2500' ? 'selected' : ''; ?>>₱2,500 - Couple (Max 2 persons)</option>
                            <option value="5000" <?php echo $room['price_option'] == '5000' ? 'selected' : ''; ?>>₱5,000 - Solo (Max 1 person)</option>
                        </select>
                        
                        <div class="occupancy-info <?php echo $occupancy_class; ?>">
                            📌 Max Occupancy: <?php echo $room['max_occupancy']; ?> person(s)
                        </div>
                        
                        <button type="submit" name="update_room" class="save-btn">💾 Save Changes</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- REMOVED: YouTube JavaScript -->
</body>
</html>