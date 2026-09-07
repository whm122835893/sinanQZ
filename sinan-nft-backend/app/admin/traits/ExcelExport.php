<?php
declare(strict_types=1);

namespace app\admin\traits;

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

            // 表头
            $col = 'A';
            foreach ($headers as $header) {
                $sheet->setCellValue("{$col}1", $header);
                $col++;
            }
            $sheet->getStyle('A1:' . chr(ord('A') + count($headers) - 1) . '1')->getFont()->setBold(true);

            // 数据行
            $rowNum = 2;
            foreach ($rows as $row) {
                $col = 'A';
                foreach ($row as $cell) {
                    // 长数字（如订单号/手机号）强制文本，防止科学计数法
                    if (is_string($cell) && is_numeric($cell) && strlen($cell) > 11) {
                        $sheet->setCellValueExplicit("{$col}{$rowNum}", $cell, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                    } else {
                        $sheet->setCellValue("{$col}{$rowNum}", $cell);
                    }
                    $col++;
                }
                $rowNum++;
            }

            // 自动列宽（简单版）
            foreach (range('A', chr(ord('A') + count($headers) - 1)) as $c) {
                $sheet->getColumnDimension($c)->setWidth(16);
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
