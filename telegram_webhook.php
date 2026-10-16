<?php
// Skip ngrok warning
header('ngrok-skip-browser-warning: true');

require_once 'config.php';

// Get the update from Telegram
$update = json_decode(file_get_contents('php://input'), true);

if (!$update) exit;

$message = $update['message'] ?? null;
if (!$message) exit;

$chat_id = $message['chat']['id'];
$text = trim($message['text'] ?? '');

// DEBUG: log every incoming message
file_put_contents(__DIR__ . '/webhook_debug.log',
    date('c') . " chat_id=$chat_id text=[$text]\n", FILE_APPEND);

$token = getenv('TELEGRAM_BOT_TOKEN');
if (!$token) {
    file_put_contents(__DIR__ . '/webhook_debug.log',
        date('c') . " ERROR: TELEGRAM_BOT_TOKEN not set\n", FILE_APPEND);
    exit;
}

// Check if it's a /start command with user ID
if (preg_match('/^\/start\s+USER_(\d+)/', $text, $matches)) {
    $user_id = intval($matches[1]);

    // DEBUG: regex matched
    file_put_contents(__DIR__ . '/webhook_debug.log',
        date('c') . " MATCH user_id=$user_id\n", FILE_APPEND);

    // Save chat_id to user
    $stmt = $pdo->prepare("UPDATE users SET telegram_chat_id = ? WHERE id = ?");
    $stmt->execute([$chat_id, $user_id]);

    // DEBUG: how many rows updated
    file_put_contents(__DIR__ . '/webhook_debug.log',
        date('c') . " UPDATED rows=" . $stmt->rowCount() . "\n", FILE_APPEND);

    // Send confirmation
    $reply = "✅ *Connected Successfully!*\n\n";
    $reply .= "You will now receive notifications from Danielle's Apartment.\n\n";
    $reply .= "📱 You'll get alerts for:\n";
    $reply .= "• 💳 Payment reminders\n";
    $reply .= "• ✅ Payment verification\n";
    $reply .= "• 📅 Appointment updates\n";
    $reply .= "• 💬 New messages";

    $data = [
        'chat_id' => $chat_id,
        'text' => $reply,
        'parse_mode' => 'Markdown'
    ];

    $response = file_get_contents(
        "https://api.telegram.org/bot" . $token . "/sendMessage?" . http_build_query($data)
    );

    // DEBUG: log telegram reply
    file_put_contents(__DIR__ . '/webhook_debug.log',
        date('c') . " TELEGRAM_RESPONSE=" . $response . "\n", FILE_APPEND);
}
?>