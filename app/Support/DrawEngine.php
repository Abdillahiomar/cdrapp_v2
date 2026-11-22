<?php

namespace App\Support;

use App\Models\Draw;
use App\Models\DrawCeremony;
use App\Models\DrawPrize;
use App\Models\DrawWinner;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use RuntimeException;

/**
 * Tirages au sort Marketing.
 *
 * 1. computeEligibles() : clients (payeurs, type Customer) ayant des
 *    transactions terminées des types choisis (restreints, le cas échéant, à
 *    certains reasons : ex. paiement marchand via Main Acc et Sub Acc) sur la période, un client = une
 *    ligne (doublons supprimés), puis seuils et exclusions. La liste est figée
 *    dans draw_participants avec son empreinte SHA-256.
 * 2. drawOne() : tirage lot par lot, un gagnant par appel ; au dernier gagnant
 *    d'un lot, ses suppléants sont tirés dans la foulée.
 * 3. invalidate() / drawExtra() : gagnant écarté → premier suppléant promu, ou
 *    tirage complémentaire s'il n'en reste plus.
 *
 * Chaque sortie garde sa graine : verify() rejoue toutes les sorties dans leur
 * ordre (mêmes exclusions à l'instant du tirage) et doit retrouver les mêmes.
 *
 * Cérémonie : une liste (Draw) par catégorie. Un client sorti dans une liste
 * est exclu de toutes les autres listes de la cérémonie (un seul lot par
 * client) ; les sorties de la cérémonie sont sérialisées par un verrou sur
 * celle-ci, leur ordre (id) suffit donc à rejouer les exclusions.
 */
class DrawEngine
{
    private const AMOUNT_FACTOR = 100;   // fact_txn_v2 stocke les montants divisés par 100

    /** Liste figée par tirage, chargée une fois par requête */
    private array $participantsCache = [];

    /** 25377046542 → 2537****542 (affichage public) */
    public static function mask(string $msisdn): string
    {
        return strlen($msisdn) > 7
            ? substr($msisdn, 0, 4) . str_repeat('*', strlen($msisdn) - 7) . substr($msisdn, -3)
            : $msisdn;
    }

    /** 25377046542 → 77 ** ** 42 (salle de tirage : 2 premiers et 2 derniers chiffres) */
    public static function maskShort(string $msisdn): string
    {
        $local = str_starts_with($msisdn, '253') && strlen($msisdn) === 11 ? substr($msisdn, 3) : $msisdn;

        return strlen($local) > 4
            ? substr($local, 0, 2) . ' ' . trim(str_repeat('** ', intdiv(strlen($local) - 4 + 1, 2))) . ' ' . substr($local, -2)
            : $local;
    }

    /** 25377046542 → 77 04 65 42 */
    public static function display(string $msisdn): string
    {
        return str_starts_with($msisdn, '253') && strlen($msisdn) === 11
            ? trim(chunk_split(substr($msisdn, 3), 2, ' '))
            : $msisdn;
    }

    // ── 1. Liste des éligibles ─────────────────────────────────────────────

    public function computeEligibles(Draw $draw, ?int $userId = null): void
    {
        if (empty($draw->txn_indexes)) {
            throw new RuntimeException('Aucun type de transaction sélectionné.');
        }

        $start = $draw->period_start->format('Y-m-d') . ' 00:00:00';
        $end   = $draw->period_end->copy()->addDay()->format('Y-m-d') . ' 00:00:00';
        $f = self::AMOUNT_FACTOR;

        // Types sans reason retenu : toutes leurs transactions ; les autres : seulement les reasons retenus
        $byType   = $draw->reasonsByType();
        $free     = array_values(array_diff(array_map('intval', $draw->txn_indexes), array_keys($byType)));
        $reasons  = array_merge([], ...array_values($byType));
        $marks    = fn (array $v) => implode(',', array_fill(0, count($v), '?'));
        $typeSql  = implode(' OR ', array_filter([
            $free    ? "txn_index IN ({$marks($free)})" : null,
            $reasons ? "(txn_index IN ({$marks(array_keys($byType))}) AND reason_index IN ({$marks($reasons)}))" : null,
        ]));
        $typeArgs = array_merge($free, $reasons ? array_keys($byType) : [], $reasons);

        DB::transaction(function () use ($draw, $userId, $start, $end, $typeSql, $typeArgs, $f) {
            DB::table('draw_participants')->where('draw_id', $draw->id)->delete();

            // Un client = une ligne : le GROUP BY supprime les doublons
            DB::insert("
                INSERT INTO draw_participants (draw_id, msisdn, name, txn_count, amount, max_amount, last_txn_at)
                SELECT ?, debit_party_identifier, MAX(debit_party_name), COUNT(*),
                       COALESCE(SUM(actual_amount), 0) * {$f}, COALESCE(MAX(actual_amount), 0) * {$f},
                       MAX(transaction_initiated_time)
                  FROM fact_txn_v2
                 WHERE transaction_initiated_time >= ? AND transaction_initiated_time < ?
                   AND status = 'Completed'
                   AND debit_party_type = 'Customer'
                   AND debit_party_identifier IS NOT NULL
                   AND ({$typeSql})
                 GROUP BY debit_party_identifier
            ", array_merge([$draw->id, $start, $end], $typeArgs));

            $participants = fn () => DB::table('draw_participants')->where('draw_id', $draw->id);

            $funnel = [
                ['label' => 'Transactions trouvées', 'value' => (int) $participants()->sum('txn_count')],
                ['label' => 'Clients uniques (doublons supprimés)', 'value' => $participants()->count()],
            ];

            // Au moins N transactions, dont au moins une ≥ montant minimum
            $removed = $participants()
                ->where(fn ($q) => $q->where('txn_count', '<', max(1, (int) $draw->min_txn_count))
                    ->orWhere('max_amount', '<', (float) $draw->min_txn_amount))
                ->delete();
            if ($draw->min_txn_count > 1 || $draw->min_txn_amount > 0) {
                $funnel[] = ['label' => 'Sous le seuil (nombre ou montant)', 'value' => -$removed];
            }

            if ($draw->exclude_employees && Schema::hasTable('employees')) {
                $removed  = $participants()->whereIn('msisdn', DB::table('employees')->select('msisdn'))->delete();
                $funnel[] = ['label' => 'Employés D-Money exclus', 'value' => -$removed];
            }

            if ($draw->exclude_rds_lite) {
                $removed  = $participants()->whereIn('msisdn', DB::table('kyc.customer_master')->where('segment', 'RDS_LITE')->select('msisdn'))->delete();
                $funnel[] = ['label' => 'Clients RDS_LITE exclus', 'value' => -$removed];
            }

            if ($blacklist = $draw->blacklistMsisdns()) {
                $removed  = $participants()->whereIn('msisdn', $blacklist)->delete();
                $funnel[] = ['label' => 'Liste noire', 'value' => -$removed];
            }

            if ($draw->exclude_winners_days) {
                // Horloge de l'application : celle du serveur de base est décalée
                $since    = Carbon::now()->subDays($draw->exclude_winners_days);
                $winners  = DB::table('draw_winners as w')
                    ->where('w.draw_id', '<>', $draw->id)
                    ->where('w.created_at', '>=', $since)
                    ->where('w.status', 'valid')
                    ->where(fn ($q) => $q->whereIn('w.kind', ['winner', 'extra'])->orWhereNotNull('w.promoted_at'))
                    ->select('w.msisdn');
                $removed  = $participants()->whereIn('msisdn', $winners)->delete();
                $funnel[] = ['label' => "Gagnants des {$draw->exclude_winners_days} derniers jours", 'value' => -$removed];
            }

            // Nom KYC quand il existe (sinon celui de la transaction)
            DB::update("
                UPDATE draw_participants p
                   SET name = k.full_name
                  FROM (SELECT DISTINCT ON (msisdn) msisdn, full_name
                          FROM kyc.customer_kyc
                         WHERE msisdn IN (SELECT msisdn FROM draw_participants WHERE draw_id = ?)
                           AND NULLIF(TRIM(full_name), '') IS NOT NULL
                         ORDER BY msisdn, source_datetime DESC NULLS LAST) k
                 WHERE p.draw_id = ? AND p.msisdn = k.msisdn
            ", [$draw->id, $draw->id]);

            DB::table('draw_tickets')->where('draw_id', $draw->id)->delete();

            if ($draw->isTicketMode()) {
                // Une transaction = un ticket, pour les seuls clients restés éligibles
                DB::insert("
                    INSERT INTO draw_tickets (draw_id, msisdn, transaction_id, amount, txn_at)
                    SELECT ?, f.debit_party_identifier, f.transaction_id, COALESCE(f.actual_amount, 0) * {$f}, f.transaction_initiated_time
                      FROM fact_txn_v2 f
                     WHERE f.transaction_initiated_time >= ? AND f.transaction_initiated_time < ?
                       AND f.status = 'Completed'
                       AND f.debit_party_type = 'Customer'
                       AND f.transaction_id IS NOT NULL
                       AND ({$typeSql})
                       AND f.debit_party_identifier IN (SELECT msisdn FROM draw_participants WHERE draw_id = ?)
                    ON CONFLICT (draw_id, transaction_id) DO NOTHING
                ", array_merge([$draw->id, $start, $end], $typeArgs, [$draw->id]));

                // Chances = tickets figés (aligne les compteurs si des transactions sont arrivées entre-temps)
                DB::update("
                    UPDATE draw_participants p SET txn_count = t.n
                      FROM (SELECT msisdn, COUNT(*) AS n FROM draw_tickets WHERE draw_id = ? GROUP BY msisdn) t
                     WHERE p.draw_id = ? AND p.msisdn = t.msisdn AND p.txn_count <> t.n
                ", [$draw->id, $draw->id]);
                $participants()->whereNotIn('msisdn', DB::table('draw_tickets')->where('draw_id', $draw->id)->select('msisdn'))->delete();
            }

            $count    = $participants()->count();
            $funnel[] = ['label' => 'Clients éligibles', 'value' => $count];
            if ($draw->isTicketMode()) {
                $funnel[] = ['label' => 'Tickets (transactions) dans l\'urne', 'value' => DB::table('draw_tickets')->where('draw_id', $draw->id)->count()];
            }

            $draw->update([
                'status'         => 'ready',
                'error'          => null,
                'funnel'         => $funnel,
                'eligible_count' => $count,
                'ticket_count'   => $this->perTicket($draw) ? (int) $participants()->sum('txn_count') : $count,
                'eligible_hash'  => $this->hash($draw),
                'computed_by'    => $userId,
                'computed_at'    => Carbon::now(),
            ]);
        });

        unset($this->participantsCache[$draw->id]);
    }

    /** Retour au brouillon : la liste figée est supprimée */
    public function resetToDraft(Draw $draw): void
    {
        if ($draw->isDrawn() || $draw->ceremony?->hasStarted()) {
            throw new RuntimeException('Le tirage a commencé : la liste ne peut plus être modifiée.');
        }

        DB::transaction(function () use ($draw) {
            DB::table('draw_participants')->where('draw_id', $draw->id)->delete();
            DB::table('draw_tickets')->where('draw_id', $draw->id)->delete();
            $draw->update([
                'status' => 'draft', 'error' => null, 'funnel' => null, 'eligible_count' => null,
                'ticket_count' => null, 'eligible_hash' => null, 'computed_by' => null, 'computed_at' => null,
            ]);
        });

        unset($this->participantsCache[$draw->id]);
    }

    /**
     * Empreinte de la liste figée : « msisdn;chances » par client, triée par numéro ;
     * en mode transaction, « msisdn;transaction_id » par ticket, dans l'ordre du tirage.
     */
    public function hash(Draw $draw): string
    {
        $ctx = hash_init('sha256');

        if ($draw->isTicketMode()) {
            foreach ($this->ticketsQuery($draw)->cursor() as $t) {
                hash_update($ctx, $t->msisdn . ';' . $t->transaction_id . "\n");
            }
        } else {
            foreach ($this->loadParticipants($draw, fresh: true) as $p) {
                hash_update($ctx, $p->msisdn . ';' . $this->weight($draw, $p) . "\n");
            }
        }

        return hash_final($ctx);
    }

    /** Tickets figés dans l'ordre du tirage : par numéro, puis par transaction (ordre binaire, indépendant de la collation) */
    private function ticketsQuery(Draw $draw)
    {
        return DB::table('draw_tickets')
            ->where('draw_id', $draw->id)
            ->orderByRaw('msisdn COLLATE "C"')
            ->orderByRaw('transaction_id COLLATE "C"')
            ->select('msisdn', 'transaction_id');
    }

    /** n-ième ticket (1..n) d'un client, dans l'ordre du tirage */
    private function ticketOf(Draw $draw, string $msisdn, int $position): ?string
    {
        return DB::table('draw_tickets')
            ->where('draw_id', $draw->id)
            ->where('msisdn', $msisdn)
            ->orderByRaw('transaction_id COLLATE "C"')
            ->offset($position - 1)
            ->limit(1)
            ->value('transaction_id');
    }

    // ── 2. Tirage lot par lot ─────────────────────────────────────────────

    /**
     * Tire UN gagnant pour ce lot. Au dernier gagnant du lot, ses suppléants
     * sont tirés aussitôt (ils ne sont pas annoncés).
     */
    public function drawOne(DrawPrize $prize, ?int $userId = null): DrawWinner
    {
        return DB::transaction(function () use ($prize, $userId) {
            // Verrous : deux clics simultanés (même sur deux catégories) ne tirent jamais en parallèle
            $ceremony = $this->lockCeremony($prize->draw_id);
            $draw     = Draw::whereKey($prize->draw_id)->lockForUpdate()->firstOrFail();

            if ($ceremony?->isClosed()) {
                throw new RuntimeException('La cérémonie est clôturée.');
            }
            if (!in_array($draw->status, ['ready', 'drawing'], true)) {
                throw new RuntimeException($draw->status === 'drawn'
                    ? 'Tous les lots de cette catégorie ont déjà été tirés.'
                    : 'Le tirage n\'est possible qu\'une fois la liste des éligibles figée et avant la clôture.');
            }
            if ($draw->status === 'ready' && !hash_equals((string) $draw->eligible_hash, $this->hash($draw))) {
                throw new RuntimeException('La liste des éligibles a changé depuis son calcul : recalculez-la.');
            }

            $drawn = $prize->winners()->where('kind', 'winner')->count();
            if ($drawn >= $prize->quantity) {
                throw new RuntimeException('Tous les gagnants de ce lot ont déjà été tirés.');
            }

            $winner = $this->pick($draw, $prize, 'winner', $drawn + 1)
                ?? throw new RuntimeException('Plus aucun client éligible disponible pour ce lot.');

            if ($drawn + 1 === $prize->quantity) {
                for ($rank = 1; $rank <= $prize->substitutes; $rank++) {
                    if (!$this->pick($draw, $prize, 'substitute', $rank)) {
                        break;
                    }
                }
            }

            $complete = $draw->prizes()->get()->every(fn (DrawPrize $p) => $p->isComplete());
            $draw->update($complete
                ? ['status' => 'drawn', 'drawn_by' => $userId, 'drawn_at' => Carbon::now()]
                : ['status' => 'drawing']);

            if ($ceremony) {
                $this->syncCeremony($ceremony, $userId);
            }

            return $winner;
        });
    }

    /**
     * Contrôle d'intégrité : la liste n'a pas changé, et chaque sortie rejouée
     * avec sa graine (exclusions identiques à l'instant du tirage) redonne le
     * même client.
     */
    public function verify(Draw $draw): array
    {
        $listOk       = hash_equals((string) $draw->eligible_hash, $this->hash($draw));
        $participants = $this->loadParticipants($draw, fresh: true);
        $resultOk     = true;
        $before       = [];

        // Toutes les sorties de la cérémonie, dans leur ordre : celles des autres listes comptent dans les exclusions
        foreach ($this->rivalWinners($draw)->orderBy('id')->get() as $row) {
            if ($row->draw_id === $draw->id) {
                $excluded = [];
                foreach ($before as $prev) {
                    if ($this->excludes($draw, $prev, $row->draw_prize_id)) {
                        $excluded[$prev->msisdn] = true;
                    }
                }

                $replayed = $row->seed ? $this->choose($draw, $participants, $excluded, $row->seed) : null;
                if ($replayed?->msisdn !== $row->msisdn || ($draw->isTicketMode() && $replayed?->transaction_id !== $row->transaction_id)) {
                    $resultOk = false;
                    break;
                }
            }
            $before[] = $row;
        }

        return ['list_ok' => $listOk, 'result_ok' => $resultOk];
    }

    /** Contrôle de toutes les listes d'une cérémonie (celles où au moins un tirage a eu lieu) */
    public function verifyCeremony(DrawCeremony $ceremony): array
    {
        $byDraw = $ceremony->draws()->get()
            ->filter->isDrawn()
            ->mapWithKeys(fn (Draw $d) => [$d->id => $this->verify($d)]);

        return [
            'list_ok'   => $byDraw->every(fn ($r) => $r['list_ok']),
            'result_ok' => $byDraw->every(fn ($r) => $r['result_ok']),
            'draws'     => $byDraw->all(),
        ];
    }

    // ── 3. Gagnant écarté ─────────────────────────────────────────────────

    /**
     * Écarte une sortie (gagnant injoignable, non conforme…). Si c'était un
     * gagnant retenu, le premier suppléant disponible du même lot est promu et
     * retourné ; null s'il n'y en a plus (→ drawExtra()).
     */
    public function invalidate(DrawWinner $winner, string $reason, ?int $userId = null): ?DrawWinner
    {
        if (!in_array($winner->draw->status, ['drawing', 'drawn'], true) || $winner->draw->ceremony?->isClosed()) {
            throw new RuntimeException('Le tirage est clôturé : plus aucune modification possible.');
        }
        if ($winner->status !== 'valid') {
            throw new RuntimeException('Cette sortie est déjà invalidée.');
        }

        return DB::transaction(function () use ($winner, $reason, $userId) {
            $wasEffective = $winner->isEffective();

            $winner->update([
                'status'             => 'invalidated',
                'invalidated_reason' => $reason,
                'invalidated_by'     => $userId,
                'invalidated_at'     => Carbon::now(),
            ]);

            if (!$wasEffective) {
                return null;
            }

            $substitute = DrawWinner::where('draw_prize_id', $winner->draw_prize_id)
                ->where('kind', 'substitute')
                ->where('status', 'valid')
                ->whereNull('promoted_at')
                ->orderBy('rank')
                ->lockForUpdate()
                ->first();

            $substitute?->update(['promoted_at' => Carbon::now(), 'replaces_id' => $winner->id]);

            return $substitute;
        });
    }

    /**
     * Tirage complémentaire pour remplacer un gagnant écarté quand le lot n'a
     * plus de suppléant. Mêmes règles que le tirage principal ; tracé en « extra ».
     */
    public function drawExtra(DrawWinner $replaced, ?int $userId = null): ?DrawWinner
    {
        return DB::transaction(function () use ($replaced) {
            $ceremony = $this->lockCeremony($replaced->draw_id);
            $draw     = Draw::whereKey($replaced->draw_id)->lockForUpdate()->firstOrFail();

            if (!in_array($draw->status, ['drawing', 'drawn'], true) || $ceremony?->isClosed()) {
                throw new RuntimeException('Le tirage est clôturé : plus aucune modification possible.');
            }
            if ($replaced->status !== 'invalidated' || DrawWinner::where('replaces_id', $replaced->id)->exists()) {
                throw new RuntimeException('Ce gagnant a déjà un remplaçant.');
            }

            $prize = $replaced->prize;
            $rank  = (int) $prize->winners()->where('kind', 'extra')->max('rank') + 1;

            return $this->pick($draw, $prize, 'extra', $rank, $replaced->id);
        });
    }

    // ── Outils ────────────────────────────────────────────────────────────

    /**
     * Une sortie : nouvelle graine aléatoire, clients exclus = déjà sortis dans
     * une autre liste de la cérémonie, ou dans cette liste (un seul lot par
     * client) ou ce lot, quel que soit leur statut.
     */
    private function pick(Draw $draw, DrawPrize $prize, string $kind, int $rank, ?int $replacesId = null): ?DrawWinner
    {
        $excluded = $this->rivalWinners($draw)->get(['draw_id', 'draw_prize_id', 'msisdn'])
            ->filter(fn (DrawWinner $prev) => $this->excludes($draw, $prev, $prize->id))
            ->pluck('msisdn')
            ->flip()
            ->all();

        $seed = bin2hex(random_bytes(32));
        $p    = $this->choose($draw, $this->loadParticipants($draw), $excluded, $seed);

        return $p ? DrawWinner::create([
            'draw_id'        => $draw->id,
            'draw_prize_id'  => $prize->id,
            'msisdn'         => $p->msisdn,
            'transaction_id' => $p->transaction_id ?? null,
            'name'          => $p->name,
            'txn_count'     => $p->txn_count,
            'kind'          => $kind,
            'rank'          => $rank,
            'seed'          => $seed,
            'status'        => 'valid',
            'replaces_id'   => $replacesId,
        ]) : null;
    }

    /** Sorties qui comptent pour les exclusions : toute la cérémonie, ou ce seul tirage s'il n'en a pas */
    private function rivalWinners(Draw $draw)
    {
        return $draw->draw_ceremony_id
            ? DrawWinner::whereIn('draw_id', Draw::where('draw_ceremony_id', $draw->draw_ceremony_id)->select('id'))
            : DrawWinner::where('draw_id', $draw->id);
    }

    /** Une sortie antérieure exclut-elle son client pour ce lot ? */
    private function excludes(Draw $draw, DrawWinner $prev, int $prizeId): bool
    {
        return $prev->draw_id !== $draw->id                // autre catégorie de la cérémonie : un seul lot par client
            || $draw->one_prize_per_client
            || $prev->draw_prize_id === $prizeId;
    }

    private function lockCeremony(int $drawId): ?DrawCeremony
    {
        $id = Draw::whereKey($drawId)->value('draw_ceremony_id');

        return $id ? DrawCeremony::whereKey($id)->lockForUpdate()->first() : null;
    }

    /** Statut de la cérémonie d'après ses listes : en cours dès la 1re sortie, terminée quand tout est tiré */
    private function syncCeremony(DrawCeremony $ceremony, ?int $userId): void
    {
        $draws   = Draw::where('draw_ceremony_id', $ceremony->id)->pluck('status');
        $allDone = $draws->isNotEmpty() && $draws->every(fn ($s) => in_array($s, ['drawn', 'closed'], true));

        if ($allDone && $ceremony->status !== 'done') {
            $ceremony->update(['status' => 'done', 'drawn_by' => $userId, 'drawn_at' => Carbon::now(),
                               'started_at' => $ceremony->started_at ?? Carbon::now()]);
        } elseif (!$allDone && $ceremony->status === 'preparation') {
            $ceremony->update(['status' => 'in_progress', 'started_at' => Carbon::now()]);
        }
    }

    /**
     * Tirage pondéré déterministe pour une graine donnée ; null si l'urne est vide.
     * Mode transaction : même tirage, plus le rang du ticket chez le client tiré
     * (= un ticket tiré uniformément parmi tous les tickets non exclus).
     */
    private function choose(Draw $draw, array $participants, array $excluded, string $seed): ?object
    {
        $weights = array_map(fn ($p) => isset($excluded[$p->msisdn]) ? 0 : $this->weight($draw, $p), $participants);
        $picked  = (new WeightedPool($weights))->pickTicket(new Randomizer(new Xoshiro256StarStar(hex2bin($seed))));

        if ($picked === null) {
            return null;
        }

        [$i, $position] = $picked;
        $p = clone $participants[$i];
        $p->transaction_id = $draw->isTicketMode() ? $this->ticketOf($draw, $p->msisdn, $position) : null;

        return $p;
    }

    /**
     * Liste figée, toujours dans le même ordre (base du hash et du tirage).
     * Gardée en mémoire pour les sorties successives d'une même requête ;
     * relue en base (fresh) pour l'empreinte et la vérification.
     */
    private function loadParticipants(Draw $draw, bool $fresh = false): array
    {
        if ($fresh || !isset($this->participantsCache[$draw->id])) {
            $this->participantsCache[$draw->id] = DB::table('draw_participants')
                ->where('draw_id', $draw->id)
                ->orderBy('msisdn')
                ->get(['msisdn', 'name', 'txn_count'])
                ->all();
        }

        return $this->participantsCache[$draw->id];
    }

    private function weight(Draw $draw, object $participant): int
    {
        return $this->perTicket($draw) ? max(1, (int) $participant->txn_count) : 1;
    }

    /** Une chance par transaction (choix de la liste, ou imposé par le mode transaction) */
    private function perTicket(Draw $draw): bool
    {
        return $draw->chance_mode === 'per_transaction' || $draw->isTicketMode();
    }
}
