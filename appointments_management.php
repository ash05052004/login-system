<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'owner') {
    header('Location: login.php');
    exit();
}

require_once 'config.php';

if (isset($_GET['approve']) || isset($_GET['reject']) || isset($_GET['complete']) || isset($_GET['delete']) || isset($_GET['restore']) || isset($_GET['permanent_delete'])) {
    csrf_check();
}

$filter = isset($_GET['filter']) ? $_GET['filter'] : 'pending';

if (isset($_GET['approve'])) {
    $appointment_id = $_GET['approve'];
    $stmt = $pdo->prepare("SELECT * FROM appointments WHERE id = ?");
    $stmt->execute([$appointment_id]);
    $apt = $stmt->fetch();
    $stmt = $pdo->prepare("UPDATE appointments SET status = 'confirmed' WHERE id = ?");
    $stmt->execute([$appointment_id]);
    if ($apt) {
        require_once 'notification_helper.php';
        $date = date('F j, Y', strtotime($apt['visit_date']));
        $time = date('g:i A', strtotime($apt['visit_time']));
        notifyAppointmentApproved($apt['user_id'], $date, $time);
    }
    header("Location: appointments_management.php?filter=$filter&msg=confirmed");
    exit();
}

if (isset($_GET['reject'])) {
    $appointment_id = $_GET['reject'];
    $stmt = $pdo->prepare("SELECT * FROM appointments WHERE id = ?");
    $stmt->execute([$appointment_id]);
    $apt = $stmt->fetch();
    $stmt = $pdo->prepare("UPDATE appointments SET status = 'cancelled' WHERE id = ?");
    $stmt->execute([$appointment_id]);
    if ($apt) {
        require_once 'notification_helper.php';
        $date = date('F j, Y', strtotime($apt['visit_date']));
        notifyAppointmentRejected($apt['user_id'], $date);
    }
    header("Location: appointments_management.php?filter=$filter&msg=cancelled");
    exit();
}

if (isset($_GET['complete'])) {
    $appointment_id = $_GET['complete'];
    $stmt = $pdo->prepare("UPDATE appointments SET status = 'completed' WHERE id = ?");
    $stmt->execute([$appointment_id]);
    header("Location: appointments_management.php?filter=$filter&msg=completed");
    exit();
}

if (isset($_GET['delete'])) {
    $appointment_id = $_GET['delete'];
    $stmt = $pdo->prepare("UPDATE appointments SET status = 'trash' WHERE id = ?");
    $stmt->execute([$appointment_id]);
    header("Location: appointments_management.php?filter=$filter&msg=deleted");
    exit();
}

if (isset($_GET['restore'])) {
    $appointment_id = $_GET['restore'];
    $stmt = $pdo->prepare("UPDATE appointments SET status = 'pending' WHERE id = ?");
    $stmt->execute([$appointment_id]);
    header("Location: appointments_management.php?filter=trash&msg=restored");
    exit();
}

if (isset($_GET['permanent_delete'])) {
    $appointment_id = $_GET['permanent_delete'];
    $stmt = $pdo->prepare("DELETE FROM appointments WHERE id = ?");
    $stmt->execute([$appointment_id]);
    header("Location: appointments_management.php?filter=trash&msg=permanent_deleted");
    exit();
}

$sql = "SELECT a.*, u.username as user_email 
        FROM appointments a 
        JOIN users u ON a.user_id = u.id";

if ($filter == 'trash') {
    $sql .= " WHERE a.status = 'trash'";
} elseif ($filter != 'all') {
    $sql .= " WHERE a.status = :filter";
}

$sql .= " ORDER BY a.visit_date DESC, a.visit_time DESC";

$stmt = $pdo->prepare($sql);
if ($filter != 'all' && $filter != 'trash') {
    $stmt->bindParam(':filter', $filter);
}
$stmt->execute();
$appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->query("SELECT status, COUNT(*) as count FROM appointments GROUP BY status");
$counts = [];
while ($row = $stmt->fetch()) {
    $counts[$row['status']] = $row['count'];
}

$pending_count = $counts['pending'] ?? 0;
$confirmed_count = $counts['confirmed'] ?? 0;
$completed_count = $counts['completed'] ?? 0;
$cancelled_count = $counts['cancelled'] ?? 0;
$trash_count = $counts['trash'] ?? 0;
$total_count = $pending_count + $confirmed_count + $completed_count + $cancelled_count;

$message = '';
if (isset($_GET['msg'])) {
    if ($_GET['msg'] == 'confirmed') $message = "Appointment confirmed.";
    if ($_GET['msg'] == 'cancelled') $message = "Appointment cancelled.";
    if ($_GET['msg'] == 'completed') $message = "Appointment marked as completed.";
    if ($_GET['msg'] == 'deleted') $message = "Appointment moved to trash.";
    if ($_GET['msg'] == 'restored') $message = "Appointment restored from trash.";
    if ($_GET['msg'] == 'permanent_deleted') $message = "Appointment permanently deleted.";
}

$view_appointment = null;
if (isset($_GET['view'])) {
    $app_id = $_GET['view'];
    $stmt = $pdo->prepare("SELECT * FROM appointments WHERE id = ?");
    $stmt->execute([$app_id]);
    $view_appointment = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Appointment Management — Dannielle's Apartment</title>
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

.navbar {
  max-width: 1400px;
  margin: 0 auto 30px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 20px;
  flex-wrap: wrap;
}
.navbar .brand-text {
  font-family: var(--font-display);
  font-weight: 600;
  font-size: 0.95rem;
  letter-spacing: 0.18em;
  color: var(--cream);
}
.nav-actions { display: flex; gap: 12px; flex-wrap: wrap; }
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
.btn-nav.logout { border-color: rgba(139,74,43,0.6); color: #E5A08B; }
.btn-nav.logout:hover { background: var(--clay); color: var(--cream); border-color: var(--clay); }

.wrap {
  max-width: 1400px;
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

.filters { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 24px; }
.filter-btn {
  font-family: var(--font-display);
  font-size: 0.68rem;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  padding: 8px 14px;
  border: 1px solid var(--line);
  border-radius: 2px;
  color: var(--ink-soft);
  transition: all .2s ease;
  text-decoration: none;
}
.filter-btn:hover { border-color: var(--brass-light); color: var(--ink); }
.filter-btn.active { background: var(--brass); color: var(--bg-card); border-color: var(--brass); }

.table-responsive { overflow-x: auto; }
table.appointments {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.85rem;
}
table.appointments thead th {
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
table.appointments tbody td {
  padding: 12px 10px;
  border-bottom: 1px solid var(--line);
  color: var(--ink);
  vertical-align: middle;
}
table.appointments tbody tr:hover { background: rgba(107,79,42,0.03); }
table.appointments .trash-row { opacity: 0.6; }

.status-badge {
  font-family: var(--font-display);
  font-size: 0.62rem;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  padding: 3px 9px;
  border-radius: 2px;
  white-space: nowrap;
}
.status-pending   { background: rgba(201,168,118,0.25); color: #8B6F3D; }
.status-confirmed { background: rgba(107,79,42,0.12); color: var(--brass); }
.status-completed { background: rgba(42,33,24,0.08); color: var(--ink-soft); }
.status-cancelled { background: rgba(139,74,43,0.15); color: var(--clay); }
.status-trash     { background: rgba(42,33,24,0.1); color: var(--ink-soft); }

.actions { display: flex; gap: 6px; flex-wrap: wrap; }
.actions a {
  font-family: var(--font-display);
  font-size: 0.62rem;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  padding: 6px 10px;
  border-radius: 2px;
  text-decoration: none;
  border: 1px solid;
  transition: all .2s ease;
  white-space: nowrap;
}
.btn-view    { background: transparent; color: var(--ink-soft); border-color: var(--line); }
.btn-view:hover { background: var(--ink-soft); color: var(--bg-card); border-color: var(--ink-soft); }
.btn-approve { background: var(--brass); color: var(--bg-card); border-color: var(--brass); }
.btn-approve:hover { background: transparent; color: var(--brass); }
.btn-reject  { background: transparent; color: var(--clay); border-color: var(--clay); }
.btn-reject:hover { background: var(--clay); color: var(--bg-card); }
.btn-complete { background: var(--brass-light); color: var(--ink); border-color: var(--brass-light); }
.btn-complete:hover { background: transparent; color: var(--brass); border-color: var(--brass); }
.btn-trash { background: transparent; color: var(--ink-soft); border-color: var(--line); }
.btn-trash:hover { background: var(--ink-soft); color: var(--bg-card); border-color: var(--ink-soft); }
.btn-restore { background: var(--brass-light); color: var(--ink); border-color: var(--brass-light); }
.btn-restore:hover { background: var(--brass); color: var(--bg-card); border-color: var(--brass); }
.btn-delete-permanent { background: transparent; color: var(--clay); border-color: var(--clay); }
.btn-delete-permanent:hover { background: var(--clay); color: var(--bg-card); }

.empty-state { text-align: center; padding: 60px 20px; color: var(--ink-soft); font-size: 0.9rem; }

.modal {
  display: flex;
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
  max-width: 480px;
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
.appointment-detail p { margin: 10px 0; font-size: 0.9rem; color: var(--ink-soft); }
.appointment-detail strong { color: var(--ink); }
.modal-actions {
  display: flex;
  gap: 8px;
  margin-top: 24px;
  padding-top: 20px;
  border-top: 1px solid var(--line);
  flex-wrap: wrap;
}
.modal-actions a, .modal-actions button {
  font-family: var(--font-display);
  font-size: 0.68rem;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  padding: 10px 16px;
  border-radius: 2px;
  text-decoration: none;
  border: 1px solid;
  cursor: pointer;
  transition: all .2s ease;
  flex: 1;
  text-align: center;
  min-width: 100px;
}
.btn-close {
  background: transparent;
  color: var(--ink-soft);
  border-color: var(--line);
}
.btn-close:hover { background: var(--ink-soft); color: var(--bg-card); border-color: var(--ink-soft); }

@media (max-width: 900px) {
  .wrap { padding: 30px 20px; }
  .page-head h1 { font-size: 1.5rem; }
  table.appointments { font-size: 0.78rem; }
  table.appointments thead th, table.appointments tbody td { padding: 8px 6px; }
}
</style>
</head>
<body>

<div class="navbar">
  <span class="brand-text">✦ DANIELLE'S APARTMENT</span>
  <div class="nav-actions">
    <a href="owner_dashboard.php" class="btn-nav">← Back to Dashboard</a>
    <a href="logout.php" class="btn-nav logout">Logout</a>
  </div>
</div>

<div class="wrap">

  <div class="page-head">
    <div>
      <p class="eyebrow">Management</p>
      <h1>Appointment Requests</h1>
    </div>
  </div>

  <?php if ($message): ?>
    <div class="msg"><?php echo $message; ?></div>
  <?php endif; ?>

  <div class="filters">
    <a href="?filter=all" class="filter-btn <?php echo $filter == 'all' ? 'active' : ''; ?>">All (<?php echo $total_count; ?>)</a>
    <a href="?filter=pending" class="filter-btn <?php echo $filter == 'pending' ? 'active' : ''; ?>">Pending (<?php echo $pending_count; ?>)</a>
    <a href="?filter=confirmed" class="filter-btn <?php echo $filter == 'confirmed' ? 'active' : ''; ?>">Confirmed (<?php echo $confirmed_count; ?>)</a>
    <a href="?filter=completed" class="filter-btn <?php echo $filter == 'completed' ? 'active' : ''; ?>">Completed (<?php echo $completed_count; ?>)</a>
    <a href="?filter=cancelled" class="filter-btn <?php echo $filter == 'cancelled' ? 'active' : ''; ?>">Cancelled (<?php echo $cancelled_count; ?>)</a>
    <a href="?filter=trash" class="filter-btn <?php echo $filter == 'trash' ? 'active' : ''; ?>">Trash (<?php echo $trash_count; ?>)</a>
  </div>

  <div class="table-responsive">
    <table class="appointments">
      <thead>
        <tr>
          <th>Date</th>
          <th>Time</th>
          <th>Name</th>
          <th>Email</th>
          <th>Phone</th>
          <th>Gender</th>
          <th>Room</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (count($appointments) > 0): ?>
          <?php foreach ($appointments as $apt): ?>
            <tr class="<?php echo $filter == 'trash' ? 'trash-row' : ''; ?>">
              <td data-label="Date"><?php echo date('M d, Y', strtotime($apt['visit_date'])); ?></td>
              <td data-label="Time"><?php echo date('g:i A', strtotime($apt['visit_time'])); ?></td>
              <td data-label="Name"><?php echo htmlspecialchars($apt['name']); ?></td>
              <td data-label="Email"><?php echo htmlspecialchars($apt['email']); ?></td>
              <td data-label="Phone"><?php echo htmlspecialchars($apt['phone']); ?></td>
              <td data-label="Gender"><?php echo ucfirst($apt['gender']); ?></td>
              <td data-label="Room">
                <?php 
                $occ = $apt['occupancy'] ?? 0;
                if ($occ == 1) echo 'Solo';
                elseif ($occ == 2) echo 'Couple';
                elseif ($occ == 3) echo 'Triple';
                elseif ($occ == 4) echo 'Quad';
                else echo 'Unknown';
                ?>
              </td>
              <td data-label="Status">
                <span class="status-badge status-<?php echo $apt['status']; ?>">
                  <?php echo ucfirst($apt['status']); ?>
                </span>
              </td>
              <td data-label="Actions">
                <div class="actions">
                  <?php if ($filter == 'trash'): ?>
                    <a href="?restore=<?php echo $apt['id']; ?>&filter=trash&token=<?php echo csrf_token(); ?>" class="btn-restore" onclick="return confirm('Restore this appointment?')">Restore</a>
                    <a href="?permanent_delete=<?php echo $apt['id']; ?>&filter=trash&token=<?php echo csrf_token(); ?>" class="btn-delete-permanent" onclick="return confirm('Permanently delete? This cannot be undone.')">Delete</a>
                  <?php else: ?>
                    <a href="?view=<?php echo $apt['id']; ?>&filter=<?php echo $filter; ?>" class="btn-view">View</a>
                    <?php if ($apt['status'] == 'pending'): ?>
                      <a href="?approve=<?php echo $apt['id']; ?>&filter=<?php echo $filter; ?>&token=<?php echo csrf_token(); ?>" class="btn-approve" onclick="return confirm('Confirm this appointment?')">Approve</a>
                      <a href="?reject=<?php echo $apt['id']; ?>&filter=<?php echo $filter; ?>&token=<?php echo csrf_token(); ?>" class="btn-reject" onclick="return confirm('Cancel this appointment?')">Reject</a>
                    <?php elseif ($apt['status'] == 'confirmed'): ?>
                      <a href="?complete=<?php echo $apt['id']; ?>&filter=<?php echo $filter; ?>&token=<?php echo csrf_token(); ?>" class="btn-complete" onclick="return confirm('Mark as completed?')">Complete</a>
                    <?php endif; ?>
                    <a href="?delete=<?php echo $apt['id']; ?>&filter=<?php echo $filter; ?>&token=<?php echo csrf_token(); ?>" class="btn-trash" onclick="return confirm('Move this appointment to trash?')">Trash</a>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr>
            <td colspan="9" class="empty-state">No appointments found.</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($view_appointment): ?>
<div id="viewModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h3>Appointment Details</h3>
      <button class="close-modal" onclick="closeModal()">✕</button>
    </div>
    <div class="appointment-detail">
      <p><strong>Name:</strong> <?php echo htmlspecialchars($view_appointment['name']); ?></p>
      <p><strong>Email:</strong> <?php echo htmlspecialchars($view_appointment['email']); ?></p>
      <p><strong>Phone:</strong> <?php echo htmlspecialchars($view_appointment['phone']); ?></p>
      <p><strong>Gender:</strong> <?php echo ucfirst($view_appointment['gender']); ?></p>
      <p><strong>Date:</strong> <?php echo date('F j, Y', strtotime($view_appointment['visit_date'])); ?></p>
      <p><strong>Time:</strong> <?php echo date('g:i A', strtotime($view_appointment['visit_time'])); ?></p>
      <p><strong>Room:</strong> 
        <?php 
        $occ = $view_appointment['occupancy'] ?? 0;
        if ($occ == 1) echo 'Solo (1 person)';
        elseif ($occ == 2) echo 'Couple (2 persons)';
        elseif ($occ == 3) echo 'Triple (3 persons)';
        elseif ($occ == 4) echo 'Quad (4 persons)';
        else echo 'Unknown';
        ?>
      </p>
      <p><strong>Status:</strong> 
        <span class="status-badge status-<?php echo $view_appointment['status']; ?>">
          <?php echo ucfirst($view_appointment['status']); ?>
        </span>
      </p>
      <p><strong>Requested:</strong> <?php echo date('F j, Y g:i A', strtotime($view_appointment['created_at'])); ?></p>
    </div>
    <div class="modal-actions">
      <?php if ($view_appointment['status'] == 'pending'): ?>
        <a href="?approve=<?php echo $view_appointment['id']; ?>&filter=<?php echo $filter; ?>&token=<?php echo csrf_token(); ?>" class="btn-approve">Approve</a>
        <a href="?reject=<?php echo $view_appointment['id']; ?>&filter=<?php echo $filter; ?>&token=<?php echo csrf_token(); ?>" class="btn-reject">Reject</a>
      <?php elseif ($view_appointment['status'] == 'confirmed'): ?>
        <a href="?complete=<?php echo $view_appointment['id']; ?>&filter=<?php echo $filter; ?>&token=<?php echo csrf_token(); ?>" class="btn-complete">Complete</a>
      <?php endif; ?>
      <button onclick="closeModal()" class="btn-close">Close</button>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
function closeModal() {
  document.getElementById('viewModal').style.display = 'none';
  window.location.href = 'appointments_management.php?filter=<?php echo $filter; ?>';
}
window.onclick = function(event) {
  const modal = document.getElementById('viewModal');
  if (event.target == modal) closeModal();
}
</script>

</body>
</html>