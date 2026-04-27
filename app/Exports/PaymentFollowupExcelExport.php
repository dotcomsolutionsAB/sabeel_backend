<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PaymentFollowupExcelExport implements FromArray, WithHeadings, ShouldAutoSize, WithStyles, WithEvents
{
    /** @var array<int, array<int, string>> */
    private array $rows;

    /** @var array<int, string> */
    private array $headings;

    /** @var array<string, string> */
    private array $alignments;

    /** @var array<int, int> */
    private array $highlightRows;

    /**
     * @param array<int, array<int, string>> $rows
     * @param array<int, string>             $headings
     * @param array<string, string>          $alignments
     * @param array<int, int>                $highlightRows 1-based Excel row numbers (incl heading row offset)
     */
    public function __construct(array $rows, array $headings, array $alignments = [], array $highlightRows = [])
    {
        $this->rows = $rows;
        $this->headings = $headings;
        $this->alignments = $alignments;
        $this->highlightRows = array_values(array_unique(array_map('intval', $highlightRows)));
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $lastCol = Coordinate::stringFromColumnIndex(count($this->headings));
                $lastRow = max(1, count($this->rows) + 1);

                $sheet->getStyle("A1:{$lastCol}{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

                foreach ($this->alignments as $col => $horizontal) {
                    $sheet->getStyle("{$col}:{$col}")->getAlignment()->setHorizontal($horizontal);
                }

                foreach ($this->highlightRows as $r) {
                    if ($r < 2 || $r > $lastRow) {
                        continue;
                    }
                    $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()->setRGB('FDE7E7');
                }
            },
        ];
    }
}
