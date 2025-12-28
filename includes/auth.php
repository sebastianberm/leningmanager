<?php
require_once( __DIR__ . '/../includes/config.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function current_user() {
    return $_SESSION['user'] ?? null;
}
function is_logged_in() {
    return isset($_SESSION['user']);
}
function require_login() {
    if (!is_logged_in()) {
        header('Location: '.BASEDIR. '/login.php');
        exit;
    }
}
function require_role($roles) {
    $u = current_user();
    if (!$u || !in_array($u['role'], (array)$roles, true)) {
        http_response_code(403);
        echo "<h1>403 Forbidden</h1>";
        exit;
    }
}
