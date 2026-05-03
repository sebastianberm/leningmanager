<?php

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money_fmt($n) { return '€' . number_format((float)$n, 2, ',', '.'); }

function annuity_payment($principal, $rate_year, $term_months) {
    $r = ($rate_year/100.0)/12.0;
    if ($r == 0) return $principal / $term_months;
    return ($principal * $r) / (1 - pow(1 + $r, -$term_months));
}

function schedule($principal, $rate_year, $term_months, $type='annuity') {
    $rows = [];
    $r = ($rate_year/100.0)/12.0;
    $remaining = $principal;
    if ($type === 'annuity') {
        $fixed = annuity_payment($principal, $rate_year, $term_months);
    } else {
        $fixed_principal = $principal / $term_months;
    }
    for ($i=1; $i <= $term_months; $i++) {
        $interest = $remaining * $r;
        if ($type === 'annuity') {
            $principal_part = $fixed - $interest;
            if ($principal_part < 0) $principal_part = 0;
            $payment = $principal_part + $interest;
        } else {
            $principal_part = $fixed_principal;
            $payment = $principal_part + $interest;
        }
        $remaining -= $principal_part;
        if ($remaining < 0) $remaining = 0;
        $rows[] = [
            'month' => $i,
            'payment' => round($payment, 2),
            'interest' => round($interest, 2),
            'principal' => round($principal_part, 2),
            'remaining' => round($remaining, 2),
        ];
    }
    return $rows;
}

function total_paid($payments) {
    $sum = 0;
    foreach ($payments as $p) $sum += (float)$p['amount'];
    return $sum;
}

function compute_allocation_with_payments($loan, $payments) {
    $r = ($loan['rate']/100.0)/12.0;
    $remaining = (float)$loan['principal'];
    $alloc = [];
    foreach ($payments as $p) {
        $amount = (float)$p['amount'];
        $interest = $remaining * $r;
        $principal = $amount - $interest;
        if ($principal < 0) $principal = 0;
        $remaining -= $principal;
        if ($remaining < 0) $remaining = 0;
        $alloc[] = [
            'id' => isset($p['id']) ? (int)$p['id'] : null,
            'date' => $p['date'],
            'amount' => round($amount, 2),
            'interest' => round($interest, 2),
            'principal' => round($principal, 2),
            'remaining' => round($remaining, 2),
            'note' => $p['note'] ?? '',
        ];
    }
    return ['remaining'=>round($remaining,2), 'allocations'=>$alloc];
}

function calculate_new_payment($remaining, $rate_year, $months_left) {
    if ($months_left <= 0) return 0;
    return annuity_payment($remaining, $rate_year, $months_left);
}

function generate_projection_schedule($remaining, $rate_year, $months_left, $type='annuity') {
    $schedule = schedule($remaining, $rate_year, $months_left, $type);
    return $schedule;
}

function curl_post_json($url, $data) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}


function component_schedule($principal, $rate_year, $term_months, $type='annuity') {
    $rows = [];
    $r = ($rate_year/100.0)/12.0;
    $remaining = (float)$principal;
    $annuity = $type === 'annuity' ? annuity_payment($principal, $rate_year, $term_months) : 0;
    $linearPrincipal = $type === 'linear' ? ($principal / $term_months) : 0;

    for ($i=1; $i <= $term_months; $i++) {
        $interest = $remaining * $r;
        if ($type === 'annuity') {
            $principalPart = max(0, $annuity - $interest);
        } elseif ($type === 'linear') {
            $principalPart = $linearPrincipal;
        } else {
            $principalPart = ($i === $term_months) ? $remaining : 0;
        }
        $remaining -= $principalPart;
        if ($remaining < 0) $remaining = 0;
        $rows[] = ['month'=>$i,'remaining'=>round($remaining,2)];
    }
    return $rows;
}

function build_mortgage_ltv_overview(array $components, float $propertyValue, int $months): array {
    $schedules = [];
    foreach ($components as $c) {
        $schedules[] = component_schedule((float)$c['principal'], (float)$c['rate'], (int)$c['term_months'], $c['type']);
    }

    $overview = [];
    for ($m = 1; $m <= $months; $m++) {
        $remaining = 0.0;
        foreach ($schedules as $s) {
            $remaining += $s[$m-1]['remaining'] ?? 0.0;
        }
        $ltv = $propertyValue > 0 ? ($remaining / $propertyValue) * 100 : 0;
        $overview[] = ['month'=>$m, 'remaining'=>round($remaining,2), 'ltv'=>round($ltv,2)];
    }
    return $overview;
}

function build_component_projection(array $component, array $eventsByMonth): array {
    $term = (int)$component['term_months'];
    $type = $component['type'];
    $remaining = (float)$component['principal'];
    $baseRate = (float)$component['rate'];
    $currentRate = $baseRate;
    $rows = [];

    for ($month = 0; $month <= $term; $month++) {
        $event = $eventsByMonth[$month] ?? ['rate'=>null,'extra_payment'=>0.0];
        if ($event['rate'] !== null && $event['rate'] !== '') {
            $currentRate = (float)$event['rate'];
        }
        $rate = $currentRate;
        $extra = max(0.0, (float)($event['extra_payment'] ?? 0.0));

        if ($month === 0) {
            $rows[] = ['month'=>0,'rate'=>$rate,'regular_payment'=>0.0,'extra_payment'=>0.0,'remaining'=>round($remaining,2)];
            continue;
        }

        $monthsLeft = max(1, $term - $month + 1);
        $r = ($rate/100.0)/12.0;
        $interest = $remaining * $r;
        if ($type === 'annuity') {
            $regularPayment = annuity_payment($remaining, $rate, $monthsLeft);
            $principalPart = max(0.0, $regularPayment - $interest);
        } elseif ($type === 'linear') {
            $principalPart = $remaining / $monthsLeft;
            $regularPayment = $principalPart + $interest;
        } else {
            $principalPart = ($month === $term) ? $remaining : 0.0;
            $regularPayment = $interest + $principalPart;
        }

        $principalPart += $extra;
        if ($principalPart > $remaining) $principalPart = $remaining;
        $remaining -= $principalPart;

        $rows[] = [
            'month'=>$month,
            'rate'=>round($rate,4),
            'regular_payment'=>round($regularPayment,2),
            'extra_payment'=>round($extra,2),
            'remaining'=>round(max(0,$remaining),2),
        ];
    }
    return $rows;
}

function build_mortgage_projection(array $components, array $componentEvents, float $basePropertyValue, array $valueEvents, int $months): array {
    $componentRows = [];
    foreach ($components as $c) {
        $componentRows[(int)$c['id']] = build_component_projection($c, $componentEvents[(int)$c['id']] ?? []);
    }

    $rows = [];
    $propertyValue = $basePropertyValue;
    for ($month = 0; $month <= $months; $month++) {
        if (isset($valueEvents[$month])) $propertyValue = (float)$valueEvents[$month];
        $remaining = 0.0;
        $monthlyPayment = 0.0;
        foreach ($components as $c) {
            $cid = (int)$c['id'];
            $remaining += $componentRows[$cid][$month]['remaining'] ?? 0.0;
            if ($month > 0) {
                $row = $componentRows[$cid][$month] ?? null;
                if ($row) $monthlyPayment += $row['regular_payment'] + $row['extra_payment'];
            }
        }
        $rows[] = [
            'month'=>$month,
            'property_value'=>round($propertyValue,2),
            'remaining'=>round($remaining,2),
            'ltv'=> $propertyValue > 0 ? round(($remaining/$propertyValue)*100,2) : 0,
            'payment'=>round($monthlyPayment,2),
            'paid'=>false,
        ];
    }
    return ['rows'=>$rows, 'component_rows'=>$componentRows];
}
