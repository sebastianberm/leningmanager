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


function elapsed_months_from_start(?string $startDate): int {
    if (!$startDate) return 0;
    try {
        $start = new DateTimeImmutable($startDate);
        $today = new DateTimeImmutable('today');
    } catch (Exception $e) {
        return 0;
    }
    if ($start > $today) return 0;
    $diff = $start->diff($today);
    $months = ($diff->y * 12) + $diff->m;
    if ($today->format('d') < $start->format('d')) $months = max(0, $months - 1);
    return $months;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_mortgage'])) {
    $id = (int)($_POST['mortgage_id'] ?? 0);
    $del = $db->prepare('DELETE FROM mortgages WHERE id=?');
    $del->execute([$id]);
    header('Location: '.BASE_PATH.'/mortgages.php?dOK=1'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_mortgage'])) {
    $name = trim($_POST['name'] ?? '');
    $property_value = (float)($_POST['property_value'] ?? 0);
    $start_date = trim($_POST['start_date'] ?? '');
    $months_elapsed = max(0, (int)($_POST['months_elapsed'] ?? 0));
    if ($name === '' || $property_value <= 0 || $start_date === '') $errors[] = 'Vul alle hypotheekvelden correct in.';
    if (!$errors) {
        $ins = $db->prepare('INSERT INTO mortgages(owner_id,name,property_value,start_date,months_elapsed) VALUES(?,?,?,?,?)');
        $ins->execute([$u['id'], $name, $property_value, $start_date, $months_elapsed]);
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_component'])) {
    $id = (int)($_POST['component_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $rate = (float)($_POST['rate'] ?? 0);
    if ($id <= 0 || $name === '' || $rate < 0) $errors[] = 'Component bewerken mislukt.';
    if (!$errors) {
        $upd = $db->prepare('UPDATE mortgage_components SET name=?, rate=? WHERE id=?');
        $upd->execute([$name, $rate, $id]);
        header('Location: '.BASE_PATH.'/mortgages.php?uOK=1'); exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_component_event'])) {
    $component_id = (int)($_POST['component_id'] ?? 0);
    $month_index = max(0, (int)($_POST['month_index'] ?? 0));
    $rateRaw = trim((string)($_POST['event_rate'] ?? ''));
    $rate = $rateRaw === '' ? null : (float)$rateRaw;
    $extra = max(0, (float)($_POST['extra_payment'] ?? 0));

    if ($component_id <= 0) $errors[] = 'Ongeldige component.';
    if (!$errors) {
        $stmt = $db->prepare('INSERT INTO mortgage_component_events(component_id, month_index, rate, extra_payment) VALUES(?,?,?,?)
                              ON CONFLICT(component_id, month_index) DO UPDATE SET rate=excluded.rate, extra_payment=excluded.extra_payment');
        $stmt->execute([$component_id, $month_index, $rate, $extra]);
        header('Location: '.BASE_PATH.'/mortgages.php?eOK=1'); exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_value_event'])) {
    $mortgage_id = (int)($_POST['mortgage_id'] ?? 0);
    $month_index = max(0, (int)($_POST['month_index'] ?? 0));
    $property_value = (float)($_POST['property_value'] ?? 0);
    if ($mortgage_id <= 0 || $property_value <= 0) $errors[] = 'Vul waardewijziging correct in.';
    if (!$errors) {
        $stmt = $db->prepare('INSERT INTO mortgage_value_events(mortgage_id, month_index, property_value) VALUES(?,?,?)
            ON CONFLICT(mortgage_id, month_index) DO UPDATE SET property_value=excluded.property_value');
        $stmt->execute([$mortgage_id, $month_index, $property_value]);
        header('Location: '.BASE_PATH.'/mortgages.php?vOK=1'); exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_paid_months'])) {
    $mortgage_id = (int)($_POST['mortgage_id'] ?? 0);
    $mode = $_POST['paid_mode'] ?? 'manual';
    $manual_months = max(0, (int)($_POST['months_elapsed'] ?? 0));
    if ($mortgage_id <= 0) $errors[] = 'Ongeldige hypotheek.';
    if (!$errors) {
        $stmt = $db->prepare('SELECT start_date FROM mortgages WHERE id=?');
        $stmt->execute([$mortgage_id]);
        $start_date = $stmt->fetchColumn();
        $months_elapsed = $mode === 'auto' ? elapsed_months_from_start($start_date ?: null) : $manual_months;
        $upd = $db->prepare('UPDATE mortgages SET months_elapsed=? WHERE id=?');
        $upd->execute([$months_elapsed, $mortgage_id]);
        header('Location: '.BASE_PATH.'/mortgages.php?pOK=1'); exit;
    }
}

$mortgages = $db->query('SELECT * FROM mortgages ORDER BY created_at DESC')->fetchAll();
include __DIR__ . '/partials_header.php';
?>
<div class="card p-3 mb-4">
  <h1>Hypotheken (beheer)</h1>
  <p class="text-muted">Los overzicht voor beheerders met meerdere hypotheekdelen, events en maandelijkse LTV/L2V.</p>
  <?php if ($errors): ?><div class="alert alert-danger"><?=implode('<br>', array_map('h',$errors))?></div><?php endif; ?>
  <?php if (isset($_GET['mOK'])): ?><div class="alert alert-success">Hypotheek opgeslagen.</div><?php endif; ?>
  <?php if (isset($_GET['cOK'])): ?><div class="alert alert-success">Component opgeslagen.</div><?php endif; ?>
  <?php if (isset($_GET['uOK'])): ?><div class="alert alert-success">Component bijgewerkt.</div><?php endif; ?>
  <?php if (isset($_GET['eOK'])): ?><div class="alert alert-success">Component-event opgeslagen.</div><?php endif; ?>
  <?php if (isset($_GET['vOK'])): ?><div class="alert alert-success">Woningwaarde-event opgeslagen.</div><?php endif; ?>
  <?php if (isset($_GET['dOK'])): ?><div class="alert alert-success">Hypotheek verwijderd.</div><?php endif; ?>
  <?php if (isset($_GET['pOK'])): ?><div class="alert alert-success">Betaalde maanden bijgewerkt.</div><?php endif; ?>
</div>
<div class="card p-3 mb-4"><h5>Nieuwe hypotheek</h5><form method="post"><?php csrf_field(); ?><input type="hidden" name="create_mortgage" value="1">
<div class="row g-2"><div class="col-md-3"><input class="form-control" name="name" placeholder="Naam"></div><div class="col-md-3"><input class="form-control" type="number" step="0.01" name="property_value" placeholder="Woningwaarde"></div><div class="col-md-3"><input class="form-control" type="date" name="start_date"></div><div class="col-md-3"><input class="form-control" type="number" name="months_elapsed" placeholder="Reeds betaald (maanden)"></div></div><button class="btn btn-primary mt-2">Opslaan</button></form></div>

<?php foreach ($mortgages as $m):
$compStmt = $db->prepare('SELECT * FROM mortgage_components WHERE mortgage_id=? ORDER BY id');
$compStmt->execute([$m['id']]);
$components = $compStmt->fetchAll();
$eventsStmt = $db->prepare('SELECT * FROM mortgage_component_events WHERE component_id IN (SELECT id FROM mortgage_components WHERE mortgage_id=?) ORDER BY month_index');
$eventsStmt->execute([$m['id']]);
$componentEvents=[]; foreach($eventsStmt->fetchAll() as $e){$componentEvents[(int)$e['component_id']][(int)$e['month_index']]=$e;}
$valStmt = $db->prepare('SELECT * FROM mortgage_value_events WHERE mortgage_id=? ORDER BY month_index');
$valStmt->execute([$m['id']]);
$valueEvents=[]; foreach($valStmt->fetchAll() as $v){$valueEvents[(int)$v['month_index']]=$v['property_value'];}
$maxMonths = max(array_map(fn($c)=>(int)$c['term_months'], $components) ?: [0]);
$projection = build_mortgage_projection($components, $componentEvents, (float)$m['property_value'], $valueEvents, $maxMonths);
?>
<div class="card p-3 mb-4"><div class="d-flex justify-content-between"><h4><?=h($m['name'])?></h4><form method="post" onsubmit="return confirm('Weet je het zeker?')"><?php csrf_field(); ?><input type="hidden" name="delete_mortgage" value="1"><input type="hidden" name="mortgage_id" value="<?=$m['id']?>"><button class="btn btn-sm btn-danger">Hypotheek wissen</button></form></div>
<?php $autoMonths = elapsed_months_from_start($m['start_date'] ?? null); ?>
<p>Woningwaarde start: <?=money_fmt($m['property_value'])?> · Reeds betaald: <?= (int)$m['months_elapsed'] ?> maanden · Verwacht o.b.v. startdatum (<?=h($m['start_date'])?>): <?=$autoMonths?> maanden</p>
<form method="post" class="row g-2 align-items-end border rounded p-2 mb-3"><?php csrf_field(); ?><input type="hidden" name="update_paid_months" value="1"><input type="hidden" name="mortgage_id" value="<?=$m['id']?>">
<div class="col-md-3"><label class="form-label mb-0">Betaalde maanden</label><input class="form-control" type="number" min="0" name="months_elapsed" value="<?= (int)$m['months_elapsed'] ?>"></div>
<div class="col-md-4"><div class="form-check"><input class="form-check-input" type="radio" name="paid_mode" id="paid_manual<?=$m['id']?>" value="manual" checked><label class="form-check-label" for="paid_manual<?=$m['id']?>">Handmatig gebruiken</label></div><div class="form-check"><input class="form-check-input" type="radio" name="paid_mode" id="paid_auto<?=$m['id']?>" value="auto"><label class="form-check-label" for="paid_auto<?=$m['id']?>">Automatisch t/m vandaag (<?=$autoMonths?>)</label></div></div>
<div class="col-md-2"><button class="btn btn-outline-primary">Opslaan</button></div></form>
<form method="post" class="border p-2 mb-2"><?php csrf_field(); ?><input type="hidden" name="create_component" value="1"><input type="hidden" name="mortgage_id" value="<?=$m['id']?>">
<div class="row g-2"><div class="col"><input class="form-control" name="component_name" placeholder="Naam"></div><div class="col"><input class="form-control" name="principal" type="number" step="0.01" placeholder="Hoofdsom"></div><div class="col"><input class="form-control" name="rate" type="number" step="0.0001" placeholder="Rente"></div><div class="col"><input class="form-control" name="term_months" type="number" placeholder="Looptijd"></div><div class="col"><input class="form-control" name="fixed_rate_months" type="number" placeholder="Renteduur"></div><div class="col"><select class="form-select" name="type"><option value="annuity">Annuïtair</option><option value="linear">Lineair</option><option value="interest_only">Aflossingsvrij</option></select></div></div><button class="btn btn-outline-primary mt-2">Component toevoegen</button></form>

<table class="table table-sm"><thead><tr><th>Naam</th><th>Type</th><th>Rente</th><th>Acties</th></tr></thead><tbody><?php foreach($components as $c): ?>
<tr><td><?=h($c['name'])?></td><td><?=h($c['type'])?></td><td><?=$c['rate']?>%</td><td>
<form method="post" class="row g-1"><?php csrf_field(); ?><input type="hidden" name="update_component" value="1"><input type="hidden" name="component_id" value="<?=$c['id']?>"><div class="col"><input class="form-control form-control-sm" name="name" value="<?=h($c['name'])?>"></div><div class="col"><input class="form-control form-control-sm" type="number" step="0.0001" name="rate" value="<?=$c['rate']?>"></div><div class="col-auto"><button class="btn btn-sm btn-secondary">Opslaan</button></div></form>
<form method="post" class="row g-1 mt-1"><?php csrf_field(); ?><input type="hidden" name="save_component_event" value="1"><input type="hidden" name="component_id" value="<?=$c['id']?>"><div class="col"><input class="form-control form-control-sm" type="number" name="month_index" placeholder="maand"></div><div class="col"><input class="form-control form-control-sm" type="number" step="0.0001" name="event_rate" placeholder="nieuwe rente %"></div><div class="col"><input class="form-control form-control-sm" type="number" step="0.01" name="extra_payment" placeholder="extra aflossing"></div><div class="col-auto"><button class="btn btn-sm btn-outline-primary">Event opslaan</button></div></form>
</td></tr><?php endforeach; ?></tbody></table>

<form method="post" class="border p-2 mb-3"><?php csrf_field(); ?><input type="hidden" name="save_value_event" value="1"><input type="hidden" name="mortgage_id" value="<?=$m['id']?>"><div class="row g-2"><div class="col-md-3"><input class="form-control" type="number" name="month_index" placeholder="Maand index"></div><div class="col-md-3"><input class="form-control" type="number" step="0.01" name="property_value" placeholder="Nieuwe woningwaarde"></div><div class="col-md-3"><button class="btn btn-outline-primary">Waarde-event opslaan</button></div></div></form>

<table class="table table-striped table-sm"><thead><tr><th>Maand</th><th>Status</th><th>Maandbedrag</th><th>Restschuld</th><th>Woningwaarde</th><th>LTV/L2V</th><th>+ Aflossing</th></tr></thead><tbody>
<?php foreach($projection['rows'] as $r): $paid = $r['month'] <= (int)$m['months_elapsed']; ?><tr><td><?=$r['month']?></td><td><?=$paid?'Betaald':'Prognose'?></td><td><?=money_fmt($r['payment'])?></td><td><?=money_fmt($r['remaining'])?></td><td><?=money_fmt($r['property_value'])?></td><td><?=number_format($r['ltv'],2,',','.')?>%</td><td><details><summary class="text-primary" style="cursor:pointer">＋</summary><form method="post" class="row g-1 mt-1"><?php csrf_field(); ?><input type="hidden" name="save_component_event" value="1"><div class="col-12"><select class="form-select form-select-sm" name="component_id" required><option value="">Kies deel</option><?php foreach($components as $compOpt): ?><option value="<?=$compOpt['id']?>"><?=h($compOpt['name'])?></option><?php endforeach; ?></select></div><input type="hidden" name="month_index" value="<?=$r['month']?>"><div class="col-6"><input class="form-control form-control-sm" type="number" step="0.0001" name="event_rate" placeholder="rente %"></div><div class="col-6"><input class="form-control form-control-sm" type="number" step="0.01" name="extra_payment" placeholder="extra"></div><div class="col-12"><button class="btn btn-sm btn-outline-primary w-100">Opslaan</button></div></form></details></td></tr><?php endforeach; ?>
</tbody></table>
<canvas id="chart<?=$m['id']?>" height="120"></canvas>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(() => {
const rows = <?=json_encode($projection['rows'])?>;
new Chart(document.getElementById('chart<?=$m['id']?>'), {type:'line', data:{labels:rows.map(r=>'M'+r.month), datasets:[{label:'Woningwaarde', data:rows.map(r=>r.property_value), borderColor:'#198754'},{label:'Restschuld', data:rows.map(r=>r.remaining), borderColor:'#dc3545'}]}});
})();
</script>
</div>
<?php endforeach; ?>
<?php include __DIR__ . '/partials_footer.php'; ?>
