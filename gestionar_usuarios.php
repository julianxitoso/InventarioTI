<?php
session_start();
require_once 'backend/auth_check.php';
restringir_acceso_pagina(['admin']); // Solo admin puede gestionar todo esto

require_once 'backend/db.php';
// historial_helper.php no se usa directamente para la visualización principal aquí, pero sí en acciones.
// require_once 'backend/historial_helper.php'; 

if (isset($conn) && !isset($conexion)) {
    $conexion = $conn;
}
if (!isset($conexion) || !$conexion || (method_exists($conexion, 'connect_error') && $conexion->connect_error)) {
    error_log("Error de conexión BD en gestionar_usuarios.php: " . ($conexion->connect_error ?? 'Desconocido'));
    die("Error de conexión a la base de datos.");
}
$conexion->set_charset("utf8mb4");

$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Administrador';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'admin';

// --- Datos para los desplegables del formulario de usuarios ---
$regionales_usuarios_form = ['Popayan', 'Bordo', 'Santander', 'Valle', 'Pasto', 'Tuquerres', 'Huila', 'Nacional', 'N/A'];
$empresas_usuarios_form = ['Arpesod', 'Finansueños', 'N/A'];
$roles_disponibles_form = [];
$result_roles_db = $conexion->query("SELECT nombre_rol FROM roles ORDER BY nombre_rol ASC");
if ($result_roles_db) {
    while($row_rol = $result_roles_db->fetch_assoc()) {
        $roles_disponibles_form[] = $row_rol['nombre_rol'];
    }
} else {
    // Manejar el error si no se pueden obtener los roles, podrías mostrar un mensaje o loguear.
    error_log("Error al obtener la lista de roles: " . $conexion->error);
    // Podrías mantener una lista por defecto como fallback si la consulta falla
    // $roles_disponibles_form = ['registrador', 'auditor', 'tecnico', 'admin']; 
}
// --- Fin Obtener Roles ---

// <<< INICIO: Obtener Cargos desde la Base de Datos >>>
$cargos_disponibles_form = [];
$sql_cargos = "SELECT nombre_cargo FROM cargos ORDER BY nombre_cargo ASC";
$result_cargos = $conexion->query($sql_cargos);
if ($result_cargos) {
    while ($row_cargo = $result_cargos->fetch_assoc()) {
        $cargos_disponibles_form[] = $row_cargo['nombre_cargo'];
    }
} else {
    error_log("Error al obtener la lista de cargos desde la BD: " . $conexion->error);
    // Fallback a una lista vacía o un mensaje si la consulta falla.
    // Si quieres, puedes añadir un mensaje de error aquí también.
    // $mensaje_accion .= "<div class='alert alert-warning'>Advertencia: No se pudieron cargar los cargos desde la base de datos.</div>";
}
// <<< FIN: Obtener Cargos desde la Base de Datos >>>

sort($cargos_disponibles_form);

$mensaje_accion = $_SESSION['mensaje_accion_usuarios'] ?? null;
if (isset($_SESSION['mensaje_accion_usuarios'])) {
    unset($_SESSION['mensaje_accion_usuarios']);
}

$usuario_para_editar = null;

// --- PROCESAR ACCIONES POST (Crear o Actualizar Usuario) ---
// Esta lógica se mantiene para la gestión de usuarios en esta misma página
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['crear_usuario_submit'])) {
        // ... (tu código existente para CREAR USUARIO sin cambios) ...
        $nuevo_usuario_login = trim($_POST['nuevo_usuario_login']);
        $nuevo_nombre_completo = trim($_POST['nuevo_nombre_completo']);
        $nueva_clave = $_POST['nueva_clave'];
        $confirmar_nueva_clave = $_POST['confirmar_nueva_clave'];
        $nuevo_cargo = trim($_POST['nuevo_cargo']);
        $nueva_empresa = $_POST['nueva_empresa'];
        $nueva_regional = $_POST['nueva_regional'];
        $nuevo_rol = $_POST['nuevo_rol'];
        $nuevo_activo = isset($_POST['nuevo_activo']) ? 1 : 0;

        if (empty($nuevo_usuario_login) || empty($nuevo_nombre_completo) || empty($nueva_clave) || empty($nuevo_cargo) || empty($nueva_empresa) || empty($nueva_regional) || empty($nuevo_rol)) {
            $mensaje_accion = "<div class='alert alert-danger'>Creación: Todos los campos marcados con * son obligatorios.</div>";
        } elseif (strlen($nueva_clave) < 6) {
            $mensaje_accion = "<div class='alert alert-danger'>Creación: La contraseña debe tener al menos 6 caracteres.</div>";
        } elseif ($nueva_clave !== $confirmar_nueva_clave) {
            $mensaje_accion = "<div class='alert alert-danger'>Creación: Las contraseñas no coinciden.</div>";
        } elseif (!in_array($nuevo_rol, $roles_disponibles_form)) {
            $mensaje_accion = "<div class='alert alert-danger'>Creación: Rol no válido.</div>";
        } elseif (!in_array($nuevo_cargo, $cargos_disponibles_form)) { 
            $mensaje_accion = "<div class='alert alert-danger'>Creación: Cargo no válido.</div>";
        } else {
            $stmt_check = $conexion->prepare("SELECT id FROM usuarios WHERE usuario = ?");
            $stmt_check->bind_param("s", $nuevo_usuario_login);
            $stmt_check->execute();
            $stmt_check->store_result();
            if ($stmt_check->num_rows > 0) {
                $mensaje_accion = "<div class='alert alert-danger'>El nombre de usuario (cédula) '" . htmlspecialchars($nuevo_usuario_login) . "' ya existe.</div>";
            } else {
                $clave_hasheada = password_hash($nueva_clave, PASSWORD_DEFAULT);
                $sql_insert = "INSERT INTO usuarios (usuario, clave, nombre_completo, cargo, empresa, regional, rol, activo) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt_insert = $conexion->prepare($sql_insert);
                $stmt_insert->bind_param("sssssssi", $nuevo_usuario_login, $clave_hasheada, $nuevo_nombre_completo, $nuevo_cargo, $nueva_empresa, $nueva_regional, $nuevo_rol, $nuevo_activo);
                if ($stmt_insert->execute()) {
                    $_SESSION['mensaje_accion_usuarios'] = "<div class='alert alert-success'>Usuario '" . htmlspecialchars($nuevo_usuario_login) . "' creado exitosamente.</div>";
                    header("Location: gestionar_usuarios.php");
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
    elseif (isset($_POST['editar_usuario_submit'])) {
        // ... (tu código existente para ACTUALIZAR USUARIO sin cambios) ...
        $id_usuario_actualizar = (int)($_POST['id_usuario_editar'] ?? 0);
        $edit_usuario_login = trim($_POST['edit_usuario_login']);
        $edit_nombre_completo = trim($_POST['edit_nombre_completo']);
        $edit_cargo = trim($_POST['edit_cargo']);
        $edit_empresa = $_POST['edit_empresa'];
        $edit_regional = $_POST['edit_regional'];
        $edit_rol = $_POST['edit_rol'];
        $edit_activo = isset($_POST['edit_activo']) ? 1 : 0;
        $edit_clave = $_POST['edit_clave'];
        $edit_confirmar_clave = $_POST['edit_confirmar_clave'];

        if ($id_usuario_actualizar <= 0) {
            $mensaje_accion = "<div class='alert alert-danger'>Edición: ID de usuario no válido.</div>";
        } elseif (empty($edit_usuario_login) || empty($edit_nombre_completo) || empty($edit_cargo) || empty($edit_empresa) || empty($edit_regional) || empty($edit_rol)) {
            $mensaje_accion = "<div class='alert alert-danger'>Edición: Todos los campos (excepto contraseña) son obligatorios.</div>";
        } elseif (!empty($edit_clave) && strlen($edit_clave) < 6) {
            $mensaje_accion = "<div class='alert alert-danger'>Edición: La nueva contraseña debe tener al menos 6 caracteres.</div>";
        } elseif (!empty($edit_clave) && $edit_clave !== $edit_confirmar_clave) {
            $mensaje_accion = "<div class='alert alert-danger'>Edición: Las nuevas contraseñas no coinciden.</div>";
        } elseif (empty($edit_clave) && !empty($edit_confirmar_clave)) {
            $mensaje_accion = "<div class='alert alert-danger'>Edición: Si desea cambiar la contraseña, complete el campo 'Nueva Contraseña'.</div>";
        } elseif (!in_array($edit_rol, $roles_disponibles_form)) {
            $mensaje_accion = "<div class='alert alert-danger'>Edición: Rol no válido.</div>";
        } elseif (!in_array($edit_cargo, $cargos_disponibles_form)) {
            $mensaje_accion = "<div class='alert alert-danger'>Edición: Cargo no válido.</div>";
        } else {
            $stmt_check = $conexion->prepare("SELECT id FROM usuarios WHERE usuario = ? AND id != ?");
            $stmt_check->bind_param("si", $edit_usuario_login, $id_usuario_actualizar);
            $stmt_check->execute();
            $stmt_check->store_result();
            if ($stmt_check->num_rows > 0) {
                $mensaje_accion = "<div class='alert alert-danger'>El nombre de usuario (cédula) '" . htmlspecialchars($edit_usuario_login) . "' ya está en uso por otro usuario.</div>";
            } else {
                $sql_parts = ["usuario = ?", "nombre_completo = ?", "cargo = ?", "empresa = ?", "regional = ?", "rol = ?", "activo = ?"];
                $params = [$edit_usuario_login, $edit_nombre_completo, $edit_cargo, $edit_empresa, $edit_regional, $edit_rol, $edit_activo];
                $types = "ssssssi";
                if (!empty($edit_clave)) {
                    $clave_hasheada_edit = password_hash($edit_clave, PASSWORD_DEFAULT);
                    $sql_parts[] = "clave = ?";
                    $params[] = $clave_hasheada_edit;
                    $types .= "s";
                }
                $params[] = $id_usuario_actualizar;
                $types .= "i";
                $sql_update = "UPDATE usuarios SET " . implode(", ", $sql_parts) . " WHERE id = ?";
                $stmt_update = $conexion->prepare($sql_update);
                if ($stmt_update) {
                    $stmt_update->bind_param($types, ...$params);
                    if ($stmt_update->execute()) {
                        $_SESSION['mensaje_accion_usuarios'] = "<div class='alert alert-success'>Usuario '" . htmlspecialchars($edit_usuario_login) . "' actualizado exitosamente.</div>";
                        header("Location: gestionar_usuarios.php");
                        exit;
                    } else {
                        $mensaje_accion = "<div class='alert alert-danger'>Error al actualizar el usuario: " . $stmt_update->error . "</div>";
                        error_log("Error al actualizar usuario ID $id_usuario_actualizar: " . $stmt_update->error);
                    }
                    $stmt_update->close();
                } else {
                    $mensaje_accion = "<div class='alert alert-danger'>Error al preparar la actualización: " . $conexion->error . "</div>";
                    error_log("Error al preparar la actualización del usuario ID $id_usuario_actualizar: " . $conexion->error);
                }
            }
            $stmt_check->close();
        }
        if (!empty($mensaje_accion) && $id_usuario_actualizar > 0) { // Para re-abrir modal si hay error
            $stmt_reload = $conexion->prepare("SELECT id, usuario, nombre_completo, cargo, empresa, regional, rol, activo FROM usuarios WHERE id = ?");
            if($stmt_reload) {
                $stmt_reload->bind_param("i", $id_usuario_actualizar);
                $stmt_reload->execute();
                $result_reload = $stmt_reload->get_result();
                if ($result_reload->num_rows === 1) {
                    $usuario_para_editar = $result_reload->fetch_assoc();
                }
                $stmt_reload->close();
            }
        }
    }
}

// --- Lógica para cargar datos para editar (GET) ---
// Esta lógica se mantiene para la gestión de usuarios en esta misma página
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['accion'])) {
    // ... (tu código existente para acciones GET (editar, activar, desactivar) sin cambios) ...
    $accion_get = $_GET['accion'];
    $id_usuario_get = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id_usuario_get > 0) {
        if ($accion_get === 'editar' && !$usuario_para_editar) {
            // ...
            $stmt_edit = $conexion->prepare("SELECT id, usuario, nombre_completo, cargo, empresa, regional, rol, activo FROM usuarios WHERE id = ?");
            if ($stmt_edit) {
                $stmt_edit->bind_param("i", $id_usuario_get);
                $stmt_edit->execute();
                $result_edit = $stmt_edit->get_result();
                if ($result_edit->num_rows === 1) {
                    $usuario_para_editar = $result_edit->fetch_assoc();
                } else {
                    $_SESSION['mensaje_accion_usuarios'] = "<div class='alert alert-warning'>Usuario no encontrado para editar.</div>";
                    header("Location: gestionar_usuarios.php");
                    exit;
                }
                $stmt_edit->close();
            } else {
                $_SESSION['mensaje_accion_usuarios'] = "<div class='alert alert-danger'>Error al preparar datos para edición.</div>";
                header("Location: gestionar_usuarios.php");
                exit;
            }
        } elseif ($accion_get === 'activar' || $accion_get === 'desactivar') {
            // ...
            $can_toggle = true;
            if ($id_usuario_get == 1) {  $can_toggle = false; } 
            else {
                $stmt_check_admin = $conexion->prepare("SELECT usuario FROM usuarios WHERE id = ?");
                $stmt_check_admin->bind_param("i", $id_usuario_get);
                $stmt_check_admin->execute();
                $res_check_admin = $stmt_check_admin->get_result();
                $user_to_toggle = $res_check_admin->fetch_assoc();
                if ($user_to_toggle && $user_to_toggle['usuario'] === 'admin') { $can_toggle = false; }
                $stmt_check_admin->close();
            }
            if (!$can_toggle) {
                $_SESSION['mensaje_accion_usuarios'] = "<div class='alert alert-danger'>No se puede cambiar el estado del administrador principal.</div>";
            } else {
                $nuevo_estado = ($accion_get === 'activar') ? 1 : 0;
                $texto_accion = ($accion_get === 'activar') ? 'activado' : 'desactivado';
                $stmt_toggle = $conexion->prepare("UPDATE usuarios SET activo = ? WHERE id = ?");
                $stmt_toggle->bind_param("ii", $nuevo_estado, $id_usuario_get);
                if ($stmt_toggle->execute()) {
                    $_SESSION['mensaje_accion_usuarios'] = "<div class='alert alert-info'>Usuario ID " . htmlspecialchars($id_usuario_get) . " ha sido " . $texto_accion . ".</div>";
                } else {
                    $_SESSION['mensaje_accion_usuarios'] = "<div class='alert alert-danger'>Error al " . $accion_get . " el usuario ID " . htmlspecialchars($id_usuario_get) . ".</div>";
                }
                $stmt_toggle->close();
            }
            header("Location: gestionar_usuarios.php");
            exit;
        }
    }
}

// --- Obtener lista de usuarios para mostrar ---
// Esta lógica se mantiene para la gestión de usuarios en esta misma página
$usuarios_listados = [];
$sql_listar = "SELECT id, usuario, nombre_completo, cargo, empresa, regional, rol, activo, fecha_creacion FROM usuarios ORDER BY nombre_completo ASC";
$result_listar = $conexion->query($sql_listar);
if ($result_listar) {
    while ($row = $result_listar->fetch_assoc()) {
        $usuarios_listados[] = $row;
    }
} else {
    if (!$mensaje_accion) { // Solo mostrar si no hay otro mensaje de acción
        $mensaje_accion = "<div class='alert alert-danger'>Error al obtener la lista de usuarios: " . $conexion->error . "</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Centro de Gestión - Inventario TI</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding-top: 100px; background-color: #f8f9fa; }
        .top-bar-custom { position: fixed; top: 0; left: 0; right: 0; z-index: 1030; display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 1.5rem; background-color: #f8f9fa; border-bottom: 1px solid #dee2e6; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .logo-container-top img { width: auto; height: 75px; object-fit: contain; margin-right: 15px; }
        .user-info-top { font-size: 0.9rem; }
        .container-main { margin-top: 20px; margin-bottom: 40px; }
        .page-title { color: #191970; font-weight: 600; }
        .card { border: 1px solid #e0e0e0; box-shadow: none; background-color: #ffffff; }
        .card-header-custom { background-color: #ffffff; border-bottom: 1px solid #e0e0e0; padding: 0.75rem 1.25rem; }
        .card-header-custom h5 { color: #343a40; font-weight: 500; }
        .table th { white-space: nowrap; font-size: 0.9rem; font-weight: 500; color: #495057; background-color: #f8f9fa; border-top: none; border-bottom-width: 1px; } /* Reducido tamaño de fuente */
        .table td { vertical-align: middle; font-size: 0.88rem; border-top: 1px solid #f1f1f1; } /* Reducido tamaño de fuente */
        .table-hover tbody tr:hover { background-color: #fcfcfc; }
        .badge.bg-info { background-color: #e2e3e5 !important; color: #343a40 !important; }
        .badge.rounded-pill { font-weight: 500; padding: 0.35em 0.65em; }
        .action-icon { font-size: 1.1rem; margin-right: 0.4rem; text-decoration: none; color: #6c757d; }
        .action-icon.text-warning:hover, .action-icon[title="Editar Usuario"]:hover { color: #ffc107 !important; }
        .action-icon.text-secondary:hover, .action-icon[title="Desactivar Usuario"]:hover { color: #545b62 !important; }
        .action-icon.text-success:hover, .action-icon[title="Activar Usuario"]:hover { color: #218838 !important; }
        .btn-principal { background-color: #191970; border-color: #191970; color: #ffffff; }
        .btn-principal:hover { background-color: #111150; border-color: #111150; color: #ffffff; }
        .modal-header-principal { background-color: #ffffff; color: #191970; border-bottom: 1px solid #e0e0e0; }
        .modal-header-principal .btn-close { filter: none; }
        .modal-header-principal .modal-title i { color: #191970; }
        .modal-content { border: 1px solid #d4d4d4; box-shadow: 0 .5rem 1rem rgba(0,0,0,.1); }
        .form-select-sm, .form-control-sm { font-size: 0.875rem; }
        /* Estilos para las nuevas tarjetas de gestión */
        .management-hub-cards .card { cursor: pointer; transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out; }
        .management-hub-cards .card:hover { transform: translateY(-5px); box-shadow: 0 0.5rem 1rem rgba(0,0,0,.15); }
        .management-hub-cards .card-body { text-align: center; }
        .management-hub-cards .card i { font-size: 2.5rem; margin-bottom: 0.5rem; }
        .management-hub-cards .card-title { font-size: 1.1rem; }
    </style>
</head>
<body>
<div class="top-bar-custom">
    <div class="logo-container-top"><a href="menu.php" title="Ir a Inicio"><img src="imagenes/logo.png" alt="Logo ARPESOD ASOCIADOS SAS"></a></div>
    <div class="d-flex align-items-center">
        <span class="text-dark me-3 user-info-top"><i class="bi bi-person-circle"></i> <?= htmlspecialchars($nombre_usuario_actual_sesion) ?> (<?= htmlspecialchars(ucfirst($rol_usuario_actual_sesion)) ?>)</span>
        <form action="logout.php" method="post" class="d-flex"><button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</button></form>
    </div>
</div>

<div class="container-main container mt-4">
    <h3 class="page-title text-center mb-4">Centro de Gestión</h3>

    <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4 mb-5 management-hub-cards justify-content-center">
        <div class="col">
            <a href="gestionar_roles.php" class="text-decoration-none text-dark">
                <div class="card h-100">
                    <div class="card-body">
                        <i class="bi bi-shield-lock-fill text-primary"></i>
                        <h5 class="card-title mt-2">Gestionar Roles y Permisos</h5>
                        <p class="card-text small text-muted">Definir y asignar roles a los usuarios.</p>
                    </div>
                </div>
            </a>
        </div>
        <div class="col">
            <a href="gestionar_proveedores.php" class="text-decoration-none text-dark">
                <div class="card h-100">
                    <div class="card-body">
                        <i class="bi bi-truck text-success"></i>
                        <h5 class="card-title mt-2">Gestionar Proveedores</h5>
                        <p class="card-text small text-muted">Administrar proveedores de mantenimiento.</p>
                    </div>
                </div>
            </a>
        </div>
        <div class="col">
            <a href="gestionar_cargos.php" class="text-decoration-none text-dark">
                <div class="card h-100">
                    <div class="card-body">
                        <i class="bi bi-person-badge-fill text-info"></i>
                        <h5 class="card-title mt-2">Gestionar Cargos</h5>
                        <p class="card-text small text-muted">Administrar los cargos de los empleados.</p>
                    </div>
                </div>
            </a>
        </div>
    </div>
    <hr class="my-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="page-title mb-0" style="font-size: 1.5rem;"><i class="bi bi-people-fill"></i> Gestión de Usuarios del Sistema</h4>
        <button type="button" class="btn btn-principal btn-sm" data-bs-toggle="modal" data-bs-target="#modalCrearUsuario">
            <i class="bi bi-person-plus-fill"></i> Crear Nuevo Usuario
        </button>
    </div>

    <?php if ($mensaje_accion) echo $mensaje_accion; ?>

    <div class="card">
        <div class="card-header card-header-custom"><h5 class="mb-0">Lista de Usuarios Registrados</h5></div>
        <div class="card-body p-0">
            <?php if (!empty($usuarios_listados)): ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th><th>Usuario</th><th>Nombre</th><th>Cargo</th><th>Empresa</th><th>Regional</th><th>Rol</th><th>Estado</th><th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($usuarios_listados as $usuario_item): ?>
                                <tr>
                                    <td><?= htmlspecialchars($usuario_item['id']) ?></td>
                                    <td><?= htmlspecialchars($usuario_item['usuario']) ?></td>
                                    <td><?= htmlspecialchars($usuario_item['nombre_completo']) ?></td>
                                    <td><?= htmlspecialchars($usuario_item['cargo'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($usuario_item['empresa'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($usuario_item['regional'] ?? 'N/A') ?></td>
                                    <td><span class="badge rounded-pill bg-info text-dark"><?= htmlspecialchars(ucfirst($usuario_item['rol'])) ?></span></td>
                                    <td><?php if ($usuario_item['activo']): ?><span class="badge rounded-pill bg-success">Activo</span><?php else: ?><span class="badge rounded-pill bg-danger">Inactivo</span><?php endif; ?></td>
                                    <td>
                                        <a href="gestionar_usuarios.php?accion=editar&id=<?= $usuario_item['id'] ?>#formEditarUsuarioAncla" class="action-icon text-warning" title="Editar Usuario"><i class="bi bi-pencil-square"></i></a>
                                        <?php if (strtolower($usuario_item['usuario']) !== 'admin' && $usuario_item['id'] !== 1): // No se puede desactivar el admin principal ?>
                                            <?php if ($usuario_item['activo']): ?>
                                                <a href="gestionar_usuarios.php?accion=desactivar&id=<?= $usuario_item['id'] ?>" class="action-icon text-secondary" title="Desactivar Usuario" onclick="return confirm('¿Está seguro de que desea desactivar este usuario: <?= htmlspecialchars($usuario_item['usuario']) ?>?');"><i class="bi bi-person-fill-slash"></i></a>
                                            <?php else: ?>
                                                <a href="gestionar_usuarios.php?accion=activar&id=<?= $usuario_item['id'] ?>" class="action-icon text-success" title="Activar Usuario" onclick="return confirm('¿Está seguro de que desea activar este usuario: <?= htmlspecialchars($usuario_item['usuario']) ?>?');"><i class="bi bi-person-fill-check"></i></a>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <i class="bi bi-person-fill-lock action-icon text-muted" title="No se puede cambiar el estado del administrador principal"></i>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-info m-3">No hay usuarios registrados.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="modal fade" id="modalCrearUsuario" tabindex="-1" aria-labelledby="modalCrearUsuarioLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" action="gestionar_usuarios.php" id="formCrearUsuario">
                <div class="modal-header modal-header-principal">
                    <h5 class="modal-title" id="modalCrearUsuarioLabel"><i class="bi bi-person-plus-fill"></i> Crear Nuevo Usuario</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="nuevo_usuario_login" class="form-label">Usuario (Cédula para login) <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="nuevo_usuario_login" name="nuevo_usuario_login" required pattern="[0-9]+" title="Solo números">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="nuevo_nombre_completo" class="form-label">Nombre Completo <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="nuevo_nombre_completo" name="nuevo_nombre_completo" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="nuevo_cargo" class="form-label">Cargo <span class="text-danger">*</span></label>
                        <select class="form-select form-select-sm" id="nuevo_cargo" name="nuevo_cargo" required>
                            <option value="">Seleccione un cargo...</option>
                            <?php foreach ($cargos_disponibles_form as $cargo): ?>
                                <option value="<?= htmlspecialchars($cargo) ?>"><?= htmlspecialchars($cargo) ?></option>
                            <?php endforeach; ?>
                        </select>
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
                            <label for="nueva_empresa" class="form-label">Empresa <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" id="nueva_empresa" name="nueva_empresa" required>
                                <option value="">Seleccione...</option>
                                <?php foreach ($empresas_usuarios_form as $e): ?><option value="<?= htmlspecialchars($e) ?>"><?= htmlspecialchars($e) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="nueva_regional" class="form-label">Regional <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" id="nueva_regional" name="nueva_regional" required>
                                <option value="">Seleccione...</option>
                                <?php foreach ($regionales_usuarios_form as $r): ?><option value="<?= htmlspecialchars($r) ?>"><?= htmlspecialchars($r) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="nuevo_rol" class="form-label">Rol <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" id="nuevo_rol" name="nuevo_rol" required>
                                <option value="">Seleccione un rol...</option>
                                <?php foreach ($roles_disponibles_form as $rol_opt): ?><option value="<?= htmlspecialchars($rol_opt) ?>"><?= htmlspecialchars(ucfirst($rol_opt)) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3 d-flex align-items-center mt-md-3 pt-md-2"> 
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="nuevo_activo" name="nuevo_activo" value="1" checked>
                                <label class="form-check-label" for="nuevo_activo">Usuario Activo</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" name="crear_usuario_submit" class="btn btn-sm btn-principal"><i class="bi bi-check-lg"></i> Crear Usuario</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($usuario_para_editar): ?>
<div class="modal fade" id="modalEditarUsuario" tabindex="-1" aria-labelledby="modalEditarUsuarioLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" action="gestionar_usuarios.php" id="formEditarUsuarioAncla"> {/* ID para ancla */}
                <input type="hidden" name="id_usuario_editar" value="<?= htmlspecialchars($usuario_para_editar['id']) ?>">
                <div class="modal-header modal-header-principal">
                    <h5 class="modal-title" id="modalEditarUsuarioLabel"><i class="bi bi-pencil-square"></i> Editar Usuario: <?= htmlspecialchars($usuario_para_editar['nombre_completo']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_usuario_login" class="form-label">Usuario (Login) <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="edit_usuario_login" name="edit_usuario_login" value="<?= htmlspecialchars($usuario_para_editar['usuario']) ?>" required pattern="[0-9]+" title="Solo números">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_nombre_completo" class="form-label">Nombre Completo <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="edit_nombre_completo" name="edit_nombre_completo" value="<?= htmlspecialchars($usuario_para_editar['nombre_completo']) ?>" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="edit_cargo" class="form-label">Cargo <span class="text-danger">*</span></label>
                        <select class="form-select form-select-sm" id="edit_cargo" name="edit_cargo" required>
                            <option value="">Seleccione un cargo...</option>
                            <?php foreach ($cargos_disponibles_form as $cargo): ?>
                                <option value="<?= htmlspecialchars($cargo) ?>" <?= ($usuario_para_editar['cargo'] ?? '') == $cargo ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cargo) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_empresa" class="form-label">Empresa <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" id="edit_empresa" name="edit_empresa" required>
                                <option value="">Seleccione...</option>
                                <?php foreach ($empresas_usuarios_form as $e): ?><option value="<?= htmlspecialchars($e) ?>" <?= ($usuario_para_editar['empresa'] ?? '') == $e ? 'selected' : '' ?>><?= htmlspecialchars($e) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_regional" class="form-label">Regional <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" id="edit_regional" name="edit_regional" required>
                                <option value="">Seleccione...</option>
                                <?php foreach ($regionales_usuarios_form as $r): ?><option value="<?= htmlspecialchars($r) ?>" <?= ($usuario_para_editar['regional'] ?? '') == $r ? 'selected' : '' ?>><?= htmlspecialchars($r) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                         <div class="col-md-6 mb-3">
                            <label for="edit_rol" class="form-label">Rol <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" id="edit_rol" name="edit_rol" required <?= (strtolower($usuario_para_editar['usuario']) === 'admin' || $usuario_para_editar['id'] === 1) ? 'disabled' : '' ?>>
                                <option value="">Seleccione un rol...</option>
                                <?php foreach ($roles_disponibles_form as $rol_opt): ?><option value="<?= htmlspecialchars($rol_opt) ?>" <?= $usuario_para_editar['rol'] == $rol_opt ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($rol_opt)) ?></option><?php endforeach; ?>
                            </select>
                            <?php if (strtolower($usuario_para_editar['usuario']) === 'admin' || $usuario_para_editar['id'] === 1): ?><small class="form-text text-muted">El rol del administrador principal no se puede cambiar.</small><input type="hidden" name="edit_rol" value="<?= htmlspecialchars($usuario_para_editar['rol']) ?>"><?php endif; ?>
                        </div>
                        <div class="col-md-6 mb-3 d-flex align-items-center mt-md-3 pt-md-2">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="edit_activo" name="edit_activo" value="1" <?= $usuario_para_editar['activo'] ? 'checked' : '' ?> <?= (strtolower($usuario_para_editar['usuario']) === 'admin' || $usuario_para_editar['id'] === 1) ? 'disabled' : '' ?>>
                                <label class="form-check-label" for="edit_activo">Usuario Activo</label>
                            </div>
                            <?php if (strtolower($usuario_para_editar['usuario']) === 'admin' || $usuario_para_editar['id'] === 1): ?><input type="hidden" name="edit_activo" value="1"><?php endif; ?>
                        </div>
                    </div>
                    <hr>
                    <p class="text-muted small">Cambiar Contraseña (opcional):</p>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_clave" class="form-label">Nueva Contraseña</label>
                            <input type="password" class="form-control form-control-sm" id="edit_clave" name="edit_clave" minlength="6">
                            <small class="form-text text-muted">Dejar en blanco para no cambiar.</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_confirmar_clave" class="form-label">Confirmar Nueva Contraseña</label>
                            <input type="password" class="form-control form-control-sm" id="edit_confirmar_clave" name="edit_confirmar_clave">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" name="editar_usuario_submit" class="btn btn-sm btn-principal"><i class="bi bi-save"></i> Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
    // Script para abrir el modal de edición si hay un error o se viene de un GET
    document.addEventListener('DOMContentLoaded', function () {
        const modalEditarElement = document.getElementById('modalEditarUsuario');
        let modalEditarInstancia = null;
        function abrirModalSiEsNecesario() {
            const urlParams = new URLSearchParams(window.location.search);
            const accion = urlParams.get('accion');
            const idUsuario = urlParams.get('id');
            const hash = window.location.hash;

            if (modalEditarElement) {
                if (!modalEditarInstancia) { 
                    modalEditarInstancia = new bootstrap.Modal(modalEditarElement);
                }
                // Si hay mensaje de acción Y el usuario para editar está cargado (implica error en POST de edición) O si viene de un GET para editar
                if ( (document.querySelector('.alert-danger') && <?= json_encode($usuario_para_editar !== null && isset($_POST['editar_usuario_submit'])) ?> ) || (accion === 'editar' && idUsuario) || hash === '#formEditarUsuarioAncla') {
                    if (!modalEditarElement.classList.contains('show')) {
                        modalEditarInstancia.show();
                    }
                }
            }
        }
        abrirModalSiEsNecesario();
        if (modalEditarElement) { // Evitar error si el modal no está en el DOM
            modalEditarElement.addEventListener('hidden.bs.modal', function (event) {
                const nuevaUrl = window.location.pathname; // Limpiar query params
                if (window.location.search.includes('accion=editar') || window.location.search.includes('id=') || window.location.hash) {
                    window.history.replaceState({ path: nuevaUrl }, '', nuevaUrl);
                }
            });
        }
        // ... (resto de tu script para validación de contraseñas)
        const formCrear = document.getElementById('formCrearUsuario');
        if(formCrear){
            const nuevaClave = formCrear.querySelector('#nueva_clave'),confirmarNuevaClave = formCrear.querySelector('#confirmar_nueva_clave');
            function validarClaveCrear(){if(nuevaClave && confirmarNuevaClave){if(nuevaClave.value !== confirmarNuevaClave.value){confirmarNuevaClave.setCustomValidity("Las contraseñas no coinciden.")}else{confirmarNuevaClave.setCustomValidity('')}}}
            if(nuevaClave)nuevaClave.addEventListener('input',validarClaveCrear);if(confirmarNuevaClave)confirmarNuevaClave.addEventListener('input',validarClaveCrear);
        }
        const formEditar = document.getElementById('formEditarUsuarioAncla');
        if(formEditar){
            const editClave = formEditar.querySelector('#edit_clave'),editConfirmarClave = formEditar.querySelector('#edit_confirmar_clave');
            function validarClaveEditar(){if(editClave && editConfirmarClave){if(editClave.value !== '' && editClave.value !== editConfirmarClave.value){editConfirmarClave.setCustomValidity("Las nuevas contraseñas no coinciden.")}else if(editClave.value === '' && editConfirmarClave.value !==''){editConfirmarClave.setCustomValidity("Escriba la nueva contraseña primero si desea cambiarla.")}else{editConfirmarClave.setCustomValidity('')}}}
            if(editClave)editClave.addEventListener('input',validarClaveEditar);if(editConfirmarClave)editConfirmarClave.addEventListener('input',validarClaveEditar);
        }

    });
</script>
<?php endif; ?>

<?php if (isset($conexion)) { $conexion->close(); } ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>