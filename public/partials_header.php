<?php if (session_status() === PHP_SESSION_NONE) { session_start(); } ?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Leningmanager</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="<?= BASE_PATH ?>/assets/styles.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark mb-4">
  <div class="container-fluid">
    <a class="navbar-brand" href="<?= BASE_PATH ?>/">Leningmanager</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="nav">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <?php if (!empty($_SESSION['user'])): ?>
          <li class="nav-item"><a class="nav-link" href="<?= BASE_PATH ?>/dashboard.php">📊 Dashboard</a></li>
          <li class="nav-item"><a class="nav-link" href="<?= BASE_PATH ?>/loans.php">💰 Leningen</a></li>
          <?php if (in_array($_SESSION['user']['role'], ['admin','manager'])): ?>
            <li class="nav-item"><a class="nav-link" href="<?= BASE_PATH ?>/users.php">👥 Gebruikers</a></li>
          <?php endif; ?>
        <?php endif; ?>
      </ul>
      <ul class="navbar-nav ms-auto">
        <?php if (!empty($_SESSION['user'])): ?>
          <li class="nav-item">
            <span class="nav-link">👤 <?= htmlspecialchars($_SESSION['user']['name']) ?> (<?= htmlspecialchars($_SESSION['user']['role']) ?>)</span>
          </li>
          <li class="nav-item"><a class="nav-link" href="<?= BASE_PATH ?>/logout.php">Uitloggen</a></li>
        <?php else: ?>
          <li class="nav-item"><a class="nav-link" href="<?= BASE_PATH ?>/login.php">Inloggen</a></li>
        <?php endif; ?>
      </ul>

    </div>
  </div>
</nav>
<div class="container">