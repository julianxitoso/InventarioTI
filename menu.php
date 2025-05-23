<?php
session_start(); 
require_once 'backend/auth_check.php'; // Este archivo DEBE definir tiene_permiso_para() y es_admin() correctamente

// Definir variables para mostrar nombre y rol del usuario
$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Usuario';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'Desconocido';

// Lógica para restringir acceso si es necesario (auth_check.php ya lo hace, pero por si acaso)
// verificar_sesion_activa(); // Esta función ya es llamada por restringir_acceso_pagina en otras páginas
// Si menu.php es accesible por todos los usuarios logueados, no se necesita una restricción adicional aquí.
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
            background-color: #ffffff !important; /* Fondo del body blanco */
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding-top: 80px; /* Espacio para la barra superior fija */
        }
        .top-bar-custom {
            position: fixed; /* Fija la barra en la parte superior */
            top: 0;
            left: 0;
            right: 0;
            z-index: 1030; /* Asegura que esté por encima de otros elementos */
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 1.5rem; /* Ajusta el padding según necesites */
            background-color: #f8f9fa; /* Un color de fondo claro para la barra */
            border-bottom: 1px solid #dee2e6;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .logo-container-top img {
            width: auto; /* Ancho automático */
            height: 75px; /* Altura fija para el logo en la barra */
            object-fit: contain;
            margin-right: 15px; /* Espacio a la derecha del logo */
        }
        .user-info-top {
            font-size: 0.9rem;
        }
        .welcome-message { font-size: 1.2rem; }
        .card-link { text-decoration: none; }
        .menu-card {
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
            border: 1px solid #e0e0e0;
            border-radius: 0.5rem;
        }
        .menu-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1.5rem rgba(0,0,0,0.1);
        }
        .menu-card .card-body { text-align: center; padding: 1.5rem; }
        .menu-card i { 
            font-size: 2.8rem; /* Iconos un poco más grandes */
            margin-bottom: 0.75rem; 
            display: block; /* Para centrar el ícono */
            margin-left: auto;
            margin-right: auto;
        }
        .menu-card .card-title {
            font-weight: 600;
            color: #333;
        }
        .menu-card .card-text {
            font-size: 0.85rem;
            min-height: 50px; /* Para alinear tarjetas con diferente cantidad de texto */
        }
        .page-header-title {
             color: #191970; /* Color corporativo para el título */
        }
    </style>
</head>
<body>
    <div class="top-bar-custom">
        <div class="logo-container-top">
            <a href="menu.php" title="Ir a Inicio">
                <img src="imagenes/logo.png" alt="Logo ARPESOD ASOCIADOS SAS">
            </a>
        </div>
        <div class="d-flex align-items-center">
            <span class="text-dark me-3 user-info-top">
                <i class="bi bi-person-circle"></i> <?= htmlspecialchars($nombre_usuario_actual_sesion) ?> 
                (<?= htmlspecialchars(ucfirst($rol_usuario_actual_sesion)) ?>)
            </span>
            <form action="logout.php" method="post" class="d-flex">
                <button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</button>
            </form>
        </div>
    </div>

    <div class="container mt-4">
        <?php if(isset($_SESSION['error_acceso_pagina'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($_SESSION['error_acceso_pagina']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['error_acceso_pagina']); ?>
        <?php endif; ?>

        <div class="px-3 py-3 pt-md-4 pb-md-3 mx-auto text-center">
            <h1 class="display-6 fw-normal page-header-title">Bienvenido al Sistema de Inventario</h1>
            <p class="fs-5 text-muted">Seleccione una opción para comenzar.</p>
        </div>
        
        <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-lg-4 g-4 justify-content-center">
            <?php if (tiene_permiso_para('crear_activo')): ?>
            <div class="col">
                <a href="index.php" class="card-link">
                <div class="card menu-card h-100">
                    <div class="card-body">
                        <i class="bi bi-plus-square-fill text-success"></i>
                        <h5 class="card-title">Registrar Activo</h5>
                        <p class="card-text text-muted">Añadir nuevos activos al inventario.</p>
                    </div>
                </div>
                </a>
            </div>
            <?php endif; ?>

            <?php if (tiene_permiso_para('buscar_activo')): ?>
            <div class="col">
                 <a href="buscar.php" class="card-link">
                <div class="card menu-card h-100">
                    <div class="card-body">
                        <i class="bi bi-search text-info"></i>
                        <h5 class="card-title">Buscar Activos</h5>
                        <p class="card-text text-muted">Consultar y ver detalles de los activos.</p>
                    </div>
                </div>
                </a>
            </div>
            <?php endif; ?>
            
            <?php if (tiene_permiso_para('editar_activo_detalles') || tiene_permiso_para('trasladar_activo')): ?>
            <div class="col">
                 <a href="editar.php" class="card-link">
                <div class="card menu-card h-100">
                    <div class="card-body">
                        <i class="bi bi-pencil-square text-primary"></i>
                        <h5 class="card-title">Administrar Activos</h5>
                        <p class="card-text text-muted">Editar, trasladar o dar de baja activos.</p>
                    </div>
                </div>
                </a>
            </div>
            <?php endif; ?>

            <?php if (tiene_permiso_para('ver_dashboard')): ?>
            <div class="col">
                 <a href="dashboard.php" class="card-link">
                <div class="card menu-card h-100">
                    <div class="card-body">
                        <i class="bi bi-bar-chart-line-fill text-secondary"></i>
                        <h5 class="card-title">Dashboard</h5>
                        <p class="card-text text-muted">Visualizar estadísticas y KPIs.</p>
                    </div>
                </div>
                </a>
            </div>
            <?php endif; ?>

            <?php if (tiene_permiso_para('generar_informes')): ?>
            <div class="col">
                 <a href="informes.php" class="card-link">
                <div class="card menu-card h-100">
                    <div class="card-body">
                        <i class="bi bi-file-earmark-text-fill text-warning"></i>
                        <h5 class="card-title">Informes</h5>
                        <p class="card-text text-muted">Generar reportes detallados.</p>
                    </div>
                </div>
                </a>
            </div>
            <?php endif; ?>
            
            <?php if (es_admin()): ?>
             <div class="col">
                 <a href="registrar_usuario.php" class="card-link">
                <div class="card menu-card h-100">
                    <div class="card-body">
                        <i class="bi bi-person-plus-fill text-danger"></i>
                        <h5 class="card-title">Crear Usuario</h5>
                        <p class="card-text text-muted">Registrar nuevos usuarios (rol Registrador).</p>
                    </div>
                </div>
                </a>
            </div>
            <?php endif; ?>

            <div class="col">
                 <a href="cambiar_clave.php" class="card-link">
                <div class="card menu-card h-100">
                    <div class="card-body">
                        <i class="bi bi-key-fill text-dark"></i>
                        <h5 class="card-title">Cambiar Contraseña</h5>
                        <p class="card-text text-muted">Modificar tu contraseña de acceso personal.</p>
                    </div>
                </div>
                </a>
            </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>