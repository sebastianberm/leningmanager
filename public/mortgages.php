<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/functions.php';
verify_csrf();
require_login();

$u = current_user();
$is_staff = in_array($u['role'], ['admin','manager'], true);
if (!$is_staff) { http_response_code(403); exit('Alleen beheerders.'); }

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_mortgage'])) {
    $name = trim($_POST['name'] ?? '');
    $property_value = (float)($_POST['property_value'] ?? 0);
    $start_date = trim($_POST['start_date'] ?? '');
    if ($name === '' || $property_value <= 0 || $start_date === '') $errors[] = 'Vul alle hypotheekvelden correct in.';
    if (!$errors) {
        $ins = $db->prepare('INSERT INTO mortgages(owner_id,name,property_value,start_date) VALUES(?,?,?,?)');
        $ins->execute([$u['id'], $name, $property_value, $start_date]);
        header('Location: '.BASE_PATH.'/mortgages.php?mOK=1'); exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_component'])) {
    $mortgage_id = (int)($_POST['mortgage_id'] ?? 0);
    $name = trim($_POST['component_name'] ?? '');
    $principal = (float)($_POST['principal'] ?? 0);
    $rate = (float)($_POST['rate'] ?? 0);
    $term = (int)($_POST['term_months'] ?? 0);
    $fixed = (int)($_POST['fixed_rate_months'] ?? 0);
    $type = $_POST['type'] ?? 'annuity';
    if ($mortgage_id <= 0 || $name === '' || $principal <= 0 || $term <= 0 || $fixed <= 0) $errors[] = 'Vul alle componentvelden correct in.';
    if (!in_array($type, ['annuity','linear','interest_only'], true)) $errors[] = 'Ongeldig type.';
    if ($fixed > $term) $errors[] = 'Renteduur kan niet langer zijn dan de looptijd.';
    if (!$errors) {
        $ins = $db->prepare('INSERT INTO mortgage_components(mortgage_id,name,principal,rate,term_months,fixed_rate_months,type) VALUES(?,?,?,?,?,?,?)');
        $ins->execute([$mortgage_id, $name, $principal, $rate, $term, $fixed, $type]);
        header('Location: '.BASE_PATH.'/mortgages.php?cOK=1'); exit;
    }
}

$mortgages = $db->query('SELECT * FROM mortgages ORDER BY created_at DESC')->fetchAll();
$componentsByMortgage = [];
$maxMonthsByMortgage = [];
foreach ($mortgages as $m) {
    $stmt = $db->prepare('SELECT * FROM mortgage_components WHERE mortgage_id=? ORDER BY created_at ASC, id ASC');
    $stmt->execute([$m['id']]);
    $rows = $stmt->fetchAll();
    $componentsByMortgage[$m['id']] = $rows;
    $maxMonthsByMortgage[$m['id']] = max(array_map(fn($c)=>(int)$c['term_months'], $rows) ?: [0]);
}

include __DIR__ . '/partials_header.php';
?>
<div class="card p-3 mb-4">
  <h1>Hypotheken (beheer)</h1>
  <p class="text-muted">Los overzicht voor beheerders met meerdere hypotheekdelen en maandelijkse LTV/L2V.</p>
  <?php if ($errors): ?><div class="alert alert-danger"><?=implode('<br>', array_map('h',$errors))?></div><?php endif; ?>
  <?php if (isset($_GET['mOK'])): ?><div class="alert alert-success">Hypotheek opgeslagen.</div><?php endif; ?>
  <?php if (isset($_GET['cOK'])): ?><div class="alert alert-success">Component opgeslagen.</div><?php endif; ?>
</div>

<div class="card p-3 mb-4">
  <h5>Nieuwe hypotheek</h5>
  <form method="post">
    <?php csrf_field(); ?><input type="hidden" name="create_mortgage" value="1">
    <div class="row g-3">
      <div class="col-md-4"><label class="form-label">Naam</label><input class="form-control" name="name"></div>
      <div class="col-md-4"><label class="form-label">Woningwaarde (€)</label><input class="form-control" type="number" step="0.01" name="property_value"></div>
      <div class="col-md-4"><label class="form-label">Startdatum</label><input class="form-control" type="date" name="start_date"></div>
    </div>
    <button class="btn btn-primary mt-3">Opslaan</button>
  </form>
</div>

<?php foreach ($mortgages as $m): $components = $componentsByMortgage[$m['id']] ?? []; ?>
<div class="card p-3 mb-4">
  <h4><?=h($m['name'])?></h4>
  <p><strong>Woningwaarde:</strong> <?=money_fmt($m['property_value'])?> · <strong>Start:</strong> <?=h($m['start_date'])?></p>
  <form method="post" class="border rounded p-3 mb-3">
    <?php csrf_field(); ?><input type="hidden" name="create_component" value="1"><input type="hidden" name="mortgage_id" value="<?=$m['id']?>">
    <div class="row g-2">
      <div class="col-md-2"><input class="form-control" name="component_name" placeholder="Component"></div>
      <div class="col-md-2"><input class="form-control" name="principal" type="number" step="0.01" placeholder="Hoofdsom"></div>
      <div class="col-md-2"><input class="form-control" name="rate" type="number" step="0.0001" placeholder="Rente %"></div>
      <div class="col-md-2"><input class="form-control" name="term_months" type="number" placeholder="Looptijd mnd"></div>
      <div class="col-md-2"><input class="form-control" name="fixed_rate_months" type="number" placeholder="Renteduur mnd"></div>
      <div class="col-md-2"><select class="form-select" name="type"><option value="annuity">Annuïtair</option><option value="linear">Lineair</option><option value="interest_only">Aflossingsvrij</option></select></div>
    </div>
    <button class="btn btn-outline-primary mt-2">Component toevoegen</button>
  </form>

  <table class="table table-sm">
    <thead><tr><th>Component</th><th>Type</th><th>Hoofdsom</th><th>Rente</th><th>Looptijd</th><th>Renteduur</th></tr></thead>
    <tbody><?php foreach ($components as $c): ?><tr><td><?=h($c['name'])?></td><td><?=h($c['type'])?></td><td><?=money_fmt($c['principal'])?></td><td><?=h($c['rate'])?>%</td><td><?=h($c['term_months'])?></td><td><?=h($c['fixed_rate_months'])?></td></tr><?php endforeach; ?></tbody>
  </table>

  <?php if ($components):
    $maxMonths = $maxMonthsByMortgage[$m['id']] ?? 0;
    $perMonth = build_mortgage_ltv_overview($components, (float)$m['property_value'], $maxMonths);
  ?>
  <h6>Maandelijkse LTV/L2V</h6>
  <table class="table table-striped table-sm">
    <thead><tr><th>Maand</th><th>Totale restschuld</th><th>LTV/L2V</th></tr></thead>
    <tbody>
    <?php foreach ($perMonth as $row): ?>
      <tr><td><?=$row['month']?></td><td><?=money_fmt($row['remaining'])?></td><td><?=number_format($row['ltv'], 2, ',', '.')?>%</td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<?php include __DIR__ . '/partials_footer.php'; ?>
