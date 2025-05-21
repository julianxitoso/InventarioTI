<?php
// session_start(); // auth_check.php ya lo incluye y gestiona
require_once 'backend/auth_check.php';
restringir_acceso_pagina(['admin']); // SOLO ADMINS PUEDEN ACCEDER

require_once 'backend/db.php';
require_once 'backend/historial_helper.php'; // Si queremos registrar quién crea usuarios

if (isset($conn) && !isset($conexion)) { $conexion = $conn; }
if (!isset($conexion) || !$conexion || (method_exists($conexion, 'connect_error') && $conexion->connect_error)) {
    error_log("Error de conexión BD en gestionar_usuarios.php: " . ($conexion->connect_error ?? 'Desconocido'));
    die("Error de conexión a la base de datos.");
}
$conexion->set_charset("utf8mb4");

$nombre_usuario_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Administrador';
$rol_usuario_sesion = obtener_rol_usuario();
$mensaje_accion = $_SESSION['mensaje_accion_usuarios'] ?? null; // Para mensajes de éxito/error
if (isset($_SESSION['mensaje_accion_usuarios'])) {
    unset($_SESSION['mensaje_accion_usuarios']);
}

$accion = $_GET['accion'] ?? 'listar'; // 'listar', 'crear', 'editar'
$id_usuario_editar = null;
$datos_usuario_edicion = null;

// Procesar creación de nuevo usuario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_usuario_submit'])) {
    if (!es_admin()) { // Doble verificación de permiso
        $_SESSION['mensaje_accion_usuarios'] = "<div class='alert alert-danger'>No tiene permisos para crear usuarios.</div>";
        header("Location: gestionar_usuarios.php");
        exit;
    }

    $nuevo_nombre_usuario = trim($_POST['nuevo_nombre_usuario']);
    $nuevo_nombre_completo = trim($_POST['nuevo_nombre_completo']);
    $nueva_clave = $_POST['nueva_clave'];
    $confirmar_nueva_clave = $_POST['confirmar_nueva_clave'];
    $nuevo_rol = $_POST['nuevo_rol'];
    $nuevo_activo = isset($_POST['nuevo_activo']) ? 1 : 0;

    // Validaciones
    if (empty($nuevo_nombre_usuario) || empty($nuevo_nombre_completo) || empty($nueva_clave) || empty($nuevo_rol)) {
        $mensaje_accion = "<div class='alert alert-danger'>Todos los campos marcados con * son obligatorios.</div>";
    } elseif (strlen($nueva_clave) < 6) { // Ejemplo de política de contraseña mínima
        $mensaje_accion = "<div class='alert alert-danger'>La contraseña debe tener al menos 6 caracteres.</div>";
    } elseif ($nueva_clave !== $confirmar_nueva_clave) {
        $mensaje_accion = "<div class='alert alert-danger'>Las contraseñas no coinciden.</div>";
    } elseif (!in_array($nuevo_rol, ['admin', 'tecnico', 'auditor'])) {
        $mensaje_accion = "<div class='alert alert-danger'>Rol no válido.</div>";
    } else {
        // Verificar si el nombre de usuario ya existe
        $stmt_check = $conexion->prepare("SELECT id FROM usuarios WHERE usuario = ?");
        $stmt_check->bind_param("s", $nuevo_nombre_usuario);
        $stmt_check->execute();
        $stmt_check->store_result();

        if ($stmt_check->num_rows > 0) {
            $mensaje_accion = "<div class='alert alert-danger'>El nombre de usuario '$nuevo_nombre_usuario' ya existe. Por favor, elija otro.</div>";
        } else {
            $clave_hasheada = password_hash($nueva_clave, PASSWORD_DEFAULT);
            $sql_insert = "INSERT INTO usuarios (usuario, clave, nombre_completo, rol, activo) VALUES (?, ?, ?, ?, ?)";
            $stmt_insert = $conexion->prepare($sql_insert);
            $stmt_insert->bind_param("ssssi", $nuevo_nombre_usuario, $clave_hasheada, $nuevo_nombre_completo, $nuevo_rol, $nuevo_activo);

            if ($stmt_insert->execute()) {
                $_SESSION['mensaje_accion_usuarios'] = "<div class='alert alert-success'>Usuario '".htmlspecialchars($nuevo_nombre_usuario)."' creado exitosamente.</div>";
                // Opcional: Registrar en historial_sistema que $usuario_actual_sistema_login creó a $nuevo_nombre_usuario
                header("Location: gestionar_usuarios.php"); // Redirigir para limpiar el POST y mostrar mensaje
                exit;
            } else {
                $mensaje_accion = "<div class='alert alert-danger'>Error al crear el usuario: " . $stmt_insert->error . "</div>";
                error_log("Error al crear usuario: " . $stmt_insert->error);
            }
            $stmt_insert->close();
        }
        $stmt_check->close();
    }
}


// Obtener lista de usuarios para mostrar
$usuarios_listados = [];
$sql_listar = "SELECT id, usuario, nombre_completo, rol, activo, fecha_creacion FROM usuarios ORDER BY nombre_completo ASC";
$result_listar = $conexion->query($sql_listar);
if ($result_listar) {
    while ($row = $result_listar->fetch_assoc()) {
        $usuarios_listados[] = $row;
    }
} else {
    $mensaje_accion = "<div class='alert alert-danger'>Error al obtener la lista de usuarios: " . $conexion->error . "</div>";
}

// No cerramos $conexion aquí si el HTML la necesita para otras cosas (como selects dinámicos, aunque no en este caso)
// $conexion->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestionar Usuarios - Inventario</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .container-main { margin-top: 20px; margin-bottom: 40px;}
        h3.page-title { color: #333; font-weight: 600; margin-bottom: 25px; }
        .logo-container { text-align: center; margin-bottom: 5px; padding-top:10px; }
        .logo-container img { width: 180px; height: 70px; object-fit: contain; }
        .navbar-custom { background-color: #191970; }
        .navbar-custom .nav-link { color: white !important; font-weight: 500; padding: 0.5rem 1rem;}
        .navbar-custom .nav-link:hover, .navbar-custom .nav-link.active { background-color: #8b0000; color: white; }
        .table th { white-space: nowrap; }
        .table td { vertical-align: middle; }
        .action-icon { font-size: 1.2rem; margin-right: 0.5rem; text-decoration: none; }
        .action-icon.text-warning:hover { color: #ff8c00 !important; }
        .action-icon.text-danger:hover { color: #dc3545 !important; }
        .action-icon.text-success:hover { color: #198754 !important; }
        .action-icon.text-secondary:hover { color: #5a6268 !important; }
        .card-header-custom { background-color: #e9ecef; }
    </style>
</head>
<body>

<div class="logo-container">
    <a href="menu.php"><img src="imagenes/logo3.png" alt="Logo ARPESOD ASOCIADOS SAS"></a>
</div>

<nav class="navbar navbar-expand-lg navbar-custom">
    <div class="container-fluid">
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon" style="background-image: url(&quot;data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba(255,255,255,0.8)' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e&quot;);"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
               <li class="nav-item"><a class="nav-link <?= (basename($_SERVER['PHP_SELF']) == 'menu.php') ? 'active' : '' ?>" href="menu.php">Inicio</a></li>
               <?php if (tiene_permiso_para('crear_activo')): ?>
                   <li class="nav-item"><a class="nav-link <?= (basename($_SERVER['PHP_SELF']) == 'index.php') ? 'active' : '' ?>" href="index.php">Registrar Activo</a></li>
               <?php endif; ?>
               <?php if (tiene_permiso_para('editar_activo_detalles') || tiene_permiso_para('trasladar_activo') || tiene_permiso_para('dar_baja_activo') ): ?>
                   <li class="nav-item"><a class="nav-link <?= (basename($_SERVER['PHP_SELF']) == 'editar.php') ? 'active' : '' ?>" href="editar.php">Editar/Trasladar/Baja</a></li>
               <?php endif; ?>
               <?php if (tiene_permiso_para('buscar_activo')): ?>
                   <li class="nav-item"><a class="nav-link <?= (basename($_SERVER['PHP_SELF']) == 'buscar.php') ? 'active' : '' ?>" href="buscar.php">Buscar Activos</a></li>
               <?php endif; ?>
               <?php if (tiene_permiso_para('generar_informes')): ?>
                   <li class="nav-item"><a class="nav-link <?= (basename($_SERVER['PHP_SELF']) == 'informes.php') ? 'active' : '' ?>" href="informes.php">Informes</a></li>
               <?php endif; ?>
               <?php if (tiene_permiso_para('ver_dashboard')): ?>
                    <li class="nav-item"><a class="nav-link <?= (basename($_SERVER['PHP_SELF']) == 'dashboard.php') ? 'active' : '' ?>" href="dashboard.php">Dashboard</a></li>
               <?php endif; ?>
               <?php if (es_admin()): ?>
                    <li class="nav-item"><a class="nav-link <?= (basename($_SERVER['PHP_SELF']) == 'gestionar_usuarios.php') ? 'active' : '' ?>" aria-current="page" href="gestionar_usuarios.php">Gestionar Usuarios</a></li>
               <?php endif; ?>
            </ul>
            <form class="d-flex ms-auto" action="logout.php" method="post">
                <button class="btn btn-outline-light" type="submit">Cerrar sesión</button>
            </form>
        </div>
    </div>
</nav>

<div class="container-main container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="page-title mb-0"><i class="bi bi-people-fill"></i> Gestión de Usuarios</h3>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCrearUsuario">
            <i class="bi bi-person-plus-fill"></i> Crear Nuevo Usuario
        </button>
    </div>

    <?php if ($mensaje_accion) echo $mensaje_accion; ?>

    <div class="card shadow-sm">
        <div class="card-header card-header-custom">
            <h5 class="mb-0">Lista de Usuarios Registrados</h5>
        </div>
        <div class="card-body">
            <?php if (!empty($usuarios_listados)): ?>
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Usuario (Login)</th>
                            <th>Nombre Completo</th>
                            <th>Rol</th>
                            <th>Estado</th>
                            <th>Fecha Creación</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usuarios_listados as $usuario_item): ?>
                        <tr>
                            <td><?= htmlspecialchars($usuario_item['id']) ?></td>
                            <td><?= htmlspecialchars($usuario_item['usuario']) ?></td>
                            <td><?= htmlspecialchars($usuario_item['nombre_completo']) ?></td>
                            <td><span class="badge bg-info text-dark"><?= htmlspecialchars(ucfirst($usuario_item['rol'])) ?></span></td>
                            <td>
                                <?php if ($usuario_item['activo']): ?>
                                    <span class="badge bg-success">Activo</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Inactivo</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars(date("d/m/Y H:i", strtotime($usuario_item['fecha_creacion']))) ?></td>
                            <td>
                                <a href="gestionar_usuarios.php?accion=editar&id=<?= $usuario_item['id'] ?>" class="action-icon text-warning" title="Editar Usuario"><i class="bi bi-pencil-square"></i></a>
                                <?php if ($usuario_item['activo']): ?>
                                    <a href="gestionar_usuarios.php?accion=desactivar&id=<?= $usuario_item['id'] ?>" class="action-icon text-secondary" title="Desactivar Usuario" onclick="return confirm('¿Está seguro de que desea desactivar este usuario?');"><i class="bi bi-toggle-off"></i></a>
                                <?php else: ?>
                                    <a href="gestionar_usuarios.php?accion=activar&id=<?= $usuario_item['id'] ?>" class="action-icon text-success" title="Activar Usuario" onclick="return confirm('¿Está seguro de que desea activar este usuario?');"><i class="bi bi-toggle-on"></i></a>
                                <?php endif; ?>
                                </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <div class="alert alert-info">No hay usuarios registrados.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="modal fade" id="modalCrearUsuario" tabindex="-1" aria-labelledby="modalCrearUsuarioLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" action="gestionar_usuarios.php" id="formCrearUsuario">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalCrearUsuarioLabel"><i class="bi bi-person-plus-fill"></i> Crear Nuevo Usuario</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="nuevo_nombre_usuario" class="form-label">Nombre de Usuario (para login) <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="nuevo_nombre_usuario" name="nuevo_nombre_usuario" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="nuevo_nombre_completo" class="form-label">Nombre Completo <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="nuevo_nombre_completo" name="nuevo_nombre_completo" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="nueva_clave" class="form-label">Contraseña <span class="text-danger">*</span></label>
                            <input type="password" class="form-control form-control-sm" id="nueva_clave" name="nueva_clave" required minlength="6">
                            <small class="form-text text-muted">Mínimo 6 caracteres.</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="confirmar_nueva_clave" class="form-label">Confirmar Contraseña <span class="text-danger">*</span></label>
                            <input type="password" class="form-control form-control-sm" id="confirmar_nueva_clave" name="confirmar_nueva_clave" required>
                        </div>
                    </div>
                    <div class="row">
                         <div class="col-md-6 mb-3">
                            <label for="nuevo_rol" class="form-label">Rol <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" id="nuevo_rol" name="nuevo_rol" required>
                                <option value="">Seleccione un rol...</option>
                                <option value="admin">Administrador</option>
                                <option value="tecnico">Técnico</option>
                                <option value="auditor">Auditor</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3 d-flex align-items-center mt-3">
                             <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="nuevo_activo" name="nuevo_activo" value="1" checked>
                                <label class="form-check-label" for="nuevo_activo">Usuario Activo</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" name="crear_usuario_submit" class="btn btn-sm btn-primary"><i class="bi bi-check-lg"></i> Crear Usuario</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if (isset($conexion)) { $conexion->close(); } ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>