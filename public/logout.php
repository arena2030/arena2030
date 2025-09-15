<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Elimina tutte le variabili di sessione
$_SESSION = [];

// Cancella il cookie della sessione (se esiste)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Distrugge la sessione
session_destroy();

// Reindirizza alla home page (index)
header("Location: /index.php");
exit;
