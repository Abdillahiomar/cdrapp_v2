<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Module « Customers » : référentiel kyc.customer_master + activité calculée
 * (customer_last_activity / customer_monthly_activity) + seuils propres aux clients.
 *
 * Classement par rapport à la date de référence = dernière transaction connue
 * (et non aujourd'hui), pour ne pas être faussé par le décalage de l'ETL :
 *   active ≤ active_days · dormant ≤ dormant_days · inactive au-delà · never : aucune transaction
 * (historique des transactions depuis le 19/08/2024).
 */
class Customers
{
    public const ACTIVITY = [
        'active'   => ['Actif',   '#E5F5ED', '#005C2B'],
        'dormant'  => ['Dormant', '#FEF3C7', '#92400E'],
        'inactive' => ['Inactif', '#FDE8E8', '#7F1D1D'],
        'never'    => ['Jamais actif', '#F3F4F6', '#374151'],
    ];

    public const STATUS = [
        'Active'         => ['#E5F5ED', '#005C2B'],
        'Pending Active' => ['#E8ECF8', '#1B2F6E'],
        'New'            => ['#E8ECF8', '#1B2F6E'],
        'Suspended'      => ['#FEF3C7', '#92400E'],
        'Frozen'         => ['#FDE8E8', '#7F1D1D'],
        'Capped'         => ['#FEF3C7', '#92400E'],
        'Closed'         => ['#F3F4F6', '#374151'],
    ];

    public const DEFAULTS = ['active_days' => 30, 'dormant_days' => 90, 'pending_days' => 7, 'new_days' => 30, 'minor_age' => 18];

    /**
     * Contrôles : [libellé, explication]. Les critères sont dans applyCheck().
     */
    public const CHECKS = [
        'reactivate'   => ['À réactiver', 'Clients déjà actifs par le passé, sans transaction depuis plus du seuil « actif ».'],
        'pending'      => ['Pending Active', 'Inscrits mais jamais activés (statut Pending Active), depuis plus du seuil « Pending ».'],
        'never'        => ['Activés sans transaction', 'Clients activés qui n\'ont fait aucune transaction depuis le 19/08/2024.'],
        'blocked_txn'  => ['Suspendus / gelés actifs', 'Statut Suspended ou Frozen, mais avec une transaction sur la période « active » : anomalie conformité.'],
        'no_main'      => ['Compte principal non actif', 'Client au statut Active dont le compte principal est gelé, suspendu, fermé ou absent.'],
        'no_id'        => ['Sans pièce d\'identité', 'Aucun type de pièce d\'identité renseigné dans le référentiel.'],
        'minor'        => ['Mineurs', 'Âge inférieur à l\'âge minimum d\'après la date de naissance du référentiel.'],
        'removed'      => ['Retirés du référentiel', 'Clients absents du dernier fichier complet de l\'ETL.'],
    ];

    public static function setting(string $key): int
    {
        return (int) (DB::table('customer_settings')->where('key', $key)->value('value') ?? self::DEFAULTS[$key]);
    }

    public static function settings(): array
    {
        return collect(self::DEFAULTS)->mapWithKeys(fn ($v, $k) => [$k => self::setting($k)])->all();
    }

    public static function putSetting(string $key, int $value): void
    {
        DB::table('customer_settings')->updateOrInsert(['key' => $key], ['value' => (string) $value]);
        Cache::forget('customers_reference_date');
    }

    public static function referenceDate(): Carbon
    {
        $d = Cache::remember('customers_reference_date', 600, fn () => DB::table('customer_last_activity')->max('last_activity_date'));

        return $d ? Carbon::parse($d) : Carbon::yesterday();
    }

    /** Statut d'activité (alias de customer_last_activity : la) */
    public static function activitySql(): string
    {
        $s   = self::settings();
        $ref = self::referenceDate();
        $a   = $ref->copy()->subDays($s['active_days'])->toDateString();
        $d   = $ref->copy()->subDays($s['dormant_days'])->toDateString();

        return "CASE WHEN la.last_activity_date IS NULL THEN 'never'
                     WHEN la.last_activity_date >= DATE '{$a}' THEN 'active'
                     WHEN la.last_activity_date >= DATE '{$d}' THEN 'dormant'
                     ELSE 'inactive' END";
    }

    /** Statut du compte principal (alias de la jointure ma), « Absent » si le client n'en a pas */
    public static function mainAccountSql(): string
    {
        return "COALESCE(ma.status, 'Absent')";
    }

    /** Même statut, en sous-requête : rapide pour afficher une page de 50 clients, sans jointure */
    private static function mainAccountSelectSql(): string
    {
        return "COALESCE((SELECT COALESCE(cur.account_status, ca.account_status)
                 FROM kyc.customer_accounts ca
                 LEFT JOIN kyc.customer_account_current cur ON cur.unique_system_id = ca.unique_system_id AND cur.account_type = ca.account_type
                 WHERE ca.unique_system_id = c.unique_system_id AND ca.account_type = 'Customer Main Account'
                 ORDER BY ca.seq LIMIT 1), 'Absent')";
    }

    /**
     * Statut courant du compte principal de chaque client : dernier changement
     * connu (customer_account_current), sinon customer_accounts.
     */
    private static function mainAccounts(): Builder
    {
        return DB::table('kyc.customer_accounts as ca')
            ->leftJoin('kyc.customer_account_current as cur', function ($j) {
                $j->on('cur.unique_system_id', '=', 'ca.unique_system_id')->on('cur.account_type', '=', 'ca.account_type');
            })
            ->where('ca.account_type', 'Customer Main Account')
            ->selectRaw('DISTINCT ON (ca.unique_system_id) ca.unique_system_id, COALESCE(cur.account_status, ca.account_status) AS status')
            ->orderBy('ca.unique_system_id')
            ->orderBy('ca.seq');
    }

    /**
     * Clients (kyc.customer_master c) avec leur activité et le statut calculé.
     * $withMainAccount : ajoute le statut du compte principal (affichage, export).
     */
    public static function customers(bool $withMainAccount = false): Builder
    {
        $q = DB::table('kyc.customer_master as c')
            ->leftJoin('customer_last_activity as la', 'la.msisdn', '=', 'c.msisdn')
            ->select(
                'c.unique_system_id', 'c.msisdn', 'c.segment', 'c.products', 'c.identity_status', 'c.id_type', 'c.date_of_birth',
                'c.registered_at', 'c.activated_at', 'c.in_last_full', 'c.removed_detected_at',
                'la.first_activity_date', 'la.last_activity_date', 'la.last_txn_at',
                DB::raw(self::activitySql() . ' AS activity')
            );

        if ($withMainAccount) {
            $q->addSelect(DB::raw(self::mainAccountSelectSql() . ' AS main_account_status'));
        }

        return $q;
    }

    /**
     * Filtres de l'annuaire et de l'export :
     * search, segment, product, identity_status, activity, year, id_type, main_account, check.
     */
    public static function applyFilters(Builder $q, array $f): Builder
    {
        // Jointure ma (statut du compte principal de chaque client) seulement pour FILTRER
        // sur ce statut : une jointure unique est bien plus rapide qu'une sous-requête
        // par client sur 315 000 clients (7 s → ~1 s).
        if (!empty($f['main_account']) || ($f['check'] ?? '') === 'no_main') {
            $q->leftJoinSub(self::mainAccounts(), 'ma', 'ma.unique_system_id', '=', 'c.unique_system_id');
        }

        if (!empty($f['search'])) {
            $q->where('c.msisdn', 'like', preg_replace('/\D/', '', $f['search']) . '%');
        }
        foreach (['segment' => 'c.segment', 'product' => 'c.products', 'identity_status' => 'c.identity_status', 'id_type' => 'c.id_type'] as $key => $col) {
            if (!empty($f[$key])) {
                $f[$key] === '__none' ? $q->whereNull($col) : $q->where($col, $f[$key]);
            }
        }
        if (!empty($f['year'])) {
            $q->whereRaw('EXTRACT(YEAR FROM c.registered_at) = ?', [(int) $f['year']]);
        }
        if (!empty($f['activity'])) {
            $q->whereRaw(self::activitySql() . ' = ?', [$f['activity']]);
        }
        if (!empty($f['main_account'])) {
            $q->whereRaw(self::mainAccountSql() . ' = ?', [$f['main_account']]);
        }
        if (!empty($f['check'])) {
            self::applyCheck($q, $f['check']);
        }

        return $q;
    }

    /** Critères des contrôles (voir CHECKS). Appelée par applyFilters, qui ajoute la jointure ma pour « no_main ». */
    private static function applyCheck(Builder $q, string $check): Builder
    {
        $s   = self::settings();
        $ref = self::referenceDate();
        $act = self::activitySql();

        return match ($check) {
            'reactivate'  => $q->whereRaw("{$act} IN ('dormant', 'inactive')"),
            'pending'     => $q->where('c.identity_status', 'Pending Active')->where('c.registered_at', '<', $ref->copy()->subDays($s['pending_days'])->toDateString()),
            'never'       => $q->whereNotNull('c.activated_at')->whereNull('la.msisdn'),
            'blocked_txn' => $q->whereIn('c.identity_status', ['Suspended', 'Frozen'])->where('la.last_activity_date', '>=', $ref->copy()->subDays($s['active_days'])->toDateString()),
            'no_main'     => $q->where('c.identity_status', 'Active')->whereRaw(self::mainAccountSql() . " <> 'Active'"),
            'no_id'       => $q->where(fn ($w) => $w->whereNull('c.id_type')->orWhereRaw("TRIM(c.id_type) = ''")),
            'minor'       => $q->where('c.date_of_birth', '>', $ref->copy()->subYears($s['minor_age'])->toDateString()),
            'removed'     => $q->where(fn ($w) => $w->where('c.in_last_full', false)->orWhereNotNull('c.removed_detected_at')),
            default       => $q,
        };
    }

    /** Valeurs des filtres, en cache (tableaux simples : le cache ne restaure pas les objets) */
    public static function options(): array
    {
        return Cache::remember('customers_filter_options', 3600, fn () => [
            'segments' => DB::table('kyc.customer_master')->whereNotNull('segment')->distinct()->orderBy('segment')->pluck('segment')->all(),
            'products' => DB::table('kyc.customer_master')->whereNotNull('products')->distinct()->orderBy('products')->pluck('products')->all(),
            'statuses' => DB::table('kyc.customer_master')->distinct()->orderBy('identity_status')->pluck('identity_status')->all(),
            'id_types' => DB::table('kyc.customer_master')->whereNotNull('id_type')->distinct()->orderBy('id_type')->pluck('id_type')->all(),
            'years'    => DB::table('kyc.customer_master')->selectRaw('DISTINCT EXTRACT(YEAR FROM registered_at)::int AS y')->orderByDesc('y')->pluck('y')->all(),
        ]);
    }

    /**
     * Identité du client : dossier KYC récent (customer_kyc) en priorité, complété
     * par kyc_customers pour les clients dont le KYC n'a pas changé récemment.
     */
    public static function identity(string $msisdn): array
    {
        $recent = DB::table('kyc.customer_kyc')->where('msisdn', $msisdn)->orderByDesc('source_datetime')->first();
        $legacy = DB::table('kyc.kyc_customers')->where('msisdn', $msisdn)->first();

        $pick = fn (string $field) => ($recent && filled($recent->{$field} ?? null)) ? $recent->{$field} : ($legacy->{$field} ?? null);

        return [
            'source' => $recent ? 'customer_kyc (mise à jour du ' . Carbon::parse($recent->source_datetime)->format('d/m/Y') . ')' : ($legacy ? 'kyc_customers' : null),
            'fields' => array_filter([
                'Nom complet'      => $pick('full_name'),
                'Nom de la mère'   => $pick('mother_full_name'),
                'Sexe'             => $pick('sex'),
                'Date de naissance'=> ($dob = $pick('date_of_birth')) ? Carbon::parse($dob)->format('d/m/Y') : null,
                'Nationalité'      => $pick('nationality'),
                'Profession'       => $pick('profession'),
                'Pièce'            => trim(($pick('id_type') ?? '') . ' ' . ($pick('id_number') ?? '')) ?: null,
                'Profil KYC'       => $pick('customer_profile'),
                'Contact'          => trim(($pick('contact_person_name') ?? '') . ' ' . ($pick('contact_person_phone') ?? '')) ?: null,
            ]),
            'photos' => $recent ? ['Recto' => $recent->has_id_front, 'Verso' => $recent->has_id_back, 'Visage' => $recent->has_face]
                                : ($legacy ? ['Recto' => $legacy->has_id_front ?? null, 'Verso' => $legacy->has_id_back ?? null, 'Visage' => $legacy->has_face_picture ?? null] : []),
        ];
    }

    /**
     * Données du tableau de bord Customers (calcul lourd : ~25 s sur toute la base).
     * Préparées chaque nuit par customers:aggregate et gardées en cache 24 h.
     */
    public static function dashboardData(): array
    {
        $act = self::activitySql();
        $ref = self::referenceDate();
        $age = self::setting('minor_age');

        $base = DB::table('kyc.customer_master as c')->leftJoin('customer_last_activity as la', 'la.msisdn', '=', 'c.msisdn');

        $totals = (array) (clone $base)->selectRaw("COUNT(*) AS total,
            COUNT(*) FILTER (WHERE c.activated_at IS NOT NULL) AS activated,
            COUNT(*) FILTER (WHERE la.msisdn IS NOT NULL) AS transacted,
            COUNT(*) FILTER (WHERE {$act} = 'active') AS active,
            COUNT(*) FILTER (WHERE {$act} = 'dormant') AS dormant,
            COUNT(*) FILTER (WHERE {$act} = 'inactive') AS inactive,
            COUNT(*) FILTER (WHERE {$act} = 'never') AS never")->first();

        $segments = (clone $base)->groupBy('c.segment')->orderByRaw('COUNT(*) DESC')
            ->selectRaw("COALESCE(c.segment, '—') AS segment, COUNT(*) AS total,
                COUNT(*) FILTER (WHERE {$act} = 'active') AS active, COUNT(*) FILTER (WHERE {$act} = 'dormant') AS dormant,
                COUNT(*) FILTER (WHERE {$act} = 'inactive') AS inactive, COUNT(*) FILTER (WHERE {$act} = 'never') AS never")
            ->get()->map(fn ($r) => (array) $r)->all();

        $ages = DB::table('kyc.customer_master')->selectRaw("CASE
                WHEN date_of_birth IS NULL THEN 'Inconnu'
                WHEN date_of_birth > DATE '{$ref->copy()->subYears($age)->toDateString()}' THEN 'Moins de {$age} ans'
                WHEN date_of_birth > DATE '{$ref->copy()->subYears(26)->toDateString()}' THEN '{$age}–25 ans'
                WHEN date_of_birth > DATE '{$ref->copy()->subYears(36)->toDateString()}' THEN '26–35 ans'
                WHEN date_of_birth > DATE '{$ref->copy()->subYears(46)->toDateString()}' THEN '36–45 ans'
                WHEN date_of_birth > DATE '{$ref->copy()->subYears(61)->toDateString()}' THEN '46–60 ans'
                ELSE 'Plus de 60 ans' END AS tranche, COUNT(*) AS n")
            ->groupBy('tranche')->pluck('n', 'tranche')->all();

        $start = $ref->copy()->startOfMonth()->subMonths(23);

        $registrations = DB::table('kyc.customer_master')->where('registered_at', '>=', $start->toDateString())
            ->groupByRaw("to_char(registered_at, 'YYYY-MM')")->orderByRaw("to_char(registered_at, 'YYYY-MM')")
            ->selectRaw("to_char(registered_at, 'YYYY-MM') AS m, COUNT(*) AS n")->pluck('n', 'm');

        $monthlyActive = DB::table('customer_monthly_activity')->where('month', '>=', $start->toDateString())
            ->groupBy('month')->orderBy('month')->selectRaw("to_char(month, 'YYYY-MM') AS m, COUNT(*) AS n")->pluck('n', 'm');

        // Rétention par cohorte : part des inscrits d'un mois encore actifs 1, 3 et 6 mois plus tard
        $cohorts = DB::select("
            WITH c AS (
                SELECT msisdn, date_trunc('month', registered_at)::date AS cohort
                FROM kyc.customer_master
                WHERE registered_at >= ? AND registered_at < ?
            )
            SELECT to_char(c.cohort, 'YYYY-MM') AS cohort, COUNT(DISTINCT c.msisdn) AS size,
                   COUNT(DISTINCT a.msisdn) FILTER (WHERE a.month = c.cohort) AS m0,
                   COUNT(DISTINCT a.msisdn) FILTER (WHERE a.month = (c.cohort + INTERVAL '1 month')::date) AS m1,
                   COUNT(DISTINCT a.msisdn) FILTER (WHERE a.month = (c.cohort + INTERVAL '3 month')::date) AS m3,
                   COUNT(DISTINCT a.msisdn) FILTER (WHERE a.month = (c.cohort + INTERVAL '6 month')::date) AS m6
            FROM c LEFT JOIN customer_monthly_activity a ON a.msisdn = c.msisdn AND a.month IN (c.cohort, (c.cohort + INTERVAL '1 month')::date, (c.cohort + INTERVAL '3 month')::date, (c.cohort + INTERVAL '6 month')::date)
            GROUP BY c.cohort ORDER BY c.cohort", [$ref->copy()->startOfMonth()->subMonths(11)->toDateString(), $ref->copy()->addDay()->toDateString()]);

        $label = fn ($m) => \Carbon\Carbon::parse($m . '-01')->locale('fr')->translatedFormat('M Y');

        return [
            'computed_at'   => now()->format('d/m/Y H:i'),
            'reference'     => $ref->toDateString(),
            'totals'        => array_map('intval', $totals),
            'statuses'      => DB::table('kyc.customer_master')->groupBy('identity_status')->orderByRaw('COUNT(*) DESC')->selectRaw('identity_status, COUNT(*) AS n')->pluck('n', 'identity_status')->all(),
            'segments'      => $segments,
            'ages'          => $ages,
            'id_types'      => DB::table('kyc.customer_master')->groupBy('id_type')->orderByRaw('COUNT(*) DESC')->selectRaw("COALESCE(id_type, 'Non renseignée') AS t, COUNT(*) AS n")->pluck('n', 't')->all(),
            'cohorts'       => array_map(fn ($r) => (array) $r + ['label' => $label($r->cohort)], $cohorts),
            'charts'        => [
                'registrations' => ['labels' => $registrations->keys()->map($label)->values()->all(), 'values' => $registrations->values()->map(fn ($v) => (int) $v)->all()],
                'active'        => ['labels' => $monthlyActive->keys()->map($label)->values()->all(), 'values' => $monthlyActive->values()->map(fn ($v) => (int) $v)->all()],
            ],
        ];
    }

    public static function cachedDashboard(bool $refresh = false): array
    {
        if ($refresh) {
            Cache::forget('customers_dashboard');
        }

        return Cache::remember('customers_dashboard', 86400, fn () => self::dashboardData());
    }
}
