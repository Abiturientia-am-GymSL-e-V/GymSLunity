<?php

declare(strict_types=1);

namespace App\Finance;

use App\Members\MemberReportWriter;
use App\Payments\GiroCode;
use Dompdf\Adapter\CPDF;
use RuntimeException;

final class FinanceInvoiceDocuments
{
    public function __construct(private readonly XRechnung $xrechnung, private readonly GiroCode $giroCode) {}

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{pdf: string, xrechnung: string}
     */
    public function create(array $snapshot, ?string $logo): array
    {
        $xrechnung = $this->xrechnung->create($snapshot);

        return [
            'pdf' => $this->pdf($snapshot, $logo, $xrechnung),
            'xrechnung' => $xrechnung,
        ];
    }

    /** @param array<string, mixed> $snapshot */
    private function pdf(array $snapshot, ?string $logo, string $xrechnung): string
    {
        $giroCode = null;
        if (($snapshot['document_type'] ?? 'invoice') === 'invoice' && $snapshot['payment_method'] === 'bank_transfer') {
            $giroCode = $this->giroCode->create($snapshot['total_cents'], $snapshot['seller']['account_holder'], $snapshot['seller']['iban'], $snapshot['seller']['bic'], $snapshot['invoice_number']);
        }
        $pdf = MemberReportWriter::dompdf(pdfA: true);
        $pdf->setPaper('A4');
        $pdf->loadHtml(view('finance.invoice', ['invoice' => $snapshot, 'logo' => $logo, 'giroCode' => $giroCode])->render());
        $pdf->render();

        $canvas = $pdf->getCanvas();
        if (! $canvas instanceof CPDF) {
            throw new RuntimeException('Die XRechnung konnte nicht in das PDF eingebettet werden.');
        }
        $temporaryFileHandle = tmpfile();
        if ($temporaryFileHandle === false) {
            throw new RuntimeException('Die temporäre XRechnung konnte nicht erstellt werden.');
        }
        try {
            $writtenBytes = fwrite($temporaryFileHandle, $xrechnung);
            $metadata = stream_get_meta_data($temporaryFileHandle);
            $temporaryFile = $metadata['uri'] ?? null;
            if ($writtenBytes !== strlen($xrechnung) || ! fflush($temporaryFileHandle) || ! is_string($temporaryFile)) {
                throw new RuntimeException('Die temporäre XRechnung konnte nicht erstellt werden.');
            }

            $cpdf = $canvas->get_cpdf();
            $cpdf->addEmbeddedFile(
                $temporaryFile,
                'xrechnung.xml',
                'Maschinenlesbare XRechnung',
                'application/xml',
                [$cpdf->catalogId => 'Alternative'],
            );

            return $pdf->output();
        } finally {
            fclose($temporaryFileHandle);
        }
    }
}
