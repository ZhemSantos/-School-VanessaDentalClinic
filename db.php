<?php
// Set timezone to Philippines (Bulacan, Manila - UTC+8)
date_default_timezone_set('Asia/Manila');

$host = 'sql202.infinityfree.com';
$db = 'if0_43372160_vanessaclinic';
$user = 'if0_43372160';
$pass = '19vanessa19';
$charset = 'utf8mb4';
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    // Set MySQL timezone to match PHP timezone
    $pdo->exec("SET time_zone = '+08:00'");
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>
