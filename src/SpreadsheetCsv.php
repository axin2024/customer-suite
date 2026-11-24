<?php

declare(strict_types=1);

namespace CustomerSuite;

final class SpreadsheetCsv
{
    public static function send(string $name, array $columns, iterable $rows): void
    {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $columns, ',', '"', '');
        foreach ($rows as $row) {
            $cells = array_map(static function (int|string|null $value): string {
                $value = (string) $value;
                return preg_match('/^\s*[=+\-@]/u', $value) ? "'" . $value : $value;
            }, array_values($row));
            fputcsv($out, $cells, ',', '"', '');
        }
        fclose($out);
    }
}
