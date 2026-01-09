<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class GenericExcelExport implements FromArray, WithHeadings, WithStyles, WithEvents, ShouldAutoSize
{
    protected array $rows;
    protected array $headings;
    protected array $alignments;

    // Optional: which column contains "TOTAL" label (e.g. 'F' for your dashboard exports)
    protected ?string $totalLabelColumn;

    public function __construct(array $rows, array $headings, array $alignments = [], ?string $totalLabelColumn = null)
    {
        $this->rows = $rows;
        $this->headings = $headings;
        $this->alignments = $alignments;
        $this->totalLabelColumn = $totalLabelColumn; // pass 'F' if you want conditional bold
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return $this->headings;
    }

    // Header: bold + centered
    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical'   => Alignment::VERTICAL_CENTER,
                ],
            ],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $highestRow = $sheet->getHighestRow();
                $highestCol = $sheet->getHighestColumn();
                $range = "A1:{$highestCol}{$highestRow}";

                // Wrap text to avoid cut-offs
                $sheet->getStyle($range)->getAlignment()->setWrapText(true);

                // Borders for all cells
                $sheet->getStyle($range)->applyFromArray([
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                        ],
                    ],
                ]);

                // Apply per-column alignments (data rows only)
                foreach ($this->alignments as $col => $align) {
                    $sheet->getStyle("{$col}2:{$col}{$highestRow}")
                        ->getAlignment()
                        ->setHorizontal($align);
                }

                // Freeze header row
                $sheet->freezePane('A2');

                // Optional: bold last row only if it is TOTAL
                if ($this->totalLabelColumn) {
                    $cellVal = (string) $sheet->getCell($this->totalLabelColumn . $highestRow)->getValue();
                    if (strtoupper(trim($cellVal)) === 'TOTAL') {
                        $sheet->getStyle("A{$highestRow}:{$highestCol}{$highestRow}")
                            ->getFont()->setBold(true);
                    }
                }
            },
        ];
    }
}
