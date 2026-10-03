<?php

namespace App\Support;

use App\Models\QrBatch;
use App\Models\QrCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use ZipArchive;

/**
 * Génération de QR codes en masse depuis un fichier Excel / CSV.
 *
 * Colonnes attendues (ligne d'en-tête obligatoire, ordre libre) :
 *   chaine | shortcode | nom_marchand
 *
 * Toutes les lignes sont vérifiées avant génération ; une seule ligne
 * invalide bloque le lot, pour ne jamais produire un lot incomplet dont il
 * manquerait des marchands sans qu'on s'en rende compte.
 */
class QrBatchGenerator
{
    public const MAX_ROWS = 500;

    /** Noms de colonnes acceptés (en minuscules, sans accents ni espaces) */
    private const COLUMNS = [
        'payload'       => ['chaine', 'contenu', 'qr', 'qrcode', 'payload'],
        'short_code'    => ['shortcode', 'short_code', 'code', 'code_marchand'],
        'merchant_name' => ['nom_marchand', 'nom', 'marchand', 'nom_du_marchand'],
    ];

    public function __construct(private QrImageGenerator $images)
    {
    }

    /**
     * Lit et vérifie le fichier.
     *
     * @return array{rows: array, error: ?string}
     */
    public function analyze(string $path): array
    {
        $sheets = Excel::toArray(new class implements ToArray {
            public function array(array $array): void
            {
            }
        }, $path);

        $lines = array_values(array_filter($sheets[0] ?? [], fn ($row) => array_filter($row, fn ($v) => trim((string) $v) !== '')));

        if (count($lines) < 2) {
            return ['rows' => [], 'error' => 'Le fichier est vide ou ne contient que la ligne d\'en-tête.'];
        }

        $map = $this->mapColumns(array_shift($lines));
        if (is_string($map)) {
            return ['rows' => [], 'error' => $map];
        }

        if (count($lines) > self::MAX_ROWS) {
            return ['rows' => [], 'error' => 'Le fichier contient ' . count($lines) . ' lignes : le maximum est ' . self::MAX_ROWS . ' par lot. Découpez-le en plusieurs fichiers.'];
        }

        $rows       = [];
        $shortCodes = [];
        $payloads   = [];

        foreach ($lines as $i => $line) {
            $row = [
                'line'          => $i + 2, // +1 en-tête, +1 numérotation à partir de 1
                'payload'       => $this->cell($line, $map['payload']),
                'short_code'    => $this->cell($line, $map['short_code']),
                'merchant_name' => $this->cell($line, $map['merchant_name']),
                'errors'        => [],
            ];

            $qr = EmvQrPayload::parse($row['payload']);
            $row['errors'] = $qr->errors;

            if ($row['short_code'] === '') {
                $row['errors'][] = 'Le shortcode est vide.';
            } elseif (isset($shortCodes[$row['short_code']])) {
                $row['errors'][] = "Shortcode en double (déjà présent ligne {$shortCodes[$row['short_code']]}).";
            } else {
                $shortCodes[$row['short_code']] = $row['line'];
            }

            if ($row['merchant_name'] === '') {
                $row['errors'][] = 'Le nom du marchand est vide.';
            }

            if ($row['payload'] !== '' && isset($payloads[$row['payload']])) {
                $row['errors'][] = "Même chaîne que la ligne {$payloads[$row['payload']]}.";
            } else {
                $payloads[$row['payload']] = $row['line'];
            }

            $rows[] = $row;
        }

        return ['rows' => $rows, 'error' => null];
    }

    /**
     * Crée le ZIP et l'historique. Les lignes doivent avoir été validées.
     */
    public function generate(array $rows, string $format, bool $withLogo, string $fileName, int $userId): QrBatch
    {
        if (collect($rows)->contains(fn ($r) => !empty($r['errors']))) {
            throw new RuntimeException('Le lot contient des lignes invalides.');
        }

        return DB::transaction(function () use ($rows, $format, $withLogo, $fileName, $userId) {
            $batch = QrBatch::create([
                'user_id'   => $userId,
                'file_name' => $fileName,
                'total'     => count($rows),
                'format'    => $format,
                'with_logo' => $withLogo,
            ]);

            $zipPath = 'qrcodes/' . $batch->id . '.zip';
            $disk    = Storage::disk('local');
            $disk->makeDirectory('qrcodes');

            $zip = new ZipArchive();
            if ($zip->open($disk->path($zipPath), ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Impossible de créer le fichier ZIP.');
            }

            $recap = ["shortcode;nom_marchand;fichier;chaine"];

            foreach ($rows as $row) {
                $code = QrCode::create([
                    'user_id'       => $userId,
                    'payload'       => $row['payload'],
                    'merchant_name' => $row['merchant_name'],
                    'short_code'    => $row['short_code'],
                    'with_logo'     => $withLogo,
                    'batch_id'      => $batch->id,
                ]);

                $base = $code->fileBaseName();

                if (in_array($format, ['png', 'both'])) {
                    $zip->addFromString(($format === 'both' ? 'png/' : '') . $base . '.png', $this->images->png($row['payload'], $withLogo, 'large'));
                }
                if (in_array($format, ['svg', 'both'])) {
                    $zip->addFromString(($format === 'both' ? 'svg/' : '') . $base . '.svg', $this->images->svg($row['payload'], $withLogo));
                }

                $recap[] = implode(';', [$row['short_code'], str_replace(';', ',', $row['merchant_name']), $base, $row['payload']]);
            }

            // BOM UTF-8 pour qu'Excel affiche correctement les accents
            $zip->addFromString('recapitulatif.csv', "\xEF\xBB\xBF" . implode("\r\n", $recap));
            $zip->close();

            $batch->update(['zip_path' => $zipPath]);

            return $batch;
        });
    }

    /**
     * @return array<string, int>|string  index de colonne par champ, ou message d'erreur
     */
    private function mapColumns(array $header): array|string
    {
        $normalized = array_map(fn ($h) => str_replace([' ', '-'], '_', strtolower(trim(\Illuminate\Support\Str::ascii((string) $h)))), $header);

        $map     = [];
        $missing = [];

        foreach (self::COLUMNS as $field => $aliases) {
            $index = collect($normalized)->search(fn ($h) => in_array($h, $aliases, true));
            if ($index === false) {
                $missing[] = $aliases[0];
            } else {
                $map[$field] = $index;
            }
        }

        if ($missing) {
            return 'Colonne(s) introuvable(s) dans la première ligne : ' . implode(', ', $missing)
                . '. La première ligne doit contenir les en-têtes « chaine », « shortcode » et « nom_marchand ». Utilisez le modèle à télécharger.';
        }

        return $map;
    }

    private function cell(array $line, int $index): string
    {
        $value = $line[$index] ?? '';

        // Une cellule numérique Excel arrive en float : on la remet en texte sans ".0"
        if (is_float($value)) {
            $value = floor($value) == $value && abs($value) < 1e15 ? (string) (int) $value : (string) $value;
        }

        return trim((string) $value);
    }
}
