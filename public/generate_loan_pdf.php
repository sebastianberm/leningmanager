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

// Header en footer instellen
$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true);
$pdf->SetHeaderData('', 0, 'Leningmanager - Aflossingen Overzicht', 'Jaar: ' . $year);
$pdf->setHeaderFont(['helvetica', '', 10]);
$pdf->setFooterFont(['helvetica', '', 8]);

// Margins
$pdf->SetMargins(15, 27, 15);
$pdf->SetHeaderMargin(5);
$pdf->SetFooterMargin(10);

$pdf->AddPage();

// Titel
$pdf->SetFont('helvetica', 'B', 18);
$pdf->SetTextColor(59, 130, 246); // Blauw
$pdf->Cell(0, 15, 'Overzicht Aflossingen', 0, 1, 'C');
$pdf->Ln(5);

// Lening details
$pdf->SetFont('helvetica', '', 12);
$pdf->SetTextColor(0, 0, 0);
$pdf->Cell(0, 8, 'Lening: ' . h($loan['name']), 0, 1);
$pdf->Cell(0, 8, 'Jaar: ' . $year, 0, 1);
$pdf->Cell(0, 8, 'Hoofdsom: ' . money_fmt($loan['principal']), 0, 1);
$pdf->Cell(0, 8, 'Rente: ' . $loan['rate'] . '% per jaar', 0, 1);
$pdf->Cell(0, 8, 'Type: ' . ($loan['type'] === 'annuity' ? 'Annuïteit' : 'Lineair'), 0, 1);
$pdf->Ln(10);

// Tabel header
$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetFillColor(59, 130, 246); // Blauw
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(35, 10, 'Datum', 1, 0, 'C', true);
$pdf->Cell(35, 10, 'Bedrag', 1, 0, 'C', true);
$pdf->Cell(35, 10, 'Rente', 1, 0, 'C', true);
$pdf->Cell(35, 10, 'Aflossing', 1, 1, 'C', true);

// Tabel data
$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(0, 0, 0);
$fill = false;
foreach ($yearly_alloc as $a) {
    $pdf->SetFillColor(240, 248, 255); // Licht blauw voor alternate
    $pdf->Cell(35, 8, h($a['date']), 1, 0, 'C', $fill);
    $pdf->Cell(35, 8, money_fmt($a['amount']), 1, 0, 'R', $fill);
    $pdf->Cell(35, 8, money_fmt($a['interest']), 1, 0, 'R', $fill);
    $pdf->Cell(35, 8, money_fmt($a['principal']), 1, 1, 'R', $fill);
    $fill = !$fill;
}

$pdf->Ln(5);
$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetFillColor(59, 130, 246);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(35, 10, 'Totaal', 1, 0, 'C', true);
$pdf->Cell(35, 10, money_fmt($total_amount), 1, 0, 'R', true);
$pdf->Cell(35, 10, money_fmt($total_interest), 1, 0, 'R', true);
$pdf->Cell(35, 10, money_fmt($total_principal), 1, 1, 'R', true);

$pdf->Ln(15);

// Samenvatting
$pdf->SetFont('helvetica', 'B', 14);
$pdf->SetTextColor(59, 130, 246);
$pdf->Cell(0, 10, 'Samenvatting', 0, 1);
$pdf->SetFont('helvetica', '', 12);
$pdf->SetTextColor(0, 0, 0);
$pdf->Cell(0, 8, 'Totaal betaald in ' . $year . ': ' . money_fmt($total_amount), 0, 1);
$pdf->Cell(0, 8, 'Waarvan rente: ' . money_fmt($total_interest), 0, 1);
$pdf->Cell(0, 8, 'Waarvan aflossing: ' . money_fmt($total_principal), 0, 1);
$pdf->Cell(0, 8, 'Aantal betalingen: ' . count($yearly_alloc), 0, 1);

// Output
$pdf->Output('lening_' . $loan['id'] . '_' . $year . '.pdf', 'I');