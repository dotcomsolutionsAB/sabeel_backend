<?php

namespace App\Exports;

use App\Exports\Traits\CommonExcelStyle;
use Maatwebsite\Excel\Concerns\{
    FromArray,
    WithHeadings,
    WithStyles,
    WithColumnFormatting
};
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class GenericExcelExport implements
    FromArray,
    WithHeadings,
    WithStyles,
    WithColumnFormatting
{
    use CommonExcelStyle;

    protected array $rows;
    protected array $headings;
    protected array $columnFormats;
    protected array $alignments;

    public function __construct(
        array $rows,
        array $headings,
        array $columnFormats = [],
        array $alignments = []
    ) {
        $this->rows          = $rows;
        $this->headings      = $headings;
        $this->columnFormats = $columnFormats;
        $this->alignments    = $alignments;
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function styles(Worksheet $sheet)
    {
        // Apply common styles
        $this->applyCommonStyles($sheet);

        // Apply column alignments
        foreach ($this->alignments as $col => $align) {
            $this->alignColumn(
                $sheet,
                "{$col}2:{$col}{$sheet->getHighestRow()}",
                $align
            );
        }
    }

    public function columnFormats(): array
    {
        return $this->columnFormats;
    }
}