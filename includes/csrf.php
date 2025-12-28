<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
function csrf_token() {
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}
function csrf_field() {
    $t = htmlspecialchars(csrf_token());
    echo "<input type='hidden' name='_csrf' value='{$t}'>";
}
function verify_csrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $ok = isset($_POST['_csrf']) && hash_equals($_SESSION['_csrf'] ?? '', $_POST['_csrf']);
        if (!$ok) {
            http_response_code(400);
            echo "<h1>400 Bad Request</h1><p>Invalid CSRF token.</p>";
            exit;
        }
    }
}
