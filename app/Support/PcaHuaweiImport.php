<?php

namespace App\Support;

use App\Models\PcaBalance;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Import des fichiers de soldes fournis manuellement par Huawei pendant un
 * incident : ils sont plus récents que les transactions chargées par l'ETL.
 *
 * Colonnes reconnues (ligne d'en-tête, ordre libre, casse et accents ignorés) :
 *   - compte (obligatoire)       : msisdn, compte, account, shortcode, identity…
 *   - solde (obligatoire)        : solde, balance, available_balance, montant
 *   - type de compte (optionnel) : type_compte, account_type ; à défaut, compte principal
 *
 * Un solde n'est appliqué que s'il est plus récent que celui déjà connu.
 */
class PcaHuaweiImport
{
    public const MAX_ROWS = 600000;

    private const COLUMNS = [
        'party_id'     => ['msisdn', 'compte', 'account', 'account_no', 'numero', 'shortcode', 'short_code', 'identity', 'identity_id', 'telephone'],
        'balance'      => ['solde', 'balance', 'available_balance', 'solde_disponible', 'montant', 'amount'],
        'account_type' => ['type_compte', 'type_de_compte', 'account_type', 'accounttype'],
    ];

    /**
     * @return array{rows: array, error: ?string, columns: array}
     */
    public static function analyze(string $path, bool $dividedBy100): array
    {
        $sheets = Excel::toArray(new class implements ToArray {
            public function array(array $array): void
            {
            }
        }, $path);

        $lines = array_values(array_filter($sheets[0] ?? [], fn ($r) => array_filter($r, fn ($v) => trim((string) $v) !== '')));

        if (count($lines) < 2) {
            return ['rows' => [], 'error' => 'Le fichier est vide ou ne contient que la ligne d\'en-tête.', 'columns' => []];
        }

        $header = array_map(fn ($h) => str_replace([' ', '-', '.'], '_', strtolower(trim(Str::ascii((string) $h)))), array_shift($lines));
        $map    = [];
        foreach (self::COLUMNS as $field => $aliases) {
            $idx = collect($header)->search(fn ($h) => in_array($h, $aliases, true));
            if ($idx !== false) {
                $map[$field] = $idx;
            }
        }

        if (!isset($map['party_id'], $map['balance'])) {
            return [
                'rows'    => [],
                'error'   => 'Colonnes obligatoires introuvables. Il faut une colonne « compte » (ou msisdn, shortcode…) et une colonne « solde » (ou balance). En-têtes trouvés : ' . implode(', ', array_filter($header)),
                'columns' => [],
            ];
        }

        if (count($lines) > self::MAX_ROWS) {
            return ['rows' => [], 'error' => 'Fichier trop volumineux (' . count($lines) . ' lignes, maximum ' . self::MAX_ROWS . ').', 'columns' => []];
        }

        $rows = [];
        foreach ($lines as $i => $line) {
            $partyId = self::text($line[$map['party_id']] ?? '');
            $raw     = $line[$map['balance']] ?? '';
            $balance = is_numeric($raw) ? (float) $raw : (is_numeric(str_replace([' ', ','], ['', '.'], (string) $raw)) ? (float) str_replace([' ', ','], ['', '.'], (string) $raw) : null);
            $type    = isset($map['account_type']) ? self::text($line[$map['account_type']] ?? '') : '';

            $error = null;
            if ($partyId === '') {
                $error = 'Compte vide';
            } elseif ($balance === null) {
                $error = 'Solde illisible (« ' . $raw . ' »)';
            }

            // Titulaire : un MSISDN client commence par 253 (11 chiffres), sinon c'est un shortcode d'organisation
            $partyType = preg_match('/^253\d{8}$/', $partyId) ? 'Customer' : 'Organization';

            $rows[] = [
                'line'         => $i + 2,
                'party_id'     => $partyId,
                'party_type'   => $partyType,
                'account_type' => $type,     // complété ci-dessous si vide
                'balance'      => $balance !== null ? round($dividedBy100 ? $balance * 100 : $balance, 2) : null,
                'error'        => $error,
            ];
        }

        // Certaines organisations ont un identifiant au format MSISDN : pour un compte déjà
        // connu, on reprend le type de titulaire enregistré plutôt que la déduction ci-dessus.
        foreach (array_chunk(array_keys($rows), 5000) as $keys) {
            $known = DB::table('pca_balances')
                ->whereIn('party_id', array_map(fn ($k) => $rows[$k]['party_id'], $keys))
                ->orderByRaw("CASE WHEN account_type IN ('Customer Main Account', 'Org Main Account') THEN 0 ELSE 1 END")
                ->get(['party_id', 'party_type'])
                ->unique('party_id')
                ->pluck('party_type', 'party_id');

            foreach ($keys as $k) {
                $rows[$k]['party_type'] = $known[$rows[$k]['party_id']] ?? $rows[$k]['party_type'];
            }
        }

        foreach ($rows as &$row) {
            if ($row['account_type'] === '') {
                $row['account_type'] = PcaBalance::MAIN_ACCOUNTS[$row['party_type']] ?? 'Inconnu';
            }
        }
        unset($row);

        return ['rows' => $rows, 'error' => null, 'columns' => array_keys($map)];
    }

    /**
     * Applique les lignes valides. Un solde existant plus récent que $asOf est conservé.
     *
     * @return array{applied: int, skipped: int}
     */
    public static function apply(array $rows, string $asOf): array
    {
        // Un compte présent deux fois : on garde la dernière ligne (PostgreSQL refuse de
        // mettre à jour deux fois la même ligne dans un même INSERT … ON CONFLICT)
        $valid = collect($rows)
            ->filter(fn ($r) => $r['error'] === null)
            ->keyBy(fn ($r) => PcaBalances::key($r['party_id'], $r['party_type'], $r['account_type']))
            ->values()
            ->all();
        $now     = now()->format('Y-m-d H:i:s');
        $applied = 0;

        foreach (array_chunk($valid, 1000) as $chunk) {
            $values   = [];
            $bindings = [];
            foreach ($chunk as $r) {
                $values[]   = '(?, ?, ?, ?, CAST(? AS timestamp), NULL, \'huawei\', CAST(? AS timestamp))';
                array_push($bindings, $r['party_id'], $r['party_type'], $r['account_type'], $r['balance'], $asOf, $now);
            }

            $applied += DB::affectingStatement('
                INSERT INTO pca_balances (party_id, party_type, account_type, balance, as_of, last_transaction_id, source, updated_at)
                VALUES ' . implode(', ', $values) . '
                ON CONFLICT (party_id, party_type, account_type) DO UPDATE SET
                    balance = EXCLUDED.balance, as_of = EXCLUDED.as_of, last_transaction_id = NULL,
                    source = EXCLUDED.source, updated_at = EXCLUDED.updated_at
                WHERE pca_balances.as_of <= EXCLUDED.as_of
            ', $bindings);
        }

        return ['applied' => $applied, 'skipped' => count($rows) - $applied];
    }

    private static function text($value): string
    {
        // Un MSISDN saisi comme nombre dans Excel arrive en float
        return is_float($value) ? sprintf('%.0f', $value) : trim((string) $value);
    }
}
