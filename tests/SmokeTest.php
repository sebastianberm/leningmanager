<?php
use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase {
    public function testTcpdfCanRenderToString() {
        if (!class_exists('TCPDF')) {
            $this->markTestSkipped('TCPDF not available');
            return;
        }
        $pdf = new TCPDF();
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Write(0, 'Smoke test PDF');
        $out = $pdf->Output('', 'S');
        $this->assertNotEmpty($out);
        $this->assertIsString($out);
    }

    public function testDeletePaymentButtonSubmitsSelectedPaymentId() {
        $loanPage = file_get_contents(__DIR__ . '/../public/loan.php');

        $this->assertStringContainsString("flag.name = 'delete_payment'", $loanPage);
        $this->assertStringContainsString(<<<'HTML'
flag.value = '<?=$a['id']?>'
HTML, $loanPage);
        $this->assertStringNotContainsString(<<<'HTML'
document.getElementById('updateFlag<?=$a['id']?>').name = 'delete_payment'; document.getElementById('editForm<?=$a['id']?>').submit();
HTML, $loanPage);
    }
}
