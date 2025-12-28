<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/config.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$year = (int)($_GET['year'] ?? date('Y'));

$q = $db->prepare("SELECT * FROM loans WHERE id=?");
$q->execute([$id]);
$loan = $q->fetch();
if (!$loan) { echo "Niet gevonden"; exit; }

$u = current_user();
$is_owner = ($u['id'] === (int)$loan['owner_id']);
$is_staff = in_array($u['role'], ['admin','manager'], true);
$can_view = $is_staff || $is_owner || ($u['id'] === (int)$loan['borrower_id']);

if (!$can_view) { http_response_code(403); exit; }

$paymentsStmt = $db->prepare("SELECT * FROM payments WHERE loan_id=? ORDER BY date ASC, id ASC");
$paymentsStmt->execute([$loan['id']]);
$payments = $paymentsStmt->fetchAll();

$alloc = compute_allocation_with_payments($loan, $payments);

// Filter allocations op jaar
$yearly_alloc = array_filter($alloc['allocations'], function($a) use ($year) {
    return date('Y', strtotime($a['date'])) == $year;
});

// Groepeer per maand
$monthly_principal = [];
foreach ($yearly_alloc as $a) {
    $month = (int)date('m', strtotime($a['date']));
    if (!isset($monthly_principal[$month])) $monthly_principal[$month] = 0;
    $monthly_principal[$month] += (float)$a['principal'];
}

// Bereken totalen
$total_amount = array_sum(array_column($yearly_alloc, 'amount'));
$total_interest = array_sum(array_column($yearly_alloc, 'interest'));
$total_principal = array_sum(array_column($yearly_alloc, 'principal'));

// PDF genereren met TCPDF
require_once __DIR__ . '/../vendor/autoload.php';

$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor('Lening Manager');
$pdf->SetTitle('Aflossingen ' . $loan['name'] . ' - ' . $year);
$pdf->SetSubject('Belastingaangifte overzicht');

$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);

$pdf->AddPage();

$pdf->SetFont('helvetica', 'B', 16);
$pdf->Cell(0, 10, 'Overzicht Aflossingen', 0, 1, 'C');
$pdf->Ln(5);

$pdf->SetFont('helvetica', '', 12);
$pdf->Cell(0, 8, 'Lening: ' . h($loan['name']), 0, 1);
$pdf->Cell(0, 8, 'Jaar: ' . $year, 0, 1);
$pdf->Cell(0, 8, 'Hoofdsom: ' . money_fmt($loan['principal']), 0, 1);
$pdf->Cell(0, 8, 'Rente: ' . $loan['rate'] . '% per jaar', 0, 1);
$pdf->Ln(10);

// Tabel header
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(30, 8, 'Datum', 1, 0, 'C');
$pdf->Cell(30, 8, 'Bedrag', 1, 0, 'C');
$pdf->Cell(30, 8, 'Rente', 1, 0, 'C');
$pdf->Cell(30, 8, 'Aflossing', 1, 1, 'C');

$pdf->SetFont('helvetica', '', 10);
foreach ($yearly_alloc as $a) {
    $pdf->Cell(30, 8, h($a['date']), 1, 0, 'C');
    $pdf->Cell(30, 8, money_fmt($a['amount']), 1, 0, 'R');
    $pdf->Cell(30, 8, money_fmt($a['interest']), 1, 0, 'R');
    $pdf->Cell(30, 8, money_fmt($a['principal']), 1, 1, 'R');
}

$pdf->Ln(5);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(30, 8, 'Totaal', 1, 0, 'C');
$pdf->Cell(30, 8, money_fmt($total_amount), 1, 0, 'R');
$pdf->Cell(30, 8, money_fmt($total_interest), 1, 0, 'R');
$pdf->Cell(30, 8, money_fmt($total_principal), 1, 1, 'R');

$pdf->Ln(10);

// Grafiek toevoegen
$pdf->AddPage();
$pdf->SetFont('helvetica', 'B', 16);
$pdf->Cell(0, 10, 'Grafiek Aflossingen per Maand - ' . $year, 0, 1, 'C');
$pdf->Ln(10);

// Simpele bar chart
$chart_width = 150;
$chart_height = 80;
$bar_width = $chart_width / 12;
$max_principal = max($monthly_principal) ?: 1;
$scale = $chart_height / $max_principal;

$x_start = 20;
$y_start = $pdf->GetY() + $chart_height + 10;

$pdf->SetFillColor(100, 149, 237); // Cornflower blue

for ($month = 1; $month <= 12; $month++) {
    $principal = $monthly_principal[$month] ?? 0;
    $bar_height = $principal * $scale;
    $x = $x_start + ($month - 1) * $bar_width;
    $y = $y_start - $bar_height;
    $pdf->Rect($x, $y, $bar_width - 2, $bar_height, 'F');
    
    // Maand label
    $pdf->SetXY($x, $y_start + 2);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->Cell($bar_width - 2, 5, date('M', mktime(0, 0, 0, $month, 1)), 0, 0, 'C');
    $pdf->SetFont('helvetica', '', 10);
}

// Y-as labels
$pdf->SetXY($x_start - 15, $y_start - $chart_height);
$pdf->Cell(10, 5, money_fmt($max_principal), 0, 0, 'R');
$pdf->SetXY($x_start - 15, $y_start);
$pdf->Cell(10, 5, '0', 0, 0, 'R');

$pdf->Ln(100);
?>