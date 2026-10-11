<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

/**
 * Base des exports qui contiennent des identifiants (MSISDN, Transaction ID) :
 * les chaînes sont écrites en texte telles quelles, seuls les int / float
 * restent numériques. Sans cela, Excel convertit 000419952199 en nombre
 * (zéros de tête perdus) ou un long MSISDN en notation scientifique.
 *
 * Le binder est statique dans PhpSpreadsheet : le job qui l'utilise doit le
 * réinitialiser après l'export (voir GenerateFraudExport).
 */
abstract class TextPreservingBinder extends DefaultValueBinder implements WithCustomValueBinder
{
    public function bindValue(Cell $cell, $value): bool
    {
        if (is_int($value) || is_float($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_NUMERIC);
        } else {
            $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);
        }

        return true;
    }
}
