<?php
declare(strict_types=1);

/**
 * FlashTournamentFinalizer
 *  - Finalizzazione Torneo Flash:
 *    * vincitore unico => 100% pool
 *    * >=2 vivi a fine round 3 => split equo
 *    * caso speciale rimborso totale: se tutti i partecipanti iniziali sono ancora vivi a fine R3 => rimborso buyin (no rake)
 *
 *  Conserva i payout in tournament_flash_payouts. Non tocca sistemi di pagamento esistenti.
 */
final class FlashTournamentFinalizer
{
    public static function finalize(\PDO $pdo,int $tid,int $adminId): array {
        try{
            $tour=self::tour($pdo,$tid);
            if(!$tour) return ['ok'=>false,'error'=>'bad_tournament'];
            if($tour['status']==='finalized') return ['ok'=>true,'result'=>'already_finalized'];

            // vivi attuali
            $st=$pdo->prepare("SELECT id,user_id FROM tournament_flash_lives WHERE tournament_id=? AND status='alive'");
            $st->execute([$tid]); $alive=$st->fetchAll(\PDO::FETCH_ASSOC);

            $aliveUsers = array_values(array_unique(array_map(fn($r)=>(int)$r['user_id'],$alive)));
            $aliveCount = count($aliveUsers);

            // partecipanti iniziali (alla pubblicazione)
            $init = (int)($tour['participants_init'] ?? 0);
            if ($init<=0) {
                // calcolo fallback (tutti gli utenti che hanno vite nel torneo - utile se publish non le aveva)
                $q=$pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM tournament_flash_lives WHERE tournament_id=?");
                $q->execute([$tid]); $init=(int)$q->fetchColumn();
            }

            // ultimo round?
            $totalRounds = 3;
            $curRound = (int)$tour['current_round'];

            // calcolo pool
            $pool = self::computePool($tour, $pdo, $tid);

            $pdo->beginTransaction();

            $payouts = [];
            $result  = 'split';

            if ($aliveCount===1) {
                // vincitore unico
                $winnerUserId = $aliveUsers[0];
                $payouts[] = ['user_id'=>$winnerUserId,'life_id'=>null,'amount'=>$pool,'position'=>1];
                $result='winner_single';
            } else {
                // Se siamo al termine del R3 e tutti ancora vivi => rimborso totale buyin (no rake)
                if ($curRound > $totalRounds && $aliveCount === $init && $init>0) {
                    $buyin = (float)$tour['buyin'];
                    foreach ($aliveUsers as $uid) {
                        // rimborsa per utente (se multi-vite, rifondere per #vite acquistate)
                        $q=$pdo->prepare("SELECT COUNT(*) FROM tournament_flash_lives WHERE tournament_id=? AND user_id=?");
                        $q->execute([$tid,$uid]); $lifeCount=(int)$q->fetchColumn();
                        $payouts[] = ['user_id'=>$uid,'life_id'=>null,'amount'=>($buyin * max(1,$lifeCount)),'position'=>1];
                    }
                    $result='refund_all';
                } else {
                    // split tra vivi (equamente)
                    $share = ($aliveCount>0) ? round($pool / $aliveCount, 2) : 0.00;
                    $pos=1;
                    foreach ($aliveUsers as $uid) {
                        $payouts[] = ['user_id'=>$uid,'life_id'=>null,'amount'=>$share,'position'=>$pos++];
                    }
                    $result='split';
                }
            }

            // scrivi payout
            if ($payouts) {
                $ins=$pdo->prepare(
                  "INSERT INTO tournament_flash_payouts (tournament_id,user_id,life_id,amount,position)
                   VALUES (?,?,?,?,?)"
                );
                foreach($payouts as $p){
                    $ins->execute([$tid,(int)$p['user_id'],$p['life_id'],$p['amount'],$p['position']]);
                }
            }

            // chiudi torneo
            $u=$pdo->prepare("UPDATE tournament_flash SET status='finalized', lock_at=NULL WHERE id=? LIMIT 1");
            $u->execute([$tid]);

            $pdo->commit();

            return [
                'ok'=>true,
                'result'=>$result,
                'pool'=>$pool,
                'winners'=>array_map(fn($p)=>['user_id'=>$p['user_id'],'amount'=>$p['amount']], $payouts)
            ];
        }catch(\Throwable $e){
            if($pdo->inTransaction()) $pdo->rollBack();
            return ['ok'=>false,'error'=>'finalize_failed','where'=>'engine.finalize','detail'=>$e->getMessage()];
        }
    }

    private static function tour(\PDO $pdo,int $tid): ?array {
        $st=$pdo->prepare("SELECT * FROM tournament_flash WHERE id=? LIMIT 1");
        $st->execute([$tid]); $r=$st->fetch(\PDO::FETCH_ASSOC);
        return $r?:null;
    }

    /**
     * Calcolo montepremi:
     *   pool_from_buyins = buyin * #vite * (buyin_to_prize_pct/100)
     *   pool = max(pool_from_buyins, guaranteed_prize|null->0)
     *   NB: la rake non si applica al pool, ma separatamente nel tuo sistema; qui conserviamo solo i payout ai vincitori.
     */
    private static function computePool(array $tour, \PDO $pdo, int $tid): float {
        $buyin = (float)($tour['buyin'] ?? 0.00);
        $pct   = (float)($tour['buyin_to_prize_pct'] ?? 0.00);
        $g     = (float)($tour['guaranteed_prize'] ?? 0.00);

        $q=$pdo->prepare("SELECT COUNT(*) FROM tournament_flash_lives WHERE tournament_id=?");
        $q->execute([$tid]); $lifeCount=(int)$q->fetchColumn();

        $pool_from_buyins = round($buyin * $lifeCount * ($pct/100.0), 2);
        $pool = max($pool_from_buyins, $g);

        // override se già impostato a mano
        if (!empty($tour['prize_pool'])) $pool = (float)$tour['prize_pool'];

        return round($pool, 2);
    }
}
