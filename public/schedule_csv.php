<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/functions.php';

$id = (int)($_GET['id'] ?? 0);
$q = $db->prepare("SELECT * FROM loans WHERE id=?");
$q->execute([$id]);
$loan = $q->fetch();
if (!$loan) { die("Lening niet gevonden."); }

$paymentsStmt = $db->prepare("SELECT * FROM payments WHERE loan_id=? ORDER BY date ASC, id ASC");
$paymentsStmt->execute([$loan['id']]);
$payments = $paymentsStmt->fetchAll();

$alloc = compute_allocation_with_payments($loan, $payments);
$current_remaining = $alloc['remaining'];
$months_left = max(0, $loan['term_months'] - count($payments));
$projection = generate_projection_schedule($current_remaining, $loan['rate'], $months_left, $loan['type']);

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="lening_'.$loan['id'].'_schema.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Maand', 'Betaling', 'Rente', 'Prognose betaling', 'Restschuld']);
$base_schedule = schedule($loan['principal'], $loan['rate'], $loan['term_months'], $loan['type']);
foreach ($base_schedule as $i => $row) {
    $projPay = $projection[$i]['payment'] ?? '';
    fputcsv($out, [
        $row['month'],
        number_format($row['payment'], 2, ',', '.'),
        number_format($row['interest'], 2, ',', '.'),
        $projPay ? number_format($projPay, 2, ',', '.') : '',
        number_format($row['remaining'], 2, ',', '.')
    ]);
}
fclose($out);
