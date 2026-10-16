<?php
// CRON JOB - Run daily at 9:00 AM
require_once 'config.php';
require_once 'notification_helper.php';

$today = date('j');
$currentMonth = date('n');
$currentYear = date('Y');
$daysInMonth = date('t');
$daysLeft = $daysInMonth - $today;

echo "Checking payments for " . date('F Y') . "...\n\n";

// Get all active tenants
$stmt = $pdo->query("SELECT * FROM users WHERE role = 'tenant' AND is_deleted = 0");
$tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sent = 0;

foreach ($tenants as $tenant) {
    // Skip if no telegram
    if (empty($tenant['telegram_chat_id'])) {
        continue;
    }
    
    // Check if already paid this month
    $stmt2 = $pdo->prepare("SELECT * FROM payments WHERE user_id = ? AND year = ? AND month = ? AND status = 'verified'");
    $stmt2->execute([$tenant['id'], $currentYear, $currentMonth]);
    $paid = $stmt2->fetch();
    
    if ($paid) continue; // Already paid
    
    // Calculate amount
    preg_match('/^(A[1-7]|B[1-7])/', $tenant['email'], $m);
    $room_number = $m[1] ?? '';
    $stmt3 = $pdo->prepare("SELECT price_option FROM rooms WHERE room_number = ?");
    $stmt3->execute([$room_number]);
    $room = $stmt3->fetch();
    $amount = ($room ? floatval($room['price_option']) : 1500) + 300 + 500 + 200;
    
    // ============================================
    // TEST MODE: Change these values to test NOW
    // ============================================
    // For production, use real dates (below)
    // For testing, uncomment the test line
    
    // TEST: Send due notification NOW (for testing)
    if (isset($_GET['test']) && $_GET['test'] == 'due') {
        notifyPaymentDue($tenant['id'], $amount, date('F j, Y'));
        echo "✅ [TEST] Sent DUE to {$tenant['email']}\n";
        $sent++;
        continue;
    }
    
    // PRODUCTION: Real date checks
    // Due 5 days before end of month
    if ($daysLeft == 5) {
        notifyPaymentDue($tenant['id'], $amount, date('F j, Y', strtotime("+5 days")));
        echo "✅ Sent DUE-5 to {$tenant['email']}\n";
        $sent++;
    }
    
    // Due TODAY (10th of month)
    if ($today == 10) {
        notifyPaymentDue($tenant['id'], $amount, date('F j, Y'));
        echo "✅ Sent DUE-TODAY to {$tenant['email']}\n";
        $sent++;
    }
    
    // Overdue (after 10th)
    if ($today > 10) {
        $daysOverdue = $today - 10;
        
        // Send every 3 days
        if ($daysOverdue % 3 == 0) {
            notifyPaymentOverdue($tenant['id'], $amount, $daysOverdue);
            echo "✅ Sent OVERDUE-{$daysOverdue}d to {$tenant['email']}\n";
            $sent++;
        }
    }
}

echo "\n✅ Total notifications sent: $sent\n";
?>