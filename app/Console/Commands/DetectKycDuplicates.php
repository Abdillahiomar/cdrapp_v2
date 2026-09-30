<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Détecte les identités KYC associées à plusieurs comptes (msisdn).
 *
 * Deux niveaux de confiance :
 *   - Niveau 1 (confirmé)  : même (id_type, id_number) — pièce d'identité officielle.
 *   - Niveau 2 (probable)  : même (full_name, mother_full_name, date_of_birth),
 *                            uniquement pour les KYC sans id_number exploitable
 *                            (ex: RDS_LITE), pour ne pas compter deux fois les
 *                            identités déjà détectées au niveau 1.
 *
 * La table kyc_duplicate_identities est un instantané de l'état actuel de la
 * base clients : elle est entièrement reconstruite à chaque exécution (pas
 * d'historique jour par jour, contrairement aux tables de détection fraude).
 *
 * id_number NULL = doublon de niveau 2 (probable) ; id_number renseigné =
 * doublon de niveau 1 (confirmé). Pas de colonne "type" dédiée : l'un ou
 * l'autre est toujours vrai par construction des deux requêtes d'insertion.
 */
class DetectKycDuplicates extends Command
{
    protected $signature = 'kyc:detect-duplicates';

    protected $description = "Détecte les clients KYC associés à plusieurs comptes (msisdn)";

    /**
     * Un groupe au-delà de ce seuil n'est quasiment jamais un vrai doublon
     * exploitable — c'est un artefact de données (nom/date par défaut partagé
     * par erreur entre des milliers de fiches). On l'exclut à la source pour
     * éviter à la fois du bruit dans l'interface et des lignes gigantesques
     * (STRING_AGG de milliers de msisdn) qui peuvent faire exploser la mémoire
     * au rendu.
     */
    private const MAX_GROUP_SIZE = 100;

    public function handle(): int
    {
        $startedAt = microtime(true);
        $now = now();

        $this->info('==============================================');
        $this->info(' KYC — DÉTECTION DES IDENTITÉS DUPLIQUÉES');
        $this->info('==============================================');

        DB::transaction(function () use ($now) {
            DB::table('kyc_duplicate_identities')->truncate();

            // ── Niveau 1 : doublon confirmé par pièce d'identité ──
            DB::statement("
                INSERT INTO kyc_duplicate_identities (
                    id_type, id_number, full_name, mother_full_name, date_of_birth,
                    msisdn_count, msisdns, computed_at
                )
                SELECT
                    id_type,
                    TRIM(UPPER(id_number)) AS id_number,
                    MAX(full_name)         AS full_name,
                    MAX(mother_full_name)  AS mother_full_name,
                    MAX(date_of_birth)     AS date_of_birth,
                    COUNT(DISTINCT msisdn) AS msisdn_count,
                    STRING_AGG(DISTINCT msisdn, '/' ORDER BY msisdn) AS msisdns,
                    ? AS computed_at
                FROM kyc.kyc_customers
                WHERE id_number IS NOT NULL AND TRIM(id_number) <> ''
                  AND msisdn IS NOT NULL
                GROUP BY id_type, TRIM(UPPER(id_number))
                HAVING COUNT(DISTINCT msisdn) BETWEEN 2 AND ?
            ", [$now, self::MAX_GROUP_SIZE]);

            // ── Niveau 2 : doublon probable par nom + nom de la mère + naissance ──
            DB::statement("
                INSERT INTO kyc_duplicate_identities (
                    id_type, id_number, full_name, mother_full_name, date_of_birth,
                    msisdn_count, msisdns, computed_at
                )
                SELECT
                    NULL AS id_type,
                    NULL AS id_number,
                    TRIM(UPPER(full_name))        AS full_name,
                    TRIM(UPPER(mother_full_name)) AS mother_full_name,
                    date_of_birth,
                    COUNT(DISTINCT msisdn) AS msisdn_count,
                    STRING_AGG(DISTINCT msisdn, '/' ORDER BY msisdn) AS msisdns,
                    ? AS computed_at
                FROM kyc.kyc_customers
                WHERE (id_number IS NULL OR TRIM(id_number) = '')
                  AND full_name IS NOT NULL AND TRIM(full_name) <> ''
                  AND mother_full_name IS NOT NULL AND TRIM(mother_full_name) <> ''
                  AND date_of_birth IS NOT NULL
                  AND msisdn IS NOT NULL
                GROUP BY TRIM(UPPER(full_name)), TRIM(UPPER(mother_full_name)), date_of_birth
                HAVING COUNT(DISTINCT msisdn) BETWEEN 2 AND ?
            ", [$now, self::MAX_GROUP_SIZE]);
        });

        $total     = DB::table('kyc_duplicate_identities')->count();
        $confirmes = DB::table('kyc_duplicate_identities')->whereNotNull('id_number')->count();
        $probables = $total - $confirmes;

        $this->info("✓ {$total} identités dupliquées détectées ({$confirmes} confirmées, {$probables} probables)");
        $this->info('Durée : ' . round(microtime(true) - $startedAt, 2) . 's');

        return self::SUCCESS;
    }
}
