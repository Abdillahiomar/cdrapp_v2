<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vérification d'une liste de MSISDN (et de noms) contre les nouvelles tables
 * clients : référentiel kyc.customer_master, comptes, dossiers KYC et activité.
 *
 * 1. Vérification du MSISDN (référentiel à jour) :
 *      active  : dans le référentiel, statut Active et compte principal actif
 *      blocked : dans le référentiel mais statut ≠ Active ou compte principal non actif
 *      removed : absent du dernier fichier complet de l'ETL
 *      legacy  : absent du référentiel, connu seulement dans l'ancienne table kyc_customers
 *      unknown : inconnu partout
 * 2. Comparaison du nom (NameMatcher), uniquement si le client est connu. customer_master
 *    ne contient pas les noms : nom du KYC récent (customer_kyc), sinon kyc_customers.
 *
 * Toutes les recherches sont groupées (une requête par table, par lots de 1 000).
 */
class CustomerVerification
{
    public const RESULTS = [
        'active'  => ['Client actif',          '#E5F5ED', '#005C2B'],
        'blocked' => ['Client bloqué',         '#FEF3C7', '#92400E'],
        'removed' => ['Retiré du référentiel', '#FDE8E8', '#7F1D1D'],
        'legacy'  => ['Ancien client',         '#F3F4F6', '#374151'],
        'unknown' => ['Introuvable',           '#FDECEA', '#7F1D1D'],
    ];

    /**
     * @param array<int, array{line: int, msisdn: string, file_name: ?string}> $lines
     * @return array<int, array>
     */
    public static function verify(array $lines): array
    {
        $msisdns = collect($lines)->pluck('msisdn')->unique()->values();

        $master  = self::fetch('kyc.customer_master', 'msisdn', $msisdns);
        $recent  = self::fetch('kyc.customer_kyc', 'msisdn', $msisdns, 'source_datetime');
        $legacy  = self::fetch('kyc.kyc_customers', 'msisdn', $msisdns);
        $main    = self::mainAccounts($master->pluck('unique_system_id'));
        $hasActivity = Schema::hasTable('customer_last_activity');
        $activity = $hasActivity ? self::fetch('customer_last_activity', 'msisdn', $msisdns) : collect();

        $settings = $hasActivity ? Customers::settings() : null;
        $ref      = $hasActivity ? Customers::referenceDate() : null;

        return array_map(function ($line) use ($master, $recent, $legacy, $main, $activity, $settings, $ref, $hasActivity) {
            $m   = $master->get($line['msisdn']);
            $k   = $recent->get($line['msisdn']);
            $l   = $legacy->get($line['msisdn']);
            $a   = $activity->get($line['msisdn']);
            $acc = $m ? ($main[$m->unique_system_id] ?? 'Absent') : null;

            // 1. Vérification du MSISDN
            [$result, $reason] = match (true) {
                $m && (!$m->in_last_full || $m->removed_detected_at) => ['removed', 'Absent du dernier fichier complet'],
                $m && $m->identity_status !== 'Active'               => ['blocked', 'Statut client : ' . $m->identity_status],
                $m && $acc !== 'Active'                              => ['blocked', 'Compte principal : ' . $acc],
                (bool) $m                                            => ['active', null],
                (bool) $l                                            => ['legacy', 'Connu seulement dans kyc_customers'],
                default                                              => ['unknown', null],
            };

            // 2. Nom en base : KYC récent, sinon ancienne table
            $dbName     = filled($k->full_name ?? null) ? $k->full_name : ($l->full_name ?? null);
            $nameSource = filled($k->full_name ?? null) ? 'customer_kyc' : (filled($l->full_name ?? null) ? 'kyc_customers' : null);
            $match      = ($m || $l) ? NameMatcher::compare($line['file_name'], $dbName) : ['match' => null, 'score' => null];

            return [
                'line'             => $line['line'],
                'msisdn'           => $line['msisdn'],
                'file_name'        => $line['file_name'] ?: null,
                'result'           => $result,
                'reason'           => $reason,
                'db_name'          => $dbName,
                'name_source'      => $nameSource,
                'name_match'       => $match['match'],
                'name_score'       => $match['score'],
                'mother_name'      => filled($k->mother_full_name ?? null) ? $k->mother_full_name : ($l->mother_full_name ?? null),
                'identity_status'  => $m->identity_status ?? null,
                'main_account'     => $acc,
                'segment'          => $m->segment ?? ($l->customer_profile ?? null),
                'product'          => $m->products ?? null,
                'id_type'          => $m->id_type ?? ($k->id_type ?? ($l->id_type ?? null)),
                'registered_at'    => isset($m->registered_at) ? Carbon::parse($m->registered_at)->format('Y-m-d H:i') : null,
                'activated_at'     => isset($m->activated_at) ? Carbon::parse($m->activated_at)->format('Y-m-d H:i') : null,
                'last_txn_at'      => $a ? Carbon::parse($a->last_txn_at)->format('Y-m-d H:i') : null,
                'activity'         => $hasActivity && ($m || $l) ? self::activity($a, $settings, $ref) : null,
            ];
        }, $lines);
    }

    /** Lignes d'une table pour une liste de MSISDN, indexées par MSISDN (la plus récente si doublons). */
    private static function fetch(string $table, string $key, Collection $values, ?string $latest = null): Collection
    {
        // Tableau rempli clé par clé : un MSISDN est une clé numérique, que merge()
        // renumérote (les clés seraient perdues et tous les clients « introuvables »).
        $out = [];

        foreach ($values->chunk(1000) as $chunk) {
            $rows = DB::table($table)->whereIn($key, $chunk->values()->all())
                ->when($latest, fn ($q) => $q->orderBy($latest))
                ->get();

            foreach ($rows as $row) {
                $out[$row->{$key}] = $row;   // la dernière ligne gagne : la plus récente quand $latest est donné
            }
        }

        return collect($out);
    }

    /** Statut du compte principal : dernier changement connu, sinon customer_accounts. */
    private static function mainAccounts(Collection $ids): array
    {
        $out = [];

        foreach ($ids->filter()->unique()->chunk(1000) as $chunk) {
            $rows = DB::table('kyc.customer_accounts as ca')
                ->leftJoin('kyc.customer_account_current as cur', function ($j) {
                    $j->on('cur.unique_system_id', '=', 'ca.unique_system_id')->on('cur.account_type', '=', 'ca.account_type');
                })
                ->where('ca.account_type', 'Customer Main Account')
                ->whereIn('ca.unique_system_id', $chunk->all())
                ->orderBy('ca.seq')
                ->get(['ca.unique_system_id', DB::raw('COALESCE(cur.account_status, ca.account_status) AS status')]);

            foreach ($rows as $r) {
                $out[$r->unique_system_id] ??= $r->status;
            }
        }

        return $out;
    }

    private static function activity(?object $a, array $s, Carbon $ref): string
    {
        if (!$a) {
            return 'never';
        }

        $last = Carbon::parse($a->last_activity_date);

        return match (true) {
            $last->gte($ref->copy()->subDays($s['active_days']))  => 'active',
            $last->gte($ref->copy()->subDays($s['dormant_days'])) => 'dormant',
            default                                               => 'inactive',
        };
    }
}
