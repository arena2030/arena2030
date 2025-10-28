<?php
/**
 * NormalLifeCyclePolicy (torneo normale) — FIX V4
 * ------------------------------------------------------------
 * Migliorie rispetto a V3 per risolvere il caso "tutto disabilitato al Round 1":
 *  - Universe sempre costruito come **unione degli eventi** del torneo
 *    (eventuale `tournament_teams` viene unito se presente), così non rischia di essere vuoto/incompleto.
 *  - Lock handling coerente con il core: se esiste il lock a livello di **pick**,
 *    la policy **ignora** `events.is_locked/locked_at` nella pickability (prima del sigillo).
 *    Questo evita falsi lock quando `is_locked` ha default a 1.
 *  - Mapping **dinamico** sia per `tournament_events` che per `tournament_picks`.
 *
 * Regole enforce (immutate):
 *  - Ciclo principale: non puoi ripetere una squadra con la stessa vita
 *    finché non hai scelto tutte le squadre dell'universo U (snapshot per ciclo).
 *  - Sottociclo: se nel round non esiste alcuna squadra "nuova" pickabile,
 *    puoi ripetere, ma NON la stessa squadra due volte nello stesso sottociclo.
 *
 * Persistenza anti‑manomissione:
 *  - normal_life_cycles, normal_life_cycle_universe, normal_life_cycle_used_teams,
 *    normal_life_cycle_subcycles, normal_life_cycle_subcycle_teams.
 *
 * PHP >=7.4
 */
class NormalLifeCyclePolicy
{
    /** @var \PDO */
    private $pdo;

    /** Tabelle policy */
    private $tblCycles    = 'normal_life_cycles';
    private $tblUniverse  = 'normal_life_cycle_universe';
    private $tblUsed      = 'normal_life_cycle_used_teams';
    private $tblSubcycles = 'normal_life_cycle_subcycles';
    private $tblSubTeams  = 'normal_life_cycle_subcycle_teams';

    /** Tabelle progetto */
    private $tblLives           = 'tournament_lives';
    private $tblEvents          = 'tournament_events';
    private $tblPicks           = 'tournament_picks';
    private $tblTournaments     = 'tournaments';
    private $tblTournamentTeams = 'tournament_teams'; // opzionale

    /** Mapping cache */
    private $evMap = null;
    private $pkMap = null;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /* =============================
     *  API esterne usate dal Core
     * ============================= */

    public function validateUnique(int $tournamentId, int $lifeId, int $round, int $teamId): array
    {
        // Vita valida e viva
        $life = $this->fetchOne("SELECT id, tournament_id, status FROM {$this->tblLives} WHERE id = :lifeId", [
            ':lifeId' => $lifeId
        ]);
        if (!$life || (int)$life['tournament_id'] !== $tournamentId) {
            return $this->fail('life_not_in_tournament', 'Vita non trovata o non appartiene al torneo.');
        }
        if (isset($life['status']) && $life['status'] !== 'alive') {
            return $this->fail('life_not_alive', 'La vita non è attiva.');
        }

        // Ciclo attivo + universe congelato (se mancante)
        $cycle = $this->ensureActiveCycle($tournamentId, $lifeId);
        $lifeCycleId = (int)$cycle['id'];

        // Universe U
        $U = $this->getUniverseTeams($lifeCycleId);
        if (!in_array($teamId, $U, true)) {
            return $this->fail('team_not_in_universe', 'La squadra non fa parte del set del torneo (ciclo).');
        }

        // F = squadre già usate nel ciclo (da pick sigillate)
        $F = $this->getUsedTeams($lifeCycleId);

        // P = pickabili ORA nel round (considerando lock corrente)
        $P = $this->getPickableTeams($tournamentId, $round, /*ignoreLock*/ false);

        // Ci sono "nuove" disponibili?
        $freshCandidates = array_values(array_diff($P, $F));

        if (count($freshCandidates) > 0) {
            // Devi scegliere "nuova"
            if (in_array($teamId, $F, true)) {
                return $this->fail('must_pick_fresh_team', 'Devi scegliere una squadra non ancora usata nel ciclo.');
            }
            return $this->ok();
        }

        // Sottociclo (nessuna nuova pickabile)
        $subInfo = $this->getOpenSubcycle($lifeCycleId);
        if (!$subInfo) {
            // Sottociclo si aprirà al sigillo se confermano
            return $this->ok();
        }

        // Dentro sottociclo: non ripetere la stessa squadra due volte
        $D = $this->getSubcycleTeams($lifeCycleId, (int)$subInfo['subcycle_no']);
        if (in_array($teamId, $D, true)) {
            return $this->fail('already_used_in_subcycle', 'Questa squadra è già stata usata in questo sottociclo.');
        }

        return $this->ok();
    }

    public function onSealRound(int $tournamentId, int $round): void
    {
        // Mappa colonne pick
        $pk = $this->mapPicks();

        // Tutte le pick sigillate per torneo/round
        $rows = $this->fetchAll(
            "SELECT p.{$pk['id']} AS pick_id, p.{$pk['life']} AS life_id, p.{$pk['team']} AS team_id
               FROM {$this->tblPicks} p
               JOIN {$this->tblLives} l ON l.id = p.{$pk['life']}
              WHERE l.tournament_id = :tid AND p.{$pk['round']} = :rnd AND p.{$pk['lock']} IS NOT NULL",
            [':tid' => $tournamentId, ':rnd' => $round]
        );

        foreach ($rows as $r) {
            $this->sealOnePick($tournamentId, (int)$r['life_id'], $round, (int)$r['team_id']);
        }
    }

    /* =============================
     *  Implementazione di dettaglio
     * ============================= */

    private function sealOnePick(int $tournamentId, int $lifeId, int $round, int $teamId): void
    {
        $cycle = $this->ensureActiveCycle($tournamentId, $lifeId);
        $lifeCycleId = (int)$cycle['id'];

        $U = $this->getUniverseTeams($lifeCycleId);
        if (!in_array($teamId, $U, true)) {
            throw new \RuntimeException('team_not_in_universe');
        }

        $F = $this->getUsedTeams($lifeCycleId);
        $fresh = !in_array($teamId, $F, true);

        // P (al sigillo) ma ignorando lock per capire se nuove esistevano
        $P = $this->getPickableTeams($tournamentId, $round, /*ignoreLock*/ true);
        $freshCandidates = array_values(array_diff($P, $F));

        $inSub = (int)$cycle['in_subcycle'] === 1;
        $subNo = $cycle['subcycle_no'] !== null ? (int)$cycle['subcycle_no'] : null;

        if (count($freshCandidates) > 0) {
            // Deve essere "nuova"
            if (!$fresh) {
                throw new \RuntimeException('must_pick_fresh_team');
            }
            // Chiudi sottociclo se aperto
            if ($inSub && $subNo !== null) {
                $this->exec("UPDATE {$this->tblSubcycles}
                                SET closed_at = NOW()
                              WHERE life_cycle_id = :lc AND subcycle_no = :sn AND closed_at IS NULL",
                    [':lc' => $lifeCycleId, ':sn' => $subNo]);
                $this->exec("UPDATE {$this->tblCycles}
                                SET in_subcycle = 0, subcycle_no = NULL, updated_at = NOW()
                              WHERE id = :lc",
                    [':lc' => $lifeCycleId]);
                $inSub = false; $subNo = null;
            }
            // Registra "prima volta"
            $this->exec("INSERT IGNORE INTO {$this->tblUsed}
                           (life_cycle_id, team_id, first_round, sealed_at)
                         VALUES (:lc, :tm, :rd, NOW())",
                [':lc' => $lifeCycleId, ':tm' => $teamId, ':rd' => $round]);
        } else {
            // Nessuna nuova → sottociclo
            if (!$inSub) {
                $nextSub = (int)$this->fetchValue(
                    "SELECT COALESCE(MAX(subcycle_no),0)+1 FROM {$this->tblSubcycles} WHERE life_cycle_id = :lc",
                    [':lc' => $lifeCycleId]
                );
                $this->exec("INSERT INTO {$this->tblSubcycles}(life_cycle_id, subcycle_no, opened_at)
                             VALUES (:lc, :sn, NOW())",
                    [':lc' => $lifeCycleId, ':sn' => $nextSub]);
                $this->exec("UPDATE {$this->tblCycles}
                                SET in_subcycle = 1, subcycle_no = :sn, updated_at = NOW()
                              WHERE id = :lc",
                    [':sn' => $nextSub, ':lc' => $lifeCycleId]);
                $inSub = true; $subNo = $nextSub;
            }

            // Dentro sottociclo non ripetere stessa squadra
            $this->exec("INSERT INTO {$this->tblSubTeams}
                           (life_cycle_id, subcycle_no, team_id, round, sealed_at)
                         VALUES (:lc, :sn, :tm, :rd, NOW())",
                [':lc' => $lifeCycleId, ':sn' => $subNo, ':tm' => $teamId, ':rd' => $round]);
        }

        // Reset ciclo quando F == U
        $usedCount = (int)$this->fetchValue(
            "SELECT COUNT(*) FROM {$this->tblUsed} WHERE life_cycle_id = :lc",
            [':lc' => $lifeCycleId]
        );
        $universeSize = (int)$this->fetchValue(
            "SELECT COUNT(*) FROM {$this->tblUniverse} WHERE life_cycle_id = :lc",
            [':lc' => $lifeCycleId]
        );

        if ($universeSize > 0 && $usedCount >= $universeSize) {
            $this->exec("UPDATE {$this->tblCycles}
                            SET completed_at = NOW(), in_subcycle = 0, subcycle_no = NULL, updated_at = NOW()
                          WHERE id = :lc AND completed_at IS NULL",
                [':lc' => $lifeCycleId]);
        }
    }

    /* --------------- Universe & pickability --------------- */

    private function ensureActiveCycle(int $tournamentId, int $lifeId): array
    {
        $cycle = $this->fetchOne(
            "SELECT * FROM {$this->tblCycles}
              WHERE life_id = :lf AND tournament_id = :tid AND completed_at IS NULL
              ORDER BY cycle_no DESC LIMIT 1",
            [':lf' => $lifeId, ':tid' => $tournamentId]
        );

        if ($cycle) {
            $exists = (int)$this->fetchValue(
                "SELECT COUNT(*) FROM {$this->tblUniverse} WHERE life_cycle_id = :lc",
                [':lc' => $cycle['id']]
            );
            if ($exists === 0) {
                $this->freezeUniverse((int)$cycle['id'], $tournamentId);
            }
            return $cycle;
        }

        // Crea nuovo ciclo
        $next = (int)$this->fetchValue(
            "SELECT COALESCE(MAX(cycle_no),0)+1 FROM {$this->tblCycles}
              WHERE life_id = :lf AND tournament_id = :tid",
            [':lf' => $lifeId, ':tid' => $tournamentId]
        );

        $this->exec(
            "INSERT INTO {$this->tblCycles}(life_id, tournament_id, cycle_no, in_subcycle, started_at, updated_at)
             VALUES (:lf, :tid, :cn, 0, NOW(), NOW())",
            [':lf' => $lifeId, ':tid' => $tournamentId, ':cn' => $next]
        );

        $id = (int)$this->pdo->lastInsertId();
        $this->freezeUniverse($id, $tournamentId);

        return $this->fetchOne("SELECT * FROM {$this->tblCycles} WHERE id = :id", [':id' => $id]);
    }

    private function freezeUniverse(int $lifeCycleId, int $tournamentId): void
    {
        $teams = $this->computeUniverseFromTournament($tournamentId);
        if (!$teams) return;

        $stmt = $this->pdo->prepare(
            "INSERT IGNORE INTO {$this->tblUniverse}(life_cycle_id, team_id) VALUES (:lc, :tm)"
        );
        foreach ($teams as $tm) {
            $stmt->execute([':lc' => $lifeCycleId, ':tm' => (int)$tm]);
        }
    }

    private function computeUniverseFromTournament(int $tournamentId): array
    {
        // 1) Unione di tutte le squadre apparse negli eventi del torneo
        $ev = $this->mapEvents();
        $evRows = $this->fetchAll(
            "SELECT {$ev['home']} AS tid FROM {$this->tblEvents} WHERE {$ev['tid']} = :tid
             UNION
             SELECT {$ev['away']} AS tid FROM {$this->tblEvents} WHERE {$ev['tid']} = :tid",
            [':tid' => $tournamentId]
        );
        $eventSet = array_values(array_unique(array_map('intval', array_column($evRows, 'tid'))));

        // 2) Se esiste una tabella dedicata, uniscila (quando presente)
        $teamSet = [];
        if ($this->tableExists($this->tblTournamentTeams) && $this->columnExists($this->tblTournamentTeams, 'team_id')) {
            $ttRows = $this->fetchAll(
                "SELECT DISTINCT team_id FROM {$this->tblTournamentTeams} WHERE tournament_id = :tid",
                [':tid' => $tournamentId]
            );
            $teamSet = array_values(array_unique(array_map('intval', array_column($ttRows, 'team_id'))));
        }

        // 3) Universo = unione insiemistica (mai vuoto se esistono eventi)
        return array_values(array_unique(array_merge($eventSet, $teamSet)));
    }

    private function getUniverseTeams(int $lifeCycleId): array
    {
        $rows = $this->fetchAll(
            "SELECT team_id FROM {$this->tblUniverse} WHERE life_cycle_id = :lc",
            [':lc' => $lifeCycleId]
        );
        return array_map('intval', array_column($rows, 'team_id'));
    }

    private function getUsedTeams(int $lifeCycleId): array
    {
        $rows = $this->fetchAll(
            "SELECT team_id FROM {$this->tblUsed} WHERE life_cycle_id = :lc",
            [':lc' => $lifeCycleId]
        );
        return array_map('intval', array_column($rows, 'team_id'));
    }

    private function getPickableTeams(int $tournamentId, int $round, bool $ignoreLock): array
    {
        $ev = $this->mapEvents();
        $pk = $this->mapPicks();
        $sealMode = $pk['lock'] ? 'pick_lock' : ($ev['is_locked'] ? 'event_lock' : 'tour_lock');

        $conds = ["{$ev['tid']} = :tid", "{$ev['round']} = :rnd"];

        // Applica lock solo quando serve
        if (!$ignoreLock) {
            if ($sealMode === 'event_lock') {
                if ($ev['is_locked']) {
                    $conds[] = "({$ev['is_locked']} IS NULL OR {$ev['is_locked']} = 0)";
                } elseif ($ev['locked_at']) {
                    $conds[] = "{$ev['locked_at']} IS NULL";
                }
            }
            // In pick_lock pre-sigillo ignoriamo il lock sugli eventi (è il comportamento del core)
        }

        // Eventi annullati/posticipati/void fuori dalle pickabili
        if ($ev['status']) {
            $conds[] = "COALESCE({$ev['status']},'') NOT IN ('CANCELLED','POSTPONED','VOID')";
        } elseif ($ev['result']) {
            $conds[] = "COALESCE({$ev['result']},'') NOT IN ('CANCELLED','POSTPONED','VOID')";
        }
        if ($ev['is_cancelled']) {
            $conds[] = "({$ev['is_cancelled']} IS NULL OR {$ev['is_cancelled']} = 0)";
        }

        $where = implode(' AND ', $conds);
        $sql = "SELECT {$ev['home']} AS tid FROM {$this->tblEvents} WHERE {$where}
                UNION
                SELECT {$ev['away']} AS tid FROM {$this->tblEvents} WHERE {$where}";

        $rows = $this->fetchAll($sql, [':tid' => $tournamentId, ':rnd' => $round]);
        return array_values(array_unique(array_map('intval', array_column($rows, 'tid'))));
    }

    /* --------------- Subcycle helpers --------------- */

    private function getOpenSubcycle(int $lifeCycleId): ?array
    {
        $row = $this->fetchOne(
            "SELECT c.in_subcycle, c.subcycle_no, s.opened_at, s.closed_at
               FROM {$this->tblCycles} c
               LEFT JOIN {$this->tblSubcycles} s
                 ON s.life_cycle_id = c.id AND s.subcycle_no = c.subcycle_no
              WHERE c.id = :lc",
            [':lc' => $lifeCycleId]
        );
        if (!$row) return null;
        if ((int)$row['in_subcycle'] === 1 && $row['subcycle_no'] !== null && $row['closed_at'] === null) {
            return [
                'subcycle_no' => (int)$row['subcycle_no'],
                'opened_at'   => $row['opened_at']
            ];
        }
        return null;
    }

    private function getSubcycleTeams(int $lifeCycleId, int $subcycleNo): array
    {
        $rows = $this->fetchAll(
            "SELECT team_id FROM {$this->tblSubTeams}
              WHERE life_cycle_id = :lc AND subcycle_no = :sn",
            [':lc' => $lifeCycleId, ':sn' => $subcycleNo]
        );
        return array_map('intval', array_column($rows, 'team_id'));
    }

    /* --------------- Mapping dinamico (events & picks) --------------- */

    private function mapEvents(): array
    {
        if ($this->evMap !== null) return $this->evMap;

        $home = $this->pickCol($this->tblEvents, ['home_team_id','home_id','team_home_id'], 'home_team_id');
        $away = $this->pickCol($this->tblEvents, ['away_team_id','away_id','team_away_id'], 'away_team_id');
        $tid  = $this->pickCol($this->tblEvents, ['tournament_id','tid'], 'tournament_id');
        $round= $this->pickCol($this->tblEvents, ['round','rnd'], 'round');

        $is_locked   = $this->columnExists($this->tblEvents, 'is_locked') ? 'is_locked' : null;
        $locked_at   = $this->columnExists($this->tblEvents, 'locked_at') ? 'locked_at' : null;
        $status      = $this->columnExists($this->tblEvents, 'status') ? 'status' : null;
        $result      = $this->columnExists($this->tblEvents, 'result') ? 'result' : null;
        $is_cancelled= $this->columnExists($this->tblEvents, 'is_cancelled') ? 'is_cancelled' : null;

        return $this->evMap = compact('home','away','tid','round','is_locked','locked_at','status','result','is_cancelled');
    }

    private function mapPicks(): array
    {
        if ($this->pkMap !== null) return $this->pkMap;

        $id    = $this->pickCol($this->tblPicks, ['id'], 'id');
        $life  = $this->pickCol($this->tblPicks, ['life_id','lid'], 'life_id');
        $round = $this->pickCol($this->tblPicks, ['round','rnd'], 'round');
        $team  = $this->pickCol($this->tblPicks, ['team_id','choice','team_choice','pick_team_id','team','squadra_id','teamid','teamID'], 'team_id');
        $lock  = $this->pickCol($this->tblPicks, ['locked_at','sealed_at','confirmed_at','finalized_at','lock_at'], 'locked_at');

        return $this->pkMap = compact('id','life','round','team','lock');
    }

    private function pickCol(string $table, array $cands, string $fallback): string
    {
        foreach ($cands as $c) {
            if ($this->columnExists($table, $c)) return $c;
        }
        return $fallback;
    }

    /* --------------- DB primitives --------------- */

    private function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function fetchValue(string $sql, array $params = [])
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    private function exec(string $sql, array $params = []): void
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    private function ok(): array
    {
        return ['ok' => true, 'code' => null, 'message' => null];
    }

    private function fail(string $code, string $msg): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $msg];
    }

    private function tableExists(string $table): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = :t AND TABLE_SCHEMA = DATABASE()"
            );
            $stmt->execute([':t' => $table]);
            return (bool)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = :t AND COLUMN_NAME = :c AND TABLE_SCHEMA = DATABASE()"
            );
            $stmt->execute([':t' => $table, ':c' => $column]);
            return (bool)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
