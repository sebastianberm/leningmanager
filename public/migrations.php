<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/csrf.php';
require_login();

$u = current_user();
if (!in_array($u['role'], ['admin','manager'], true)) { http_response_code(403); exit; }

require_once __DIR__ . '/../includes/migrations.php';

// list migration files and statuses
$files = list_migration_files();
$appliedStmt = $db->query("SELECT name, applied_at FROM migrations");
$appliedRows = $appliedStmt->fetchAll(PDO::FETCH_ASSOC);
$applied = [];
foreach ($appliedRows as $r) $applied[$r['name']] = $r['applied_at'];

include __DIR__ . '/partials_header.php';
?>
<div class="card p-3 mb-4">
  <h3>Migraties</h3>
  <p class="text-muted">Overzicht van beschikbare migraties en status. Alleen beheerders/manager kunnen migraties uitvoeren.</p>
  <table class="table">
    <thead><tr><th>Naam</th><th>Status</th><th>Toegepast op</th></tr></thead>
    <tbody>
    <?php foreach ($files as $f):
        $m = include $f;
        $name = $m['name'] ?? basename($f);
        $isApplied = isset($applied[$name]);
        $appliedAt = $applied[$name] ?? '';
    ?>
      <tr>
        <td><?= h($name) ?></td>
        <td><?= $isApplied ? '<span class="badge bg-success">Toegepast</span>' : '<span class="badge bg-warning">Niet toegepast</span>' ?></td>
        <td><?= $appliedAt ? h($appliedAt) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <form method="post" action="<?= BASE_PATH ?>/run_migrations.php">
    <?php csrf_field(); ?>
    <button class="btn btn-primary">Voer pending migraties uit</button>
  </form>
</div>

<?php include __DIR__ . '/partials_footer.php';
