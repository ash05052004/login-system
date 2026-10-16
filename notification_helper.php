<?php
require_once 'config.php';

// ============================================
// SEND NOTIFICATION VIA TELEGRAM
// ============================================
function sendTelegram($user_id, $title, $message, $url = '') {
    global $pdo;
    
    // Get user's chat_id
    $stmt = $pdo->prepare("SELECT telegram_chat_id FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user || empty($user['telegram_chat_id'])) {
        return false; // User not connected
    }
    
    $token = getenv('TELEGRAM_BOT_TOKEN');
    if (!$token) {
        error_log('TELEGRAM_BOT_TOKEN missing from .env');
        return false;
    }
    
    $chat_id = $user['telegram_chat_id'];
    $text = "🔔 *$title*\n\n$message";
    if ($url) {
        $text .= "\n\n🔗 [Open Dashboard]($url)";
    }
    
    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'Markdown',
        'disable_web_page_preview' => true
    ];
    
    $ch = curl_init("https://api.telegram.org/bot" . $token . "/sendMessage");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $result = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($result, true)['ok'] ?? false;
}

// ============================================
// PAYMENT NOTIFICATIONS
// ============================================

function notifyPaymentDue($tenant_id, $amount, $due_date) {
    return sendTelegram(
        $tenant_id,
        '💳 Payment Due',
        "Your rent payment of ₱" . number_format($amount, 2) . " is due on $due_date.\n\nPlease pay on time to avoid penalties."
    );
}

function notifyPaymentOverdue($tenant_id, $amount, $days_overdue) {
    return sendTelegram(
        $tenant_id,
        '⚠️ Payment OVERDUE',
        "Your rent payment of ₱" . number_format($amount, 2) . " is $days_overdue day(s) overdue!\n\nPlease pay immediately."
    );
}

function notifyPaymentVerified($tenant_id, $amount, $month_name) {
    return sendTelegram(
        $tenant_id,
        '✅ Payment Verified',
        "Your payment of ₱" . number_format($amount, 2) . " for $month_name has been verified.\n\nThank you!"
    );
}

function notifyPaymentUnverified($tenant_id, $month_name) {
    return sendTelegram(
        $tenant_id,
        '⏳ Payment Pending',
        "Your payment for $month_name is now pending again.\n\nPlease contact the owner."
    );
}

function notifyReceiptUploaded($owner_id, $tenant_email, $amount, $month_name) {
    return sendTelegram(
        $owner_id,
        '💰 Payment Received',
        "Tenant: $tenant_email\nAmount: ₱" . number_format($amount, 2) . "\nMonth: $month_name\n\nPlease verify in dashboard."
    );
}

// ============================================
// PARTIAL PAYMENT NOTIFICATION
// ============================================
function notifyPartialPayment($tenant_id, $amount_paid, $balance, $month_name) {
    return sendTelegram(
        $tenant_id,
        '📩 Partial Payment Received',
        "We received ₱" . number_format($amount_paid, 2) . " for $month_name.\n\n" .
        "Remaining balance: ₱" . number_format($balance, 2) . "\n\n" .
        "Please settle the balance on or before the next due date."
    );
}

// ============================================
// APPOINTMENT NOTIFICATIONS
// ============================================

function notifyNewAppointment($owner_id, $tenant_name, $date, $time, $occupancy) {
    return sendTelegram(
        $owner_id,
        '📅 New Appointment',
        "Tenant: $tenant_name\nDate: $date\nTime: $time\nRoom: $occupancy person(s)\n\nApprove or reject in dashboard."
    );
}

function notifyAppointmentApproved($tenant_id, $date, $time) {
    return sendTelegram(
        $tenant_id,
        '✅ Appointment Approved',
        "Your appointment on $date at $time has been APPROVED.\n\nPlease arrive on time."
    );
}

function notifyAppointmentRejected($tenant_id, $date) {
    return sendTelegram(
        $tenant_id,
        '❌ Appointment Rejected',
        "Your appointment on $date has been rejected.\n\nPlease schedule a new one."
    );
}

function notifyAppointmentReminder($tenant_id, $date, $time) {
    return sendTelegram(
        $tenant_id,
        '🔔 Appointment Tomorrow',
        "Reminder: You have an appointment tomorrow at $time.\n\nLocation: Kalawag 2, Isulan"
    );
}

// ============================================
// MESSAGE NOTIFICATIONS
// ============================================

function notifyNewMessage($receiver_id, $sender_name, $subject) {
    return sendTelegram(
        $receiver_id,
        '💬 New Message',
        "New message from $sender_name\n\nSubject: $subject"
    );
}

function notifyComplaintReply($tenant_id) {
    return sendTelegram(
        $tenant_id,
        '📝 Complaint Reply',
        "The owner has replied to your complaint.\n\nCheck your dashboard."
    );
}

function notifyNewComplaint($owner_id, $tenant_email, $subject) {
    return sendTelegram(
        $owner_id,
        '📝 New Complaint',
        "From: $tenant_email\nSubject: $subject\n\nCheck dashboard to respond."
    );
}

// ============================================
// GET OWNER ID
// ============================================
function getOwnerId() {
    global $pdo;
    $stmt = $pdo->query("SELECT id FROM users WHERE role = 'owner' LIMIT 1");
    $owner = $stmt->fetch(PDO::FETCH_ASSOC);
    return $owner ? $owner['id'] : null;
}
?>