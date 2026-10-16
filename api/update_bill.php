<?php
require_once '../config.php';

header('Content-Type: application/json');

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'Method not allowed']));
}

// Get params
$api_key = $_POST['api_key'] ?? '';
$room = $_POST['room'] ?? '';
$type = $_POST['type'] ?? '';      // 'water' or 'electric' or 'wifi'
$reading = floatval($_POST['reading'] ?? 0);  // Meter reading
$amount = floatval($_POST['amount'] ?? 0);    // Computed amount in pesos

if (empty($api_key) || empty($room) || empty($type)) {
    http_response_code(400);
    exit(json_encode(['error' => 'Missing parameters']));
}

// Validate API key
$stmt = $pdo->prepare("SELECT * FROM arduino_api_keys WHERE api_key = ? AND is_active = 1");
$stmt->execute([$api_key]);
$key_data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$key_data) {
    http_response_code(401);
    exit(json_encode(['error' => 'Invalid API key']));
}

// Update last_used
$stmt = $pdo->prepare("UPDATE arduino_api_keys SET last_used = NOW() WHERE id = ?");
$stmt->execute([$key_data['id']]);

// Validate type
if (!in_array($type, ['water', 'electric', 'wifi'])) {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid type. Use: water, electric, or wifi']));
}

// Get current year and month
$year = intval($_POST['year'] ?? date('Y'));
$month = intval($_POST['month'] ?? date('n'));

// Get all tenants in the room
$stmt = $pdo->prepare("SELECT id FROM users WHERE room_number = ? AND role = 'tenant' AND is_deleted = 0");
$stmt->execute([$room]);
$tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($tenants)) {
    http_response_code(404);
    exit(json_encode(['error' => 'No tenants in room ' . $room]));
}

// Divide amount among tenants
$amount_per_tenant = $amount / count($tenants);

foreach ($tenants as $tenant) {
    // Check if monthly_bills record exists
    $stmt = $pdo->prepare("SELECT id FROM monthly_bills WHERE user_id = ? AND year = ? AND month = ?");
    $stmt->execute([$tenant['id'], $year, $month]);
    $existing = $stmt->fetch();

    if ($existing) {
        // ADD mode — accumulate values
        if ($type == 'water') {
            $stmt = $pdo->prepare("UPDATE monthly_bills 
                SET water_amount = water_amount + ?, 
                    water_reading = water_reading + ?, 
                    source = 'arduino', 
                    reading_date = CURDATE() 
                WHERE id = ?");
            $stmt->execute([$amount_per_tenant, $reading, $existing['id']]);
        } elseif ($type == 'electric') {
            $stmt = $pdo->prepare("UPDATE monthly_bills 
                SET electric_amount = electric_amount + ?, 
                    electric_reading = electric_reading + ?, 
                    source = 'arduino', 
                    reading_date = CURDATE() 
                WHERE id = ?");
            $stmt->execute([$amount_per_tenant, $reading, $existing['id']]);
        } elseif ($type == 'wifi') {
            $stmt = $pdo->prepare("UPDATE monthly_bills 
                SET wifi_amount = ?, 
                    source = 'arduino', 
                    reading_date = CURDATE() 
                WHERE id = ?");
            $stmt->execute([$amount_per_tenant, $existing['id']]);
        }
    } else {
        // Insert new record
        $stmt = $pdo->prepare("INSERT INTO monthly_bills 
            (user_id, room_number, year, month, water_amount, electric_amount, wifi_amount, water_reading, electric_reading, reading_date, source) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 'arduino')");
        
        $water = ($type == 'water') ? $amount_per_tenant : 0;
        $electric = ($type == 'electric') ? $amount_per_tenant : 0;
        $wifi = ($type == 'wifi') ? $amount_per_tenant : 200;
        $water_read = ($type == 'water') ? $reading : 0;
        $electric_read = ($type == 'electric') ? $reading : 0;
        
        $stmt->execute([$tenant['id'], $room, $year, $month, $water, $electric, $wifi, $water_read, $electric_read]);
    }
}

echo json_encode([
    'success' => true,
    'room' => $room,
    'type' => $type,
    'reading' => $reading,
    'amount' => $amount,
    'tenants_updated' => count($tenants)
]);
?>