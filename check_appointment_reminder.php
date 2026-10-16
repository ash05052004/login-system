<?php
// CRON JOB - Run daily at 9:00 AM
require_once 'config.php';
require_once 'notification_helper.php';

$tomorrow = date('Y-m-d', strtotime('+1 day'));

echo "Checking appointments for tomorrow (" . $tomorrow . ")...\n\n";

// Get appointments for tomorrow
$stmt = $pdo->prepare("
    SELECT a.*, u.email, u.telegram_chat_id 
    FROM appointments a 
    JOIN users u ON a.user_id = u.id 
    WHERE a.visit_date = ? 
    AND a.status = 'confirmed'
    AND u.telegram_chat_id IS NOT NULL
");
$stmt->execute([$tomorrow]);
$appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sent = 0;

foreach ($appointments as $apt) {
    $date = date('F j, Y', strtotime($apt['visit_date']));
    $time = date('g:i A', strtotime($apt['visit_time']));
    
    notifyAppointmentReminder($apt['user_id'], $date, $time);
    echo "✅ Reminder sent to {$apt['email']}\n";
    $sent++;
}

echo "\n✅ Total reminders sent: $sent\n";
?>