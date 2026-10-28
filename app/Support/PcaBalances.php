<?php

namespace App\Support;

use App\Models\PcaCashout;
use App\Models\PcaIncident;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use ZipArchive;

/**
 * PCA : derniers soldes connus de chaque compte.
 *
 * Chaque transaction de fact_txn_v2 porte le solde du compte après
 * l'opération (balance_after_debit / balance_after_credit). Le dernier solde
 * d'un compte est donc celui de sa dernière transaction aboutie. Vérifié sur
 * 85 000 enchaînements : solde après (n) = solde avant (n+1) dans 99,5 % des cas.
 *
 * Soldes stockés divisés par 100 dans fact_txn_v2 : convertis en FDJ ici.
 */
class PcaBalances
{
    private const AMOUNT_FACTOR = 100;

    /**
     * Met à jour pca_balances avec les transactions de [$from, $to[. Un compte
     * n'est modifié que si sa transaction est plus récente que le solde connu
     * (un fichier Huawei plus récent n'est donc jamais écrasé). Retourne le
     * nombre de comptes insérés ou mis à jour.
     */
    public static function refreshWindow(string $from, string $to): int
    {
        $f = self::AMOUNT_FACTOR;

        return DB::affectingStatement("
            INSERT INTO pca_balances (party_id, party_type, account_type, party_name, balance, as_of, last_transaction_id, source, updated_at)
            SELECT DISTINCT ON (party_id, party_type, account_type)
                   party_id, party_type, account_type, NULLIF(TRIM(party_name), ''), bal * {$f}, t, id, 'transactions', CAST(? AS timestamp)
            FROM (
                SELECT debit_party_identifier AS party_id, debit_party_type AS party_type,
                       COALESCE(debit_party_account_type, 'Inconnu') AS account_type, debit_party_name AS party_name,
                       balance_after_debit AS bal, transaction_initiated_time AS t, transaction_id AS id
                FROM fact_txn_v2
                WHERE transaction_initiated_time >= ? AND transaction_initiated_time < ?
                  AND status = 'Completed' AND debit_party_identifier IS NOT NULL
                  AND debit_party_type IN ('Customer', 'Organization') AND balance_after_debit IS NOT NULL

                UNION ALL

                SELECT credit_party_identifier, credit_party_type,
                       COALESCE(credit_party_account_type, 'Inconnu'), credit_party_name,
                       balance_after_credit, transaction_initiated_time, transaction_id
                FROM fact_txn_v2
                WHERE transaction_initiated_time >= ? AND transaction_initiated_time < ?
                  AND status = 'Completed' AND credit_party_identifier IS NOT NULL
                  AND credit_party_type IN ('Customer', 'Organization') AND balance_after_credit IS NOT NULL
            ) mv
            ORDER BY party_id, party_type, account_type, t DESC, id DESC
            ON CONFLICT (party_id, party_type, account_type) DO UPDATE SET
                party_name          = COALESCE(EXCLUDED.party_name, pca_balances.party_name),
                balance             = EXCLUDED.balance,
                as_of               = EXCLUDED.as_of,
                last_transaction_id = EXCLUDED.last_transaction_id,
                source              = EXCLUDED.source,
                updated_at          = EXCLUDED.updated_at
            WHERE pca_balances.as_of <= EXCLUDED.as_of
        ", [now()->format('Y-m-d H:i:s'), $from, $to, $from, $to]);
    }

    /**
     * Total des cash-out PCA valides par compte pour l'incident en cours.
     *
     * @return array<string, float>  clé = party_id|party_type|account_type
     */
    public static function paidDuringIncident(?PcaIncident $incident, string $partyId): array
    {
        if (!$incident) {
            return [];
        }

        return PcaCashout::valid()
            ->where('incident_id', $incident->id)
            ->where('party_id', $partyId)
            ->selectRaw('party_type, account_type, SUM(amount) AS total')
            ->groupBy('party_type', 'account_type')
            ->get()
            ->mapWithKeys(fn ($r) => [self::key($partyId, $r->party_type, $r->account_type) => (float) $r->total])
            ->all();
    }

    public static function key(string $partyId, string $partyType, string $accountType): string
    {
        return "{$partyId}|{$partyType}|{$accountType}";
    }

    /**
     * Identité KYC du titulaire, pour vérifier la pièce présentée au guichet.
     */
    public static function identity(string $partyId, string $partyType): ?array
    {
        if ($partyType === 'Organization') {
            // Le nom de l'organisation est dans organizations ; kyc_organizations.initiator_name
            // est la personne qui a saisi le KYC, pas l'organisation.
            // Repli : nom porté par les transactions (organizations peut être vide)
            $name = DB::table('organizations')->whereRaw('"SHORT_CODE"::text = ?', [$partyId])->value('BIZ_ORG_NAME')
                ?? DB::table('pca_balances')->where('party_id', $partyId)->whereNotNull('party_name')->value('party_name');
            $o    = DB::table('kyc.kyc_organizations')->whereRaw('short_code::text = ?', [$partyId])->first();

            if (!$name && !$o) {
                return null;
            }

            return array_filter([
                'Organisation'          => $name,
                'Représentant légal'    => $o->legal_representative ?? null,
                'Activité'              => $o->activity ?? null,
                'Profil'                => $o->org_profile ?? null,
                'Pièce'                 => trim(($o->id_type ?? '') . ' ' . ($o->id_number ?? '')) ?: null,
                'Contact'               => trim(($o->contact_person_name ?? '') . ' ' . ($o->contact_person_phone ?? '')) ?: null,
            ]);
        }

        // Un MSISDN peut avoir plusieurs fiches (client réinscrit sous un nouvel identifiant) :
        // la fiche du client actuel (customer_master) d'abord, puis la plus récente
        $c = DB::table('kyc.customer_kyc as k')
            ->where('k.msisdn', $partyId)
            ->orderByRaw('EXISTS (SELECT 1 FROM kyc.customer_master m WHERE m.unique_system_id = k.unique_system_id) DESC')
            ->orderByDesc('k.source_datetime')
            ->first();

        // Numéro sans fiche KYC client : il peut s'agir du short code d'une organisation
        if (!$c) {
            $o = DB::table('kyc.org_master')->whereRaw('short_code::text = ?', [$partyId])->first();

            return $o ? array_filter([
                'Organisation'     => $o->org_name,
                'Organisation mère'=> $o->parent_name,
                'Segment'          => $o->segment,
                'Produit'          => $o->products,
                'Statut'           => $o->identity_status,
                'Inscrite le'      => $o->registered_at ? Carbon::parse($o->registered_at)->format('d/m/Y') : null,
                'Source'           => 'Référentiel organisations (org_master)',
            ]) : null;
        }

        return array_filter([
            'Nom complet'      => $c->full_name ?? null,
            'Nom de la mère'   => $c->mother_full_name ?? null,
            'Date de naissance'=> $c->date_of_birth ? Carbon::parse($c->date_of_birth)->format('d/m/Y') : null,
            'Pièce'            => trim(($c->id_type ?? '') . ' ' . ($c->id_number ?? '')) ?: null,
            'Expiration pièce' => $c->id_expiry_date ? Carbon::parse($c->id_expiry_date)->format('d/m/Y') : null,
            'Profil'           => $c->customer_profile ?? null,
            'Nationalité'      => $c->nationality ?? null,
        ]);
    }

    /**
     * Fichier des soldes pour l'envoi par email : CSV (« ; », BOM UTF-8) dans un
     * ZIP chiffré AES-256 avec le mot de passe défini dans les réglages.
     *
     * @return array{path: string, rows: int}
     */
    public static function buildSnapshotZip(string $password, bool $nonZeroOnly = true): array
    {
        $dir = storage_path('app/private/pca');
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $stamp   = now()->format('Ymd_His');
        $csvPath = "{$dir}/soldes_pca_{$stamp}.csv";
        $zipPath = "{$dir}/soldes_pca_{$stamp}.zip";

        $out = fopen($csvPath, 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Compte', 'Titulaire', 'Type de compte', 'Nom', 'Pièce', 'Solde (FDJ)', 'Solde au', 'Source'], ';');

        $query = DB::table('pca_balances as b')
            // Une seule fiche KYC par compte, choisie comme dans identity()
            ->leftJoinLateral(
                DB::table('kyc.customer_kyc as k')
                    ->whereColumn('k.msisdn', 'b.party_id')
                    ->where('b.party_type', 'Customer')
                    ->orderByRaw('EXISTS (SELECT 1 FROM kyc.customer_master m WHERE m.unique_system_id = k.unique_system_id) DESC')
                    ->orderByDesc('k.source_datetime')
                    ->limit(1)
                    ->select('k.full_name', 'k.id_type', 'k.id_number'),
                'c'
            )
            // Numéro sans fiche KYC client : nom de l'organisation au même short code
            ->leftJoin('kyc.org_master as om', function ($j) {
                $j->on(DB::raw('om.short_code::text'), '=', 'b.party_id')->where('b.party_type', '=', 'Customer');
            })
            ->leftJoin('kyc.kyc_organizations as o', function ($j) {
                $j->on(DB::raw('o.short_code::text'), '=', 'b.party_id')->where('b.party_type', '=', 'Organization');
            })
            ->leftJoin('organizations as og', function ($j) {
                $j->on(DB::raw('og."SHORT_CODE"::text'), '=', 'b.party_id')->where('b.party_type', '=', 'Organization');
            })
            ->when($nonZeroOnly, fn ($q) => $q->where('b.balance', '<>', 0))
            ->select(
                'b.id', 'b.party_id', 'b.party_type', 'b.account_type', 'b.balance', 'b.as_of', 'b.source',
                DB::raw('COALESCE(c.full_name, om.org_name, og."BIZ_ORG_NAME", b.party_name) AS name'),
                DB::raw("TRIM(COALESCE(c.id_type, o.id_type, '') || ' ' || COALESCE(c.id_number, o.id_number, '')) AS id_doc")
            )
            ->orderBy('b.id');

        $rows = 0;
        foreach ($query->cursor() as $r) {
            fputcsv($out, [
                $r->party_id,
                $r->party_type === 'Organization' ? 'Organisation' : 'Client',
                $r->account_type,
                $r->name,
                $r->id_doc,
                number_format((float) $r->balance, 2, ',', ''),
                Carbon::parse($r->as_of)->format('d/m/Y H:i:s'),
                $r->source === 'huawei' ? 'Fichier Huawei' : 'Transactions',
            ], ';');
            $rows++;
        }
        fclose($out);

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Impossible de créer le fichier ZIP.');
        }
        $zip->addFile($csvPath, basename($csvPath));
        $zip->setEncryptionName(basename($csvPath), ZipArchive::EM_AES_256, $password);
        $zip->close();

        unlink($csvPath);

        return ['path' => $zipPath, 'rows' => $rows];
    }
}
