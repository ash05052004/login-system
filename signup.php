<?php
require_once 'config.php';

$error = '';
$success = '';

// LOCALHOST reCAPTCHA keys
$RECAPTCHA_SITE_KEY = RECAPTCHA_SITE_KEY;
$RECAPTCHA_SECRET_KEY = RECAPTCHA_SECRET_KEY;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $recaptcha_response = $_POST['g-recaptcha-response'] ?? '';

    // Tenant if the account name ends with @tenant.
    $is_tenant = (strtolower(substr($username, -7)) === '@tenant');

    // Email column mirrors the account name (kept for compatibility).
    $email = $username;

    if (empty($recaptcha_response)) {
        $error = 'Please check the "I\'m not a robot" box.';
    } else {
        $verify_url = 'https://www.google.com/recaptcha/api/siteverify';
        $verify_data = [
            'secret' => $RECAPTCHA_SECRET_KEY,
            'response' => $recaptcha_response,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ];

        $ch = curl_init($verify_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($verify_data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $verify_result = curl_exec($ch);
        curl_close($ch);

        $verify_json = json_decode($verify_result ?: '', true);

        if (!$verify_json || empty($verify_json['success'])) {
            $error = '❌ reCAPTCHA verification failed. Please try again.';
        } else {
            if (empty($username) || empty($password)) {
                $error = 'All fields are required.';
            } elseif ($password !== $confirm_password) {
                $error = 'Passwords do not match.';
            } elseif (strlen($password) < 6) {
                $error = 'Password must be at least 6 characters.';
            } elseif ($is_tenant) {
                if (!preg_match('/^(A[1-7]|B[1-7])[a-zA-Z0-9]+@tenant$/i', $username)) {
                    $error = 'Invalid tenant account format. Use A1lastname@tenant or B1lastname@tenant.';
                } else {
                    preg_match('/^(A[1-7]|B[1-7])/', $username, $matches);
                    $room = strtoupper($matches[1]);

                    $stmt = $pdo->prepare(
                        "SELECT price_option, max_occupancy
                         FROM rooms
                         WHERE room_number = ?"
                    );
                    $stmt->execute([$room]);
                    $room_data = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$room_data) {
                        $error = "❌ Invalid room number.";
                    } else {
                        $stmt = $pdo->prepare(
                            "SELECT COUNT(*) AS count
                             FROM users
                             WHERE room_number = ?
                             AND role = 'tenant'
                             AND is_deleted = 0"
                        );
                        $stmt->execute([$room]);
                        $current_occupants = (int) $stmt->fetch(PDO::FETCH_ASSOC)['count'];

                        $max_occupancy = (int) $room_data['max_occupancy'];

                        if ($current_occupants >= $max_occupancy) {
                            $error = "❌ Room $room is full.";
                        }
                    }
                }
            }

            if (empty($error)) {
                $stmt = $pdo->prepare(
                    "SELECT id
                     FROM users
                     WHERE LOWER(username) = LOWER(?)"
                );
                $stmt->execute([$username]);

                if ($stmt->fetch(PDO::FETCH_ASSOC)) {
                    $error = 'Account name already exists. Please choose another one.';
                } else {
                    if ($is_tenant) {
                        $role = 'tenant';

                        preg_match('/^(A[1-7]|B[1-7])/', $username, $matches);
                        $room = strtoupper($matches[1]);

                        $stmt = $pdo->prepare(
                            "SELECT price_option
                             FROM rooms
                             WHERE room_number = ?"
                        );
                        $stmt->execute([$room]);
                        $room_data = $stmt->fetch(PDO::FETCH_ASSOC);

                        $price_option = $room_data
                            ? $room_data['price_option']
                            : '1500';
                    } else {
                        $role = 'appointment';
                        $price_option = null;
                        $room = null;
                    }

                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                    if ($role == 'tenant') {
                        $stmt = $pdo->prepare(
                            "INSERT INTO users
                            (username, email, password, role, price_option,
                             rent_amount, room_number, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
                        );

                        $stmt->execute([
                            $username,
                            $email,
                            $hashed_password,
                            $role,
                            $price_option,
                            $price_option,
                            $room
                        ]);
                    } else {
                        $stmt = $pdo->prepare(
                            "INSERT INTO users
                            (username, email, password, role, created_at)
                            VALUES (?, ?, ?, ?, NOW())"
                        );

                        $stmt->execute([
                            $username,
                            $email,
                            $hashed_password,
                            $role
                        ]);
                    }

                    $success = 'Registration successful! You can now login.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign Up — Dannielle's Apartment</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Josefin+Sans:wght@300;400;500;600;700&family=Karla:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://www.google.com/recaptcha/api.js" async defer></script>
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
.signup-card {
  width: 100%;
  max-width: 460px;
  background: var(--bg-card);
  border-radius: 4px;
  border: 1px solid var(--line);
  box-shadow: 0 30px 60px -20px rgba(0,0,0,0.6);
  padding: 44px 40px 36px;
}
.brand { text-align: center; margin-bottom: 26px; }
.brand-text {
  font-family: var(--font-display);
  font-weight: 600;
  font-size: 0.95rem;
  letter-spacing: 0.18em;
  color: var(--ink);
  display: block;
  margin-bottom: 18px;
}
.brand h1 {
  font-family: var(--font-display);
  font-weight: 300;
  font-size: 1.8rem;
  margin: 0 0 6px;
  color: var(--ink);
}
.brand .eyebrow {
  font-family: var(--font-body);
  font-weight: 700;
  font-size: 0.66rem;
  letter-spacing: 0.28em;
  text-transform: uppercase;
  color: var(--brass);
}
.field { margin-bottom: 16px; }
.field label {
  display: block;
  font-family: var(--font-display);
  font-size: 0.7rem;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--ink-soft);
  margin-bottom: 8px;
}
.field input {
  width: 100%;
  padding: 12px 14px;
  border: 1px solid var(--line);
  background: rgba(246,241,230,0.6);
  border-radius: 2px;
  font-family: var(--font-body);
  font-size: 0.95rem;
  color: var(--ink);
}
.field input:focus {
  outline: none;
  border-color: var(--brass);
  background: var(--bg-card);
}
.g-recaptcha { margin: 18px 0 22px; display: flex; justify-content: center; }
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
}
.btn-primary:hover { background: transparent; color: var(--brass); }
.msg {
  padding: 12px 14px;
  border-radius: 2px;
  font-size: 0.88rem;
  margin-bottom: 20px;
  text-align: center;
}
.msg.error { background: rgba(139,74,43,0.12); color: var(--clay); border-left: 3px solid var(--clay); }
.msg.success { background: rgba(107,79,42,0.08); color: var(--brass); border-left: 3px solid var(--brass); }
.links { margin-top: 22px; text-align: center; font-size: 0.85rem; color: var(--ink-soft); }
.links a { color: var(--brass); text-decoration: none; }
.links a:hover { text-decoration: underline; }
.links .row { margin: 10px 0; }
.back-home {
  display: inline-block;
  font-family: var(--font-display);
  font-size: 0.72rem;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  padding: 10px 22px;
  border: 1px solid var(--ink);
  border-radius: 2px;
  color: var(--ink);
  margin-top: 8px;
  text-decoration: none;
}
.back-home:hover { background: var(--ink); color: var(--bg-card); }
</style>
</head>
<body>

<div class="signup-card">
  <div class="brand">
    <span class="brand-text">✦ DANIELLE'S APARTMENT</span>
    <h1>Create an account</h1>
    <p class="eyebrow">Join us today</p>
  </div>

  <?php if ($error): ?>
    <div class="msg error"><?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>

  <?php if ($success): ?>
    <div class="msg success"><?php echo htmlspecialchars($success); ?></div>
  <?php endif; ?>

  <form method="POST" action="">
    <div class="field">
      <label>Account Name</label>
      <input type="text" name="username" required autocomplete="username" placeholder="Enter your account name">
    </div>

    <div class="field">
      <label>Password (min. 6 characters)</label>
      <input type="password" name="password" required autocomplete="new-password">
    </div>

    <div class="field">
      <label>Confirm Password</label>
      <input type="password" name="confirm_password" required autocomplete="new-password">
    </div>

    <div class="g-recaptcha" data-sitekey="<?php echo htmlspecialchars($RECAPTCHA_SITE_KEY); ?>"></div>

    <button type="submit" class="btn-primary">Create Account</button>
  </form>

  <div class="links">
    <div class="row">Already have an account? <a href="login.php">Login here</a></div>
    <div class="row"><a href="homepage.php" class="back-home">← Back to Homepage</a></div>
  </div>
</div>

</body>
</html>