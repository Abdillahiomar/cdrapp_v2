<?php
// app/Imports/MsisdnImport.php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Lit les lignes du fichier de vérification : colonne MSISDN (obligatoire)
 * et nom complet (facultatif). Une entrée par ligne du fichier, doublons
 * compris : chaque ligne est vérifiée et comparée séparément.
 */
class MsisdnImport implements ToArray, WithHeadingRow, WithChunkReading
{
    /** En-têtes acceptés pour le nom (WithHeadingRow les met en minuscules, espaces → « _ ») */
    private const NAME_KEYS = ['full_name', 'fullname', 'nom_complet', 'nom'];

    protected array $rows = [];

    protected bool $hasNameColumn = false;

    /** Numéro de ligne Excel courant (1 = en-tête) */
    protected int $line = 1;

    // Lit 500 lignes à la fois
    public function chunkSize(): int
    {
        return 500;
    }

    public function array(array $rows)
    {
        foreach ($rows as $row) {
            $this->line++;

            $keys     = collect(array_keys($row))->mapWithKeys(fn ($k) => [strtolower(trim((string) $k)) => $k]);
            $msisdnK  = $keys->get('msisdn');
            $nameK    = collect(self::NAME_KEYS)->map(fn ($k) => $keys->get($k))->filter()->first();

            if ($nameK !== null) {
                $this->hasNameColumn = true;
            }

            $msisdn = $msisdnK !== null ? $this->cell($row[$msisdnK]) : '';
            if ($msisdn === '') {
                continue;
            }

            $this->rows[] = [
                'line'      => $this->line,
                'msisdn'    => $msisdn,
                'file_name' => $nameK !== null ? $this->cell($row[$nameK]) : null,
            ];
        }
    }

    /**
     * @return array<int, array{line: int, msisdn: string, file_name: ?string}>
     */
    public function getRows(): array
    {
        return $this->rows;
    }

    public function hasNameColumn(): bool
    {
        return $this->hasNameColumn;
    }

    private function cell($value): string
    {
        // Un MSISDN saisi comme nombre dans Excel arrive en float : on le remet en chiffres
        if (is_float($value)) {
            return sprintf('%.0f', $value);
        }

        return trim((string) $value);
    }
}
