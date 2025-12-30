<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/csrf.php';
require_login();

$u = current_user();
if (!in_array($u['role'], ['admin','manager'], true)) { http_response_code(403); exit; }
verify_csrf();

require_once __DIR__ . '/../includes/migrations.php';
$results = apply_pending_migrations($db);

// store results in session for feedback
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['migrations_results'] = $results;
header('Location: ' . BASE_PATH . '/dashboard.php?migrations=1');
exit;
