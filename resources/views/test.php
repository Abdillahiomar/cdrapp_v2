<?php 
$host = "localhost"; //c'est l'@ ip auquel notre base de donnée est installée/ 127.0.0.1
$username = "root";
$password = "";
$dbname = "db_test";
try {
    $conn = new mysqli($host,$username,'',$dbname);
    
    // Activer les erreurs PDO
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Connexion réussie !";
    
} catch (PDOException $e) {
    die("Erreur de connexion : " . $e->getMessage());
}
?>