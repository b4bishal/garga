<?php
$sessionFile = "session.json";
$sessions = file_exists($sessionFile) ? json_decode(file_get_contents($sessionFile), true) : [];

if (isset($_COOKIE['sessionid'])) {
    $sid = $_COOKIE['sessionid'];
    if (isset($sessions[$sid])) {
        unset($sessions[$sid]);
        file_put_contents($sessionFile, json_encode($sessions, JSON_PRETTY_PRINT));
    }
    setcookie("sessionid", "", time() - 3600, "/");
}

header("Location: login.php");
exit;
