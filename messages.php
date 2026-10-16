<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'owner') {
    header('Location: login.php');
    exit();
}

require_once 'config.php';

if (isset($_POST['mark_read']) || isset($_POST['delete_messages']) || isset($_POST['send_reply']) || isset($_POST['compose_message'])) {
    csrf_check();
}

$stmt = $pdo->query("SELECT id FROM users WHERE role = 'owner' LIMIT 1");
$owner = $stmt->fetch();
$owner_id = $owner ? $owner['id'] : $_SESSION['user_id'];

$current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'messages';
$message = '';
$message_type = '';

if (isset($_GET['msg_id'])) {
    $stmt = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE id = ? AND receiver_id = ?");
    $stmt->execute([$_GET['msg_id'], $owner_id]);
}

if (isset($_POST['mark_read']) && isset($_POST['selected_messages'])) {
    $selected = $_POST['selected_messages'];
    $placeholders = implode(',', array_fill(0, count($selected), '?'));
    $stmt = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE id IN ($placeholders) AND receiver_id = ?");
    $stmt->execute(array_merge($selected, [$owner_id]));
    $message = "Messages marked as read.";
}

if (isset($_POST['delete_messages']) && isset($_POST['selected_messages'])) {
    $selected = $_POST['selected_messages'];
    foreach ($selected as $msg_id) {
        $stmt = $pdo->prepare("SELECT * FROM messages WHERE id = ?");
        $stmt->execute([$msg_id]);
        $msg = $stmt->fetch();
        if ($msg) {
            $stmt = $pdo->prepare("INSERT INTO message_trash (original_id, sender_id, receiver_id, sender_role, message_type, subject, message, deleted_by) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?, 'receiver')");
            $stmt->execute([$msg_id, $msg['sender_id'], $msg['receiver_id'], $msg['sender_role'], 
                           $msg['message_type'], $msg['subject'], $msg['message']]);
            $stmt = $pdo->prepare("DELETE FROM messages WHERE id = ?");
            $stmt->execute([$msg_id]);
        }
    }
    $message = "Messages moved to trash.";
}

if (isset($_POST['send_reply'])) {
    $reply_to = $_POST['reply_to'];
    $subject = $_POST['subject'];
    $message_text = $_POST['message'];
    $stmt = $pdo->prepare("SELECT * FROM messages WHERE id = ?");
    $stmt->execute([$reply_to]);
    $original = $stmt->fetch();
    if ($original) {
        $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, sender_role, message_type, subject, message) 
                               VALUES (?, ?, 'owner', ?, ?, ?)");
        $stmt->execute([$owner_id, $original['sender_id'], 
                       $original['message_type'], 
                       'Re: ' . $original['subject'], $message_text]);
        $message = "Reply sent.";
    }
}

if (isset($_POST['compose_message'])) {
    $recipient_type = $_POST['recipient_type'];
    $subject = $_POST['subject'];
    $message_text = $_POST['message'];
    
    if ($recipient_type == 'tenants') {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'tenant' AND is_deleted = 0");
        $stmt->execute();
        $recipients = $stmt->fetchAll();
        foreach ($recipients as $recipient) {
            $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, sender_role, message_type, subject, message) 
                                   VALUES (?, ?, 'owner', 'message', ?, ?)");
            $stmt->execute([$owner_id, $recipient['id'], $subject, $message_text]);
        }
        $message = "Message sent to all tenants.";
    } elseif (preg_match('/^[AB][1-7]$/', $recipient_type)) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email LIKE ? AND role = 'tenant' AND is_deleted = 0");
        $stmt->execute([$recipient_type . '%']);
        $recipients = $stmt->fetchAll();
        foreach ($recipients as $recipient) {
            $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, sender_role, message_type, subject, message) 
                                   VALUES (?, ?, 'owner', 'message', ?, ?)");
            $stmt->execute([$owner_id, $recipient['id'], $subject, $message_text]);
        }
        $message = "Message sent to Room " . $recipient_type . " tenants.";
    }
}

$message_type_filter = '';
if ($current_tab == 'messages') $message_type_filter = 'message';
elseif ($current_tab == 'complaints') $message_type_filter = 'complaint';

$messages = [];
$stmt = $pdo->prepare("SELECT m.*, u.username as sender_name 
                       FROM messages m 
                       JOIN users u ON m.sender_id = u.id 
                       WHERE (m.receiver_id = ? OR m.sender_id = ?) AND m.message_type = ?
                       ORDER BY m.created_at DESC");
$stmt->execute([$owner_id, $owner_id, $message_type_filter]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT message_type, COUNT(*) as count, 
                       SUM(CASE WHEN is_read = 0 AND receiver_id = ? THEN 1 ELSE 0 END) as unread 
                       FROM messages WHERE receiver_id = ? GROUP BY message_type");
$stmt->execute([$owner_id, $owner_id]);
$counts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$message_unread = 0;
$complaint_unread = 0;
foreach ($counts as $c) {
    if ($c['message_type'] == 'message') $message_unread = $c['unread'];
    elseif ($c['message_type'] == 'complaint') $complaint_unread = $c['unread'];
}

$selected_message = null;
if (isset($_GET['msg_id'])) {
    $stmt = $pdo->prepare("SELECT m.*, u.username as sender_name 
                           FROM messages m 
                           JOIN users u ON m.sender_id = u.id 
                           WHERE m.id = ?");
    $stmt->execute([$_GET['msg_id']]);
    $selected_message = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Messages — Dannielle's Apartment</title>
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
  padding: 40px 20px 80px;
}
.video-background, .overlay, .unmute-btn { display: none !important; }

.navbar {
  max-width: 1200px;
  margin: 0 auto 30px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 20px;
  flex-wrap: wrap;
}
.brand-text {
  font-family: var(--font-display);
  font-weight: 600;
  font-size: 0.95rem;
  letter-spacing: 0.18em;
  color: var(--cream);
  white-space: nowrap;
}
.btn-nav {
  font-family: var(--font-display);
  font-size: 0.7rem;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  padding: 10px 18px;
  border-radius: 2px;
  text-decoration: none;
  border: 1px solid rgba(237,227,209,0.4);
  color: var(--cream);
  transition: all .25s ease;
  white-space: nowrap;
  background: transparent;
}
.btn-nav:hover { background: var(--cream); color: var(--ink); }

.wrap {
  max-width: 1200px;
  margin: 0 auto;
  background: var(--bg-card);
  border: 1px solid var(--line);
  border-radius: 4px;
  box-shadow: 0 30px 60px -20px rgba(0,0,0,0.6);
  padding: 44px 40px 40px;
}
.page-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-end;
  margin-bottom: 30px;
  padding-bottom: 24px;
  border-bottom: 1px solid var(--line);
  flex-wrap: wrap;
  gap: 16px;
}
.page-head h1 {
  font-family: var(--font-display);
  font-weight: 300;
  font-size: 2rem;
  margin: 0;
}
.eyebrow {
  font-family: var(--font-body);
  font-weight: 700;
  font-size: 0.66rem;
  letter-spacing: 0.28em;
  text-transform: uppercase;
  color: var(--brass);
  margin: 0 0 8px;
}

.msg {
  padding: 14px 16px;
  border-radius: 2px;
  font-size: 0.9rem;
  margin-bottom: 22px;
  background: rgba(107,79,42,0.08);
  color: var(--brass);
  border-left: 3px solid var(--brass);
}

.tabs { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
.tab-btn {
  font-family: var(--font-display);
  font-size: 0.72rem;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  padding: 10px 18px;
  border: 1px solid var(--line);
  border-radius: 2px;
  background: transparent;
  color: var(--ink-soft);
  cursor: pointer;
  transition: all .2s ease;
}
.tab-btn:hover { border-color: var(--brass-light); color: var(--ink); }
.tab-btn.active { background: var(--brass); color: var(--bg-card); border-color: var(--brass); }
.tab-badge {
  display: inline-block;
  background: var(--clay);
  color: var(--bg-card);
  font-size: 0.62rem;
  padding: 1px 6px;
  border-radius: 8px;
  margin-left: 6px;
}

.actions-bar { display: flex; gap: 8px; margin-bottom: 20px; flex-wrap: wrap; }
.action-btn {
  font-family: var(--font-display);
  font-size: 0.68rem;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  padding: 9px 16px;
  border: 1px solid var(--line);
  border-radius: 2px;
  background: transparent;
  color: var(--ink-soft);
  cursor: pointer;
  transition: all .2s ease;
}
.action-btn:hover { border-color: var(--brass); color: var(--brass); }
.action-btn.primary { background: var(--brass); color: var(--bg-card); border-color: var(--brass); }
.action-btn.primary:hover { background: transparent; color: var(--brass); }

table.messages {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.85rem;
}
table.messages thead th {
  font-family: var(--font-display);
  font-weight: 500;
  font-size: 0.66rem;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: var(--ink-soft);
  text-align: left;
  padding: 12px 10px;
  border-bottom: 1px solid var(--line);
  white-space: nowrap;
}
table.messages tbody td {
  padding: 12px 10px;
  border-bottom: 1px solid var(--line);
  color: var(--ink);
  vertical-align: middle;
}
table.messages tbody tr { cursor: pointer; transition: background .15s ease; }
table.messages tbody tr:hover { background: rgba(107,79,42,0.04); }
table.messages tbody tr.unread td { font-weight: 500; }
table.messages tbody tr.unread .message-subject { color: var(--brass); }

.message-subject { font-weight: 500; margin-bottom: 2px; }
.message-preview { font-size: 0.78rem; color: var(--ink-soft); font-weight: 400; }
.message-date { color: var(--ink-soft); font-size: 0.78rem; white-space: nowrap; }
.message-checkbox { width: 32px; }
.message-checkbox input { accent-color: var(--brass); }
.status-unread {
  font-family: var(--font-display);
  font-size: 0.6rem;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  padding: 3px 8px;
  border-radius: 2px;
  background: rgba(201,168,118,0.25);
  color: #8B6F3D;
}
.status-read {
  font-family: var(--font-display);
  font-size: 0.6rem;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  padding: 3px 8px;
  border-radius: 2px;
  background: rgba(42,33,24,0.08);
  color: var(--ink-soft);
}
.empty-state { text-align: center; padding: 50px 20px; color: var(--ink-soft); font-size: 0.9rem; }

.preview-panel {
  margin-top: 30px;
  padding: 24px;
  border: 1px solid var(--line);
  border-radius: 2px;
  background: rgba(246,241,230,0.5);
}
.preview-header h3 {
  font-family: var(--font-display);
  font-weight: 400;
  font-size: 1.2rem;
  margin: 0 0 16px;
}
.preview-meta p { margin: 6px 0; font-size: 0.82rem; color: var(--ink-soft); }
.preview-meta strong { color: var(--ink); font-weight: 600; }
.preview-message {
  margin: 18px 0;
  padding: 18px;
  background: var(--bg-card);
  border-left: 3px solid var(--brass-light);
  border-radius: 2px;
  font-size: 0.9rem;
  line-height: 1.7;
}
.preview-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.preview-btn {
  font-family: var(--font-display);
  font-size: 0.68rem;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  padding: 10px 18px;
  border: 1px solid;
  border-radius: 2px;
  cursor: pointer;
  background: transparent;
  transition: all .2s ease;
}
.reply-btn { color: var(--brass); border-color: var(--brass); }
.reply-btn:hover { background: var(--brass); color: var(--bg-card); }
.delete-btn { color: var(--clay); border-color: var(--clay); }
.delete-btn:hover { background: var(--clay); color: var(--bg-card); }

.modal {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(14,14,14,0.75);
  backdrop-filter: blur(10px);
  z-index: 999;
  justify-content: center;
  align-items: center;
  padding: 20px;
}
.modal-content {
  background: var(--bg-card);
  border: 1px solid var(--line);
  border-radius: 4px;
  padding: 30px 28px;
  max-width: 520px;
  width: 100%;
  max-height: 90vh;
  overflow-y: auto;
}
.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
  padding-bottom: 14px;
  border-bottom: 1px solid var(--line);
}
.modal-header h3 {
  margin: 0;
  font-family: var(--font-display);
  font-weight: 300;
  font-size: 1.3rem;
}
.close-modal {
  background: none;
  border: none;
  font-size: 22px;
  cursor: pointer;
  color: var(--ink-soft);
}
.close-modal:hover { color: var(--clay); }
.form-group { margin-bottom: 16px; }
.form-group label {
  display: block;
  font-family: var(--font-display);
  font-size: 0.68rem;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--ink-soft);
  margin-bottom: 8px;
}
.form-group input,
.form-group select,
.form-group textarea {
  width: 100%;
  padding: 12px 14px;
  border: 1px solid var(--line);
  background: rgba(246,241,230,0.6);
  border-radius: 2px;
  font-family: var(--font-body);
  font-size: 0.9rem;
  color: var(--ink);
}
.form-group textarea { resize: vertical; min-height: 120px; }
.form-group input:focus, .form-group select:focus, .form-group textarea:focus {
  outline: none;
  border-color: var(--brass);
  background: var(--bg-card);
}
.modal-actions {
  display: flex;
  gap: 10px;
  justify-content: flex-end;
  margin-top: 22px;
  padding-top: 18px;
  border-top: 1px solid var(--line);
}
.modal-btn {
  font-family: var(--font-display);
  font-size: 0.72rem;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  padding: 11px 22px;
  border-radius: 2px;
  border: 1px solid;
  cursor: pointer;
  transition: all .2s ease;
}
.cancel-btn { background: transparent; color: var(--ink-soft); border-color: var(--line); }
.cancel-btn:hover { background: var(--ink-soft); color: var(--bg-card); }
.send-btn { background: var(--brass); color: var(--bg-card); border-color: var(--brass); }
.send-btn:hover { background: transparent; color: var(--brass); }

@media (max-width: 900px) {
  .wrap { padding: 30px 20px; }
  .page-head h1 { font-size: 1.5rem; }
  table.messages { font-size: 0.78rem; }
  table.messages thead th, table.messages tbody td { padding: 8px 6px; }
  .message-preview { display: none; }
}
</style>
</head>
<body>

<div class="navbar">
  <span class="brand-text">✦ DANIELLE'S APARTMENT</span>
  <a href="owner_dashboard.php" class="btn-nav">← Back to Dashboard</a>
</div>

<div class="wrap">

  <div class="page-head">
    <div>
      <p class="eyebrow">Inbox</p>
      <h1>Messages</h1>
    </div>
  </div>

  <?php if ($message): ?>
    <div class="msg"><?php echo $message; ?></div>
  <?php endif; ?>

  <div class="tabs">
    <button class="tab-btn <?php echo $current_tab == 'messages' ? 'active' : ''; ?>" onclick="window.location.href='?tab=messages'">
      Messages <?php if ($message_unread > 0): ?><span class="tab-badge"><?php echo $message_unread; ?></span><?php endif; ?>
    </button>
    <button class="tab-btn <?php echo $current_tab == 'complaints' ? 'active' : ''; ?>" onclick="window.location.href='?tab=complaints'">
      Complaints <?php if ($complaint_unread > 0): ?><span class="tab-badge"><?php echo $complaint_unread; ?></span><?php endif; ?>
    </button>
  </div>

  <div class="actions-bar">
    <button class="action-btn primary" onclick="openComposeModal()">Compose</button>
    <button class="action-btn" onclick="markSelectedRead()">Mark as Read</button>
    <button class="action-btn" onclick="deleteSelected()">Move to Trash</button>
  </div>

  <form id="messagesForm" method="POST">
    <input type="hidden" name="token" value="<?php echo csrf_token(); ?>">
    <table class="messages">
      <thead>
        <tr>
          <th class="message-checkbox"><input type="checkbox" id="selectAll"></th>
          <th>From</th>
          <th>Subject</th>
          <th>Date</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php if (count($messages) > 0): ?>
          <?php foreach ($messages as $msg): ?>
            <tr class="<?php echo (!$msg['is_read'] && $msg['receiver_id'] == $owner_id) ? 'unread' : ''; ?>" 
                onclick="window.location.href='?tab=<?php echo $current_tab; ?>&msg_id=<?php echo $msg['id']; ?>'">
              <td class="message-checkbox" onclick="event.stopPropagation()">
                <input type="checkbox" name="selected_messages[]" value="<?php echo $msg['id']; ?>" class="message-check">
              </td>
              <td>
                <?php 
                if ($msg['sender_role'] == 'owner') echo 'You';
                else echo htmlspecialchars($msg['sender_name']);
                ?>
              </td>
              <td>
                <div class="message-subject"><?php echo htmlspecialchars($msg['subject'] ?: 'No subject'); ?></div>
                <div class="message-preview"><?php echo htmlspecialchars(substr($msg['message'], 0, 70)); ?>...</div>
              </td>
              <td class="message-date"><?php echo date('M d, Y', strtotime($msg['created_at'])); ?></td>
              <td>
                <?php if (!$msg['is_read'] && $msg['receiver_id'] == $owner_id): ?>
                  <span class="status-unread">New</span>
                <?php else: ?>
                  <span class="status-read">Read</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr>
            <td colspan="5" class="empty-state">No messages in this folder.</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
    <input type="hidden" name="mark_read" id="markReadInput">
    <input type="hidden" name="delete_messages" id="deleteMessagesInput">
  </form>

  <?php if ($selected_message): ?>
    <div class="preview-panel">
      <div class="preview-header">
        <h3><?php echo htmlspecialchars($selected_message['subject'] ?: 'No subject'); ?></h3>
      </div>
      <div class="preview-meta">
        <p><strong>From:</strong> 
          <?php 
          if ($selected_message['sender_role'] == 'owner') echo 'You';
          else echo htmlspecialchars($selected_message['sender_name']);
          ?>
        </p>
        <p><strong>To:</strong> You (Owner)</p>
        <p><strong>Date:</strong> <?php echo date('F j, Y g:i A', strtotime($selected_message['created_at'])); ?></p>
        <p><strong>Type:</strong> 
          <?php 
          if ($selected_message['message_type'] == 'complaint') echo 'Complaint';
          else echo 'General Message';
          ?>
        </p>
      </div>
      <div class="preview-message">
        <?php echo nl2br(htmlspecialchars($selected_message['message'])); ?>
      </div>
      <div class="preview-actions">
        <button class="preview-btn reply-btn" onclick="openReplyModal(<?php echo $selected_message['id']; ?>, '<?php echo htmlspecialchars($selected_message['subject']); ?>')">Reply</button>
        <button class="preview-btn delete-btn" onclick="deleteSingle(<?php echo $selected_message['id']; ?>)">Delete</button>
      </div>
    </div>
  <?php endif; ?>
</div>

<div id="composeModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h3>Compose Message</h3>
      <button class="close-modal" onclick="closeComposeModal()">✕</button>
    </div>
    <form method="POST" action="">
      <input type="hidden" name="token" value="<?php echo csrf_token(); ?>">
      <div class="form-group">
        <label>Send to</label>
        <select name="recipient_type" required>
          <option value="tenants">All Tenants</option>
          <option value="A1">Room A1</option>
          <option value="A2">Room A2</option>
          <option value="A3">Room A3</option>
          <option value="A4">Room A4</option>
          <option value="A5">Room A5</option>
          <option value="A6">Room A6</option>
          <option value="A7">Room A7</option>
          <option value="B1">Room B1</option>
          <option value="B2">Room B2</option>
          <option value="B3">Room B3</option>
          <option value="B4">Room B4</option>
          <option value="B5">Room B5</option>
          <option value="B6">Room B6</option>
          <option value="B7">Room B7</option>
        </select>
      </div>
      <div class="form-group">
        <label>Subject</label>
        <input type="text" name="subject" required>
      </div>
      <div class="form-group">
        <label>Message</label>
        <textarea name="message" rows="6" required></textarea>
      </div>
      <div class="modal-actions">
        <button type="button" class="modal-btn cancel-btn" onclick="closeComposeModal()">Cancel</button>
        <button type="submit" name="compose_message" class="modal-btn send-btn">Send</button>
      </div>
    </form>
  </div>
</div>

<div id="replyModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h3>Reply</h3>
      <button class="close-modal" onclick="closeReplyModal()">✕</button>
    </div>
    <form method="POST" action="" id="replyForm">
      <input type="hidden" name="token" value="<?php echo csrf_token(); ?>">
      <input type="hidden" name="reply_to" id="reply_to">
      <div class="form-group">
        <label>Subject</label>
        <input type="text" name="subject" id="reply_subject" required>
      </div>
      <div class="form-group">
        <label>Message</label>
        <textarea name="message" rows="6" required></textarea>
      </div>
      <div class="modal-actions">
        <button type="button" class="modal-btn cancel-btn" onclick="closeReplyModal()">Cancel</button>
        <button type="submit" name="send_reply" class="modal-btn send-btn">Send Reply</button>
      </div>
    </form>
  </div>
</div>

<script>
document.getElementById('selectAll')?.addEventListener('change', function() {
    document.querySelectorAll('.message-check').forEach(cb => cb.checked = this.checked);
});

function markSelectedRead() {
    const selected = document.querySelectorAll('.message-check:checked');
    if (selected.length === 0) { alert('Please select at least one message'); return; }
    if (confirm('Mark selected messages as read?')) {
        document.getElementById('markReadInput').value = '1';
        document.getElementById('messagesForm').submit();
    }
}

function deleteSelected() {
    const selected = document.querySelectorAll('.message-check:checked');
    if (selected.length === 0) { alert('Please select at least one message'); return; }
    if (confirm('Move selected messages to trash?')) {
        document.getElementById('deleteMessagesInput').value = '1';
        document.getElementById('messagesForm').submit();
    }
}

function deleteSingle(msgId) {
    if (confirm('Move this message to trash?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = window.location.href.split('?')[0] + '?tab=<?php echo $current_tab; ?>';
        const token = document.createElement('input');
        token.type = 'hidden'; token.name = 'token';
        token.value = '<?php echo csrf_token(); ?>';
        form.appendChild(token);
        const input = document.createElement('input');
        input.type = 'hidden'; input.name = 'selected_messages[]';
        input.value = msgId;
        const deleteInput = document.createElement('input');
        deleteInput.type = 'hidden'; deleteInput.name = 'delete_messages';
        deleteInput.value = '1';
        form.appendChild(input); form.appendChild(deleteInput);
        document.body.appendChild(form); form.submit();
    }
}

function openComposeModal() { document.getElementById('composeModal').style.display = 'flex'; }
function closeComposeModal() { document.getElementById('composeModal').style.display = 'none'; }
function openReplyModal(msgId, subject) {
    document.getElementById('reply_to').value = msgId;
    document.getElementById('reply_subject').value = 'Re: ' + subject;
    document.getElementById('replyModal').style.display = 'flex';
}
function closeReplyModal() { document.getElementById('replyModal').style.display = 'none'; }

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) event.target.style.display = 'none';
}
</script>

</body>
</html>