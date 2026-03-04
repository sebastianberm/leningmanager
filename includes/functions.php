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
            'id' => (int)$p['id'],
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
