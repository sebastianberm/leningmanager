<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/csrf.php';
require_login();

$u = current_user();
$is_staff = in_array($u['role'], ['admin','manager'], true);

// migrations status
require_once __DIR__ . '/../includes/migrations.php';
$pending = pending_migrations($db);


// Haal alle leningen op basis van rol
if ($is_staff) {
    $loans = $db->query("SELECT l.*, b.name AS borrower_name FROM loans l LEFT JOIN users b ON b.id = l.borrower_id ORDER BY l.created_at DESC")->fetchAll();
} else {
    $stmt = $db->prepare("SELECT l.*, b.name AS borrower_name FROM loans l LEFT JOIN users b ON b.id = l.borrower_id WHERE borrower_id=? ORDER BY l.created_at DESC");
    $stmt->execute([$u['id']]);
    $loans = $stmt->fetchAll();
}

// Dashboard statistieken berekenen
$total_principal = 0;
$total_remaining = 0;
$total_paid = 0;
$total_interest_paid = 0;
$total_principal_paid = 0;
$active_loans = 0;
$paid_off_loans = 0;
$monthly_data = [];
$loan_details = [];

foreach ($loans as $loan) {
    $total_principal += (float)$loan['principal'];
    
    // Haal betalingen op
    $paymentsStmt = $db->prepare("SELECT * FROM payments WHERE loan_id=? ORDER BY date ASC, id ASC");
    $paymentsStmt->execute([$loan['id']]);
    $payments = $paymentsStmt->fetchAll();
    
    $alloc = compute_allocation_with_payments($loan, $payments);
    $remaining = $alloc['remaining'];
    $total_remaining += $remaining;
    
    foreach ($alloc['allocations'] as $a) {
        $total_paid += (float)$a['amount'];
        $total_interest_paid += (float)$a['interest'];
        $total_principal_paid += (float)$a['principal'];
        
        // Groepeer per maand voor grafiek
        $month = date('Y-m', strtotime($a['date']));
        if (!isset($monthly_data[$month])) {
            $monthly_data[$month] = ['amount' => 0, 'interest' => 0, 'principal' => 0];
        }
        $monthly_data[$month]['amount'] += (float)$a['amount'];
        $monthly_data[$month]['interest'] += (float)$a['interest'];
        $monthly_data[$month]['principal'] += (float)$a['principal'];
    }
    
    if ($remaining > 0) {
        $active_loans++;
    } else {
        $paid_off_loans++;
    }
    
    $loan_details[] = [
        'id' => $loan['id'],
        'name' => $loan['name'],
        'borrower' => $loan['borrower_name'] ?? '—',
        'principal' => (float)$loan['principal'],
        'remaining' => $remaining,
        'paid' => array_sum(array_column($alloc['allocations'], 'amount')),
        'progress' => $loan['principal'] > 0 ? (($loan['principal'] - $remaining) / $loan['principal']) * 100 : 0,
        'payments_count' => count($payments)
    ];
}

// Sorteer maandelijkse data
ksort($monthly_data);
$months = array_keys($monthly_data);
$monthly_amounts = array_column($monthly_data, 'amount');
$monthly_interest = array_column($monthly_data, 'interest');
$monthly_principal = array_column($monthly_data, 'principal');

// Sorteer leningen op restschuld
usort($loan_details, function($a, $b) {
    return $b['remaining'] <=> $a['remaining'];
});

// Bereken gemiddeldes
$avg_loan_size = count($loans) > 0 ? $total_principal / count($loans) : 0;
$completion_rate = $total_principal > 0 ? (($total_principal - $total_remaining) / $total_principal) * 100 : 0;

include __DIR__ . '/partials_header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h1 class="mb-4">📊 Dashboard</h1>
        <?php if ($is_staff && count($pending) > 0): ?>
            <div class="alert alert-warning">
                Er zijn <strong><?= count($pending) ?></strong> migratie(s) die uitgevoerd moeten worden: <em><?= h(implode(', ', $pending)) ?></em>.
                <form method="post" action="<?= BASE_PATH ?>/run_migrations.php" style="display:inline-block;margin-left:12px;">
                    <?php csrf_field(); ?>
                    <button class="btn btn-sm btn-primary">Voer migraties uit</button>
                </form>
            </div>
        <?php elseif ($is_staff):
            // show previous results if any
            if (session_status() === PHP_SESSION_NONE) session_start();
            if (!empty($_SESSION['migrations_results'])): ?>
                <div class="alert alert-info">
                    Migratie resultaten:
                    <ul>
                    <?php foreach($_SESSION['migrations_results'] as $k => $v): ?>
                        <li><?= h($k) ?>: <?= h($v) ?></li>
                    <?php endforeach; unset($_SESSION['migrations_results']); ?>
                    </ul>
                </div>
        <?php endif; endif; ?>
    </div>
</div>

<!-- KPI Cards -->
<div class="row mb-4">
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card p-4 text-center kpi-card">
            <div class="kpi-icon mb-3">💰</div>
            <h5 class="text-muted mb-2">Totaal Uitstaand</h5>
            <h2 class="mb-0"><?= money_fmt($total_remaining) ?></h2>
            <small class="text-muted">van <?= money_fmt($total_principal) ?></small>
        </div>
    </div>
    
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card p-4 text-center kpi-card">
            <div class="kpi-icon mb-3">📈</div>
            <h5 class="text-muted mb-2">Totaal Betaald</h5>
            <h2 class="mb-0"><?= money_fmt($total_paid) ?></h2>
            <small class="text-muted"><?= number_format($completion_rate, 1) ?>% afgelost</small>
        </div>
    </div>
    
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card p-4 text-center kpi-card">
            <div class="kpi-icon mb-3">🏦</div>
            <h5 class="text-muted mb-2">Actieve Leningen</h5>
            <h2 class="mb-0"><?= $active_loans ?></h2>
            <small class="text-muted"><?= $paid_off_loans ?> afgelost</small>
        </div>
    </div>
    
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card p-4 text-center kpi-card">
            <div class="kpi-icon mb-3">💵</div>
            <h5 class="text-muted mb-2">Rente Betaald</h5>
            <h2 class="mb-0"><?= money_fmt($total_interest_paid) ?></h2>
            <small class="text-muted">van totaal <?= money_fmt($total_paid) ?></small>
        </div>
    </div>
</div>

<!-- Charts Row -->
<div class="row mb-4">
    <div class="col-lg-8 mb-3">
        <div class="card p-4">
            <h5 class="mb-4">📊 Betalingen Overzicht</h5>
            <canvas id="paymentsChart" height="300"></canvas>
        </div>
    </div>
    
    <div class="col-lg-4 mb-3">
        <div class="card p-4">
            <h5 class="mb-4">🥧 Rente vs Aflossing</h5>
            <canvas id="pieChart" height="300"></canvas>
            <div class="mt-3">
                <div class="d-flex justify-content-between mb-2">
                    <span>💰 Aflossing:</span>
                    <strong><?= money_fmt($total_principal_paid) ?></strong>
                </div>
                <div class="d-flex justify-content-between">
                    <span>📈 Rente:</span>
                    <strong><?= money_fmt($total_interest_paid) ?></strong>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Portfolio Overview -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card p-4">
            <h5 class="mb-4">📋 Portfolio Overzicht</h5>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Lening</th>
                            <th>Lener</th>
                            <th>Hoofdsom</th>
                            <th>Restschuld</th>
                            <th>Betaald</th>
                            <th>Voortgang</th>
                            <th>Betalingen</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($loan_details as $detail): ?>
                        <tr>
                            <td><strong><?= h($detail['name']) ?></strong></td>
                            <td><?= h($detail['borrower']) ?></td>
                            <td><?= money_fmt($detail['principal']) ?></td>
                            <td><?= money_fmt($detail['remaining']) ?></td>
                            <td><?= money_fmt($detail['paid']) ?></td>
                            <td>
                                <div class="progress" style="height: 25px;">
                                    <div class="progress-bar bg-success" role="progressbar" 
                                         style="width: <?= number_format($detail['progress'], 1) ?>%"
                                         aria-valuenow="<?= $detail['progress'] ?>" 
                                         aria-valuemin="0" aria-valuemax="100">
                                        <?= number_format($detail['progress'], 1) ?>%
                                    </div>
                                </div>
                            </td>
                            <td><span class="badge bg-primary"><?= $detail['payments_count'] ?>x</span></td>
                            <td>
                                <a href="<?= BASE_PATH ?>/loan.php?id=<?= $detail['id'] ?>" 
                                   class="btn btn-sm btn-secondary">Details</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Recent Activity -->
<div class="row mb-4">
    <div class="col-lg-6 mb-3">
        <div class="card p-4">
            <h5 class="mb-4">🕐 Recente Betalingen</h5>
            <?php
            $recent = $db->query("
                SELECT p.*, l.name as loan_name 
                FROM payments p 
                JOIN loans l ON l.id = p.loan_id 
                ORDER BY p.created_at DESC 
                LIMIT 5
            ")->fetchAll();
            ?>
            <div class="list-group">
                <?php foreach($recent as $r): ?>
                <div class="list-group-item">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <strong><?= h($r['loan_name']) ?></strong><br>
                            <small class="text-muted"><?= date('d-m-Y', strtotime($r['date'])) ?></small>
                        </div>
                        <span class="badge bg-success" style="font-size: 1rem;">
                            <?= money_fmt($r['amount']) ?>
                        </span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <div class="col-lg-6 mb-3">
        <div class="card p-4">
            <h5 class="mb-4">📈 Statistieken</h5>
            <div class="stat-item">
                <div class="d-flex justify-content-between mb-3 p-3 bg-light rounded">
                    <span>Gemiddelde leninggrootte:</span>
                    <strong><?= money_fmt($avg_loan_size) ?></strong>
                </div>
                <div class="d-flex justify-content-between mb-3 p-3 bg-light rounded">
                    <span>Totaal aantal leningen:</span>
                    <strong><?= count($loans) ?></strong>
                </div>
                <div class="d-flex justify-content-between mb-3 p-3 bg-light rounded">
                    <span>Aflossingspercentage:</span>
                    <strong><?= number_format($completion_rate, 1) ?>%</strong>
                </div>
                <div class="d-flex justify-content-between mb-3 p-3 bg-light rounded">
                    <span>Rente/Betaling ratio:</span>
                    <strong><?= $total_paid > 0 ? number_format(($total_interest_paid / $total_paid) * 100, 1) : 0 ?>%</strong>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Betalingen overzicht grafiek
const ctxPayments = document.getElementById('paymentsChart').getContext('2d');
new Chart(ctxPayments, {
    type: 'bar',
    data: {
        labels: <?= json_encode($months) ?>,
        datasets: [
            {
                label: 'Aflossing',
                data: <?= json_encode($monthly_principal) ?>,
                backgroundColor: 'rgba(72, 187, 120, 0.8)',
                borderColor: 'rgba(72, 187, 120, 1)',
                borderWidth: 2
            },
            {
                label: 'Rente',
                data: <?= json_encode($monthly_interest) ?>,
                backgroundColor: 'rgba(237, 137, 54, 0.8)',
                borderColor: 'rgba(237, 137, 54, 1)',
                borderWidth: 2
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            x: { stacked: true, grid: { display: false } },
            y: { 
                stacked: true,
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return '€' + value.toLocaleString('nl-NL');
                    }
                }
            }
        },
        plugins: {
            legend: { position: 'bottom' },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return context.dataset.label + ': €' + 
                               context.parsed.y.toLocaleString('nl-NL', {minimumFractionDigits: 2});
                    }
                }
            }
        }
    }
});

// Pie chart voor rente vs aflossing
const ctxPie = document.getElementById('pieChart').getContext('2d');
new Chart(ctxPie, {
    type: 'doughnut',
    data: {
        labels: ['Aflossing', 'Rente'],
        datasets: [{
            data: [<?= $total_principal_paid ?>, <?= $total_interest_paid ?>],
            backgroundColor: [
                'rgba(72, 187, 120, 0.8)',
                'rgba(237, 137, 54, 0.8)'
            ],
            borderColor: [
                'rgba(72, 187, 120, 1)',
                'rgba(237, 137, 54, 1)'
            ],
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom' },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        const total = <?= $total_paid ?>;
                        const percentage = total > 0 ? ((context.parsed / total) * 100).toFixed(1) : 0;
                        return context.label + ': €' + 
                               context.parsed.toLocaleString('nl-NL', {minimumFractionDigits: 2}) +
                               ' (' + percentage + '%)';
                    }
                }
            }
        }
    }
});
</script>

<style>
.kpi-card {
    transition: all 0.3s ease;
    border-left: 4px solid transparent;
}

.kpi-card:hover {
    border-left-color: var(--accent);
    transform: translateY(-5px);
}

.kpi-icon {
    font-size: 3rem;
    opacity: 0.8;
}

.kpi-card h2 {
    font-size: 1.8rem;
    font-weight: 700;
    color: var(--accent);
}

.progress {
    border-radius: 1rem;
    overflow: hidden;
}

.progress-bar {
    font-weight: 600;
    font-size: 0.875rem;
}

.list-group-item {
    border: none;
    border-left: 4px solid var(--accent);
    margin-bottom: 0.5rem;
    border-radius: 0.5rem;
    transition: all 0.2s ease;
}

.list-group-item:hover {
    transform: translateX(5px);
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.stat-item .bg-light {
    transition: all 0.2s ease;
}

.stat-item .bg-light:hover {
    background-color: var(--accent) !important;
    color: white;
    transform: scale(1.02);
}
</style>

<?php include __DIR__ . '/partials_footer.php'; ?>