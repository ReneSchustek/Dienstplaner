<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Assembly;
use App\Repository\AssignmentRepository;
use App\Repository\DayRepository;
use App\Repository\TaskRepository;
use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\TblWidth;

/**
 * Exports the monthly planning grid as PDF (DomPDF), Excel (PhpSpreadsheet) or Word (PhpWord).
 *
 * All formats follow the same layout: day-first, all departments per day,
 * each department rendered as a task-name row followed by a person row.
 */
class ExportService
{
    /** Mapping of internal special-label keys to German display strings. */
    private const LABEL_MAP = [
        'planning.label.memorial'     => 'Gedächtnismahl',
        'planning.label.congress'     => 'Kongress',
        'planning.label.service_week' => 'Dienstwoche',
        'planning.label.misc'         => 'Sonstiges',
    ];

    public function __construct(
        private readonly DayRepository $dayRepository,
        private readonly AssignmentRepository $assignmentRepository,
        private readonly TaskRepository $taskRepository,
    ) {}

    public function exportMonthlyPlanPdf(Assembly $assembly, int $year, int $month, string $htmlContent): string
    {
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($htmlContent);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    // -------------------------------------------------------------------------
    // Excel
    // -------------------------------------------------------------------------

    public function exportExcel(Assembly $assembly, int $year, int $month, array $grid, array $tasks): string
    {
        $deptGroups = $this->groupTasksByDept($tasks);
        $maxCols    = $this->maxTaskCount($deptGroups);
        $lastCol    = Coordinate::stringFromColumnIndex($maxCols + 1);

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle(mb_substr($assembly->getName(), 0, 31));

        $row = $this->writeExcelTitle($sheet, $assembly, $year, $month, $lastCol);

        foreach ($grid as $dayData) {
            $row = $this->writeExcelDayBlock($sheet, $row, $dayData, $deptGroups, $maxCols, $lastCol);
        }

        $this->applyExcelBorders($sheet, $lastCol, $row - 1);
        $this->setExcelColumnWidths($sheet, $maxCols);

        $writer   = new Xlsx($spreadsheet);
        $tempFile = sys_get_temp_dir() . '/export_' . uniqid() . '.xlsx';
        $writer->save($tempFile);

        return $tempFile;
    }

    private function writeExcelTitle(Worksheet $sheet, Assembly $assembly, int $year, int $month, string $lastCol): int
    {
        $title = $assembly->getName() . ' – ' . $this->formatMonthYear($month, $year);
        $sheet->setCellValue('A1', $title);
        $sheet->mergeCells('A1:' . $lastCol . '1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        return 2;
    }

    private function writeExcelDayBlock(Worksheet $sheet, int $row, array $dayData, array $deptGroups, int $maxCols, string $lastCol): int
    {
        $date = $dayData['day']->getDate()->format('d.m.Y');
        $kw   = 'KW ' . (int) $dayData['day']->getDate()->format('W');

        $sheet->setCellValue('A' . $row, $date . '   ' . $kw);
        $sheet->mergeCells('A' . $row . ':' . $lastCol . $row);
        $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(10)->getColor()->setRGB('ffffff');
        $sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2c3e50');
        $row++;

        if ($dayData['isBlocked']) {
            $label = $this->translateLabel($dayData['specialLabel'] ?? null);
            $sheet->setCellValue('A' . $row, $label);
            $sheet->mergeCells('A' . $row . ':' . $lastCol . $row);
            $sheet->getStyle('A' . $row)->getFont()->setItalic(true)->getColor()->setRGB('888888');
            return $row + 1;
        }

        foreach ($deptGroups as $deptData) {
            $row = $this->writeExcelDeptRows($sheet, $row, $deptData, $dayData['assignments'], $maxCols);
        }

        return $row;
    }

    private function writeExcelDeptRows(Worksheet $sheet, int $row, array $deptData, array $assignments, int $maxCols): int
    {
        $deptTasks  = $deptData['tasks'];
        $headerCol  = Coordinate::stringFromColumnIndex($maxCols + 1);

        // Task name row (grey background)
        $sheet->setCellValue('A' . $row, $deptData['dept']->getName());
        foreach ($deptTasks as $i => $task) {
            $col = Coordinate::stringFromColumnIndex($i + 2);
            $sheet->setCellValue($col . $row, $task->getName());
        }
        $sheet->getStyle('A' . $row . ':' . $headerCol . $row)
            ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('e8e8e8');
        $sheet->getStyle('A' . $row . ':' . $headerCol . $row)->getFont()->setBold(true)->setSize(8);
        $row++;

        // Person name row
        $sheet->setCellValue('A' . $row, '');
        foreach ($deptTasks as $i => $task) {
            $col  = Coordinate::stringFromColumnIndex($i + 2);
            $name = isset($assignments[$task->getId()])
                ? $assignments[$task->getId()]->getPerson()->getName()
                : '–';
            $sheet->setCellValue($col . $row, $name);
        }
        $sheet->getStyle('A' . $row . ':' . $headerCol . $row)->getFont()->setSize(9);
        $row++;

        return $row;
    }

    private function applyExcelBorders(Worksheet $sheet, string $lastCol, int $lastRow): void
    {
        $sheet->getStyle('A2:' . $lastCol . $lastRow)
            ->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN);
    }

    private function setExcelColumnWidths(Worksheet $sheet, int $maxCols): void
    {
        $sheet->getColumnDimension('A')->setWidth(22);

        for ($i = 2; $i <= $maxCols + 1; $i++) {
            $col = Coordinate::stringFromColumnIndex($i);
            $sheet->getColumnDimension($col)->setWidth(18);
        }
    }

    // -------------------------------------------------------------------------
    // Word
    // -------------------------------------------------------------------------

    public function generateWord(Assembly $assembly, int $year, int $month, array $grid, array $tasks, string $monthName = ''): string
    {
        $phpWord = new PhpWord();
        $phpWord->setDefaultFontName('Calibri');
        $phpWord->setDefaultFontSize(10);

        // 20mm margins: 1mm ≈ 56.7 twips
        $section = $phpWord->addSection([
            'marginTop'    => 1134,
            'marginBottom' => 1134,
            'marginLeft'   => 1134,
            'marginRight'  => 1134,
        ]);

        $deptGroups  = $this->groupTasksByDept($tasks);
        $maxCols     = $this->maxTaskCount($deptGroups);
        $displayName = $monthName !== '' ? $monthName : $this->formatMonthYear($month, $year);

        $section->addText(
            $assembly->getName() . ' – ' . $displayName,
            ['bold' => true, 'size' => 14],
            ['alignment' => 'center', 'spaceAfter' => 200]
        );

        $prevKw = null;
        foreach ($grid as $dayData) {
            $this->writeWordDayBlock($section, $dayData, $deptGroups, $maxCols, $prevKw);
            $prevKw = (int) $dayData['day']->getDate()->format('W');
        }

        $section->addText(
            'Stand: ' . (new \DateTimeImmutable())->format('d.m.Y'),
            ['size' => 8, 'color' => '888888'],
            ['spaceBefore' => 200]
        );

        $tempFile = sys_get_temp_dir() . '/export_' . uniqid() . '.docx';
        $writer   = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($tempFile);

        return $tempFile;
    }

    private function writeWordDayBlock(object $section, array $dayData, array $deptGroups, int $maxCols, ?int $prevKw): void
    {
        $date = $dayData['day']->getDate()->format('d.m.Y');
        $kw   = (int) $dayData['day']->getDate()->format('W');
        $kwLabel = ($kw !== $prevKw) ? '   KW ' . $kw : '';

        $section->addText(
            $date . $kwLabel,
            ['bold' => true, 'size' => 10],
            ['spaceBefore' => 120, 'spaceAfter' => 40]
        );

        if ($dayData['isBlocked']) {
            $label = $this->translateLabel($dayData['specialLabel'] ?? null);
            $section->addText(
                $label,
                ['italic' => true, 'size' => 9, 'color' => '888888'],
                ['spaceAfter' => 60]
            );
            return;
        }

        $this->writeWordDeptTable($section, $deptGroups, $dayData['assignments'], $maxCols);
    }

    private function writeWordDeptTable(object $section, array $deptGroups, array $assignments, int $maxCols): void
    {
        // Total usable width in twips (A4 minus 20mm margins each side)
        $totalWidth   = 9360;
        $deptColWidth = 1600;
        $taskColWidth = (int) (($totalWidth - $deptColWidth) / max(1, $maxCols));

        $table = $section->addTable([
            'width'       => $totalWidth,
            'unit'        => TblWidth::TWIP,
            'borderSize'  => 4,
            'borderColor' => 'cccccc',
            'cellMargin'  => 40,
        ]);

        foreach ($deptGroups as $deptData) {
            $this->writeWordDeptRows($table, $deptData, $assignments, $deptColWidth, $taskColWidth, $maxCols);
        }
    }

    private function writeWordDeptRows(object $table, array $deptData, array $assignments, int $deptColWidth, int $taskColWidth, int $maxCols): void
    {
        $deptTasks = $deptData['tasks'];
        $padCount  = $maxCols - count($deptTasks);
        $greyBg    = ['bgColor' => 'e8e8e8'];
        $taskFont  = ['bold' => true, 'size' => 8];
        $cellFont  = ['size' => 9];
        $center    = ['alignment' => 'center'];

        // Task name row
        $table->addRow();
        $table->addCell($deptColWidth, $greyBg)->addText($deptData['dept']->getName(), $taskFont);
        foreach ($deptTasks as $task) {
            $table->addCell($taskColWidth, $greyBg)->addText($task->getName(), $taskFont, $center);
        }
        for ($i = 0; $i < $padCount; $i++) {
            $table->addCell($taskColWidth, $greyBg)->addText('', $taskFont);
        }

        // Person name row
        $table->addRow();
        $table->addCell($deptColWidth)->addText('', $cellFont);
        foreach ($deptTasks as $task) {
            $name = isset($assignments[$task->getId()])
                ? $assignments[$task->getId()]->getPerson()->getName()
                : '–';
            $table->addCell($taskColWidth)->addText($name, $cellFont, $center);
        }
        for ($i = 0; $i < $padCount; $i++) {
            $table->addCell($taskColWidth)->addText('', $cellFont);
        }
    }

    // -------------------------------------------------------------------------
    // Shared helpers
    // -------------------------------------------------------------------------

    /** Groups tasks by their department, preserving task order. */
    private function groupTasksByDept(array $tasks): array
    {
        $groups = [];
        foreach ($tasks as $task) {
            $deptId = $task->getDepartment()->getId();
            if (!isset($groups[$deptId])) {
                $groups[$deptId] = ['dept' => $task->getDepartment(), 'tasks' => []];
            }
            $groups[$deptId]['tasks'][] = $task;
        }
        return $groups;
    }

    /** Returns the highest task count across all department groups. */
    private function maxTaskCount(array $deptGroups): int
    {
        $max = 1;
        foreach ($deptGroups as $deptData) {
            $count = count($deptData['tasks']);
            if ($count > $max) {
                $max = $count;
            }
        }
        return $max;
    }

    private function translateLabel(?string $key): string
    {
        if ($key === null) {
            return '';
        }
        return self::LABEL_MAP[$key] ?? $key;
    }

    private function formatMonthYear(int $month, int $year): string
    {
        $names = [
            1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April',
            5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember',
        ];
        return ($names[$month] ?? $month) . ' ' . $year;
    }
}
