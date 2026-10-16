<?php
// config.php — reads secrets from .env

// --- Minimal .env loader (no Composer needed) ---
function load_env(string $path): void {
    if (!is_readable($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\"'");
        if (getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

load_env(__DIR__ . '/.env');

function env(string $key, ?string $default = null): string {
    $v = getenv($key);
    if ($v === false || $v === '') {
        if ($default === null) {
            http_response_code(500);
            error_log("Missing env: $key");
            exit("Server misconfigured");
        }
        return $default;
    }
    return $v;
}

date_default_timezone_set(env('APP_TIMEZONE', 'Asia/Manila'));

// --- Database ---
$host     = env('DB_HOST');
$dbname   = env('DB_NAME');
$username = env('DB_USER');
$password = env('DB_PASS', '');

$port = env('DB_PORT', '4000');

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

// Local: use certs/tidb-ca.pem
// Render: env var DB_SSL_CA_PEM contains the cert; write to /tmp
$ssl_ca_path = '';

$pem_from_env = env('DB_SSL_CA_PEM', '');
if ($pem_from_env !== '') {
    $ssl_ca_path = sys_get_temp_dir() . '/tidb-ca.pem';
    if (!file_exists($ssl_ca_path)) {
        file_put_contents($ssl_ca_path, $pem_from_env);
    }
} else {
    $local_cert = __DIR__ . '/' . env('DB_SSL_CA', 'certs/tidb-ca.pem');
    if (file_exists($local_cert)) {
        $ssl_ca_path = $local_cert;
    }
}

if ($ssl_ca_path !== '') {
    $options[PDO::MYSQL_ATTR_SSL_CA] = $ssl_ca_path;
}

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        $options
    );
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Ensure session is active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function csrf_token() {
    return $_SESSION['csrf_token'] ?? '';
}

function csrf_check() {
    $token = $_GET['token'] ?? $_POST['token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die('Invalid CSRF token');
    }
}

// ===== reCAPTCHA keys (from .env) =====
// Login does not use reCAPTCHA. Signup uses it.
// Empty defaults so missing vars don't kill the app.
define('RECAPTCHA_SITE_KEY',   env('RECAPTCHA_SITE_KEY', ''));
define('RECAPTCHA_SECRET_KEY', env('RECAPTCHA_SECRET_KEY', ''));

// Get unread message counts for a user
function getUnreadCounts($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT message_type, COUNT(*) as count 
                           FROM messages 
                           WHERE receiver_id = ? AND is_read = 0 
                           GROUP BY message_type");
    $stmt->execute([$user_id]);
    $counts = ['message' => 0, 'complaint' => 0];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $counts[$row['message_type']] = $row['count'];
    }
    return $counts;
}