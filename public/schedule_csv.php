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
$elapsed_payment_periods = loan_elapsed_payment_periods($alloc['allocations']);
$months_left = loan_months_left($loan, $alloc['allocations']);
$projection = generate_projection_schedule($current_remaining, $loan['rate'], $months_left, $loan['type']);

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="lening_'.$loan['id'].'_schema.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Soort', 'Datum/termijn', 'Bedrag', 'Rente', 'Aflossing / mutatie hoofdsom', 'Hoofdsomverhoging', 'Restschuld']);

foreach ($alloc['allocations'] as $a) {
    fputcsv($out, [
        $a['type_label'] ?? transaction_type_label($a['transaction_type'] ?? 'payment'),
        date('d-m-Y', strtotime($a['date'])),
        number_format($a['amount'], 2, ',', '.'),
        number_format($a['interest'], 2, ',', '.'),
        number_format($a['principal'], 2, ',', '.'),
        !empty($a['principal_increase']) ? number_format($a['principal_increase'], 2, ',', '.') : '',
        number_format($a['remaining'], 2, ',', '.'),
    ]);
}

foreach ($projection as $i => $row) {
    fputcsv($out, [
        'Prognose',
        'Termijn ' . ($elapsed_payment_periods + $i + 1),
        number_format($row['payment'], 2, ',', '.'),
        number_format($row['interest'], 2, ',', '.'),
        number_format($row['principal'], 2, ',', '.'),
        '',
        number_format($row['remaining'], 2, ',', '.'),
    ]);
}

fclose($out);
