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
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_payment'])) {
    if (!$can_edit || $u['role']==='borrower') { http_response_code(403); exit; }
    $date = $_POST['date'] ?? '';
    $amount = (float)($_POST['amount'] ?? 0);
    $note = trim($_POST['note'] ?? '');
    if ($date=='' || $amount<=0) $errors[]='Datum en bedrag zijn verplicht.';
    if (!$errors) {
        $ins=$db->prepare("INSERT INTO payments(loan_id,date,amount,note) VALUES(?,?,?,?)");
        $ins->execute([$loan['id'],$date,$amount,$note]);

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
                'amount_left' => $alloc['remaining'],
            ];
            curl_post_json(WEBHOOK_URL, $payload);
        }
        header('Location: '.BASEDIR.'/loan.php?id='.$loan['id'].'&pOK=1'); exit;
    }
}



$paymentsStmt = $db->prepare("SELECT * FROM payments WHERE loan_id=? ORDER BY date ASC, id ASC");
$paymentsStmt->execute([$loan['id']]);
$payments = $paymentsStmt->fetchAll();

$plan = schedule($loan['principal'], $loan['rate'], $loan['term_months'], $loan['type']);
$alloc = compute_allocation_with_payments($loan, $payments);
$current_remaining = $alloc['remaining'];
$months_left = max(0, $loan['term_months'] - count($payments));
$new_payment = calculate_new_payment($current_remaining, $loan['rate'], $months_left);

// Prognose: bereken restant-schema vanaf laatste betaling
$projection = [];
if ($months_left > 0) {
    $projection = generate_projection_schedule($current_remaining, $loan['rate'], $months_left, $loan['type']);
    // plak lege rijen voor eerdere maanden zodat grafieklijnen netjes gelijk lopen
    for ($i = 0; $i < count($payments); $i++) {
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
  <h3><?= h($loan['name']) ?></h3>
  <p><strong>Huidige restschuld:</strong> <?= money_fmt($current_remaining) ?>
     (na <?= count($payments) ?> betalingen)</p>
  <?php if ($new_payment > 0): ?>
    <p><strong>Adviesbedrag:</strong> <?= money_fmt($new_payment) ?> per maand
       om binnen <?= $months_left ?> maanden klaar te zijn.</p>
  <?php endif; ?>
</div>

<div class="card p-3 mb-4">
  <canvas id="chartRemaining" height="700"></canvas>
</div>

      <?php if ($can_edit && $u['role']!=='borrower'): ?>
  <div class="col-lg-4">
    <div class="card p-3">
      <h5 class="mb-3">Betaling toevoegen</h5>
      <?php if ($errors): ?><div class="alert alert-danger"><?php echo implode('<br>', array_map('htmlspecialchars',$errors)); ?></div><?php endif; ?>
      <?php if ($can_edit && $u['role']!=='borrower'): ?>
      <form method="post">
        <?php csrf_field(); ?>
        <input type="hidden" name="add_payment" value="1">
        <div class="mb-3"><label class="form-label">Datum</label><input class="form-control" type="date" name="date" required></div>
        <div class="mb-3"><label class="form-label">Bedrag (€)</label><input class="form-control" type="number" step="0.01" name="amount" required></div>
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
  <h5>Overzicht betalingen</h5>
  <?php if (isset($_GET['pOK'])): ?><div class="alert alert-success">Betaling toegevoegd.</div><?php endif; ?>
  <table class="table table-hover">
    <thead>
      <tr>
        <th>Datum</th><th>Bedrag</th><th>Rente</th><th>Aflossing</th><th>Restschuld</th><th>Notitie</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach($alloc['allocations'] as $a): ?>
        <tr>
          <td><?=h($a['date'])?></td>
          <td><?=money_fmt($a['amount'])?></td>
          <td><?=money_fmt($a['interest'])?></td>
          <td><?=money_fmt($a['principal'])?></td>
          <td><?=money_fmt($a['remaining'])?></td>
          <td><?=isset($a['note']) ? h($a['note']) : ''?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const labels = <?= json_encode($labels) ?>;
const scheduled = <?= json_encode($scheduledRemaining) ?>;
const actual = <?= json_encode($actualRemaining) ?>;
const projection = <?= json_encode($projRemaining) ?>;

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
    plugins: {legend:{position:'bottom'}},
    scales: {
    x: {grid:{color:'#374151'}, ticks:{color:'#e5e7eb'} },
      y: {grid:{color:'#374151'}, ticks:{color:'#e5e7eb'}}
    }
  }
});
</script>

<?php include __DIR__ . '/partials_footer.php'; ?>

