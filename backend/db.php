<?php
$host = "localhost";
$user = "root";
$pass = ""; // Cambia esto si tu MySQL tiene contraseña
$db = "inventario";

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}
?>
