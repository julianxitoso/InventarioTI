<?php
session_start();
require_once 'backend/auth_check.php';
restringir_acceso_pagina(['admin']);

require_once 'backend/db.php';
require_once 'backend/historial_helper.php';

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

// --- Datos para los desplegables ---
$regionales_usuarios_form = ['Popayan', 'Bordo', 'Santander', 'Valle', 'Pasto', 'Tuquerres', 'Huila', 'Nacional', 'N/A'];
$empresas_usuarios_form = ['Arpesod', 'Finansueños', 'N/A'];
$roles_disponibles_form = ['registrador', 'auditor', 'tecnico', 'admin'];

$mensaje_accion = $_SESSION['mensaje_accion_usuarios'] ?? null;
if (isset($_SESSION['mensaje_accion_usuarios'])) {
    unset($_SESSION['mensaje_accion_usuarios']);
}

$usuario_para_editar = null;
// No necesitamos $id_usuario_edicion aquí arriba, lo manejaremos dentro del bloque GET

// --- PROCESAR ACCIONES POST (Crear o Actualizar Usuario) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ------ CREAR USUARIO ------
    if (isset($_POST['crear_usuario_submit'])) {
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
        } else {
            $stmt_check = $conexion->prepare("SELECT id FROM usuarios WHERE usuario = ?");
            $stmt_check->bind_param("s", $nuevo_usuario_login);
            $stmt_check->execute();
            $stmt_check->store_result();

            if ($stmt_check->num_rows > 0) {
                $mensaje_accion = "<div class='alert alert-danger'>El nombre de usuario (cédula) '" . htmlspecialchars($nuevo_usuario_login) . "' ya existe.</div>";
            } else {
                $clave_hasheada = password_hash($nueva_clave, PASSWORD_DEFAULT);
                $sql_insert = "INSERT INTO usuarios (usuario, clave, nombre_completo, cargo, empresa, regional, rol, activo) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt_insert = $conexion->prepare($sql_insert);
                $stmt_insert->bind_param(
                    "sssssssi",
                    $nuevo_usuario_login,
                    $clave_hasheada,
                    $nuevo_nombre_completo,
                    $nuevo_cargo,
                    $nueva_empresa,
                    $nueva_regional,
                    $nuevo_rol,
                    $nuevo_activo
                );

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
    // ------ FIN CREAR USUARIO ------

    // ------ ACTUALIZAR USUARIO ------
    elseif (isset($_POST['editar_usuario_submit'])) {
        $id_usuario_actualizar = (int)($_POST['id_usuario_editar'] ?? 0);
        $edit_usuario_login = trim($_POST['edit_usuario_login']);
        $edit_nombre_completo = trim($_POST['edit_nombre_completo']);
        $edit_cargo = trim($_POST['edit_cargo']);
        $edit_empresa = $_POST['edit_empresa'];
        $edit_regional = $_POST['edit_regional'];
        $edit_rol = $_POST['edit_rol'];
        $edit_activo = isset($_POST['edit_activo']) ? 1 : 0;
        $edit_clave = $_POST['edit_clave']; // No trimear
        $edit_confirmar_clave = $_POST['edit_confirmar_clave']; // No trimear

        // Validaciones
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
        } else {
            // Verificar si el nuevo nombre de usuario ya existe PARA OTRO USUARIO
            $stmt_check = $conexion->prepare("SELECT id FROM usuarios WHERE usuario = ? AND id != ?");
            $stmt_check->bind_param("si", $edit_usuario_login, $id_usuario_actualizar);
            $stmt_check->execute();
            $stmt_check->store_result();

            if ($stmt_check->num_rows > 0) {
                $mensaje_accion = "<div class='alert alert-danger'>El nombre de usuario (cédula) '" . htmlspecialchars($edit_usuario_login) . "' ya está en uso por otro usuario.</div>";
            } else {
                $sql_parts = [
                    "usuario = ?",
                    "nombre_completo = ?",
                    "cargo = ?",
                    "empresa = ?",
                    "regional = ?",
                    "rol = ?",
                    "activo = ?"
                ];
                $params = [
                    $edit_usuario_login,
                    $edit_nombre_completo,
                    $edit_cargo,
                    $edit_empresa,
                    $edit_regional,
                    $edit_rol,
                    $edit_activo
                ];
                $types = "ssssssi";

                if (!empty($edit_clave)) {
                    $clave_hasheada_edit = password_hash($edit_clave, PASSWORD_DEFAULT);
                    $sql_parts[] = "clave = ?";
                    $params[] = $clave_hasheada_edit;
                    $types .= "s";
                }

                // Añadir el ID del usuario para el WHERE clause
                $params[] = $id_usuario_actualizar;
                $types .= "i";

                $sql_update = "UPDATE usuarios SET " . implode(", ", $sql_parts) . " WHERE id = ?";
                $stmt_update = $conexion->prepare($sql_update);

                if ($stmt_update) {
                    $stmt_update->bind_param($types, ...$params);
                    if ($stmt_update->execute()) {
                        $_SESSION['mensaje_accion_usuarios'] = "<div class='alert alert-success'>Usuario '" . htmlspecialchars($edit_usuario_login) . "' actualizado exitosamente.</div>";
                        // Opcional: registrar en historial_sistema
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
        // Si hubo un error de validación y no se redirigió, necesitamos recargar los datos para el modal
        if (!empty($mensaje_accion) && $id_usuario_actualizar > 0) {
            $stmt_reload = $conexion->prepare("SELECT id, usuario, nombre_completo, cargo, empresa, regional, rol, activo FROM usuarios WHERE id = ?");
            $stmt_reload->bind_param("i", $id_usuario_actualizar);
            $stmt_reload->execute();
            $result_reload = $stmt_reload->get_result();
            if ($result_reload->num_rows === 1) {
                $usuario_para_editar = $result_reload->fetch_assoc();
            }
            $stmt_reload->close();
        }
    }
    // ------ FIN ACTUALIZAR USUARIO ------
}


// --- LÓGICA GET (Para Editar o Activar/Desactivar) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['accion'])) {
    $accion_get = $_GET['accion'];
    $id_usuario_get = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($id_usuario_get > 0) {
        if ($accion_get === 'editar' && !$usuario_para_editar) { // Solo cargar si no viene de un POST fallido
            $stmt_edit = $conexion->prepare("SELECT id, usuario, nombre_completo, cargo, empresa, regional, rol, activo FROM usuarios WHERE id = ?");
            if ($stmt_edit) {
                $stmt_edit->bind_param("i", $id_usuario_get);
                $stmt_edit->execute();
                $result_edit = $stmt_edit->get_result();
                if ($result_edit->num_rows === 1) {
                    $usuario_para_editar = $result_edit->fetch_assoc();
                } else {
                    $_SESSION['mensaje_accion_usuarios'] = "<div class='alert alert-warning'>Usuario no encontrado para editar.</div>";
                    header("Location: gestionar_usuarios.php"); // Redirigir si no se encuentra
                    exit;
                }
                $stmt_edit->close();
            } else {
                $_SESSION['mensaje_accion_usuarios'] = "<div class='alert alert-danger'>Error al preparar datos para edición.</div>";
                header("Location: gestionar_usuarios.php");
                exit;
            }
        } elseif ($accion_get === 'activar' || $accion_get === 'desactivar') {
            // No permitir desactivar/activar el usuario 'admin' principal (si su ID es 1 o su usuario es 'admin')
            // Primero obtenemos el nombre de usuario para verificar
            $can_toggle = true;
            if ($id_usuario_get == 1) { // Asumiendo ID 1 es el superadmin
                $can_toggle = false;
            } else {
                $stmt_check_admin = $conexion->prepare("SELECT usuario FROM usuarios WHERE id = ?");
                $stmt_check_admin->bind_param("i", $id_usuario_get);
                $stmt_check_admin->execute();
                $res_check_admin = $stmt_check_admin->get_result();
                $user_to_toggle = $res_check_admin->fetch_assoc();
                if ($user_to_toggle && $user_to_toggle['usuario'] === 'admin') {
                    $can_toggle = false;
                }
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

// Obtener lista de usuarios para mostrar
$usuarios_listados = [];
// Asegúrate que tu tabla usuarios tiene las columnas: cargo, empresa, regional
$sql_listar = "SELECT id, usuario, nombre_completo, cargo, empresa, regional, rol, activo, fecha_creacion FROM usuarios ORDER BY nombre_completo ASC";
$result_listar = $conexion->query($sql_listar);
if ($result_listar) {
    while ($row = $result_listar->fetch_assoc()) {
        $usuarios_listados[] = $row;
    }
} else {
    // Evitar sobrescribir el mensaje de una acción POST o GET
    if (!$mensaje_accion) {
        $mensaje_accion = "<div class='alert alert-danger'>Error al obtener la lista de usuarios: " . $conexion->error . "</div>";
    }
}

?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Gestionar Usuarios - Inventario TI</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>

    body { 
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        padding-top: 100px; /* Ajusta ESTO si la altura REAL de tu nav es diferente */
        background-color: #f8f9fa; /* Un fondo global muy claro */
    }
    /* Estilos para la barra de navegación superior (SIN CAMBIOS, como pediste) */
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
    /* Fin estilos de la barra de navegación superior */

    .container-main { 
        margin-top: 20px; 
        margin-bottom: 40px;
    }
    .page-title { 
        color: #191970; /* Mantenemos tu color principal para el título */
        font-weight: 600; /* Un poco menos de peso si antes era más */
    }

    /* Tarjeta principal para la lista de usuarios */
    .card {
        border: 1px solid #e0e0e0; /* Borde sutil en lugar de sombra */
        box-shadow: none; /* Eliminamos la sombra */
        background-color: #ffffff; /* Fondo blanco para la tarjeta */
        }
    .card-header-custom {
        background-color: #ffffff; /* Encabezado de tarjeta blanco */
        border-bottom: 1px solid #e0e0e0; /* Solo un borde inferior */
        padding: 0.75rem 1.25rem; /* Ajuste de padding */
    }
    .card-header-custom h5 {
        color: #343a40; /* Color de texto más estándar para el encabezado */
        font-weight: 500;
    }

    /* Tabla */
    .table {
        margin-bottom: 0; /* Quitar margen si la tabla es el último elemento en card-body p-0 */
    }
    .table th { 
        white-space: nowrap; 
        font-size: 1rem; /* Un poco más pequeño */
        font-weight: 500; /* Ligeramente menos bold */
        color: #495057;
        background-color: #f8f9fa; /* Cabecera de tabla muy clara */
        border-top: none; /* Sin borde superior en la cabecera de la tabla */
        border-bottom-width: 1px; /* Borde inferior más delgado */
    }
    .table td { 
        vertical-align: middle; 
        font-size: 0.98rem;
        border-top: 1px solid #f1f1f1; /* Bordes de fila más sutiles */
    }
    .table-hover tbody tr:hover {
        background-color: #fcfcfc; /* Hover muy sutil */
    }

    /* Badges */
    .badge.bg-info { /* Para el rol */
        background-color: #e2e3e5 !important; /* Bootstrap gris claro */
        color: #343a40 !important; /* Texto oscuro para contraste */
    }
    .badge.rounded-pill {
        font-weight: 500;
        padding: 0.35em 0.65em;
    }


    /* Iconos de Acción */
    .action-icon { 
        font-size: 1.1rem; 
        margin-right: 0.4rem; 
        text-decoration: none; 
        color: #6c757d; /* Color base más neutro */
    }
    .action-icon.text-warning:hover,
    .action-icon[title="Editar Usuario"]:hover { /* Específico para editar */
        color: #ffc107 !important; 
    }
    .action-icon.text-secondary:hover,
    .action-icon[title="Desactivar Usuario"]:hover {
        color: #545b62 !important;
    }
     .action-icon.text-success:hover,
     .action-icon[title="Activar Usuario"]:hover {
        color: #218838 !important;
    }


    /* Botón Principal (Crear Nuevo Usuario) */
    .btn-principal { 
        background-color: #191970; 
        border-color: #191970; 
        color: #ffffff; 
    }
    .btn-principal:hover { 
        background-color: #111150; 
        border-color: #111150; 
        color: #ffffff; 
    }

    /* Estilos del Modal */
    .modal-header-principal {
        background-color: #ffffff; /* Encabezado de modal blanco */
        color: #191970; /* Título del modal con tu color principal */
        border-bottom: 1px solid #e0e0e0; /* Borde inferior sutil */
    }
    .modal-header-principal .btn-close {
        filter: none; /* Quitar filtro si el fondo es claro */
         /* Si necesitas que el botón de cierre sea oscuro sobre fondo claro:
         background: none;
         opacity: 0.7; 
         */
    }
    .modal-header-principal .modal-title i {
        color: #191970; /* Icono con tu color principal */
    }
    .modal-content {
        border: 1px solid #d4d4d4; /* Borde sutil para el contenido del modal */
        box-shadow: 0 .5rem 1rem rgba(0,0,0,.1); /* Sombra más suave si se desea */
    }
    .form-select-sm,
    .form-control-sm {
        font-size: 0.875rem;
    }
</style>
    </style>
</head>
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

<body>
    <div class="container-main container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3 class="page-title mb-0"><i class="bi bi-people-fill"></i> Gestión de Usuarios</h3>
            <button type="button" class="btn btn-principal btn-sm" data-bs-toggle="modal" data-bs-target="#modalCrearUsuario">
                <i class="bi bi-person-plus-fill"></i> Crear Nuevo Usuario
            </button>
        </div>

        <?php if ($mensaje_accion) echo $mensaje_accion; // Muestra mensajes de éxito/error de las acciones POST/GET 
        ?>

        <div class="card">
            <div class="card-header card-header-custom">
                <h5 class="mb-0">Lista de Usuarios Registrados</h5>
            </div>
            <div class="card-body p-0">
                <?php if (!empty($usuarios_listados)): ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Usuario</th>
                                    <th>Nombre</th>
                                    <th>Cargo</th>
                                    <th>Empresa</th>
                                    <th>Regional</th>
                                    <th>Rol</th>
                                    <th>Estado</th>
                                    <th>Acciones</th>
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
                                        <td>
                                            <?php if ($usuario_item['activo']): ?>
                                                <span class="badge rounded-pill bg-success">Activo</span>
                                            <?php else: ?>
                                                <span class="badge rounded-pill bg-danger">Inactivo</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="gestionar_usuarios.php?accion=editar&id=<?= $usuario_item['id'] ?>#formEditarUsuario" class="action-icon text-warning" title="Editar Usuario"><i class="bi bi-pencil-square"></i></a>
                                            <?php if (strtolower($usuario_item['usuario']) !== 'admin' && $usuario_item['id'] !== 1): // No permitir desactivar al usuario 'admin' principal ni ID 1 (doble seguridad) 
                                            ?>
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
                            <input type="text" class="form-control form-control-sm" id="nuevo_cargo" name="nuevo_cargo" required>
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
                                    <?php foreach ($empresas_usuarios_form as $e): ?>
                                        <option value="<?= htmlspecialchars($e) ?>"><?= htmlspecialchars($e) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="nueva_regional" class="form-label">Regional <span class="text-danger">*</span></label>
                                <select class="form-select form-select-sm" id="nueva_regional" name="nueva_regional" required>
                                    <option value="">Seleccione...</option>
                                    <?php foreach ($regionales_usuarios_form as $r): ?>
                                        <option value="<?= htmlspecialchars($r) ?>"><?= htmlspecialchars($r) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="nuevo_rol" class="form-label">Rol <span class="text-danger">*</span></label>
                                <select class="form-select form-select-sm" id="nuevo_rol" name="nuevo_rol" required>
                                    <option value="">Seleccione un rol...</option>
                                    <?php foreach ($roles_disponibles_form as $rol_opt): ?>
                                        <option value="<?= htmlspecialchars($rol_opt) ?>"><?= htmlspecialchars(ucfirst($rol_opt)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3 d-flex align-items-center mt-md-3 pt-md-2"> {/* Ajuste para alineación vertical en md y superior */}
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

    <?php if ($usuario_para_editar): // Solo renderizar el modal si hay datos para editar 
    ?>
        <div class="modal fade" id="modalEditarUsuario" tabindex="-1" aria-labelledby="modalEditarUsuarioLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <form method="post" action="gestionar_usuarios.php" id="formEditarUsuarioAncla"> {/* Cambiado id para evitar duplicados si no usas el #formEditarUsuario en URL */}
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
                                <input type="text" class="form-control form-control-sm" id="edit_cargo" name="edit_cargo" value="<?= htmlspecialchars($usuario_para_editar['cargo'] ?? '') ?>" required>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="edit_empresa" class="form-label">Empresa <span class="text-danger">*</span></label>
                                    <select class="form-select form-select-sm" id="edit_empresa" name="edit_empresa" required>
                                        <option value="">Seleccione...</option>
                                        <?php foreach ($empresas_usuarios_form as $e): ?>
                                            <option value="<?= htmlspecialchars($e) ?>" <?= ($usuario_para_editar['empresa'] ?? '') == $e ? 'selected' : '' ?>><?= htmlspecialchars($e) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="edit_regional" class="form-label">Regional <span class="text-danger">*</span></label>
                                    <select class="form-select form-select-sm" id="edit_regional" name="edit_regional" required>
                                        <option value="">Seleccione...</option>
                                        <?php foreach ($regionales_usuarios_form as $r): ?>
                                            <option value="<?= htmlspecialchars($r) ?>" <?= ($usuario_para_editar['regional'] ?? '') == $r ? 'selected' : '' ?>><?= htmlspecialchars($r) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="edit_rol" class="form-label">Rol <span class="text-danger">*</span></label>
                                    <select class="form-select form-select-sm" id="edit_rol" name="edit_rol" required
                                        <?= (strtolower($usuario_para_editar['usuario']) === 'admin' || $usuario_para_editar['id'] === 1) ? 'disabled' : '' ?>>
                                        <option value="">Seleccione un rol...</option>
                                        <?php foreach ($roles_disponibles_form as $rol_opt): ?>
                                            <option value="<?= htmlspecialchars($rol_opt) ?>" <?= $usuario_para_editar['rol'] == $rol_opt ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($rol_opt)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (strtolower($usuario_para_editar['usuario']) === 'admin' || $usuario_para_editar['id'] === 1): ?>
                                        <small class="form-text text-muted">El rol del administrador principal no se puede cambiar.</small>
                                        <input type="hidden" name="edit_rol" value="<?= htmlspecialchars($usuario_para_editar['rol']) ?>"> {/* Enviar el rol actual si está deshabilitado */}
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-6 mb-3 d-flex align-items-center mt-md-3 pt-md-2">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="edit_activo" name="edit_activo" value="1"
                                            <?= $usuario_para_editar['activo'] ? 'checked' : '' ?>
                                            <?= (strtolower($usuario_para_editar['usuario']) === 'admin' || $usuario_para_editar['id'] === 1) ? 'disabled' : '' ?>>
                                        <label class="form-check-label" for="edit_activo">Usuario Activo</label>
                                    </div>
                                    <?php if (strtolower($usuario_para_editar['usuario']) === 'admin' || $usuario_para_editar['id'] === 1): ?>
                                        <input type="hidden" name="edit_activo" value="1"> {/* Forzar activo si es admin principal */}
                                    <?php endif; ?>
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
    <?php endif; ?>

    <?php if (isset($conexion)) {
        $conexion->close();
    } ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
    const modalEditarElement = document.getElementById('modalEditarUsuario');
    let modalEditarInstancia = null;

    function abrirModalSiEsNecesario() {
        const urlParams = new URLSearchParams(window.location.search);
        const accion = urlParams.get('accion');
        const idUsuario = urlParams.get('id');

        if (modalEditarElement) {
            if (!modalEditarInstancia) { // Crear instancia solo si no existe
                modalEditarInstancia = new bootstrap.Modal(modalEditarElement);
            }

            // Para el modal de edición: si la URL tiene accion=editar y un id.
            // Y el PHP ha renderizado el modal (lo cual se infiere si modalEditarElement existe)
            if (accion === 'editar' && idUsuario) {
                // Prevenir que se abra si ya está abierto por alguna razón (aunque no debería pasar)
                if (!modalEditarElement.classList.contains('show')) {
                     modalEditarInstancia.show();
                }
            } else {
                // Si no hay parámetros de edición, asegurarse de que el modal esté oculto
                 if (modalEditarElement.classList.contains('show')) {
                    modalEditarInstancia.hide();
                 }
            }
        }
    }

    // Abrir el modal al cargar la página si los parámetros son correctos
    abrirModalSiEsNecesario();

    if (modalEditarElement) {
        // Listener para cuando el modal de edición se oculte
        modalEditarElement.addEventListener('hidden.bs.modal', function (event) {
            const nuevaUrl = window.location.pathname; // URL base sin parámetros
            // Solo modificar el historial si la URL actual todavía tiene los parámetros de edición
            if (window.location.search.includes('accion=editar') || window.location.search.includes('id=')) {
                 // Reemplaza la entrada actual en el historial en lugar de añadir una nueva
                 window.history.replaceState({ path: nuevaUrl }, '', nuevaUrl);
            }
        });
    }
    
    // ---- El resto de tu JavaScript para validación de contraseñas ----
    // (sin cambios en esta parte)

    // Validación de contraseñas en el modal de creación
    const formCrear = document.getElementById('formCrearUsuario');
    if (formCrear) {
        const nuevaClave = formCrear.querySelector('#nueva_clave');
        const confirmarNuevaClave = formCrear.querySelector('#confirmar_nueva_clave');
        
        function validarClaveCrear() {
            if (nuevaClave && confirmarNuevaClave) {
                if (nuevaClave.value !== confirmarNuevaClave.value) {
                    confirmarNuevaClave.setCustomValidity("Las contraseñas no coinciden.");
                } else {
                    confirmarNuevaClave.setCustomValidity('');
                }
            }
        }
        if(nuevaClave) nuevaClave.addEventListener('input', validarClaveCrear);
        if(confirmarNuevaClave) confirmarNuevaClave.addEventListener('input', validarClaveCrear);
        
        formCrear.addEventListener('submit', function(event) {
            validarClaveCrear();
            if (!this.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
        });
    }

    // Validación de contraseñas en el modal de edición
    const formEditar = document.getElementById('formEditarUsuarioAncla');
    if (formEditar) {
        const editClave = formEditar.querySelector('#edit_clave');
        const editConfirmarClave = formEditar.querySelector('#edit_confirmar_clave');

        function validarClaveEditar() {
            if (editClave && editConfirmarClave) {
                if (editClave.value !== '' && editClave.value !== editConfirmarClave.value) {
                    editConfirmarClave.setCustomValidity("Las nuevas contraseñas no coinciden.");
                } else if (editClave.value === '' && editConfirmarClave.value !== '') {
                    editConfirmarClave.setCustomValidity("Escriba la nueva contraseña primero si desea cambiarla.");
                } else {
                    editConfirmarClave.setCustomValidity('');
                }
            }
        }
        if(editClave) editClave.addEventListener('input', validarClaveEditar);
        if(editConfirmarClave) editConfirmarClave.addEventListener('input', validarClaveEditar);

        formEditar.addEventListener('submit', function(event) {
            if (editClave && editClave.value !== '' && editClave.value.length < 6) {
                alert('La nueva contraseña debe tener al menos 6 caracteres.');
                editClave.focus();
                event.preventDefault();
                event.stopPropagation();
                return;
            }
            validarClaveEditar(); 
            if (!this.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
        });
    }

    // Escuchar el evento popstate para manejar el botón "Atrás/Adelante" del navegador
    window.addEventListener('popstate', function(event) {
        // Cuando el usuario usa los botones de atrás/adelante,
        // volvemos a verificar la URL y abrimos/cerramos el modal según sea necesario.
        abrirModalSiEsNecesario();
    });
});
    </script>
</body>

</html>