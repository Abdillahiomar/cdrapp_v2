<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Réseau de distribution : référentiel kyc.org_master + activité calculée
 * (org_daily_activity / org_last_activity) + seuils réglables.
 *
 * L'activité d'une organisation est classée par rapport à la date de
 * référence = dernier jour chargé (et non aujourd'hui), pour ne pas fausser
 * le classement quand l'ETL a un jour de décalage :
 *   - active   : dernière transaction il y a ≤ active_days jours
 *   - dormante : ≤ dormant_days jours
 *   - inactive : plus ancienne
 *   - jamais   : aucune transaction connue (historique depuis le 19/08/2024)
 */
class Network
{
    public const ACTIVITY = [
        'active'   => ['Active',   '#E5F5ED', '#005C2B'],
        'dormant'  => ['Dormante', '#FEF3C7', '#92400E'],
        'inactive' => ['Inactive', '#FDE8E8', '#7F1D1D'],
        'never'    => ['Jamais active', '#F3F4F6', '#374151'],
    ];

    public const IDENTITY_STATUS = [
        'Active'    => ['#E5F5ED', '#005C2B'],
        'New'       => ['#E8ECF8', '#1B2F6E'],
        'Suspended' => ['#FEF3C7', '#92400E'],
        'Frozen'    => ['#FDE8E8', '#7F1D1D'],
    ];

    public static function setting(string $key, int $default): int
    {
        return (int) (DB::table('network_settings')->where('key', $key)->value('value') ?? $default);
    }

    public static function putSetting(string $key, int $value): void
    {
        DB::table('network_settings')->updateOrInsert(['key' => $key], ['value' => (string) $value]);
        Cache::forget('network_reference_date');
    }

    /** @return array{active_days: int, dormant_days: int, new_org_days: int} */
    public static function thresholds(): array
    {
        return [
            'active_days'  => self::setting('active_days', 7),
            'dormant_days' => self::setting('dormant_days', 30),
            'new_org_days' => self::setting('new_org_days', 30),
        ];
    }

    /** Dernier jour d'activité chargé (référence du classement). */
    public static function referenceDate(): Carbon
    {
        $d = Cache::remember('network_reference_date', 600, fn () => DB::table('org_daily_activity')->max('activity_date'));

        return $d ? Carbon::parse($d) : Carbon::yesterday();
    }

    /** Expression SQL du statut d'activité (alias de org_last_activity : la). */
    public static function activitySql(): string
    {
        $t   = self::thresholds();
        $ref = self::referenceDate();

        $active  = $ref->copy()->subDays($t['active_days'])->toDateString();
        $dormant = $ref->copy()->subDays($t['dormant_days'])->toDateString();

        return "CASE
            WHEN la.last_activity_date IS NULL THEN 'never'
            WHEN la.last_activity_date >= DATE '{$active}' THEN 'active'
            WHEN la.last_activity_date >= DATE '{$dormant}' THEN 'dormant'
            ELSE 'inactive' END";
    }

    /**
     * Organisations (kyc.org_master m) avec leur dernière activité (la),
     * leur organisation de tête (t) et le statut d'activité calculé.
     */
    public static function organizations(): Builder
    {
        return DB::table('kyc.org_master as m')
            ->leftJoin('org_last_activity as la', 'la.org_id', '=', 'm.short_code')
            ->select(
                'm.unique_system_id', 'm.short_code', 'm.org_name', 'm.segment', 'm.products', 'm.charge_profile',
                'm.identity_status', 'm.hierarchy_level', 'm.is_top_org', 'm.parent_id', 'm.parent_name',
                'm.top_org_id', 'm.top_org_name', 'm.parent_missing', 'm.registered_at',
                'la.first_activity_date', 'la.last_activity_date', 'la.last_txn_at',
                DB::raw(self::activitySql() . ' AS activity')
            );
    }

    /**
     * Filtres de l'annuaire (aussi utilisés par l'export) :
     * search, segment, product, charge_profile, identity_status, activity, top_org, level, year.
     */
    public static function applyFilters(Builder $q, array $f): Builder
    {
        if (!empty($f['search'])) {
            $term = trim($f['search']);
            $q->where(fn ($w) => $w->where('m.org_name', 'ilike', "%{$term}%")->orWhere('m.short_code', 'like', "{$term}%"));
        }
        foreach (['segment' => 'm.segment', 'product' => 'm.products', 'charge_profile' => 'm.charge_profile', 'identity_status' => 'm.identity_status', 'top_org' => 'm.top_org_id'] as $key => $col) {
            if (!empty($f[$key])) {
                $q->where($col, $f[$key]);
            }
        }
        if (isset($f['level']) && $f['level'] !== '') {
            $q->where('m.hierarchy_level', (int) $f['level']);
        }
        if (!empty($f['year'])) {
            $q->whereRaw('EXTRACT(YEAR FROM m.registered_at) = ?', [(int) $f['year']]);
        }
        if (!empty($f['activity'])) {
            $q->whereRaw(self::activitySql() . ' = ?', [$f['activity']]);
        }

        return $q;
    }

    /** Listes de valeurs pour les filtres (mises en cache en tableaux simples). */
    public static function options(): array
    {
        return Cache::remember('network_filter_options', 3600, fn () => [
            'segments'        => DB::table('kyc.org_master')->whereNotNull('segment')->distinct()->orderBy('segment')->pluck('segment')->all(),
            'products'        => DB::table('kyc.org_master')->whereNotNull('products')->distinct()->orderBy('products')->pluck('products')->all(),
            'charge_profiles' => DB::table('kyc.org_master')->whereNotNull('charge_profile')->distinct()->orderBy('charge_profile')->pluck('charge_profile')->all(),
            'statuses'        => DB::table('kyc.org_master')->distinct()->orderBy('identity_status')->pluck('identity_status')->all(),
            'years'           => DB::table('kyc.org_master')->selectRaw('DISTINCT EXTRACT(YEAR FROM registered_at)::int AS y')->orderByDesc('y')->pluck('y')->all(),
            // Réseaux = organisations de tête qui ont au moins une caisse
            'networks'        => DB::table('kyc.org_master as t')
                ->where('t.is_top_org', true)
                ->whereExists(fn ($q) => $q->from('kyc.org_master as c')->whereColumn('c.top_org_id', 't.unique_system_id')->where('c.is_top_org', false))
                ->orderBy('t.org_name')
                ->get(['t.unique_system_id', 't.org_name', 't.short_code'])
                ->map(fn ($r) => (array) $r)->all(),
        ]);
    }

    /** Activité cumulée d'un ensemble d'organisations sur une période. */
    public static function activityTotals(string $from, string $to): Builder
    {
        return DB::table('org_daily_activity as a')
            ->whereBetween('a.activity_date', [$from, $to]);
    }
}
