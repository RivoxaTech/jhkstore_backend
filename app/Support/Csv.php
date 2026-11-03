<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/** CSV downloads (customers, orders, wholesale requests, abandoned carts): UTF-8 with a byte order mark for Excel, cells safe against formulas. */
class Csv
{
    /**
     * @param  list<string>  $header
     * @param  iterable<list<mixed>>  $rows  each row is a list of cells (they are made safe here)
     */
    public static function download(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");   // UTF-8 byte order mark, so Excel reads names with accents correctly
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, array_map([self::class, 'safe'], $row));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** A spreadsheet runs a cell that starts with = + - @ as a formula: a customer-typed name must not do that. */
    public static function safe(mixed $value): mixed
    {
        if (! is_string($value) || $value === '' || preg_match('/^\+?[0-9][0-9 ()\-]*$/', $value)) {
            return $value;   // empty, or a plain phone number such as +49 170 1234567
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
