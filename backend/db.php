<?php
$host = "localhost";
$user = "root";
$pass = "@p200905"; // Cambia esto si tu MySQL tiene contraseña
$db = "inventario";

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}
?>
