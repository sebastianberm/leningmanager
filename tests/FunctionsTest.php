<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/functions.php';

final class FunctionsTest extends TestCase {

    public function testAnnuityPaymentZeroRate() {
        $p = annuity_payment(1200, 0, 12);
        $this->assertEquals(100.0, $p);
    }

    public function testScheduleAnnuityPrincipalSum() {
        $principal = 1000;
        $sched = schedule($principal, 12, 10, 'annuity');
        $this->assertCount(10, $sched);
        $sumPrincipal = array_sum(array_column($sched, 'principal'));
        $this->assertEquals(round($principal,2), round($sumPrincipal,2));
    }

    public function testScheduleLinearPrincipalSum() {
        $principal = 1200;
        $sched = schedule($principal, 5, 12, 'linear');
        $this->assertCount(12, $sched);
        $sumPrincipal = array_sum(array_column($sched, 'principal'));
        $this->assertEquals(round($principal,2), round($sumPrincipal,2));
    }

    public function testTotalPaid() {
        $payments = [ ['amount'=>100], ['amount'=>250.5] ];
        $this->assertEquals(350.5, total_paid($payments));
    }

    public function testComputeAllocationWithPayments() {
        $loan = ['principal'=>1000, 'rate'=>12];
        $payments = [ 
            ['id'=>1, 'date'=>'2025-01-01','amount'=>100, 'note'=>'First'], 
            ['id'=>2, 'date'=>'2025-02-01','amount'=>100, 'note'=>'Second'] 
        ];
        $res = compute_allocation_with_payments($loan, $payments);
        
        $this->assertEquals(2, count($res['allocations']));
        
        // Month 1: Interest = 1000 * (0.12/12) = 10. Principal = 100 - 10 = 90. Remaining = 910.
        $this->assertEquals(10.0, $res['allocations'][0]['interest']);
        $this->assertEquals(90.0, $res['allocations'][0]['principal']);
        $this->assertEquals(910.0, $res['allocations'][0]['remaining']);
        
        // Month 2: Interest = 910 * (0.12/12) = 9.1. Principal = 100 - 9.1 = 90.9. Remaining = 910 - 90.9 = 819.1.
        $this->assertEquals(9.1, $res['allocations'][1]['interest']);
        $this->assertEquals(90.9, $res['allocations'][1]['principal']);
        $this->assertEquals(819.1, $res['allocations'][1]['remaining']);
        
        $this->assertEquals(819.1, $res['remaining']);
    }

    public function testComputeAllocationWithIncorrectOrder() {
        $loan = ['principal'=>1000, 'rate'=>12];
        // Payments in DESC order (newest first)
        $payments = [ 
            ['id'=>2, 'date'=>'2025-02-01','amount'=>100],
            ['id'=>1, 'date'=>'2025-01-01','amount'=>100]
        ];
        $res = compute_allocation_with_payments($loan, $payments);
        
        // If processed in this order:
        // Step 1 (Feb): Interest = 1000 * 0.01 = 10. Principal = 90. Remaining = 910.
        // Step 2 (Jan): Interest = 910 * 0.01 = 9.1. Principal = 90.9. Remaining = 819.1.
        // The math is "stable" but the logic is wrong because the JAN payment should have been applied to the 1000 principal.
        // If we had a payment in DEC, the JAN interest would be calculated on that remaining balance.
        
        // Real bug scenario: if Feb payment was 500.
        // ASC order:
        // Jan (100): Interest 10, Princ 90, Rem 910.
        // Feb (500): Interest 9.1, Princ 490.9, Rem 419.1.
        
        // DESC order:
        // Feb (500): Interest 10, Princ 490, Rem 510.
        // Jan (100): Interest 5.1, Princ 94.9, Rem 415.1.
        
        // Different results!
        $loan = ['principal'=>1000, 'rate'=>12];
        $paymentsAsc = [
            ['date'=>'2025-01-01','amount'=>100],
            ['date'=>'2025-02-01','amount'=>500]
        ];
        $paymentsDesc = [
            ['date'=>'2025-02-01','amount'=>500],
            ['date'=>'2025-01-01','amount'=>100]
        ];
        
        $resAsc = compute_allocation_with_payments($loan, $paymentsAsc);
        $resDesc = compute_allocation_with_payments($loan, $paymentsDesc);
        
        $this->assertNotEquals($resAsc['remaining'], $resDesc['remaining'], "Order should affect calculations");
        $this->assertEquals(419.1, $resAsc['remaining']);
    }

    public function testCalculateNewPayment() {
        $p = calculate_new_payment(500, 6, 10);
        $this->assertGreaterThan(0, $p);
    }

    public function testGenerateProjectionScheduleLength() {
        $sched = generate_projection_schedule(800, 4, 8, 'annuity');
        $this->assertCount(8, $sched);
    }


    public function testBuildComponentProjectionRateChangePersists() {
        $component = [
            'term_months' => 4,
            'type' => 'annuity',
            'principal' => 100000,
            'rate' => 2.0,
        ];
        $events = [
            2 => ['rate' => 6.0, 'extra_payment' => 0],
        ];

        $rows = build_component_projection($component, $events);
        $this->assertEquals(2.0, $rows[1]['rate']);
        $this->assertEquals(6.0, $rows[2]['rate']);
        $this->assertEquals(6.0, $rows[3]['rate']);
        $this->assertEquals(6.0, $rows[4]['rate']);
    }

    public function testBuildMortgageProjectionIncludesMonthZero() {
        $components = [[
            'id' => 1,
            'term_months' => 3,
            'type' => 'linear',
            'principal' => 1200,
            'rate' => 12,
        ]];
        $projection = build_mortgage_projection($components, [], 300000, [], 3);
        $this->assertEquals(0, $projection['rows'][0]['month']);
        $this->assertEquals(1200.0, $projection['rows'][0]['remaining']);
        $this->assertEquals(0.0, $projection['rows'][0]['payment']);
    }
}
