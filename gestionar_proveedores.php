<?php
session_start();
require_once 'backend/auth_check.php';
restringir_acceso_pagina(['admin']); // Solo administradores pueden gestionar proveedores

require_once 'backend/db.php';

if (isset($conn) && !isset($conexion)) {
    $conexion = $conn;
}
if (!isset($conexion) || !$conexion || (method_exists($conexion, 'connect_error') && $conexion->connect_error)) {
    error_log("Error de conexión BD en gestionar_proveedores.php: " . ($conexion->connect_error ?? 'Desconocido'));
    die("Error de conexión a la base de datos.");
}
$conexion->set_charset("utf8mb4");

$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Administrador';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'admin';

$mensaje_accion = $_SESSION['mensaje_accion_proveedores'] ?? null;
if (isset($_SESSION['mensaje_accion_proveedores'])) {
    unset($_SESSION['mensaje_accion_proveedores']);
}

$proveedor_para_editar = null;
$edit_mode = false;

// --- PROCESAR ACCIONES POST (Crear, Actualizar, Eliminar) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ------ CREAR O ACTUALIZAR PROVEEDOR ------
    if (isset($_POST['guardar_proveedor_submit'])) {
        $id_proveedor = filter_input(INPUT_POST, 'id_proveedor_editar', FILTER_VALIDATE_INT);
        $nombre_proveedor = trim($_POST['nombre_proveedor'] ?? '');
        $contacto_nombre = trim($_POST['contacto_nombre'] ?? '');
        $contacto_telefono = trim($_POST['contacto_telefono'] ?? '');
        $contacto_email = filter_input(INPUT_POST, 'contacto_email', FILTER_VALIDATE_EMAIL);
        if (empty($contacto_email)) $contacto_email = null; // Permitir email vacío si no es válido pero no se ingresó

        if (empty($nombre_proveedor)) {
            $mensaje_accion = "<div class='alert alert-danger'>El nombre del proveedor es obligatorio.</div>";
        } elseif ($contacto_email === false && !empty($_POST['contacto_email'])) { // Si se ingresó algo pero no es un email válido
             $mensaje_accion = "<div class='alert alert-danger'>El formato del email de contacto no es válido.</div>";
        } else {
            // Verificar si el nombre del proveedor ya existe (al crear o al editar si se cambia el nombre)
            $sql_check_nombre = "SELECT id FROM proveedores_mantenimiento WHERE nombre_proveedor = ?";
            $params_check = [$nombre_proveedor];
            if ($id_proveedor) { // Si estamos editando, excluimos el ID actual de la verificación
                $sql_check_nombre .= " AND id != ?";
                $params_check[] = $id_proveedor;
            }
            $stmt_check = $conexion->prepare($sql_check_nombre);
            $stmt_check->bind_param(str_repeat('s', count($params_check)- ($id_proveedor ? 1:0)) . ($id_proveedor ? 'i':''), ...$params_check); // s o si
            if ($id_proveedor) { $stmt_check->bind_param("si", $nombre_proveedor, $id_proveedor); } 
            else { $stmt_check->bind_param("s", $nombre_proveedor); }
            $stmt_check->execute();
            $stmt_check->store_result();

            if ($stmt_check->num_rows > 0) {
                $mensaje_accion = "<div class='alert alert-danger'>El nombre del proveedor '" . htmlspecialchars($nombre_proveedor) . "' ya existe.</div>";
            } else {
                if ($id_proveedor) { // Actualizar
                    $sql = "UPDATE proveedores_mantenimiento SET nombre_proveedor = ?, contacto_nombre = ?, contacto_telefono = ?, contacto_email = ? WHERE id = ?";
                    $stmt = $conexion->prepare($sql);
                    $stmt->bind_param("ssssi", $nombre_proveedor, $contacto_nombre, $contacto_telefono, $contacto_email, $id_proveedor);
                    $accion_texto = "actualizado";
                } else { // Crear
                    $sql = "INSERT INTO proveedores_mantenimiento (nombre_proveedor, contacto_nombre, contacto_telefono, contacto_email) VALUES (?, ?, ?, ?)";
                    $stmt = $conexion->prepare($sql);
                    $stmt->bind_param("ssss", $nombre_proveedor, $contacto_nombre, $contacto_telefono, $contacto_email);
                    $accion_texto = "creado";
                }

                if ($stmt && $stmt->execute()) {
                    $_SESSION['mensaje_accion_proveedores'] = "<div class='alert alert-success'>Proveedor '" . htmlspecialchars($nombre_proveedor) . "' " . $accion_texto . " exitosamente.</div>";
                } else {
                    $_SESSION['mensaje_accion_proveedores'] = "<div class='alert alert-danger'>Error al " . ($id_proveedor ? "actualizar" : "crear") . " el proveedor: " . ($stmt ? $stmt->error : $conexion->error) . "</div>";
                    error_log("Error DB proveedores: " . ($stmt ? $stmt->error : $conexion->error));
                }
                if ($stmt) $stmt->close();
                header("Location: gestionar_proveedores.php");
                exit;
            }
            $stmt_check->close();
        }
         // Si hubo error y estábamos editando, recargar datos del proveedor para el form
        if ($id_proveedor && !empty($mensaje_accion)) {
            $stmt_reload = $conexion->prepare("SELECT * FROM proveedores_mantenimiento WHERE id = ?");
            if ($stmt_reload) {
                $stmt_reload->bind_param("i", $id_proveedor);
                $stmt_reload->execute();
                $result_reload = $stmt_reload->get_result();
                if ($result_reload->num_rows === 1) {
                    $proveedor_para_editar = $result_reload->fetch_assoc();
                    $edit_mode = true;
                }
                $stmt_reload->close();
            }
        }
    }
    // ------ ELIMINAR PROVEEDOR ------
    elseif (isset($_POST['eliminar_proveedor_submit'])) {
        $id_proveedor_eliminar = filter_input(INPUT_POST, 'id_proveedor_eliminar', FILTER_VALIDATE_INT);
        if ($id_proveedor_eliminar) {
            // Opcional: Verificar si el proveedor está en uso en historial_activos antes de eliminar
            // Por ahora, eliminación directa.
            $sql_delete = "DELETE FROM proveedores_mantenimiento WHERE id = ?";
            $stmt_delete = $conexion->prepare($sql_delete);
            if ($stmt_delete) {
                $stmt_delete->bind_param("i", $id_proveedor_eliminar);
                if ($stmt_delete->execute()) {
                    $_SESSION['mensaje_accion_proveedores'] = "<div class='alert alert-info'>Proveedor eliminado exitosamente.</div>";
                } else {
                    $_SESSION['mensaje_accion_proveedores'] = "<div class='alert alert-danger'>Error al eliminar el proveedor: " . $stmt_delete->error . ". Es posible que esté en uso.</div>";
                    error_log("Error DB al eliminar proveedor: " . $stmt_delete->error);
                }
                $stmt_delete->close();
            } else {
                 $_SESSION['mensaje_accion_proveedores'] = "<div class='alert alert-danger'>Error al preparar la eliminación del proveedor.</div>";
            }
            header("Location: gestionar_proveedores.php");
            exit;
        }
    }
}

// --- Lógica para cargar datos para editar (GET) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['accion']) && $_GET['accion'] === 'editar' && isset($_GET['id'])) {
    $id_proveedor_get = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if ($id_proveedor_get) {
        $stmt_edit = $conexion->prepare("SELECT * FROM proveedores_mantenimiento WHERE id = ?");
        if ($stmt_edit) {
            $stmt_edit->bind_param("i", $id_proveedor_get);
            $stmt_edit->execute();
            $result_edit = $stmt_edit->get_result();
            if ($result_edit->num_rows === 1) {
                $proveedor_para_editar = $result_edit->fetch_assoc();
                $edit_mode = true;
            } else {
                $_SESSION['mensaje_accion_proveedores'] = "<div class='alert alert-warning'>Proveedor no encontrado para editar.</div>";
                // No redirigir para que el mensaje se muestre en la misma página
            }
            $stmt_edit->close();
        }
    }
}

// --- Obtener lista de proveedores para mostrar ---
$proveedores_listados = [];
$sql_listar = "SELECT id, nombre_proveedor, contacto_nombre, contacto_telefono, contacto_email, fecha_creacion FROM proveedores_mantenimiento ORDER BY nombre_proveedor ASC";
$result_listar = $conexion->query($sql_listar);
if ($result_listar) {
    while ($row = $result_listar->fetch_assoc()) {
        $proveedores_listados[] = $row;
    }
} else {
    if (!$mensaje_accion) {
        $mensaje_accion = "<div class='alert alert-danger'>Error al obtener la lista de proveedores: " . $conexion->error . "</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestionar Proveedores de Mantenimiento</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding-top: 80px; background-color: #f8f9fa; }
        .top-bar-custom { position: fixed; top: 0; left: 0; right: 0; z-index: 1030; display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 1.5rem; background-color: #ffffff; border-bottom: 1px solid #dee2e6; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .logo-container-top img { width: auto; height: 75px; object-fit: contain; margin-right: 15px; }
        .user-info-top { font-size: 0.9rem; }
        .page-title { color: #191970; font-weight: 600; }
        .card-form { margin-bottom: 2rem; }
        .table th { font-size: 0.9rem; }
        .table td { font-size: 0.88rem; }
        .action-icon { font-size: 1.1rem; text-decoration: none; margin-right: 0.5rem; }
        .btn-principal { background-color: #191970; border-color: #191970; color: #ffffff; }
        .btn-principal:hover { background-color: #111150; border-color: #111150; }
    </style>
</head>
<body>
<div class="top-bar-custom">
    <div class="logo-container-top"><a href="menu.php" title="Ir a Inicio"><img src="imagenes/logo.png" alt="Logo ARPESOD ASOCIADOS SAS"></a></div>
    <div class="d-flex align-items-center">
        <a href="gestionar_usuarios.php" class="btn btn-sm btn-outline-secondary me-2"><i class="bi bi-arrow-left-circle"></i> Volver a Centro de Gestión</a>
        <span class="text-dark me-3 user-info-top"><i class="bi bi-person-circle"></i> <?= htmlspecialchars($nombre_usuario_actual_sesion) ?> (<?= htmlspecialchars(ucfirst($rol_usuario_actual_sesion)) ?>)</span>
        <form action="logout.php" method="post" class="d-flex"><button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</button></form>
    </div>
</div>

<div class="container mt-4">
    <h3 class="page-title text-center mb-4"><i class="bi bi-truck"></i> Gestión de Proveedores de Mantenimiento</h3>

    <?php if ($mensaje_accion) echo $mensaje_accion; ?>

    <div class="card card-form shadow-sm">
        <div class="card-header bg-light">
            <h5 class="mb-0"><?= $edit_mode ? '<i class="bi bi-pencil-square"></i> Editar Proveedor' : '<i class="bi bi-plus-circle-fill"></i> Agregar Nuevo Proveedor' ?></h5>
        </div>
        <div class="card-body">
            <form method="POST" action="gestionar_proveedores.php">
                <?php if ($edit_mode && $proveedor_para_editar): ?>
                    <input type="hidden" name="id_proveedor_editar" value="<?= htmlspecialchars($proveedor_para_editar['id']) ?>">
                <?php endif; ?>
                <div class="row g-3">
                    <div class="col-md-12">
                        <label for="nombre_proveedor" class="form-label">Nombre del Proveedor <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm" id="nombre_proveedor" name="nombre_proveedor" 
                               value="<?= htmlspecialchars($proveedor_para_editar['nombre_proveedor'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label for="contacto_nombre" class="form-label">Nombre de Contacto</label>
                        <input type="text" class="form-control form-control-sm" id="contacto_nombre" name="contacto_nombre"
                               value="<?= htmlspecialchars($proveedor_para_editar['contacto_nombre'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="contacto_telefono" class="form-label">Teléfono de Contacto</label>
                        <input type="text" class="form-control form-control-sm" id="contacto_telefono" name="contacto_telefono"
                               value="<?= htmlspecialchars($proveedor_para_editar['contacto_telefono'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="contacto_email" class="form-label">Email de Contacto</label>
                        <input type="email" class="form-control form-control-sm" id="contacto_email" name="contacto_email"
                               value="<?= htmlspecialchars($proveedor_para_editar['contacto_email'] ?? '') ?>">
                    </div>
                </div>
                <div class="mt-3">
                    <button type="submit" name="guardar_proveedor_submit" class="btn btn-sm btn-principal">
                        <i class="bi <?= $edit_mode ? 'bi-save-fill' : 'bi-plus-lg' ?>"></i> <?= $edit_mode ? 'Actualizar Proveedor' : 'Agregar Proveedor' ?>
                    </button>
                    <?php if ($edit_mode): ?>
                        <a href="gestionar_proveedores.php" class="btn btn-sm btn-outline-secondary">Cancelar Edición</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm mt-4">
        <div class="card-header bg-light">
            <h5 class="mb-0"><i class="bi bi-list-ul"></i> Lista de Proveedores Existentes</h5>
        </div>
        <div class="card-body p-0">
            <?php if (!empty($proveedores_listados)): ?>
                <div class="table-responsive">
                    <table class="table table-hover table-striped mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>ID</th>
                                <th>Nombre Proveedor</th>
                                <th>Contacto</th>
                                <th>Teléfono</th>
                                <th>Email</th>
                                <th>Registrado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($proveedores_listados as $prov): ?>
                                <tr>
                                    <td><?= htmlspecialchars($prov['id']) ?></td>
                                    <td><?= htmlspecialchars($prov['nombre_proveedor']) ?></td>
                                    <td><?= htmlspecialchars($prov['contacto_nombre'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($prov['contacto_telefono'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($prov['contacto_email'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars(date("d/m/Y", strtotime($prov['fecha_creacion']))) ?></td>
                                    <td>
                                        <a href="gestionar_proveedores.php?accion=editar&id=<?= $prov['id'] ?>" class="action-icon text-warning" title="Editar Proveedor"><i class="bi bi-pencil-fill"></i></a>
                                        <form method="POST" action="gestionar_proveedores.php" style="display: inline;" onsubmit="return confirm('¿Está seguro que desea eliminar este proveedor: <?= htmlspecialchars(addslashes($prov['nombre_proveedor'])) ?>? Esta acción no se puede deshacer.');">
                                            <input type="hidden" name="id_proveedor_eliminar" value="<?= $prov['id'] ?>">
                                            <button type="submit" name="eliminar_proveedor_submit" class="btn btn-link p-0 m-0 align-baseline action-icon text-danger" title="Eliminar Proveedor"><i class="bi bi-trash3-fill"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-info m-3">No hay proveedores registrados. Utilice el formulario de arriba para agregar uno nuevo.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (isset($conexion)) { $conexion->close(); } ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Script para auto-enfocar el formulario si se está editando
    document.addEventListener('DOMContentLoaded', function () {
        <?php if ($edit_mode && $proveedor_para_editar): ?>
            const formElement = document.getElementById('nombre_proveedor'); // O cualquier otro campo del formulario
            if(formElement) {
                formElement.focus();
                // Opcional: scroll hacia el formulario
                const cardForm = document.querySelector('.card-form');
                if(cardForm) cardForm.scrollIntoView({ behavior: 'smooth' });
            }
        <?php endif; ?>

        // Limpiar query params de la URL después de una acción para evitar re-ejecución al recargar
        if (window.history.replaceState) {
            const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
            if (window.location.search.includes('accion=editar')) {
                // No limpiar si estamos activamente editando (el formulario se pre-llena desde PHP)
            } else if (window.location.search) { // Limpiar si hay otros query params de acciones previas
                 // window.history.replaceState({ path: cleanUrl }, '', cleanUrl);
                 // Comentado temporalmente, ya que el reload de POST ya limpia el estado de GET 'accion=editar'
            }
        }
    });
</script>
</body>
</html>