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

use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

// ✅ NEW (binder)
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

class GenericExcelExport extends DefaultValueBinder implements
    FromArray,
    WithHeadings,
    WithStyles,
    WithEvents,
    ShouldAutoSize,
    WithColumnFormatting,
    WithCustomValueBinder
{
    protected array $rows;
    protected array $headings;
    protected array $alignments;
    protected array $columnFormats;

    protected ?string $totalLabelColumn;

    public function __construct(
        array $rows,
        array $headings,
        array $alignments = [],
        ?string $totalLabelColumn = null,
        array $columnFormats = []
    ) {
        $this->rows = $rows;
        $this->headings = $headings;
        $this->alignments = $alignments;
        $this->totalLabelColumn = $totalLabelColumn;
        $this->columnFormats = $columnFormats;
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function columnFormats(): array
    {
        return $this->columnFormats;
    }

    // ✅ NEW: force TEXT columns to be written as STRING
    public function bindValue(Cell $cell, $value): bool
    {
        $col = $cell->getColumn(); // e.g. "L"

        // If this column is set as TEXT format, write it explicitly as STRING
        if (isset($this->columnFormats[$col]) && $this->columnFormats[$col] === NumberFormat::FORMAT_TEXT) {
            $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);
            return true;
        }

        return parent::bindValue($cell, $value);
    }

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

                $sheet->getStyle($range)->getAlignment()->setWrapText(true);

                $sheet->getStyle($range)->applyFromArray([
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                        ],
                    ],
                ]);

                foreach ($this->alignments as $col => $align) {
                    $sheet->getStyle("{$col}2:{$col}{$highestRow}")
                        ->getAlignment()
                        ->setHorizontal($align);
                }

                $sheet->freezePane('A2');

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