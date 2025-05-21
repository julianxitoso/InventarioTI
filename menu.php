<?php
session_start();
if (!isset($_SESSION['usuario'])) {
 header("Location: login.php");
 exit;
}
?>

<?php
// session_start(); // auth_check.php ya se encarga de esto
require_once 'backend/auth_check.php';
verificar_sesion_activa(); // Asegura que solo usuarios logueados vean el menú

$nombre_usuario_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Usuario';
$rol_usuario_sesion = obtener_rol_usuario(); // Para mostrar el rol si quieres, o para debug

// Manejar mensajes de error si el usuario fue redirigido aquí por falta de permisos
$mensaje_error_acceso = $_SESSION['mensaje_error_global'] ?? null;
if (isset($_SESSION['mensaje_error_global'])) {
    unset($_SESSION['mensaje_error_global']);
}
?>


<!DOCTYPE html>
<html lang="es">
<head>
 <meta charset="UTF-8">
 <title>Menú Principal - Inventario</title>
 <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
 <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
 <style>
 body {
  background-color: #f4f6f8;
  /* Intentar centrar la tarjeta verticalmente si el contenido es corto */
  /* display: flex; */
  /* align-items: center; */
  /* min-height: 100vh; */ /* Esto puede ser problemático si el contenido SÍ es largo */
  /* padding-top: 20px; */ /* Si no usamos flex, un padding para que no pegue arriba */
  /* padding-bottom: 20px; */
 }
 .card-menu {
  max-width: 450px; /* Un poco más angosto */
  margin: 15px auto; /* Reducido margen superior, auto para horizontal */
  padding: 2rem;    /* Reducido padding */
  border-radius: 12px; /* Ligeramente menos redondeo */
  background-color: #ffffff;
  box-shadow: 0 8px 20px rgba(0,0,0,0.07); /* Sombra ajustada */
 }
 .logo-menu img { /* Clase específica para el logo en el menú */
    max-height: 80px; /* Reducido tamaño del logo */
    margin-bottom: 0.75rem; /* Reducido margen inferior del logo */
 }
 .title {
  color: #37517e;
  font-weight: 600; /* Ligeramente menos peso si es necesario */
  font-size: 1.5rem; /* Reducido tamaño del título */
  margin-bottom: 0.25rem; /* Menos espacio debajo del título */
 }
 .subtitle {
  font-size: 0.9rem; /* Reducido tamaño del subtítulo */
  color: #6c757d;
  margin-bottom: 1.5rem; /* Reducido espacio después del subtítulo */
 }
 .list-group-item {
    border-radius: 0.375rem !important; /* Bordes redondeados estándar de BS */
    margin-bottom: 0.5rem; /* Espacio entre items reducido */
    border-color: #e9ecef;
    font-weight: 500;
    font-size: 0.95rem; /* Fuente de items un poco más pequeña */
    padding: 0.6rem 1rem; /* Padding de items reducido */
 }
 .list-group-item-action {
  display: flex;
  align-items: center;
  transition: background-color 0.2s ease-in-out, border-left-color 0.2s ease-in-out;
  border-left: 3px solid transparent; 
 }
 .list-group-item-action:hover,
 .list-group-item-action:focus {
    background-color: #eef2f7;
    border-left-color: #191970; 
    color: #191970;
 }
 .list-group-item-action .bi {
  margin-right: 0.6rem; /* Espacio para el ícono reducido */
  font-size: 1.1rem; /* Íconos un poco más pequeños */
  color: #37517e; 
 }
  .list-group-item-action:hover .bi,
  .list-group-item-action:focus .bi {
    color: #191970;
  }
 .user-info {
    margin-top: 1.5rem; /* Reducido */
    padding-top: 0.75rem; /* Reducido */
    border-top: 1px solid #e9ecef;
    font-size: 0.85rem; /* Texto de usuario más pequeño */
 }
 .user-info .btn {
    font-size: 0.85rem; /* Botón más pequeño */
    padding: 0.25rem 0.6rem; /* Padding de botón reducido */
 }
 </style>
</head>
<body>
 <div class="card-menu text-center">
  <div class="logo-menu">
    <img src="imagenes/logo3.png" alt="Logo de la empresa" class="img-fluid">
  </div>
  <h3 class="title">ACTIVOS FIJOS TECNOLOGÍA</h3>
  <p class="subtitle">ARPESOD &amp; FINANSUEÑOS</p>

  <div class="list-group text-start mt-3"> 
   <a href="index.html" class="list-group-item list-group-item-action">
    <i class="bi bi-plus-square-dotted"></i> 1. Registrar Activos
   </a>
   <a href="buscar.php" class="list-group-item list-group-item-action">
    <i class="bi bi-search-heart"></i> 2. Buscar Activos
   </a>
   <a href="editar.php" class="list-group-item list-group-item-action">
    <i class="bi bi-pencil-square"></i> 3. Editar / Eliminar Activos
   </a>
   <a href="informes.php" class="list-group-item list-group-item-action">
    <i class="bi bi-file-earmark-text"></i> 4. Informe General
   </a>
   <a href="dashboard.php" class="list-group-item list-group-item-action">
    <i class="bi bi-bar-chart-line-fill"></i> 5. Estadísticas (Dashboard)
   </a>
  </div>
  <div class="user-info">
   <span class="text-muted">Sesión iniciada como: <strong><?php echo htmlspecialchars($_SESSION['usuario']); ?></strong></span><br>
   <a href="logout.php" class="btn btn-outline-danger btn-sm mt-2">
    <i class="bi bi-box-arrow-right"></i> Cerrar Sesión
   </a>
  </div>
 </div>

 <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>