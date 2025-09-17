<?php
// Generatore di codici univoci (6 char base36) + helper

/**
 * Genera un codice base36 (0-9 A-Z) lungo 6 caratteri.
 */
function genUserCode(): string {
  $n = random_int(0, 2176782335); // 36^6 - 1
  $b36 = strtoupper(base_convert($n, 10, 36));
  return str_pad($b36, 6, '0', STR_PAD_LEFT);
}

/**
 * Verifica se un codice è già presente in users.user_code.
 */
function userCodeExists(PDO $pdo, string $code): bool {
  $st = $pdo->prepare('SELECT 1 FROM users WHERE user_code = ? LIMIT 1');
  $st->execute([$code]);
  return (bool)$st->fetchColumn();
}

/**
 * Restituisce un codice libero interrogando il DB.
 * Lancia eccezione se dopo $maxTries non trova disponibilità (eventualità remotissima).
 */
function getFreeUserCode(PDO $pdo, int $maxTries = 10): string {
  for ($i = 0; $i < $maxTries; $i++) {
    $c = genUserCode();
    if (!userCodeExists($pdo, $c)) return $c;
  }
  throw new RuntimeException('Non riesco a generare un user_code unico. Riprova.');
}
