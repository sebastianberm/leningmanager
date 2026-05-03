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
        $date      = trim((string)($dates[$i]   ?? ''));
        $amountRaw = trim((string)($amounts[$i] ?? ''));
        if ($date === '' && $amountRaw === '') continue;
        if ($date === '' || $amountRaw === '') continue;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
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
        if ($p['date'] >= $startDate && $p['date'] <= $date) {
            $balance -= $p['amount'] * pow(1.0 + $rate, year_fraction($p['date'], $date));
        }
    }
    foreach ($costs as $c) {
        if ($c['date'] >= $startDate && $c['date'] <= $date) {
            $balance += $c['amount'] * pow(1.0 + $rate, year_fraction($c['date'], $date));
        }
    }
    return $balance;
}

function solve_rate(float $startBalance, string $startDate, float $targetBalance, string $targetDate, array $payments, array $costs): ?float {
    $f = fn(float $r): float => predicted_balance_at($targetDate, $startBalance, $startDate, $r, $payments, $costs) - $targetBalance;
    $low = -0.99; $high = 5.0;
    $fLow = $f($low); $fHigh = $f($high);
    if ($fLow * $fHigh > 0) return null;
    for ($i = 0; $i < 200; $i++) {
        $mid  = ($low + $high) / 2.0;
        $fMid = $f($mid);
        if (abs($fMid) < 0.000001) return $mid;
        if ($fLow * $fMid <= 0) { $high = $mid; $fHigh = $fMid; } else { $low = $mid; $fLow = $fMid; }
    }
    return ($low + $high) / 2.0;
}

function rate_label(float $rate): string {
    $pct = $rate * 100;
    if ($pct < 2)   return 'zeer laag';
    if ($pct < 4)   return 'laag';
    if ($pct < 6)   return 'gemiddeld';
    if ($pct < 9)   return 'hoog';
    return 'zeer hoog';
}

function rate_context(float $rate): string {
    $pct = $rate * 100;
    $fmt = number_format($pct, 2, ',', '.');
    if ($pct < 0)   return "De berekende rente is negatief ({$fmt}%). Dit wijst mogelijk op een subsidie of storting die groter is dan de schuld.";
    if ($pct < 2)   return "De berekende rente van {$fmt}% per jaar is zeer laag. Dit is lager dan de meeste spaarrekeningen en hypotheken. Controleer of de invoergegevens kloppen.";
    if ($pct < 4)   return "De berekende rente van {$fmt}% per jaar is laag tot gemiddeld — vergelijkbaar met een hypotheek of zakelijk krediet van de afgelopen jaren.";
    if ($pct < 6)   return "De berekende rente van {$fmt}% per jaar is gemiddeld. Dit is gebruikelijk voor persoonlijke leningen en zakelijke kredieten.";
    if ($pct < 9)   return "De berekende rente van {$fmt}% per jaar is aan de hoge kant. Dit kan wijzen op een consumptief krediet of rekening-courant.";
    if ($pct < 15)  return "De berekende rente van {$fmt}% per jaar is hoog. Dit is kenmerkend voor doorlopend krediet of creditcardschulden.";
    return "De berekende rente van {$fmt}% per jaar is uitzonderlijk hoog. Controleer de invoergegevens zorgvuldig.";
}

function avg_delta(array $checks): float {
    if (!$checks) return 0.0;
    $sum = 0.0;
    foreach ($checks as $c) $sum += abs($c['delta']);
    return $sum / count($checks);
}

function fit_label(float $avgDelta): string {
    if ($avgDelta < 0.01)  return 'Uitstekend';
    if ($avgDelta < 1.00)  return 'Goed';
    if ($avgDelta < 10.00) return 'Redelijk';
    return 'Matig';
}

function rate_traffic_color(float $rate): string {
    $pct = $rate * 100;
    if ($pct < 4)  return 'green';
    if ($pct < 7)  return 'orange';
    return 'red';
}

function fit_traffic_color(float $avgDelta): string {
    if ($avgDelta < 1.00)  return 'green';
    if ($avgDelta < 10.00) return 'orange';
    return 'red';
}

function fit_explanation(float $avgDelta): string {
    if ($avgDelta < 0.01)  return 'De berekening sluit vrijwel exact aan op alle bekende saldi. De gevonden rente is zeer betrouwbaar.';
    if ($avgDelta < 1.00)  return 'De berekening wijkt gemiddeld minder dan €1,- af van de bekende saldi. De gevonden rente is betrouwbaar.';
    if ($avgDelta < 10.00) return 'Er is een kleine afwijking. Dit kan komen door afronding, dagrenteberekeningen of transacties die niet zijn opgegeven.';
    return 'De afwijking is relatief groot. Controleer of alle aflossingen en extra kosten correct zijn ingevoerd.';
}

function run_calculation(array $post): array {
    $errors   = [];
    $known    = parse_rows($post['known_dates']   ?? [], $post['known_amounts']   ?? []);
    $payments = parse_rows($post['payment_dates'] ?? [], $post['payment_amounts'] ?? []);
    $costs    = parse_rows($post['cost_dates']    ?? [], $post['cost_amounts']    ?? []);
    if (count($known) < 2) $errors[] = 'Vul minimaal 2 pijldatums met bekende bedragen in (datum én bedrag zijn beide verplicht).';
    $result = null;
    if (!$errors) {
        $start = $known[0];
        $end   = $known[count($known) - 1];
        $rate  = solve_rate((float)$start['amount'], $start['date'], (float)$end['amount'], $end['date'], $payments, $costs);
        if ($rate === null) {
            $errors[] = 'Kon geen rente vinden die past bij deze invoer. Controleer of de bedragen en datums kloppen, en of alle aflossingen zijn opgegeven.';
        } else {
            $checks = [];
            foreach ($known as $k) {
                $pred     = predicted_balance_at($k['date'], (float)$start['amount'], $start['date'], $rate, $payments, $costs);
                $delta    = $pred - (float)$k['amount'];
                $checks[] = ['date' => $k['date'], 'known' => (float)$k['amount'], 'predicted' => $pred, 'delta' => $delta];
            }
            $monthly_rate    = pow(1.0 + $rate, 1.0 / 12.0) - 1.0;
            $nominal_monthly = $rate / 12.0;
            $avg             = avg_delta($checks);
            $result = compact('rate', 'monthly_rate', 'nominal_monthly', 'start', 'end', 'checks', 'payments', 'costs', 'known', 'avg');
        }
    }
    return [$errors, $result, $known, $payments, $costs];
}

/* ──────────────────────────────────────────────
   PDF EXPORT
────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_pdf'])) {
    [$errors, $result] = run_calculation($_POST);
    if (!$errors && $result) {
        require_once __DIR__ . '/../vendor/autoload.php';

        $ratePct      = number_format($result['rate'] * 100, 4, ',', '.');
        $monthlyPct   = number_format($result['monthly_rate'] * 100, 6, ',', '.');
        $nomMonthlyPct = number_format($result['nominal_monthly'] * 100, 6, ',', '.');
        $fitLbl       = fit_label($result['avg']);
        $fitExpl      = fit_explanation($result['avg']);
        $rateCtx      = rate_context($result['rate']);
        $rateLabel    = rate_label($result['rate']);
        $trafficColor = rate_traffic_color($result['rate']);
        $exportDate   = date('d-m-Y');
        $periodFrom   = date('d-m-Y', strtotime($result['start']['date']));
        $periodTo     = date('d-m-Y', strtotime($result['end']['date']));

        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Fiscana');
        $pdf->SetAuthor('Fiscana Rentecheck');
        $pdf->SetTitle('Renteanalyse – ' . $periodFrom . ' t/m ' . $periodTo);
        $pdf->SetMargins(20, 15, 20);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();

        // ── HEADER BANNER
        $pdf->SetFillColor(66, 97, 181);
        $pdf->Rect(0, 0, 210, 38, 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 20);
        $pdf->SetXY(20, 8);
        $pdf->Cell(0, 10, 'Renteanalyse', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 11);
        $pdf->SetXY(20, 20);
        $pdf->Cell(0, 6, 'Berekening van de impliciete effectieve jaarrente', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetXY(20, 28);
        $pdf->Cell(0, 6, 'Gegenereerd op ' . $exportDate . '  |  Periode ' . $periodFrom . ' t/m ' . $periodTo, 0, 1, 'L');

        $pdf->SetTextColor(30, 30, 30);
        $pdf->SetY(48);

        // ── UITLEG BOX
        $pdf->SetFillColor(241, 245, 249);
        $pdf->SetDrawColor(200, 213, 230);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 7, 'Wat is dit rapport?', 1, 1, 'L', true);
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetFillColor(248, 250, 252);
        $intro = 'Dit rapport berekent de werkelijke rente die gehanteerd is bij een lening of schuld, op basis van bekende saldi op specifieke datums. '
               . 'U heeft een beginsaldo en een eindsaldo opgegeven. Door te berekenen welke rente ervoor zorgt dat het beginsaldo – rekening houdend '
               . 'met aflossingen en kosten – uitkomt op het eindsaldo, vinden we de impliciete effectieve jaarrente. '
               . 'Deze rente kunt u vergelijken met de rente die in contracten staat vermeld, of gebruiken om te controleren of de juiste rente in rekening is gebracht.';
        $pdf->MultiCell(0, 5.5, $intro, 1, 'L', true);
        $pdf->Ln(4);

        // ── HOOFDRESULTAAT
        $pdf->SetFillColor(66, 97, 181);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 8, 'Gevonden rente', 0, 1, 'L', true);
        $pdf->SetTextColor(30, 30, 30);
        $pdf->SetFillColor(255, 255, 255);

        // Rente blokken naast elkaar (3 kolommen, geen kwalificatie erin)
        $pdf->SetDrawColor(200, 213, 230);
        $pdf->SetFillColor(249, 251, 253);
        $col = 55;
        $bh  = 20;

        $pdf->SetFont('helvetica', '', 8);
        $pdf->Cell($col, 6, 'Effectieve jaarrente', 1, 0, 'C', true);
        $pdf->Cell($col, 6, 'Effectieve maandrente', 1, 0, 'C', true);
        $pdf->Cell(0,    6, 'Nominale maandrente',   1, 1, 'C', true);

        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Cell($col, $bh, $ratePct . '%',      1, 0, 'C', true);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell($col, $bh, $monthlyPct . '%',   1, 0, 'C', true);
        $pdf->Cell(0,    $bh, $nomMonthlyPct . '%',1, 1, 'C', true);
        $pdf->Ln(4);

        // ── KWALIFICATIE STOPLICHT
        if ($trafficColor === 'green')      $pdf->SetFillColor(34, 197, 94);
        elseif ($trafficColor === 'orange') $pdf->SetFillColor(249, 115, 22);
        else                                $pdf->SetFillColor(239, 68, 68);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 10, 'Kwalificatie rente: ' . strtoupper($rateLabel), 1, 1, 'C', true);
        $pdf->SetTextColor(30, 30, 30);
        $pdf->Ln(2);

        // Toelichting op de rente
        $pdf->SetFont('helvetica', 'I', 9);
        $pdf->SetFillColor(254, 252, 232);
        $pdf->SetDrawColor(245, 205, 50);
        $pdf->MultiCell(0, 5.5, $rateCtx, 1, 'L', true);
        $pdf->Ln(4);

        // ── UITLEG BEGRIPPEN
        $pdf->SetFillColor(66, 97, 181);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 8, 'Wat betekenen deze getallen?', 0, 1, 'L', true);
        $pdf->SetTextColor(30, 30, 30);
        $pdf->SetFillColor(248, 250, 252);
        $pdf->SetDrawColor(200, 213, 230);
        $pdf->SetFont('helvetica', '', 9);
        $begrippen = [
            ['Effectieve jaarrente',   'De werkelijke renteopslag per jaar, inclusief het samengesteld-renteeffect. Dit is het meest gebruikte getal om leningen met elkaar te vergelijken.'],
            ['Effectieve maandrente',  'Wat u per maand feitelijk aan rente betaalt over het openstaande saldo, inclusief het samengesteld-renteeffect binnen het jaar.'],
            ['Nominale maandrente',    'De jaarrente simpelweg gedeeld door 12. Banken gebruiken dit getal soms in contracten. Het is iets lager dan de effectieve maandrente.'],
        ];
        foreach ($begrippen as [$term, $uitleg]) {
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(55, 6, $term, 0, 0, 'L');
            $pdf->SetFont('helvetica', '', 9);
            $pdf->MultiCell(0, 6, $uitleg, 0, 'L');
        }
        $pdf->Ln(3);

        // ── CONTROLE TABEL
        $pdf->SetFillColor(66, 97, 181);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 8, 'Controle per pijldatum', 0, 1, 'L', true);
        $pdf->SetTextColor(30, 30, 30);

        // Leg uit wat een pijldatum is
        $pdf->SetFillColor(241, 245, 249);
        $pdf->SetDrawColor(200, 213, 230);
        $pdf->SetFont('helvetica', 'I', 9);
        $pdf->MultiCell(0, 5.5,
            'Een pijldatum is een datum waarop het exacte saldo van de lening bekend is. De tabel hieronder laat zien of het berekende saldo '
            . '(op basis van de gevonden rente) overeenkomt met het door u opgegeven saldo. Een klein verschil (enkele centen) is normaal door afronding. '
            . 'Een groot verschil kan betekenen dat niet alle aflossingen of kosten zijn opgegeven.',
        1, 'L', true);
        $pdf->Ln(2);

        $colW = [35, 42, 42, 42, 0];
        $pdf->SetFillColor(230, 236, 248);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell($colW[0], 7, 'Datum',          1, 0, 'C', true);
        $pdf->Cell($colW[1], 7, 'Bekend saldo',   1, 0, 'C', true);
        $pdf->Cell($colW[2], 7, 'Berekend saldo', 1, 0, 'C', true);
        $pdf->Cell(0,        7, 'Afwijking',       1, 1, 'C', true);

        $pdf->SetFont('helvetica', '', 9);
        $rowToggle = false;
        foreach ($result['checks'] as $c) {
            $abs = abs($c['delta']);
            $pdf->SetFillColor($rowToggle ? 255 : 248, $rowToggle ? 255 : 250, $rowToggle ? 255 : 252);
            if ($abs >= 10) $pdf->SetFillColor(255, 240, 240);
            elseif ($abs >= 1) $pdf->SetFillColor(255, 251, 235);
            $dFmt = date('d-m-Y', strtotime($c['date']));
            $sign = $c['delta'] >= 0 ? '+' : '';
            $pdf->Cell($colW[0], 6, $dFmt,                              1, 0, 'C', true);
            $pdf->Cell($colW[1], 6, money_fmt($c['known']),             1, 0, 'R', true);
            $pdf->Cell($colW[2], 6, money_fmt($c['predicted']),         1, 0, 'R', true);
            $pdf->Cell(0,        6, $sign . money_fmt($c['delta']),     1, 1, 'R', true);
            $rowToggle = !$rowToggle;
        }

        // Nauwkeurigheid samenvatting
        $pdf->Ln(2);
        $pdf->SetFillColor(241, 245, 249);
        $pdf->SetDrawColor(200, 213, 230);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(55, 6, 'Nauwkeurigheid berekening: ' . $fitLbl, 0, 0, 'L');
        $pdf->SetFont('helvetica', 'I', 9);
        $pdf->MultiCell(0, 6, $fitExpl, 0, 'L');
        $pdf->Ln(4);

        // ── AFLOSSINGEN (optioneel)
        if (!empty($result['payments'])) {
            $pdf->SetFillColor(66, 97, 181);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->Cell(0, 8, 'Meegenomen aflossingen', 0, 1, 'L', true);
            $pdf->SetTextColor(30, 30, 30);
            $pdf->SetFont('helvetica', 'I', 9);
            $pdf->SetFillColor(241, 245, 249);
            $pdf->MultiCell(0, 5.5, 'De onderstaande bedragen zijn in de berekening meegenomen als vermindering van de schuld op de opgegeven datum.', 1, 'L', true);
            $pdf->Ln(2);
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->SetFillColor(230, 236, 248);
            $pdf->Cell(55, 7, 'Datum', 1, 0, 'C', true);
            $pdf->Cell(0,  7, 'Afgelost bedrag', 1, 1, 'C', true);
            $pdf->SetFont('helvetica', '', 9);
            $rowToggle = false;
            foreach ($result['payments'] as $p) {
                $pdf->SetFillColor($rowToggle ? 255 : 248, $rowToggle ? 255 : 250, $rowToggle ? 255 : 252);
                $pdf->Cell(55, 6, date('d-m-Y', strtotime($p['date'])), 1, 0, 'C', true);
                $pdf->Cell(0,  6, money_fmt($p['amount']), 1, 1, 'R', true);
                $rowToggle = !$rowToggle;
            }
            $pdf->Ln(4);
        }

        // ── EXTRA KOSTEN (optioneel)
        if (!empty($result['costs'])) {
            $pdf->SetFillColor(66, 97, 181);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->Cell(0, 8, 'Meegenomen extra kosten', 0, 1, 'L', true);
            $pdf->SetTextColor(30, 30, 30);
            $pdf->SetFont('helvetica', 'I', 9);
            $pdf->SetFillColor(241, 245, 249);
            $pdf->MultiCell(0, 5.5, 'De onderstaande bedragen zijn in de berekening meegenomen als verhoging van de schuld (bijv. boetes, administratiekosten).', 1, 'L', true);
            $pdf->Ln(2);
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->SetFillColor(230, 236, 248);
            $pdf->Cell(55, 7, 'Datum', 1, 0, 'C', true);
            $pdf->Cell(0,  7, 'Kostenbedrag', 1, 1, 'C', true);
            $pdf->SetFont('helvetica', '', 9);
            $rowToggle = false;
            foreach ($result['costs'] as $c) {
                $pdf->SetFillColor($rowToggle ? 255 : 248, $rowToggle ? 255 : 250, $rowToggle ? 255 : 252);
                $pdf->Cell(55, 6, date('d-m-Y', strtotime($c['date'])), 1, 0, 'C', true);
                $pdf->Cell(0,  6, money_fmt($c['amount']), 1, 1, 'R', true);
                $rowToggle = !$rowToggle;
            }
            $pdf->Ln(4);
        }

        // ── METHODE TOELICHTING
        $pdf->SetFillColor(66, 97, 181);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 8, 'Hoe is de rente berekend?', 0, 1, 'L', true);
        $pdf->SetTextColor(30, 30, 30);
        $pdf->SetFillColor(248, 250, 252);
        $pdf->SetDrawColor(200, 213, 230);
        $pdf->SetFont('helvetica', '', 9);
        $methode = 'De rente is berekend met een wiskundige zoekprocedure (bisectiemethode). Het systeem zoekt naar de rente '
                 . 'waarbij het beginsaldo – dagelijks opgerent en verminderd met elke aflossing op het juiste moment – '
                 . 'uitkomt op het opgegeven eindsaldo. Er zijn maximaal 200 iteraties uitgevoerd met een nauwkeurigheid '
                 . 'van €0,001 (0,1 cent). De gebruikte formule is:' . "\n\n"
                 . 'B(t) = B0 × (1 + r)^Δt  −  Σ Aᵢ × (1 + r)^Δtᵢ  +  Σ Kⱼ × (1 + r)^Δtⱼ' . "\n\n"
                 . 'Hierin is: B(t) = saldo op datum t,  B0 = beginsaldo,  r = effectieve jaarrente,  Δt = tijdverschil in jaren (op basis van 365 dagen per jaar),  '
                 . 'Aᵢ = aflossingen,  Kⱼ = extra kosten.';
        $pdf->MultiCell(0, 5.5, $methode, 1, 'L', true);
        $pdf->Ln(3);

        // ── DISCLAIMER
        $pdf->SetFillColor(245, 245, 245);
        $pdf->SetDrawColor(210, 210, 210);
        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->SetTextColor(120, 120, 120);
        $disclaimer = 'Dit rapport is uitsluitend bedoeld ter informatie en kan niet worden gebruikt als juridisch of financieel advies. '
                    . 'De berekening is gebaseerd op de door de gebruiker opgegeven gegevens. Fiscana aanvaardt geen aansprakelijkheid voor onjuistheden die voortvloeien uit onjuiste invoer.';
        $pdf->MultiCell(0, 4.5, $disclaimer, 1, 'L', true);

        // ── FOOTER OP ELKE PAGINA (handmatig onderaan eerste pagina)
        $pdf->SetTextColor(150, 150, 150);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetY(-15);
        $pdf->Cell(0, 5, 'Fiscana Rentecheck  |  Gegenereerd op ' . $exportDate, 0, 0, 'C');

        $pdf->Output('renteanalyse_' . date('Ymd') . '.pdf', 'I');
        exit;
    }
}

$errors = []; $result = null; $known = []; $payments = []; $costs = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    [$errors, $result, $known, $payments, $costs] = run_calculation($_POST);
}
include __DIR__ . '/partials_header.php';
?>

<style>
.rc-step-badge {
  display: inline-flex; align-items: center; justify-content: center;
  width: 2rem; height: 2rem; border-radius: 50%;
  background: linear-gradient(135deg, #667eea, #764ba2);
  color: #fff; font-weight: 700; font-size: 1rem; flex-shrink: 0;
}
.rc-section-header {
  display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.25rem;
}
.rc-help {
  font-size: 0.85rem; color: #718096;
  background: #f7fafc; border-left: 3px solid #4299e1;
  border-radius: 0 0.5rem 0.5rem 0; padding: 0.5rem 0.75rem;
  margin-bottom: 0.75rem;
}
.rc-col-labels {
  display: flex; gap: 0.5rem; padding: 0 0 0.25rem 0;
  font-size: 0.78rem; font-weight: 600; text-transform: uppercase;
  letter-spacing: 0.04em; color: #718096;
}
.rc-col-labels .col-auto { min-width: 5.5rem; }
.input-row .btn-outline-danger { opacity: 0.55; }
.input-row .btn-outline-danger:hover { opacity: 1; }
.rc-stat-card {
  border: 1px solid #e2e8f0; border-radius: 0.75rem; padding: 1rem 1.25rem;
  text-align: center; background: #fff;
  transition: border-color 0.2s, box-shadow 0.2s;
}
.rc-stat-card:hover { border-color: #4299e1; box-shadow: 0 0 0 3px rgba(66,153,225,0.1); }
.rc-stat-card .label { font-size: 0.78rem; color: #718096; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; }
.rc-stat-card .value { font-size: 1.6rem; font-weight: 800; color: #2b6cb0; line-height: 1.2; }
.rc-stat-card .value.sm { font-size: 1.15rem; }
.rc-stat-card .sub { font-size: 0.78rem; color: #a0aec0; margin-top: 0.1rem; }
.rc-uitleg-box {
  background: #f0f7ff; border: 1px solid #bee3f8; border-radius: 0.75rem;
  padding: 1rem 1.25rem; font-size: 0.9rem; color: #2c5282;
}
.rc-uitleg-box strong { display: block; margin-bottom: 0.25rem; }
.rc-context-box {
  background: #fffbeb; border: 1px solid #f6e05e; border-radius: 0.75rem;
  padding: 0.75rem 1rem; font-size: 0.9rem; color: #744210;
}
.delta-ok   { color: #276749; }
.delta-warn { color: #c05621; }
.delta-bad  { color: #c53030; }
.fit-badge { display: inline-block; padding: 0.2em 0.7em; border-radius: 1em; font-size: 0.8rem; font-weight: 700; }
.fit-Uitstekend { background: #c6f6d5; color: #22543d; }
.fit-Goed       { background: #bee3f8; color: #2a4365; }
.fit-Redelijk   { background: #fefcbf; color: #744210; }
.fit-Matig      { background: #fed7d7; color: #742a2a; }
/* Traffic light */
.rc-traffic-light {
  display: flex; align-items: center; gap: 0.6rem;
  padding: 0.65rem 1rem; border-radius: 0.75rem;
  font-size: 0.9rem;
}
.rc-traffic-light.tl-green  { background: #f0fdf4; border: 1px solid #86efac; color: #14532d; }
.rc-traffic-light.tl-orange { background: #fff7ed; border: 1px solid #fed7aa; color: #7c2d12; }
.rc-traffic-light.tl-red    { background: #fef2f2; border: 1px solid #fecaca; color: #7f1d1d; }
.rc-tl-dot {
  display: inline-block; width: 14px; height: 14px; border-radius: 50%; flex-shrink: 0;
}
.rc-tl-dot.tl-green  { background: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,0.2); }
.rc-tl-dot.tl-orange { background: #f97316; box-shadow: 0 0 0 3px rgba(249,115,22,0.2); }
.rc-tl-dot.tl-red    { background: #ef4444; box-shadow: 0 0 0 3px rgba(239,68,68,0.2); }
.rc-btn-duplicate { opacity: 0.55; font-size: 0.75rem; padding: 0.2rem 0.45rem; }
.rc-btn-duplicate:hover { opacity: 1; }
</style>

<!-- PAGE HEADER -->
<div class="card p-4 mb-4">
  <div class="d-flex align-items-start gap-3">
    <div style="font-size:2.5rem; line-height:1;">🧮</div>
    <div>
      <h1 class="mb-1">Rente rekenmodule</h1>
      <p class="text-muted mb-0">
        Bereken de werkelijke rente van een lening op basis van bekende saldi.
        Handig om te controleren welke rente er feitelijk is berekend — ook als het contract onduidelijk is.
      </p>
    </div>
  </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger mb-4">
  <strong>Er ging iets mis:</strong><br>
  <?= implode('<br>', array_map('h', $errors)) ?>
</div>
<?php endif; ?>

<!-- FORMULIER -->
<div class="card p-4 mb-4">
  <form method="post" id="calcForm" novalidate><?php csrf_field(); ?>

    <!-- STAP 1 -->
    <div class="rc-section-header">
      <span class="rc-step-badge">1</span>
      <h5 class="mb-0">Bekende saldi <small class="text-muted fw-normal" style="font-size:0.85rem;">(minimaal 2 verplicht)</small></h5>
    </div>
    <div class="rc-help">
      Voer de datums in waarop u het exacte saldo van de lening weet — dit noemen we <strong>pijldatums</strong>.
      Minimaal het beginsaldo (op de startdatum) en het eindsaldo (op de meest recente datum) zijn verplicht.
      Hoe meer pijldatums u invult, hoe nauwkeuriger de berekening.<br>
      <em>Voorbeeld: op 01-01-2022 stond de schuld op €50.000 en op 01-01-2024 op €45.200.</em>
    </div>
    <div class="rc-col-labels row g-2 px-1">
      <div class="col-md-4"><span>Datum</span></div>
      <div class="col-md-4"><span>Saldo op die datum (€)</span></div>
      <div class="col-auto" style="min-width:5.5rem;"></div>
    </div>
    <div id="knownRows"></div>
    <button class="btn btn-sm btn-outline-secondary mb-4" type="button" onclick="addRow('knownRows','known')">
      + Pijldatum toevoegen
    </button>

    <!-- STAP 2 -->
    <div class="rc-section-header">
      <span class="rc-step-badge">2</span>
      <h5 class="mb-0">Aflossingen <small class="text-muted fw-normal" style="font-size:0.85rem;">(optioneel)</small></h5>
    </div>
    <div class="rc-help">
      Vul hier alle betalingen in die gedaan zijn om de schuld te verminderen — zoals maandelijkse termijnen of extra aflossingen.
      Zonder aflossingen gaat de berekening ervan uit dat de schuld puur door rente gegroeid of geslonken is.<br>
      <em>Voorbeeld: elke maand €500 afgelost.</em>
    </div>
    <div class="rc-col-labels row g-2 px-1">
      <div class="col-md-4"><span>Datum van betaling</span></div>
      <div class="col-md-4"><span>Afgelost bedrag (€)</span></div>
      <div class="col-auto" style="min-width:5.5rem;"></div>
    </div>
    <div id="paymentRows"></div>
    <button class="btn btn-sm btn-outline-secondary mb-4" type="button" onclick="addRow('paymentRows','payment')">
      + Aflossing toevoegen
    </button>

    <!-- STAP 3 -->
    <div class="rc-section-header">
      <span class="rc-step-badge">3</span>
      <h5 class="mb-0">Extra kosten <small class="text-muted fw-normal" style="font-size:0.85rem;">(optioneel)</small></h5>
    </div>
    <div class="rc-help">
      Extra kosten zijn bedragen die de schuld verhogen — denk aan dossierkosten, boetes of andere bijschrijvingen.
      Deze worden bij het saldo opgeteld op de datum waarop ze gemaakt zijn.<br>
      <em>Voorbeeld: op 15-03-2022 is €150 aan administratiekosten bijgeschreven.</em>
    </div>
    <div class="rc-col-labels row g-2 px-1">
      <div class="col-md-4"><span>Datum</span></div>
      <div class="col-md-4"><span>Kostenbedrag (€)</span></div>
      <div class="col-auto" style="min-width:5.5rem;"></div>
    </div>
    <div id="costRows"></div>
    <button class="btn btn-sm btn-outline-secondary mb-4" type="button" onclick="addRow('costRows','cost')">
      + Kost toevoegen
    </button>

    <hr>
    <div class="d-flex flex-wrap gap-2 align-items-center">
      <button class="btn btn-primary" name="calculate" value="1">
        🔍 Bereken rente
      </button>
      <?php if ($result): ?>
        <button class="btn btn-outline-dark" name="export_pdf" value="1">
          📄 Exporteer rapport (PDF)
        </button>
      <?php endif; ?>
      <button class="btn btn-outline-secondary ms-auto" type="button" onclick="resetForm()">
        Nieuw formulier
      </button>
    </div>
  </form>
</div>

<!-- RESULTAAT -->
<?php if ($result):
    $fitLbl       = fit_label($result['avg']);
    $fitExpl      = fit_explanation($result['avg']);
    $rateCtx      = rate_context($result['rate']);
    $trafficColor = rate_traffic_color($result['rate']);
    $fitColor     = fit_traffic_color($result['avg']);
?>
<div class="card p-4 mb-4">

  <h4 class="mb-3">Uitkomst</h4>

  <!-- Stat blokken -->
  <div class="row g-3 mb-3">
    <div class="col-sm-4">
      <div class="rc-stat-card">
        <div class="label">Effectieve jaarrente</div>
        <div class="value"><?= number_format($result['rate'] * 100, 4, ',', '.') ?>%</div>
        <div class="sub">Per jaar, inclusief rente-op-rente</div>
      </div>
    </div>
    <div class="col-sm-4">
      <div class="rc-stat-card">
        <div class="label">Effectieve maandrente</div>
        <div class="value sm"><?= number_format($result['monthly_rate'] * 100, 6, ',', '.') ?>%</div>
        <div class="sub">Feitelijk per maand</div>
      </div>
    </div>
    <div class="col-sm-4">
      <div class="rc-stat-card">
        <div class="label">Nominale maandrente</div>
        <div class="value sm"><?= number_format($result['nominal_monthly'] * 100, 6, ',', '.') ?>%</div>
        <div class="sub">Jaarrente ÷ 12</div>
      </div>
    </div>
  </div>

  <!-- Kwalificatie stoplicht -->
  <div class="rc-traffic-light tl-<?= h($trafficColor) ?> mb-3">
    <span class="rc-tl-dot tl-<?= h($trafficColor) ?>"></span>
    <span><strong>Kwalificatie rente: <?= h(rate_label($result['rate'])) ?></strong></span>
  </div>

  <!-- Context uitleg over de gevonden rente -->
  <div class="rc-context-box mb-3">
    💡 <?= h($rateCtx) ?>
  </div>

  <!-- Wat betekenen deze getallen -->
  <details class="mb-3">
    <summary class="mortgage-details-summary fw-semibold" style="font-size:0.9rem;">Wat betekenen deze getallen? (klik om uit te klappen)</summary>
    <div class="rc-uitleg-box mt-2">
      <strong>Effectieve jaarrente</strong>
      Dit is de werkelijke jaarlijkse rentevoet inclusief het effect van rente-op-rente. Dit is het standaard vergelijkingsgetal voor leningen.
      Een hypotheek van 4% effectief kost per jaar 4% van de uitstaande schuld aan rente.<br><br>
      <strong>Effectieve maandrente</strong>
      Wat u per maand feitelijk aan rente kwijt bent, rekening houdend met het samengesteld-renteeffect.
      Berekend als: (1 + jaarrente)^(1/12) − 1.<br><br>
      <strong>Nominale maandrente</strong>
      De jaarrente simpelweg gedeeld door 12. Dit getal is iets lager dan de effectieve maandrente.
      Sommige banken vermelden dit in contracten omdat het gunstiger lijkt. Het verschil is doorgaans klein maar relevant bij hoge rentes.
    </div>
  </details>

  <!-- Grafiek: saldo over tijd -->
  <div class="mb-4">
    <h6 class="mb-2">Saldoverloop</h6>
    <canvas id="renteChart" height="80"></canvas>
  </div>

  <!-- Controle tabel -->
  <h6 class="mt-2 mb-1">Controle per pijldatum
    <span class="rc-tl-dot tl-<?= h($fitColor) ?>" style="vertical-align:middle; margin-right:4px;"></span><span class="fit-badge fit-<?= h($fitLbl) ?>"><?= h($fitLbl) ?></span>
  </h6>
  <p class="text-muted" style="font-size:0.85rem; margin-bottom:0.5rem;">
    <?= h($fitExpl) ?>
    Rijen in <span style="color:#c05621; font-weight:600;">oranje</span> wijken licht af (€0,01–€1);
    rijen in <span style="color:#c53030; font-weight:600;">rood</span> wijken meer dan €1 af.
  </p>

  <div class="table-responsive mb-3">
    <table class="table table-sm table-bordered align-middle">
      <thead>
        <tr>
          <th>Datum</th>
          <th class="text-end">Bekend saldo</th>
          <th class="text-end">Berekend saldo</th>
          <th class="text-end">Afwijking</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($result['checks'] as $i => $c):
            $abs = abs($c['delta']);
            $rowCls = $abs >= 1.0 ? 'table-danger' : ($abs >= 0.01 ? 'table-warning' : '');
            $deltaCls = $c['delta'] > 0 ? 'delta-bad' : ($c['delta'] < 0 ? 'delta-ok' : '');
            $sign = $c['delta'] >= 0 ? '+' : '';
        ?>
        <tr class="<?= $rowCls ?>">
          <td>
            <?= h(date('d-m-Y', strtotime($c['date']))) ?>
            <?php if ($i === 0): ?><span class="badge bg-secondary" style="font-size:0.65rem;">start</span><?php endif; ?>
            <?php if ($i === count($result['checks']) - 1 && $i > 0): ?><span class="badge bg-secondary" style="font-size:0.65rem;">einde</span><?php endif; ?>
          </td>
          <td class="text-end"><?= money_fmt($c['known']) ?></td>
          <td class="text-end"><?= money_fmt($c['predicted']) ?></td>
          <td class="text-end <?= $deltaCls ?>">
            <?= $sign . money_fmt($c['delta']) ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Methode toelichting -->
  <details class="mt-3">
    <summary class="mortgage-details-summary fw-semibold" style="font-size:0.9rem;">Hoe is de rente berekend? (technische toelichting)</summary>
    <div class="rc-uitleg-box mt-2" style="font-size:0.85rem;">
      <strong>Methode: bisectiemethode (wiskundige zoekprocedure)</strong>
      Het systeem zoekt naar de jaarrente waarbij het beginsaldo — dagelijks opgerent en verminderd met elke aflossing op het exacte moment — uitkomt op het opgegeven eindsaldo.
      Er zijn maximaal 200 iteraties uitgevoerd met een nauwkeurigheid van €0,001 (tiende cent).<br><br>
      <strong>Gebruikte formule:</strong><br>
      <code>B(t) = B₀ · (1+r)<sup>Δt</sup> − Σ Aᵢ · (1+r)<sup>Δtᵢ</sup> + Σ Kⱼ · (1+r)<sup>Δtⱼ</sup></code><br><br>
      Waarbij: <em>B(t)</em> = saldo op datum t &nbsp;|&nbsp; <em>B₀</em> = beginsaldo &nbsp;|&nbsp; <em>r</em> = effectieve jaarrente &nbsp;|&nbsp;
      <em>Δt</em> = tijdverschil in jaren (365 dagen/jaar) &nbsp;|&nbsp; <em>Aᵢ</em> = aflossingen &nbsp;|&nbsp; <em>Kⱼ</em> = extra kosten.
    </div>
  </details>

</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const initialData = {
  known:   <?= json_encode(array_map(fn($r) => ['date' => $r['date'], 'amount' => $r['amount']], $known    ?: [['date'=>'','amount'=>''],['date'=>'','amount'=>'']])) ?>,
  payment: <?= json_encode(array_map(fn($r) => ['date' => $r['date'], 'amount' => $r['amount']], $payments ?: [['date'=>'','amount'=>'']])) ?>,
  cost:    <?= json_encode(array_map(fn($r) => ['date' => $r['date'], 'amount' => $r['amount']], $costs    ?: [['date'=>'','amount'=>'']])) ?>,
};

function rowHtml(kind, d = '', a = '') {
  return `<div class="row g-2 mb-2 align-items-center input-row">
    <div class="col-md-4">
      <input class="form-control" type="date" name="${kind}_dates[]" value="${d}">
    </div>
    <div class="col-md-4">
      <div class="input-group">
        <span class="input-group-text">€</span>
        <input class="form-control" type="number" step="0.01" name="${kind}_amounts[]" value="${a}" placeholder="0,00">
      </div>
    </div>
    <div class="col-auto d-flex gap-1">
      <button type="button" class="btn btn-sm btn-outline-secondary rc-btn-duplicate" onclick="duplicateNextMonth(this, '${kind}')" title="Dupliceer naar volgende maand">+1m</button>
      <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeRow(this)" title="Verwijder rij">✕</button>
    </div>
  </div>`;
}

function addRow(id, kind, d = '', a = '') {
  document.getElementById(id).insertAdjacentHTML('beforeend', rowHtml(kind, d, a));
}

function removeRow(btn) {
  btn.closest('.input-row').remove();
}

function duplicateNextMonth(btn, kind) {
  const row         = btn.closest('.input-row');
  const dateInput   = row.querySelector('input[type="date"]');
  const amountInput = row.querySelector('input[type="number"]');
  const amount      = amountInput.value;
  let nextDate = '';
  if (dateInput.value) {
    const d   = new Date(dateInput.value);
    const day = d.getDate();
    d.setMonth(d.getMonth() + 1);
    // Clamp to last day of month if overflow (e.g. Jan 31 → Feb 28)
    if (d.getDate() !== day) d.setDate(0);
    nextDate = d.toISOString().slice(0, 10);
  }
  const newRow = document.createElement('div');
  newRow.innerHTML = rowHtml(kind, nextDate, amount);
  row.after(newRow.firstElementChild);
}

function resetForm() {
  ['knownRows', 'paymentRows', 'costRows'].forEach(id => document.getElementById(id).innerHTML = '');
  addRow('knownRows',   'known');
  addRow('knownRows',   'known');
  addRow('paymentRows', 'payment');
  addRow('costRows',    'cost');
}

['known', 'payment', 'cost'].forEach(kind => {
  const target = kind + 'Rows';
  const data = initialData[kind] && initialData[kind].some(r => r.date || r.amount)
    ? initialData[kind]
    : kind === 'known' ? [{date:'',amount:''},{date:'',amount:''}] : [{date:'',amount:''}];
  data.forEach(r => addRow(target, kind, r.date || '', r.amount || ''));
});

<?php if ($result): ?>
const checks = <?= json_encode($result['checks']) ?>;
new Chart(document.getElementById('renteChart'), {
  type: 'line',
  data: {
    labels: checks.map(c => {
      const d = new Date(c.date);
      return d.toLocaleDateString('nl-NL', {day:'2-digit', month:'short', year:'numeric'});
    }),
    datasets: [
      {
        label: 'Bekend saldo',
        data: checks.map(c => c.known),
        borderColor: '#3182ce',
        backgroundColor: 'rgba(49,130,206,0.08)',
        borderWidth: 2.5,
        tension: 0.3,
        pointRadius: 6,
        pointHoverRadius: 8,
        fill: true,
      },
      {
        label: 'Berekend saldo',
        data: checks.map(c => Math.round(c.predicted * 100) / 100),
        borderColor: '#38a169',
        borderDash: [7, 4],
        borderWidth: 2,
        tension: 0.3,
        pointRadius: 4,
        pointHoverRadius: 7,
        fill: false,
      },
    ]
  },
  options: {
    responsive: true,
    plugins: {
      legend: { position: 'top' },
      tooltip: {
        callbacks: {
          label: ctx => ctx.dataset.label + ': € ' + ctx.parsed.y.toLocaleString('nl-NL', {minimumFractionDigits:2, maximumFractionDigits:2})
        }
      }
    },
    scales: {
      y: {
        ticks: {
          callback: v => '€ ' + v.toLocaleString('nl-NL', {minimumFractionDigits:0})
        }
      }
    }
  }
});
<?php endif; ?>
</script>
<?php include __DIR__ . '/partials_footer.php'; ?>
