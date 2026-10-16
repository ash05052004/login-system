<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'owner') {
    header('Location: login.php');
    exit();
}

require_once 'config.php';

if (isset($_GET['set_inactive']) || isset($_GET['set_active'])) {
    csrf_check();
}
if (isset($_POST['verify_payment']) || isset($_POST['mark_partial']) || isset($_POST['unverify_payment']) || isset($_POST['update_capacity'])) {
    csrf_check();
}

$current_year = isset($_GET['year']) ? intval($_GET['year']) : 2026;
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'active';

$counts = getUnreadCounts($pdo, $_SESSION['user_id']);
$message_unread = $counts['message'];
$complaint_unread = $counts['complaint'];

if (isset($_POST['update_capacity'])) {
    $room_number = $_POST['room_number'];
    $price_option = $_POST['price_option'];
    $max_occupancy = 4;
    if ($price_option == '5000') $max_occupancy = 1;
    elseif ($price_option == '2500') $max_occupancy = 2;
    elseif ($price_option == '2000') $max_occupancy = 3;
    elseif ($price_option == '1500') $max_occupancy = 4;
    $stmt = $pdo->prepare("UPDATE rooms SET price_option = ?, max_occupancy = ? WHERE room_number = ?");
    $stmt->execute([$price_option, $max_occupancy, $room_number]);
    header("Location: owner_dashboard.php?year=$current_year&filter=$filter&msg=capacity_updated");
    exit();
}

if (isset($_GET['set_inactive'])) {
    $userId = $_GET['set_inactive'];
    $stmt = $pdo->prepare("UPDATE users SET tenant_status = 'inactive', left_date = CURDATE() WHERE id = ?");
    $stmt->execute([$userId]);
    header("Location: owner_dashboard.php?year=$current_year&filter=active&msg=inactive");
    exit();
}

if (isset($_GET['set_active'])) {
    $userId = $_GET['set_active'];
    $stmt = $pdo->prepare("UPDATE users SET tenant_status = 'active', left_date = NULL WHERE id = ?");
    $stmt->execute([$userId]);
    header("Location: owner_dashboard.php?year=$current_year&filter=inactive&msg=active");
    exit();
}

if (isset($_POST['verify_payment'])) {
    $userId = $_POST['user_id'];
    $month = $_POST['month'];
    $year = $_POST['year'];
    $amount_due = floatval($_POST['amount_due'] ?? 0);
    
    $stmt = $pdo->prepare("INSERT INTO payments (user_id, year, month, status, payment_date, amount_paid, amount_due, balance, is_partial) 
                           VALUES (?, ?, ?, 'verified', NOW(), ?, ?, 0, 0)
                           ON DUPLICATE KEY UPDATE status = 'verified', payment_date = NOW(), amount_paid = ?, amount_due = ?, balance = 0, is_partial = 0");
    $stmt->execute([$userId, $year, $month, $amount_due, $amount_due, $amount_due, $amount_due]);
    
    $prev_month = $month - 1;
    $prev_year = $year;
    if ($prev_month == 0) { $prev_month = 12; $prev_year--; }
    
    $stmt = $pdo->prepare("UPDATE payments SET balance = 0, status = 'verified' 
                           WHERE user_id = ? AND year = ? AND month = ? AND balance > 0");
    $stmt->execute([$userId, $prev_year, $prev_month]);
    
    require_once 'notification_helper.php';
    $monthName = date('F', mktime(0, 0, 0, $month, 1));
    notifyPaymentVerified($userId, $amount_due, "$monthName $year");
    
    header("Location: owner_dashboard.php?year=$current_year&filter=$filter&msg=verified");
    exit();
}

if (isset($_POST['mark_partial'])) {
    $userId = $_POST['user_id'];
    $month = $_POST['month'];
    $year = $_POST['year'];
    $amount_paid = floatval($_POST['amount_paid'] ?? 0);
    $amount_due = floatval($_POST['amount_due'] ?? 0);
    $balance = $amount_due - $amount_paid;
    
    $stmt = $pdo->prepare("INSERT INTO payments (user_id, year, month, status, payment_date, amount_paid, amount_due, balance, is_partial) 
                           VALUES (?, ?, ?, 'partial', NOW(), ?, ?, ?, 1)
                           ON DUPLICATE KEY UPDATE status = 'partial', payment_date = NOW(), amount_paid = ?, amount_due = ?, balance = ?, is_partial = 1");
    $stmt->execute([$userId, $year, $month, $amount_paid, $amount_due, $balance, $amount_paid, $amount_due, $balance]);
    
    require_once 'notification_helper.php';
    $monthName = date('F', mktime(0, 0, 0, $month, 1));
    notifyPartialPayment($userId, $amount_paid, $balance, "$monthName $year");
    
    header("Location: owner_dashboard.php?year=$current_year&filter=$filter&msg=partial");
    exit();
}

if (isset($_POST['unverify_payment'])) {
    $userId = $_POST['user_id'];
    $month = $_POST['month'];
    $year = $_POST['year'];
    $stmt = $pdo->prepare("UPDATE payments SET status = 'pending', payment_date = NULL 
                           WHERE user_id = ? AND year = ? AND month = ?");
    $stmt->execute([$userId, $year, $month]);
    require_once 'notification_helper.php';
    $monthName = date('F', mktime(0, 0, 0, $month, 1));
    notifyPaymentUnverified($userId, "$monthName $year");
    header("Location: owner_dashboard.php?year=$current_year&filter=$filter&msg=unverified");
    exit();
}

if ($filter == 'inactive') {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE role = 'tenant' AND tenant_status = 'inactive' ORDER BY username ASC");
} else {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE role = 'tenant' AND is_deleted = 0 AND tenant_status = 'active' ORDER BY username ASC");
}
$stmt->execute();
$allTenants = $stmt->fetchAll(PDO::FETCH_ASSOC);

$tenantsByRoom = [];
foreach ($allTenants as $tenant) {
    $room = '';

    if (!empty($tenant['room_number'])) {
        $room = strtoupper(trim($tenant['room_number']));
    }
    elseif (preg_match('/^(A[1-7]|B[1-7])/i', $tenant['username'], $matches)) {
        $room = strtoupper($matches[1]);
    }

    if ($room && preg_match('/^[AB][1-7]$/', $room)) {
        if (!isset($tenantsByRoom[$room])) {
            $tenantsByRoom[$room] = [];
        }
        $tenantsByRoom[$room][] = $tenant;
    }
}

$stmt = $pdo->query("SELECT * FROM rooms ORDER BY room_number");
$roomsData = [];
foreach ($stmt->fetchAll() as $room) {
    $roomsData[$room['room_number']] = $room;
}

$payments = [];
$stmt = $pdo->prepare("SELECT * FROM payments WHERE year = ?");
$stmt->execute([$current_year]);
$paymentData = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($paymentData as $payment) {
    $payments[$payment['user_id']][$payment['month']] = $payment;
}

$currentMonth = date('n');
$currentYear = date('Y');

function getStatusClass($status) {
    switch($status) {
        case 'verified': return 'st-verified';
        case 'partial': return 'st-partial';
        case 'pending': return 'st-pending';
        case 'overdue': return 'st-overdue';
        default: return 'st-empty';
    }
}

function getStatusText($status) {
    switch($status) {
        case 'verified': return 'Verified';
        case 'partial': return 'Partial';
        case 'pending': return 'Pending';
        case 'overdue': return 'Overdue';
        default: return '—';
    }
}

function getTenantBalance($tenant_id, $current_year) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT SUM(balance) as total_balance FROM payments WHERE user_id = ? AND year = ? AND balance > 0");
    $stmt->execute([$tenant_id, $current_year]);
    $result = $stmt->fetch();
    return floatval($result['total_balance'] ?? 0);
}

$message = '';
if (isset($_GET['msg'])) {
    if ($_GET['msg'] == 'verified') $message = 'Payment verified successfully';
    if ($_GET['msg'] == 'unverified') $message = 'Payment unverified';
    if ($_GET['msg'] == 'partial') $message = 'Partial payment marked';
    if ($_GET['msg'] == 'capacity_updated') $message = 'Room capacity updated successfully';
    if ($_GET['msg'] == 'inactive') $message = 'Tenant moved to inactive';
    if ($_GET['msg'] == 'active') $message = 'Tenant reactivated';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Owner Dashboard — Dannielle's Apartment</title>
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
}
.video-background, .overlay, .unmute-btn { display: none !important; }

.navbar {
  background: rgba(14,14,14,0.7);
  backdrop-filter: blur(8px);
  padding: 16px 40px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  position: sticky;
  top: 0;
  z-index: 100;
  border-bottom: 1px solid rgba(237,227,209,0.15);
  flex-wrap: wrap;
  gap: 12px;
}
.navbar h2 {
  font-family: var(--font-display);
  font-weight: 600;
  font-size: 1rem;
  letter-spacing: 0.18em;
  color: var(--cream);
  margin: 0;
  white-space: nowrap;
}
.nav-links { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.nav-links .welcome { color: rgba(237,227,209,0.75); font-size: 0.85rem; margin-right: 6px; }
.nav-links a {
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
}
.nav-links a:hover { background: var(--cream); color: var(--ink); }
.nav-links a.active { background: var(--cream); color: var(--ink); }
.nav-links a.logout { border-color: rgba(139,74,43,0.6); color: #E5A08B; }
.nav-links a.logout:hover { background: var(--clay); color: var(--cream); border-color: var(--clay); }
.nav-badge {
  display: inline-block;
  background: var(--clay);
  color: var(--cream);
  font-size: 0.6rem;
  padding: 1px 6px;
  border-radius: 8px;
  margin-left: 5px;
  line-height: 1.4;
}

.owner-container {
  max-width: 1500px;
  margin: 40px auto 60px;
  padding: 40px 36px;
  background: var(--bg-card);
  border: 1px solid var(--line);
  border-radius: 4px;
  box-shadow: 0 30px 60px -20px rgba(0,0,0,0.6);
}
.owner-container h1 {
  font-family: var(--font-display);
  font-weight: 300;
  font-size: 2rem;
  margin: 0 0 24px;
  color: var(--ink);
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

.room-section { margin-bottom: 40px; }
.room-title {
  font-family: var(--font-display);
  font-size: 1rem;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--ink-soft);
  margin: 0 0 14px;
  padding-bottom: 10px;
  border-bottom: 1px solid var(--line);
}

.excel-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.82rem;
  margin-bottom: 20px;
  background: var(--bg-card);
}
.excel-table thead th {
  font-family: var(--font-display);
  font-weight: 500;
  font-size: 0.7rem;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: var(--ink-soft);
  padding: 12px 8px;
  text-align: center;
  border: 1px solid var(--line);
  background: rgba(246,241,230,0.6);
  white-space: nowrap;
}
.excel-table tbody td {
  padding: 12px 10px;
  border: 1px solid var(--line);
  text-align: center;
  color: var(--ink);
}
.tenant-name-cell {
  text-align: left !important;
  font-weight: 500;
  min-width: 160px;
  white-space: nowrap;
}
.balance-badge {
  display: inline-block;
  font-size: 0.68rem;
  color: var(--clay);
  margin-top: 4px;
  font-weight: 600;
}
.payment-cell {
  cursor: pointer;
  transition: background .15s ease;
  font-size: 0.78rem;
  padding: 14px 8px;
}
.payment-cell:hover:not(.payment-empty) { background: rgba(107,79,42,0.08); }
.payment-empty { opacity: 0.35; cursor: default; pointer-events: none; }
.st-verified { background: rgba(107,79,42,0.12); color: var(--brass); font-weight: 600; }
.st-partial  { background: rgba(139,92,246,0.15); color: #6B4FCF; font-weight: 600; }
.st-pending  { background: rgba(201,168,118,0.25); color: #8B6F3D; font-weight: 600; }
.st-overdue  { background: rgba(139,74,43,0.15); color: var(--clay); font-weight: 600; }
.st-empty    { color: rgba(42,33,24,0.25); }

.room-total-row td {
  background: rgba(246,241,230,0.7);
  font-size: 0.78rem;
  color: var(--ink-soft);
  font-weight: 600;
  padding: 12px 8px;
}
.total-value { font-family: var(--font-display); color: var(--brass); font-size: 0.85rem; }

.capacity-cell {
  font-family: var(--font-display);
  color: var(--ink-soft);
  font-size: 0.9rem;
}
.capacity-clickable {
  cursor: pointer;
  text-decoration: underline;
  text-decoration-style: dotted;
  text-underline-offset: 3px;
  transition: all .2s ease;
}
.capacity-clickable:hover { background: rgba(107,79,42,0.12); color: var(--brass); }
.capacity-locked { cursor: not-allowed; opacity: 0.55; }
.capacity-locked .lock-icon { font-size: 0.7rem; display: block; }

.action-link {
  text-decoration: none;
  font-size: 1.05rem;
  display: inline-block;
  transition: all .2s ease;
}
.action-link:hover { transform: scale(1.15); }

.modal {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(14,14,14,0.75);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
  z-index: 9999;
  justify-content: center;
  align-items: center;
  padding: 20px;
}
.modal.active { display: flex; }
.modal-content {
  background: var(--bg-card);
  border: 1px solid var(--line);
  border-radius: 4px;
  padding: 30px 28px;
  max-width: 520px;
  width: 100%;
  max-height: 90vh;
  overflow-y: auto;
  box-shadow: 0 40px 80px -20px rgba(0,0,0,0.7);
}
.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 20px;
  padding-bottom: 16px;
  border-bottom: 1px solid var(--line);
  gap: 12px;
}
.modal-header h3 {
  margin: 0;
  font-family: var(--font-display);
  font-weight: 300;
  font-size: 1.25rem;
}
.modal-sub { font-size: 0.78rem; color: var(--ink-soft); margin-top: 4px; }
.modal-sub strong { color: var(--brass); font-weight: 600; }
.modal-close {
  background: none;
  border: none;
  font-size: 20px;
  cursor: pointer;
  color: var(--ink-soft);
}
.modal-close:hover { color: var(--clay); }

.modal-option {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 14px;
  margin: 8px 0;
  background: rgba(246,241,230,0.5);
  border-radius: 2px;
  color: var(--ink);
  cursor: pointer;
  border: 1px solid var(--line);
  font-size: 0.85rem;
  transition: all .2s ease;
}
.modal-option:hover { border-color: var(--brass-light); }
.modal-option.selected { border-color: var(--brass); background: rgba(107,79,42,0.1); }
.modal-option input[type="radio"] { accent-color: var(--brass); }
.modal-save {
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
  margin-top: 14px;
}
.modal-save:hover { background: transparent; color: var(--brass); }
.modal-cancel {
  width: 100%;
  font-family: var(--font-display);
  font-size: 0.7rem;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  padding: 10px;
  background: transparent;
  color: var(--ink-soft);
  border: none;
  cursor: pointer;
  margin-top: 6px;
}

.panel-bills { font-size: 0.9rem; }
.panel-bill-item {
  display: flex;
  justify-content: space-between;
  padding: 10px 0;
  border-bottom: 1px dashed var(--line);
  color: var(--ink-soft);
}
.panel-total {
  display: flex;
  justify-content: space-between;
  padding: 14px 0 8px;
  margin-top: 10px;
  font-family: var(--font-display);
  font-weight: 600;
  font-size: 1.05rem;
  color: var(--brass);
}
.prev-balance-line { color: var(--clay); }
.panel-image { margin: 18px 0; text-align: center; }
.panel-image-placeholder {
  padding: 40px;
  background: rgba(42,33,24,0.04);
  border: 1px dashed var(--line);
  color: var(--ink-soft);
  font-size: 0.85rem;
}
.panel-buttons {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  margin-top: 18px;
  padding-top: 18px;
  border-top: 1px solid var(--line);
}
.panel-btn {
  flex: 1;
  min-width: 120px;
  font-family: var(--font-display);
  font-size: 0.7rem;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  padding: 12px 16px;
  border: 1px solid;
  border-radius: 2px;
  cursor: pointer;
  background: transparent;
  transition: all .2s ease;
}
.panel-btn.verify { color: var(--brass); border-color: var(--brass); }
.panel-btn.verify:hover { background: var(--brass); color: var(--bg-card); }
.panel-btn.partial { color: #6B4FCF; border-color: #6B4FCF; }
.panel-btn.partial:hover { background: #6B4FCF; color: var(--bg-card); }
.panel-btn.unverify { color: var(--clay); border-color: var(--clay); }
.panel-btn.unverify:hover { background: var(--clay); color: var(--bg-card); }

@media (max-width: 900px) {
  .navbar { padding: 14px 20px; }
  .owner-container { padding: 24px 20px; margin: 20px 12px 40px; }
  .excel-table { font-size: 0.72rem; }
  .excel-table thead th, .excel-table tbody td { padding: 8px 5px; }
}
</style>
</head>
<body>

<div class="modal" id="capacityModal">
  <div class="modal-content">
    <div class="modal-header">
      <div>
        <h3>Change Room Capacity</h3>
        <p id="modalRoomDisplay" class="modal-sub" style="font-family:var(--font-display);letter-spacing:0.1em;text-transform:uppercase;color:var(--brass);"></p>
      </div>
      <button class="modal-close" onclick="closeModal()">✕</button>
    </div>
    <form method="POST" id="capacityForm">
      <input type="hidden" name="token" value="<?php echo csrf_token(); ?>">
      <input type="hidden" name="room_number" id="modalRoomNumber">
      
      <label class="modal-option"><input type="radio" name="price_option" value="1500"> ₱1,500 — Quad (max 4 persons)</label>
      <label class="modal-option"><input type="radio" name="price_option" value="2000"> ₱2,000 — Triple (max 3 persons)</label>
      <label class="modal-option"><input type="radio" name="price_option" value="2500"> ₱2,500 — Couple (max 2 persons)</label>
      <label class="modal-option"><input type="radio" name="price_option" value="5000"> ₱5,000 — Solo (max 1 person)</label>
      
      <button type="submit" name="update_capacity" class="modal-save">Save Changes</button>
      <button type="button" class="modal-cancel" onclick="closeModal()">Cancel</button>
    </form>
  </div>
</div>

<div class="modal" id="paymentModal">
  <div class="modal-content">
    <div class="modal-header">
      <div>
        <h3>Payment Details</h3>
        <p class="modal-sub" id="modalTenantEmail">Loading...</p>
      </div>
      <button class="modal-close" onclick="closePaymentModal()">✕</button>
    </div>
    <div id="panelContent">
      <div style="text-align:center;padding:20px;color:var(--ink-soft);">Loading...</div>
    </div>
  </div>
</div>

<div class="navbar">
  <h2>✦ OWNER DASHBOARD</h2>
  <div class="nav-links">
    <span class="welcome">Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?></span>
    <a href="?year=<?php echo $current_year; ?>&filter=active" class="<?php echo $filter == 'active' ? 'active' : ''; ?>">Active</a>
    <a href="?year=<?php echo $current_year; ?>&filter=inactive" class="<?php echo $filter == 'inactive' ? 'active' : ''; ?>">Inactive</a>
    <a href="messages.php?tab=messages">Messages<?php if ($message_unread > 0): ?><span class="nav-badge"><?php echo $message_unread; ?></span><?php endif; ?></a>
    <a href="messages.php?tab=complaints">Complaints<?php if ($complaint_unread > 0): ?><span class="nav-badge"><?php echo $complaint_unread; ?></span><?php endif; ?></a>
    <a href="appointments_management.php">Appointments</a>
    <a href="settings.php">Notifications</a>
    <a href="logout.php" class="logout">Logout</a>
  </div>
</div>

<div class="owner-container">
  <h1>Property Management</h1>

  <?php if ($message): ?>
    <div class="message"><?php echo $message; ?></div>
  <?php endif; ?>

  <div class="year-navigator">
    <a href="?year=<?php echo $current_year - 1; ?>&filter=<?php echo $filter; ?>" class="year-btn">‹</a>
    <span class="current-year"><?php echo $current_year; ?></span>
    <a href="?year=<?php echo $current_year + 1; ?>&filter=<?php echo $filter; ?>" class="year-btn">›</a>
  </div>

  <div class="dashboard-layout">
    <div class="table-area">

      <div class="room-section">
        <div class="room-title">Rooms A1 – A7 <?php echo $filter == 'inactive' ? '· Inactive' : ''; ?></div>
        <?php for ($i = 1; $i <= 7; $i++): 
          $room = "A$i";
          $roomTenants = isset($tenantsByRoom[$room]) ? $tenantsByRoom[$room] : [];
          $roomData = isset($roomsData[$room]) ? $roomsData[$room] : null;
          $maxCapacity = $roomData ? $roomData['max_occupancy'] : 4;
          $hasTenants = !empty($roomTenants);
        ?>
          <table class="excel-table">
            <thead>
              <tr>
                <th rowspan="2"><?php echo $room; ?></th>
                <th colspan="12"><?php echo $current_year; ?></th>
                <th rowspan="2">Action</th>
              </tr>
              <tr>
                <th>Jan</th><th>Feb</th><th>Mar</th><th>Apr</th><th>May</th><th>Jun</th>
                <th>Jul</th><th>Aug</th><th>Sep</th><th>Oct</th><th>Nov</th><th>Dec</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($hasTenants): ?>
                <?php foreach ($roomTenants as $tenant): 
                  $tenant_created_month = date('n', strtotime($tenant['created_at']));
                  $tenant_created_year = date('Y', strtotime($tenant['created_at']));
                  $tenant_balance = getTenantBalance($tenant['id'], $current_year);
                ?>
                  <tr>
                    <td class="tenant-name-cell">
                      <?php echo htmlspecialchars($tenant['username']); ?>
                      <?php if ($tenant_balance > 0): ?><br><span class="balance-badge">Balance ₱<?php echo number_format($tenant_balance, 0); ?></span><?php endif; ?>
                    </td>
                    <?php for ($month = 1; $month <= 12; $month++): 
                      $paymentStatus = isset($payments[$tenant['id']][$month]) ? $payments[$tenant['id']][$month]['status'] : 'empty';
                      $status = 'empty';
                      if ($current_year > $currentYear) $status = 'empty';
                      elseif ($current_year < $currentYear) {
                        if ($tenant_created_year > $current_year) $status = 'empty';
                        elseif ($tenant_created_year == $current_year && $month < $tenant_created_month) $status = 'empty';
                        else $status = $paymentStatus == 'verified' ? 'verified' : ($paymentStatus == 'partial' ? 'partial' : 'overdue');
                      } else {
                        if ($tenant_created_year == $current_year && $month < $tenant_created_month) $status = 'empty';
                        elseif ($month < $currentMonth) $status = $paymentStatus == 'verified' ? 'verified' : ($paymentStatus == 'partial' ? 'partial' : 'overdue');
                        elseif ($month == $currentMonth) $status = $paymentStatus == 'verified' ? 'verified' : ($paymentStatus == 'partial' ? 'partial' : 'pending');
                        else $status = 'empty';
                      }
                    ?>
                      <td class="<?php echo getStatusClass($status); ?> payment-cell <?php echo $status == 'empty' ? 'payment-empty' : ''; ?>" 
                        <?php if ($status != 'empty'): ?>onclick="showPaymentDetails(<?php echo $tenant['id']; ?>, <?php echo $month; ?>, <?php echo $current_year; ?>, '<?php echo $status; ?>', '<?php echo htmlspecialchars($tenant['username']); ?>')"<?php endif; ?>>
                        <?php echo getStatusText($status); ?>
                      </td>
                    <?php endfor; ?>
                    <td>
                      <?php if ($tenant['tenant_status'] == 'active'): ?>
                        <a href="?set_inactive=<?php echo $tenant['id']; ?>&year=<?php echo $current_year; ?>&filter=<?php echo $filter; ?>&token=<?php echo csrf_token(); ?>" onclick="return confirm('Move to inactive?')" class="action-link" style="color:var(--clay);" title="Mark Inactive">✕</a>
                      <?php else: ?>
                        <a href="?set_active=<?php echo $tenant['id']; ?>&year=<?php echo $current_year; ?>&filter=<?php echo $filter; ?>&token=<?php echo csrf_token(); ?>" onclick="return confirm('Reactivate?')" class="action-link" style="color:var(--brass);" title="Reactivate">↻</a>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <tr class="room-total-row">
                  <td>Collected</td>
                  <?php for ($month = 1; $month <= 12; $month++): 
                    $monthTotal = 0;
                    foreach ($roomTenants as $tenant) {
                      $pStatus = isset($payments[$tenant['id']][$month]) ? $payments[$tenant['id']][$month]['status'] : 'empty';
                      if ($pStatus == 'verified' || $pStatus == 'partial') {
                        $monthTotal += floatval($payments[$tenant['id']][$month]['amount_paid'] ?? 0);
                      }
                    }
                  ?>
                    <td class="total-value">₱<?php echo number_format($monthTotal, 0); ?></td>
                  <?php endfor; ?>
                  <td class="capacity-cell capacity-locked" title="Cannot change — tenants occupy this room">
                    <?php echo $maxCapacity; ?>
                    <span class="lock-icon">🔒</span>
                  </td>
                </tr>
              <?php else: ?>
                <tr>
                  <td colspan="14" style="padding:24px;color:var(--ink-soft);font-style:italic;">
                    No <?php echo $filter == 'inactive' ? 'inactive' : ''; ?> tenants — available for rent (up to <?php echo $maxCapacity; ?> persons)
                  </td>
                </tr>
                <tr class="room-total-row">
                  <td colspan="13" style="text-align:right;padding-right:14px;">Change capacity:</td>
                  <td class="capacity-cell capacity-clickable" onclick="openModal('<?php echo $room; ?>', <?php echo $maxCapacity; ?>)" title="Click to change capacity">
                    <?php echo $maxCapacity; ?> 👥
                  </td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        <?php endfor; ?>
      </div>

      <div class="room-section">
        <div class="room-title">Rooms B1 – B7 <?php echo $filter == 'inactive' ? '· Inactive' : ''; ?></div>
        <?php for ($i = 1; $i <= 7; $i++): 
          $room = "B$i";
          $roomTenants = isset($tenantsByRoom[$room]) ? $tenantsByRoom[$room] : [];
          $roomData = isset($roomsData[$room]) ? $roomsData[$room] : null;
          $maxCapacity = $roomData ? $roomData['max_occupancy'] : 4;
          $hasTenants = !empty($roomTenants);
        ?>
          <table class="excel-table">
            <thead>
              <tr>
                <th rowspan="2"><?php echo $room; ?></th>
                <th colspan="12"><?php echo $current_year; ?></th>
                <th rowspan="2">Action</th>
              </tr>
              <tr>
                <th>Jan</th><th>Feb</th><th>Mar</th><th>Apr</th><th>May</th><th>Jun</th>
                <th>Jul</th><th>Aug</th><th>Sep</th><th>Oct</th><th>Nov</th><th>Dec</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($hasTenants): ?>
                <?php foreach ($roomTenants as $tenant): 
                  $tenant_created_month = date('n', strtotime($tenant['created_at']));
                  $tenant_created_year = date('Y', strtotime($tenant['created_at']));
                  $tenant_balance = getTenantBalance($tenant['id'], $current_year);
                ?>
                  <tr>
                    <td class="tenant-name-cell">
                      <?php echo htmlspecialchars($tenant['username']); ?>
                      <?php if ($tenant_balance > 0): ?><br><span class="balance-badge">Balance ₱<?php echo number_format($tenant_balance, 0); ?></span><?php endif; ?>
                    </td>
                    <?php for ($month = 1; $month <= 12; $month++): 
                      $paymentStatus = isset($payments[$tenant['id']][$month]) ? $payments[$tenant['id']][$month]['status'] : 'empty';
                      $status = 'empty';
                      if ($current_year > $currentYear) $status = 'empty';
                      elseif ($current_year < $currentYear) {
                        if ($tenant_created_year > $current_year) $status = 'empty';
                        elseif ($tenant_created_year == $current_year && $month < $tenant_created_month) $status = 'empty';
                        else $status = $paymentStatus == 'verified' ? 'verified' : ($paymentStatus == 'partial' ? 'partial' : 'overdue');
                      } else {
                        if ($tenant_created_year == $current_year && $month < $tenant_created_month) $status = 'empty';
                        elseif ($month < $currentMonth) $status = $paymentStatus == 'verified' ? 'verified' : ($paymentStatus == 'partial' ? 'partial' : 'overdue');
                        elseif ($month == $currentMonth) $status = $paymentStatus == 'verified' ? 'verified' : ($paymentStatus == 'partial' ? 'partial' : 'pending');
                        else $status = 'empty';
                      }
                    ?>
                      <td class="<?php echo getStatusClass($status); ?> payment-cell <?php echo $status == 'empty' ? 'payment-empty' : ''; ?>" 
                        <?php if ($status != 'empty'): ?>onclick="showPaymentDetails(<?php echo $tenant['id']; ?>, <?php echo $month; ?>, <?php echo $current_year; ?>, '<?php echo $status; ?>', '<?php echo htmlspecialchars($tenant['username']); ?>')"<?php endif; ?>>
                        <?php echo getStatusText($status); ?>
                      </td>
                    <?php endfor; ?>
                    <td>
                      <?php if ($tenant['tenant_status'] == 'active'): ?>
                        <a href="?set_inactive=<?php echo $tenant['id']; ?>&year=<?php echo $current_year; ?>&filter=<?php echo $filter; ?>&token=<?php echo csrf_token(); ?>" onclick="return confirm('Move to inactive?')" class="action-link" style="color:var(--clay);" title="Mark Inactive">✕</a>
                      <?php else: ?>
                        <a href="?set_active=<?php echo $tenant['id']; ?>&year=<?php echo $current_year; ?>&filter=<?php echo $filter; ?>&token=<?php echo csrf_token(); ?>" onclick="return confirm('Reactivate?')" class="action-link" style="color:var(--brass);" title="Reactivate">↻</a>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <tr class="room-total-row">
                  <td>Collected</td>
                  <?php for ($month = 1; $month <= 12; $month++): 
                    $monthTotal = 0;
                    foreach ($roomTenants as $tenant) {
                      $pStatus = isset($payments[$tenant['id']][$month]) ? $payments[$tenant['id']][$month]['status'] : 'empty';
                      if ($pStatus == 'verified' || $pStatus == 'partial') {
                        $monthTotal += floatval($payments[$tenant['id']][$month]['amount_paid'] ?? 0);
                      }
                    }
                  ?>
                    <td class="total-value">₱<?php echo number_format($monthTotal, 0); ?></td>
                  <?php endfor; ?>
                  <td class="capacity-cell capacity-locked" title="Cannot change — tenants occupy this room">
                    <?php echo $maxCapacity; ?>
                    <span class="lock-icon">🔒</span>
                  </td>
                </tr>
              <?php else: ?>
                <tr>
                  <td colspan="14" style="padding:24px;color:var(--ink-soft);font-style:italic;">
                    No <?php echo $filter == 'inactive' ? 'inactive' : ''; ?> tenants — available for rent (up to <?php echo $maxCapacity; ?> persons)
                  </td>
                </tr>
                <tr class="room-total-row">
                  <td colspan="13" style="text-align:right;padding-right:14px;">Change capacity:</td>
                  <td class="capacity-cell capacity-clickable" onclick="openModal('<?php echo $room; ?>', <?php echo $maxCapacity; ?>)" title="Click to change capacity">
                    <?php echo $maxCapacity; ?> 👥
                  </td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        <?php endfor; ?>
      </div>

    </div>
  </div>
</div>

<script>
function closePanel() {
  document.getElementById('paymentModal').classList.remove('active');
}
function closePaymentModal() {
  document.getElementById('paymentModal').classList.remove('active');
}

function showPaymentDetails(userId, month, year, status, tenantName) {
  const monthName = ['January','February','March','April','May','June','July','August','September','October','November','December'][month-1];
  const modal = document.getElementById('paymentModal');
  const content = document.getElementById('panelContent');
  const sub = document.getElementById('modalTenantEmail');
  
  sub.innerHTML = '<strong>' + tenantName + '</strong> · ' + monthName + ' ' + year;
  content.innerHTML = '<div style="text-align:center;padding:20px;color:var(--ink-soft);">Loading...</div>';
  modal.classList.add('active');

  fetch(`get_tenant_bills.php?user_id=${userId}&year=${year}&month=${month}`)
    .then(r => r.json())
    .then(data => {
      if (data.error) { content.innerHTML = '<div style="color:var(--clay);">Error: ' + data.error + '</div>'; return; }

      let buttons = '';
      if (status === 'verified') {
        buttons = '<button class="panel-btn unverify" onclick="unverifyPayment(' + userId + ',' + year + ',' + month + ')">Unverify</button>';
      } else if (status === 'partial') {
        buttons = '<button class="panel-btn verify" onclick="verifyPayment(' + userId + ',' + year + ',' + month + ',' + data.amount_due + ')">Verify Full Payment</button>';
      } else if (status === 'pending' || status === 'overdue') {
        buttons = '<button class="panel-btn verify" onclick="verifyPayment(' + userId + ',' + year + ',' + month + ',' + data.total + ')">Verify Full</button>';
        buttons += '<button class="panel-btn partial" onclick="markPartial(' + userId + ',' + year + ',' + month + ',' + data.total + ')">Mark Partial</button>';
      }

      let sourceBadge = '';
      if (data.source === 'arduino') sourceBadge = '<div style="color:var(--brass);font-size:0.75rem;margin-top:6px;">Arduino reading · ' + data.reading_date + '</div>';
      else if (data.source === 'manual') sourceBadge = '<div style="color:#8B6F3D;font-size:0.75rem;margin-top:6px;">Manual entry</div>';

      let prevBalanceLine = '';
      if (data.previous_balance > 0) {
        prevBalanceLine = `<div class="panel-bill-item prev-balance-line"><span>Previous Balance</span><span>₱${data.previous_balance.toLocaleString()}</span></div>`;
      }

      let balanceInfo = '';
      if (data.balance > 0) {
        balanceInfo = '<div style="background:rgba(139,92,246,0.12);color:#6B4FCF;padding:12px;margin-top:12px;font-size:0.85rem;border-left:3px solid #6B4FCF;">Partial Payment<br>Paid ₱' + data.amount_paid.toLocaleString() + ' · Balance ₱' + data.balance.toLocaleString() + '</div>';
      }

      content.innerHTML = `
        <div class="panel-bills">
          <div class="panel-bill-item"><span>Rent</span><span>₱${data.rent.toLocaleString()}</span></div>
          <div class="panel-bill-item"><span>Water</span><span>₱${data.water.toLocaleString()}</span></div>
          <div class="panel-bill-item"><span>Electric</span><span>₱${data.electric.toLocaleString()}</span></div>
          <div class="panel-bill-item"><span>WiFi</span><span>₱${data.wifi.toLocaleString()}</span></div>
          ${prevBalanceLine}
          <div class="panel-total"><span>Total</span><span>₱${data.total.toLocaleString()}</span></div>
          ${sourceBadge}
        </div>
        ${balanceInfo}
        <div class="panel-image">
          ${data.receipt_image 
            ? `<img src="${data.receipt_image}?t=${Date.now()}" style="max-width:100%;max-height:300px;cursor:pointer;" onclick="window.open('${data.receipt_image}','_blank')">` 
            : '<div class="panel-image-placeholder">No receipt uploaded</div>'}
        </div>
        <div class="panel-buttons">${buttons}</div>
      `;
    })
    .catch(err => { content.innerHTML = '<div style="color:var(--clay);">Failed to load.</div>'; });
}

function verifyPayment(userId, year, month, amountDue) {
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = window.location.href.split('?')[0] + '?year=<?php echo $current_year; ?>&filter=<?php echo $filter; ?>';
  const inputs = [
    {name:'verify_payment',value:'1'},{name:'token',value:'<?php echo csrf_token(); ?>'},
    {name:'user_id',value:userId},{name:'month',value:month},{name:'year',value:year},{name:'amount_due',value:amountDue}
  ];
  inputs.forEach(d => { const i = document.createElement('input'); i.type='hidden'; i.name=d.name; i.value=d.value; form.appendChild(i); });
  document.body.appendChild(form); form.submit();
}

function markPartial(userId, year, month, total) {
  const amount = prompt('Enter amount paid (Total due: ₱' + total + '):', total / 2);
  if (amount === null) return;
  const amountPaid = parseFloat(amount);
  if (isNaN(amountPaid) || amountPaid <= 0) { alert('Invalid amount'); return; }
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = window.location.href.split('?')[0] + '?year=<?php echo $current_year; ?>&filter=<?php echo $filter; ?>';
  const inputs = [
    {name:'mark_partial',value:'1'},{name:'token',value:'<?php echo csrf_token(); ?>'},
    {name:'user_id',value:userId},{name:'month',value:month},{name:'year',value:year},
    {name:'amount_paid',value:amountPaid},{name:'amount_due',value:total}
  ];
  inputs.forEach(d => { const i = document.createElement('input'); i.type='hidden'; i.name=d.name; i.value=d.value; form.appendChild(i); });
  document.body.appendChild(form); form.submit();
}

function unverifyPayment(userId, year, month) {
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = window.location.href.split('?')[0] + '?year=<?php echo $current_year; ?>&filter=<?php echo $filter; ?>';
  const inputs = [
    {name:'unverify_payment',value:'1'},{name:'token',value:'<?php echo csrf_token(); ?>'},
    {name:'user_id',value:userId},{name:'month',value:month},{name:'year',value:year}
  ];
  inputs.forEach(d => { const i = document.createElement('input'); i.type='hidden'; i.name=d.name; i.value=d.value; form.appendChild(i); });
  document.body.appendChild(form); form.submit();
}

function openModal(roomNumber, currentCapacity) {
  document.getElementById('modalRoomNumber').value = roomNumber;
  document.getElementById('modalRoomDisplay').textContent = 'Room ' + roomNumber;
  document.querySelectorAll('.modal-option').forEach(el => el.classList.remove('selected'));
  let priceValue = '1500';
  if (currentCapacity == 1) priceValue = '5000';
  else if (currentCapacity == 2) priceValue = '2500';
  else if (currentCapacity == 3) priceValue = '2000';
  else if (currentCapacity == 4) priceValue = '1500';
  document.querySelector('input[name="price_option"][value="' + priceValue + '"]').checked = true;
  document.querySelector('input[name="price_option"][value="' + priceValue + '"]').closest('.modal-option').classList.add('selected');
  document.getElementById('capacityModal').classList.add('active');
}

function closeModal() {
  document.getElementById('capacityModal').classList.remove('active');
}

document.querySelectorAll('.modal-option input[type="radio"]').forEach(radio => {
  radio.addEventListener('change', function() {
    document.querySelectorAll('.modal-option').forEach(el => el.classList.remove('selected'));
    this.closest('.modal-option').classList.add('selected');
  });
});

document.getElementById('capacityModal').addEventListener('click', function(e) {
  if (e.target === this) closeModal();
});
document.getElementById('paymentModal').addEventListener('click', function(e) {
  if (e.target === this) closePaymentModal();
});

document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    closeModal();
    closePaymentModal();
  }
});
</script>

</body>
</html>