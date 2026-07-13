<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/config.php';
require_login();

$id   = (int)($_GET['id'] ?? 0);
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

// Rapportagesamenvatting: betalingen, rente, aflossing en hoofdsomverhogingen gescheiden houden.
$year_summary = summarize_allocations_for_year($alloc['allocations'], $year);
$yearly_alloc = $year_summary['yearly_allocations'];
$monthly_data = $year_summary['monthly_data'];
$total_amount = $year_summary['total_amount'];
$total_interest = $year_summary['total_interest'];
$total_principal = $year_summary['total_principal'];
$total_principal_increase = $year_summary['total_principal_increase'];

// Cumulatieve aflossing
$cumulative = 0;
$cumulative_data = [];
foreach ($monthly_data as $month => $data) {
    $cumulative += $data['principal'];
    $cumulative_data[$month] = $cumulative;
}

// PDF
require_once __DIR__ . '/../vendor/autoload.php';

class CustomPDF extends TCPDF {
    public function Header() {
        $this->SetFont('helvetica', 'B', 20);
        $this->SetTextColor(66, 153, 225);
        $appName = defined('APP_NAME') ? APP_NAME : 'Fiscana';
        $this->Cell(0, 15, $appName, 0, false, 'L');
        $this->SetFont('helvetica', '', 10);
        $this->SetTextColor(113, 128, 150);
        $this->Cell(0, 15, 'Belastingaangifte Overzicht', 0, false, 'R');
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
$pdf->SetAuthor(defined('APP_NAME') ? APP_NAME : 'Fiscana');
$pdf->SetTitle('Aflossingen ' . $loan['name'] . ' - ' . $year);
$pdf->SetSubject('Belastingaangifte overzicht');

$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);

$pdf->SetMargins(15, 27, 15);
$pdf->SetAutoPageBreak(true, 20);

$pdf->AddPage();

/*
 * ==========================
 * VOORBLAD + IDENTIFICATIE
 * ==========================
 */

$pdf->SetFont('helvetica', 'B', 26);
$pdf->SetTextColor(66, 153, 225);
$pdf->Ln(25);
$pdf->Cell(0, 20, 'Jaaroverzicht ' . $year, 0, 1, 'C');

$pdf->Ln(8);
$pdf->SetFont('helvetica', '', 12);
$pdf->SetTextColor(113, 128, 150);
$pdf->Cell(0, 8, 'Overzicht t.b.v. belastingaangifte', 0, 1, 'C');

$pdf->Ln(20);

// Identificatie
$lender_type = $loan['lender_type'] ?? 'private';
if ($lender_type === 'company') {
    $lender_name    = defined('LENDER_COMPANY_NAME') ? LENDER_COMPANY_NAME : 'Sebsoft Holding BV';
    $lender_address = defined('LENDER_COMPANY_ADDRESS') ? LENDER_COMPANY_ADDRESS : '';
} else {
    $lender_name    = defined('LENDER_PRIVATE_NAME') ? LENDER_PRIVATE_NAME : 'Sebastian R. Berm';
    $lender_address = defined('LENDER_PRIVATE_ADDRESS') ? LENDER_PRIVATE_ADDRESS : '';
}

$borrower = null;
if (!empty($loan['borrower_id'])) {
    $bq = $db->prepare('SELECT * FROM users WHERE id=?');
    $bq->execute([$loan['borrower_id']]);
    $borrower = $bq->fetch();
}
$borrower_name = $borrower['name'] ?? ($loan['borrower_name'] ?? '');

$loan_date = !empty($loan['start_date']) ? date('d-m-Y', strtotime($loan['start_date'])) : '';
$startDateForRef = !empty($loan['start_date'])
    ? date('Ymd', strtotime($loan['start_date']))
    : date('Ymd', strtotime($loan['created_at'] ?? date('Y-m-d')));

$internal_ref = 'LN-' . $loan['id'] . '-' . $startDateForRef . '-' . substr(md5($loan['id'] . '|' . ($loan['name'] ?? '')), 0, 6);

// ==========================
// Restschulden berekenen
// ==========================
$get_remaining_on = function(array $allocations, string $targetDate, float $defaultPrincipal): float {
    $targetTs = strtotime($targetDate);
    $lastRemaining = null;

    foreach ($allocations as $a) {
        $aTs = strtotime($a['date']);
        if ($aTs <= $targetTs) {
            $lastRemaining = (float)$a['remaining'];
        }
    }

    return $lastRemaining ?? $defaultPrincipal;
};

$rest_01_01 = $get_remaining_on(
    $alloc['allocations'],
    $year . '-01-01',
    (float)$loan['principal']
);

$rest_12_31 = $get_remaining_on(
    $alloc['allocations'],
    $year . '-12-31',
    (float)$loan['principal']
);

// Als lening na 1 januari van dit jaar is gestart
if (!empty($loan['start_date']) && strtotime($loan['start_date']) > strtotime($year . '-01-01')) {
    $rest_01_01 = 0.0;
}

$pdf->SetFont('helvetica', 'B', 15);
$pdf->SetTextColor(26, 32, 44);
$pdf->Cell(0, 10, 'Identificatie van partijen', 0, 1);
$pdf->Ln(3);

$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(55, 6, 'Leningverstrekker:', 0, 0, 'R');
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(0, 6, $lender_name, 0, 1);

if ($lender_address) {
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(113, 128, 150);
    $pdf->Cell(55, 5, '', 0, 0);
    $pdf->Cell(0, 5, $lender_address, 0, 1);
    $pdf->SetTextColor(26, 32, 44);
}

$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(55, 6, 'Leningnemer(s):', 0, 0, 'R');
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(0, 6, !empty($borrower_name) ? $borrower_name : $loan['name'], 0, 1);

$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(55, 6, 'Leningreferentie:', 0, 0, 'R');
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(0, 6, $internal_ref, 0, 1);

$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(55, 6, 'Datum overeenkomst:', 0, 0, 'R');
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(0, 6, $loan_date, 0, 1);

/*
 * ==========================
 * PAGINA 2 – OVERZICHT
 * ==========================
 */

$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true);
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

// Jaarsaldi (restschulden op specifieke data)
$pdf->Ln(3);
$pdf->SetXY(15, $boxY + $boxHeight + 6);
$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetTextColor(26, 32, 44);
$pdf->Cell(0, 7, 'Jaarsaldi', 0, 1);
$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(26, 32, 44);
$pdf->Cell(90, 6, 'Restschuld per 1 januari ' . $year . ':', 0, 0, 'R');
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(90, 6, money_fmt($rest_01_01), 0, 1, 'L');
$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(90, 6, 'Restschuld per 31 december ' . $year . ':', 0, 0, 'R');
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(90, 6, money_fmt($rest_12_31), 0, 1, 'L');
if ($total_principal_increase > 0) {
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(90, 6, 'Hoofdsomverhogingen in ' . $year . ':', 0, 0, 'R');
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(90, 6, money_fmt($total_principal_increase), 0, 1, 'L');
}

$pdf->Ln(15);

// Maandelijks overzicht
$pdf->SetFont('helvetica', 'B', 13);
$pdf->SetTextColor(26, 32, 44);
$pdf->Cell(0, 10, 'Maandelijks Overzicht', 0, 1);

$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetFillColor(66, 153, 225);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(30, 8, 'Maand', 1, 0, 'C', true);
$pdf->Cell(33, 8, 'Betaald', 1, 0, 'C', true);
$pdf->Cell(32, 8, 'Rente', 1, 0, 'C', true);
$pdf->Cell(32, 8, 'Aflossing', 1, 0, 'C', true);
$pdf->Cell(33, 8, 'Verhoging', 1, 0, 'C', true);
$pdf->Cell(20, 8, '% rente', 1, 1, 'C', true);

$pdf->SetFont('helvetica', '', 9);
$pdf->SetTextColor(26, 32, 44);

$months_nl = ['', 'Januari', 'Februari', 'Maart', 'April', 'Mei', 'Juni', 
              'Juli', 'Augustus', 'September', 'Oktober', 'November', 'December'];

$fill = false;
foreach ($monthly_data as $m => $data) {
    if ($data['payment_amount'] > 0 || $data['principal_increase'] > 0) {
        $pdf->SetFillColor(248, 250, 252);
        $percentage = $data['payment_amount'] > 0 ? ($data['interest'] / $data['payment_amount']) * 100 : 0;

        $pdf->Cell(30, 7, $months_nl[$m], 1, 0, 'L', $fill);
        $pdf->Cell(33, 7, $data['payment_amount'] > 0 ? money_fmt($data['payment_amount']) : '—', 1, 0, 'R', $fill);
        $pdf->Cell(32, 7, money_fmt($data['interest']), 1, 0, 'R', $fill);
        $pdf->Cell(32, 7, money_fmt($data['principal']), 1, 0, 'R', $fill);
        $pdf->Cell(33, 7, $data['principal_increase'] > 0 ? money_fmt($data['principal_increase']) : '—', 1, 0, 'R', $fill);
        $pdf->Cell(20, 7, $data['payment_amount'] > 0 ? number_format($percentage, 1) . '%' : '—', 1, 1, 'C', $fill);
        $fill = !$fill;
    }
}

// Totaal rij
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetFillColor(66, 153, 225);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(30, 8, 'TOTAAL', 1, 0, 'C', true);
$pdf->Cell(33, 8, money_fmt($total_amount), 1, 0, 'R', true);
$pdf->Cell(32, 8, money_fmt($total_interest), 1, 0, 'R', true);
$pdf->Cell(32, 8, money_fmt($total_principal), 1, 0, 'R', true);
$pdf->Cell(33, 8, $total_principal_increase > 0 ? money_fmt($total_principal_increase) : '—', 1, 0, 'R', true);
$percentage_total = $total_amount > 0 ? ($total_interest / $total_amount) * 100 : 0;
$pdf->Cell(20, 8, number_format($percentage_total, 1) . '%', 1, 1, 'C', true);

$pdf->AddPage();

// Grafiek 1: Maandelijkse betalingen (Bar Chart simulatie)
$pdf->SetFont('helvetica', 'B', 13);
$pdf->SetTextColor(26, 32, 44);
$pdf->Cell(0, 10, 'Grafiek: Maandelijkse Betalingen', 0, 1);
$pdf->Ln(3);

$maxValue = max(array_column($monthly_data, 'payment_amount'));
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
    if ($data['payment_amount'] > 0) {

        $barHeight = $maxValue > 0 ? ($data['payment_amount'] / $maxValue) * $graphHeight * 0.9 : 0;

        // Bereken segmenthoogtes
        $principalHeight = $data['payment_amount'] > 0 ? ($data['principal'] / $data['payment_amount']) * $barHeight : 0;
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

$activeMonths = array_filter($monthly_data, function($d) { return $d['payment_amount'] > 0; });
$monthCount = count($activeMonths);
$xSpacing = $monthCount > 0 ? $lineWidth / $monthCount : 0;

$i = 0;
foreach ($cumulative_data as $m => $cumVal) {
    if ($monthly_data[$m]['payment_amount'] > 0) {
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

$loan_start_year = !empty($loan['start_date'])
    ? (int)date('Y', strtotime($loan['start_date']))
    : null;

$show_start_row = ($loan_start_year === (int)$year);



// Detail betalingen tabel
if (count($yearly_alloc) > 0 || $show_start_row) {
    $pdf->AddPage();
    $pdf->SetFont('helvetica', 'B', 13);
    $pdf->SetTextColor(26, 32, 44);
    $pdf->Cell(0, 10, 'Gedetailleerde Betalingslijst', 0, 1);
    $pdf->Ln(3);
    
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetFillColor(66, 153, 225);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(28, 7, 'Datum', 1, 0, 'C', true);
    $pdf->Cell(32, 7, 'Type', 1, 0, 'C', true);
    $pdf->Cell(30, 7, 'Bedrag', 1, 0, 'C', true);
    $pdf->Cell(28, 7, 'Rente', 1, 0, 'C', true);
    $pdf->Cell(32, 7, 'Mutatie', 1, 0, 'C', true);
    $pdf->Cell(30, 7, 'Restschuld', 1, 1, 'C', true);
    
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(26, 32, 44);
    $fill = false;
    

    if ($show_start_row) {
        $pdf->SetFillColor(240, 244, 248);
        $pdf->SetFont('helvetica', 'I', 8);

        $pdf->Cell(
            28,
            6,
            date('d-m-Y', strtotime($loan['start_date'])),
            1,
            0,
            'C',
            true
        );
        $pdf->Cell(32, 6, 'Start', 1, 0, 'C', true);
        $pdf->Cell(30, 6, '—', 1, 0, 'C', true); // Bedrag
        $pdf->Cell(28, 6, '—', 1, 0, 'C', true); // Rente
        $pdf->Cell(32, 6, '—', 1, 0, 'C', true); // Mutatie
        $pdf->Cell(
            30,
            6,
            money_fmt($loan['principal']),
            1,
            1,
            'R',
            true
        );

        // Reset font
        $pdf->SetFont('helvetica', '', 8);
    }


    foreach ($yearly_alloc as $a) {
        $pdf->SetFillColor(248, 250, 252);
        $pdf->Cell(28, 6, date('d-m-Y', strtotime($a['date'])), 1, 0, 'C', $fill);
        $pdf->Cell(32, 6, $a['type_label'] ?? transaction_type_label($a['transaction_type'] ?? 'payment'), 1, 0, 'L', $fill);
        $pdf->Cell(30, 6, money_fmt($a['amount']), 1, 0, 'R', $fill);
        $pdf->Cell(28, 6, money_fmt($a['interest']), 1, 0, 'R', $fill);
        $pdf->Cell(32, 6, money_fmt($a['principal']), 1, 0, 'R', $fill);
        $pdf->Cell(30, 6, money_fmt($a['remaining']), 1, 1, 'R', $fill);
        $fill = !$fill;
    }
}

// Fiscale duiding (neutraal, niet adviserend)
$pdf->AddPage();
$pdf->SetFont('helvetica', 'B', 13);
$pdf->SetTextColor(26, 32, 44);
$pdf->Cell(0, 10, 'Fiscale duiding', 0, 1);
$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(113, 128, 150);
$renteTekst = isset($loan['rate']) ? ($loan['rate'] . '% per jaar') : '';
$pdf->MultiCell(0, 6,
    "Type lening: onderhandse (niet-notariële) lening.\n" .
    "De rente is zakelijk vastgesteld: " . $renteTekst . ".\n\n" .
    "Deze weergave bevat een overzicht van betaalde bedragen, rente- en aflossingscomponenten en restschulden. " .
    "De informatie kan relevant zijn voor uw belastingaangifte. Dit document geeft echter geen belastingadvies; " .
    "voor specifieke vragen met betrekking tot belastingpositie of aftrekbaarheid raden wij aan een belastingadviseur of accountant te raadplegen.\n\n" .
    "Gegenereerd op: " . date('d-m-Y H:i') . "\n" .
    "Contact (leningverstrekker): " . $lender_name . ( !empty($lender_address) ? ' - ' . $lender_address : '' ),
    0, 'L');

// Ondertekening - laatste pagina
/*
$pdf->AddPage();
$pdf->SetFont('helvetica', 'B', 13);
$pdf->SetTextColor(26, 32, 44);
$pdf->Cell(0, 10, 'Voor akkoord', 0, 1);
$pdf->Ln(6);
$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(50, 7, 'Naam leningverstrekker:', 0, 0, 'R');
$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(0, 7, $lender_name, 0, 1, 'L');
$pdf->Ln(8);
$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(50, 7, 'Datum:', 0, 0, 'R');
$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(0, 7, date('d-m-Y'), 0, 1, 'L');
$pdf->Ln(12);
$pdf->SetFont('helvetica', '', 10);
$pdf->MultiCell(0, 6, "Handtekening (ruimte):\n\n\n..............................................................", 0, 'L');
*/
// Output
$pdf->Output('lening_' . $loan['id'] . '_' . $year . '_belastingaangifte.pdf', 'I');
