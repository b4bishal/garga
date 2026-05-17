<?php
session_start();

// Load sessions.json
$sessionFile = __DIR__ . "/session.json";
$sessions = file_exists($sessionFile) ? json_decode(file_get_contents($sessionFile), true) : [];
if (!is_array($sessions)) $sessions = [];

// Check if user is logged in
if (!isset($_COOKIE['sessionid']) || !isset($sessions[$_COOKIE['sessionid']])) {
    header("Location: login.php");
    exit;
}

$user = $sessions[$_COOKIE['sessionid']]['username'];

// Handle logout
if(isset($_POST['logout'])){
    $sid = $_COOKIE['sessionid'];
    if(isset($sessions[$sid])){
        unset($sessions[$sid]);
        file_put_contents($sessionFile, json_encode($sessions, JSON_PRETTY_PRINT));
    }
    setcookie("sessionid", "", time()-3600, "/");
    header("Location: login.php");
    exit;
}
?>

<header>
    <a href="dashboard.php"><img src="assets/images/logo.png" alt="Logo"></a>
    <form method="POST" style="margin-left:auto;">
        <button type="submit" name="logout" class="logout-btn">Logout</button>
    </form>
</header>

<style>
header {
    display: flex;
    align-items: center;
    padding: 0px 20px;
    background: rgba(255,255,255,0.85);
    backdrop-filter: blur(12px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}
header img {
    height: 70px;
    cursor: pointer;
}
header .logout-btn {
    margin-left: auto;
    padding: 10px 22px;
    background: #e53935;
    color: white;
    border: none;
    border-radius: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.3s;
}
header .logout-btn:hover { background: #c62828; }
</style>
