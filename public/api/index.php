<?php
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/config.php';
header('Content-Type: application/json; charset=utf-8');

function require_api_auth() {
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/Bearer\s+(.+)/i', $hdr, $m)) {
        http_response_code(401); echo json_encode(['error'=>'Missing Bearer token']); exit;
    }
    $token = trim($m[1]);
    if (!hash_equals(API_TOKEN, $token)) {
        http_response_code(403); echo json_encode(['error'=>'Invalid token']); exit;
    }
}
require_api_auth();

$method = $_SERVER['REQUEST_METHOD'];
$path = $_GET['r'] ?? '';
$parts = array_values(array_filter(explode('/', $path), 'strlen'));

try {
    if ($method === 'GET' && count($parts)===1 && $parts[0]==='loans') {
        $rows = $db->query("SELECT id,name,principal,rate,start_date,term_months,type,borrower_id FROM loans ORDER BY id DESC")->fetchAll();
        echo json_encode($rows); exit;
    }
    if ($method === 'GET' && count($parts)===2 && $parts[0]==='loans') {
        $id = (int)$parts[1];
        $st = $db->prepare("SELECT * FROM loans WHERE id=?");
        $st->execute([$id]);
        $loan = $st->fetch();
        if (!$loan) { http_response_code(404); echo json_encode(['error'=>'Not found']); exit; }
        echo json_encode($loan); exit;
    }
    if ($method === 'GET' && count($parts)===3 && $parts[0]==='loans' && $parts[2]==='payments') {
        $id=(int)$parts[1];
        $st=$db->prepare("SELECT id FROM loans WHERE id=?");
        $st->execute([$id]);
        if (!$st->fetch()) { http_response_code(404); echo json_encode(['error'=>'Not found']); exit; }
        $st=$db->prepare("SELECT * FROM payments WHERE loan_id=? ORDER BY date ASC, id ASC");
        $st->execute([$id]);
        echo json_encode($st->fetchAll()); exit;
    }
    if ($method === 'GET' && count($parts)===3 && $parts[0]==='loans' && $parts[2]==='schedule') {
        $id=(int)$parts[1];
        $st=$db->prepare("SELECT * FROM loans WHERE id=?");
        $st->execute([$id]);
        $loan=$st->fetch();
        if (!$loan) { http_response_code(404); echo json_encode(['error'=>'Not found']); exit; }
        $paymentsStmt = $db->prepare("SELECT * FROM payments WHERE loan_id=? ORDER BY date ASC, id ASC");
        $paymentsStmt->execute([$loan['id']]);
        $payments = $paymentsStmt->fetchAll();
        $alloc = compute_allocation_with_payments($loan, $payments);
        $current_remaining = $alloc['remaining'];
        $months_left = max(0, $loan['term_months'] - count($payments));
        $projection = generate_projection_schedule($current_remaining, $loan['rate'], $months_left, $loan['type']);
        $full_schedule = array_merge($alloc['allocations'], $projection);
        echo json_encode($full_schedule); exit;
    }
    if ($method === 'POST' && count($parts)===3 && $parts[0]==='loans' && $parts[2]==='payments') {
        $id=(int)$parts[1];
        $st=$db->prepare("SELECT * FROM loans WHERE id=?");
        $st->execute([$id]);
        $loan=$st->fetch();
        if (!$loan) { http_response_code(404); echo json_encode(['error'=>'Not found']); exit; }
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $date = $body['date'] ?? null;
        $amount = isset($body['amount']) ? (float)$body['amount'] : 0.0;
        $note = $body['note'] ?? '';
        if (!$date || $amount<=0) { http_response_code(400); echo json_encode(['error'=>'date and amount required']); exit; }
        $ins=$db->prepare("INSERT INTO payments(loan_id,date,amount,note) VALUES(?,?,?,?)");
        $ins->execute([$id,$date,$amount,$note]);
        $ps=$db->prepare("SELECT * FROM payments WHERE loan_id=? ORDER BY date ASC, id ASC");
        $ps->execute([$id]);
        $payments=$ps->fetchAll();
        $alloc = compute_allocation_with_payments($loan, $payments);
        $last = end($alloc['allocations']);
        if (WEBHOOK_URL) {
            $payload = [
                'loan_id' => (int)$loan['id'],
                'loan_name' => $loan['name'],
                'dateadded' => $last['date'] ?? $date,
                'amount' => $last['amount'] ?? $amount,
                'calculated_interest' => $last['interest'] ?? null,
                'calculated_payment' => $last['principal'] ?? null,
                'amount_left' => $alloc['remaining'],
            ];
            curl_post_json(WEBHOOK_URL, $payload);
        }
        echo json_encode(['ok'=>true, 'payment'=>$last, 'remaining'=>$alloc['remaining']]); exit;
    }
    http_response_code(404);
    echo json_encode(['error'=>'Unknown route']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error'=>'Server error','detail'=>$e->getMessage()]);
}
