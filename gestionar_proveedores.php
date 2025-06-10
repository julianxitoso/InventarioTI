<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'backend/auth_check.php';
restringir_acceso_pagina(['admin']); // Solo administradores pueden gestionar proveedores

require_once 'backend/db.php';

// Inicializar mensaje de error de conexión
$conexion_error_msg = null;
if (isset($conn) && !isset($conexion)) {
    $conexion = $conn;
}
if (!isset($conexion) || !$conexion || (method_exists($conexion, 'connect_error') && $conexion->connect_error)) {
    $db_conn_error_detail = method_exists($conexion, 'connect_error') ? $conexion->connect_error : 'Desconocido';
    error_log("Error de conexión BD en gestionar_proveedores.php: " . $db_conn_error_detail);
    $conexion_error_msg = "<div class='alert alert-danger'>Error crítico de conexión a la base de datos. No se pueden cargar ni guardar datos. Contacte al administrador.</div>";
} else {
    $conexion->set_charset("utf8mb4");
}

$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Administrador';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'admin';

// Inicializar $mensaje_accion correctamente
$mensaje_accion = $_SESSION['mensaje_accion_proveedores'] ?? null;
if ($conexion_error_msg && empty($mensaje_accion)) {
    $mensaje_accion = $conexion_error_msg;
}
unset($_SESSION['mensaje_accion_proveedores']);

$proveedor_para_editar = null;
$abrir_modal_creacion_proveedor_js = false;
$abrir_modal_editar_proveedor_js = false;

// --- PROCESAR ACCIONES POST (Crear, Actualizar, Eliminar) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$conexion_error_msg) {
    // ------ CREAR PROVEEDOR ------
    if (isset($_POST['crear_proveedor_submit'])) {
        $nombre_proveedor = trim($_POST['nombre_proveedor_crear'] ?? '');
        $contacto_nombre = trim($_POST['contacto_nombre_crear'] ?? '');
        $contacto_telefono = trim($_POST['contacto_telefono_crear'] ?? '');
        $contacto_email_raw = trim($_POST['contacto_email_crear'] ?? '');
        $contacto_email = null;

        if (!empty($contacto_email_raw)) {
            $contacto_email = filter_var($contacto_email_raw, FILTER_VALIDATE_EMAIL);
            if ($contacto_email === false) {
                 $_SESSION['mensaje_accion_proveedores'] = "<div class='alert alert-danger'>Creación: El formato del email de contacto no es válido.</div>";
                 header("Location: gestionar_proveedores.php?error_creacion_proveedor=1"); exit;
            }
        }

        if (empty($nombre_proveedor)) {
            $_SESSION['mensaje_accion_proveedores'] = "<div class='alert alert-danger'>Creación: El nombre del proveedor es obligatorio.</div>";
        } else {
            $stmt_check = $conexion->prepare("SELECT id FROM proveedores_mantenimiento WHERE nombre_proveedor = ?");
            $stmt_check->bind_param("s", $nombre_proveedor);
            $stmt_check->execute();
            $stmt_check->store_result();
            if ($stmt_check->num_rows > 0) {
                $_SESSION['mensaje_accion_proveedores'] = "<div class='alert alert-danger'>Creación: El nombre del proveedor '" . htmlspecialchars($nombre_proveedor) . "' ya existe.</div>";
            } else {
                $sql_insert = "INSERT INTO proveedores_mantenimiento (nombre_proveedor, contacto_nombre, contacto_telefono, contacto_email) VALUES (?, ?, ?, ?)";
                $stmt_insert = $conexion->prepare($sql_insert);
                $stmt_insert->bind_param("ssss", $nombre_proveedor, $contacto_nombre, $contacto_telefono, $contacto_email);
                if ($stmt_insert->execute()) {
                    $_SESSION['mensaje_accion_proveedores'] = "<div class='alert alert-success'>Proveedor '" . htmlspecialchars($nombre_proveedor) . "' creado exitosamente.</div>";
                    header("Location: gestionar_proveedores.php"); exit;
                } else {
                    $_SESSION['mensaje_accion_proveedores'] = "<div class='alert alert-danger'>Creación: Error al crear el proveedor: " . $stmt_insert->error . "</div>";
                    error_log("Error DB proveedores (insert): " . $stmt_insert->error);
                }
                $stmt_insert->close();
            }
            $stmt_check->close();
        }
        header("Location: gestionar_proveedores.php?error_creacion_proveedor=1"); 
        exit;
    }
    // ------ ACTUALIZAR PROVEEDOR ------
    elseif (isset($_POST['editar_proveedor_submit'])) {
        $id_proveedor_editar = filter_input(INPUT_POST, 'id_proveedor_editar', FILTER_VALIDATE_INT);
        $nombre_proveedor_editar = trim($_POST['nombre_proveedor_editar_modal'] ?? '');
        $contacto_nombre_editar = trim($_POST['contacto_nombre_editar_modal'] ?? '');
        $contacto_telefono_editar = trim($_POST['contacto_telefono_editar_modal'] ?? '');
        $contacto_email_editar_raw = trim($_POST['contacto_email_editar_modal'] ?? '');
        $contacto_email_editar = null;

        if (!empty($contacto_email_editar_raw)) {
            $contacto_email_editar = filter_var($contacto_email_editar_raw, FILTER_VALIDATE_EMAIL);
            if ($contacto_email_editar === false) {
                 $_SESSION['mensaje_accion_proveedores'] = "<div class='alert alert-danger'>Edición: El formato del email de contacto no es válido.</div>";
                 header("Location: gestionar_proveedores.php?accion=editar&id=" . $id_proveedor_editar . "&error_edicion_proveedor=1"); exit;
            }
        }
        
        if (empty($nombre_proveedor_editar)) {
            $_SESSION['mensaje_accion_proveedores'] = "<div class='alert alert-danger'>Edición: El nombre del proveedor es obligatorio.</div>";
        } elseif (!$id_proveedor_editar) {
            $_SESSION['mensaje_accion_proveedores'] = "<div class='alert alert-danger'>Edición: ID de proveedor inválido.</div>";
        } else {
            $stmt_check_nombre = $conexion->prepare("SELECT id FROM proveedores_mantenimiento WHERE nombre_proveedor = ? AND id != ?");
            $stmt_check_nombre->bind_param("si", $nombre_proveedor_editar, $id_proveedor_editar);
            $stmt_check_nombre->execute();
            $stmt_check_nombre->store_result();
            if ($stmt_check_nombre->num_rows > 0) {
                $_SESSION['mensaje_accion_proveedores'] = "<div class='alert alert-danger'>Edición: El nombre del proveedor '" . htmlspecialchars($nombre_proveedor_editar) . "' ya existe.</div>";
            } else {
                $sql_update = "UPDATE proveedores_mantenimiento SET nombre_proveedor = ?, contacto_nombre = ?, contacto_telefono = ?, contacto_email = ? WHERE id = ?";
                $stmt_update = $conexion->prepare($sql_update);
                $stmt_update->bind_param("ssssi", $nombre_proveedor_editar, $contacto_nombre_editar, $contacto_telefono_editar, $contacto_email_editar, $id_proveedor_editar);
                if ($stmt_update->execute()) {
                     if ($stmt_update->affected_rows > 0) {
                        $_SESSION['mensaje_accion_proveedores'] = "<div class='alert alert-success'>Proveedor '" . htmlspecialchars($nombre_proveedor_editar) . "' actualizado exitosamente.</div>";
                    } else {
                        $_SESSION['mensaje_accion_proveedores'] = "<div class='alert alert-info'>No se detectaron cambios en el proveedor.</div>";
                    }
                    header("Location: gestionar_proveedores.php"); exit;
                } else {
                    $_SESSION['mensaje_accion_proveedores'] = "<div class='alert alert-danger'>Edición: Error al actualizar el proveedor: " . $stmt_update->error . "</div>";
                    error_log("Error DB proveedores (update): " . $stmt_update->error);
                }
                $stmt_update->close();
            }
            $stmt_check_nombre->close();
        }
        header("Location: gestionar_proveedores.php?accion=editar&id=" . $id_proveedor_editar . "&error_edicion_proveedor=1");
        exit;
    }
    // ------ ELIMINAR PROVEEDOR ------
    elseif (isset($_POST['eliminar_proveedor_submit'])) {
        $id_proveedor_eliminar = filter_input(INPUT_POST, 'id_proveedor_eliminar', FILTER_VALIDATE_INT);
        if ($id_proveedor_eliminar) {
            // Aquí podrías añadir una verificación si el proveedor está en uso en 'historial_activos' si tienes una columna proveedor_id allí
            // Ejemplo: $stmt_check_uso = $conexion->prepare("SELECT COUNT(*) as total FROM historial_activos WHERE proveedor_id = ?"); ...
            // if ($res_check_uso['total'] > 0) { mensaje de error } else { proceder a eliminar }

            $sql_delete = "DELETE FROM proveedores_mantenimiento WHERE id = ?";
            $stmt_delete = $conexion->prepare($sql_delete);
            if ($stmt_delete) {
                $stmt_delete->bind_param("i", $id_proveedor_eliminar);
                if ($stmt_delete->execute()) {
                    if ($stmt_delete->affected_rows > 0) {
                        $_SESSION['mensaje_accion_proveedores'] = "<div class='alert alert-info'>Proveedor eliminado exitosamente.</div>";
                    } else {
                        $_SESSION['mensaje_accion_proveedores'] = "<div class='alert alert-warning'>No se encontró el proveedor para eliminar o ya fue eliminado.</div>";
                    }
                } else {
                    // Error 1451: Constraint violation (foreign key), indica que está en uso.
                    if ($conexion->errno == 1451) {
                         $_SESSION['mensaje_accion_proveedores'] = "<div class='alert alert-danger'>Error al eliminar: Este proveedor está actualmente en uso (ej. en historial de activos) y no puede ser eliminado.</div>";
                    } else {
                        $_SESSION['mensaje_accion_proveedores'] = "<div class='alert alert-danger'>Error al eliminar el proveedor: " . $stmt_delete->error . ".</div>";
                    }
                    error_log("Error DB al eliminar proveedor ID {$id_proveedor_eliminar}: " . $stmt_delete->error);
                }
                $stmt_delete->close();
            } else {
                $_SESSION['mensaje_accion_proveedores'] = "<div class='alert alert-danger'>Error al preparar la eliminación del proveedor.</div>";
                error_log("Error DB al preparar eliminar proveedor ID {$id_proveedor_eliminar}: " . $conexion->error);
            }
            header("Location: gestionar_proveedores.php");
            exit;
        }
    }
}

// --- Lógica para cargar datos para editar (GET) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['accion']) && $_GET['accion'] === 'editar' && isset($_GET['id']) && !$conexion_error_msg) {
    $id_proveedor_get = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if ($id_proveedor_get) {
        $stmt_edit_fetch = $conexion->prepare("SELECT * FROM proveedores_mantenimiento WHERE id = ?");
        if ($stmt_edit_fetch) {
            $stmt_edit_fetch->bind_param("i", $id_proveedor_get);
            $stmt_edit_fetch->execute();
            $result_edit_fetch = $stmt_edit_fetch->get_result();
            if ($result_edit_fetch->num_rows === 1) {
                $proveedor_para_editar = $result_edit_fetch->fetch_assoc();
                $abrir_modal_editar_proveedor_js = true;
            } else {
                $_SESSION['mensaje_accion_proveedores'] = "<div class='alert alert-warning'>Proveedor no encontrado para editar (ID: {$id_proveedor_get}).</div>";
            }
            $stmt_edit_fetch->close();
        } else { /* ... error ... */ }
    }
    if(isset($_GET['error_edicion_proveedor']) && $_GET['error_edicion_proveedor'] == '1' && $id_proveedor_get && !$proveedor_para_editar){
       $stmt_reload = $conexion->prepare("SELECT * FROM proveedores_mantenimiento WHERE id = ?");
       if($stmt_reload){ /* ... recargar $proveedor_para_editar ... */ }
       if($proveedor_para_editar) $abrir_modal_editar_proveedor_js = true;
    }
}

// --- Obtener lista de proveedores para mostrar ---
$proveedores_listados = [];
if (!$conexion_error_msg) {
    $sql_listar = "SELECT id, nombre_proveedor, contacto_nombre, contacto_telefono, contacto_email, fecha_creacion FROM proveedores_mantenimiento ORDER BY nombre_proveedor ASC";
    $result_listar = $conexion->query($sql_listar);
    if ($result_listar) {
        while ($row = $result_listar->fetch_assoc()) {
            $proveedores_listados[] = $row;
        }
    } else { /* ... error ... */ }
}

// Determinar si el modal de creación debe abrirse automáticamente
if (isset($_GET['error_creacion_proveedor']) && $_GET['error_creacion_proveedor'] == '1' && !empty($mensaje_accion)) {
    if (is_string($mensaje_accion) && stripos($mensaje_accion, "Creación:") !== false) {
        $abrir_modal_creacion_proveedor_js = true;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gestionar Proveedores</title>
    <link rel="icon" type="image/x-icon" href="imagenes/icono.ico">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding-top: 110px; background-color: #eef2f5; font-size: 0.92rem; }
        .top-bar-custom { position: fixed; top: 0; left: 0; right: 0; z-index: 1030; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; padding: 0.5rem 1.5rem; background-color: #ffffff; border-bottom: 1px solid #dee2e6; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .logo-container-top img { height: 75px; width: auto; }
        .top-bar-user-info .navbar-text { font-size: 0.8rem; }
        .top-bar-user-info .btn { font-size: 0.8rem; }
        .page-header-custom-area { /* Estilos para el contenedor del título y botones si son necesarios */ }
        h1.page-title { color: #0d6efd; font-weight: 600; font-size: 1.75rem; }
        .card { border: none; box-shadow: 0 0 10px rgba(0,0,0,0.06); }
        .card-header { background-color: #f8f9fa; border-bottom: 1px solid #dee2e6; font-weight: 500; color: #495057; font-size: 1.05rem; }
        .table thead th { background-color: #4A5568; color: white; font-weight: 500; vertical-align: middle; font-size: 0.85rem; padding: 0.6rem 0.75rem; white-space: nowrap;}
        .table tbody td { vertical-align: middle; font-size: 0.85rem; padding: 0.6rem 0.75rem; }
        .form-label { font-weight: 500; color: #495057; font-size: 0.85rem; }
        .container.mt-4 {max-width: 1140px;} /* Ajustado para tabla de proveedores */
        .action-icon { font-size: 1rem; text-decoration: none; margin-right: 0.3rem; }

        @media (max-width: 575.98px) { /* xs screens */
            body { padding-top: 150px; } 
            .top-bar-custom { flex-direction: column; padding: 0.75rem 1rem; }
            .logo-container-top { margin-bottom: 0.5rem; text-align: center; width: 100%; }
            .top-bar-user-info { display: flex; flex-direction: column; align-items: center; width: 100%; text-align: center; }
            .top-bar-user-info .navbar-text { margin-right: 0; margin-bottom: 0.5rem; }
            h1.page-title { font-size: 1.4rem !important; margin-top: 0.5rem; margin-bottom: 0.75rem;}
            .page-header-custom-area .btn { margin-bottom: 0.5rem; }
            .page-header-custom-area > div:last-child .btn { margin-bottom: 0; }
        }
    </style>
</head>
<body>
<div class="top-bar-custom">
    <div class="logo-container-top"><a href="menu.php"><img src="imagenes/logo.png" alt="Logo"></a></div>
    <div class="top-bar-user-info">
        <span class="navbar-text me-sm-3"><i class="bi bi-person-circle"></i> <?= htmlspecialchars($nombre_usuario_actual_sesion) ?> (<?= htmlspecialchars(ucfirst($rol_usuario_actual_sesion)) ?>)</span>
        <a href="logout.php" class="btn btn-outline-danger btn-sm"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</a>
    </div>
</div>

<div class="container mt-4"> 
    <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-sm-between mb-3 page-header-custom-area">
        <div class="mb-2 mb-sm-0 text-center text-sm-start order-sm-1" style="flex-shrink: 0;">
            <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#modalCrearProveedor">
                <i class="bi bi-plus-circle"></i> Crear Nuevo Proveedor
            </button>
        </div>
        <div class="flex-fill text-center order-first order-sm-2 px-sm-3">
            <h1 class="page-title my-2 my-sm-0">
                <i class="bi bi-truck"></i> Gestión de Proveedores
            </h1>
        </div>
        <div class="mt-2 mt-sm-0 text-center text-sm-end order-sm-3" style="flex-shrink: 0;">
             <a href="centro_gestion.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left-circle"></i> Volver al Centro de Gestión
            </a>
        </div>
    </div>

    <?php if ($mensaje_accion && is_string($mensaje_accion)) echo "<div class='mb-3 text-center'>{$mensaje_accion}</div>"; ?>

    <div class="card mt-2">
        <div class="card-header"><i class="bi bi-list-ul"></i> Lista de Proveedores Existentes</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover table-sm mb-0">
                    <thead>
                        <tr>
                            <th>Nombre Proveedor</th>
                            <th>Contacto</th>
                            <th>Teléfono</th>
                            <th>Email</th>
                            <th>Registrado</th>
                            <th style="min-width: 100px;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($proveedores_listados)): ?>
                            <?php foreach ($proveedores_listados as $prov): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($prov['nombre_proveedor']) ?></strong></td>
                                    <td><?= htmlspecialchars($prov['contacto_nombre'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($prov['contacto_telefono'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($prov['contacto_email'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars(!empty($prov['fecha_creacion']) ? date("d/m/Y", strtotime($prov['fecha_creacion'])) : 'N/A') ?></td>
                                    <td>
                                        <a href="gestionar_proveedores.php?accion=editar&id=<?= $prov['id'] ?>" class="btn btn-sm btn-outline-warning action-icon" title="Editar Proveedor"><i class="bi bi-pencil-fill"></i></a>
                                        <form method="POST" action="gestionar_proveedores.php" style="display: inline;" onsubmit="return confirm('¿Está seguro que desea eliminar este proveedor: \'<?= htmlspecialchars(addslashes($prov['nombre_proveedor'])) ?>\'? Esta acción no se puede deshacer.');">
                                            <input type="hidden" name="id_proveedor_eliminar" value="<?= $prov['id'] ?>">
                                            <button type="submit" name="eliminar_proveedor_submit" class="btn btn-sm btn-outline-danger action-icon" title="Eliminar Proveedor"><i class="bi bi-trash3-fill"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-center p-4">No hay proveedores registrados o hubo un error al cargarlos.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalCrearProveedor" tabindex="-1" aria-labelledby="modalCrearProveedorLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="gestionar_proveedores.php" id="formCrearProveedorModal">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalCrearProveedorLabel"><i class="bi bi-plus-circle-fill"></i> Agregar Nuevo Proveedor</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="nombre_proveedor_crear" class="form-label">Nombre del Proveedor <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm" id="nombre_proveedor_crear" name="nombre_proveedor_crear" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="contacto_nombre_crear" class="form-label">Nombre de Contacto</label>
                            <input type="text" class="form-control form-control-sm" id="contacto_nombre_crear" name="contacto_nombre_crear">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="contacto_telefono_crear" class="form-label">Teléfono de Contacto</label>
                            <input type="text" class="form-control form-control-sm" id="contacto_telefono_crear" name="contacto_telefono_crear">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="contacto_email_crear" class="form-label">Email de Contacto</label>
                        <input type="email" class="form-control form-control-sm" id="contacto_email_crear" name="contacto_email_crear">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" name="crear_proveedor_submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-plus-lg"></i> Agregar Proveedor
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($proveedor_para_editar): ?>
<div class="modal fade" id="modalEditarProveedor" tabindex="-1" aria-labelledby="modalEditarProveedorLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="gestionar_proveedores.php" id="formEditarProveedorModal">
                <input type="hidden" name="id_proveedor_editar" value="<?= htmlspecialchars($proveedor_para_editar['id']) ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalEditarProveedorLabel"><i class="bi bi-pencil-fill"></i> Editar Proveedor: <?= htmlspecialchars($proveedor_para_editar['nombre_proveedor']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="nombre_proveedor_editar_modal" class="form-label">Nombre del Proveedor <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm" id="nombre_proveedor_editar_modal" name="nombre_proveedor_editar_modal" 
                               value="<?= htmlspecialchars($proveedor_para_editar['nombre_proveedor']) ?>" required>
                    </div>
                     <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="contacto_nombre_editar_modal" class="form-label">Nombre de Contacto</label>
                            <input type="text" class="form-control form-control-sm" id="contacto_nombre_editar_modal" name="contacto_nombre_editar_modal"
                                   value="<?= htmlspecialchars($proveedor_para_editar['contacto_nombre'] ?? '') ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="contacto_telefono_editar_modal" class="form-label">Teléfono de Contacto</label>
                            <input type="text" class="form-control form-control-sm" id="contacto_telefono_editar_modal" name="contacto_telefono_editar_modal"
                                   value="<?= htmlspecialchars($proveedor_para_editar['contacto_telefono'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="contacto_email_editar_modal" class="form-label">Email de Contacto</label>
                        <input type="email" class="form-control form-control-sm" id="contacto_email_editar_modal" name="contacto_email_editar_modal"
                               value="<?= htmlspecialchars($proveedor_para_editar['contacto_email'] ?? '') ?>">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" name="editar_proveedor_submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-save-fill"></i> Actualizar Proveedor
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (isset($conexion) && $conexion && !$conexion_error_msg) { $conexion->close(); } ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const currentUrl = new URL(window.location);
    if (currentUrl.searchParams.has('error_creacion_proveedor')) {
        currentUrl.searchParams.delete('error_creacion_proveedor');
        window.history.replaceState({}, document.title, currentUrl.toString());
    }
    if (currentUrl.searchParams.has('error_edicion_proveedor')) {
        currentUrl.searchParams.delete('error_edicion_proveedor');
        window.history.replaceState({}, document.title, currentUrl.toString());
    }

    <?php if ($abrir_modal_creacion_proveedor_js): ?>
    const modalCrearProveedorEl = document.getElementById('modalCrearProveedor');
    if (modalCrearProveedorEl) {
        const modalCrear = new bootstrap.Modal(modalCrearProveedorEl);
        modalCrear.show();
    }
    <?php endif; ?>

    <?php if ($abrir_modal_editar_proveedor_js && $proveedor_para_editar): ?>
    const modalEditarProveedorEl = document.getElementById('modalEditarProveedor');
    if (modalEditarProveedorEl) {
        const modalEditar = new bootstrap.Modal(modalEditarProveedorEl);
        modalEditar.show(); 
    }
    <?php endif; ?>
});
</script>
</body>
</html>