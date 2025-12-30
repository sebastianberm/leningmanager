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
$monthly_data = [];
for ($m = 1; $m <= 12; $m++) {
    $monthly_data[$m] = ['principal' => 0, 'interest' => 0, 'amount' => 0];
}

foreach ($yearly_alloc as $a) {
    $month = (int)date('m', strtotime($a['date']));
    $monthly_data[$month]['principal'] += (float)$a['principal'];
    $monthly_data[$month]['interest'] += (float)$a['interest'];
    $monthly_data[$month]['amount'] += (float)$a['amount'];
}

// Bereken totalen
$total_amount = array_sum(array_column($yearly_alloc, 'amount'));
$total_interest = array_sum(array_column($yearly_alloc, 'interest'));
$total_principal = array_sum(array_column($yearly_alloc, 'principal'));

// Bereken cumulatieve aflossing
$cumulative = 0;
$cumulative_data = [];
foreach ($monthly_data as $month => $data) {
    $cumulative += $data['principal'];
    $cumulative_data[$month] = $cumulative;
}

// PDF genereren met TCPDF
require_once __DIR__ . '/../vendor/autoload.php';

class CustomPDF extends TCPDF {
    public function Header() {
        $this->SetFont('helvetica', 'B', 20);
        $this->SetTextColor(66, 153, 225);
        $this->Cell(0, 15, 'Leningmanager', 0, false, 'L', 0, '', 0, false, 'M', 'M');
        $this->SetFont('helvetica', '', 10);
        $this->SetTextColor(113, 128, 150);
        $this->Cell(0, 15, 'Belastingaangifte Overzicht', 0, false, 'R', 0, '', 0, false, 'M', 'M');
        $this->Ln(10);
    }
    
    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->SetTextColor(113, 128, 150);
        $this->Cell(0, 10, 'Pagina ' . $this->getAliasNumPage() . ' van ' . $this->getAliasNbPages(), 0, false, 'C');
    }
}

$pdf = new CustomPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor('Lening Manager');
$pdf->SetTitle('Aflossingen ' . $loan['name'] . ' - ' . $year);
$pdf->SetSubject('Belastingaangifte overzicht');

$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true);

$pdf->SetMargins(15, 27, 15);
$pdf->SetHeaderMargin(5);
$pdf->SetFooterMargin(10);
$pdf->SetAutoPageBreak(TRUE, 20);

$pdf->AddPage();

// Samenvatting Box
$pdf->SetFillColor(240, 248, 255);
$pdf->SetDrawColor(66, 153, 225);
$pdf->SetLineWidth(0.5);
$pdf->RoundedRect(15, 37, 180, 50, 3, '1111', 'DF');

$pdf->SetY(42);
$pdf->SetFont('helvetica', 'B', 16);
$pdf->SetTextColor(66, 153, 225);
$pdf->Cell(0, 8, 'Jaaroverzicht ' . $year, 0, 1, 'C');

$pdf->SetFont('helvetica', '', 11);
$pdf->SetTextColor(26, 32, 44);
$pdf->Ln(3);

$pdf->Cell(90, 7, 'Lening:', 0, 0, 'R');
$pdf->SetFont('helvetica', 'B', 11);
$loanName = html_entity_decode($loan['name'], ENT_QUOTES, 'UTF-8');
$pdf->Cell(90, 7, $loanName, 0, 1, 'L');

$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(90, 7, 'Hoofdsom:', 0, 0, 'R');
$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(90, 7, money_fmt($loan['principal']), 0, 1, 'L');

$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(90, 7, 'Rentevoet:', 0, 0, 'R');
$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(90, 7, $loan['rate'] . '% per jaar', 0, 1, 'L');

$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(90, 7, 'Type:', 0, 0, 'R');
$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(90, 7, ($loan['type'] === 'annuity' ? 'Annuïteit' : 'Lineair'), 0, 1, 'L');

$pdf->Ln(10);

// Belangrijkste cijfers in boxes
$boxWidth = 58;
$boxHeight = 30;
$spacing = 3;

$boxY = $pdf->GetY();

$pdf->SetFillColor(72, 187, 120);
$pdf->SetTextColor(255, 255, 255);
$pdf->RoundedRect(15, $boxY, $boxWidth, $boxHeight, 2, '1111', 'F');
$pdf->SetXY(15, $boxY + 5);
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell($boxWidth, 5, 'Totaal Betaald', 0, 1, 'C');
$pdf->SetFont('helvetica', 'B', 14);
$pdf->SetXY(15, $boxY + 12);
$pdf->Cell($boxWidth, 8, money_fmt($total_amount), 0, 0, 'C');

$pdf->SetFillColor(237, 137, 54);
$pdf->RoundedRect(15 + $boxWidth + $spacing, $boxY, $boxWidth, $boxHeight, 2, '1111', 'F');
$pdf->SetXY(15 + $boxWidth + $spacing, $boxY + 5);
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell($boxWidth, 5, 'Rente Betaald', 0, 1, 'C');
$pdf->SetFont('helvetica', 'B', 14);
$pdf->SetXY(15 + $boxWidth + $spacing, $boxY + 12);
$pdf->Cell($boxWidth, 8, money_fmt($total_interest), 0, 0, 'C');

$pdf->SetFillColor(66, 153, 225);
$pdf->RoundedRect(15 + ($boxWidth + $spacing) * 2, $boxY, $boxWidth, $boxHeight, 2, '1111', 'F');
$pdf->SetXY(15 + ($boxWidth + $spacing) * 2, $boxY + 5);
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell($boxWidth, 5, 'Aflossing', 0, 1, 'C');
$pdf->SetFont('helvetica', 'B', 14);
$pdf->SetXY(15 + ($boxWidth + $spacing) * 2, $boxY + 12);
$pdf->Cell($boxWidth, 8, money_fmt($total_principal), 0, 0, 'C');

$pdf->Ln(25);

// Maandelijks overzicht
$pdf->SetFont('helvetica', 'B', 13);
$pdf->SetTextColor(26, 32, 44);
$pdf->Cell(0, 10, 'Maandelijks Overzicht', 0, 1);

$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetFillColor(66, 153, 225);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(35, 8, 'Maand', 1, 0, 'C', true);
$pdf->Cell(40, 8, 'Totaal Betaald', 1, 0, 'C', true);
$pdf->Cell(40, 8, 'Rente', 1, 0, 'C', true);
$pdf->Cell(40, 8, 'Aflossing', 1, 0, 'C', true);
$pdf->Cell(25, 8, '% (rente/bedrag)', 1, 1, 'C', true);

$pdf->SetFont('helvetica', '', 9);
$pdf->SetTextColor(26, 32, 44);

$months_nl = ['', 'Januari', 'Februari', 'Maart', 'April', 'Mei', 'Juni', 
              'Juli', 'Augustus', 'September', 'Oktober', 'November', 'December'];

$fill = false;
foreach ($monthly_data as $m => $data) {
    if ($data['amount'] > 0) {
        $pdf->SetFillColor(248, 250, 252);
        $percentage = $data['amount'] > 0 ? ($data['interest'] / $data['amount']) * 100 : 0;
        
        $pdf->Cell(35, 7, $months_nl[$m], 1, 0, 'L', $fill);
        $pdf->Cell(40, 7, money_fmt($data['amount']), 1, 0, 'R', $fill);
        $pdf->Cell(40, 7, money_fmt($data['interest']), 1, 0, 'R', $fill);
        $pdf->Cell(40, 7, money_fmt($data['principal']), 1, 0, 'R', $fill);
        $pdf->Cell(25, 7, number_format($percentage, 1) . '%', 1, 1, 'C', $fill);
        $fill = !$fill;
    }
}

// Totaal rij
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetFillColor(66, 153, 225);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(35, 8, 'TOTAAL', 1, 0, 'C', true);
$pdf->Cell(40, 8, money_fmt($total_amount), 1, 0, 'R', true);
$pdf->Cell(40, 8, money_fmt($total_interest), 1, 0, 'R', true);
$pdf->Cell(40, 8, money_fmt($total_principal), 1, 0, 'R', true);
$percentage_total = $total_amount > 0 ? ($total_interest / $total_amount) * 100 : 0;
$pdf->Cell(25, 8, number_format($percentage_total, 1) . '%', 1, 1, 'C', true);

$pdf->AddPage();

// Grafiek 1: Maandelijkse betalingen (Bar Chart simulatie)
$pdf->SetFont('helvetica', 'B', 13);
$pdf->SetTextColor(26, 32, 44);
$pdf->Cell(0, 10, 'Grafiek: Maandelijkse Betalingen', 0, 1);
$pdf->Ln(3);

$maxValue = max(array_column($monthly_data, 'amount'));
$graphHeight = 80;
$graphWidth = 160;
$barWidth = 12;
$startX = 25;
$startY = $pdf->GetY();

// Y-as
$pdf->SetDrawColor(113, 128, 150);
$pdf->Line($startX, $startY, $startX, $startY + $graphHeight);
$pdf->Line($startX, $startY + $graphHeight, $startX + $graphWidth, $startY + $graphHeight);

// Y-as label
$pdf->SetFont('helvetica', '', 9);
$pdf->SetTextColor(113, 128, 150);
$pdf->SetXY($startX - 20, $startY - 6);
$pdf->Cell(40, 5, 'Bedrag (€)', 0, 0, 'L');

// Bars
$x = $startX + 5;
foreach ($monthly_data as $m => $data) {
    if ($data['amount'] > 0) {
        
        $barHeight = $maxValue > 0 ? ($data['amount'] / $maxValue) * $graphHeight * 0.9 : 0;

        // Bereken segmenthoogtes
        $principalHeight = $data['amount'] > 0 ? ($data['principal'] / $data['amount']) * $barHeight : 0;
        $interestHeight = $barHeight - $principalHeight;

        $baseline = $startY + $graphHeight;

        // Rente (oranje) bovenaan van de bar (tekenen eerst het bovenste segment)
        $pdf->SetFillColor(237, 137, 54);
        $pdf->Rect($x, $baseline - $barHeight, $barWidth, $interestHeight, 'F');

        // Aflossing (groen) onderaan
        $pdf->SetFillColor(72, 187, 120);
        $pdf->Rect($x, $baseline - $principalHeight, $barWidth, $principalHeight, 'F');
        
        // Maand label
        $pdf->SetFont('helvetica', '', 7);
        $pdf->SetTextColor(113, 128, 150);
        $pdf->SetXY($x - 2, $startY + $graphHeight + 1);
        $pdf->Cell($barWidth, 4, substr($months_nl[$m], 0, 3), 0, 0, 'C');
        
        $x += $barWidth + 2;
    }
}

// Legenda
$pdf->SetY($startY + $graphHeight + 10);
$pdf->SetFont('helvetica', '', 9);
$pdf->SetTextColor(26, 32, 44);

$pdf->SetFillColor(72, 187, 120);
$pdf->Rect($startX, $pdf->GetY(), 5, 5, 'F');
$pdf->SetXY($startX + 7, $pdf->GetY());
$pdf->Cell(40, 5, 'Aflossing', 0, 0);

$pdf->SetFillColor(237, 137, 54);
$pdf->Rect($startX + 50, $pdf->GetY(), 5, 5, 'F');
$pdf->SetXY($startX + 57, $pdf->GetY());
$pdf->Cell(40, 5, 'Rente', 0, 1);

$pdf->Ln(15);

// Grafiek 2: Cumulatieve aflossing
$pdf->SetFont('helvetica', 'B', 13);
$pdf->SetTextColor(26, 32, 44);
$pdf->Cell(0, 10, 'Grafiek: Cumulatieve Aflossing', 0, 1);
$pdf->Ln(3);

$maxCumulative = max($cumulative_data);
$lineStartX = 25;
$lineStartY = $pdf->GetY();
$lineHeight = 70;
$lineWidth = 160;

// Assen
$pdf->SetDrawColor(113, 128, 150);
$pdf->Line($lineStartX, $lineStartY, $lineStartX, $lineStartY + $lineHeight);
$pdf->Line($lineStartX, $lineStartY + $lineHeight, $lineStartX + $lineWidth, $lineStartY + $lineHeight);

// Lijn tekenen
$pdf->SetDrawColor(66, 153, 225);
$pdf->SetLineWidth(1);
$pointsX = [];
$pointsY = [];

$activeMonths = array_filter($monthly_data, function($d) { return $d['amount'] > 0; });
$monthCount = count($activeMonths);
$xSpacing = $monthCount > 0 ? $lineWidth / $monthCount : 0;

$i = 0;
foreach ($cumulative_data as $m => $cumVal) {
    if ($monthly_data[$m]['amount'] > 0) {
        $x = $lineStartX + ($i * $xSpacing);
        $y = $lineStartY + $lineHeight - ($maxCumulative > 0 ? ($cumVal / $maxCumulative) * $lineHeight * 0.9 : 0);
        
        $pointsX[] = $x;
        $pointsY[] = $y;
        
        // Punt tekenen
        $pdf->SetFillColor(66, 153, 225);
        $pdf->Circle($x, $y, 1.5, 0, 360, 'F');
        
        $i++;
    }
}

// Lijnen tussen punten
$pdf->SetDrawColor(66, 153, 225);
for ($j = 0; $j < count($pointsX) - 1; $j++) {
    $pdf->Line($pointsX[$j], $pointsY[$j], $pointsX[$j + 1], $pointsY[$j + 1]);
}

$pdf->Ln(2);

// Y-as ticks and labels
$pdf->SetFont('helvetica', '', 8);
$ticks = 5;
for ($t = 0; $t <= $ticks; $t++) {
    $val = ($t / $ticks) * $maxCumulative;
    $yTick = $lineStartY + $lineHeight - ($t / $ticks) * $lineHeight * 0.9;
    $pdf->SetTextColor(113, 128, 150);
    $pdf->SetXY($lineStartX - 22, $yTick - 3);
    $pdf->Cell(20, 4, money_fmt($val), 0, 0, 'R');
    $pdf->SetDrawColor(200, 210, 220);
    $pdf->Line($lineStartX - 2, $yTick, $lineStartX + $lineWidth, $yTick);
}

// Legend for cumulative line
$pdf->SetXY($lineStartX + $lineWidth - 70, $lineStartY - 6);
$pdf->SetFillColor(66, 153, 225);
$pdf->Rect($pdf->GetX(), $pdf->GetY(), 6, 6, 'F');
$pdf->SetXY($pdf->GetX() + 8, $pdf->GetY());
$pdf->SetTextColor(26, 32, 44);
$pdf->Cell(60, 6, 'Cumulatieve aflossing', 0, 1);

// X labels under the axis (maanden)
$pdf->SetFont('helvetica', '', 7);
$pdf->SetTextColor(113, 128, 150);
// bepaal actieve maand keys in volgorde
$activeMonthKeys = array_keys($activeMonths);
for ($k = 0; $k < count($pointsX); $k++) {
    $monthIndex = $activeMonthKeys[$k];
    $label = substr($months_nl[$monthIndex], 0, 3);
    $pdf->SetXY($pointsX[$k] - 8, $lineStartY + $lineHeight + 2);
    $pdf->Cell(16, 4, $label, 0, 0, 'C');
}

$pdf->Ln($lineHeight - 40 + 15);

// Detail betalingen tabel
if (count($yearly_alloc) > 0) {
    $pdf->AddPage();
    $pdf->SetFont('helvetica', 'B', 13);
    $pdf->SetTextColor(26, 32, 44);
    $pdf->Cell(0, 10, 'Gedetailleerde Betalingslijst', 0, 1);
    $pdf->Ln(3);
    
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetFillColor(66, 153, 225);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(30, 7, 'Datum', 1, 0, 'C', true);
    $pdf->Cell(35, 7, 'Bedrag', 1, 0, 'C', true);
    $pdf->Cell(35, 7, 'Rente', 1, 0, 'C', true);
    $pdf->Cell(35, 7, 'Aflossing', 1, 0, 'C', true);
    $pdf->Cell(45, 7, 'Restschuld', 1, 1, 'C', true);
    
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(26, 32, 44);
    $fill = false;
    
    foreach ($yearly_alloc as $a) {
        $pdf->SetFillColor(248, 250, 252);
        $pdf->Cell(30, 6, date('d-m-Y', strtotime($a['date'])), 1, 0, 'C', $fill);
        $pdf->Cell(35, 6, money_fmt($a['amount']), 1, 0, 'R', $fill);
        $pdf->Cell(35, 6, money_fmt($a['interest']), 1, 0, 'R', $fill);
        $pdf->Cell(35, 6, money_fmt($a['principal']), 1, 0, 'R', $fill);
        $pdf->Cell(45, 6, money_fmt($a['remaining']), 1, 1, 'R', $fill);
        $fill = !$fill;
    }
}

// Disclaimer
$pdf->AddPage();
$pdf->SetFont('helvetica', 'B', 13);
$pdf->SetTextColor(26, 32, 44);
$pdf->Cell(0, 10, 'Belangrijk', 0, 1);
$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(113, 128, 150);
$pdf->MultiCell(0, 6, 
    "Dit document is gegenereerd door Leningmanager en dient als overzicht van de betalingen en aflossingen voor het jaar {$year}. " .
    "De rentebetalingen kunnen mogelijk aftrekbaar zijn voor de belastingaangifte, afhankelijk van uw situatie. " .
    "Raadpleeg altijd een belastingadviseur of accountant voor specifiek advies over uw belastingaangifte.\n\n" .
    "Dit overzicht bevat:\n" .
    "• Totaal betaalde bedragen per maand\n" .
    "• Verdeling tussen rente en aflossing\n" .
    "• Cumulatieve aflossing over het jaar\n" .
    "• Gedetailleerde betalingslijst\n\n" .
    "Gegenereerd op: " . date('d-m-Y H:i') . "\n" .
    "Voor vragen: neem contact op met uw leningverstrekker.",
    0, 'L');

// Output
$pdf->Output('lening_' . $loan['id'] . '_' . $year . '_belastingaangifte.pdf', 'I');