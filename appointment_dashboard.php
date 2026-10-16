<?php
session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'appointment') {
    header('Location: login.php');
    exit();
}

require_once 'config.php';

if (isset($_GET['cancel'])) {
    csrf_check();
}

function getSetting($pdo, $key, $default = '') {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result['setting_value'] : $default;
}

function otpRespond($success, $message, $extra = []) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));
    exit();
}

/*
|--------------------------------------------------------------------------
| TELEGRAM OTP HANDLER
|--------------------------------------------------------------------------
| Sends OTP through the user's linked Telegram account.
| The OTP is never returned to the browser.
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['otp_action'])) {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    $expectedToken = (string)csrf_token();

    if (
        $submittedToken === '' ||
        !hash_equals($expectedToken, $submittedToken)
    ) {
        otpRespond(false, 'Session expired. Refresh the page and try again.');
    }

    $action = (string)$_POST['otp_action'];

    // Reset OTP and verification when the phone number changes.
    if ($action === 'reset') {
        unset(
            $_SESSION['otp_hash'],
            $_SESSION['otp_phone'],
            $_SESSION['otp_expires'],
            $_SESSION['otp_attempts'],
            $_SESSION['phone_verified']
        );

        otpRespond(true, 'Phone verification reset.');
    }

    $phone = trim((string)($_POST['phone'] ?? ''));

    if (!preg_match('/^09[0-9]{9}$/', $phone)) {
        otpRespond(false, 'Enter an 11-digit mobile number starting with 09.');
    }

    if ($action === 'send') {
        // Require a linked Telegram account.
        $stmt = $pdo->prepare(
            "SELECT telegram_chat_id FROM users WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$_SESSION['user_id']]);
        $telegramUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$telegramUser || empty($telegramUser['telegram_chat_id'])) {
            otpRespond(
                false,
                'Telegram is not connected. Connect your Telegram account first, then try again.'
            );
        }

        // Prevent rapid repeat requests.
        $lastSent = (int)($_SESSION['otp_last_sent'] ?? 0);
        $elapsed = time() - $lastSent;

        if ($elapsed < 60) {
            $wait = 60 - $elapsed;
            otpRespond(false, "Please wait {$wait} seconds before requesting another OTP.");
        }

        // If the phone number changes, previous verification is invalid.
        unset($_SESSION['phone_verified']);

        // Generate a random six-digit OTP.
        $otp = (string)random_int(100000, 999999);

        // Use the existing Telegram notification helper.
        require_once 'notification_helper.php';

        $telegramMessage =
            "Your phone verification code is: {$otp}\n\n" .
            "This code expires in 5 minutes.\n" .
            "Do not share this code with anyone.";

        try {
            $sent = sendTelegram(
                (int)$_SESSION['user_id'],
                'Phone Verification OTP',
                $telegramMessage
            );
        } catch (Throwable $e) {
            $sent = false;
            error_log('Telegram OTP delivery error: ' . $e->getMessage());
        }

        if (!$sent) {
            otpRespond(
                false,
                'Unable to send the OTP through Telegram. Open your bot and press Start, then try again.'
            );
        }

        // Store only a password hash after Telegram confirms delivery.
        $_SESSION['otp_hash'] = password_hash($otp, PASSWORD_DEFAULT);
        $_SESSION['otp_phone'] = $phone;
        $_SESSION['otp_expires'] = time() + 300;
        $_SESSION['otp_attempts'] = 0;
        $_SESSION['otp_last_sent'] = time();

        // Never return the OTP itself to the browser.
        otpRespond(
            true,
            'OTP sent to your connected Telegram account. Check your Telegram messages.'
        );
    }

    if ($action === 'verify') {
        if (
            empty($_SESSION['otp_hash']) ||
            ($_SESSION['otp_phone'] ?? '') !== $phone
        ) {
            otpRespond(false, 'Request an OTP for this phone number first.');
        }

        if (time() > (int)($_SESSION['otp_expires'] ?? 0)) {
            unset(
                $_SESSION['otp_hash'],
                $_SESSION['otp_phone'],
                $_SESSION['otp_expires'],
                $_SESSION['otp_attempts']
            );

            otpRespond(false, 'Your OTP has expired. Request a new one.');
        }

        if ((int)($_SESSION['otp_attempts'] ?? 0) >= 5) {
            unset(
                $_SESSION['otp_hash'],
                $_SESSION['otp_phone'],
                $_SESSION['otp_expires'],
                $_SESSION['otp_attempts']
            );

            otpRespond(false, 'Too many incorrect attempts. Request a new OTP.');
        }

        $enteredOtp = trim((string)($_POST['otp'] ?? ''));

        if (!preg_match('/^[0-9]{6}$/', $enteredOtp)) {
            $_SESSION['otp_attempts'] = (int)($_SESSION['otp_attempts'] ?? 0) + 1;
            otpRespond(false, 'Enter the complete 6-digit OTP.');
        }

        if (!password_verify($enteredOtp, $_SESSION['otp_hash'])) {
            $_SESSION['otp_attempts'] = (int)($_SESSION['otp_attempts'] ?? 0) + 1;
            otpRespond(false, 'Incorrect OTP. Please try again.');
        }

        $_SESSION['phone_verified'] = $phone;

        unset(
            $_SESSION['otp_hash'],
            $_SESSION['otp_phone'],
            $_SESSION['otp_expires'],
            $_SESSION['otp_attempts']
        );

        otpRespond(true, 'Phone number verified successfully.');
    }

    otpRespond(false, 'Invalid OTP request.');
}

/*
|--------------------------------------------------------------------------
| ROOM SETTINGS
|--------------------------------------------------------------------------
*/
$solo_available = getSetting($pdo, 'solo_available', '1') == '1';
$two_person_available = getSetting($pdo, 'two_person_available', '1') == '1';
$three_person_available = getSetting($pdo, 'three_person_available', '1') == '1';
$four_person_available = getSetting($pdo, 'four_person_available', '1') == '1';

$solo_price = getSetting($pdo, 'solo_price', '5000');
$two_person_price = getSetting($pdo, 'two_person_price', '2500');
$three_person_price = getSetting($pdo, 'three_person_price', '2000');
$four_person_price = getSetting($pdo, 'four_person_price', '1500');

$any_available = $solo_available || $two_person_available ||
                 $three_person_available || $four_person_available;

/*
|--------------------------------------------------------------------------
| TELEGRAM STATUS
|--------------------------------------------------------------------------
*/
$bot_username = 'DBtheApartmentBot';
$connect_url = "https://t.me/$bot_username?start=USER_" . $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT telegram_chat_id FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$tg_row = $stmt->fetch(PDO::FETCH_ASSOC);
$telegram_connected = !empty($tg_row['telegram_chat_id']);

/*
|--------------------------------------------------------------------------
| APPOINTMENT BOOKING
|--------------------------------------------------------------------------
*/
$booking_success = '';
$booking_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_appointment'])) {
    $name = trim((string)($_POST['name'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $gender = (string)($_POST['gender'] ?? '');
    $visit_date = (string)($_POST['visit_date'] ?? '');
    $visit_time = (string)($_POST['visit_time'] ?? '');
    $occupancy = (string)($_POST['occupancy'] ?? '');
    $user_id = $_SESSION['user_id'];

    if (
        $name === '' ||
        $phone === '' ||
        $email === '' ||
        $gender === '' ||
        $visit_date === '' ||
        $visit_time === '' ||
        $occupancy === ''
    ) {
        $booking_error = 'Please fill in all required fields.';
    } elseif (!preg_match('/^09[0-9]{9}$/', $phone)) {
        $booking_error = 'Phone number must start with 09 and contain exactly 11 digits.';
    } elseif (($_SESSION['phone_verified'] ?? '') !== $phone) {
        $booking_error = 'Please verify your phone number using OTP before requesting an appointment.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $booking_error = 'Please enter a valid email address.';
    } elseif (
        !in_array($gender, ['male', 'female'], true) ||
        !in_array($occupancy, ['1', '2', '3', '4'], true)
    ) {
        $booking_error = 'Please select a valid gender and room preference.';
    } elseif (
        !(
            ($occupancy === '1' && $solo_available) ||
            ($occupancy === '2' && $two_person_available) ||
            ($occupancy === '3' && $three_person_available) ||
            ($occupancy === '4' && $four_person_available)
        )
    ) {
        $booking_error = 'The selected room preference is currently unavailable.';
    } else {
        // Validate date, weekday, and time on the server.
        $dateObject = DateTime::createFromFormat('!Y-m-d', $visit_date);
        $validDate = $dateObject &&
                     $dateObject->format('Y-m-d') === $visit_date;

        $allowedTimes = [
            '08:00', '09:00', '10:00', '11:00',
            '13:00', '14:00', '15:00', '16:00', '17:00'
        ];

        if (
            !$validDate ||
            !in_array((int)$dateObject->format('w'), [0, 5, 6], true) ||
            $visit_date < date('Y-m-d') ||
            $visit_date > date('Y-m-d', strtotime('+60 days'))
        ) {
            $booking_error = 'Please select a valid Friday, Saturday, or Sunday within the next 60 days.';
        } elseif (!in_array($visit_time, $allowedTimes, true)) {
            $booking_error = 'Please select a valid viewing time.';
        } else {
            try {
                $stmt = $pdo->prepare(
                    "INSERT INTO appointments
                    (user_id, name, phone, email, gender, visit_date, visit_time, occupancy, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
                );

                $stmt->execute([
                    $user_id,
                    $name,
                    $phone,
                    $email,
                    $gender,
                    $visit_date,
                    $visit_time,
                    $occupancy
                ]);

                $booking_success = "Appointment request sent. We'll confirm your visit soon.";

                // Require verification again for a future appointment request.
                unset($_SESSION['phone_verified']);

                require_once 'notification_helper.php';
                $owner_id = getOwnerId();

                if ($owner_id) {
                    $date = date('F j, Y', strtotime($visit_date));
                    $time = date('g:i A', strtotime($visit_time));
                    notifyNewAppointment($owner_id, $name, $date, $time, $occupancy);
                }
            } catch (PDOException $e) {
                $booking_error = 'Error saving appointment.';
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| CANCEL APPOINTMENT
|--------------------------------------------------------------------------
*/
if (isset($_GET['cancel'])) {
    $appointment_id = filter_var($_GET['cancel'], FILTER_VALIDATE_INT);

    if (!$appointment_id || $appointment_id < 1) {
        header('Location: appointment_dashboard.php');
        exit();
    }

    $stmt = $pdo->prepare(
        "UPDATE appointments
         SET status = 'cancelled'
         WHERE id = ? AND user_id = ? AND status = 'pending'"
    );

    $stmt->execute([$appointment_id, $_SESSION['user_id']]);

    header('Location: appointment_dashboard.php?msg=cancelled');
    exit();
}

/*
|--------------------------------------------------------------------------
| APPOINTMENT HISTORY
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare(
    "SELECT * FROM appointments
     WHERE user_id = ?
     ORDER BY visit_date DESC, visit_time DESC
     LIMIT 10"
);
$stmt->execute([$_SESSION['user_id']]);
$my_appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$success_msg = '';
$error_msg = '';

if (isset($_GET['msg']) && $_GET['msg'] === 'cancelled') {
    $success_msg = 'Appointment cancelled.';
}

if ($booking_error !== '') {
    $error_msg = $booking_error;
}

$csrfForJs = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Book a Viewing — Dannielle's Apartment</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Josefin+Sans:wght@300;400;500;600;700&family=Karla:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">

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
  max-width: 1200px;
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

.nav-actions {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  align-items: center;
}

.nav-actions .welcome {
  color: rgba(237,227,209,0.75);
  font-size: 0.85rem;
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

.btn-nav:hover {
  background: var(--cream);
  color: var(--ink);
}

.btn-nav.telegram {
  background: var(--brass);
  color: var(--bg-card);
  border-color: var(--brass);
}

.btn-nav.telegram:hover {
  background: transparent;
  color: var(--brass-light);
}

.btn-nav.connected {
  color: #7bc47f;
  border-color: #7bc47f;
  cursor: default;
}

.btn-nav.logout {
  border-color: rgba(139,74,43,0.6);
  color: #E5A08B;
}

.btn-nav.logout:hover {
  background: var(--clay);
  color: var(--cream);
  border-color: var(--clay);
}

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

.note {
  font-size: 0.8rem;
  color: var(--ink-soft);
  text-align: right;
}

.note strong { color: var(--ink); }

.msg {
  padding: 14px 16px;
  border-radius: 2px;
  font-size: 0.9rem;
  margin-bottom: 22px;
  border-left: 3px solid;
}

.msg.success {
  background: rgba(107,79,42,0.08);
  color: var(--brass);
  border-color: var(--brass);
}

.msg.error {
  background: rgba(139,74,43,0.12);
  color: var(--clay);
  border-color: var(--clay);
}

.telegram-cta {
  margin: 0 0 24px;
  padding: 16px 18px;
  background: rgba(107,79,42,0.06);
  border-left: 3px solid var(--brass-light);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
}

.telegram-cta .cta-text {
  font-size: 0.85rem;
  color: var(--ink-soft);
  flex: 1;
  min-width: 220px;
}

.telegram-cta strong { color: var(--ink); }

.grid {
  display: grid;
  grid-template-columns: 1.1fr 0.9fr;
  gap: 40px;
}

@media (max-width: 900px) {
  .grid {
    grid-template-columns: 1fr;
    gap: 30px;
  }

  .wrap {
    padding: 30px 20px;
  }
}

.section-title {
  font-family: var(--font-display);
  font-weight: 500;
  font-size: 1rem;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--ink);
  margin: 0 0 18px;
  padding-bottom: 10px;
  border-bottom: 1px solid var(--line);
}

.field { margin-bottom: 18px; }

.field label {
  display: block;
  font-family: var(--font-display);
  font-size: 0.68rem;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--ink-soft);
  margin-bottom: 8px;
}

.field input[type="text"],
.field input[type="email"],
.field input[type="tel"],
.field select {
  width: 100%;
  padding: 12px 14px;
  border: 1px solid var(--line);
  background: rgba(246,241,230,0.6);
  border-radius: 2px;
  font-family: var(--font-body);
  font-size: 0.95rem;
  color: var(--ink);
  transition: border-color .2s ease, background .2s ease;
}

.field input:focus,
.field select:focus {
  outline: none;
  border-color: var(--brass);
  background: var(--bg-card);
}

.room-option {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 14px 16px;
  border: 1px solid var(--line);
  border-radius: 2px;
  margin-bottom: 10px;
  transition: background .2s ease, border-color .2s ease;
}

.room-option:hover {
  background: rgba(107,79,42,0.04);
  border-color: var(--brass-light);
}

.room-option input[type="radio"] { accent-color: var(--brass); }

.room-option label {
  flex: 1;
  font-size: 0.92rem;
  color: var(--ink);
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.room-option .price-tag {
  font-family: var(--font-display);
  font-size: 0.85rem;
  color: var(--brass);
  white-space: nowrap;
}

.room-option.disabled {
  opacity: 0.5;
  pointer-events: none;
}

.room-status {
  font-size: 0.7rem;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  padding: 3px 8px;
  border-radius: 2px;
}

.status-available {
  background: rgba(107,79,42,0.1);
  color: var(--brass);
}

.status-unavailable {
  background: rgba(139,74,43,0.12);
  color: var(--clay);
}

.info-box {
  margin-top: 20px;
  padding: 14px 16px;
  background: rgba(107,79,42,0.05);
  border-left: 3px solid var(--brass-light);
  border-radius: 2px;
  font-size: 0.82rem;
  color: var(--ink-soft);
}

.btn-primary {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  font-family: var(--font-display);
  font-size: 0.85rem;
  letter-spacing: 0.16em;
  text-transform: uppercase;
  padding: 15px 26px;
  background: var(--brass);
  color: var(--bg-card);
  border: 1px solid var(--brass);
  border-radius: 2px;
  cursor: pointer;
  transition: background .25s ease, color .25s ease;
  margin-top: 8px;
}

.btn-primary:hover:not(:disabled) {
  background: transparent;
  color: var(--brass);
}

.btn-primary:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.appt-card {
  padding: 16px 18px;
  border: 1px solid var(--line);
  border-radius: 2px;
  margin-bottom: 12px;
  background: rgba(246,241,230,0.4);
}

.appt-card p {
  margin: 6px 0;
  font-size: 0.85rem;
  color: var(--ink-soft);
}

.appt-card strong {
  color: var(--ink);
  font-weight: 600;
}

.appt-actions {
  margin-top: 12px;
  padding-top: 12px;
  border-top: 1px solid var(--line);
}

.cancel-link {
  font-family: var(--font-display);
  font-size: 0.7rem;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: var(--clay);
  border: 1px solid var(--clay);
  padding: 8px 16px;
  border-radius: 2px;
  display: inline-block;
  transition: all .2s ease;
  text-decoration: none;
}

.cancel-link:hover {
  background: var(--clay);
  color: var(--bg-card);
}

.status-badge {
  font-family: var(--font-display);
  font-size: 0.68rem;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  padding: 3px 10px;
  border-radius: 2px;
}

.status-approved {
  background: rgba(107,79,42,0.12);
  color: var(--brass);
}

.status-pending {
  background: rgba(201,168,118,0.25);
  color: #8B6F3D;
}

.status-cancelled {
  background: rgba(139,74,43,0.15);
  color: var(--clay);
}

.status-completed {
  background: rgba(42,33,24,0.08);
  color: var(--ink-soft);
}

/* OTP POPUP */
.otp-modal {
  position: fixed;
  inset: 0;
  z-index: 9999;
  background: rgba(0,0,0,0.72);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
}

.otp-modal-content {
  position: relative;
  width: 100%;
  max-width: 420px;
  padding: 30px;
  background: var(--bg-card);
  border: 1px solid var(--line);
  border-radius: 4px;
  box-shadow: 0 20px 60px rgba(0,0,0,0.35);
}

.otp-modal-content p {
  color: var(--ink-soft);
  font-size: 0.9rem;
  line-height: 1.5;
}

.otp-modal-content label {
  display: block;
  margin: 18px 0 8px;
  font-family: var(--font-display);
  font-size: 0.75rem;
  letter-spacing: 0.1em;
  text-transform: uppercase;
}

.otp-modal-content input {
  width: 100%;
  padding: 14px;
  border: 1px solid var(--line);
  background: var(--bg);
  font-size: 1.3rem;
  letter-spacing: 0.3em;
  text-align: center;
  border-radius: 2px;
}

.otp-close {
  position: absolute;
  top: 8px;
  right: 12px;
  border: 0;
  background: transparent;
  font-size: 1.8rem;
  color: var(--ink);
  cursor: pointer;
}

#phoneVerificationStatus {
  font-size: 0.85rem;
  margin: 8px 0 0;
}

#otpMessage {
  overflow-wrap: anywhere;
}
</style>
</head>

<body>

<div class="navbar">
  <span class="brand-text">✦ DANIELLE'S APARTMENT</span>

  <div class="nav-actions">
    <span class="welcome">
      <?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?>
    </span>

    <?php if ($telegram_connected): ?>
      <span class="btn-nav connected">✓ Telegram connected</span>
    <?php else: ?>
      <a href="<?php echo htmlspecialchars($connect_url); ?>"
         target="_blank" rel="noopener" class="btn-nav telegram">Connect Telegram</a>
    <?php endif; ?>

    <a href="logout.php" class="btn-nav logout">Logout</a>
  </div>
</div>

<div class="wrap">

  <div class="page-head">
    <div>
      <p class="eyebrow">Schedule a Viewing</p>
      <h1>Book a Property Viewing</h1>
    </div>

    <div class="note">
      <strong>Owner availability:</strong><br>
      Friday – Sunday, 8:00 AM – 5:00 PM
    </div>
  </div>

  <?php if ($booking_success): ?>
    <div class="msg success">
      <?php echo htmlspecialchars($booking_success); ?>
    </div>

    <?php if (!$telegram_connected): ?>
      <div class="telegram-cta">
        <div class="cta-text">
          <strong>Want instant approval alerts?</strong>
          Connect Telegram and get notified the moment the owner approves your appointment.
        </div>
        <a href="<?php echo htmlspecialchars($connect_url); ?>"
           target="_blank" rel="noopener" class="btn-nav telegram">Connect Telegram</a>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($error_msg): ?>
    <div class="msg error"><?php echo htmlspecialchars($error_msg); ?></div>
  <?php endif; ?>

  <?php if ($success_msg): ?>
    <div class="msg success"><?php echo htmlspecialchars($success_msg); ?></div>
  <?php endif; ?>

  <?php if (!$any_available): ?>
    <div class="no-vacancy">
      <h3>No Vacancy</h3>
      <p>All rooms are currently fully booked. Please check back later.</p>
    </div>
  <?php endif; ?>

  <div class="grid">

    <div>
      <h2 class="section-title">1. Visit Date &amp; Time</h2>

      <div class="field">
        <label for="datepicker">Select Date (Fri–Sun only)</label>
        <input type="text" id="datepicker" placeholder="Pick a date..." readonly>
      </div>

      <div class="field">
        <label for="time-select">Select Time (8am – 5pm)</label>
        <select id="time-select">
          <option value="">-- Select time --</option>
          <option value="08:00">8:00 AM</option>
          <option value="09:00">9:00 AM</option>
          <option value="10:00">10:00 AM</option>
          <option value="11:00">11:00 AM</option>
          <option value="13:00">1:00 PM</option>
          <option value="14:00">2:00 PM</option>
          <option value="15:00">3:00 PM</option>
          <option value="16:00">4:00 PM</option>
          <option value="17:00">5:00 PM</option>
        </select>
      </div>

      <h2 class="section-title" style="margin-top:32px;">2. Room Preference</h2>

      <div class="room-option <?php echo !$solo_available ? 'disabled' : ''; ?>">
        <input type="radio" name="occupancy_choice" value="1" id="solo"
               <?php echo !$solo_available ? 'disabled' : ''; ?>>
        <label for="solo">
          Solo (1 person)
          <?php if ($solo_available): ?>
            <span class="room-status status-available">Available</span>
          <?php else: ?>
            <span class="room-status status-unavailable">Unavailable</span>
          <?php endif; ?>
        </label>
        <span class="price-tag">₱<?php echo number_format((float)$solo_price); ?></span>
      </div>

      <div class="room-option <?php echo !$two_person_available ? 'disabled' : ''; ?>">
        <input type="radio" name="occupancy_choice" value="2" id="couple"
               <?php echo !$two_person_available ? 'disabled' : ''; ?>>
        <label for="couple">
          Shared by 2 Persons
          <?php if ($two_person_available): ?>
            <span class="room-status status-available">Available</span>
          <?php else: ?>
            <span class="room-status status-unavailable">Unavailable</span>
          <?php endif; ?>
        </label>
        <span class="price-tag">₱<?php echo number_format((float)$two_person_price); ?> each</span>
      </div>

      <div class="room-option <?php echo !$three_person_available ? 'disabled' : ''; ?>">
        <input type="radio" name="occupancy_choice" value="3" id="three"
               <?php echo !$three_person_available ? 'disabled' : ''; ?>>
        <label for="three">
          Shared by 3 Persons
          <?php if ($three_person_available): ?>
            <span class="room-status status-available">Available</span>
          <?php else: ?>
            <span class="room-status status-unavailable">Unavailable</span>
          <?php endif; ?>
        </label>
        <span class="price-tag">₱<?php echo number_format((float)$three_person_price); ?> each</span>
      </div>

      <div class="room-option <?php echo !$four_person_available ? 'disabled' : ''; ?>">
        <input type="radio" name="occupancy_choice" value="4" id="four"
               <?php echo !$four_person_available ? 'disabled' : ''; ?>>
        <label for="four">
          Shared by 4 Persons
          <?php if ($four_person_available): ?>
            <span class="room-status status-available">Available</span>
          <?php else: ?>
            <span class="room-status status-unavailable">Unavailable</span>
          <?php endif; ?>
        </label>
        <span class="price-tag">₱<?php echo number_format((float)$four_person_price); ?> each</span>
      </div>

      <div class="info-box">
        <strong>Note:</strong> Water, electric, and other utilities are added to the total monthly rent.
      </div>
    </div>

    <div>
      <h2 class="section-title">3. Your Contact Information</h2>

      <form method="POST" action="" id="appointmentForm">

        <div class="field">
          <label for="name">Full Name</label>
          <input type="text" id="name" name="name" required
                 <?php echo !$any_available ? 'disabled' : ''; ?>>
        </div>

        <div class="field">
          <label for="email">Email Address</label>
          <input type="email" id="email" name="email" required
                 placeholder="youremail@gmail.com"
                 <?php echo !$any_available ? 'disabled' : ''; ?>>
        </div>

        <div class="field">
          <label for="phone">Phone Number</label>
          <input type="tel" id="phone" name="phone" required
                 placeholder="09123456789"
                 pattern="09[0-9]{9}"
                 minlength="11" maxlength="11"
                 inputmode="numeric"
                 autocomplete="tel"
                 title="Enter exactly 11 digits starting with 09."
                 <?php echo !$any_available ? 'disabled' : ''; ?>>

          <button type="button" class="btn-primary" id="otpVerifyBtn"
                  style="margin-top:10px;"
                  <?php echo !$any_available ? 'disabled' : ''; ?>>
            OTP Verify
          </button>

          <p id="phoneVerificationStatus" role="status" aria-live="polite"></p>
        </div>

        <div class="field">
          <label for="gender">Gender</label>
          <select id="gender" name="gender" required
                  <?php echo !$any_available ? 'disabled' : ''; ?>>
            <option value="">-- Select Gender --</option>
            <option value="male">Male</option>
            <option value="female">Female</option>
          </select>
        </div>

        <input type="hidden" name="visit_date" id="visit_date" value="">
        <input type="hidden" name="visit_time" id="visit_time" value="">
        <input type="hidden" name="occupancy" id="selected_occupancy" value="">

        <?php if ($any_available): ?>
          <button type="submit" name="book_appointment"
                  class="btn-primary" id="submitBtn" disabled>
            Request Appointment
          </button>
        <?php else: ?>
          <button type="button" class="btn-primary" disabled>Fully Booked</button>
        <?php endif; ?>
      </form>

      <!-- OTP POPUP -->
      <div id="otpModal" class="otp-modal" style="display:none;">
        <div class="otp-modal-content" role="dialog" aria-modal="true"
             aria-labelledby="otpTitle">

          <button type="button" id="closeOtpModal" class="otp-close"
                  aria-label="Close popup">&times;</button>

          <h2 id="otpTitle" class="section-title">Verify Phone Number</h2>
          <p>A six-digit verification code will be sent to your connected Telegram account. Enter the code here to continue.</p>

          <label for="otpCode">6-Digit OTP</label>
          <input type="text" id="otpCode" inputmode="numeric"
                 autocomplete="one-time-code" maxlength="6"
                 pattern="[0-9]{6}" placeholder="000000">

          <p id="otpMessage" role="status" aria-live="polite"></p>

          <button type="button" id="confirmOtpBtn" class="btn-primary">
            Verify OTP
          </button>

          <button type="button" id="resendOtpBtn" class="btn-primary">
            Resend OTP
          </button>
        </div>
      </div>

      <?php if (count($my_appointments) > 0): ?>
        <h2 class="section-title" style="margin-top:34px;">My Appointments</h2>

        <?php foreach ($my_appointments as $apt): ?>
          <div class="appt-card">
            <p>
              <strong>Date:</strong>
              <?php echo htmlspecialchars(date('F j, Y', strtotime($apt['visit_date']))); ?>
            </p>

            <p>
              <strong>Time:</strong>
              <?php echo htmlspecialchars(date('g:i A', strtotime($apt['visit_time']))); ?>
            </p>

            <p>
              <strong>Occupancy:</strong>
              <?php echo htmlspecialchars($apt['occupancy']); ?> person(s)
            </p>

            <p>
              <strong>Status:</strong>

              <?php if ($apt['status'] === 'confirmed'): ?>
                <span class="status-badge status-approved">Approved</span>
              <?php elseif ($apt['status'] === 'pending'): ?>
                <span class="status-badge status-pending">Pending</span>
              <?php elseif ($apt['status'] === 'cancelled'): ?>
                <span class="status-badge status-cancelled">Cancelled</span>
              <?php elseif ($apt['status'] === 'completed'): ?>
                <span class="status-badge status-completed">Completed</span>
              <?php else: ?>
                <span class="status-badge status-completed">
                  <?php echo htmlspecialchars(ucfirst($apt['status'])); ?>
                </span>
              <?php endif; ?>
            </p>

            <?php if ($apt['status'] === 'pending'): ?>
              <div class="appt-actions">
                <a href="?cancel=<?php echo (int)$apt['id']; ?>&token=<?php echo rawurlencode(csrf_token()); ?>"
                   class="cancel-link"
                   onclick="return confirm('Cancel this appointment?')">Cancel</a>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<script>
const appointmentForm = document.getElementById('appointmentForm');
const phoneInput = document.getElementById('phone');
const submitBtn = document.getElementById('submitBtn');
const otpModal = document.getElementById('otpModal');
const otpCode = document.getElementById('otpCode');
const otpMessage = document.getElementById('otpMessage');
const phoneStatus = document.getElementById('phoneVerificationStatus');
const otpVerifyBtn = document.getElementById('otpVerifyBtn');
const confirmOtpBtn = document.getElementById('confirmOtpBtn');
const resendOtpBtn = document.getElementById('resendOtpBtn');

const otpCsrfToken = <?php echo json_encode($csrfForJs); ?>;
let verifiedPhone = <?php echo json_encode($_SESSION['phone_verified'] ?? ''); ?>;
let otpBusy = false;

flatpickr('#datepicker', {
    enableTime: false,
    dateFormat: 'Y-m-d',
    minDate: 'today',
    maxDate: new Date().fp_incr(60),

    enable: [function(date) {
        return [0, 5, 6].includes(date.getDay());
    }],

    onChange: function(selectedDates, dateStr) {
        document.getElementById('visit_date').value = dateStr;
        checkFormCompletion();
    }
});

document.getElementById('time-select').addEventListener('change', function() {
    document.getElementById('visit_time').value = this.value;
    checkFormCompletion();
});

document.querySelectorAll('input[name="occupancy_choice"]').forEach(function(radio) {
    radio.addEventListener('change', function() {
        document.getElementById('selected_occupancy').value = this.value;
        checkFormCompletion();
    });
});

function checkFormCompletion() {
    if (!submitBtn) return;

    const date = document.getElementById('visit_date').value;
    const time = document.getElementById('visit_time').value;
    const occupancy = document.getElementById('selected_occupancy').value;
    const phone = phoneInput.value.trim();

    const phoneVerified = verifiedPhone !== '' && verifiedPhone === phone;

    submitBtn.disabled = !(
        appointmentForm.checkValidity() &&
        /^09[0-9]{9}$/.test(phone) &&
        phoneVerified &&
        date &&
        time &&
        occupancy
    );
}

async function otpRequest(action, extra = {}) {
    const data = new URLSearchParams({
        otp_action: action,
        phone: phoneInput.value.trim(),
        csrf_token: otpCsrfToken,
        ...extra
    });

    const response = await fetch(window.location.pathname, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: data.toString()
    });

    if (!response.ok) {
        throw new Error('Server request failed.');
    }

    return await response.json();
}

function updatePhoneStatus() {
    if (verifiedPhone && verifiedPhone === phoneInput.value.trim()) {
        phoneStatus.textContent = '✓ Phone Number Verified';
        phoneStatus.style.color = 'green';
    } else {
        phoneStatus.textContent = '';
        phoneStatus.style.color = '';
    }

    checkFormCompletion();
}

async function sendOtp() {
    if (otpBusy) return;

    const phone = phoneInput.value.trim();

    if (!/^09[0-9]{9}$/.test(phone)) {
        otpMessage.textContent = 'Enter a valid 11-digit mobile number starting with 09.';
        return;
    }

    otpBusy = true;
    otpMessage.textContent = 'Sending OTP to Telegram...';
    confirmOtpBtn.disabled = true;
    resendOtpBtn.disabled = true;

    try {
        const result = await otpRequest('send');

        if (result.success) {
            otpCode.value = '';
            otpMessage.textContent = result.message;
            otpCode.focus();
        } else {
            otpMessage.textContent = result.message;
        }
    } catch (error) {
        otpMessage.textContent = 'Unable to contact the server. Please try again.';
    } finally {
        otpBusy = false;
        confirmOtpBtn.disabled = false;
        resendOtpBtn.disabled = false;
    }
}

otpVerifyBtn.addEventListener('click', async function() {
    const phone = phoneInput.value.trim();

    if (!/^09[0-9]{9}$/.test(phone)) {
        phoneStatus.textContent = 'Enter a valid 11-digit phone number first.';
        phoneStatus.style.color = 'red';
        return;
    }

    if (verifiedPhone === phone) {
        phoneStatus.textContent = 'This phone number is already verified.';
        phoneStatus.style.color = 'green';
        return;
    }

    otpModal.style.display = 'flex';
    otpMessage.textContent = '';
    await sendOtp();
});

confirmOtpBtn.addEventListener('click', async function() {
    if (otpBusy) return;

    const code = otpCode.value.trim();

    if (!/^[0-9]{6}$/.test(code)) {
        otpMessage.textContent = 'Enter all 6 digits.';
        return;
    }

    otpBusy = true;
    confirmOtpBtn.disabled = true;
    resendOtpBtn.disabled = true;
    otpMessage.textContent = 'Verifying...';

    try {
        const result = await otpRequest('verify', { otp: code });
        otpMessage.textContent = result.message;

        if (result.success) {
            verifiedPhone = phoneInput.value.trim();
            otpModal.style.display = 'none';
            updatePhoneStatus();
        }
    } catch (error) {
        otpMessage.textContent = 'Unable to contact the server. Please try again.';
    } finally {
        otpBusy = false;
        confirmOtpBtn.disabled = false;
        resendOtpBtn.disabled = false;
    }
});

resendOtpBtn.addEventListener('click', sendOtp);

document.getElementById('closeOtpModal').addEventListener('click', function() {
    otpModal.style.display = 'none';
});

otpModal.addEventListener('click', function(event) {
    if (event.target === otpModal) {
        otpModal.style.display = 'none';
    }
});

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape' && otpModal.style.display === 'flex') {
        otpModal.style.display = 'none';
    }
});

// A changed phone number must be verified again.
phoneInput.addEventListener('input', function() {
    if (verifiedPhone && phoneInput.value.trim() !== verifiedPhone) {
        verifiedPhone = '';
        phoneStatus.textContent = 'Phone number changed. Verify it again.';
        phoneStatus.style.color = 'red';

        otpCode.value = '';

        otpRequest('reset').catch(function() {});
    }

    checkFormCompletion();
});

// Validate required fields whenever they change.
appointmentForm.querySelectorAll('input, select').forEach(function(field) {
    field.addEventListener('input', checkFormCompletion);
    field.addEventListener('change', checkFormCompletion);
});

appointmentForm.addEventListener('submit', function(event) {
    const phone = phoneInput.value.trim();

    if (verifiedPhone === '' || verifiedPhone !== phone) {
        event.preventDefault();
        alert('Please verify your phone number using OTP first.');
        checkFormCompletion();
        return;
    }

    if (!appointmentForm.checkValidity()) {
        event.preventDefault();
        appointmentForm.reportValidity();
    }
});

updatePhoneStatus();
checkFormCompletion();
</script>

</body>
</html>