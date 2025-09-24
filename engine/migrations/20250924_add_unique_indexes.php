<?php
declare(strict_types=1);

/**
 * Migrazione idempotente: crea indice unico su (life_id, round) per la tabella picks
 * Valida anche naming alternativi (tournament_picks / picks, life_id/life, round/rnd).
 */
require_once __DIR__ . '/../..//partials/db.php';

if (!isset($pdo)) { exit("PDO non disponibile\n"); }

function tableExists(PDO $pdo, string $t): bool {
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
  $q->execute([$t]); return (bool)$q->fetchColumn();
}
function colExists(PDO $pdo, string $t, string $c): bool {
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $q->execute([$t,$c]); return (bool)$q->fetchColumn();
}
function firstCol(PDO $pdo, string $t, array $cands, string $fallback='NULL'){
  foreach($cands as $c){ if(colExists($pdo,$t,$c)) return $c; } return $fallback;
}
function pickTable(PDO $pdo, array $cands){ foreach($cands as $t){ if(tableExists($pdo,$t)) return $t; } return null; }

$pT = pickTable($pdo, ['tournament_picks','picks','torneo_picks']);
if(!$pT){ echo "Tabella picks non trovata, skip.\n"; return; }

$pLife  = firstCol($pdo,$pT,['life_id','life','id_life'],'NULL');
$pRound = firstCol($pdo,$pT,['round','rnd','round_no'],'NULL');
if($pLife==='NULL' || $pRound==='NULL'){ echo "Colonne life/round non trovate, skip.\n"; return; }

$exists = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
                         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME='uniq_life_round'");
$exists->execute([$pT]);
if(!$exists->fetchColumn()){
  $sql = "ALTER TABLE `$pT` ADD UNIQUE KEY `uniq_life_round` (`$pLife`, `$pRound`)";
  $pdo->exec($sql);
  echo "Creato indice uniq_life_round su $pT($pLife,$pRound)\n";
} else {
  echo "Indice uniq_life_round già presente, ok.\n";
}
