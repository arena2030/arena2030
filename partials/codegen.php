<?php
// partials/codegen.php — generatori di codici univoci (user_code, tour_code, team_code, ecc.)
if (!isset($pdo)) { require_once __DIR__ . '/db.php'; }

function random_code(int $len=6): string {
  $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // niente O/0, I/1
  $out = '';
  for ($i=0; $i<$len; $i++) {
    $out .= $alphabet[random_int(0, strlen($alphabet)-1)];
  }
  return $out;
}

/**
 * Genera un codice univoco per una tabella/colonna con tentativi limitati.
 * @param PDO $pdo
 * @param string $table
 * @param string $column
 * @param int $len
 * @param int $maxAttempts
 * @return string
 * @throws RuntimeException
 */
function generate_unique_code(PDO $pdo, string $table, string $column, int $len=6, int $maxAttempts=20): string {
  for ($i=0; $i<$maxAttempts; $i++) {
    $code = random_code($len);
    $stmt = $pdo->prepare("SELECT 1 FROM `$table` WHERE `$column` = ? LIMIT 1");
    $stmt->execute([$code]);
    if (!$stmt->fetchColumn()) {
      return $code;
    }
  }
  throw new RuntimeException("Impossibile generare un codice univoco dopo $maxAttempts tentativi");
}
