<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

class GenericExcelExport implements FromArray, WithHeadings, WithStyles, WithEvents, ShouldAutoSize
{
    protected array $rows;
    protected array $headings;
    protected array $alignments;

    public function __construct(array $rows, array $headings, array $alignments = [])
    {
        $this->rows = $rows;
        $this->headings = $headings;
        $this->alignments = $alignments;
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return $this->headings;
    }

    // ✅ Heading bold + centered
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
                $range      = "A1:{$highestCol}{$highestRow}";

                // ✅ Auto wrap text (like long name/address)
                $sheet->getStyle($range)->getAlignment()->setWrapText(true);

                // ✅ Borders
                $sheet->getStyle($range)->applyFromArray([
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                        ],
                    ],
                ]);

                // ✅ Apply column alignments (your passed map)
                foreach ($this->alignments as $col => $align) {
                    $sheet->getStyle("{$col}2:{$col}{$highestRow}")
                        ->getAlignment()
                        ->setHorizontal($align);
                }

                // ✅ Make last row (TOTAL) bold
                $sheet->getStyle("A{$highestRow}:{$highestCol}{$highestRow}")
                    ->getFont()
                    ->setBold(true);

                // ✅ Optional: freeze header row
                $sheet->freezePane('A2');
            }
        ];
    }
}
