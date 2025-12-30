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
        $payments = [ ['date'=>'2025-01-01','amount'=>100], ['date'=>'2025-02-01','amount'=>100] ];
        $res = compute_allocation_with_payments($loan, $payments);
        $this->assertArrayHasKey('remaining', $res);
        $this->assertArrayHasKey('allocations', $res);
        $this->assertCount(2, $res['allocations']);
        $this->assertGreaterThanOrEqual(0, $res['remaining']);
    }

    public function testCalculateNewPayment() {
        $p = calculate_new_payment(500, 6, 10);
        $this->assertGreaterThan(0, $p);
    }

    public function testGenerateProjectionScheduleLength() {
        $sched = generate_projection_schedule(800, 4, 8, 'annuity');
        $this->assertCount(8, $sched);
    }

}
