<?php
// app/Imports/MsisdnImport.php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;

class MsisdnImport implements ToArray, WithHeadingRow, WithChunkReading
{
    protected array $msisdns = [];

    // ✅ Lit 500 lignes à la fois
    public function chunkSize(): int
    {
        return 500;
    }

    public function array(array $rows)
    {
        foreach ($rows as $row) {
            // Cherche la clé msisdn insensible à la casse
            $key = collect(array_keys($row))
                ->first(fn($k) => strtolower(trim($k)) === 'msisdn');

            if ($key && !empty($row[$key])) {
                $this->msisdns[] = trim((string) $row[$key]);
            }
        }
    }

    public function getMsisdns(): array
    {
        return array_unique($this->msisdns);
    }
}