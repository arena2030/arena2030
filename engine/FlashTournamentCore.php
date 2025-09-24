<?php
declare(strict_types=1);

/**
 * FlashTournamentCore
 * - Sigillo/Riapertura/Calcolo esclusivi per i Tornei Flash.
 * - Non tocca le tabelle/classiche; usa solo tabelle flash_*
 */

final class FlashTournamentCore
{
    /* ============ Utilities ============ */

    private static function outNormalizeOutcome(?string $s): string {
        $s = strtoupper(trim((string)$s));
        if ($s==='') return 'UNKNOWN';
        $map = [
          'HOME' => ['HOME','H','CASA','1','HOST'],
          'AWAY' => ['AWAY','A','TRASFERTA','2','GUEST'],
          'DRAW' => ['DRAW','X','PAREGGIO','TIE'],
          'VOID' => ['VOID','POSTPONED','CANCELLED','CANCELED','ANNULLATA','RINVIATA']
        ];
        foreach ($map as $k=>$arr) if (in_array($s,$arr,true)) return $k;
        return in_array($s,['HOME','AWAY','DRAW','VOID','UNKNOWN'],true) ? $s : 'UNKNOWN';
    }

    private static function genCode(int $len=8): string {
        $len = max(4, min(16,$len));
        return strtoupper(substr(bin2hex(random_bytes($len)),0,$len));
    }

    private static function detectOutcome(array $ev): string {
        $res = strtoupper((string)($ev['result'] ?? 'UNKNOWN'));
        return self::outNormalizeOutcome($res);
    }

    private static function throwx(string $msg, string $where): void {
        $e = new \RuntimeException($msg);
        $e->where = $where;
        throw $e;
    }

    /* ============ Create/Publish ============ */

    public static function create(\PDO $pdo, array $data): array {
        $code = self::genCode(6);
        $st = $pdo->prepare("INSERT INTO flash_tournaments(code,name,total_rounds,events_per_round,prize_pool,status)
                             VALUES(?,?,?,?,?, 'pending')");
        $st->execute([$code, (string)$data['name'], (int)$data['total_rounds'], (int)$data['events_per_round'], (float)($data['prize_pool'] ?? 0)]);
        return ['ok'=>true,'id'=>(int)$pdo->lastInsertId(),'code'=>$code];
    }

    public static function publish(\PDO $pdo, int $tid): array {
        $pdo->beginTransaction();
        try{
            // crea righe rounds se mancanti
            $st = $pdo->prepare("SELECT total_rounds FROM flash_tournaments WHERE id=? LIMIT 1");
            $st->execute([$tid]); $tot = (int)$st->fetchColumn();
            if ($tot<=0) self::throwx('total_rounds non impostato','FlashTournamentCore::publish');

            for ($i=1;$i<=$tot;$i++){
                $ins = $pdo->prepare("INSERT IGNORE INTO flash_tournament_rounds(tournament_id,round_no,status) VALUES (?,?, 'published')");
                $ins->execute([$tid,$i]);
            }
            $pdo->prepare("UPDATE flash_tournaments SET status='published', current_round=1 WHERE id=?")->execute([$tid]);
            $pdo->commit();
            return ['ok'=>true];
        }catch(\Throwable $e){
            if($pdo->inTransaction()) $pdo->rollBack();
            return ['ok'=>false,'error'=>'publish_failed','message'=>$e->getMessage(),'file'=>__FILE__,'where'=>__METHOD__,'line'=>$e->getLine()];
        }
    }

    /* ============ Events ============ */

    public static function addEvent(\PDO $pdo, int $tid, int $round, int $home, int $away): array {
        if ($home<=0 || $away<=0 || $home===$away) return ['ok'=>false,'error'=>'teams_invalid','where'=>__METHOD__,'file'=>__FILE__,'line'=>__LINE__];
        $code = self::genCode(8);
        $st = $pdo->prepare("INSERT INTO flash_tournament_events(tournament_id,round_no,event_code,home_team_id,away_team_id) VALUES (?,?,?,?,?)");
        $st->execute([$tid,$round,$code,$home,$away]);
        return ['ok'=>true,'event_code'=>$code];
    }

    public static function listEvents(\PDO $pdo, int $tid, int $round): array {
        $st = $pdo->prepare("SELECT e.*, h.name AS home_name, a.name AS away_name
                               FROM flash_tournament_events e
                               JOIN teams h ON h.id=e.home_team_id
                               JOIN teams a ON a.id=e.away_team_id
                              WHERE e.tournament_id=? AND e.round_no=?
                              ORDER BY e.id DESC");
        $st->execute([$tid,$round]);
        return ['ok'=>true,'rows'=>$st->fetchAll(\PDO::FETCH_ASSOC)];
    }

    /* ============ Seal/Reopen ============ */

    public static function sealRound(\PDO $pdo, int $tid, int $round): array {
        // pick-level lock: set locked_at=NOW() sulle pick presenti del round
        $u=$pdo->prepare("UPDATE flash_tournament_picks SET locked_at=NOW()
                           WHERE tournament_id=? AND round_no=? AND locked_at IS NULL");
        $u->execute([$tid,$round]);
        $n1=$u->rowCount();

        // fallback: event-level
        $u2=$pdo->prepare("UPDATE flash_tournament_events SET is_locked=1 WHERE tournament_id=? AND round_no=?");
        $u2->execute([$tid,$round]);
        $n2=$u2->rowCount();

        // fallback ulteriore: set lock_at ora
        $pdo->prepare("UPDATE flash_tournaments SET lock_at=NOW() WHERE id=?")->execute([$tid]);

        return ['ok'=>true,'mode'=>'pick_lock','sealed'=>$n1,'events_locked'=>$n2];
    }

    public static function reopenRound(\PDO $pdo, int $tid, int $round): array {
        $u=$pdo->prepare("UPDATE flash_tournament_picks SET locked_at=NULL WHERE tournament_id=? AND round_no=?");
        $u->execute([$tid,$round]);
        $n1=$u->rowCount();

        $u2=$pdo->prepare("UPDATE flash_tournament_events SET is_locked=0 WHERE tournament_id=? AND round_no=?");
        $u2->execute([$tid,$round]);
        $n2=$u2->rowCount();

        $pdo->prepare("UPDATE flash_tournaments SET lock_at=NULL WHERE id=?")->execute([$tid]);
        return ['ok'=>true,'mode'=>'pick_lock','reopened'=>$n1,'events_unlocked'=>$n2];
    }

    /* ============ Picks (utente) ============ */

    /**
     * submit all picks up-front:  payload = [{life_id, round_no, event_id, team_id}, ...]
     */
    public static function submitPicks(\PDO $pdo, int $tid, int $uid, array $payload): array {
        if (!$payload) return ['ok'=>false,'error'=>'empty_payload','where'=>__METHOD__,'file'=>__FILE__,'line'=>__LINE__];
        $pdo->beginTransaction();
        try{
            // sicurezza: le life devono appartenere all'utente
            $lifeIds = array_values(array_unique(array_map(fn($x)=>(int)$x['life_id'],$payload)));
            $in = implode(',', array_fill(0,count($lifeIds),'?'));
            $chk = $pdo->prepare("SELECT id FROM flash_tournament_lives WHERE tournament_id=? AND user_id=? AND id IN ($in)");
            $chk->execute(array_merge([$tid,$uid], $lifeIds));
            $okSet = array_fill_keys(array_map('intval',$chk->fetchAll(\PDO::FETCH_COLUMN)), true);

            $ins = $pdo->prepare("INSERT INTO flash_tournament_picks(tournament_id,life_id,round_no,event_id,team_id)
                                  VALUES (?,?,?,?,?)
                                  ON DUPLICATE KEY UPDATE event_id=VALUES(event_id), team_id=VALUES(team_id)");
            $n=0;
            foreach ($payload as $r) {
                $lifeId=(int)$r['life_id']; if (!isset($okSet[$lifeId])) self::throwx('life_id non valido','FlashTournamentCore::submitPicks');
                $ins->execute([$tid,(int)$r['life_id'],(int)$r['round_no'],(int)$r['event_id'],(int)$r['team_id']]); $n++;
            }
            $pdo->commit();
            return ['ok'=>true,'saved'=>$n];
        }catch(\Throwable $e){
            if($pdo->inTransaction()) $pdo->rollBack();
            return ['ok'=>false,'error'=>'submit_failed','message'=>$e->getMessage(),'file'=>__FILE__,'where'=>__METHOD__,'line'=>$e->getLine()];
        }
    }

    /* ============ Compute round ============ */

    public static function computeRound(\PDO $pdo, int $tid, int $round): array {
        // verifica risultati completi
        $st=$pdo->prepare("SELECT * FROM flash_tournament_events WHERE tournament_id=? AND round_no=?");
        $st->execute([$tid,$round]); $evs=$st->fetchAll(\PDO::FETCH_ASSOC);
        if (!$evs) return ['ok'=>false,'error'=>'no_events_for_round','where'=>__METHOD__,'file'=>__FILE__,'line'=>__LINE__];

        $unknown=[]; foreach($evs as $e){ if (self::detectOutcome($e)==='UNKNOWN'){ $unknown[]=$e['event_code']; } }
        if ($unknown) return ['ok'=>false,'error'=>'results_missing','detail'=>['events'=>$unknown],'where'=>__METHOD__,'file'=>__FILE__,'line'=>__LINE__];

        // carica pick sigillate (preferenza pick-level; in Flash le consideriamo comunque valide se presenti)
        $q=$pdo->prepare("SELECT p.*, e.home_team_id, e.away_team_id
                            FROM flash_tournament_picks p
                            JOIN flash_tournament_lives  l ON l.id=p.life_id
                            JOIN flash_tournament_events e ON e.id=p.event_id
                           WHERE p.tournament_id=? AND p.round_no=? AND l.status='alive'");
        $q->execute([$tid,$round]); $picks=$q->fetchAll(\PDO::FETCH_ASSOC);

        // vite vive attese
        $alive=$pdo->prepare("SELECT id FROM flash_tournament_lives WHERE tournament_id=? AND status='alive' AND round=?");
        $alive->execute([$tid,$round]); $aliveIds=array_map('intval',$alive->fetchAll(\PDO::FETCH_COLUMN));

        // doppie pick (non dovrebbero esistere per uk constraint, ma controllo difensivo)
        $byLife=[]; foreach($picks as $r){ $byLife[(int)$r['life_id']][]=$r; }
        foreach ($byLife as $lid=>$arr){ if (count($arr)>1) return ['ok'=>false,'error'=>'duplicate_picks','detail'=>['life_id'=>$lid],'where'=>__METHOD__,'file'=>__FILE__,'line'=>__LINE__]; }

        // no-pick: le vite senza pick vanno out
        $lifeWithPick=array_map('intval', array_keys($byLife));
        $lifeNoPick=array_values(array_diff($aliveIds,$lifeWithPick));

        $pass=[]; $out=[];

        // mappa eventi per outcome veloce
        $evById=[]; foreach($evs as $e){ $evById[(int)$e['id']]=self::detectOutcome($e); }

        foreach ($picks as $r){
            $outc = $evById[(int)$r['event_id']];
            $home = (int)$r['home_team_id']; $away=(int)$r['away_team_id']; $team=(int)$r['team_id'];
            $life = (int)$r['life_id'];

            // coerenza team
            if ($team!==$home && $team!==$away){
                return ['ok'=>false,'error'=>'invalid_pick_team','detail'=>['life_id'=>$life,'team_id'=>$team,'home'=>$home,'away'=>$away],'where'=>__METHOD__,'file'=>__FILE__,'line'=>__LINE__];
            }

            if ($outc==='DRAW'){ $out[]=$life; continue; }
            if ($outc==='VOID'){ $pass[]=$life; continue; }
            if ($outc==='HOME'){ ($team===$home) ? $pass[]=$life : $out[]=$life; continue; }
            if ($outc==='AWAY'){ ($team===$away) ? $pass[]=$life : $out[]=$life; continue; }
        }

        if ($lifeNoPick) $out = array_values(array_unique(array_merge($out,$lifeNoPick)));

        $pdo->beginTransaction();
        try{
            if ($out){
                $in=implode(',', array_fill(0,count($out),'?'));
                $pdo->prepare("UPDATE flash_tournament_lives SET status='out' WHERE id IN ($in)")->execute($out);
            }
            if ($pass){
                $in=implode(',', array_fill(0,count($pass),'?'));
                $pdo->prepare("UPDATE flash_tournament_lives SET status='alive', round=round+1 WHERE id IN ($in)")->execute($pass);
            }
            $pdo->commit();
        }catch(\Throwable $e){
            if($pdo->inTransaction()) $pdo->rollBack();
            return ['ok'=>false,'error'=>'calc_failed','message'=>$e->getMessage(),'file'=>__FILE__,'where'=>__METHOD__,'line'=>$e->getLine()];
        }

        return ['ok'=>true,'passed'=>count(array_unique($pass)),'out'=>count(array_unique($out)),'next_round'=>$round+1];
    }

    public static function publishNextRound(\PDO $pdo, int $tid, int $round): array {
        $pdo->prepare("UPDATE flash_tournaments SET current_round=GREATEST(current_round, ?) WHERE id=?")->execute([$round+1,$tid]);
        // opzionale: pulizia lock_at
        $pdo->prepare("UPDATE flash_tournaments SET lock_at=NULL WHERE id=?")->execute([$tid]);
        return ['ok'=>true,'current_round'=>$round+1];
    }
}
