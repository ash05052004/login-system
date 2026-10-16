<?php
// CRON JOB - Run daily
require_once 'config.php';
require_once 'notification_helper.php';

// --- Token protection ---
$cron_token = env('CRON_TOKEN', '');
if ($cron_token === '') {
    http_response_code(500);
    exit('CRON_TOKEN not set');
}
if (($_GET['token'] ?? '') !== $cron_token) {
    http_response_code(403);
    exit('Forbidden');
}

$today        = (int) date('j');
$currentMonth = (int) date('n');
$currentYear  = (int) date('Y');
$daysInMonth  = (int) date('t');
$daysLeft     = $daysInMonth - $today;

echo "Checking payments for " . date('F Y') . "...\n\n";

$stmt = $pdo->query("SELECT * FROM users WHERE role = 'tenant' AND is_deleted = 0");
$tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sent = 0;

foreach ($tenants as $tenant) {

    if (empty($tenant['telegram_chat_id'])) {
        continue;
    }

    // Current month payment record
    $stmt2 = $pdo->prepare(
        "SELECT * FROM payments WHERE user_id = ? AND year = ? AND month = ?"
    );
    $stmt2->execute([$tenant['id'], $currentYear, $currentMonth]);
    $currentPayment = $stmt2->fetch(PDO::FETCH_ASSOC);

    $currentStatus = $currentPayment['status'] ?? 'unpaid';

    // Verified or partial → skip this month
    if ($currentStatus === 'verified' || $currentStatus === 'partial') {
        continue;
    }

    // Monthly amount
    preg_match('/^(A[1-7]|B[1-7])/', $tenant['email'], $m);
    $room_number = $m[1] ?? '';
    $stmt3 = $pdo->prepare("SELECT price_option FROM rooms WHERE room_number = ?");
    $stmt3->execute([$room_number]);
    $room = $stmt3->fetch();

    $thisMonthAmount = ($room ? floatval($room['price_option']) : 1500) + 300 + 500 + 200;

    // Carryover from any previous unpaid month this year
    $stmt4 = $pdo->prepare(
        "SELECT SUM(balance) AS prev_balance
         FROM payments
         WHERE user_id = ?
           AND year = ?
           AND month < ?
           AND balance > 0"
    );
    $stmt4->execute([$tenant['id'], $currentYear, $currentMonth]);
    $prevBalance = (float) ($stmt4->fetchColumn() ?: 0);

    $totalDue = $thisMonthAmount + $prevBalance;

    // ============================================
    // TEST MODE
    // ============================================
    if (isset($_GET['test']) && $_GET['test'] === 'due') {
        notifyPaymentDue($tenant['id'], $totalDue, date('F j, Y'));
        echo "✅ [TEST] Sent DUE ₱{$totalDue} to {$tenant['email']}\n";
        $sent++;
        continue;
    }

    // ============================================
    // PRODUCTION REMINDER SCHEDULE
    // ============================================
    $shouldSend = false;
    $reason = '';

    if ($today < 10) {
        // Start 5 days before due date, then daily
        if ($daysLeft <= 5) {
            $shouldSend = true;
            $reason = 'due-soon';
        }
    } elseif ($today == 10) {
        $shouldSend = true;
        $reason = 'due-today';
    } else {
        // Overdue → every 2 days (11, 13, 15...)
        if (($today - 10) % 2 === 1) {
            $shouldSend = true;
            $reason = 'overdue';
        }
    }

    if ($shouldSend) {
        if ($reason === 'overdue') {
            $daysOverdue = $today - 10;
            notifyPaymentOverdue($tenant['id'], $totalDue, $daysOverdue);
            echo "✅ Sent OVERDUE-{$daysOverdue}d ₱{$totalDue} to {$tenant['email']}\n";
        } else {
            notifyPaymentDue($tenant['id'], $totalDue, date('F j, Y'));
            echo "✅ Sent {$reason} ₱{$totalDue} to {$tenant['email']}\n";
        }
        $sent++;
    }
}

echo "\n✅ Total notifications sent: $sent\n";