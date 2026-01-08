<?php

namespace App\Exports;

use App\Exports\Traits\CommonExcelStyle;
use Maatwebsite\Excel\Concerns\{
    FromArray,
    WithHeadings,
    WithStyles,
    WithColumnFormatting
};
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class GenericExcelExport implements
    FromArray,
    WithHeadings,
    WithStyles
    // ,
    // WithColumnFormatting
{
    use CommonExcelStyle;

    protected array $rows;
    protected array $headings;
    // protected array $columnFormats;
    protected array $alignments;

    public function __construct(
        array $rows,
        array $headings,
        // array $columnFormats = [],
        array $alignments = []
    ) {
        $this->rows          = $rows;
        $this->headings      = array_values($headings); // 🔑 force reindex
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

    // public function styles(Worksheet $sheet)
    // {
    //     // ✅ Call trait method DIRECTLY
    //     $this->styles($sheet);

    //     // Column alignment
    //     foreach ($this->alignments as $col => $align) {
    //         $this->alignColumn(
    //             $sheet,
    //             "{$col}2:{$col}{$sheet->getHighestRow()}",
    //             $align
    //         );
    //     }
    // }

    // public function styles(Worksheet $sheet)
    // {
    //     // ✅ apply common header + borders
    //     $this->applyCommonStyles($sheet);

    //     // ✅ Column alignment
    //     $highestRow = $sheet->getHighestRow();
    //     foreach ($this->alignments as $col => $align) {
    //         $this->alignColumn($sheet, "{$col}2:{$col}{$highestRow}", $align);
    //     }

    //     // WithStyles expects an array return OR you can return []
    //     return [];
    // }
    public function styles(Worksheet $sheet)
    {
        $this->applyCommonStyles($sheet);

        $highestRow = $sheet->getHighestRow();

        // ✅ Alignment (same as you already do)
        foreach ($this->alignments as $col => $align) {
            $this->alignColumn($sheet, "{$col}2:{$col}{$highestRow}", $align);
        }

        // ✅ Number format ONLY for data rows (NOT header)
        // Example: if your amount/sabeel is in column G:
        $sheet->getStyle("G2:G{$highestRow}")
            ->getNumberFormat()
            ->setFormatCode('_₹* #,##0.00_ ;_₹* (#,##0.00);_₹* "-"??_ ;_@_ ');

        return [];
    }

    public function columnFormats(): array
    {
        return $this->columnFormats;
    }
}
