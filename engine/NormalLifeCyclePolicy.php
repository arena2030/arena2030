<?php
/**
 * NormalLifeCyclePolicy (torneo normale)
 * Regole:
 *  - Ciclo principale: con la stessa vita non puoi ripetere una squadra
 *    finché non hai scelto TUTTE le squadre del torneo (universo U).
 *  - Sottociclo: se in un round non esiste alcuna squadra "nuova" pickabile
 *    (P \ F == ∅), puoi ripetere, ma NON la stessa squadra due volte nel
 *    medesimo sottociclo.
 *
 * Persistenza:
 *  - Tabelle dedicate (vedi SQL) per stato/audit a prova di manomissione.
 *  - Lo stato "definitivo" si registra al sigillo del round (onSealRound).
 *  - La validazione pre-pick è life-safe, ma non scrive su DB.
 */
class NormalLifeCyclePolicy
{
    /** @var \PDO */
    private $pdo;

    /** Tabelle policy (puoi cambiare i nomi se hai un prefisso) */
    private $tblCycles    = 'normal_life_cycles';
    private $tblUniverse  = 'normal_life_cycle_universe';
    private $tblUsed      = 'normal_life_cycle_used_teams';
    private $tblSubcycles = 'normal_life_cycle_subcycles';
    private $tblSubTeams  = 'normal_life_cycle_subcycle_teams';

    /** Tabelle esistenti del progetto (nomi standard) */
    private $tblLives        = 'tournament_lives';
    private $tblEvents       = 'tournament_events';
    private $tblPicks        = 'tournament_picks';
    private $tblTournaments  = 'tournaments';
    private $tblTournamentTeams = 'tournament_teams'; // opzionale

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Validazione PRIMA del salvataggio pick (non muta stato).
     * Ritorna: ['ok'=>bool, 'code'=>?string, 'message'=>?string]
     */
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

        // Universe U
        $U = $this->getUniverseTeams((int)$cycle['id']);
        if (!in_array($teamId, $U, true)) {
            return $this->fail('team_not_in_universe', 'La squadra non fa parte del set del torneo (ciclo).');
        }

        // F = squadre già usate nel ciclo (da pick sigillate)
        $F = $this->getUsedTeams((int)$cycle['id']);

        // P = pickabili ORA nel round (considerando il lock corrente)
        $P = $this->getPickableTeams($tournamentId, $round, /*ignoreLock*/ false);

        // ci sono "nuove" disponibili?
        $freshCandidates = array_values(array_diff($P, $F));

        if (count($freshCandidates) > 0) {
            // Devi scegliere "nuova"
            if (in_array($teamId, $F, true)) {
                return $this->fail('must_pick_fresh_team', 'Devi scegliere una squadra non ancora usata nel ciclo.');
            }
            return $this->ok();
        }

        // Sottociclo (nessuna nuova pickabile)
        $subInfo = $this->getOpenSubcycle((int)$cycle['id']);
        if (!$subInfo) {
            // Sottociclo si aprirà al sigillo se confermano
            return $this->ok();
        }

        // Se sottociclo già aperto: dentro il sottociclo non ripetere la stessa squadra
        $D = $this->getSubcycleTeams((int)$cycle['id'], (int)$subInfo['subcycle_no']);
        if (in_array($teamId, $D, true)) {
            return $this->fail('already_used_in_subcycle', 'Questa squadra è già stata usata in questo sottociclo.');
        }

        return $this->ok();
    }

    /**
     * Consolidamento al sigillo del round (DEVE essere chiamato dopo lock/locked_at).
     * - Registra "first-use" nel ciclo (F).
     * - Apre/chiude sottociclo.
     * - Completa e resetta il ciclo quando F == U.
     * Lancia \RuntimeException su violazioni (per rollback transazione a monte).
     */
    public function onSealRound(int $tournamentId, int $round): void
    {
        // Tutte le pick sigillate per torneo/round
        $rows = $this->fetchAll(
            "SELECT p.id AS pick_id, p.life_id, p.team_id
               FROM {$this->tblPicks} p
               JOIN {$this->tblLives} l ON l.id = p.life_id
              WHERE l.tournament_id = :tid AND p.round = :rnd AND p.locked_at IS NOT NULL",
            [':tid' => $tournamentId, ':rnd' => $round]
        );

        foreach ($rows as $r) {
            $this->sealOnePick($tournamentId, (int)$r['life_id'], $round, (int)$r['team_id']);
        }
    }

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

        // P al momento del sigillo, ma IGNORANDO il lock per capire se "nuova" era teoricamente disponibile
        $P = $this->getPickableTeams($tournamentId, $round, /*ignoreLock*/ true);
        $freshCandidates = array_values(array_diff($P, $F));

        $inSub = (int)$cycle['in_subcycle'] === 1;
        $subNo = $cycle['subcycle_no'] !== null ? (int)$cycle['subcycle_no'] : null;

        if (count($freshCandidates) > 0) {
            // In presenza di nuove disponibili, la pick DEVE essere "nuova"
            if (!$fresh) {
                throw new \RuntimeException('must_pick_fresh_team');
            }
            // Se c'era un sottociclo aperto, chiudilo
            if ($inSub && $subNo !== null) {
                $this->exec("UPDATE {$this->tblSubcycles}
                                SET closed_at = NOW()
                              WHERE life_cycle_id = :lc AND subcycle_no = :sn AND closed_at IS NULL",
                    [':lc' => $lifeCycleId, ':sn' => $subNo]);
                $this->exec("UPDATE {$this->tblCycles}
                                SET in_subcycle = 0, subcycle_no = NULL, updated_at = NOW()
                              WHERE id = :lc", [':lc' => $lifeCycleId]);
                $inSub = false; $subNo = null;
            }
            // Registra "prima volta" per questa squadra nel ciclo
            $this->exec("INSERT IGNORE INTO {$this->tblUsed}
                           (life_cycle_id, team_id, first_round, sealed_at)
                         VALUES (:lc, :tm, :rd, NOW())",
                [':lc' => $lifeCycleId, ':tm' => $teamId, ':rd' => $round]);
        } else {
            // Nessuna nuova disponibile -> sottociclo
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

            // Dentro sottociclo: non ripetere la stessa squadra due volte
            $this->exec("INSERT INTO {$this->tblSubTeams}
                           (life_cycle_id, subcycle_no, team_id, round, sealed_at)
                         VALUES (:lc, :sn, :tm, :rd, NOW())",
                [':lc' => $lifeCycleId, ':sn' => $subNo, ':tm' => $teamId, ':rd' => $round]);
            // Se è un duplicato nella stessa sequenza, la PK fa fallire ⇒ rollback a monte
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

    // -------------------- Helpers stato/universe --------------------

    private function ensureActiveCycle(int $tournamentId, int $lifeId): array
    {
        $cycle = $this->fetchOne(
            "SELECT * FROM {$this->tblCycles}
              WHERE life_id = :lf AND tournament_id = :tid AND completed_at IS NULL
              ORDER BY cycle_no DESC LIMIT 1",
            [':lf' => $lifeId, ':tid' => $tournamentId]
        );

        if ($cycle) {
            // Assicura universe congelato
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
        // Preferisci tabella dedicata se esiste
        if ($this->tableExists($this->tblTournamentTeams)) {
            $rows = $this->fetchAll(
                "SELECT DISTINCT team_id FROM {$this->tblTournamentTeams} WHERE tournament_id = :tid",
                [':tid' => $tournamentId]
            );
            return array_values(array_unique(array_map('intval', array_column($rows, 'team_id'))));
        }

        // Fallback: unione home/away su tutti gli eventi del torneo
        $rows = $this->fetchAll(
            "SELECT home_team_id AS tid FROM {$this->tblEvents} WHERE tournament_id = :tid
             UNION
             SELECT away_team_id AS tid FROM {$this->tblEvents} WHERE tournament_id = :tid",
            [':tid' => $tournamentId]
        );
        return array_values(array_unique(array_map('intval', array_column($rows, 'tid'))));
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

    /**
     * Ritorna le squadre "pickabili" per round:
     * - Se $ignoreLock = true, ignora is_locked/locked_at (utile al sigillo per capire
     *   se "nuove" esistevano prima del lock).
     * - Esclude CANCELLED/POSTPONED/VOID se presenti.
     */
    private function getPickableTeams(int $tournamentId, int $round, bool $ignoreLock): array
    {
        $conds = ["tournament_id = :tid", "round = :rnd"];

        if (!$ignoreLock) {
            if ($this->columnExists($this->tblEvents, 'is_locked')) {
                $conds[] = "(is_locked IS NULL OR is_locked = 0)";
            } elseif ($this->columnExists($this->tblEvents, 'locked_at')) {
                $conds[] = "locked_at IS NULL";
            }
        }

        if ($this->columnExists($this->tblEvents, 'status')) {
            $conds[] = "COALESCE(status,'') NOT IN ('CANCELLED','POSTPONED','VOID')";
        } elseif ($this->columnExists($this->tblEvents, 'result')) {
            $conds[] = "COALESCE(result,'') NOT IN ('CANCELLED','POSTPONED','VOID')";
        }
        if ($this->columnExists($this->tblEvents, 'is_cancelled')) {
            $conds[] = "(is_cancelled IS NULL OR is_cancelled = 0)";
        }

        $where = implode(' AND ', $conds);
        $sql = "SELECT home_team_id AS tid FROM {$this->tblEvents} WHERE {$where}
                UNION
                SELECT away_team_id AS tid FROM {$this->tblEvents} WHERE {$where}";

        $rows = $this->fetchAll($sql, [':tid' => $tournamentId, ':rnd' => $round]);
        return array_values(array_unique(array_map('intval', array_column($rows, 'tid'))));
    }

    // -------------------- DB primitives --------------------

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
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_NAME = :t AND COLUMN_NAME = :c AND TABLE_SCHEMA = DATABASE()"
            );
            $stmt->execute([':t' => $table, ':c' => $column]);
            return (bool)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
