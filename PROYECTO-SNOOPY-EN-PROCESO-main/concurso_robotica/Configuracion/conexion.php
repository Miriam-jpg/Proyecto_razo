<?php
$host = "localhost";
$dbname = "concurso_robotica";
$user = "root";
$pass = ""; 

// Lista de puertos a intentar (Estándar 3306 y Alternativo 3307)
$ports = ["3306", "3307"];
$pdo = null;
$errorConexion = "";

foreach ($ports as $port) {
    try {
        $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8";
        $pdo = new PDO($dsn, $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Si conecta exitosamente, salimos del bucle
        break; 
    } catch (PDOException $e) {
        $errorConexion = $e->getMessage();
        continue; // Intentar siguiente puerto
    }
}

// NOTA: No usamos die() aquí para permitir que la API maneje el error elegantemente.
?>