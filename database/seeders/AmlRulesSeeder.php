<?php

namespace Database\Seeders;

use App\Models\AmlRule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Règles AML initiales (tableau AL1 → AL18 de la conformité).
 *
 * Idempotent : une règle déjà présente (même code) n'est jamais modifiée,
 * pour ne pas écraser les seuils ajustés ensuite depuis l'interface.
 *
 * Points en attente de confirmation par la conformité (saisis tels quels) :
 *   - AL5  : libellé "HEBDO" mais texte "par mois" → saisie en hebdo ;
 *   - AL12 / AL13 : "Transactions JOUR" → saisies en hebdo / mensuel ;
 *   - AL3 / AL14 : "ou" / "et" non précisé → OU pour AL3, ET pour AL14 ;
 *   - AL14 : population non précisée → désactivée en attendant ;
 *   - AL10 : liste des secteurs à risque à définir → désactivée en attendant.
 */
class AmlRulesSeeder extends Seeder
{
    public function run(): void
    {
        $idx = DB::table('transaction_types')->pluck('txn_index', 'txn_type_name');

        // Construit [{txn_index, direction}] à partir des libellés de transaction_types
        $flows = function (array $byDirection) use ($idx): array {
            $out = [];
            foreach ($byDirection as $direction => $names) {
                foreach ($names as $name) {
                    if (isset($idx[$name])) {
                        $out[] = ['txn_index' => (int) $idx[$name], 'direction' => $direction];
                    } else {
                        $this->command?->warn("Type de transaction introuvable : {$name}");
                    }
                }
            }
            return $out;
        };

        $cashIn      = ['Customer Cash In'];
        $cashOut     = ['Customer Cash Out'];
        $p2p         = ['Customer Send Money'];
        $merchant    = ['Merchant Payment', 'Online Merchant Payment'];
        $orgTransfer = ['Org Inter-Transfer'];
        $intl        = ['IRMAAN Transfer'];

        // RDS : cash in reçu, cash out effectué, transfert reçu
        $rdsInFlows = $flows(['in' => [...$cashIn, ...$p2p], 'out' => $cashOut]);
        $rdsOut     = $flows(['out' => $p2p]);
        $corporate  = $flows(['both' => [...$orgTransfer, ...$cashIn, 'Business Cash In', ...$cashOut, 'Business Cash Out', ...$merchant]]);

        $rules = [
            ['AL1',  'RDS_Seuil_Jour (Transfert IN, cash in, cash out)',   'rds',         'day',   $rdsInFlows, 5,    50000,    'or',  'gt',  'medium', true],
            ['AL2',  'RDS_Seuil_Hebdo (Transfert IN, cash in, cash out)',  'rds',         'week',  $rdsInFlows, 20,   100000,   'or',  'gt',  'medium', true],
            ['AL3',  'RDS_Seuil_Mois (Transfert IN, cash in, cash out)',   'rds',         'month', $rdsInFlows, 80,   3000000,  'or',  'gt',  'medium', true],
            ['AL4',  'RDS_Seuil_Jour (Transfert OUT)',                     'rds',         'day',   $rdsOut,     5,    100000,   'or',  'gt',  'medium', true],
            ['AL5',  'RDS_Seuil_Hebdo (Transfert OUT)',                    'rds',         'week',  $rdsOut,     20,   100000,   'or',  'gt',  'medium', true],
            ['AL6',  'P2POUT_Seuil_Mois',                                  'rds',         'month', $rdsOut,     80,   100000,   'or',  'gt',  'medium', true],
            ['AL7',  'RDS_transaction des PPE',                            'ppe',         'day',   [],          5,    100000,   'or',  'gt',  'high',   true],
            ['AL8',  'RDS_transaction des comptes dormants 3 mois',        'dormant',     'day',   [],          3,    50000,    'or',  'gt',  'high',   true],
            ['AL9',  'RDS_transaction black liste / liste grise',          'watchlist',   'day',   [],          1,    40000,    'or',  'gt',  'high',   true],
            ['AL10', 'Corporate_jour secteurs à risque',                   'risk_sector', 'day',   [],          10,   100000,   'or',  'gt',  'high',   false],
            ['AL11', 'Corporate_jour (transfert IN, cash in, cash out, paiement marchand)', 'corporate', 'day',   $corporate, 20,  500000,   'or', 'gt', 'medium', true],
            ['AL12', 'Corporate_hebdo (transfert IN, cash in, cash out, paiement marchand)', 'corporate', 'week',  $corporate, 100, 2000000,  'or', 'gt', 'medium', true],
            ['AL13', 'Corporate_mensuel (transfert IN, cash in, cash out, paiement marchand)', 'corporate', 'month', $corporate, 500, 10000000, 'or', 'gt', 'medium', true],
            ['AL14', 'Un compte actif 30 jours',                           'all',         'month', [],          20,   2000000,  'and', 'gt',  'medium', false],
            ['AL15', 'Corporate_jour (paiement marchand, transfert)',      'corporate',   'day',   $flows(['in' => $merchant, 'both' => $orgTransfer]), 1, 2000000, 'and', 'gte', 'high', true],
            ['AL16', 'RDS_transaction international',                      'rds',         'day',   $flows(['both' => $intl]), 1, 100000,  'and', 'gte', 'high', true],
            ['AL17', 'Corporate_transaction international',                'corporate',   'day',   $flows(['both' => $intl]), 1, 2000000, 'and', 'gte', 'high', true],
            ['AL18', 'RDS_transaction international (hebdo)',              'rds',         'week',  $flows(['both' => $intl]), 6, 500000,  'and', 'gte', 'high', true],
        ];

        $created = 0;

        foreach ($rules as [$code, $name, $population, $period, $ruleFlows, $count, $amount, $logic, $comparison, $severity, $enabled]) {
            $rule = AmlRule::firstOrCreate(['code' => $code], [
                'name'             => $name,
                'population'       => $population,
                'period'           => $period,
                'flows'            => $ruleFlows,
                'count_threshold'  => $count,
                'amount_threshold' => $amount,
                'logic'            => $logic,
                'comparison'       => $comparison,
                'severity'         => $severity,
                'enabled'          => $enabled,
            ]);

            $created += $rule->wasRecentlyCreated ? 1 : 0;
        }

        $this->command?->info("✓ Règles AML : {$created} créée(s), " . (count($rules) - $created) . ' déjà présente(s).');
    }
}
