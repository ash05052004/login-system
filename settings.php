<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

require_once 'config.php';

$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (isset($_GET['disconnect'])) {
    $stmt = $pdo->prepare("UPDATE users SET telegram_chat_id = NULL WHERE id = ?");
    $stmt->execute([$user_id]);
    header("Location: settings.php?msg=disconnected");
    exit();
}

$message = '';
if (isset($_GET['msg'])) {
    if ($_GET['msg'] == 'disconnected') $message = 'Telegram disconnected';
}

$bot_username = 'DBtheApartmentBot';
$connect_url = "https://t.me/$bot_username?start=USER_$user_id";

$back_url = $_SESSION['role'] == 'owner' ? 'owner_dashboard.php' : 'tenant_dashboard.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Settings — Dannielle's Apartment</title>
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
  min-height: 100vh;
  font-family: var(--font-body);
  color: var(--ink);
  background:
    linear-gradient(180deg, rgba(14,14,14,0.65) 0%, rgba(14,14,14,0.85) 100%),
    url("Images/Hall.jpg") center / cover no-repeat fixed;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 40px 20px;
}

.settings-card {
  width: 100%;
  max-width: 520px;
  background: var(--bg-card);
  border: 1px solid var(--line);
  border-radius: 4px;
  box-shadow: 0 30px 60px -20px rgba(0,0,0,0.6);
  padding: 44px 40px 36px;
}

.page-head {
  padding-bottom: 22px;
  margin-bottom: 28px;
  border-bottom: 1px solid var(--line);
}
.eyebrow {
  font-family: var(--font-body);
  font-weight: 700;
  font-size: 0.66rem;
  letter-spacing: 0.28em;
  text-transform: uppercase;
  color: var(--brass);
  margin: 0 0 6px;
}
.page-head h1 {
  font-family: var(--font-display);
  font-weight: 300;
  font-size: 1.9rem;
  margin: 0;
  color: var(--ink);
}

.msg {
  padding: 14px 16px;
  border-radius: 2px;
  background: rgba(107,79,42,0.08);
  color: var(--brass);
  border-left: 3px solid var(--brass);
  margin-bottom: 22px;
  font-size: 0.9rem;
}

.info-row {
  display: flex;
  justify-content: space-between;
  padding: 12px 0;
  border-bottom: 1px dashed var(--line);
  font-size: 0.9rem;
}
.info-row .label {
  font-family: var(--font-display);
  font-size: 0.7rem;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--ink-soft);
}
.info-row .value {
  color: var(--ink);
  font-weight: 500;
}

.section-title {
  font-family: var(--font-display);
  font-weight: 400;
  font-size: 0.9rem;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--ink-soft);
  margin: 32px 0 16px;
  padding-bottom: 10px;
  border-bottom: 1px solid var(--line);
}

.telegram-box {
  background: rgba(246,241,230,0.6);
  border: 1px solid var(--line);
  border-radius: 2px;
  padding: 24px;
  text-align: center;
}
.telegram-box .tg-title {
  font-family: var(--font-display);
  font-weight: 500;
  font-size: 1.05rem;
  color: var(--ink);
  margin: 0 0 8px;
}
.telegram-box .tg-desc {
  color: var(--ink-soft);
  font-size: 0.85rem;
  line-height: 1.6;
  margin: 0 0 20px;
}
.connected-status {
  display: inline-block;
  font-family: var(--font-display);
  font-size: 0.72rem;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  padding: 6px 14px;
  background: rgba(107,79,42,0.12);
  color: var(--brass);
  border-radius: 2px;
  margin-bottom: 12px;
}

.btn-primary {
  display: inline-block;
  font-family: var(--font-display);
  font-size: 0.75rem;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  padding: 13px 28px;
  background: var(--brass);
  color: var(--bg-card);
  border: 1px solid var(--brass);
  border-radius: 2px;
  text-decoration: none;
  cursor: pointer;
  transition: all .2s ease;
}
.btn-primary:hover { background: transparent; color: var(--brass); }

.btn-danger {
  display: inline-block;
  font-family: var(--font-display);
  font-size: 0.7rem;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  padding: 11px 22px;
  background: transparent;
  color: var(--clay);
  border: 1px solid var(--clay);
  border-radius: 2px;
  text-decoration: none;
  cursor: pointer;
  transition: all .2s ease;
}
.btn-danger:hover { background: var(--clay); color: var(--bg-card); }

.tg-hint {
  font-size: 0.72rem;
  color: var(--ink-soft);
  margin-top: 14px;
  font-style: italic;
}

.back-link {
  display: inline-block;
  font-family: var(--font-display);
  font-size: 0.7rem;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  padding: 10px 20px;
  border: 1px solid var(--ink-soft);
  border-radius: 2px;
  color: var(--ink-soft);
  text-decoration: none;
  margin-top: 30px;
  transition: all .2s ease;
}
.back-link:hover { background: var(--ink-soft); color: var(--bg-card); }

@media (max-width: 600px) {
  .settings-card { padding: 30px 24px; }
  .page-head h1 { font-size: 1.5rem; }
}
</style>
</head>
<body>

<div class="settings-card">

  <div class="page-head">
    <p class="eyebrow">Account</p>
    <h1>Settings</h1>
  </div>

  <?php if ($message): ?>
    <div class="msg"><?php echo $message; ?></div>
  <?php endif; ?>

  <div class="info-row">
    <span class="label">Logged in as</span>
    <span class="value"><?php echo htmlspecialchars($user['email']); ?></span>
  </div>
  <div class="info-row">
    <span class="label">Role</span>
    <span class="value"><?php echo ucfirst($user['role']); ?></span>
  </div>

  <h2 class="section-title">Telegram Notifications</h2>

  <div class="telegram-box">
    <?php if (!empty($user['telegram_chat_id'])): ?>
      <div class="connected-status">Connected</div>
      <p class="tg-title">You're all set</p>
      <p class="tg-desc">You'll receive payment reminders, appointment updates, and messages directly on your phone.</p>
      <a href="?disconnect=1" class="btn-danger" onclick="return confirm('Disconnect Telegram?')">Disconnect</a>
    <?php else: ?>
      <p class="tg-title">Get instant notifications</p>
      <p class="tg-desc">Receive payment reminders, appointment updates, and messages on your phone.</p>
      <a href="<?php echo $connect_url; ?>" target="_blank" class="btn-primary">Connect Telegram</a>
      <p class="tg-hint">This opens Telegram. Click START to connect.</p>
    <?php endif; ?>
  </div>

  <a href="<?php echo $back_url; ?>" class="back-link">← Back to Dashboard</a>

</div>

</body>
</html>