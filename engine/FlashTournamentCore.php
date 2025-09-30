<?php
declare(strict_types=1);

/**
 * FlashTournamentCore
 *  - Crea tornei flash con stessi campi del torneo classico (ma tabella dedicata).
 *  - Gestione eventi (1 per round, 3 round fissi), publish/lock/reopen.
 *  - Scelte utente upfront (vincolo: per ogni vita, permutazione esatta di {HOME, DRAW, AWAY}).
 *  - Calcolo round, publish next.
 *
 *  Tabelle: tournament_flash*, teams (esistente)
 */
final class FlashTournamentCore
{
    /* ===== Utils ===== */

    private static function genCode(int $len=6): string {
        $len = max(4,min($len,12));
        $bytes = random_bytes($len);
        $base = strtoupper(substr(bin2hex($bytes),0,$len));
        return $base;
    }

    private static function tourById(\PDO $pdo, int $tid): ?array {
        $st=$pdo->prepare("SELECT * FROM tournament_flash WHERE id=? LIMIT 1");
        $st->execute([$tid]);
        $r=$st->fetch(\PDO::FETCH_ASSOC);
        return $r?:null;
    }

    public static function resolveId(\PDO $pdo, ?int $id, ?string $code): int {
        if ($id && $id>0) return $id;
        if ($code) {
            $st=$pdo->prepare("SELECT id FROM tournament_flash WHERE UPPER(code)=UPPER(?) LIMIT 1");
            $st->execute([$code]);
            $x=$st->fetchColumn();
            return $x? (int)$x : 0;
        }
        return 0;
    }

    private static function teamName(\PDO $pdo, int $teamId): ?string {
        $st=$pdo->prepare("SELECT name FROM teams WHERE id=? LIMIT 1");
        $st->execute([$teamId]);
        $n=$st->fetchColumn();
        return $n!==false ? (string)$n : null;
    }

    /* ===== Crea torneo (pending) ===== */

    public static function create(\PDO $pdo, array $data): array {
        try{
            $code = self::allocCode($pdo);
            $st=$pdo->prepare(
              "INSERT INTO tournament_flash
               (code,name,status,buyin,seats_max,seats_infinite,lives_max_user,guaranteed_prize,buyin_to_prize_pct,rake_pct,total_rounds,events_per_round,current_round,prize_pool)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NULL)"
            );
            $name = trim((string)($data['name'] ?? 'Torneo Flash'));
            $buyin= (float)($data['buyin'] ?? 0);
            $seats_inf = (int)($data['seats_infinite'] ?? 0);
            $seats_max = $seats_inf? null : (isset($data['seats_max']) ? (int)$data['seats_max'] : null);
            $lives_max= max(1,(int)($data['lives_max_user'] ?? 1));
            $gprize   = strlen(trim((string)($data['guaranteed_prize'] ?? ''))) ? (float)$data['guaranteed_prize'] : null;
            $pct2prize= (float)($data['buyin_to_prize_pct'] ?? 0);
            $rake_pct = (float)($data['rake_pct'] ?? 0);

            $st->execute([
                $code,$name,'pending',$buyin,$seats_max,$seats_inf,$lives_max,$gprize,$pct2prize,$rake_pct,3,1,1
            ]);
            return ['ok'=>true,'id'=>(int)$pdo->lastInsertId(),'code'=>$code];
        }catch(\Throwable $e){
            return ['ok'=>false,'error'=>'create_failed','where'=>'engine.core.create','detail'=>$e->getMessage()];
        }
    }

    private static function allocCode(\PDO $pdo): string {
        for($i=0;$i<10;$i++){
            $c=self::genCode(6);
            $q=$pdo->prepare("SELECT 1 FROM tournament_flash WHERE code=? LIMIT 1");
            $q->execute([$c]);
            if(!$q->fetchColumn()) return $c;
        }
        throw new \RuntimeException("code_alloc_fail");
    }

    /* ===== Eventi ===== */

    public static function addEvent(\PDO $pdo, int $tid, int $round, int $homeId, int $awayId): array {
        if ($tid<=0 || $round<1 || $round>3) return ['ok'=>false,'error'=>'bad_round'];
        if ($homeId<=0 || $awayId<=0 || $homeId===$awayId) return ['ok'=>false,'error'=>'teams_invalid'];

        $tour=self::tourById($pdo,$tid);
        if(!$tour) return ['ok'=>false,'error'=>'bad_tournament'];
        if($tour['status']!=='pending') return ['ok'=>false,'error'=>'bad_status','detail'=>'Aggiunta eventi consentita solo in pending'];

        try{
            $code = self::allocEventCode($pdo);
            // un solo evento per round
            $chk=$pdo->prepare("SELECT 1 FROM tournament_flash_events WHERE tournament_id=? AND round_no=? LIMIT 1");
            $chk->execute([$tid,$round]);
            if($chk->fetchColumn()) return ['ok'=>false,'error'=>'round_already_has_event'];

            $st=$pdo->prepare(
              "INSERT INTO tournament_flash_events (tournament_id,round_no,event_code,home_team_id,away_team_id)
               VALUES (?,?,?,?,?)"
            );
            $st->execute([$tid,$round,$code,$homeId,$awayId]);
            return ['ok'=>true,'event_code'=>$code];
        }catch(\Throwable $e){
            return ['ok'=>false,'error'=>'add_event_failed','where'=>'engine.core.addEvent','detail'=>$e->getMessage()];
        }
    }

    private static function allocEventCode(\PDO $pdo): string {
        for($i=0;$i<12;$i++){
            $c=self::genCode(8);
            $q=$pdo->prepare("SELECT 1 FROM tournament_flash_events WHERE event_code=? LIMIT 1");
            $q->execute([$c]);
            if(!$q->fetchColumn()) return $c;
        }
        throw new \RuntimeException("event_code_alloc_fail");
    }

    public static function listEvents(\PDO $pdo, int $tid, int $round): array {
        try{
            $st=$pdo->prepare(
              "SELECT e.id,e.round_no,e.event_code,e.home_team_id,e.away_team_id,COALESCE(e.is_locked,0) AS is_locked,
                      COALESCE(e.result,'UNKNOWN') AS result,
                      th.name AS home_name, ta.name AS away_name
               FROM tournament_flash_events e
               JOIN teams th ON th.id=e.home_team_id
               JOIN teams ta ON ta.id=e.away_team_id
               WHERE e.tournament_id=? AND e.round_no=?
               ORDER BY e.id ASC"
            );
            $st->execute([$tid,$round]);
            return ['ok'=>true,'rows'=>$st->fetchAll(\PDO::FETCH_ASSOC)];
        }catch(\Throwable $e){
            return ['ok'=>false,'error'=>'list_events_failed','where'=>'engine.core.listEvents','detail'=>$e->getMessage()];
        }
    }

    public static function publish(\PDO $pdo, int $tid): array {
        $tour=self::tourById($pdo,$tid); if(!$tour) return ['ok'=>false,'error'=>'bad_tournament'];
        if($tour['status']!=='pending') return ['ok'=>false,'error'=>'bad_status','detail'=>'Pubblicabile solo in pending'];

        // deve esistere 1 evento per round 1..3
        for($r=1;$r<=3;$r++){
            $q=$pdo->prepare("SELECT COUNT(*) FROM tournament_flash_events WHERE tournament_id=? AND round_no=?");
            $q->execute([$tid,$r]);
            if((int)$q->fetchColumn()!==1){
                return ['ok'=>false,'error'=>'events_incomplete','detail'=>"Manca evento per round $r"];
            }
        }

        try{
            $pdo->beginTransaction();

            // memorizza #utenti partecipanti (distinti) al momento della pubblicazione
            $q=$pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM tournament_flash_lives WHERE tournament_id=?");
            $q->execute([$tid]); $partInit=(int)$q->fetchColumn();

            $st=$pdo->prepare("UPDATE tournament_flash
                               SET status='published', current_round=1, lock_at=NULL, published_at=NOW(), participants_init=?
                               WHERE id=? LIMIT 1");
            $st->execute([$partInit,$tid]);

            $pdo->commit();
            return ['ok'=>true,'status'=>'published','current_round'=>1,'participants_init'=>$partInit];
        }catch(\Throwable $e){
            if($pdo->inTransaction()) $pdo->rollBack();
            return ['ok'=>false,'error'=>'publish_failed','where'=>'engine.core.publish','detail'=>$e->getMessage()];
        }
    }

    /* ===== Sigillo / Riapertura ===== */

    public static function sealRound(\PDO $pdo,int $tid,int $round): array {
        try{
            $pdo->beginTransaction();

            // lock evento
            $u=$pdo->prepare("UPDATE tournament_flash_events SET is_locked=1 WHERE tournament_id=? AND round_no=?");
            $u->execute([$tid,$round]);

            // lock pick di quel round
            $u2=$pdo->prepare("UPDATE tournament_flash_picks SET locked_at=NOW() WHERE tournament_id=? AND round_no=? AND locked_at IS NULL");
            $u2->execute([$tid,$round]);

            // === AUTO-ELIM: elimina le vite che NON hanno fatto alcuna scelta per questo round ===
// Regola: se una vita è 'alive' al round corrente e non esiste un pick per quel round, la vita diventa 'out'.
// (Nel Flash ci interessa soprattutto il round 1, ma la logica è generica per ogni round sigillato.)
$elim = $pdo->prepare("
  UPDATE tournament_flash_lives AS l
  LEFT JOIN tournament_flash_picks AS p
    ON p.tournament_id = l.tournament_id
   AND p.life_id       = l.id
   AND p.round_no      = ?
  SET l.status = 'out'
  WHERE l.tournament_id = ?
    AND l.status        = 'alive'
    AND l.`round`       = ?
    AND p.id IS NULL
");
$elim->execute([$round, $tid, $round]);
// (facoltativo) $autoEliminated = $elim->rowCount();

            // segna torneo locked genericamente (facoltativo)
            $u3=$pdo->prepare("UPDATE tournament_flash SET lock_at=NOW(), status='locked' WHERE id=?");
            $u3->execute([$tid]);

            $pdo->commit();
            return ['ok'=>true,'mode'=>'pick_lock','sealed'=>$u2->rowCount(),'events_locked'=>$u->rowCount()];
        }catch(\Throwable $e){
            if($pdo->inTransaction()) $pdo->rollBack();
            return ['ok'=>false,'error'=>'seal_failed','where'=>'engine.core.sealRound','detail'=>$e->getMessage()];
        }
    }

    public static function reopenRound(\PDO $pdo,int $tid,int $round): array {
        try{
            $pdo->beginTransaction();

            $u=$pdo->prepare("UPDATE tournament_flash_events SET is_locked=0 WHERE tournament_id=? AND round_no=?");
            $u->execute([$tid,$round]);

            $u2=$pdo->prepare("UPDATE tournament_flash_picks SET locked_at=NULL WHERE tournament_id=? AND round_no=?");
            $u2->execute([$tid,$round]);

            $u3=$pdo->prepare("UPDATE tournament_flash SET lock_at=NULL, status='published' WHERE id=?");
            $u3->execute([$tid]);

            $pdo->commit();
            return ['ok'=>true,'mode'=>'pick_lock','reopened'=>$u2->rowCount(),'events_unlocked'=>$u->rowCount()];
        }catch(\Throwable $e){
            if($pdo->inTransaction()) $pdo->rollBack();
            return ['ok'=>false,'error'=>'reopen_failed','where'=>'engine.core.reopenRound','detail'=>$e->getMessage()];
        }
    }

    /* ===== Scelte utente ===== */

    /**
     * @param array $payload Array di oggetti: [{life_id, round_no(1..3), event_id, choice:'HOME'|'DRAW'|'AWAY'}, ...]
     * Vincolo: per ogni life_id, l'insieme {choice round 1..3} == permutazione esatta di {HOME,DRAW,AWAY}.
     */
    public static function submitPicks(\PDO $pdo,int $tid,int $uid,array $payload): array {
        if (!$payload) return ['ok'=>false,'error'=>'empty_payload'];
        // Raggruppa per life_id
        $byLife=[];
        foreach($payload as $row){
            $lid=(int)($row['life_id'] ?? 0);
            $rnd=(int)($row['round_no'] ?? 0);
            $ev =(int)($row['event_id'] ?? 0);
            $ch = strtoupper(trim((string)($row['choice'] ?? '')));
            if($lid<=0||$rnd<1||$rnd>3||$ev<=0||!in_array($ch,['HOME','DRAW','AWAY'],true)){
                return ['ok'=>false,'error'=>'invalid_payload_row','detail'=>$row];
            }
            $byLife[$lid][] = ['round_no'=>$rnd,'event_id'=>$ev,'choice'=>$ch];
        }

        try{
            $pdo->beginTransaction();

            // Validazioni per vita
            foreach($byLife as $lid=>$rows){
                // vita appartiene all'utente e al torneo
                $q=$pdo->prepare("SELECT user_id FROM tournament_flash_lives WHERE id=? AND tournament_id=? LIMIT 1");
                $q->execute([$lid,$tid]); $owner=(int)$q->fetchColumn();
                if ($owner!==$uid) { throw new \RuntimeException("life_owner_mismatch (life_id=$lid)"); }

                // deve avere 3 entry (1..3) con scelte distinte
                if (count($rows)!==3) { throw new \RuntimeException("life_entries_must_be_3 (life_id=$lid)"); }
                $rounds = array_map(fn($r)=>(int)$r['round_no'],$rows);
                sort($rounds);
                if ($rounds !== [1,2,3]) { throw new \RuntimeException("life_rounds_must_be_123 (life_id=$lid)"); }
                $choices = array_map(fn($r)=>$r['choice'],$rows);
                sort($choices);
                if ($choices !== ['AWAY','DRAW','HOME']) { // confronto set ordinato
                    return ['ok'=>false,'error'=>'invalid_choice_set','detail'=>['life_id'=>$lid,'choices'=>$choices]];
                }

                // salva/upsert
                foreach($rows as $r){
                    // verifica che event_id sia dell round corrispondente e del torneo
                    $ev=$pdo->prepare("SELECT id FROM tournament_flash_events WHERE id=? AND tournament_id=? AND round_no=? LIMIT 1");
                    $ev->execute([(int)$r['event_id'],$tid,(int)$r['round_no']]);
                    if(!$ev->fetchColumn()){
                        throw new \RuntimeException("event_mismatch (life_id=$lid,round={$r['round_no']},event={$r['event_id']})");
                    }

                    $ins=$pdo->prepare(
                      "INSERT INTO tournament_flash_picks (tournament_id,life_id,round_no,event_id,choice,created_at,locked_at,pick_code)
                       VALUES (?,?,?,?,?,NOW(),NULL,?)
                       ON DUPLICATE KEY UPDATE event_id=VALUES(event_id), choice=VALUES(choice)"
                    );
                    $ins->execute([$tid,$lid,(int)$r['round_no'],(int)$r['event_id'],$r['choice'], self::genCode(10)]);
                }
            }

            $pdo->commit();
            return ['ok'=>true,'saved'=>count($payload)];
        }catch(\Throwable $e){
            if($pdo->inTransaction()) $pdo->rollBack();
            return ['ok'=>false,'error'=>'submit_failed','where'=>'engine.core.submitPicks','detail'=>$e->getMessage()];
        }
    }

    /* ===== Calcolo round ===== */

    public static function computeRound(\PDO $pdo,int $tid,int $round): array {
        $tour=self::tourById($pdo,$tid);
        if(!$tour) return ['ok'=>false,'error'=>'bad_tournament'];
        if((int)$tour['current_round']!==$round && $tour['status']!=='locked'){
            return ['ok'=>false,'error'=>'bad_round_state','detail'=>"current_round={$tour['current_round']} vs req=$round"];
        }

        // carica evento del round
        $q=$pdo->prepare("SELECT * FROM tournament_flash_events WHERE tournament_id=? AND round_no=? LIMIT 1");
        $q->execute([$tid,$round]); $ev=$q->fetch(\PDO::FETCH_ASSOC);
        if(!$ev) return ['ok'=>false,'error'=>'no_event_for_round'];
        $outcome=strtoupper((string)($ev['result'] ?? 'UNKNOWN'));
        if($outcome==='UNKNOWN') return ['ok'=>false,'error'=>'results_missing','detail'=>"round=$round"];

        try{
            $pdo->beginTransaction();

            // vite alive al round R
            $st=$pdo->prepare("SELECT id,user_id FROM tournament_flash_lives WHERE tournament_id=? AND status='alive' AND `round`=?");
            $st->execute([$tid,$round]);
            $lives=$st->fetchAll(\PDO::FETCH_ASSOC);

            if (!$lives) {
                // nessuno da calcolare → semplicemente avanza il torneo
                $pdo->commit();
                return ['ok'=>true,'sealed_mode'=>'pick_lock','passed'=>0,'out'=>0,'next_round'=>$round+1];
            }

            $lifeIds=array_map(fn($r)=>(int)$r['id'],$lives);
            $in=implode(',',array_fill(0,count($lifeIds),'?'));

            $picksByLife=[]; // life_id=>choice
            $p=$pdo->prepare("SELECT life_id,choice FROM tournament_flash_picks WHERE tournament_id=? AND round_no=? AND life_id IN ($in)");
            $p->execute(array_merge([$tid,$round],$lifeIds));
            foreach($p->fetchAll(\PDO::FETCH_ASSOC) as $r){ $picksByLife[(int)$r['life_id']]=strtoupper($r['choice']); }

            $pass=[]; $out=[];

            $postpone = in_array($outcome,['POSTPONED','CANCELLED'],true);
            if ($postpone) {
                // tutti passano
                $pass = $lifeIds;
            } else {
                // calcola chi ha indovinato
                foreach($lives as $lv){
                    $lid=(int)$lv['id'];
                    $ch = $picksByLife[$lid] ?? null;
                    if ($ch!==null && $ch===$outcome) { $pass[]=$lid; }
                }
                // regola "nessuno indovina" => passano tutti
                if (count($pass)===0) { $pass = $lifeIds; }
                else {
                    // eliminati = alive - pass
                    $passSet=array_fill_keys($pass,true);
                    foreach($lifeIds as $lid){ if(!isset($passSet[$lid])) $out[]=$lid; }
                }
            }

            // applica esiti
            if ($out) {
                $in2=implode(',',array_fill(0,count($out),'?'));
                $pdo->prepare("UPDATE tournament_flash_lives SET status='out' WHERE id IN ($in2)")->execute($out);
            }
            if ($pass) {
                $in3=implode(',',array_fill(0,count($pass),'?'));
                $pdo->prepare("UPDATE tournament_flash_lives SET `round`=`round`+1, status='alive' WHERE id IN ($in3)")->execute($pass);
            }

            $pdo->commit();
            return ['ok'=>true,'sealed_mode'=>'pick_lock','passed'=>count($pass),'out'=>count($out),'next_round'=>$round+1];
        }catch(\Throwable $e){
            if($pdo->inTransaction()) $pdo->rollBack();
            return ['ok'=>false,'error'=>'calc_failed','where'=>'engine.core.computeRound','detail'=>$e->getMessage()];
        }
    }

    public static function publishNextRound(\PDO $pdo,int $tid,int $round): array {
        try{
            $u=$pdo->prepare("UPDATE tournament_flash SET current_round=GREATEST(current_round,?)+1, lock_at=NULL, status='published' WHERE id=?");
            $u->execute([$round,$tid]);
            $cur=$pdo->query("SELECT current_round FROM tournament_flash WHERE id=".(int)$tid." LIMIT 1")->fetchColumn();
            return ['ok'=>true,'current_round'=>(int)$cur];
        }catch(\Throwable $e){
            return ['ok'=>false,'error'=>'publish_next_failed','where'=>'engine.core.publishNextRound','detail'=>$e->getMessage()];
        }
    }
}
