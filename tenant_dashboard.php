<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'tenant') {
    header('Location: login.php');
    exit();
}

require_once 'config.php';

$user_id = $_SESSION['user_id'];
$current_year = isset($_GET['year']) ? intval($_GET['year']) : 2026;

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$tenant = $stmt->fetch(PDO::FETCH_ASSOC);

$tenant_created_month = date('n', strtotime($tenant['created_at']));
$tenant_created_year = date('Y', strtotime($tenant['created_at']));

preg_match('/^(A[1-7]|B[1-7])/', $tenant['email'], $matches);
$room = $matches[1] ?? 'Unknown';

$rent_amount = 1500;
if ($room !== 'Unknown') {
    $stmt = $pdo->prepare("SELECT price_option FROM rooms WHERE room_number = ?");
    $stmt->execute([$room]);
    $room_data = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($room_data) {
        $rent_amount = floatval($room_data['price_option']);
    }
}

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE email LIKE ? AND is_deleted = 0");
$stmt->execute([$room . '%']);
$occupants = $stmt->fetch()['count'];

$payments = [];
$stmt = $pdo->prepare("SELECT * FROM payments WHERE user_id = ? AND year = ? ORDER BY month ASC");
$stmt->execute([$user_id, $current_year]);
$paymentData = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($paymentData as $payment) {
    $payments[$payment['month']] = $payment;
}

$currentMonth = date('n');
$currentYear = date('Y');
$current_month_name = date('F');

$total_amount = $rent_amount + ($tenant['water_bill'] ?: 300) + ($tenant['electric_bill'] ?: 500) + ($tenant['wifi_bill'] ?: 200);

function getStatusClass($status) {
    switch($status) {
        case 'verified': return 'status-verified';
        case 'pending': return 'status-pending';
        case 'overdue': return 'status-overdue';
        default: return 'status-empty';
    }
}

function getStatusText($status) {
    switch($status) {
        case 'verified': return '✅ Paid';
        case 'pending': return '⏳ Pending';
        case 'overdue': return '⚠️ Overdue';
        default: return '🔵 Empty';
    }
}

if (isset($_POST['upload_receipt'])) {
    $month = intval($_POST['month']);
    $year = intval($_POST['year']);

    $checkStmt = $pdo->prepare("SELECT status FROM payments WHERE user_id = ? AND year = ? AND month = ?");
    $checkStmt->execute([$user_id, $year, $month]);
    $existing = $checkStmt->fetch();

    if ($existing && $existing['status'] == 'verified') {
        header("Location: tenant_dashboard.php?year=$current_year&msg=locked");
        exit();
    }

    if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] == 0) {
        $file_extension = strtolower(pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION));

        if ($file_extension !== 'png') {
            header("Location: tenant_dashboard.php?year=$current_year&msg=invalid_format");
            exit();
        }

        $filename = 'receipt_' . $user_id . '_' . $year . '_' . $month . '_' . time() . '.png';
        $supabase_url = rtrim(env('SUPABASE_URL', ''), '/');
        $supabase_key = env('SUPABASE_SERVICE_KEY', '');

        if ($supabase_url === '' || $supabase_key === '') {
            header("Location: tenant_dashboard.php?year=$current_year&msg=storage_error");
            exit();
        }

        $file_data = file_get_contents($_FILES['receipt']['tmp_name']);

        $ch = curl_init($supabase_url . '/storage/v1/object/receipts/' . $filename);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $file_data,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $supabase_key,
                'Content-Type: image/png',
                'x-upsert: true',
            ],
        ]);
        $upload_response = curl_exec($ch);
        $upload_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($upload_code < 200 || $upload_code >= 300) {
            error_log("Supabase upload failed ($upload_code): " . $upload_response);
            header("Location: tenant_dashboard.php?year=$current_year&msg=storage_error");
            exit();
        }

        $receipt_image = $supabase_url . '/storage/v1/object/public/receipts/' . $filename;
    } else {
        $receipt_image = null;
    }

    $stmt = $pdo->prepare("INSERT INTO payments (user_id, year, month, status, receipt_image) 
                           VALUES (?, ?, ?, 'pending', ?)
                           ON DUPLICATE KEY UPDATE status = 'pending', receipt_image = ?");
    $stmt->execute([$user_id, $year, $month, $receipt_image, $receipt_image]);

    require_once 'notification_helper.php';
    $owner_stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'owner' LIMIT 1");
    $owner_stmt->execute();
    $owner_row = $owner_stmt->fetch();
    if ($owner_row) {
        $monthName = date('F', mktime(0, 0, 0, $month, 1));
        notifyReceiptUploaded($owner_row['id'], $tenant['email'], $total_amount, "$monthName $year");
    }

    header("Location: tenant_dashboard.php?year=$current_year&msg=uploaded");
    exit();
}

if (isset($_POST['send_message'])) {
    $message = trim($_POST['message']);
    $subject = 'Message from Tenant';
    $sender_id = $_SESSION['user_id'];

    $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'owner' LIMIT 1");
    $stmt->execute();
    $owner = $stmt->fetch();
    $receiver_id = $owner ? $owner['id'] : 1;

    $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, sender_role, message_type, subject, message) 
                           VALUES (?, ?, 'tenant', 'message', ?, ?)");
    $stmt->execute([$sender_id, $receiver_id, $subject, $message]);

    require_once 'notification_helper.php';
    notifyNewMessage($receiver_id, $_SESSION['username'], $subject);

    header("Location: tenant_dashboard.php?year=$current_year&msg=message_sent");
    exit();
}

if (isset($_POST['file_complaint'])) {
    $complaint = trim($_POST['complaint']);
    $subject = 'Complaint: ' . substr($complaint, 0, 50);
    $sender_id = $_SESSION['user_id'];

    $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'owner' LIMIT 1");
    $stmt->execute();
    $owner = $stmt->fetch();
    $receiver_id = $owner ? $owner['id'] : 1;

    $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, sender_role, message_type, subject, message) 
                           VALUES (?, ?, 'tenant', 'complaint', ?, ?)");
    $stmt->execute([$sender_id, $receiver_id, $subject, $complaint]);

    require_once 'notification_helper.php';
    notifyNewComplaint($receiver_id, $_SESSION['username'], $subject);

    header("Location: tenant_dashboard.php?year=$current_year&msg=complaint_filed");
    exit();
}

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM messages WHERE receiver_id = ? AND is_read = 0");
$stmt->execute([$_SESSION['user_id']]);
$unread_count = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT m.*, u.username as sender_name 
                       FROM messages m 
                       JOIN users u ON m.sender_id = u.id 
                       WHERE m.receiver_id = ? OR m.sender_id = ?
                       ORDER BY m.created_at DESC LIMIT 20");
$stmt->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
$tenant_messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

$message = '';
if (isset($_GET['msg'])) {
    if ($_GET['msg'] == 'uploaded') $message = '✅ Receipt uploaded successfully';
    if ($_GET['msg'] == 'message_sent') $message = '✅ Message sent to owner';
    if ($_GET['msg'] == 'complaint_filed') $message = '✅ Complaint filed successfully';
    if ($_GET['msg'] == 'locked') $message = '🔒 This month is verified and cannot be changed';
    if ($_GET['msg'] == 'invalid_format') $message = '❌ Only PNG files are allowed';
    if ($_GET['msg'] == 'storage_error') $message = '❌ Upload failed. Please try again.';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tenant Dashboard — Dannielle's Apartment</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Josefin+Sans:wght@300;400;500;600;700&family=Karla:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
  --bg: #F6F1E6;
  --bg-card: #FBF8F2;
  --ink: #2A2118;
  --ink-soft: #6B5D4C;
  --brass: #6B4F2A;
  --brass-light: #C9A876;
  --clay: #8B4A2B;
  --cream: #EDE3D1;
  --line: rgba(42,33,24,0.14);
  --font-display: 'Josefin Sans', sans-serif;
  --font-body: 'Karla', sans-serif;
}
* { box-sizing: border-box; }
body {
  margin: 0;
  font-family: var(--font-body);
  color: var(--ink);
  background:
    linear-gradient(180deg, rgba(14,14,14,0.65) 0%, rgba(14,14,14,0.85) 100%),
    url("Images/Hall.jpg") center / cover no-repeat fixed;
  min-height: 100vh;
  padding: 24px 20px 60px;
}
.video-background, .overlay, .unmute-btn { display: none !important; }

.navbar {
  max-width: 1400px;
  margin: 0 auto 24px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
  flex-wrap: wrap;
}
.navbar h2 {
  font-family: var(--font-display);
  font-weight: 600;
  font-size: 0.95rem;
  letter-spacing: 0.18em;
  color: var(--cream);
  margin: 0;
  white-space: nowrap;
}
.nav-actions {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}
.nav-actions a {
  font-family: var(--font-display);
  font-size: 0.68rem;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  padding: 9px 16px;
  border-radius: 2px;
  text-decoration: none;
  color: var(--cream);
  border: 1px solid rgba(237,227,209,0.35);
  background: transparent;
  transition: all .2s ease;
  white-space: nowrap;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.nav-actions a:hover { background: var(--cream); color: var(--ink); }
.nav-actions .welcome { color: rgba(237,227,209,0.75); font-size: 0.85rem; }
.nav-actions .logout-btn { border-color: rgba(139,74,43,0.6); color: #E5A08B; }
.nav-actions .logout-btn:hover { background: var(--clay); color: var(--cream); border-color: var(--clay); }
.badge {
  display: inline-block;
  background: var(--clay);
  color: var(--cream);
  font-size: 0.6rem;
  padding: 1px 6px;
  border-radius: 8px;
  margin-left: 4px;
  line-height: 1.4;
}

.wrap {
  max-width: 1400px;
  margin: 0 auto;
  background: var(--bg-card);
  border: 1px solid var(--line);
  border-radius: 4px;
  box-shadow: 0 30px 60px -20px rgba(0,0,0,0.6);
  padding: 40px 36px 40px;
}

.message {
  padding: 14px 18px;
  border-radius: 2px;
  background: rgba(107,79,42,0.08);
  color: var(--brass);
  border-left: 3px solid var(--brass);
  margin-bottom: 22px;
  font-size: 0.9rem;
}

.welcome-card {
  padding: 22px 26px;
  background: rgba(107,79,42,0.06);
  border-left: 3px solid var(--brass);
  margin-bottom: 26px;
}
.welcome-title {
  font-family: var(--font-display);
  font-weight: 400;
  font-size: 1.4rem;
  color: var(--ink);
  margin-bottom: 6px;
}
.welcome-subtitle {
  display: flex;
  gap: 16px;
  align-items: center;
  flex-wrap: wrap;
  color: var(--ink-soft);
  font-size: 0.9rem;
}
.room-badge {
  font-family: var(--font-display);
  font-size: 0.68rem;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  padding: 4px 10px;
  background: var(--brass);
  color: var(--bg-card);
  border-radius: 2px;
}

.year-navigator {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 20px;
  margin: 20px 0 30px;
}
.year-navigator .year-btn {
  font-family: var(--font-display);
  font-size: 1rem;
  width: 44px;
  height: 44px;
  border: 1px solid var(--line);
  border-radius: 2px;
  color: var(--ink-soft);
  text-decoration: none;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all .2s ease;
}
.year-navigator .year-btn:hover { background: var(--brass); color: var(--bg-card); border-color: var(--brass); }
.year-navigator .current-year {
  font-family: var(--font-display);
  font-weight: 500;
  font-size: 1.6rem;
  color: var(--brass);
  letter-spacing: 0.05em;
  min-width: 80px;
  text-align: center;
}

.section-title {
  font-family: var(--font-display);
  font-weight: 400;
  font-size: 1.1rem;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: var(--ink-soft);
  margin: 30px 0 18px;
  padding-bottom: 10px;
  border-bottom: 1px solid var(--line);
}

.bills-container {
  background: rgba(246,241,230,0.5);
  border: 1px solid var(--line);
  border-radius: 2px;
  padding: 26px;
  margin-bottom: 10px;
}
.bills-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 26px;
}
@media (max-width: 900px) { .bills-grid { grid-template-columns: 1fr; } }
.bills-breakdown {
  background: var(--bg-card);
  border: 1px solid var(--line);
  border-radius: 2px;
  padding: 20px 22px;
}
.bill-item {
  display: flex;
  justify-content: space-between;
  padding: 10px 0;
  border-bottom: 1px dashed var(--line);
  color: var(--ink-soft);
  font-size: 0.9rem;
}
.bill-label { color: var(--ink-soft); }
.bill-value { color: var(--ink); font-weight: 600; }
.bill-total {
  display: flex;
  justify-content: space-between;
  padding: 14px 0 4px;
  margin-top: 10px;
  font-family: var(--font-display);
  font-weight: 600;
  font-size: 1.05rem;
  color: var(--brass);
}

.receipt-section {
  background: var(--bg-card);
  border: 1px solid var(--line);
  border-radius: 2px;
  padding: 20px 22px;
}
.receipt-header {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  margin-bottom: 16px;
  padding-bottom: 12px;
  border-bottom: 1px solid var(--line);
}
.receipt-month {
  font-family: var(--font-display);
  font-size: 0.9rem;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--ink);
}
.receipt-amount {
  font-family: var(--font-display);
  font-weight: 600;
  font-size: 1rem;
  color: var(--brass);
}

.month-navigator {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 14px;
  margin: 0 0 18px;
}
.month-nav-btn {
  font-family: var(--font-display);
  font-size: 1.1rem;
  color: var(--ink-soft);
  background: transparent;
  border: 1px solid var(--line);
  width: 38px;
  height: 38px;
  border-radius: 2px;
  cursor: pointer;
  transition: all .2s;
}
.month-nav-btn:hover:not(:disabled) { background: var(--brass); color: var(--bg-card); border-color: var(--brass); }
.month-nav-btn:disabled { opacity: 0.3; cursor: not-allowed; }
.month-label {
  font-family: var(--font-display);
  color: var(--ink);
  font-size: 0.9rem;
  font-weight: 500;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  min-width: 150px;
  text-align: center;
}

.upload-area {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 32px 20px;
  background: rgba(246,241,230,0.5);
  border: 2px dashed var(--line);
  border-radius: 2px;
  cursor: pointer;
  transition: all .2s;
  margin: 16px 0;
}
.upload-area:hover { border-color: var(--brass-light); background: rgba(107,79,42,0.05); }
.upload-icon { font-size: 1.8rem; margin-bottom: 6px; }
.upload-text { color: var(--ink-soft); font-size: 0.85rem; }
#uploadPreview {
  display: none;
  max-width: 100%;
  max-height: 200px;
  border-radius: 2px;
  object-fit: contain;
}
.upload-btn {
  width: 100%;
  font-family: var(--font-display);
  font-size: 0.75rem;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  padding: 13px;
  background: var(--brass);
  color: var(--bg-card);
  border: 1px solid var(--brass);
  border-radius: 2px;
  cursor: pointer;
  transition: all .2s ease;
  margin-top: 6px;
}
.upload-btn:hover:not(:disabled) { background: transparent; color: var(--brass); }
.upload-btn:disabled { opacity: 0.5; cursor: not-allowed; }

.status-badge {
  display: inline-block;
  font-family: var(--font-display);
  font-size: 0.68rem;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  padding: 6px 12px;
  border-radius: 2px;
  margin-top: 12px;
}
.status-pending { background: rgba(201,168,118,0.25); color: #8B6F3D; }
.status-verified { background: rgba(107,79,42,0.12); color: var(--brass); }
.status-overdue { background: rgba(139,74,43,0.15); color: var(--clay); }

.history-container {
  background: rgba(246,241,230,0.5);
  border: 1px solid var(--line);
  border-radius: 2px;
  padding: 20px;
  overflow-x: auto;
  margin-bottom: 10px;
}
.history-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.82rem;
  background: var(--bg-card);
}
.history-table thead th {
  font-family: var(--font-display);
  font-weight: 500;
  font-size: 0.68rem;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: var(--ink-soft);
  padding: 12px 8px;
  text-align: center;
  border: 1px solid var(--line);
  background: rgba(246,241,230,0.6);
  white-space: nowrap;
}
.history-table tbody td {
  padding: 12px 8px;
  border: 1px solid var(--line);
  text-align: center;
  color: var(--ink);
}
.history-table tbody .row-label {
  text-align: left !important;
  font-weight: 500;
  padding-left: 14px;
  color: var(--ink-soft);
  background: rgba(246,241,230,0.4);
}
.month-name { font-family: var(--font-display); }
.payment-cell {
  cursor: pointer;
  transition: background .15s ease;
  font-size: 0.75rem;
}
.payment-cell:hover { background: rgba(107,79,42,0.08); }
.payment-cell.status-verified { background: rgba(107,79,42,0.12); color: var(--brass); font-weight: 600; }
.payment-cell.status-pending  { background: rgba(201,168,118,0.25); color: #8B6F3D; font-weight: 600; }
.payment-cell.status-overdue  { background: rgba(139,74,43,0.15); color: var(--clay); font-weight: 600; }
.payment-cell.status-empty    { color: rgba(42,33,24,0.3); }
.receipt-badge { font-size: 0.7rem; margin-left: 3px; }
.amount-cell { font-size: 0.78rem; color: var(--ink-soft); }
.view-btn, .upload-btn-small {
  font-family: var(--font-display);
  font-size: 0.62rem;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  padding: 6px 10px;
  border: 1px solid;
  border-radius: 2px;
  cursor: pointer;
  transition: all .2s ease;
  background: transparent;
}
.view-btn { color: var(--brass); border-color: var(--brass); }
.view-btn:hover { background: var(--brass); color: var(--bg-card); }
.upload-btn-small { color: var(--ink-soft); border-color: var(--line); }
.upload-btn-small:hover { background: var(--ink-soft); color: var(--bg-card); border-color: var(--ink-soft); }
.no-action { color: rgba(42,33,24,0.3); }

.send-section {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 24px;
  margin-top: 20px;
}
@media (max-width: 900px) { .send-section { grid-template-columns: 1fr; } }
.send-card {
  background: var(--bg-card);
  border: 1px solid var(--line);
  border-radius: 2px;
  padding: 22px;
}
.send-card h3 {
  font-family: var(--font-display);
  font-weight: 400;
  font-size: 0.85rem;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--ink-soft);
  margin: 0 0 14px;
}
.send-textarea {
  width: 100%;
  padding: 12px 14px;
  border: 1px solid var(--line);
  background: rgba(246,241,230,0.6);
  border-radius: 2px;
  font-family: var(--font-body);
  font-size: 0.9rem;
  color: var(--ink);
  min-height: 100px;
  resize: vertical;
  margin-bottom: 12px;
}
.send-textarea:focus { outline: none; border-color: var(--brass); background: var(--bg-card); }
.send-btn {
  width: 100%;
  font-family: var(--font-display);
  font-size: 0.72rem;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  padding: 12px;
  border: 1px solid;
  border-radius: 2px;
  cursor: pointer;
  transition: all .2s;
}
.message-btn { color: var(--brass); border-color: var(--brass); background: transparent; }
.message-btn:hover { background: var(--brass); color: var(--bg-card); }
.complaint-btn { color: var(--clay); border-color: var(--clay); background: transparent; }
.complaint-btn:hover { background: var(--clay); color: var(--bg-card); }

.receipt-panel {
  display: none;
  position: fixed;
  top: 0;
  right: 0;
  width: 420px;
  max-width: 90%;
  height: 100vh;
  background: var(--bg-card);
  border-left: 1px solid var(--line);
  z-index: 9999;
  padding: 26px;
  overflow-y: auto;
  box-shadow: -20px 0 50px rgba(0,0,0,0.5);
}
.receipt-panel.active { display: block; }
.receipt-panel .panel-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
  padding-bottom: 14px;
  border-bottom: 1px solid var(--line);
}
.receipt-panel .panel-header h3 {
  font-family: var(--font-display);
  font-weight: 400;
  font-size: 1.05rem;
  margin: 0;
  color: var(--ink);
}
.close-panel {
  background: none;
  border: none;
  color: var(--ink-soft);
  font-size: 20px;
  cursor: pointer;
}
.close-panel:hover { color: var(--clay); }
.receipt-image img { width: 100%; border-radius: 2px; margin-bottom: 15px; }
.receipt-image-placeholder {
  text-align: center;
  padding: 40px;
  color: var(--ink-soft);
  background: rgba(42,33,24,0.04);
  border: 1px dashed var(--line);
  border-radius: 2px;
}
.receipt-details p {
  color: var(--ink-soft);
  margin: 8px 0;
  font-size: 0.9rem;
}
.receipt-details strong { color: var(--brass); }
.panel-btn {
  width: 100%;
  font-family: var(--font-display);
  font-size: 0.72rem;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  padding: 12px;
  margin-top: 15px;
  border: 1px solid var(--brass);
  border-radius: 2px;
  background: var(--brass);
  color: var(--bg-card);
  font-weight: 600;
  cursor: pointer;
  transition: all .2s;
}
.panel-btn:hover { background: transparent; color: var(--brass); }

.modal {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(14,14,14,0.75);
  backdrop-filter: blur(8px);
  z-index: 9999;
  justify-content: center;
  align-items: center;
  padding: 20px;
}
.modal-content {
  background: var(--bg-card);
  border: 1px solid var(--line);
  border-radius: 4px;
  max-width: 520px;
  width: 100%;
  max-height: 85vh;
  display: flex;
  flex-direction: column;
}
.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 20px 24px;
  border-bottom: 1px solid var(--line);
}
.modal-header h3 {
  font-family: var(--font-display);
  font-weight: 400;
  font-size: 1.1rem;
  margin: 0;
}
.close-modal {
  background: none;
  border: none;
  color: var(--ink-soft);
  font-size: 20px;
  cursor: pointer;
}
.close-modal:hover { color: var(--clay); }
.modal-body { padding: 20px 24px; overflow-y: auto; flex: 1; }
.modal-footer {
  padding: 16px 24px;
  border-top: 1px solid var(--line);
  text-align: right;
}
.message-item {
  background: rgba(246,241,230,0.5);
  border-radius: 2px;
  padding: 14px;
  margin-bottom: 10px;
  border-left: 3px solid transparent;
}
.message-item.unread { border-left-color: var(--brass); background: rgba(107,79,42,0.06); }
.message-sender {
  font-family: var(--font-display);
  font-size: 0.75rem;
  letter-spacing: 0.08em;
  color: var(--brass);
  text-transform: uppercase;
}
.message-subject { font-weight: 600; margin: 4px 0; font-size: 0.9rem; color: var(--ink); }
.message-preview { font-size: 0.8rem; color: var(--ink-soft); }
.message-date { font-size: 0.72rem; color: var(--ink-soft); margin-top: 6px; }
.btn-close {
  font-family: var(--font-display);
  font-size: 0.72rem;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  padding: 10px 22px;
  border-radius: 2px;
  border: 1px solid var(--ink-soft);
  background: transparent;
  color: var(--ink-soft);
  cursor: pointer;
}
.btn-close:hover { background: var(--ink-soft); color: var(--bg-card); }

@media (max-width: 900px) {
  .wrap { padding: 24px 20px; margin: 0 8px; }
  .navbar { padding: 0 8px; }
}
</style>
</head>
<body>

<div class="navbar">
  <h2>✦ TENANT DASHBOARD</h2>
  <div class="nav-actions">
    <a href="#" onclick="openMessageModal()">💬 Messages <?php if ($unread_count > 0): ?><span class="badge"><?php echo $unread_count; ?></span><?php endif; ?></a>
    <a href="settings.php">🔔 Notifications</a>
    <span class="welcome"><?php echo htmlspecialchars($tenant['email']); ?></span>
    <a href="logout.php" class="logout-btn">Logout</a>
  </div>
</div>

<div class="wrap">

  <?php if ($message): ?>
    <div class="message"><?php echo $message; ?></div>
  <?php endif; ?>

  <div class="welcome-card">
    <div class="welcome-title">Welcome back, <?php echo htmlspecialchars($tenant['email']); ?></div>
    <div class="welcome-subtitle">
      <span>Room <?php echo $room; ?></span>
      <span class="room-badge"><?php echo $occupants; ?> Person(s) in room</span>
    </div>
  </div>

  <div class="year-navigator">
    <a href="?year=<?php echo $current_year - 1; ?>" class="year-btn">‹</a>
    <span class="current-year"><?php echo $current_year; ?></span>
    <a href="?year=<?php echo $current_year + 1; ?>" class="year-btn">›</a>
  </div>

  <h2 class="section-title">💰 Current Bills</h2>
  <div class="bills-container">
    <div class="bills-grid">
      <div class="bills-breakdown">
        <div class="bill-item"><span class="bill-label">Rent</span><span class="bill-value">₱<?php echo number_format($rent_amount, 2); ?></span></div>
        <div class="bill-item"><span class="bill-label">Water Bill</span><span class="bill-value">₱<?php echo number_format($tenant['water_bill'] ?: 300, 2); ?></span></div>
        <div class="bill-item"><span class="bill-label">Electric Bill</span><span class="bill-value">₱<?php echo number_format($tenant['electric_bill'] ?: 500, 2); ?></span></div>
        <div class="bill-item"><span class="bill-label">WiFi Bill</span><span class="bill-value">₱<?php echo number_format($tenant['wifi_bill'] ?: 200, 2); ?></span></div>
        <div class="bill-total"><span>TOTAL</span><span>₱<?php echo number_format($total_amount, 2); ?></span></div>
      </div>
      <div class="receipt-section">
        <div class="month-navigator">
          <button type="button" class="month-nav-btn" id="prevMonthBtn" onclick="changeUploadMonth(-1)">‹</button>
          <span class="month-label" id="selectedMonthLabel"><?php echo date('F Y'); ?></span>
          <button type="button" class="month-nav-btn" id="nextMonthBtn" onclick="changeUploadMonth(1)">›</button>
        </div>
        <div class="receipt-header">
          <span class="receipt-month" id="receiptMonthHeader"><?php echo date('F Y'); ?></span>
          <span class="receipt-amount">₱<?php echo number_format($total_amount, 2); ?></span>
        </div>
        <form method="POST" enctype="multipart/form-data" id="uploadForm">
          <div class="upload-area" id="uploadArea" onclick="triggerFilePick()">
            <span class="upload-icon" id="uploadIcon">📷</span>
            <span class="upload-text" id="uploadText">Click to upload GCash/Bank Receipt (PNG only)</span>
            <img id="uploadPreview" alt="Preview">
          </div>
          <input type="file" id="receiptFile" name="receipt" style="display: none;" accept="image/png" onchange="showFileName()">
          <input type="hidden" name="month" id="upload_form_month" value="<?php echo $currentMonth; ?>">
          <input type="hidden" name="year" id="upload_form_year" value="<?php echo $currentYear; ?>">
          <button type="submit" name="upload_receipt" class="upload-btn" id="uploadBtn">📤 UPLOAD RECEIPT</button>
        </form>
        <div class="status-badge status-pending" id="statusBadge">⏳ Waiting for owner verification</div>
      </div>
    </div>
  </div>

  <h2 class="section-title">📜 Payment History</h2>
  <div class="history-container">
    <table class="history-table">
      <thead>
        <tr>
          <th class="month-name">Month</th>
          <th>Jan</th><th>Feb</th><th>Mar</th><th>Apr</th><th>May</th><th>Jun</th>
          <th>Jul</th><th>Aug</th><th>Sep</th><th>Oct</th><th>Nov</th><th>Dec</th>
        </tr>
      </thead>
      <tbody>
        <tr class="status-row">
          <td class="row-label">Status</td>
          <?php for ($month = 1; $month <= 12; $month++): 
            $paymentStatus = isset($payments[$month]) ? $payments[$month]['status'] : 'empty';
            $has_receipt = isset($payments[$month]) && !empty($payments[$month]['receipt_image']);
            $receipt_path = $has_receipt ? $payments[$month]['receipt_image'] : '';
            
            if ($current_year > $currentYear) {
                $status = 'empty';
            } elseif ($current_year < $currentYear) {
                if ($tenant_created_year > $current_year) $status = 'empty';
                else $status = $paymentStatus == 'verified' ? 'verified' : 'overdue';
            } else {
                if ($month < $tenant_created_month) $status = 'empty';
                elseif ($month < $currentMonth) $status = $paymentStatus == 'verified' ? 'verified' : 'overdue';
                elseif ($month == $currentMonth) $status = $paymentStatus == 'verified' ? 'verified' : 'pending';
                else $status = 'empty';
            }
          ?>
            <td class="payment-cell <?php echo getStatusClass($status); ?>" 
                data-receipt="<?php echo htmlspecialchars($receipt_path); ?>"
                data-month="<?php echo $month; ?>"
                data-year="<?php echo $current_year; ?>"
                data-status="<?php echo $status; ?>"
                data-has-receipt="<?php echo $has_receipt ? '1' : '0'; ?>"
                onclick="showReceiptPanel(this)">
              <?php echo getStatusText($status); ?>
              <?php if ($has_receipt): ?><span class="receipt-badge">📷</span><?php endif; ?>
            </td>
          <?php endfor; ?>
        </tr>
        <tr class="amount-row">
          <td class="row-label">Amount (₱)</td>
          <?php for ($month = 1; $month <= 12; $month++): ?>
            <td class="amount-cell">₱<?php echo number_format($total_amount, 0); ?></td>
          <?php endfor; ?>
        </tr>
        <tr class="receipt-row">
          <td class="row-label">Receipt</td>
          <?php for ($month = 1; $month <= 12; $month++): 
            $paymentStatus = isset($payments[$month]) ? $payments[$month]['status'] : 'empty';
            $has_receipt = isset($payments[$month]) && !empty($payments[$month]['receipt_image']);
            
            if ($current_year > $currentYear) $status = 'empty';
            elseif ($current_year < $currentYear) {
                if ($tenant_created_year > $current_year) $status = 'empty';
                else $status = $paymentStatus == 'verified' ? 'verified' : 'overdue';
            } else {
                if ($month < $tenant_created_month) $status = 'empty';
                elseif ($month < $currentMonth) $status = $paymentStatus == 'verified' ? 'verified' : 'overdue';
                elseif ($month == $currentMonth) $status = $paymentStatus == 'verified' ? 'verified' : 'pending';
                else $status = 'empty';
            }
          ?>
            <td class="receipt-cell">
              <?php if ($has_receipt): ?>
                <button class="view-btn" onclick="showReceiptPanel(this.closest('tr').previousElementSibling.querySelector('td[data-month=&quot;<?php echo $month; ?>&quot;]'))">👁️ View</button>
              <?php elseif ($status == 'pending' || $status == 'overdue'): ?>
                <button class="upload-btn-small" onclick="setUploadMonth(<?php echo $month; ?>, <?php echo $current_year; ?>)">📸 Upload</button>
              <?php else: ?>
                <span class="no-action">—</span>
              <?php endif; ?>
            </td>
          <?php endfor; ?>
        </tr>
      </tbody>
    </table>
  </div>

  <h2 class="section-title">📤 Send to Owner</h2>
  <div class="send-section">
    <div class="send-card">
      <h3>📝 Message</h3>
      <form method="POST">
        <textarea name="message" class="send-textarea" placeholder="Type your message here..."></textarea>
        <button type="submit" name="send_message" class="send-btn message-btn">📨 Send Message</button>
      </form>
    </div>
    <div class="send-card">
      <h3>⚠️ Complaint</h3>
      <form method="POST">
        <textarea name="complaint" class="send-textarea" placeholder="Type your complaint here..."></textarea>
        <button type="submit" name="file_complaint" class="send-btn complaint-btn">📢 File Complaint</button>
      </form>
    </div>
  </div>

</div>

<div class="receipt-panel" id="receiptPanel">
  <div class="panel-header">
    <h3>📸 Receipt Details</h3>
    <button class="close-panel" onclick="closeReceiptPanel()">✕</button>
  </div>
  <div id="receiptContent">
    <div class="receipt-image" id="receiptImageContainer">
      <img id="receiptImage" src="" style="display: none;">
      <div class="receipt-image-placeholder" id="noImagePlaceholder">No receipt uploaded</div>
    </div>
    <div class="receipt-details" id="receiptDetails"></div>
    <button class="panel-btn" id="downloadBtn" onclick="downloadReceipt()" style="display: none;">📥 Download Receipt</button>
  </div>
</div>

<div id="messageModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h3>💬 Messages</h3>
      <button class="close-modal" onclick="closeMessageModal()">✕</button>
    </div>
    <div class="modal-body">
      <?php if (count($tenant_messages) > 0): ?>
        <?php foreach ($tenant_messages as $msg): ?>
          <div class="message-item <?php echo (!$msg['is_read'] && $msg['receiver_id'] == $_SESSION['user_id']) ? 'unread' : ''; ?>">
            <div class="message-sender"><?php echo $msg['sender_role'] == 'owner' ? '👑 Owner' : '📅 You'; ?></div>
            <div class="message-subject"><?php echo htmlspecialchars($msg['subject']); ?></div>
            <div class="message-preview"><?php echo htmlspecialchars(substr($msg['message'], 0, 100)); ?></div>
            <div class="message-date"><?php echo date('M d, Y h:i A', strtotime($msg['created_at'])); ?></div>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <p style="text-align:center;padding:20px;color:var(--ink-soft);">No messages yet</p>
      <?php endif; ?>
    </div>
    <div class="modal-footer">
      <button onclick="closeMessageModal()" class="btn-close">Close</button>
    </div>
  </div>
</div>

<script>
const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
const today = new Date();
const realCurrentMonth = today.getMonth() + 1;
const realCurrentYear = today.getFullYear();

let uploadMonth = realCurrentMonth;
let uploadYear = realCurrentYear;

const paymentStatuses = <?php echo json_encode(array_map(function($p) { return $p['status']; }, $payments)); ?>;

function changeUploadMonth(delta) {
    let newMonth = uploadMonth + delta;
    let newYear = uploadYear;
    if (newMonth > 12) { newMonth = 1; newYear++; }
    if (newMonth < 1) { newMonth = 12; newYear--; }
    
    const minDate = new Date(realCurrentYear, realCurrentMonth - 1 - 2, 1);
    const maxDate = new Date(realCurrentYear, realCurrentMonth - 1, 1);
    const newDate = new Date(newYear, newMonth - 1, 1);
    if (newDate < minDate || newDate > maxDate) return;
    
    uploadMonth = newMonth;
    uploadYear = newYear;
    updateUploadUI();
}

function updateUploadUI() {
    document.getElementById('selectedMonthLabel').textContent = monthNames[uploadMonth - 1] + ' ' + uploadYear;
    document.getElementById('receiptMonthHeader').textContent = monthNames[uploadMonth - 1] + ' ' + uploadYear;
    document.getElementById('upload_form_month').value = uploadMonth;
    document.getElementById('upload_form_year').value = uploadYear;
    
    document.getElementById('receiptFile').value = '';
    document.getElementById('uploadIcon').style.display = 'inline';
    document.getElementById('uploadText').style.display = 'inline';
    document.getElementById('uploadPreview').style.display = 'none';
    document.getElementById('uploadPreview').src = '';
    document.getElementById('uploadText').textContent = 'Click to upload GCash/Bank Receipt (PNG only)';
    
    const status = paymentStatuses[uploadMonth] || 'pending';
    const badge = document.getElementById('statusBadge');
    
    if (status === 'verified') {
        badge.textContent = '✅ Verified by owner — locked';
        badge.className = 'status-badge status-verified';
        document.getElementById('uploadBtn').disabled = true;
        document.getElementById('uploadArea').style.pointerEvents = 'none';
        document.getElementById('uploadArea').style.opacity = '0.5';
    } else if (status === 'overdue') {
        badge.textContent = '⚠️ Overdue — please pay';
        badge.className = 'status-badge status-overdue';
        document.getElementById('uploadBtn').disabled = false;
        document.getElementById('uploadArea').style.pointerEvents = 'auto';
        document.getElementById('uploadArea').style.opacity = '1';
    } else {
        badge.textContent = '⏳ Waiting for owner verification';
        badge.className = 'status-badge status-pending';
        document.getElementById('uploadBtn').disabled = false;
        document.getElementById('uploadArea').style.pointerEvents = 'auto';
        document.getElementById('uploadArea').style.opacity = '1';
    }
    
    const minDate = new Date(realCurrentYear, realCurrentMonth - 1 - 2, 1);
    const maxDate = new Date(realCurrentYear, realCurrentMonth - 1, 1);
    const currentDate = new Date(uploadYear, uploadMonth - 1, 1);
    document.getElementById('prevMonthBtn').disabled = (currentDate <= minDate);
    document.getElementById('nextMonthBtn').disabled = (currentDate >= maxDate);
}

function triggerFilePick() {
    const btn = document.getElementById('uploadBtn');
    if (btn.disabled) return;
    document.getElementById('receiptFile').click();
}

function setUploadMonth(month, year) {
    document.getElementById('upload_form_month').value = month;
    document.getElementById('upload_form_year').value = year;
    uploadMonth = month;
    uploadYear = year;
    updateUploadUI();
    document.querySelector('.receipt-section').scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function showFileName() {
    const input = document.getElementById('receiptFile');
    const icon = document.getElementById('uploadIcon');
    const text = document.getElementById('uploadText');
    const preview = document.getElementById('uploadPreview');
    
    if (input.files && input.files[0]) {
        icon.style.display = 'none';
        text.style.display = 'none';
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            preview.style.display = 'block';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function showReceiptPanel(cell) {
    const panel = document.getElementById('receiptPanel');
    const img = document.getElementById('receiptImage');
    const placeholder = document.getElementById('noImagePlaceholder');
    const details = document.getElementById('receiptDetails');
    const downloadBtn = document.getElementById('downloadBtn');
    
    const receiptPath = cell.getAttribute('data-receipt');
    const month = parseInt(cell.getAttribute('data-month'));
    const year = cell.getAttribute('data-year');
    const status = cell.getAttribute('data-status');
    const monthName = monthNames[month - 1];
    
    if (receiptPath && receiptPath !== '') {
        img.src = receiptPath;
        img.style.display = 'block';
        placeholder.style.display = 'none';
        downloadBtn.style.display = 'block';
        downloadBtn.onclick = function() { 
            const link = document.createElement('a'); 
            link.href = receiptPath; 
            link.download = 'receipt_' + monthName + '_' + year + '.png'; 
            link.click(); 
        };
    } else {
        img.style.display = 'none';
        placeholder.style.display = 'block';
        placeholder.textContent = status === 'empty' ? 'No payment record for this month' : 'No receipt uploaded';
        downloadBtn.style.display = 'none';
    }
    
    let statusText = '';
    if (status === 'verified') statusText = '✅ Verified';
    else if (status === 'pending') statusText = '⏳ Pending';
    else if (status === 'overdue') statusText = '⚠️ Overdue';
    else statusText = '🔵 Empty';
    
    details.innerHTML = `
        <p><strong>Month:</strong> ${monthName} ${year}</p>
        <p><strong>Amount:</strong> ₱<?php echo number_format($total_amount, 0); ?></p>
        <p><strong>Status:</strong> ${statusText}</p>
    `;
    panel.classList.add('active');
}

function closeReceiptPanel() {
    document.getElementById('receiptPanel').classList.remove('active');
}

function downloadReceipt() {
    const img = document.getElementById('receiptImage');
    if (img.src && img.style.display !== 'none') {
        const link = document.createElement('a');
        link.href = img.src;
        link.download = 'receipt.png';
        link.click();
    }
}

function openMessageModal() { document.getElementById('messageModal').style.display = 'flex'; }
function closeMessageModal() { document.getElementById('messageModal').style.display = 'none'; }
window.onclick = function(event) {
    const modal = document.getElementById('messageModal');
    if (event.target == modal) modal.style.display = 'none';
}

document.addEventListener('DOMContentLoaded', function() {
    updateUploadUI();
});
</script>
</body>
</html>