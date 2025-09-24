<?php
declare(strict_types=1);

/**
 * FlashTournamentFinalizer
 * - Finalizza tornei flash (winners + payout).
 * - Non interagisce con tornei classici.
 */

final class FlashTournamentFinalizer
{
    public static function shouldEnd(\PDO $pdo, int $tid): array {
        $st=$pdo->prepare("SELECT total_rounds, current_round FROM flash_tournaments WHERE id=? LIMIT 1");
        $st->execute([$tid]); $row=$st->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return ['should_end'=>false,'reason'=>'missing_tournament','alive_users'=>0,'round'=>null];

        $aliveUsers = (int)$pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM flash_tournament_lives WHERE tournament_id=? AND status='alive'")
                               ->execute([$tid])->fetchColumn();

        $should = ((int)$row['current_round'] > (int)$row['total_rounds']) || ($aliveUsers<2);
        return ['should_end'=>$should,'reason'=>$should?'conditions_met':'continue','alive_users'=>$aliveUsers,'round'=>(int)$row['current_round']];
    }

    public static function finalize(\PDO $pdo, int $tid, int $adminId): array {
        $chk = self::shouldEnd($pdo,$tid);
        if (!$chk['should_end']) return ['ok'=>false,'error'=>'not_final','message'=>'Condizioni non soddisfatte','context'=>$chk];

        $pdo->beginTransaction();
        try{
            $pool = (float)($pdo->prepare("SELECT COALESCE(prize_pool,0) FROM flash_tournaments WHERE id=?")->execute([$tid])->fetchColumn() ?: 0);

            $wq=$pdo->prepare("SELECT DISTINCT user_id, id AS life_id FROM flash_tournament_lives WHERE tournament_id=? AND status='alive'");
            $wq->execute([$tid]); $winners=$wq->fetchAll(\PDO::FETCH_ASSOC);

            $n = max(1, count($winners));
            $share = $n>0 ? round($pool / $n, 2) : 0.00;

            $ins=$pdo->prepare("INSERT INTO flash_tournament_payouts(tournament_id,user_id,life_id,amount,position) VALUES (?,?,?,?,?)");
            $pos=1;
            foreach($winners as $w){ $ins->execute([$tid,(int)$w['user_id'],(int)$w['life_id'],$share,$pos++]); }

            $pdo->prepare("UPDATE flash_tournaments SET status='finalized' WHERE id=?")->execute([$tid]);

            $pdo->commit();
            return ['ok'=>true,'result'=>'finalized','pool'=>$pool,'winners'=>$winners];
        }catch(\Throwable $e){
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok'=>false,'error'=>'finalize_failed','message'=>$e->getMessage(),'file'=>__FILE__,'where'=>__METHOD__,'line'=>$e->getLine()];
        }
    }
}
