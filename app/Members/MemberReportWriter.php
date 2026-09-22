<?php

namespace App\Members;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;

final class MemberReportWriter
{
    public static function pdf(string $html, bool $landscape = false): string
    {
        $directory = storage_path('framework/cache/pdf');
        File::ensureDirectoryExists($directory, 0770);
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('tempDir', $directory);
        $options->set('fontCache', $directory);
        $options->set('chroot', $directory);
        $pdf = new Dompdf($options);
        $pdf->setPaper('A4', $landscape ? 'landscape' : 'portrait');
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();

        return $pdf->output();
    }

    /** @param list<string> $headers
     * @param  list<list<string>>  $rows
     */
    public static function excel(array $headers, array $rows): void
    {
        $book = new Spreadsheet;
        try {
            $sheet = $book->getActiveSheet()->setTitle('Mitglieder');
            foreach ([$headers, ...$rows] as $rowIndex => $row) {
                foreach ($row as $columnIndex => $value) {
                    // Explicit text preserves postcodes/identifiers and never evaluates formulas.
                    $sheet->setCellValueExplicit([$columnIndex + 1, $rowIndex + 1], $value, DataType::TYPE_STRING);
                }
            }
            $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
            $lastRow = count($rows) + 1;
            $sheet->getStyle('A1:'.$lastColumn.'1')->getFont()->setBold(true);
            $sheet->getStyle('A1:'.$lastColumn.$lastRow)->getAlignment()->setWrapText(true);
            $sheet->setAutoFilter('A1:'.$lastColumn.$lastRow);
            $sheet->freezePane('A2');
            foreach (array_keys($headers) as $column) {
                $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column + 1))->setWidth(24);
            }
            (new Xlsx($book))->save('php://output');
        } finally {
            $book->disconnectWorksheets();
        }
    }

    /** @param list<string> $headers
     * @param  list<list<string>>  $rows
     */
    public static function word(array $headers, array $rows, string $title): void
    {
        Settings::setOutputEscapingEnabled(true);
        $document = new PhpWord;
        $document->setDefaultFontName('Calibri');
        $document->setDefaultFontSize(9);
        foreach (array_chunk(array_keys($headers), 7) as $index => $columns) {
            if ($index > 0 && ! in_array(0, $columns, true)) {
                array_unshift($columns, 0);
            }
            $section = $document->addSection(['orientation' => 'landscape', 'marginLeft' => 600, 'marginRight' => 600, 'marginTop' => 600, 'marginBottom' => 600]);
            $section->addText($title, ['bold' => true, 'size' => 16]);
            $section->addText(count($rows).' Mitglieder · Stand '.now()->format('d.m.Y H:i T'));
            $table = $section->addTable(['borderSize' => 4, 'borderColor' => 'D1D5DB', 'cellMargin' => 80]);
            $table->addRow(null, ['tblHeader' => true]);
            foreach ($columns as $column) {
                $table->addCell((int) (15000 / count($columns)), ['bgColor' => 'F3F4F6'])->addText($headers[$column], ['bold' => true]);
            }
            foreach ($rows as $row) {
                $table->addRow();
                foreach ($columns as $column) {
                    $table->addCell((int) (15000 / count($columns)))->addText($row[$column]);
                }
            }
        }
        IOFactory::createWriter($document, 'Word2007')->save('php://output');
    }
}
