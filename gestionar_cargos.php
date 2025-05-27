<?php
session_start();
require_once 'backend/auth_check.php';
restringir_acceso_pagina(['admin']); // Solo administradores pueden gestionar cargos

require_once 'backend/db.php';

if (isset($conn) && !isset($conexion)) {
    $conexion = $conn;
}
if (!isset($conexion) || !$conexion || (method_exists($conexion, 'connect_error') && $conexion->connect_error)) {
    error_log("Error de conexión BD en gestionar_cargos.php: " . ($conexion->connect_error ?? 'Desconocido'));
    die("Error de conexión a la base de datos.");
}
$conexion->set_charset("utf8mb4");

$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Administrador';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'admin';

$mensaje_accion = $_SESSION['mensaje_accion_cargos'] ?? null;
if (isset($_SESSION['mensaje_accion_cargos'])) {
    unset($_SESSION['mensaje_accion_cargos']);
}

$cargo_para_editar = null;
$edit_mode = false;

// --- PROCESAR ACCIONES POST (Crear, Actualizar, Eliminar) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ------ CREAR O ACTUALIZAR CARGO ------
    if (isset($_POST['guardar_cargo_submit'])) {
        $id_cargo = filter_input(INPUT_POST, 'id_cargo_editar', FILTER_VALIDATE_INT);
        $nombre_cargo = trim($_POST['nombre_cargo'] ?? '');
        $descripcion_cargo = trim($_POST['descripcion_cargo'] ?? '');

        if (empty($nombre_cargo)) {
            $mensaje_accion = "<div class='alert alert-danger'>El nombre del cargo es obligatorio.</div>";
        } else {
            $sql_check_nombre = "SELECT id_cargo FROM cargos WHERE nombre_cargo = ?";
            $params_check = [$nombre_cargo];
            if ($id_cargo) {
                $sql_check_nombre .= " AND id_cargo != ?";
                $params_check[] = $id_cargo;
            }
            $stmt_check = $conexion->prepare($sql_check_nombre);
            if ($id_cargo) { $stmt_check->bind_param("si", $nombre_cargo, $id_cargo); } 
            else { $stmt_check->bind_param("s", $nombre_cargo); }
            $stmt_check->execute();
            $stmt_check->store_result();

            if ($stmt_check->num_rows > 0) {
                $mensaje_accion = "<div class='alert alert-danger'>El nombre del cargo '" . htmlspecialchars($nombre_cargo) . "' ya existe.</div>";
            } else {
                if ($id_cargo) { // Actualizar
                    $sql = "UPDATE cargos SET nombre_cargo = ?, descripcion_cargo = ? WHERE id_cargo = ?";
                    $stmt = $conexion->prepare($sql);
                    $stmt->bind_param("ssi", $nombre_cargo, $descripcion_cargo, $id_cargo);
                    $accion_texto = "actualizado";
                } else { // Crear
                    $sql = "INSERT INTO cargos (nombre_cargo, descripcion_cargo) VALUES (?, ?)";
                    $stmt = $conexion->prepare($sql);
                    $stmt->bind_param("ss", $nombre_cargo, $descripcion_cargo);
                    $accion_texto = "creado";
                }

                if ($stmt && $stmt->execute()) {
                    $_SESSION['mensaje_accion_cargos'] = "<div class='alert alert-success'>Cargo '" . htmlspecialchars($nombre_cargo) . "' " . $accion_texto . " exitosamente.</div>";
                } else {
                    $_SESSION['mensaje_accion_cargos'] = "<div class='alert alert-danger'>Error al " . ($id_cargo ? "actualizar" : "crear") . " el cargo: " . ($stmt ? $stmt->error : $conexion->error) . "</div>";
                    error_log("Error DB cargos: " . ($stmt ? $stmt->error : $conexion->error));
                }
                if ($stmt) $stmt->close();
                header("Location: gestionar_cargos.php");
                exit;
            }
            $stmt_check->close();
        }
        if ($id_cargo && !empty($mensaje_accion)) {
            $stmt_reload = $conexion->prepare("SELECT * FROM cargos WHERE id_cargo = ?");
            if($stmt_reload){
                $stmt_reload->bind_param("i", $id_cargo);
                $stmt_reload->execute();
                $result_reload = $stmt_reload->get_result();
                if ($result_reload->num_rows === 1) {
                    $cargo_para_editar = $result_reload->fetch_assoc();
                    $edit_mode = true;
                }
                $stmt_reload->close();
            }
        }
    }
    // ------ ELIMINAR CARGO ------
    elseif (isset($_POST['eliminar_cargo_submit'])) {
        $id_cargo_eliminar = filter_input(INPUT_POST, 'id_cargo_eliminar', FILTER_VALIDATE_INT);
        if ($id_cargo_eliminar) {
            // Verificar si el cargo está en uso por usuarios
            $stmt_check_uso = $conexion->prepare("SELECT COUNT(*) as total FROM usuarios WHERE cargo = (SELECT nombre_cargo FROM cargos WHERE id_cargo = ?)");
            $stmt_check_uso->bind_param("i", $id_cargo_eliminar);
            $stmt_check_uso->execute();
            $res_check_uso = $stmt_check_uso->get_result()->fetch_assoc();
            $stmt_check_uso->close();

            if ($res_check_uso['total'] > 0) {
                $_SESSION['mensaje_accion_cargos'] = "<div class='alert alert-danger'>No se puede eliminar el cargo porque está asignado a " . $res_check_uso['total'] . " usuario(s). Reasigne los usuarios a otro cargo primero.</div>";
            } else {
                $sql_delete = "DELETE FROM cargos WHERE id_cargo = ?";
                $stmt_delete = $conexion->prepare($sql_delete);
                if ($stmt_delete) {
                    $stmt_delete->bind_param("i", $id_cargo_eliminar);
                    if ($stmt_delete->execute()) {
                        $_SESSION['mensaje_accion_cargos'] = "<div class='alert alert-info'>Cargo eliminado exitosamente.</div>";
                    } else {
                        $_SESSION['mensaje_accion_cargos'] = "<div class='alert alert-danger'>Error al eliminar el cargo: " . $stmt_delete->error . "</div>";
                        error_log("Error DB al eliminar cargo: " . $stmt_delete->error);
                    }
                    $stmt_delete->close();
                } else {
                     $_SESSION['mensaje_accion_cargos'] = "<div class='alert alert-danger'>Error al preparar la eliminación del cargo.</div>";
                }
            }
            header("Location: gestionar_cargos.php");
            exit;
        }
    }
}

// --- Lógica para cargar datos para editar (GET) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['accion']) && $_GET['accion'] === 'editar' && isset($_GET['id'])) {
    $id_cargo_get = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if ($id_cargo_get) {
        $stmt_edit = $conexion->prepare("SELECT * FROM cargos WHERE id_cargo = ?");
        if ($stmt_edit) {
            $stmt_edit->bind_param("i", $id_cargo_get);
            $stmt_edit->execute();
            $result_edit = $stmt_edit->get_result();
            if ($result_edit->num_rows === 1) {
                $cargo_para_editar = $result_edit->fetch_assoc();
                $edit_mode = true;
            } else {
                $_SESSION['mensaje_accion_cargos'] = "<div class='alert alert-warning'>Cargo no encontrado para editar.</div>";
            }
            $stmt_edit->close();
        }
    }
}

// --- Obtener lista de cargos para mostrar ---
$cargos_listados = [];
$sql_listar_cargos = "SELECT id_cargo, nombre_cargo, descripcion_cargo, fecha_creacion FROM cargos ORDER BY nombre_cargo ASC";
$result_listar_cargos = $conexion->query($sql_listar_cargos);
if ($result_listar_cargos) {
    while ($row = $result_listar_cargos->fetch_assoc()) {
        $cargos_listados[] = $row;
    }
} else {
    if (!$mensaje_accion) {
        $mensaje_accion = "<div class='alert alert-danger'>Error al obtener la lista de cargos: " . $conexion->error . "</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestionar Cargos</title>
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
    <h3 class="page-title text-center mb-4"><i class="bi bi-person-badge-fill"></i> Gestión de Cargos</h3>

    <?php if ($mensaje_accion) echo $mensaje_accion; ?>

    <div class="card card-form shadow-sm">
        <div class="card-header bg-light">
            <h5 class="mb-0"><?= $edit_mode ? '<i class="bi bi-pencil-square"></i> Editar Cargo' : '<i class="bi bi-plus-circle-fill"></i> Agregar Nuevo Cargo' ?></h5>
        </div>
        <div class="card-body">
            <form method="POST" action="gestionar_cargos.php">
                <?php if ($edit_mode && $cargo_para_editar): ?>
                    <input type="hidden" name="id_cargo_editar" value="<?= htmlspecialchars($cargo_para_editar['id_cargo']) ?>">
                <?php endif; ?>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="nombre_cargo" class="form-label">Nombre del Cargo <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm" id="nombre_cargo" name="nombre_cargo" 
                               value="<?= htmlspecialchars($cargo_para_editar['nombre_cargo'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label for="descripcion_cargo" class="form-label">Descripción del Cargo (Opcional)</label>
                        <input type="text" class="form-control form-control-sm" id="descripcion_cargo" name="descripcion_cargo"
                               value="<?= htmlspecialchars($cargo_para_editar['descripcion_cargo'] ?? '') ?>">
                    </div>
                </div>
                <div class="mt-3">
                    <button type="submit" name="guardar_cargo_submit" class="btn btn-sm btn-principal">
                        <i class="bi <?= $edit_mode ? 'bi-save-fill' : 'bi-plus-lg' ?>"></i> <?= $edit_mode ? 'Actualizar Cargo' : 'Agregar Cargo' ?>
                    </button>
                    <?php if ($edit_mode): ?>
                        <a href="gestionar_cargos.php" class="btn btn-sm btn-outline-secondary">Cancelar Edición</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm mt-4">
        <div class="card-header bg-light">
            <h5 class="mb-0"><i class="bi bi-list-ul"></i> Lista de Cargos Existentes</h5>
        </div>
        <div class="card-body p-0">
            <?php if (!empty($cargos_listados)): ?>
                <div class="table-responsive">
                    <table class="table table-hover table-striped mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>ID</th>
                                <th>Nombre del Cargo</th>
                                <th>Descripción</th>
                                <th>Fecha Creación</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cargos_listados as $cargo_item): ?>
                                <tr>
                                    <td><?= htmlspecialchars($cargo_item['id_cargo']) ?></td>
                                    <td><strong><?= htmlspecialchars($cargo_item['nombre_cargo']) ?></strong></td>
                                    <td><?= htmlspecialchars($cargo_item['descripcion_cargo'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars(date("d/m/Y H:i", strtotime($cargo_item['fecha_creacion']))) ?></td>
                                    <td>
                                        <a href="gestionar_cargos.php?accion=editar&id=<?= $cargo_item['id_cargo'] ?>" class="action-icon text-warning" title="Editar Cargo"><i class="bi bi-pencil-fill"></i></a>
                                        <form method="POST" action="gestionar_cargos.php" style="display: inline;" onsubmit="return confirm('¿Está seguro que desea eliminar este cargo: <?= htmlspecialchars(addslashes($cargo_item['nombre_cargo'])) ?>? Si este cargo está asignado a usuarios, deberá reasignarlos primero.');">
                                            <input type="hidden" name="id_cargo_eliminar" value="<?= $cargo_item['id_cargo'] ?>">
                                            <button type="submit" name="eliminar_cargo_submit" class="btn btn-link p-0 m-0 align-baseline action-icon text-danger" title="Eliminar Cargo"><i class="bi bi-trash3-fill"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-info m-3">No hay cargos registrados. Utilice el formulario de arriba para agregar uno nuevo.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (isset($conexion)) { $conexion->close(); } ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        <?php if ($edit_mode && $cargo_para_editar): ?>
            const formElement = document.getElementById('nombre_cargo');
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