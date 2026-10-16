<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'owner') {
    http_response_code(403);
    exit(json_encode(['error' => 'Unauthorized']));
}

require_once 'config.php';

$user_id = intval($_GET['user_id'] ?? 0);
$year = intval($_GET['year'] ?? date('Y'));
$month = intval($_GET['month'] ?? date('n'));

if ($user_id <= 0) {
    exit(json_encode(['error' => 'Invalid user']));
}

// Get tenant data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$tenant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tenant) {
    exit(json_encode(['error' => 'Tenant not found']));
}

// Get rent from ROOM's price_option
$rent = 1500;
if (preg_match('/^(A[1-7]|B[1-7])/', $tenant['email'], $matches)) {
    $room_number = $matches[1];
    $stmt = $pdo->prepare("SELECT price_option FROM rooms WHERE room_number = ?");
    $stmt->execute([$room_number]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($room) {
        $rent = floatval($room['price_option']);
    }
}

$water = $tenant['water_bill'] ?: 300;
$electric = $tenant['electric_bill'] ?: 500;
$wifi = $tenant['wifi_bill'] ?: 200;
$source = 'default';
$reading_date = null;
$receipt_image = null;

// Check if monthly_bills has specific data for this month
$stmt = $pdo->prepare("SELECT * FROM monthly_bills WHERE user_id = ? AND year = ? AND month = ?");
$stmt->execute([$user_id, $year, $month]);
$monthly = $stmt->fetch(PDO::FETCH_ASSOC);

if ($monthly) {
    $water = $monthly['water_amount'] ?: $water;
    $electric = $monthly['electric_amount'] ?: $electric;
    $wifi = $monthly['wifi_amount'] ?: $wifi;
    $source = $monthly['source'];
    $reading_date = $monthly['reading_date'];
}

// Get payment details for current month
$amount_paid = 0;
$amount_due = 0;
$balance = 0;
$is_partial = 0;
$status = 'pending';

$stmt = $pdo->prepare("SELECT receipt_image, amount_paid, amount_due, balance, is_partial, status FROM payments WHERE user_id = ? AND year = ? AND month = ?");
$stmt->execute([$user_id, $year, $month]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

if ($payment) {
    if (!empty($payment['receipt_image'])) {
        $receipt_image = $payment['receipt_image'];
    }
    $amount_paid = floatval($payment['amount_paid'] ?? 0);
    $amount_due = floatval($payment['amount_due'] ?? 0);
    $balance = floatval($payment['balance'] ?? 0);
    $is_partial = intval($payment['is_partial'] ?? 0);
    $status = $payment['status'] ?? 'pending';
}

// ===== PREVIOUS MONTH BALANCE =====
$prev_month = $month - 1;
$prev_year = $year;
if ($prev_month == 0) { 
    $prev_month = 12; 
    $prev_year--; 
}

$previous_balance = 0;
$stmt = $pdo->prepare("SELECT balance FROM payments WHERE user_id = ? AND year = ? AND month = ? AND balance > 0");
$stmt->execute([$user_id, $prev_year, $prev_month]);
$prev_payment = $stmt->fetch(PDO::FETCH_ASSOC);

if ($prev_payment) {
    $previous_balance = floatval($prev_payment['balance']);
}

// Calculate total
$current_total = $rent + $water + $electric + $wifi;
$total = $current_total + $previous_balance;

// If amount_due is 0 (not set), use total
if ($amount_due == 0) {
    $amount_due = $total;
}

echo json_encode([
    'rent' => floatval($rent),
    'water' => floatval($water),
    'electric' => floatval($electric),
    'wifi' => floatval($wifi),
    'current_total' => floatval($current_total),
    'previous_balance' => floatval($previous_balance),
    'total' => floatval($total),
    'source' => $source,
    'reading_date' => $reading_date,
    'receipt_image' => $receipt_image,
    'amount_paid' => $amount_paid,
    'amount_due' => $amount_due,
    'balance' => $balance,
    'is_partial' => $is_partial,
    'status' => $status
]);
?>