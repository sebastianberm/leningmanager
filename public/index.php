<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
include __DIR__ . '/partials_header.php';
?>
<div class="row">
  <div class="col-lg-8">
    <div class="card p-4 mb-4">
      <h1 class="mb-3">Welkom bij Leningmanager</h1>
      <p class="text-muted">Beheer leningen, betalingen en aflossingsschema’s. Geef leningnemers een login zodat ze hun eigen lening kunnen inzien.</p>
      <?php if (!is_logged_in()): ?>
        <a href="<?= BASE_PATH ?>/login.php" class="btn btn-primary">Inloggen</a>
        <a href="<?= BASE_PATH ?>/setup.php" class="btn btn-outline-light">Eerste gebruiker aanmaken</a>
      <?php else: ?>
        <a href="<?= BASE_PATH ?>/loans.php" class="btn btn-primary">Ga naar leningen</a>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card p-4">
      <h5>Snelle tips</h5>
      <ul>
        <li>Eerste keer? Gebruik <a href="<?= BASE_PATH ?>/setup.php">Setup</a> om een admin aan te maken.</li>
        <li>Maak daarna andere gebruikers aan: manager of borrower.</li>
        <li>Koppel per lening een borrower voor read-only inzage.</li>
      </ul>
    </div>
  </div>
</div>
<?php include __DIR__ . '/partials_footer.php'; ?>
