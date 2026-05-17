<?php
session_start();

$sessionFile = __DIR__ . "/session.json";
$sessions = file_exists($sessionFile) ? json_decode(file_get_contents($sessionFile), true) : [];
if (!is_array($sessions)) $sessions = [];

$error = '';

if (isset($_COOKIE['sessionid']) && isset($sessions[$_COOKIE['sessionid']])) {
    header("Location: dashboard.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if ($username === "Garga" && $password === "2081") {
        $sessionId = bin2hex(random_bytes(16));
        $sessions[$sessionId] = [
            "username" => $username,
            "created_at" => time()
        ];
        file_put_contents($sessionFile, json_encode($sessions, JSON_PRETTY_PRINT));
        setcookie("sessionid", $sessionId, time() + 3600, "/");
        header("Location: dashboard.php");
        exit;
    } else {
        $error = "❌ Invalid username or password";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Garga Copy Udhyog - Login</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Poppins', sans-serif; }
body {
    height: 100vh;
    background: linear-gradient(135deg, #e0f7fa, #f9fbe7);
    display: flex;
    flex-direction: column;
    justify-content: center; /* vertical centering */
    align-items: center;
}

/* Login card */
.login-box {
    background: rgba(255,255,255,0.7);
    backdrop-filter: blur(12px);
    padding: 40px 35px;
    border-radius: 20px;
    width: 360px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.1);
    text-align: center;
    animation: fadeInUp 0.8s ease forwards;
}

.login-box h2 {
    color: #00796b;
    margin-bottom: 25px;
    font-weight: 600;
    font-size: 24px;
}

.login-box input {
    width: 100%;
    padding: 14px 15px;
    margin: 10px 0;
    border-radius: 12px;
    border: none;
    background: rgba(255,255,255,0.8);
    box-shadow: inset 0 2px 5px rgba(0,0,0,0.1);
    font-size: 15px;
    transition: all 0.3s ease;
}
.login-box input:focus {
    outline: none;
    box-shadow: 0 0 8px #26a69a;
    background: rgba(255,255,255,0.95);
}

.login-box button {
    width: 100%;
    padding: 14px 0;
    margin-top: 15px;
    border-radius: 12px;
    border: none;
    background: linear-gradient(90deg, #26a69a, #80cbc4);
    color: white;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
}
.login-box button:hover {
    background: linear-gradient(90deg, #00796b, #26a69a);
    transform: translateY(-2px);
}

.error {
    margin-top: 12px;
    color: #d32f2f;
    font-size: 14px;
}

/* Logo below login box */
.logo-below {
    margin-top: 15px; /* smaller gap */
}
.logo-below img {
    width: 140px;
    opacity: 0.95;
    transition: transform 0.3s;
}
.logo-below img:hover {
    transform: scale(1.05);
}

@keyframes fadeInUp {
    0% { opacity: 0; transform: translateY(30px); }
    100% { opacity: 1; transform: translateY(0); }
}
</style>
</head>
<body>
    <div class="login-box">
        <h2>Login to Garga Copy Udhyog</h2>
        <form method="POST" action="">
            <input type="text" name="username" placeholder="Enter username" required autocomplete="username">
            <input type="password" name="password" placeholder="Enter password" required autocomplete="current-password">
            <button type="submit">Login</button>
        </form>
        <?php if (!empty($error)): ?>
            <p class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
    </div>

    <div class="logo-below">
        <img src="assets/images/logo.png" alt="Garga Copy Udhyog Logo">
    </div>
</body>
</html>
