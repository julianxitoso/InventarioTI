<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'backend/auth_check.php';
restringir_acceso_pagina(['admin']);

require_once 'backend/db.php';

// Inicializar mensaje de error de conexión
$conexion_error_msg = null;
if (isset($conn) && !isset($conexion)) {
    $conexion = $conn;
}
if (!isset($conexion) || !$conexion || (method_exists($conexion, 'connect_error') && $conexion->connect_error)) {
    $db_conn_error_detail = $conexion->connect_error ?? 'Desconocido';
    error_log("GESTIONAR_ACTIVOS (TIPOS): Error de conexión a la BD: " . $db_conn_error_detail);
    $conexion_error_msg = "<div class='alert alert-danger'>Error crítico de conexión a la base de datos. No se pueden cargar ni guardar datos. Contacte al administrador.</div>";
    // No se puede continuar sin conexión para esta página
} else {
    $conexion->set_charset("utf8mb4");
}

$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Administrador';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'admin';

// Inicializar $mensaje_accion correctamente
$mensaje_accion = $_SESSION['mensaje_accion_gestion'] ?? null; // Prioridad al mensaje de sesión
if ($conexion_error_msg && empty($mensaje_accion)) { // Si hubo error de conexión y no hay otro mensaje
    $mensaje_accion = $conexion_error_msg;
}
unset($_SESSION['mensaje_accion_gestion']); // Limpiar mensaje de sesión

$id_columna_pk = 'id_tipo_activo';
$nombre_columna_tipo = 'nombre_tipo_activo';
$tipo_activo_para_editar = null;
$abrir_modal_creacion_tipo_js = false;
$abrir_modal_editar_tipo_js = false;


// --- LÓGICA POST (CREAR o ACTUALIZAR TIPO DE ACTIVO) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$conexion_error_msg) { // Solo procesar POST si hay conexión
    if (isset($_POST['crear_tipo_activo_submit'])) {
        $nuevo_tipo_nombre = trim($_POST['nuevo_tipo_nombre_modal']);
        $descripcion = trim($_POST['descripcion_modal']) ?: null;
        $vida_util = !empty($_POST['vida_util_sugerida_modal']) ? (int)$_POST['vida_util_sugerida_modal'] : null;
        $campos_especificos = isset($_POST['campos_especificos_modal']) ? 1 : 0;

        if (!empty($nuevo_tipo_nombre)) {
            $stmt_check = $conexion->prepare("SELECT $id_columna_pk FROM tipos_activo WHERE $nombre_columna_tipo = ?");
            $stmt_check->bind_param("s", $nuevo_tipo_nombre);
            $stmt_check->execute();
            $stmt_check->store_result();
            if ($stmt_check->num_rows > 0) {
                $_SESSION['mensaje_accion_gestion'] = "<div class='alert alert-danger'>Creación: El tipo de activo '" . htmlspecialchars($nuevo_tipo_nombre) . "' ya existe.</div>";
            } else {
                $sql_insert = "INSERT INTO tipos_activo (nombre_tipo_activo, descripcion, vida_util_sugerida, campos_especificos) VALUES (?, ?, ?, ?)";
                $stmt_insert = $conexion->prepare($sql_insert);
                $stmt_insert->bind_param("ssii", $nuevo_tipo_nombre, $descripcion, $vida_util, $campos_especificos);
                if ($stmt_insert->execute()) {
                    $_SESSION['mensaje_accion_gestion'] = "<div class='alert alert-success'>Tipo de activo '" . htmlspecialchars($nuevo_tipo_nombre) . "' creado exitosamente.</div>";
                    header("Location: gestionar_activos.php"); exit;
                } else {
                    $_SESSION['mensaje_accion_gestion'] = "<div class='alert alert-danger'>Creación: Error al crear el tipo de activo: " . $stmt_insert->error . "</div>";
                    error_log("GESTIONAR_ACTIVOS (TIPOS): Error al INSERTAR tipo: " . $stmt_insert->error);
                }
                $stmt_insert->close();
            }
            $stmt_check->close();
        } else {
            $_SESSION['mensaje_accion_gestion'] = "<div class='alert alert-danger'>Creación: El nombre del tipo de activo no puede estar vacío.</div>";
        }
        header("Location: gestionar_activos.php?error_creacion_tipo=1"); 
        exit;
    }
    elseif (isset($_POST['editar_tipo_activo_submit'])) {
        $id_tipo_editar = filter_input(INPUT_POST, 'id_tipo_activo_editar', FILTER_VALIDATE_INT);
        $nombre_editado = trim($_POST['edit_tipo_nombre_modal']);
        $descripcion_editada = trim($_POST['edit_descripcion_modal']) ?: null;
        $vida_util_editada = !empty($_POST['edit_vida_util_sugerida_modal']) ? (int)$_POST['edit_vida_util_sugerida_modal'] : null;
        $campos_especificos_editados = isset($_POST['edit_campos_especificos_modal']) ? 1 : 0;

        if ($id_tipo_editar && !empty($nombre_editado)) {
            $stmt_check_edit = $conexion->prepare("SELECT $id_columna_pk FROM tipos_activo WHERE $nombre_columna_tipo = ? AND $id_columna_pk != ?");
            $stmt_check_edit->bind_param("si", $nombre_editado, $id_tipo_editar);
            $stmt_check_edit->execute();
            $stmt_check_edit->store_result();

            if ($stmt_check_edit->num_rows > 0) {
                $_SESSION['mensaje_accion_gestion'] = "<div class='alert alert-danger'>Edición: El nombre de tipo de activo '" . htmlspecialchars($nombre_editado) . "' ya está en uso por otro tipo.</div>";
                header("Location: gestionar_activos.php?accion=editar_tipo&id=" . $id_tipo_editar . "&error_edicion_tipo=1"); exit;
            } else {
                $sql_update = "UPDATE tipos_activo SET nombre_tipo_activo = ?, descripcion = ?, vida_util_sugerida = ?, campos_especificos = ? WHERE id_tipo_activo = ?";
                $stmt_update = $conexion->prepare($sql_update);
                $stmt_update->bind_param("ssiii", $nombre_editado, $descripcion_editada, $vida_util_editada, $campos_especificos_editados, $id_tipo_editar);
                if ($stmt_update->execute()) {
                    if ($stmt_update->affected_rows > 0) {
                        $_SESSION['mensaje_accion_gestion'] = "<div class='alert alert-success'>Tipo de activo '" . htmlspecialchars($nombre_editado) . "' actualizado exitosamente.</div>";
                    } else {
                        $_SESSION['mensaje_accion_gestion'] = "<div class='alert alert-info'>No se detectaron cambios en el tipo de activo o el tipo no fue encontrado.</div>";
                    }
                    header("Location: gestionar_activos.php"); exit;
                } else {
                    $_SESSION['mensaje_accion_gestion'] = "<div class='alert alert-danger'>Edición: Error al actualizar el tipo de activo: " . $stmt_update->error . "</div>";
                    error_log("GESTIONAR_ACTIVOS (TIPOS): Error al ACTUALIZAR tipo ID {$id_tipo_editar}: " . $stmt_update->error);
                    header("Location: gestionar_activos.php?accion=editar_tipo&id=" . $id_tipo_editar . "&error_edicion_tipo=1"); exit;
                }
                $stmt_update->close();
            }
            $stmt_check_edit->close();
        } else {
            $_SESSION['mensaje_accion_gestion'] = "<div class='alert alert-danger'>Edición: Faltan datos para actualizar o el nombre no puede estar vacío.</div>";
            header("Location: gestionar_activos.php" . ($id_tipo_editar ? "?accion=editar_tipo&id=".$id_tipo_editar."&error_edicion_tipo=1" : "?error_edicion_tipo=1")); exit;
        }
    }
}

// --- LÓGICA GET (ELIMINAR O CARGAR PARA EDITAR) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['accion']) && isset($_GET['id']) && !$conexion_error_msg) {
    $id_get = (int)$_GET['id'];
    if ($id_get > 0) {
        if ($_GET['accion'] === 'eliminar') {
            $stmt = $conexion->prepare("DELETE FROM tipos_activo WHERE $id_columna_pk = ?");
            $stmt->bind_param("i", $id_get);
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    $_SESSION['mensaje_accion_gestion'] = "<div class='alert alert-info'>Tipo de activo eliminado exitosamente.</div>";
                } else {
                    $_SESSION['mensaje_accion_gestion'] = "<div class='alert alert-warning'>No se encontró el tipo de activo para eliminar o ya fue eliminado.</div>";
                }
            } else {
                if ($conexion->errno == 1451) { 
                    $_SESSION['mensaje_accion_gestion'] = "<div class='alert alert-danger'>Error al eliminar: Este tipo de activo está actualmente en uso por uno o más activos y no puede ser eliminado.</div>";
                } else {
                    $_SESSION['mensaje_accion_gestion'] = "<div class='alert alert-danger'>Error al eliminar el tipo de activo: " . $stmt->error . "</div>";
                }
                error_log("GESTIONAR_ACTIVOS (TIPOS): Error al ELIMINAR tipo ID {$id_get}: " . $stmt->error);
            }
            $stmt->close();
            header("Location: gestionar_activos.php"); exit;
        } elseif ($_GET['accion'] === 'editar_tipo') {
            $stmt_edit_get = $conexion->prepare("SELECT * FROM tipos_activo WHERE $id_columna_pk = ?");
            if ($stmt_edit_get) {
                $stmt_edit_get->bind_param("i", $id_get);
                $stmt_edit_get->execute();
                $result_edit_get = $stmt_edit_get->get_result();
                if ($result_edit_get->num_rows === 1) {
                    $tipo_activo_para_editar = $result_edit_get->fetch_assoc();
                    $abrir_modal_editar_tipo_js = true; // Marcar para abrir modal de edición
                } else {
                    $_SESSION['mensaje_accion_gestion'] = "<div class='alert alert-warning'>Tipo de activo no encontrado para editar (ID: {$id_get}).</div>";
                     // No redirigir aquí, para que el mensaje se muestre en la página actual
                }
                $stmt_edit_get->close();
            } else {
                 $_SESSION['mensaje_accion_gestion'] = "<div class='alert alert-danger'>Error al preparar datos para edición: " . $conexion->error . "</div>";
            }
             // Si hay error en edición y se redirige desde POST, se setea el flag
            if(isset($_GET['error_edicion_tipo']) && $_GET['error_edicion_tipo'] == '1'){
                $abrir_modal_editar_tipo_js = true;
            }
        }
    }
}

// --- OBTENER LISTA DE TIPOS DE ACTIVO PARA LA TABLA ---
$tipos_activo_listados = [];
if (!$conexion_error_msg) { // Solo intentar si hay conexión
    $sql_tipos = "SELECT id_tipo_activo, nombre_tipo_activo, descripcion, vida_util_sugerida, campos_especificos FROM tipos_activo ORDER BY nombre_tipo_activo ASC";
    $result_tipos_list = $conexion->query($sql_tipos);
    if ($result_tipos_list) {
        while ($row_list = $result_tipos_list->fetch_assoc()) {
            $tipos_activo_listados[] = $row_list;
        }
    } else {
        error_log("GESTIONAR_ACTIVOS (TIPOS): Error al listar tipos de activo: " . $conexion->error);
        if(empty($mensaje_accion)) $mensaje_accion = "<div class='alert alert-danger'>Error al cargar la lista de tipos de activo.</div>";
    }
}


// Determinar si el modal de creación debe abrirse automáticamente (si hubo error de creación)
if (isset($_GET['error_creacion_tipo']) && $_GET['error_creacion_tipo'] == '1' && !empty($mensaje_accion)) {
    if (is_string($mensaje_accion) && stripos($mensaje_accion, "Creación:") !== false) {
        $abrir_modal_creacion_tipo_js = true;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gestionar Tipos de Activo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            padding-top: 110px; 
            background-color: #eef2f5; 
            font-size: 0.92rem; 
        }
        .top-bar-custom { 
            position: fixed; top: 0; left: 0; right: 0; z-index: 1030; 
            display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; 
            padding: 0.5rem 1.5rem; background-color: #ffffff; 
            border-bottom: 1px solid #dee2e6; box-shadow: 0 1px 3px rgba(0,0,0,0.05); 
        }
        .logo-container-top img { height: 75px; width: auto; }
        .top-bar-user-info .navbar-text { font-size: 0.8rem; }
        .top-bar-user-info .btn { font-size: 0.8rem; }

        .page-header-custom-area {
            /* No specific styles needed if Bootstrap flex handles it all */
        }
        h1.page-title { 
            color: #0d6efd; /* Azul primario de Bootstrap */
            font-weight: 600; 
            font-size: 1.75rem; 
            flex-grow: 3px; */ /* Opcional: si quieres que el título tome más espacio */
        }
        .card { border: none; box-shadow: 0 0 10px rgba(0,0,0,0.06); }
        .card-header { background-color: #f8f9fa; border-bottom: 1px solid #dee2e6; font-weight: 500; color: #495057; font-size: 1.05rem; }
        .table thead th { background-color: #4A5568; color: white; font-weight: 500; vertical-align: middle; font-size: 0.85rem; padding: 0.6rem 0.75rem; white-space: nowrap;}
        .table tbody td { vertical-align: middle; font-size: 0.85rem; padding: 0.6rem 0.75rem; }
        .form-label { font-weight: 500; color: #495057; font-size: 0.85rem; }
        .container.mt-4 {max-width: 992px;}
        .action-icon { font-size: 1rem; text-decoration: none; margin-right: 0.3rem; }

        @media (max-width: 575.98px) { /* xs screens */
            body { padding-top: 150px; } 
            .top-bar-custom { flex-direction: column; padding: 0.75rem 1rem; }
            .logo-container-top { margin-bottom: 0.5rem; text-align: center; width: 100%; }
            .top-bar-user-info { display: flex; flex-direction: column; align-items: center; width: 100%; text-align: center; }
            .top-bar-user-info .navbar-text { margin-right: 0; margin-bottom: 0.5rem; }
            
            h1.page-title { 
                font-size: 1.4rem !important; /* Título más pequeño en móviles */
                margin-top: 0.5rem; 
                margin-bottom: 0.75rem;
            }
            .page-header-custom-area .btn { /* Hacer botones más prominentes en modo columna */
                 width: auto; /* Opcional: width: 100%; para full-width */
                 margin-bottom: 0.5rem;
            }
             .page-header-custom-area > div:last-child .btn { /* Quitar margen inferior al último botón en modo columna */
                margin-bottom: 0;
            }
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
            <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#modalCrearTipoActivo">
                <i class="bi bi-plus-circle"></i> Crear Nuevo Tipo de Activo
            </button>
        </div>

        <div class="flex-fill text-center order-first order-sm-2 px-sm-3"> 
            <h1 class="page-title my-2 my-sm-0">
                <i class="bi bi-tags-fill"></i> Gestión de Tipos de Activo
            </h1>
        </div>

        <div class="mt-2 mt-sm-0 text-center text-sm-end order-sm-3" style="flex-shrink: 0;"> 
             <a href="centro_gestion.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left-circle"></i> Volver al Centro de Gestión
            </a>
        </div>
    </div>
    <?php if ($mensaje_accion && is_string($mensaje_accion)): ?>
        <div class='mb-3 text-center'><?php echo $mensaje_accion; ?></div>
    <?php endif; ?>

    <div class="card mt-2"> 
        <div class="card-header"><i class="bi bi-list-ul"></i> Tipos de Activo Registrados</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover table-sm mb-0">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Descripción</th>
                            <th>Vida Útil (Años)</th>
                            <th>Campos Específicos</th>
                            <th style="min-width: 100px;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($tipos_activo_listados)): ?>
                            <?php foreach ($tipos_activo_listados as $tipo): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($tipo['nombre_tipo_activo']) ?></strong></td>
                                    <td><?= nl2br(htmlspecialchars($tipo['descripcion'] ?: 'N/A')) ?></td>
                                    <td><?= htmlspecialchars($tipo['vida_util_sugerida'] ? $tipo['vida_util_sugerida'] : 'N/A') ?></td>
                                    <td>
                                        <?php if ($tipo['campos_especificos']): ?>
                                            <span class="badge rounded-pill bg-success">Sí</span>
                                        <?php else: ?>
                                            <span class="badge rounded-pill bg-secondary">No</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="gestionar_activos.php?accion=editar_tipo&id=<?= $tipo[$id_columna_pk] ?>" class="btn btn-sm btn-outline-warning action-icon" title="Editar Tipo de Activo">
                                            <i class="bi bi-pencil-square"></i>
                                        </a>
                                        <a href="gestionar_activos.php?accion=eliminar&id=<?= $tipo[$id_columna_pk] ?>" class="btn btn-sm btn-outline-danger action-icon" title="Eliminar Tipo de Activo" 
                                           onclick="return confirm('¿Está seguro de eliminar este tipo de activo: \'<?= htmlspecialchars(addslashes($tipo['nombre_tipo_activo'])) ?>\'? Esta acción podría fallar si el tipo de activo está en uso.');">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="text-center p-4">No hay tipos de activo registrados o hubo un error al cargarlos.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalCrearTipoActivo" tabindex="-1" aria-labelledby="modalCrearTipoActivoLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" action="gestionar_activos.php" id="formCrearTipoActivoModal">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalCrearTipoActivoLabel"><i class="bi bi-plus-circle-fill"></i> Añadir Nuevo Tipo de Activo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="nuevo_tipo_nombre_modal" class="form-label">Nombre del Tipo <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm" id="nuevo_tipo_nombre_modal" name="nuevo_tipo_nombre_modal" required>
                    </div>
                    <div class="mb-3">
                        <label for="vida_util_sugerida_modal" class="form-label">Vida Útil Sugerida (Años)</label>
                        <input type="number" class="form-control form-control-sm" id="vida_util_sugerida_modal" name="vida_util_sugerida_modal" min="0" step="1" placeholder="Ej: 5">
                    </div>
                    <div class="mb-3">
                        <label for="descripcion_modal" class="form-label">Descripción</label>
                        <textarea class="form-control form-control-sm" id="descripcion_modal" name="descripcion_modal" rows="3"></textarea>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="campos_especificos_modal" name="campos_especificos_modal" value="1">
                        <label class="form-check-label" for="campos_especificos_modal">Requiere Campos Específicos (Ej: CPU, RAM para Computador)</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" name="crear_tipo_activo_submit" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Guardar Tipo de Activo</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($tipo_activo_para_editar): ?>
<div class="modal fade" id="modalEditarTipoActivo" tabindex="-1" aria-labelledby="modalEditarTipoActivoLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" action="gestionar_activos.php" id="formEditarTipoActivoModal">
                <input type="hidden" name="id_tipo_activo_editar" value="<?= htmlspecialchars($tipo_activo_para_editar[$id_columna_pk]) ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalEditarTipoActivoLabel"><i class="bi bi-pencil-fill"></i> Editar Tipo de Activo: <?= htmlspecialchars($tipo_activo_para_editar['nombre_tipo_activo']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_tipo_nombre_modal" class="form-label">Nombre del Tipo <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm" id="edit_tipo_nombre_modal" name="edit_tipo_nombre_modal" 
                               value="<?= htmlspecialchars($tipo_activo_para_editar['nombre_tipo_activo']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_vida_util_sugerida_modal" class="form-label">Vida Útil Sugerida (Años)</label>
                        <input type="number" class="form-control form-control-sm" id="edit_vida_util_sugerida_modal" name="edit_vida_util_sugerida_modal" 
                               min="0" step="1" placeholder="Ej: 5" value="<?= htmlspecialchars($tipo_activo_para_editar['vida_util_sugerida'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label for="edit_descripcion_modal" class="form-label">Descripción</label>
                        <textarea class="form-control form-control-sm" id="edit_descripcion_modal" name="edit_descripcion_modal" 
                                  rows="3"><?= htmlspecialchars($tipo_activo_para_editar['descripcion'] ?? '') ?></textarea>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="edit_campos_especificos_modal" name="edit_campos_especificos_modal" 
                               value="1" <?= ($tipo_activo_para_editar['campos_especificos'] ?? 0) == 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="edit_campos_especificos_modal">Requiere Campos Específicos</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" name="editar_tipo_activo_submit" class="btn btn-primary btn-sm"><i class="bi bi-save"></i> Guardar Cambios</button>
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
    if (currentUrl.searchParams.has('error_creacion_tipo')) {
        currentUrl.searchParams.delete('error_creacion_tipo');
        window.history.replaceState({}, document.title, currentUrl.toString());
    }
    if (currentUrl.searchParams.has('error_edicion_tipo')) {
        currentUrl.searchParams.delete('error_edicion_tipo');
        window.history.replaceState({}, document.title, currentUrl.toString());
    }

    <?php if ($abrir_modal_creacion_tipo_js): ?>
    const modalCrearTipoActivoEl = document.getElementById('modalCrearTipoActivo');
    if (modalCrearTipoActivoEl) {
        const modalCrear = new bootstrap.Modal(modalCrearTipoActivoEl);
        modalCrear.show();
    }
    <?php endif; ?>

    <?php if ($abrir_modal_editar_tipo_js && $tipo_activo_para_editar): ?>
    const modalEditarTipoActivoEl = document.getElementById('modalEditarTipoActivo');
    if (modalEditarTipoActivoEl) {
        const modalEditar = new bootstrap.Modal(modalEditarTipoActivoEl);
        modalEditar.show(); 
    }
    <?php endif; ?>

});
</script>
</body>
</html>