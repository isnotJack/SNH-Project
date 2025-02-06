<?php
require_once 'config.php';
require_once 'csrf.php';
require 'vendor/autoload.php';
use Dotenv\Dotenv;

session_start();
$inactive = 300; // 5 minutes session timeout

function showMessage($message, $type = "error") {
    $color = $type === "success" ? "#28a745" : "#dc3545"; // Green for success, red for error
    echo "<div style='padding: 10px; margin: 10px 0; border-radius: 5px; background: $color; color: white; text-align: center; font-weight: bold;'>
            $message
          </div>";
}

// Check if session has timed out
if (isset($_SESSION['timeout']) && (time() - $_SESSION['timeout'] > $inactive)) {
    session_unset();
    session_destroy();
    showMessage("Session expired. Please log in again.");
    exit();
}


$_SESSION['timeout'] = time(); // Update session timeout

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();
$stmt = $conn->prepare('SELECT id, username, password_hash, is_premium, role FROM Users WHERE username = ?');
$stmt->bind_param('s', $username);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows > 0) {
    $stmt->bind_result($user_id, $db_username, $password_hash, $is_premium, $role);
    $stmt->fetch();
    # dobbiamo verificare che password_hash sia una stringa e NOT NULL per type juggling
    if (!is_string($password_hash) || empty($password_hash)) {
        die('Authentication error.');
    }        
    if (password_verify($password, $password_hash)) {
        // Store user info in session
        $_SESSION['user_id'] = $user_id;
        $_SESSION['username'] = $db_username;
        $_SESSION['is_premium'] = $is_premium;
        $_SESSION['role'] = $role;

        header('Location: home.php');
        exit();
    } else {
        echo 'Invalid username or password!';
    }
} else {
    echo 'Invalid username or password!';
}


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit();
}

if (!isset($_POST['token_csrf']) || !verifyToken($_POST['token_csrf'])) {
    die("Error, invalid csrf token"); ### DA CAMBIARE PERCHè SPECIFICO
    exit();
}


// Validate required fields
$username = trim($_POST['username'] ?? '');
$password = trim($_POST['password'] ?? '');
$recaptcha_response = $_POST['g-recaptcha-response'] ?? '';

if (empty($username) || empty($password) || empty($recaptcha_response)) {
    showMessage("All fields are required!");
    exit();
}

// Verify reCAPTCHA
$recaptcha_secret = $_ENV['RECAPTCHA_V2_SECRETKEY'];
$recaptcha_url = 'https://www.google.com/recaptcha/api/siteverify';

$ch = curl_init($recaptcha_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, [
    'secret' => $recaptcha_secret,
    'response' => $recaptcha_response
]);
$recaptcha_verify = curl_exec($ch);
curl_close($ch);

$recaptcha_data = json_decode($recaptcha_verify, true);

if (!$recaptcha_data || !$recaptcha_data['success']) {
    showMessage("reCAPTCHA verification failed! Please try again.");
    exit();
}

// Prepare SQL query
$stmt = $conn->prepare('SELECT id, username, password_hash, is_premium, role FROM Users WHERE username = ?');
$stmt->bind_param('s', $username);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows > 0) {
    $stmt->bind_result($user_id, $db_username, $password_hash, $is_premium, $role);
    $stmt->fetch();

    if ($password_hash && password_verify($password, $password_hash)) {
        session_regenerate_id(true); // Prevent session fixation
        newToken();
        // Store user info in session
        $_SESSION['user_id'] = $user_id;
        $_SESSION['username'] = $db_username;
        $_SESSION['is_premium'] = $is_premium;
        $_SESSION['role'] = $role;

        showMessage("Login successful! Redirecting...", "success");
        echo '<script>setTimeout(function(){ window.location.href = "home.php"; }, 2000);</script>';
        exit();
    }
}

// Login failed
showMessage("Invalid username or password!");
exit();

$stmt->close();
$conn->close();
?>
