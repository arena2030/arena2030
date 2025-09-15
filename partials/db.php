<?php
// Connessione al database MySQL su Railway tramite variabili di ambiente

$host = getenv('MYSQLHOST');        // es: mysql.railway.internal
$port = getenv('MYSQLPORT');        // es: 3306
$db   = getenv('MYSQLDATABASE');    // es: railway
$user = getenv('MYSQLUSER');        // es: root
$pass = getenv('MYSQLPASSWORD');    // password reale

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,     // errori come eccezioni
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, // risultati come array associativi
    PDO::ATTR_EMULATE_PREPARES => false,              // prepared statements reali
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    // ✅ Connessione riuscita
} catch (PDOException $e) {
    // ❌ Connessione fallita
    echo "<h3>Errore connessione al database</h3>";
    echo "<pre>" . $e->getMessage() . "</pre>";
    exit;
}
