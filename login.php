<?php
session_start();
require_once 'config.php';

$error = '';

$MAX_ATTEMPTS = 5;
$LOCK_MINUTES = 15;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    $pdo->prepare(
        "DELETE FROM login_attempts
         WHERE attempted_at < (NOW() - INTERVAL ? MINUTE)"
    )->execute([$LOCK_MINUTES]);

    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM login_attempts
         WHERE (username = ? OR ip_address = ?)
         AND attempted_at > (NOW() - INTERVAL ? MINUTE)"
    );
    $stmt->execute([$username, $ip, $LOCK_MINUTES]);
    $attempts = (int) $stmt->fetchColumn();

    if ($attempts >= $MAX_ATTEMPTS) {
        $error = "Too many failed attempts. Try again in $LOCK_MINUTES minutes.";
    } elseif (empty($username) || empty($password)) {
        $error = 'Please enter account name and password.';
    } else {
        $stmt = $pdo->prepare(
            "SELECT id, username, password, role, tenant_status
             FROM users
             WHERE LOWER(username) = LOWER(?)"
        );
        $stmt->execute([$username]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {

            // Tenant approval gate
            if ($user['role'] === 'tenant') {
                if ($user['tenant_status'] === 'pending') {
                    $error = 'Your account is awaiting owner approval. Please try again later.';
                } elseif ($user['tenant_status'] === 'declined') {
                    $error = 'Your account was declined. Please contact the owner.';
                }
            }

            if (empty($error)) {
                $pdo->prepare(
                    "DELETE FROM login_attempts
                     WHERE username = ? OR ip_address = ?"
                )->execute([$username, $ip]);

                session_regenerate_id(true);

                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];

                if ($user['role'] == 'owner') {
                    header('Location: owner_dashboard.php');
                    exit();
                } elseif ($user['role'] == 'tenant') {
                    header('Location: tenant_dashboard.php');
                    exit();
                } elseif ($user['role'] == 'appointment') {
                    header('Location: appointment_dashboard.php');
                    exit();
                } else {
                    $error = 'Invalid account role. Please contact the owner.';
                }
            } else {
                $pdo->prepare(
                    "INSERT INTO login_attempts
                     (username, ip_address, attempted_at)
                     VALUES (?, ?, NOW())"
                )->execute([$username, $ip]);
            }
        } else {
            $error = 'Invalid account name or password.';
            $pdo->prepare(
                "INSERT INTO login_attempts
                 (username, ip_address, attempted_at)
                 VALUES (?, ?, NOW())"
            )->execute([$username, $ip]);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login — Dannielle's Apartment</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Josefin+Sans:wght@300;400;500;600;700&family=Karla:wght@400;500;600;700&family=Cormorant+Garamond:ital,wght@1,500;1,600&display=swap" rel="stylesheet">
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
  --font-accent: 'Cormorant Garamond', serif;
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

.login-card {
  width: 100%;
  max-width: 420px;
  background: var(--bg-card);
  border-radius: 4px;
  border: 1px solid var(--line);
  box-shadow: 0 30px 60px -20px rgba(0,0,0,0.6);
  padding: 44px 40px 36px;
  position: relative;
}

.brand {
  text-align: center;
  margin-bottom: 28px;
}
.brand-text {
  font-family: var(--font-display);
  font-weight: 600;
  font-size: 0.95rem;
  letter-spacing: 0.18em;
  color: var(--ink);
  display: block;
  margin-bottom: 20px;
}
.brand h1 {
  font-family: var(--font-display);
  font-weight: 300;
  font-size: 1.9rem;
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

.field {
  margin-bottom: 18px;
}
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
  transition: border-color .2s ease, background .2s ease;
}
.field input:focus {
  outline: none;
  border-color: var(--brass);
  background: var(--bg-card);
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
}
.btn-primary:hover {
  background: transparent;
  color: var(--brass);
}

.msg {
  padding: 12px 14px;
  border-radius: 2px;
  font-size: 0.88rem;
  margin-bottom: 20px;
  text-align: center;
}
.msg.error {
  background: rgba(139, 74, 43, 0.12);
  color: var(--clay);
  border-left: 3px solid var(--clay);
}

.links {
  margin-top: 24px;
  text-align: center;
  font-size: 0.85rem;
  color: var(--ink-soft);
}
.links a {
  color: var(--brass);
  border-bottom: 1px solid transparent;
  transition: border-color .2s ease;
}
.links a:hover {
  border-bottom-color: var(--brass);
}
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
  transition: background .25s ease, color .25s ease;
}
.back-home:hover {
  background: var(--ink);
  color: var(--bg-card);
}
</style>
</head>
<body>

<div class="login-card">
  <div class="brand">
    <span class="brand-text">✦ DANIELLE'S APARTMENT</span>
    <h1>Welcome back</h1>
    <p class="eyebrow">Sign in to your account</p>
  </div>

  <?php if ($error): ?>
    <div class="msg error"><?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>

  <form method="POST" action="">
    <div class="field">
      <label>Account Name</label>
      <input
        type="text"
        name="username"
        required
        autocomplete="username"
        placeholder="Enter your account name"
      >
    </div>

    <div class="field">
      <label>Password</label>
      <input type="password" name="password" required autocomplete="current-password">
    </div>

    <button type="submit" class="btn-primary">Login</button>
  </form>

  <div class="links">
    <div class="row">Don't have an account? <a href="signup.php">Sign up here</a></div>
    <div class="row"><a href="homepage.php" class="back-home">← Back to Homepage</a></div>
  </div>
</div>

</body>
</html>