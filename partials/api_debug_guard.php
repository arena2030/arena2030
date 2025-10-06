<?php
/**
 * ============================================================
 *  API DEBUG GUARD (iper specifico)
 * ============================================================
 * Da includere come PRIMA cosa in ogni endpoint API.
 * Fornisce:
 *  - Error reporting totale
 *  - Conversione di warning/notice in eccezioni
 *  - Cattura di fatal error
 *  - Output sempre e solo JSON
 *  - Log dettagliato (solo se ?debug=1)
 * ============================================================
 */

if (!defined('ARENA_API_DEBUG_GUARD')) {
  define('ARENA_API_DEBUG_GUARD', true);

  // 1️⃣ Modalità debug attiva solo con ?debug=1
  $isDebug = isset($_GET['debug']) && $_GET['debug'] === '1';

  // 2️⃣ Output JSON garantito
  header('Content-Type: application/json; charset=utf-8');

  // Pulisci eventuali buffer precedenti
  while (ob_get_level()) ob_end_clean();
  ob_start();

  // 3️⃣ Imposta error reporting totale
  error_reporting(E_ALL);
  ini_set('display_errors', $isDebug ? '1' : '0');
  ini_set('log_errors', '1');

  // 4️⃣ Trasforma ogni warning/notice in eccezione
  set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return;
    throw new ErrorException($message, 0, $severity, $file, $line);
  });

  // 5️⃣ Gestione fatal error
  register_shutdown_function(function () use ($isDebug) {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
      @http_response_code(500);
      $payload = [
        'ok'    => false,
        'error' => 'fatal',
        'type'  => $err['type'],
      ];
      if ($isDebug) {
        $payload['detail'] = [
          'message' => $err['message'] ?? null,
          'file'    => $err['file'] ?? null,
          'line'    => $err['line'] ?? null,
          'trace'   => null,
        ];
      }
      while (ob_get_level()) ob_end_clean();
      echo json_encode($payload, JSON_PRETTY_PRINT);
    }
  });

  // 6️⃣ Wrapper standardizzato per uscire in JSON (da usare al posto di echo + exit)
  function api_json(array $data, int $code = 200): void {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
  }

  // 7️⃣ Helper per try/catch DB o logica
  function api_try(callable $fn, bool $isDebug = false): void {
    try {
      $result = $fn();
      api_json(['ok' => true, 'result' => $result]);
    } catch (Throwable $e) {
      api_json([
        'ok' => false,
        'error' => 'exception',
        'message' => $e->getMessage(),
        'file' => $isDebug ? $e->getFile() : null,
        'line' => $isDebug ? $e->getLine() : null,
        'trace' => $isDebug ? explode("\n", $e->getTraceAsString()) : null,
      ], 500);
    }
  }

  // 8️⃣ Helper per risposte rapide di errore
  function api_error(string $msg, int $code = 400, array $extra = []): void {
    api_json(array_merge(['ok' => false, 'error' => $msg], $extra), $code);
  }

  // ✅ Log immediato in file temporaneo (utile su hosting tipo Railway)
  if ($isDebug) {
    $tmp = sys_get_temp_dir() . '/arena_debug.log';
    file_put_contents($tmp, "[".date('H:i:s')."] Loaded api_debug_guard.php\n", FILE_APPEND);
  }
}
