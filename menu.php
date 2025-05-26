<?php
session_start();
require_once 'backend/auth_check.php'; // Este archivo DEBE definir tiene_permiso_para()

// Definir variables para mostrar nombre y rol del usuario
$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Usuario';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'Desconocido';

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
            background-color: #ffffff !important;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding-top: 80px;
        }
        .top-bar-custom {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1030;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 1.5rem;
            background-color: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .logo-container-top img {
            width: auto;
            height: 75px;
            object-fit: contain;
            margin-right: 15px;
        }
        .user-info-top {
            font-size: 0.9rem;
        }
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
            font-size: 2.8rem;
            margin-bottom: 0.75rem;
            display: block;
            margin-left: auto;
            margin-right: auto;
        }
        .menu-card .card-title {
            font-weight: 600;
            color: #333;
        }
        .menu-card .card-text {
            font-size: 0.85rem;
            min-height: 50px;
        }
        .page-header-title {
            color: #191970;
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

            <?php if (tiene_permiso_para('gestionar_usuarios')): ?>
            <div class="col">
                 <a href="gestionar_usuarios.php" class="card-link">
                <div class="card menu-card h-100">
                    <div class="card-body">
                        <i class="bi bi-people-fill text-primary"></i>
                        <h5 class="card-title">Gestionar Usuarios</h5>
                        <p class="card-text text-muted">Administrar usuarios y sus roles de acceso.</p>
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
            
            <div class="col">
                 <a href="depreciacion_activos.php" class="card-link">
                <div class="card menu-card h-100">
                    <div class="card-body">
                        <i class="bi bi-graph-down text-dark"></i>
                        <h5 class="card-title">Depreciación de Activos</h5>
                        <p class="card-text text-muted">Calcular y consultar la depreciación de los activos.</p>
                    </div>
                </div>
                </a>
            </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <div id="chatbot-button"><svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" fill="currentColor" class="bi bi-chat-dots-fill" viewBox="0 0 16 16"><path d="M16 8c0 3.866-3.582 7-8 7a9.06 9.06 0 0 1-2.347-.306c-.584.296-1.925.864-4.181 1.234-.2.032-.352-.176-.273-.362.354-.836.674-1.95.77-2.966C.744 11.37 0 9.76 0 8c0-3.866 3.582-7 8-7s8 3.134 8 7zM5 8a1 1 0 1 0-2 0 1 1 0 0 0 2 0zm4 0a1 1 0 1 0-2 0 1 1 0 0 0 2 0zm3 1a1 1 0 1 0 0-2 1 1 0 0 0 0 2z"/></svg></div>
    <div id="chatbot-container"><div id="chatbot-header"><span>Mi Chatbot</span><span id="chatbot-close-button">&times;</span></div><iframe id="chatbot-iframe" src="" frameborder="0"></iframe></div>
    <style>#chatbot-button{position:fixed;bottom:25px;right:25px;width:60px;height:60px;background-color:#007bff;color:white;border-radius:50%;display:flex;justify-content:center;align-items:center;cursor:pointer;box-shadow:0 4px 8px rgba(0,0,0,.2);z-index:999;transition:transform .2s}#chatbot-button:hover{transform:scale(1.1)}#chatbot-container{position:fixed;bottom:100px;right:25px;width:370px;height:70vh;max-height:500px;background-color:white;border-radius:15px;box-shadow:0 4px 12px rgba(0,0,0,.2);display:none;flex-direction:column;overflow:hidden;z-index:1000}#chatbot-header{background-color:#007bff;color:white;padding:10px 15px;font-weight:700;display:flex;justify-content:space-between;align-items:center}#chatbot-close-button{cursor:pointer;font-size:24px;font-weight:700}#chatbot-iframe{width:100%;height:100%;border:none}#chatbot-container.visible{display:flex}</style>
    <script>document.addEventListener("DOMContentLoaded",function(){const e="https://asistenteaifront.onrender.com/",t=document.getElementById("chatbot-button"),o=document.getElementById("chatbot-container"),n=document.getElementById("chatbot-iframe"),c=document.getElementById("chatbot-close-button");let d=!1;function a(){d||(n.src=e,d=!0),o.classList.add("visible")}function i(){o.classList.remove("visible")}t.addEventListener("click",a),c.addEventListener("click",i)});</script>

</body>
</html>