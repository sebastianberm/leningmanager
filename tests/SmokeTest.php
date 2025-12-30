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
}
