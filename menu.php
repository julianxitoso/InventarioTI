<?php
session_start(); 
require_once 'backend/auth_check.php'; // Este archivo DEBE definir tiene_permiso_para() y es_admin() correctamente

// Definir variables para mostrar nombre y rol del usuario
// Estas se toman de la sesión que debió establecer login.php
$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Usuario';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'Desconocido';

// La función restringir_acceso_pagina() ya se encarga de verificar si hay sesión activa
// y si el rol tiene permiso para 'ver_menu' (si así lo defines).
// Opcionalmente, podrías añadir una llamada explícita aquí si 'ver_menu' no siempre está permitido
// para todos los roles que llegan a menu.php:
// if (!tiene_permiso_para('ver_menu')) {
//     // Manejar el caso donde un rol logueado no debería ver el menú principal
//     // Podría ser una redirección o un mensaje.
//     // Por ahora, se asume que si llega aquí, tiene permiso básico de ver el menú.
// }

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Menú Principal - Inventario</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f4f6f8; }
        .navbar-custom { background-color: #191970; margin-bottom: 20px;}
        .navbar-custom .nav-link { color: white !important; font-weight: 500; padding: 0.6rem 1rem;}
        .navbar-custom .nav-link.active, .navbar-custom .nav-link:hover { background-color: #8b0000; border-radius:0.25rem; }
        .logo-container { text-align: center; padding:10px 0; }
        .logo-container img { width: 200px; height: auto; max-height:70px; object-fit: contain;}
        .welcome-message { font-size: 1.2rem; }
        .card-link { text-decoration: none; }
        .menu-card {
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
            border: 1px solid #ddd;
        }
        .menu-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15);
        }
        .menu-card .card-body { text-align: center; }
        .menu-card i { font-size: 2.5rem; margin-bottom: 0.5rem; }
    </style>
</head>
<body>
    <div class="logo-container">
        <a href="menu.php"><img src="imagenes/logo3.png" alt="Logo ARPESOD ASOCIADOS SAS"></a>
    </div>

    <nav class="navbar navbar-expand-lg navbar-custom">
        <div class="container-fluid">
            <a class="navbar-brand text-white" href="menu.php">Inventario</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNavGlobal" aria-controls="navbarNavGlobal" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon" style="background-image: url(&quot;data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba(255,255,255,0.8)' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e&quot;);"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNavGlobal">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item">
                        <a class="nav-link <?= (basename($_SERVER['PHP_SELF']) == 'menu.php') ? 'active' : '' ?>" href="menu.php"> <i class="bi bi-house-door-fill"></i> Inicio</a>
                    </li>

                    <?php if (tiene_permiso_para('crear_activo')): ?>
                        <li class="nav-item"><a class="nav-link <?= (basename($_SERVER['PHP_SELF']) == 'index.php') ? 'active' : '' ?>" href="index.php"><i class="bi bi-plus-square-fill"></i> Registrar Activo</a></li>
                    <?php endif; ?>

                    <?php if (tiene_permiso_para('buscar_activo')): ?>
                        <li class="nav-item"><a class="nav-link <?= (basename($_SERVER['PHP_SELF']) == 'buscar.php') ? 'active' : '' ?>" href="buscar.php"><i class="bi bi-search"></i> Buscar Activos</a></li>
                    <?php endif; ?>
                    
                    <?php if (tiene_permiso_para('editar_activo_detalles') || tiene_permiso_para('trasladar_activo')): ?>
                        <li class="nav-item"><a class="nav-link <?= (basename($_SERVER['PHP_SELF']) == 'editar.php') ? 'active' : '' ?>" href="editar.php"><i class="bi bi-pencil-square"></i> Editar/Trasladar/Baja</a></li>
                    <?php endif; ?>
                    
                    <?php if (tiene_permiso_para('ver_dashboard')): ?>
                        <li class="nav-item"><a class="nav-link <?= (basename($_SERVER['PHP_SELF']) == 'dashboard.php') ? 'active' : '' ?>" href="dashboard.php"><i class="bi bi-bar-chart-line-fill"></i> Dashboard</a></li>
                    <?php endif; ?>

                    <?php if (tiene_permiso_para('generar_informes')): ?>
                        <li class="nav-item"><a class="nav-link <?= (basename($_SERVER['PHP_SELF']) == 'informes.php') ? 'active' : '' ?>" href="informes.php"><i class="bi bi-file-earmark-text-fill"></i> Informes</a></li>
                    <?php endif; ?>

                    <?php if (es_admin()): // La creación de usuarios (incluso de tipo registrador) es tarea del admin ?>
                        <li class="nav-item">
                            <a class="nav-link <?= (basename($_SERVER['PHP_SELF']) == 'registrar_usuario.php') ? 'active' : '' ?>" href="registrar_usuario.php">
                                <i class="bi bi-person-plus-fill"></i> Crear Usuario
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
                <div class="d-flex align-items-center"> {/* Cambiado de navbar-nav ms-auto a este div para mejor alineación */}
                    <span class="navbar-text text-white me-3">
                        <i class="bi bi-person-circle"></i> <?= htmlspecialchars($nombre_usuario_actual_sesion) ?> (<?= htmlspecialchars(ucfirst($rol_usuario_actual_sesion)) ?>)
                    </span>
                    <form action="logout.php" method="post" class="d-flex">
                        <button class="btn btn-outline-light btn-sm" type="submit"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</button>
                    </form>
                </div>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <?php if(isset($_SESSION['error_acceso_pagina'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($_SESSION['error_acceso_pagina']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['error_acceso_pagina']); ?>
        <?php endif; ?>

        <div class="px-3 py-3 pt-md-5 pb-md-4 mx-auto text-center">
            <h1 class="display-6 fw-normal">Bienvenido al Sistema de Inventario</h1>
            <p class="fs-5 text-muted">Seleccione una opción del menú de navegación o de las tarjetas de acceso rápido para comenzar.</p>
        </div>
        
        <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4 justify-content-center">
            <?php if (tiene_permiso_para('crear_activo')): ?>
            <div class="col">
                <a href="index.php" class="card-link">
                <div class="card menu-card h-100">
                    <div class="card-body">
                        <i class="bi bi-plus-square-fill text-success"></i>
                        <h5 class="card-title">Registrar Activo</h5>
                        <p class="card-text text-muted small">Añadir nuevos activos tecnológicos al inventario.</p>
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
                        <p class="card-text text-muted small">Consultar el inventario y ver detalles de los activos.</p>
                    </div>
                </div>
                </a>
            </div>
            <?php endif; ?>
            
            <?php if (tiene_permiso_para('editar_activo_detalles')): ?>
            <div class="col">
                 <a href="editar.php" class="card-link">
                <div class="card menu-card h-100">
                    <div class="card-body">
                        <i class="bi bi-pencil-square text-primary"></i>
                        <h5 class="card-title">Administrar Activos</h5>
                        <p class="card-text text-muted small">Editar, trasladar o dar de baja activos existentes.</p>
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
                        <p class="card-text text-muted small">Visualizar estadísticas y KPIs del inventario.</p>
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
                        <p class="card-text text-muted small">Generar reportes detallados del inventario.</p>
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
                        <p class="card-text text-muted small">Registrar nuevos usuarios (rol Registrador).</p>
                    </div>
                </div>
                </a>
            </div>
            <?php endif; ?>
        </div>


    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>