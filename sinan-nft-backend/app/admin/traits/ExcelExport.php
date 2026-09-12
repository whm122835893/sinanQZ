<?php
declare(strict_types=1);

namespace app\admin\traits;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use think\Response;

/**
 * Excel 导出 Trait
 *
 * 使用方法：
 *   $this->excelExport('文件名.xlsx', [
 *       ['sheet' => '第一页', 'headers' => ['A列标题','B列标题'], 'rows' => [[...], [...]]],
 *       ['sheet' => '第二页', 'headers' => [...], 'rows' => [...]],
 *   ]);
 */
trait ExcelExport
{
    /**
     * @param string $filename 文件名（可不带 .xlsx 后缀）
     * @param array<array{sheet:string, headers:array, rows:array}> $sheets
     */
    protected function excelExport(string $filename, array $sheets): Response
    {
        if (!str_ends_with($filename, '.xlsx')) {
            $filename .= '.xlsx';
        }

        $spreadsheet = new Spreadsheet();
        $first = true;

        foreach ($sheets as $index => $sheetData) {
            $sheet = $first ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
            $first = false;

            $sheet->setTitle(mb_substr((string) ($sheetData['sheet'] ?? "Sheet{$index}"), 0, 31));
            $headers = $sheetData['headers'] ?? [];
            $rows = $sheetData['rows'] ?? [];
            $lastCol = Coordinate::stringFromColumnIndex(max(1, count($headers)));

            // 表头
            foreach (array_values($headers) as $i => $header) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . '1', $header);
            }
            $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);

            // 数据行
            $rowNum = 2;
            foreach ($rows as $row) {
                foreach (array_values($row) as $i => $cell) {
                    $cellRef = Coordinate::stringFromColumnIndex($i + 1) . $rowNum;
                    // 长数字（如订单号/手机号）强制文本，防止科学计数法
                    if (is_string($cell) && is_numeric($cell) && strlen($cell) > 11) {
                        $sheet->setCellValueExplicit($cellRef, $cell, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                    } else {
                        $sheet->setCellValue($cellRef, $cell);
                    }
                }
                $rowNum++;
            }

            // 自动列宽（简单版）
            for ($i = 1; $i <= count($headers); $i++) {
                $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setWidth(16);
            }
        }

        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $content = ob_get_clean();

        return response($content, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . rawurlencode($filename) . '"',
            'Cache-Control'       => 'max-age=0',
        ]);
    }
}
