<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/functions.php';
verify_csrf();
require_login();

$u = current_user();
$is_staff = in_array($u['role'], ['admin', 'manager'], true);
if (!$is_staff) { http_response_code(403); exit('Alleen beheerders.'); }

function parse_rows(array $dates, array $amounts): array {
    $rows = [];
    $count = max(count($dates), count($amounts));
    for ($i = 0; $i < $count; $i++) {
        $date = trim((string)($dates[$i] ?? ''));
        $amountRaw = trim((string)($amounts[$i] ?? ''));
        if ($date === '' && $amountRaw === '') continue;
        if ($date === '' || $amountRaw === '') continue;
        $rows[] = ['date' => $date, 'amount' => (float)str_replace(',', '.', $amountRaw)];
    }
    usort($rows, fn($a, $b) => strcmp($a['date'], $b['date']));
    return $rows;
}
function year_fraction(string $from, string $to): float {
    $d1 = new DateTimeImmutable($from);
    $d2 = new DateTimeImmutable($to);
    return ((float)$d1->diff($d2)->format('%r%a')) / 365.0;
}
function predicted_balance_at(string $date, float $startBalance, string $startDate, float $rate, array $payments, array $costs): float {
    $balance = $startBalance * pow(1.0 + $rate, year_fraction($startDate, $date));
    foreach ($payments as $p) {
        if ($p['date'] >= $startDate && $p['date'] <= $date) $balance -= $p['amount'] * pow(1.0 + $rate, year_fraction($p['date'], $date));
    }
    foreach ($costs as $c) {
        if ($c['date'] >= $startDate && $c['date'] <= $date) $balance += $c['amount'] * pow(1.0 + $rate, year_fraction($c['date'], $date));
    }
    return $balance;
}
function solve_rate(float $startBalance, string $startDate, float $targetBalance, string $targetDate, array $payments, array $costs): ?float {
    $f = fn(float $r): float => predicted_balance_at($targetDate, $startBalance, $startDate, $r, $payments, $costs) - $targetBalance;
    $low = -0.99; $high = 5.0;
    $fLow = $f($low); $fHigh = $f($high);
    if ($fLow * $fHigh > 0) return null;
    for ($i = 0; $i < 120; $i++) {
        $mid = ($low + $high) / 2.0; $fMid = $f($mid);
        if (abs($fMid) < 0.00001) return $mid;
        if ($fLow * $fMid <= 0) { $high = $mid; $fHigh = $fMid; } else { $low = $mid; $fLow = $fMid; }
    }
    return ($low + $high) / 2.0;
}

function run_calculation(array $post): array {
    $errors = [];
    $known = parse_rows($post['known_dates'] ?? [], $post['known_amounts'] ?? []);
    $payments = parse_rows($post['payment_dates'] ?? [], $post['payment_amounts'] ?? []);
    $costs = parse_rows($post['cost_dates'] ?? [], $post['cost_amounts'] ?? []);
    if (count($known) < 2) $errors[] = 'Vul minimaal 2 pijldatums met bekende bedragen in.';
    $result = null;
    if (!$errors) {
        $start = $known[0]; $end = $known[count($known)-1];
        $rate = solve_rate((float)$start['amount'], $start['date'], (float)$end['amount'], $end['date'], $payments, $costs);
        if ($rate === null) $errors[] = 'Kon geen rente vinden die past bij deze invoer. Controleer datums/bedragen.';
        else {
            $checks = [];
            foreach ($known as $k) {
                $pred = predicted_balance_at($k['date'], (float)$start['amount'], $start['date'], $rate, $payments, $costs);
                $checks[] = ['date'=>$k['date'],'known'=>(float)$k['amount'],'predicted'=>$pred,'delta'=>$pred-(float)$k['amount']];
            }
            $result = compact('rate', 'start', 'end', 'checks', 'payments', 'costs', 'known');
        }
    }
    return [$errors, $result, $known, $payments, $costs];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_pdf'])) {
    [$errors, $result] = run_calculation($_POST);
    if (!$errors && $result) {
        require_once __DIR__ . '/../vendor/autoload.php';
        $pdf = new TCPDF();
        $pdf->SetCreator('Fiscana');
        $pdf->SetTitle('Rentecheck export');
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 10, 'Rente rekenmodule export', 0, 1);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->MultiCell(0, 6, 'Impliciete effectieve jaarrente: ' . number_format($result['rate']*100, 4, ',', '.') . "%\n" .
            'Formule: B(t)=B0*(1+r)^Δt - Σ A_i*(1+r)^Δt_i + Σ K_j*(1+r)^Δt_j');
        $html = '<h3>Controle per pijldatum</h3><table border="1" cellpadding="4"><tr><th>Datum</th><th>Bekend</th><th>Berekend</th><th>Verschil</th></tr>';
        foreach ($result['checks'] as $c) {
            $html .= '<tr><td>'.h($c['date']).'</td><td>'.money_fmt($c['known']).'</td><td>'.money_fmt($c['predicted']).'</td><td>'.money_fmt($c['delta']).'</td></tr>';
        }
        $html .= '</table>';
        $pdf->writeHTML($html);
        $pdf->Output('rentecheck.pdf', 'I');
        exit;
    }
}

$errors = []; $result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    [$errors, $result, $known, $payments, $costs] = run_calculation($_POST);
}
include __DIR__ . '/partials_header.php';
?>
<div class="card p-3 mb-4">
  <h1>Rente rekenmodule (beheer)</h1>
  <p class="text-muted">Voeg arbitrair veel bekende momenten, aflossingen en extra kosten toe en bereken de impliciete rente.</p>
  <?php if ($errors): ?><div class="alert alert-danger"><?=implode('<br>', array_map('h', $errors))?></div><?php endif; ?>
</div>
<div class="card p-3 mb-4">
<form method="post" id="calcForm"><?php csrf_field(); ?>
  <h5>1) Bekende bedragen op pijldatums</h5><div id="knownRows"></div>
  <button class="btn btn-sm btn-outline-secondary mb-3" type="button" onclick="addRow('knownRows','known')">+ Moment toevoegen</button>
  <h5>2) Aflossingen</h5><div id="paymentRows"></div>
  <button class="btn btn-sm btn-outline-secondary mb-3" type="button" onclick="addRow('paymentRows','payment')">+ Aflossing toevoegen</button>
  <h5>3) Extra kosten</h5><div id="costRows"></div>
  <button class="btn btn-sm btn-outline-secondary mb-3" type="button" onclick="addRow('costRows','cost')">+ Kost toevoegen</button><br>
  <button class="btn btn-primary" name="calculate" value="1">Bereken rente</button>
  <?php if ($result): ?><button class="btn btn-outline-dark" name="export_pdf" value="1">Exporteer naar PDF</button><?php endif; ?>
</form>
</div>
<?php if ($result): ?>
<div class="card p-3 mb-4">
  <h5>Uitkomst</h5>
  <p><strong>Impliciete effectieve jaarrente:</strong> <?=number_format($result['rate'] * 100, 4, ',', '.')?>%</p>
  <p>Formule: <code>B(t)=B0*(1+r)^Δt - Σ A_i*(1+r)^Δt_i + Σ K_j*(1+r)^Δt_j</code></p>
  <canvas id="renteChart" height="120"></canvas>
</div>
<?php endif; ?>
<?php if ($result): ?><script src="https://cdn.jsdelivr.net/npm/chart.js"></script><?php endif; ?>
<script>
const initialData = {
 known: <?=json_encode($_POST['known_dates'] ?? ['', ''])?>.map((d,i)=>({date:d,amount:(<?=json_encode($_POST['known_amounts'] ?? ['', ''])?>[i]||'')})),
 payment: <?=json_encode($_POST['payment_dates'] ?? [''])?>.map((d,i)=>({date:d,amount:(<?=json_encode($_POST['payment_amounts'] ?? [''])?>[i]||'')})),
 cost: <?=json_encode($_POST['cost_dates'] ?? [''])?>.map((d,i)=>({date:d,amount:(<?=json_encode($_POST['cost_amounts'] ?? [''])?>[i]||'')})),
};
function rowHtml(kind, d='', a='') { return `<div class="row g-2 mb-2"><div class="col-md-4"><input class="form-control" type="date" name="${kind}_dates[]" value="${d}"></div><div class="col-md-4"><input class="form-control" type="number" step="0.01" name="${kind}_amounts[]" value="${a}"></div></div>`; }
function addRow(id, kind, d='', a='') { document.getElementById(id).insertAdjacentHTML('beforeend', rowHtml(kind,d,a)); }
['known','payment','cost'].forEach(kind => {
 const target = kind+'Rows';
 const data = initialData[kind] && initialData[kind].length ? initialData[kind] : [{date:'',amount:''}];
 data.forEach(r => addRow(target, kind, r.date || '', r.amount || ''));
});
<?php if ($result): ?>
new Chart(document.getElementById('renteChart'), {
 type:'line',
 data:{
  labels: <?=json_encode(array_column($result['checks'], 'date'))?>,
  datasets:[
    {label:'Bekend saldo', data: <?=json_encode(array_map(fn($x)=>round($x['known'],2), $result['checks']))?>, borderColor:'#0d6efd'},
    {label:'Berekend saldo', data: <?=json_encode(array_map(fn($x)=>round($x['predicted'],2), $result['checks']))?>, borderColor:'#198754'},
    {label:'Aflossingen', data: <?=json_encode(array_map(fn($d) => (float)($d['amount'] ?? 0), $result['payments']))?>, borderColor:'#dc3545'}
  ]
 },
 options:{responsive:true}
});
<?php endif; ?>
</script>
<?php include __DIR__ . '/partials_footer.php'; ?>
