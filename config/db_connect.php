<?php
require_once __DIR__ . '/env.php';
/* Every DB-backed endpoint loads this file, so wiring CORS here gives them all
 * consistent Access-Control headers without a per-file require. cors.php is
 * require_once-safe and a no-op for same-origin requests and CLI scripts. */
require_once __DIR__ . '/cors.php';

function getConnection() {
    $host = $_ENV['DB_HOST'];
    $db = $_ENV['DB_NAME'];
    $user = $_ENV['DB_USER'];
    $pass = $_ENV['DB_PASS'];

    try {
        $conn = new PDO("mysql:host=$host;dbname=$db", $user, $pass);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $conn;
    } catch (PDOException $e) {
        die("DB Error: " . $e->getMessage());
    }
}

?>