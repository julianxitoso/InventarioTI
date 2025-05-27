<?php
session_start();
require_once 'backend/auth_check.php';
restringir_acceso_pagina(['admin']); // Solo administradores pueden gestionar roles

require_once 'backend/db.php';

if (isset($conn) && !isset($conexion)) {
    $conexion = $conn;
}
if (!isset($conexion) || !$conexion || (method_exists($conexion, 'connect_error') && $conexion->connect_error)) {
    error_log("Error de conexión BD en gestionar_roles.php: " . ($conexion->connect_error ?? 'Desconocido'));
    die("Error de conexión a la base de datos.");
}
$conexion->set_charset("utf8mb4");

$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Administrador';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'admin';

$mensaje_accion = $_SESSION['mensaje_accion_roles'] ?? null;
if (isset($_SESSION['mensaje_accion_roles'])) {
    unset($_SESSION['mensaje_accion_roles']);
}

$rol_para_editar = null;
$edit_mode = false;

// --- PROCESAR ACCIONES POST (Crear, Actualizar, Eliminar) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ------ CREAR O ACTUALIZAR ROL ------
    if (isset($_POST['guardar_rol_submit'])) {
        $id_rol = filter_input(INPUT_POST, 'id_rol_editar', FILTER_VALIDATE_INT);
        $nombre_rol = trim($_POST['nombre_rol'] ?? '');
        $descripcion_rol = trim($_POST['descripcion_rol'] ?? '');

        if (empty($nombre_rol)) {
            $mensaje_accion = "<div class='alert alert-danger'>El nombre del rol es obligatorio.</div>";
        } else {
            // Verificar si el nombre del rol ya existe
            $sql_check_nombre = "SELECT id_rol FROM roles WHERE nombre_rol = ?";
            $params_check = [$nombre_rol];
            if ($id_rol) { // Si estamos editando, excluimos el ID actual
                $sql_check_nombre .= " AND id_rol != ?";
                $params_check[] = $id_rol;
            }
            $stmt_check = $conexion->prepare($sql_check_nombre);
            // Ajustar bind_param según si $id_rol existe
            if ($id_rol) { $stmt_check->bind_param("si", $nombre_rol, $id_rol); } 
            else { $stmt_check->bind_param("s", $nombre_rol); }
            $stmt_check->execute();
            $stmt_check->store_result();

            if ($stmt_check->num_rows > 0) {
                $mensaje_accion = "<div class='alert alert-danger'>El nombre del rol '" . htmlspecialchars($nombre_rol) . "' ya existe.</div>";
            } else {
                if ($id_rol) { // Actualizar
                    $sql = "UPDATE roles SET nombre_rol = ?, descripcion_rol = ? WHERE id_rol = ?";
                    $stmt = $conexion->prepare($sql);
                    $stmt->bind_param("ssi", $nombre_rol, $descripcion_rol, $id_rol);
                    $accion_texto = "actualizado";
                } else { // Crear
                    $sql = "INSERT INTO roles (nombre_rol, descripcion_rol) VALUES (?, ?)";
                    $stmt = $conexion->prepare($sql);
                    $stmt->bind_param("ss", $nombre_rol, $descripcion_rol);
                    $accion_texto = "creado";
                }

                if ($stmt && $stmt->execute()) {
                    $_SESSION['mensaje_accion_roles'] = "<div class='alert alert-success'>Rol '" . htmlspecialchars($nombre_rol) . "' " . $accion_texto . " exitosamente. <br>Recuerde que si es un nuevo rol, debe definir sus permisos en el archivo <code>backend/auth_check.php</code>.</div>";
                } else {
                    $_SESSION['mensaje_accion_roles'] = "<div class='alert alert-danger'>Error al " . ($id_rol ? "actualizar" : "crear") . " el rol: " . ($stmt ? $stmt->error : $conexion->error) . "</div>";
                    error_log("Error DB roles: " . ($stmt ? $stmt->error : $conexion->error));
                }
                if ($stmt) $stmt->close();
                header("Location: gestionar_roles.php");
                exit;
            }
            $stmt_check->close();
        }
        if ($id_rol && !empty($mensaje_accion)) { // Para re-poblar form en error de edición
            $stmt_reload = $conexion->prepare("SELECT * FROM roles WHERE id_rol = ?");
            if($stmt_reload){
                $stmt_reload->bind_param("i", $id_rol);
                $stmt_reload->execute();
                $result_reload = $stmt_reload->get_result();
                if ($result_reload->num_rows === 1) {
                    $rol_para_editar = $result_reload->fetch_assoc();
                    $edit_mode = true;
                }
                $stmt_reload->close();
            }
        }
    }
    // ------ ELIMINAR ROL ------
    elseif (isset($_POST['eliminar_rol_submit'])) {
        $id_rol_eliminar = filter_input(INPUT_POST, 'id_rol_eliminar', FILTER_VALIDATE_INT);
        if ($id_rol_eliminar) {
            // PRECAUCIÓN: Verificar si el rol está en uso por usuarios
            $stmt_check_uso = $conexion->prepare("SELECT COUNT(*) as total FROM usuarios WHERE rol = (SELECT nombre_rol FROM roles WHERE id_rol = ?)");
            $stmt_check_uso->bind_param("i", $id_rol_eliminar);
            $stmt_check_uso->execute();
            $res_check_uso = $stmt_check_uso->get_result()->fetch_assoc();
            $stmt_check_uso->close();

            if ($res_check_uso['total'] > 0) {
                $_SESSION['mensaje_accion_roles'] = "<div class='alert alert-danger'>No se puede eliminar el rol porque está asignado a " . $res_check_uso['total'] . " usuario(s). Reasigne los usuarios a otro rol primero.</div>";
            } else {
                // También verificar si el rol es uno de los 'super roles' que no deberían eliminarse (ej: admin)
                $stmt_get_rol_name = $conexion->prepare("SELECT nombre_rol FROM roles WHERE id_rol = ?");
                $stmt_get_rol_name->bind_param("i", $id_rol_eliminar);
                $stmt_get_rol_name->execute();
                $rol_name_to_delete = $stmt_get_rol_name->get_result()->fetch_assoc()['nombre_rol'];
                $stmt_get_rol_name->close();

                if (in_array($rol_name_to_delete, ['admin'])) { // Lista de roles críticos
                     $_SESSION['mensaje_accion_roles'] = "<div class='alert alert-danger'>El rol '" . htmlspecialchars($rol_name_to_delete) . "' es crítico y no puede ser eliminado.</div>";
                } else {
                    $sql_delete = "DELETE FROM roles WHERE id_rol = ?";
                    $stmt_delete = $conexion->prepare($sql_delete);
                    if ($stmt_delete) {
                        $stmt_delete->bind_param("i", $id_rol_eliminar);
                        if ($stmt_delete->execute()) {
                            $_SESSION['mensaje_accion_roles'] = "<div class='alert alert-info'>Rol eliminado exitosamente. Recuerde verificar los permisos asociados en <code>auth_check.php</code>.</div>";
                        } else {
                            $_SESSION['mensaje_accion_roles'] = "<div class='alert alert-danger'>Error al eliminar el rol: " . $stmt_delete->error . "</div>";
                            error_log("Error DB al eliminar rol: " . $stmt_delete->error);
                        }
                        $stmt_delete->close();
                    } else {
                        $_SESSION['mensaje_accion_roles'] = "<div class='alert alert-danger'>Error al preparar la eliminación del rol.</div>";
                    }
                }
            }
            header("Location: gestionar_roles.php");
            exit;
        }
    }
}

// --- Lógica para cargar datos para editar (GET) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['accion']) && $_GET['accion'] === 'editar' && isset($_GET['id'])) {
    $id_rol_get = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if ($id_rol_get) {
        $stmt_edit = $conexion->prepare("SELECT * FROM roles WHERE id_rol = ?");
        if ($stmt_edit) {
            $stmt_edit->bind_param("i", $id_rol_get);
            $stmt_edit->execute();
            $result_edit = $stmt_edit->get_result();
            if ($result_edit->num_rows === 1) {
                $rol_para_editar = $result_edit->fetch_assoc();
                $edit_mode = true;
            } else {
                $_SESSION['mensaje_accion_roles'] = "<div class='alert alert-warning'>Rol no encontrado para editar.</div>";
            }
            $stmt_edit->close();
        }
    }
}

// --- Obtener lista de roles para mostrar ---
$roles_listados = [];
$sql_listar_roles = "SELECT id_rol, nombre_rol, descripcion_rol, fecha_creacion FROM roles ORDER BY nombre_rol ASC";
$result_listar_roles = $conexion->query($sql_listar_roles);
if ($result_listar_roles) {
    while ($row = $result_listar_roles->fetch_assoc()) {
        $roles_listados[] = $row;
    }
} else {
    if (!$mensaje_accion) {
        $mensaje_accion = "<div class='alert alert-danger'>Error al obtener la lista de roles: " . $conexion->error . "</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestionar Roles</title>
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
    <h3 class="page-title text-center mb-4"><i class="bi bi-shield-lock-fill"></i> Gestión de Roles del Sistema</h3>

    <?php if ($mensaje_accion) echo $mensaje_accion; ?>

    <div class="card card-form shadow-sm">
        <div class="card-header bg-light">
            <h5 class="mb-0"><?= $edit_mode ? '<i class="bi bi-pencil-square"></i> Editar Rol' : '<i class="bi bi-plus-circle-fill"></i> Agregar Nuevo Rol' ?></h5>
        </div>
        <div class="card-body">
            <form method="POST" action="gestionar_roles.php">
                <?php if ($edit_mode && $rol_para_editar): ?>
                    <input type="hidden" name="id_rol_editar" value="<?= htmlspecialchars($rol_para_editar['id_rol']) ?>">
                <?php endif; ?>
                <div class="row g-3">
                    <div class="col-md-5">
                        <label for="nombre_rol" class="form-label">Nombre del Rol <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm" id="nombre_rol" name="nombre_rol" 
                               value="<?= htmlspecialchars($rol_para_editar['nombre_rol'] ?? '') ?>" required 
                               pattern="[a-zA-Z0-9_]+" title="Solo letras, números y guion bajo (_). Sin espacios.">
                        <small class="form-text text-muted">Ej: 'admin', 'ventas_regional', 'soporte_nivel_1'. Usar solo minúsculas, números y guion bajo. Sin espacios.</small>
                    </div>
                    <div class="col-md-7">
                        <label for="descripcion_rol" class="form-label">Descripción del Rol</label>
                        <input type="text" class="form-control form-control-sm" id="descripcion_rol" name="descripcion_rol"
                               value="<?= htmlspecialchars($rol_para_editar['descripcion_rol'] ?? '') ?>">
                    </div>
                </div>
                <div class="mt-3">
                    <button type="submit" name="guardar_rol_submit" class="btn btn-sm btn-principal">
                        <i class="bi <?= $edit_mode ? 'bi-save-fill' : 'bi-plus-lg' ?>"></i> <?= $edit_mode ? 'Actualizar Rol' : 'Agregar Rol' ?>
                    </button>
                    <?php if ($edit_mode): ?>
                        <a href="gestionar_roles.php" class="btn btn-sm btn-outline-secondary">Cancelar Edición</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm mt-4">
        <div class="card-header bg-light">
            <h5 class="mb-0"><i class="bi bi-list-ul"></i> Lista de Roles Existentes</h5>
        </div>
        <div class="card-body p-0">
            <?php if (!empty($roles_listados)): ?>
                <div class="table-responsive">
                    <table class="table table-hover table-striped mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>ID</th>
                                <th>Nombre del Rol</th>
                                <th>Descripción</th>
                                <th>Fecha Creación</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($roles_listados as $rol_item): ?>
                                <tr>
                                    <td><?= htmlspecialchars($rol_item['id_rol']) ?></td>
                                    <td><strong><?= htmlspecialchars($rol_item['nombre_rol']) ?></strong></td>
                                    <td><?= htmlspecialchars($rol_item['descripcion_rol'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars(date("d/m/Y H:i", strtotime($rol_item['fecha_creacion']))) ?></td>
                                    <td>
                                        <a href="gestionar_roles.php?accion=editar&id=<?= $rol_item['id_rol'] ?>" class="action-icon text-warning" title="Editar Rol"><i class="bi bi-pencil-fill"></i></a>
                                        <?php if (!in_array($rol_item['nombre_rol'], ['admin', 'tecnico', 'auditor', 'registrador'])): // Prevenir eliminación de roles base/críticos ?>
                                        <form method="POST" action="gestionar_roles.php" style="display: inline;" onsubmit="return confirm('¿Está seguro que desea eliminar este rol: <?= htmlspecialchars(addslashes($rol_item['nombre_rol'])) ?>? Esta acción no se puede deshacer y podría afectar a usuarios con este rol.');">
                                            <input type="hidden" name="id_rol_eliminar" value="<?= $rol_item['id_rol'] ?>">
                                            <button type="submit" name="eliminar_rol_submit" class="btn btn-link p-0 m-0 align-baseline action-icon text-danger" title="Eliminar Rol"><i class="bi bi-trash3-fill"></i></button>
                                        </form>
                                        <?php else: ?>
                                            <i class="bi bi-slash-circle action-icon text-muted" title="Este rol base no se puede eliminar."></i>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-info m-3">No hay roles registrados. Utilice el formulario de arriba para agregar uno nuevo.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (isset($conexion)) { $conexion->close(); } ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        <?php if ($edit_mode && $rol_para_editar): ?>
            const formElement = document.getElementById('nombre_rol');
            if(formElement) {
                formElement.focus();
                const cardForm = document.querySelector('.card-form');
                if(cardForm) cardForm.scrollIntoView({ behavior: 'smooth' });
            }
        <?php endif; ?>
    });
</script>
</body>
</html>