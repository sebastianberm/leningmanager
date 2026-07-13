<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/config.php';
verify_csrf();
require_login();

$id = (int)($_GET['id'] ?? 0);
$q = $db->prepare("SELECT * FROM loans WHERE id=?");
$q->execute([$id]);
$loan = $q->fetch();
if (!$loan) { echo "Niet gevonden"; exit; }

$u = current_user();
$is_owner = ($u['id'] === (int)$loan['owner_id']);
$is_staff = in_array($u['role'], ['admin','manager'], true);
$can_edit = $is_staff || $is_owner;
$is_borrower_view = ($u['id'] === (int)$loan['borrower_id'] && $u['role']==='borrower');

$errors=[];
// Delete payment
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete_payment'])) {
    if (!$can_edit || $u['role']==='borrower') { http_response_code(403); exit; }
    $payment_id = (int)$_POST['delete_payment'];
    $del = $db->prepare("DELETE FROM payments WHERE id=? AND loan_id=?");
    $del->execute([$payment_id, $loan['id']]);
    header('Location: '.BASEDIR.'/loan.php?id='.$loan['id'].'&pDelete=1'); exit;
}

// Update payment
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['update_payment'])) {
    if (!$can_edit || $u['role']==='borrower') { http_response_code(403); exit; }
    $payment_id = (int)$_POST['payment_id'];
    $date = $_POST['date'] ?? '';
    $raw_amount = (float)($_POST['amount'] ?? 0);
    $raw_transaction_type = $_POST['transaction_type'] ?? 'payment';
    $transaction_type = normalize_transaction_type($raw_transaction_type);
    $amount = $transaction_type === 'principal_increase' ? abs($raw_amount) : $raw_amount;
    $note = trim($_POST['note'] ?? '');
    if ($date=='' || $amount<=0) $errors[]='Datum en bedrag zijn verplicht.';
    if (!is_valid_transaction_type($raw_transaction_type)) $errors[]='Ongeldig transactietype.';
    if (!$errors) {
        $upd = $db->prepare("UPDATE payments SET date=?, amount=?, transaction_type=?, note=? WHERE id=? AND loan_id=?");
        $upd->execute([$date, $amount, $transaction_type, $note, $payment_id, $loan['id']]);
        header('Location: '.BASEDIR.'/loan.php?id='.$loan['id'].'&pEdit=1'); exit;
    }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_payment'])) {
    if (!$can_edit || $u['role']==='borrower') { http_response_code(403); exit; }
    $date = $_POST['date'] ?? '';
    $raw_amount = (float)($_POST['amount'] ?? 0);
    $raw_transaction_type = $_POST['transaction_type'] ?? 'payment';
    $transaction_type = normalize_transaction_type($raw_transaction_type);
    $amount = $transaction_type === 'principal_increase' ? abs($raw_amount) : $raw_amount;
    $note = trim($_POST['note'] ?? '');
    if ($date=='' || $amount<=0) $errors[]='Datum en bedrag zijn verplicht.';
    if (!is_valid_transaction_type($raw_transaction_type)) $errors[]='Ongeldig transactietype.';
    if (!$errors) {
        $ins=$db->prepare("INSERT INTO payments(loan_id,date,amount,transaction_type,note) VALUES(?,?,?,?,?)");
        $ins->execute([$loan['id'],$date,$amount,$transaction_type,$note]);

        $paymentsStmt = $db->prepare("SELECT * FROM payments WHERE loan_id=? ORDER BY date ASC, id ASC");
        $paymentsStmt->execute([$loan['id']]);
        $payments = $paymentsStmt->fetchAll();
        $alloc = compute_allocation_with_payments($loan, $payments);
        $last = end($alloc['allocations']);
        if (WEBHOOK_URL) {
            $payload = [
                'loan_id' => (int)$loan['id'],
                'loan_name' => $loan['name'],
                'dateadded' => $last['date'] ?? $date,
                'amount' => $last['amount'] ?? $amount,
                'calculated_interest' => $last['interest'] ?? null,
                'calculated_payment' => $last['principal'] ?? null,
                'transaction_type' => $last['transaction_type'] ?? $transaction_type,
                'amount_left' => $alloc['remaining'],
            ];
            curl_post_json(WEBHOOK_URL, $payload);
        }
        header('Location: '.BASEDIR.'/loan.php?id='.$loan['id'].'&pOK=1'); exit;
    }
}

// Update loan details (CRUD edit)
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['update_loan'])) {
  if (!$can_edit) { http_response_code(403); exit; }
  $name = trim($_POST['name'] ?? '');
  $principal = (float)($_POST['principal'] ?? 0);
  $rate = (float)($_POST['rate'] ?? 0);
  $start_date = trim($_POST['start_date'] ?? '');
  $term = (int)($_POST['term_months'] ?? 0);
  $type = $_POST['type'] ?? 'annuity';
  $borrower_id = $_POST['borrower_id'] ? (int)$_POST['borrower_id'] : null;
  $lender_type = in_array($_POST['lender_type'] ?? '', ['company','private'], true) ? $_POST['lender_type'] : (defined('DEFAULT_LENDER_TYPE') ? DEFAULT_LENDER_TYPE : 'private');

  if ($name=='' || $principal<=0 || $term<=0 || $start_date=='') $errors[]='Vul alle velden correct in.';
  if (!in_array($type, ['annuity','linear'], true)) $errors[]='Ongeldig type.';
  if (!$errors) {
    $upd = $db->prepare("UPDATE loans SET borrower_id=?, name=?, principal=?, rate=?, start_date=?, term_months=?, type=?, lender_type=? WHERE id=?");
    $upd->execute([$borrower_id, $name, $principal, $rate, $start_date, $term, $type, $lender_type, $loan['id']]);
    header('Location: '.BASEDIR.'/loan.php?id='.$loan['id'].'&ok=1'); exit;
  }
}



$paymentsStmt = $db->prepare("SELECT * FROM payments WHERE loan_id=? ORDER BY date ASC, id ASC");
$paymentsStmt->execute([$loan['id']]);
$payments = $paymentsStmt->fetchAll();

$plan = schedule($loan['principal'], $loan['rate'], $loan['term_months'], $loan['type']);
$alloc = compute_allocation_with_payments($loan, $payments);
$current_remaining = $alloc['remaining'];
$months_left = loan_months_left($loan, $alloc['allocations']);
$elapsed_payment_periods = loan_elapsed_payment_periods($alloc['allocations']);
$new_payment = calculate_new_payment($current_remaining, $loan['rate'], $months_left);

// Prognose: bereken restant-schema vanaf laatste betaling
$projection = [];
if ($months_left > 0) {
    $projection = generate_projection_schedule($current_remaining, $loan['rate'], $months_left, $loan['type']);
    // plak lege rijen voor eerdere maanden zodat grafieklijnen netjes gelijk lopen
    for ($i = 0; $i < $elapsed_payment_periods; $i++) {
        array_unshift($projection, ['remaining' => null]);
    }
}

$labels = range(1, $loan['term_months']);
$scheduledRemaining = array_column($plan, 'remaining');
$actualRemaining = array_column($alloc['allocations'], 'remaining');
$projRemaining = array_column($projection, 'remaining');
?>

<?php include __DIR__ . '/partials_header.php'; ?>

<div class="card p-3 mb-4">
  <h1><?= h($loan['name']) ?></h1>
  <p><strong>Huidige restschuld:</strong> <?= money_fmt($current_remaining) ?>
     (na <?= $elapsed_payment_periods ?> betaaltermijnen, <?= count($payments) ?> transacties)</p>
  <?php if ($new_payment > 0): ?>
    <p><strong>Adviesbedrag:</strong> <?= money_fmt($new_payment) ?> per maand
       om binnen <?= $months_left ?> maanden klaar te zijn.</p>
  <?php endif; ?>
</div>

<?php if ($can_edit): ?>
<div class="card p-3 mb-4">
  <h5>Lening bewerken</h5>
  <?php if (isset($_GET['ok'])): ?><div class="alert alert-success">Lening opgeslagen.</div><?php endif; ?>
  <?php if ($errors): ?><div class="alert alert-danger"><?php echo implode('<br>', array_map('htmlspecialchars',$errors)); ?></div><?php endif; ?>
  <form method="post">
    <?php csrf_field(); ?>
    <input type="hidden" name="update_loan" value="1">
    <div class="mb-3"><label class="form-label">Naam</label><input class="form-control" name="name" value="<?= h($loan['name']) ?>"></div>
    <div class="mb-3"><label class="form-label">Hoofdsom (€)</label><input class="form-control" name="principal" type="number" step="0.01" value="<?= h($loan['principal']) ?>"></div>
    <div class="mb-3"><label class="form-label">Rente (% per jaar)</label><input class="form-control" name="rate" type="number" step="0.0001" value="<?= h($loan['rate']) ?>"></div>
    <div class="mb-3"><label class="form-label">Startdatum</label><input class="form-control" name="start_date" type="date" value="<?= h($loan['start_date']) ?>"></div>
    <div class="mb-3"><label class="form-label">Looptijd (maanden)</label><input class="form-control" name="term_months" type="number" value="<?= h($loan['term_months']) ?>"></div>
    <div class="mb-3">
      <label class="form-label">Type</label>
      <select class="form-select" name="type">
        <option value="annuity" <?= $loan['type'] === 'annuity' ? 'selected' : '' ?>>Annuïtair</option>
        <option value="linear" <?= $loan['type'] === 'linear' ? 'selected' : '' ?>>Lineair</option>
      </select>
    </div>
    <div class="mb-3">
      <label class="form-label">Lender type</label>
      <select class="form-select" name="lender_type">
        <option value="private" <?= ($loan['lender_type'] ?? DEFAULT_LENDER_TYPE) === 'private' ? 'selected' : '' ?>>Privé (naam vanuit config)</option>
        <option value="company" <?= ($loan['lender_type'] ?? DEFAULT_LENDER_TYPE) === 'company' ? 'selected' : '' ?>>Zakelijk (Sebsoft Holding BV)</option>
      </select>
    </div>
    <div class="mb-3">
      <label class="form-label">Borrower (optioneel)</label>
      <select class="form-select" name="borrower_id">
        <option value="">—</option>
        <?php foreach($db->query("SELECT id,name FROM users WHERE role='borrower' ORDER BY name ASC")->fetchAll() as $b): ?>
          <option value="<?=$b['id']?>" <?= ($loan['borrower_id']==$b['id']) ? 'selected' : '' ?>><?=h($b['name'])?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn btn-primary">Opslaan</button>
  </form>
</div>
<?php endif; ?>

<div class="card p-3 mb-4">
  <h5>PDF Overzicht voor Belastingaangifte</h5>
  <form method="get" action="generate_loan_pdf.php" target="_blank">
    <input type="hidden" name="id" value="<?= $loan['id'] ?>">
    <div class="mb-3">
      <label class="form-label">Kies jaar</label>
      <select class="form-select" name="year">
        <?php for ($y = date('Y') - 5; $y <= date('Y') + 1; $y++): ?>
          <option value="<?= $y ?>" <?= $y == date('Y') ? 'selected' : '' ?>><?= $y ?></option>
        <?php endfor; ?>
      </select>
    </div>
    <button class="btn btn-primary" type="submit">Download PDF</button>
  </form>
</div>

<div class="card p-3 mb-4">
  <canvas id="chartRemaining" height="700"></canvas>
</div>

      <?php if ($can_edit && $u['role']!=='borrower'): ?>
  <div class="col-lg-4">
    <div class="card p-3">
      <h5 class="mb-3">Transactie toevoegen</h5>
      <?php if ($errors): ?><div class="alert alert-danger"><?php echo implode('<br>', array_map('htmlspecialchars',$errors)); ?></div><?php endif; ?>
      <?php if ($can_edit && $u['role']!=='borrower'): ?>
      <form method="post">
        <?php csrf_field(); ?>
        <input type="hidden" name="add_payment" value="1">
        <div class="mb-3"><label class="form-label">Datum</label><input class="form-control" type="date" name="date" required></div>
        <div class="mb-3">
          <label class="form-label">Type</label>
          <select class="form-select" name="transaction_type">
            <option value="payment">Betaling / aflossing</option>
            <option value="principal_increase">Hoofdsomverhoging / extra opname</option>
          </select>
        </div>
        <div class="mb-3"><label class="form-label">Bedrag (€)</label><input class="form-control" type="number" step="0.01" min="0.01" name="amount" required></div>
        <div class="mb-3"><label class="form-label">Notitie</label><input class="form-control" name="note"></div>
        <button class="btn btn-primary w-100">Toevoegen</button>
      </form>
      <?php else: ?>
        <div class="alert alert-info">Je hebt geen rechten om betalingen toe te voegen.</div>
      <?php endif; ?>
    </div>
  </div>
      <?php endif; ?>

<div class="card p-3 mb-4">
  <h5>Overzicht transacties</h5>
  <?php if (isset($_GET['pOK'])): ?><div class="alert alert-success">Betaling toegevoegd.</div><?php endif; ?>
  <?php if (isset($_GET['pEdit'])): ?><div class="alert alert-success">Betaling bijgewerkt.</div><?php endif; ?>
  <?php if (isset($_GET['pDelete'])): ?><div class="alert alert-success">Betaling verwijderd.</div><?php endif; ?>
  <table class="table table-hover">
    <thead>
      <tr>
        <th>Datum</th><th>Type</th><th>Bedrag</th><th>Rente</th><th>Aflossing / mutatie</th><th>Restschuld</th><th>Notitie</th><?php if ($can_edit && $u['role']!=='borrower' && !empty(array_filter($alloc['allocations'], fn($a) => isset($a['id']) && $a['id'] > 0))): ?><th>Acties</th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach(array_reverse($alloc['allocations']) as $a): ?>
        <tr>
          <td><?= date('d-m-Y', strtotime($a['date'])) ?></td>
          <td><?= h($a['type_label'] ?? transaction_type_label($a['transaction_type'] ?? 'payment')) ?></td>
          <td><?=money_fmt($a['amount'])?></td>
          <td><?=money_fmt($a['interest'])?></td>
          <td><?=money_fmt($a['principal'])?></td>
          <td><?=money_fmt($a['remaining'])?></td>
          <td><?=isset($a['note']) ? h($a['note']) : ''?></td>
          <?php if ($can_edit && $u['role']!=='borrower' && isset($a['id']) && $a['id'] > 0): ?>
          <td>
            <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editPaymentModal<?=$a['id']?>">Bewerk</button>
          </td>
          <?php else: ?>
          <td></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($can_edit && $u['role']!=='borrower'): ?>
<?php foreach(array_reverse($alloc['allocations']) as $a): ?>
<?php if (isset($a['id']) && $a['id'] > 0): ?>
<div class="modal fade" id="editPaymentModal<?=$a['id']?>" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Transactie bewerken</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" id="editForm<?=$a['id']?>">
        <?php csrf_field(); ?>
        <div class="modal-body">
          <input type="hidden" name="payment_id" value="<?=$a['id']?>">
          <input type="hidden" name="update_payment" value="1" id="updateFlag<?=$a['id']?>">
          <div class="mb-3">
            <label class="form-label">Datum</label>
            <input type="date" class="form-control" name="date" value="<?=h($a['date'])?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Type</label>
            <select class="form-select" name="transaction_type">
              <option value="payment" <?= ($a['transaction_type'] ?? 'payment') === 'payment' ? 'selected' : '' ?>>Betaling / aflossing</option>
              <option value="principal_increase" <?= ($a['transaction_type'] ?? 'payment') === 'principal_increase' ? 'selected' : '' ?>>Hoofdsomverhoging / extra opname</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Bedrag (€)</label>
            <input type="number" step="0.01" min="0.01" class="form-control" name="amount" value="<?=h($a['amount'])?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Notitie</label>
            <input type="text" class="form-control" name="note" value="<?=isset($a['note']) ? h($a['note']) : ''?>">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Sluiten</button>
          <button type="button" class="btn btn-danger" onclick="if (confirm('Weet je het zeker?')) { const flag = document.getElementById('updateFlag<?=$a['id']?>'); flag.name = 'delete_payment'; flag.value = '<?=$a['id']?>'; document.getElementById('editForm<?=$a['id']?>').submit(); }">Verwijderen</button>
          <button type="submit" class="btn btn-primary">Opslaan</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>
<?php endforeach; ?>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const labels = <?= json_encode($labels) ?>;
const scheduled = <?= json_encode($scheduledRemaining) ?>;
const actual = <?= json_encode($actualRemaining) ?>;
const projection = <?= json_encode($projRemaining) ?>;

const darkColor = getComputedStyle(document.documentElement)
  .getPropertyValue('--fucking-dark')
  .trim();

new Chart(document.getElementById('chartRemaining').getContext('2d'), {
  type: 'line',
  data: {
    labels: labels,
    datasets: [
      {label: 'Gepland restschuld', data: scheduled, borderColor:'#3b82f6', tension:0.2, fill:false},
      {label: 'Werkelijke restschuld', data: actual, borderColor:'#10b981', tension:0.2, fill:false},
      {label: 'Prognose', data: projection, borderColor:'#f59e0b', borderDash:[5,5], tension:0.2, fill:false}
    ]
  },
  options: {
    responsive: true,
    plugins: {
      legend: {
        position:'bottom',
        labels: {
          color: darkColor
        }
      }
    },
    scales: {
      x: { grid:{color:'#374151'}, ticks:{color: darkColor} },
      y: { grid:{color:'#374151'}, ticks:{color: darkColor} }
    }
  }
});
</script>



<?php include __DIR__ . '/partials_footer.php'; ?>

