<?php
declare(strict_types=1);

/**
 * TournamentFinalizer — chiusura torneo + payout + helper leaderboard.
 *
 * Requisiti minimi tabelle (autodetect colonne):
 *  - tournaments: id, code/tour_code/t_code/short_id, status (opzionale), current_round (opzionale),
 *                 buyin (opz.), guaranteed_prize (opz.), buyin_to_prize_pct (opz.), rake_pct (opz.),
 *                 closed_at/ended_at (opz.).
 *  - tournament_lives: id, tournament_id, user_id, status ('alive'/'out'), round (opz.)
 *  - tournament_picks: id, tournament_id (opz.), life_id, round
 *  - users: id, username(/display_name)
 *  - tournament_payouts (opz.): tournament_id, user_id, amount, admin_id, created_at
 */

final class TournamentFinalizer
{
    /* =======================
     *  Utilities & autodetect
     * ======================= */

    private static array $colCache = [];

    private static function columnExists(\PDO $pdo, string $table, string $col): bool {
      $st=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
      $st->execute([$table,$col]); return (bool)$st->fetchColumn();
    }

    private static function firstCol(\PDO $pdo, string $table, array $cands, string $fallback='NULL'): string {
      foreach ($cands as $c) if (self::columnExists($pdo,$table,$c)) return $c;
      return $fallback;
    }

    public static function resolveTournamentId(\PDO $pdo, int $id=0, ?string $code=null): int {
      $tT='tournaments'; $tId=self::firstCol($pdo,$tT,['id'],'id');
      $tCode=self::firstCol($pdo,$tT,['code','tour_code','t_code','short_id'],'NULL');
      if ($id>0) return $id;
      if ($code && $tCode!=='NULL') {
        $st=$pdo->prepare("SELECT $tId FROM $tT WHERE UPPER($tCode)=UPPER(?) LIMIT 1");
        $st->execute([$code]); $v=$st->fetchColumn(); return $v? (int)$v : 0;
      }
      return 0;
    }

    private static function map(\PDO $pdo): array {
      $tT='tournaments';
      $tId=self::firstCol($pdo,$tT,['id'],'id');
      $tCode=self::firstCol($pdo,$tT,['code','tour_code','t_code','short_id'],'NULL');
      $tSt=self::firstCol($pdo,$tT,['status','state'],'NULL');
      $tCR=self::firstCol($pdo,$tT,['current_round','round_current','round'],'NULL');
      $tClosed=self::firstCol($pdo,$tT,['closed_at','ended_at','finished_at'],'NULL');
      $tBuyin=self::firstCol($pdo,$tT,['buyin','fee'],'NULL');
      $tGtd=self::firstCol($pdo,$tT,['guaranteed_prize','prize_gtd','gtd'],'NULL');
      $tPct=self::firstCol($pdo,$tT,['buyin_to_prize_pct','to_prize_pct','prize_pct'],'NULL');
      $tRake=self::firstCol($pdo,$tT,['rake_pct','rake'],'NULL');

      $lT='tournament_lives';
      $lId=self::firstCol($pdo,$lT,['id'],'id');
      $lTid=self::firstCol($pdo,$lT,['tournament_id','tid'],'tournament_id');
      $lUid=self::firstCol($pdo,$lT,['user_id','uid'],'user_id');
      $lRnd=self::firstCol($pdo,$lT,['round','rnd'],'NULL');
      $lSt=self::firstCol($pdo,$lT,['status','state'],'status');

      $pT='tournament_picks';
      $pId=self::firstCol($pdo,$pT,['id'],'id');
      $pLid=self::firstCol($pdo,$pT,['life_id','lid'],'life_id');
      $pTid=self::firstCol($pdo,$pT,['tournament_id','tid'],'NULL');
      $pRnd=self::firstCol($pdo,$pT,['round','rnd'],'round');

      $uT='users';
      $uId=self::firstCol($pdo,$uT,['id'],'id');
      $uNm=self::firstCol($pdo,$uT,['username','name','fullname','display_name'],'username');
      $uAv=self::firstCol($pdo,$uT,['avatar','avatar_url','photo','picture'],'NULL');

      $payT='tournament_payouts';
      // non è garantita l'esistenza; se non c'è, i metodi la tratteranno come assente
      return compact('tT','tId','tCode','tSt','tCR','tClosed','tBuyin','tGtd','tPct','tRake',
                     'lT','lId','lTid','lUid','lRnd','lSt',
                     'pT','pId','pLid','pTid','pRnd',
                     'uT','uId','uNm','uAv','payT');
    }

    /* =======================
     *  Letture & calcoli base
     * ======================= */

    private static function tableExists(\PDO $pdo, string $t): bool {
      $st=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
      $st->execute([$t]); return (bool)$st->fetchColumn();
    }

    private static function aliveUsers(\PDO $pdo, int $tid, array $m): array {
      $sql = "SELECT DISTINCT $m[lUid] AS user_id
                FROM $m[lT]
               WHERE $m[lTid]=? AND LOWER($m[lSt])='alive'";
      $st=$pdo->prepare($sql); $st->execute([$tid]);
      return array_map('intval',$st->fetchAll(\PDO::FETCH_COLUMN));
    }

    private static function usernameOf(\PDO $pdo, int $uid, array $m): ?string {
      $st=$pdo->prepare("SELECT $m[uNm] FROM $m[uT] WHERE $m[uId]=? LIMIT 1");
      $st->execute([$uid]); $v=$st->fetchColumn();
      return ($v!==false && $v!=='') ? (string)$v : null;
    }

    private static function countLives(\PDO $pdo, int $tid, array $m): int {
      $st=$pdo->prepare("SELECT COUNT(*) FROM $m[lT] WHERE $m[lTid]=?");
      $st->execute([$tid]); return (int)$st->fetchColumn();
    }

    private static function lastRoundFromPicks(\PDO $pdo, int $tid, array $m): int {
      $whereTid = ($m['pTid']!=='NULL') ? "$m[pTid]=?" : "1=1";
      $st=$pdo->prepare("SELECT COALESCE(MAX($m[pRnd]),1) FROM $m[pT] WHERE $whereTid");
      $st->execute(($m['pTid']!=='NULL')?[$tid]:[]);
      $r=(int)$st->fetchColumn(); return max(1,$r);
    }

    private static function effectivePool(\PDO $pdo, int $tid, array $m): float {
      // Legge dai campi noti; se mancano → 0
      $st=$pdo->prepare("SELECT "
        . ($m['tBuyin']!=='NULL' ? "$m[tBuyin]" : "NULL")
        . ", "
        . ($m['tGtd']!=='NULL'   ? "$m[tGtd]"   : "NULL")
        . ", "
        . ($m['tPct']!=='NULL'   ? "$m[tPct]"   : "NULL")
        . ", "
        . ($m['tRake']!=='NULL'  ? "$m[tRake]"  : "NULL")
        . " FROM $m[tT] WHERE $m[tId]=? LIMIT 1");
      $st->execute([$tid]);
      [$buyin,$gtd,$pct,$rake] = $st->fetch(\PDO::FETCH_NUM) ?: [null,null,null,null];
      $buyin = $buyin!==null ? (float)$buyin : 0.0;
      $gtd   = $gtd!==null   ? (float)$gtd   : 0.0;
      $pct   = $pct!==null   ? (float)$pct   : 0.0;
      $rake  = $rake!==null  ? (float)$rake  : 0.0;

      $lives = self::countLives($pdo,$tid,$m);
      $gross = $buyin * $lives;
      $poolVar = $gross * ($pct/100.0);
      $pool = max($gtd, $poolVar);
      // il rake è già escluso dal pct in molti modelli; se vuoi sottrarlo, scommenta:
      // $pool = $pool * max(0.0, 1.0 - ($rake/100.0));
      return round($pool, 2);
    }

    /* ==================
     *  API di alto livello
     * ================== */

    public static function shouldEndTournament(\PDO $pdo, int $tournamentId): array {
      $m=self::map($pdo);

      $alive = self::aliveUsers($pdo,$tournamentId,$m);
      $aliveN = count($alive);
      $r = ($m['tCR']!=='NULL')
           ? (int)($pdo->query("SELECT COALESCE($m[tCR],1) FROM $m[tT] WHERE $m[tId]=".(int)$tournamentId." LIMIT 1")->fetchColumn())
           : self::lastRoundFromPicks($pdo,$tournamentId,$m);

      if ($aliveN < 2) {
        return ['should_end'=>true,'reason'=>'alive_lt_2','alive_users'=>$aliveN,'round'=>$r];
      }
      // opzionale: se non ci sono eventi futuri potresti considerare la fine; qui non servono altre regole
      return ['should_end'=>false,'reason'=>'alive_ge_2','alive_users'=>$aliveN,'round'=>$r];
    }

    public static function buildLeaderboard(\PDO $pdo, int $tournamentId, array $winnerIds=[]): array {
      $m=self::map($pdo);
      // Punteggio semplice: vite ancora vive conteggiate (proxy). In mancanza di round/score dedichiamo ranking semplice.
      $sql = "SELECT $m[lUid] AS user_id, SUM(CASE WHEN LOWER($m[lSt])='alive' THEN 1 ELSE 0 END) AS score
                FROM $m[lT]
               WHERE $m[lTid]=?
               GROUP BY $m[lUid]
               ORDER BY score DESC, $m[lUid] ASC
               LIMIT 10";
      $st=$pdo->prepare($sql); $st->execute([$tournamentId]);
      $rows=$st->fetchAll(\PDO::FETCH_ASSOC) ?: [];

      $out=[];
      foreach ($rows as $r) {
        $uid=(int)$r['user_id']; $score=(int)$r['score'];
        $out[]=[
          'user_id'=>$uid,
          'username'=> self::usernameOf($pdo,$uid,$m),
          'score'=>$score,
          'is_winner'=> in_array($uid,$winnerIds,true),
        ];
      }
      return $out;
    }

    public static function finalizeTournament(\PDO $pdo, int $tournamentId, int $adminId): array {
      $m=self::map($pdo);

      // Già chiuso?
      if ($m['tSt']!=='NULL') {
        $st=$pdo->prepare("SELECT $m[tSt] FROM $m[tT] WHERE $m[tId]=? LIMIT 1");
        $st->execute([$tournamentId]);
        $stVal = (string)($st->fetchColumn() ?? '');
        if ($stVal!=='' && strtolower($stVal)==='closed') {
          return ['ok'=>true,'result'=>'already_closed','winners'=>[],'pool'=>0];
        }
      }

      // Calcola winners (utenti con almeno una vita viva)
      $alive = self::aliveUsers($pdo,$tournamentId,$m);
      $winners = $alive;

      // Se nessuno vivo, prendi gli utenti con max round raggiunto (tie): top group
      if (!$winners) {
        $whereRnd = ($m['lRnd']!=='NULL') ? "$m[lRnd]" : "0";
        $st=$pdo->prepare("SELECT $m[lUid] AS user_id, MAX($whereRnd) AS mr
                             FROM $m[lT] WHERE $m[lTid]=?
                             GROUP BY $m[lUid]
                             ORDER BY mr DESC");
        $st->execute([$tournamentId]);
        $rows=$st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        if ($rows) {
          $best = (int)$rows[0]['mr'];
          foreach ($rows as $r) if ((int)$r['mr']===$best) $winners[]=(int)$r['user_id'];
          $winners = array_values(array_unique($winners));
        }
      }

      if (!$winners) {
        // fallback estremo: nessun partecipante
        return ['ok'=>false,'error'=>'no_winners_detected','detail'=>'Impossibile determinare vincitori'];
      }

      $pool = self::effectivePool($pdo,$tournamentId,$m);
      $perHead = count($winners) ? round($pool / count($winners), 2) : 0.0;

      // transazione
      $pdo->beginTransaction();
      try{
        // Inserisci payouts se tabella esiste
        if (self::tableExists($pdo,$m['payT'])) {
          $insertCols = ['tournament_id','user_id','amount'];
          if (self::columnExists($pdo,$m['payT'],'admin_id'))   $insertCols[]='admin_id';
          if (self::columnExists($pdo,$m['payT'],'created_at')) $insertCols[]='created_at';
          $colsStr = implode(',', $insertCols);
          $place   = implode(',', array_fill(0,count($insertCols),'?'));
          $sql = "INSERT INTO $m[payT] ($colsStr) VALUES ($place)";
          $ins=$pdo->prepare($sql);
          foreach ($winners as $uid) {
            $vals = [$tournamentId, $uid, $perHead];
            if (in_array('admin_id', $insertCols, true))   $vals[]=$adminId;
            if (in_array('created_at', $insertCols, true)) $vals[]=date('Y-m-d H:i:s');
            $ins->execute($vals);
          }
        }

        // Aggiorna stato torneo a closed + timestamp
        $sets=[]; $pars=[];
        if ($m['tSt']!=='NULL') { $sets[]="$m[tSt]=?";  $pars[]='closed'; }
        if ($m['tClosed']!=='NULL') { $sets[]="$m[tClosed]=NOW()"; }
        if ($sets) {
          $sql="UPDATE $m[tT] SET ".implode(',', $sets)." WHERE $m[tId]=?"; $pars[]=$tournamentId;
          $pdo->prepare($sql)->execute($pars);
        }

        $pdo->commit();

        // arricchisci winners con username
        $winRows=[];
        foreach ($winners as $uid) {
          $winRows[]=[
            'user_id'=>$uid,
            'username'=> self::usernameOf($pdo,$uid,$m),
            'amount'=>$perHead
          ];
        }

        $leaderTop = self::buildLeaderboard($pdo,$tournamentId,$winners);

        return [
          'ok'=>true,
          'result'=>'closed',
          'pool'=>$pool,
          'winners'=>$winRows,
          'leaderboard_top10'=>$leaderTop
        ];

      }catch(\Throwable $e){
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok'=>false,'error'=>'finalize_error','detail'=>$e->getMessage()];
      }
    }

    public static function userNotice(\PDO $pdo, int $tournamentId, int $userId): array {
      $m=self::map($pdo);
      // prova a leggere dal payout (se esiste)
      if (!self::tableExists($pdo,$m['payT'])) {
        return ['ok'=>true,'is_winner'=>false];
      }
      $st=$pdo->prepare("SELECT SUM(amount) FROM $m[payT] WHERE tournament_id=? AND user_id=?");
      $st->execute([$tournamentId,$userId]);
      $amt = (float)($st->fetchColumn() ?: 0);
      if ($amt>0) {
        return ['ok'=>true,'is_winner'=>true,'amount'=>$amt];
      }
      return ['ok'=>true,'is_winner'=>false];
    }
}
