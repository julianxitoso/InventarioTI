<?php
session_start();
// auth_check.php DEBE estar primero
require_once 'backend/auth_check.php'; 
// Ejemplo: Permitir ver historial a todos los roles autenticados que tienen permiso para 'ver_historial'
// o a roles específicos. Ajusta según tu lógica de permisos.
// Por ahora, asumimos que si llegas aquí con un ID de activo válido, puedes ver el historial.
// verificar_sesion_activa(); // auth_check.php ya debería llamar a esto o ser llamado por restringir_acceso_pagina
// Si tienes un permiso específico para 'ver_historial' en tu auth_check.php:
// if (!tiene_permiso_para('ver_historial')) {
//     $_SESSION['error_acceso_pagina'] = "No tiene permisos para ver historiales.";
//    header("Location: menu.php");
//    exit;
// }
// Si no, al menos verificar que esté logueado:
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php?error=sesion_requerida_historial");
    exit;
}


require_once 'backend/db.php';
require_once 'backend/historial_helper.php'; 

if (isset($conn) && !isset($conexion)) { $conexion = $conn; }
if (!isset($conexion) || !$conexion) {
    error_log("Fallo CRÍTICO de conexión a BD en historial.php.");
    die("Error de conexión a la base de datos.");
}
$conexion->set_charset("utf8mb4");

// Captura de datos de sesión para la barra superior
$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Usuario';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'Desconocido';


if (!isset($_GET['id_activo']) || !filter_var($_GET['id_activo'], FILTER_VALIDATE_INT) || $_GET['id_activo'] <= 0) {
    // Considera redirigir a una página de error o al menú con un mensaje
    $_SESSION['error_global'] = "ID de activo no válido o no proporcionado para ver el historial.";
    header("Location: buscar.php"); // O menu.php
    exit;
}
$id_activo_historial = (int)$_GET['id_activo'];

$activo_info = null;
// Añadir Empresa a la consulta para mostrarlo en la info del activo
$stmt_activo = $conexion->prepare("SELECT id, tipo_activo, serie, marca, nombre AS nombre_responsable, cedula AS cedula_responsable, regional, Empresa FROM activos_tecnologicos WHERE id = ?");
if ($stmt_activo) {
    $stmt_activo->bind_param('i', $id_activo_historial);
    $stmt_activo->execute();
    $result_activo = $stmt_activo->get_result();
    $activo_info = $result_activo->fetch_assoc();
    $stmt_activo->close();
}

if (!$activo_info) {
    $_SESSION['error_global'] = "Activo con ID " . htmlspecialchars($id_activo_historial) . " no encontrado.";
    header("Location: buscar.php"); // O menu.php
    exit;
}

$historial_items = [];
$stmt_hist = $conexion->prepare(
    "SELECT fecha_evento, tipo_evento, descripcion_evento, usuario_responsable, datos_anteriores, datos_nuevos
     FROM historial_activos
     WHERE id_activo = ? ORDER BY fecha_evento DESC, id_historial DESC"
);
if ($stmt_hist) {
    $stmt_hist->bind_param('i', $id_activo_historial);
    $stmt_hist->execute();
    $result_hist = $stmt_hist->get_result();
    while ($row_hist = $result_hist->fetch_assoc()) {
        $historial_items[] = $row_hist;
    }
    $stmt_hist->close();
} else {
    error_log("HISTORIAL: Error al preparar consulta para ver historial de activo ID $id_activo_historial: " . $conexion->error);
    // No mostrar un die() aquí, mejor un mensaje en la página.
    $error_historial_carga = "No se pudo cargar el historial completo debido a un error del sistema.";
}
$conexion->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Historial: <?= htmlspecialchars($activo_info['tipo_activo'] ?? '') ?> S/N: <?= htmlspecialchars($activo_info['serie'] ?? 'N/A') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { 
            background-color: #ffffff !important; 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding-top: 80px; /* Espacio para la barra superior fija */
        }
        .top-bar-custom {
            position: fixed; top: 0; left: 0; right: 0; z-index: 1030;
            display: flex; justify-content: space-between; align-items: center;
            padding: 0.5rem 1.5rem; background-color: #f8f9fa; 
            border-bottom: 1px solid #dee2e6; box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .logo-container-top img { width: auto; height: 75px; object-fit: contain; margin-right: 15px; }
        .user-info-top { font-size: 0.9rem; }
        
        /* Estilos específicos de la página de historial */
        .container-main-historial {
            max-width: 900px; /* Un poco menos ancho que antes */
            margin: 20px auto 40px auto; 
            background-color: #fff;
            padding: 25px;
            border-radius: 8px; /* Ligeramente menos redondeado */
            box-shadow: 0 2px 10px rgba(0,0,0,0.075); /* Sombra más sutil */
        }
        .page-header { 
            border-bottom: 1px solid #dee2e6; /* Borde más sutil */
            padding-bottom: 10px; 
            margin-bottom: 20px; 
            color: #191970; /* Color corporativo */
        }
        .page-header h3 { color: inherit; } /* Heredar color del padre */
        .activo-info-card { 
            background-color: #f8f9fa; /* Fondo más claro */
            border: 1px solid #e9ecef; /* Borde sutil */
            border-left: 4px solid #191970; /* Borde izquierdo con color corporativo */
            border-radius: 0.375rem;
        }
         .activo-info-card .card-header {
            background-color: transparent;
            border-bottom: 1px solid #e9ecef;
            font-weight: 600;
            color: #343a40;
         }
        .history-entry { border: 1px solid #e9ecef; border-radius: 0.375rem; margin-bottom: 15px; }
        .history-entry .card-header { background-color: #f8f9fa; font-weight: bold; font-size: 0.95em; }
        .history-entry .card-body { font-size: 0.9rem; }
        .history-meta { font-size: 0.8em; color: #6c757d; }
        .history-description { margin-top: 8px; }
        .badge-custom { font-size: 0.9em; padding: 0.4em 0.7em; }
        .details-toggle { cursor: pointer; color: #007bff; text-decoration: none; font-size: 0.85em; }
        .details-toggle:hover { text-decoration: underline; }
        .details-content { background-color: #fdfdff; border: 1px solid #e9ecef; padding: 12px; margin-top: 8px; border-radius: 5px; font-size: 0.85em; }
        .details-content ul { padding-left: 18px; margin-bottom: 0; list-style-type: disc; }
        .details-content strong { color: #333; }

        @media print {
            body { padding-top: 0; background-color: #fff !important; }
            .top-bar-custom, .d-print-none { display: none !important; }
            .container-main-historial { box-shadow: none; border:none; border-radius: 0; padding: 0; max-width: 100%; margin-top:0;}
            .page-header { border-bottom: 1px solid #ccc; padding-bottom: 10px; margin-bottom: 15px; }
            .activo-info-card { background-color: #f0f0f0 !important; border-left: 3px solid #555 !important; }
            .collapse.show, .collapse { display: block !important; visibility: visible !important; height: auto !important; }
            .details-toggle { display: none !important; }
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

    <div class="container-main-historial">
        <div class="page-header d-flex justify-content-between align-items-center">
            <h3 class="mb-0"><i class="bi bi-journal-richtext"></i> Historial del Activo</h3>
        </div>

        <?php if (isset($error_historial_carga)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error_historial_carga) ?></div>
        <?php endif; ?>

        <div class="card activo-info-card mb-4">
            <div class="card-header">
                <strong>Información del Activo ID: <?= htmlspecialchars($activo_info['id']) ?></strong>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p class="mb-1"><strong>Tipo:</strong> <?= htmlspecialchars($activo_info['tipo_activo'] ?? 'N/A') ?></p>
                        <p class="mb-1"><strong>Marca:</strong> <?= htmlspecialchars($activo_info['marca'] ?? 'N/A') ?></p>
                        <p class="mb-0"><strong>Serie:</strong> <?= htmlspecialchars($activo_info['serie'] ?? 'N/A') ?></p>
                    </div>
                    <div class="col-md-6">
                        <p class="mb-1"><strong>Responsable Actual:</strong> <?= htmlspecialchars($activo_info['nombre_responsable'] ?? 'N/A') ?></p>
                        <p class="mb-1"><strong>C.C. Responsable:</strong> <?= htmlspecialchars($activo_info['cedula_responsable'] ?? 'N/A') ?></p>
                        <p class="mb-1"><strong>Regional Actual:</strong> <?= htmlspecialchars($activo_info['regional'] ?? 'N/A') ?></p>
                        <p class="mb-0"><strong>Empresa:</strong> <?= htmlspecialchars($activo_info['Empresa'] ?? ($activo_info['empresa'] ?? 'N/A')) ?></p> {/* Muestra la Empresa */}
                    </div>
                </div>
            </div>
        </div>

        <h5><i class="bi bi-list-ol"></i> Eventos Registrados</h5>
        <?php if (!empty($historial_items)): ?>
            <?php foreach ($historial_items as $idx => $item_hist): ?>
                <div class="card history-entry">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <span>
                                <i class="bi bi-calendar3"></i> <?= htmlspecialchars(date("d/m/Y H:i:s", strtotime($item_hist['fecha_evento']))) ?>
                                 - <span class="badge bg-info text-dark badge-custom"><?= htmlspecialchars($item_hist['tipo_evento']) ?></span>
                            </span>
                            <span class="history-meta">
                                <i class="bi bi-person-check-fill"></i> <?= htmlspecialchars($item_hist['usuario_responsable'] ?? 'N/A') ?>
                            </span>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="history-description">
                            <p class="mb-1"><?= nl2br(htmlspecialchars($item_hist['descripcion_evento'])) ?></p>
                            <?php
                            $datos_anteriores_hist = !empty($item_hist['datos_anteriores']) ? json_decode($item_hist['datos_anteriores'], true) : null;
                            $datos_nuevos_hist = !empty($item_hist['datos_nuevos']) ? json_decode($item_hist['datos_nuevos'], true) : null;
                            $has_details_to_show = false;

                            if (($item_hist['tipo_evento'] == HISTORIAL_TIPO_ACTUALIZACION && $datos_nuevos_hist && $datos_anteriores_hist) ||
                                ($item_hist['tipo_evento'] == HISTORIAL_TIPO_TRASLADO && ($datos_anteriores_hist || $datos_nuevos_hist)) ||
                                ($item_hist['tipo_evento'] == (defined('HISTORIAL_TIPO_CREACION') ? HISTORIAL_TIPO_CREACION : 'CREACIÓN') && $datos_nuevos_hist) ||
                                ($item_hist['tipo_evento'] == HISTORIAL_TIPO_BAJA && ($datos_anteriores_hist || $datos_nuevos_hist)) ) { // Se muestran detalles para BAJA también si hay datos nuevos (contexto)
                                $has_details_to_show = true;
                            }
                            ?>
                            <?php if ($has_details_to_show): ?>
                            <a class="details-toggle d-print-none" data-bs-toggle="collapse" href="#detailsCollapse_<?= $idx ?>" role="button" aria-expanded="false" aria-controls="detailsCollapse_<?= $idx ?>">
                                <i class="bi bi-caret-down-fill"></i> Ver Detalles del Evento
                            </a>
                            <div class="collapse mt-2" id="detailsCollapse_<?= $idx ?>">
                                <div class="details-content">
                                    <?php if ($datos_anteriores_hist && ($item_hist['tipo_evento'] == HISTORIAL_TIPO_ACTUALIZACION || $item_hist['tipo_evento'] == HISTORIAL_TIPO_TRASLADO || $item_hist['tipo_evento'] == HISTORIAL_TIPO_BAJA ) ): ?>
                                        <h6>Datos Anteriores:</h6>
                                        <ul>
                                        <?php foreach ($datos_anteriores_hist as $key => $val_anterior): 
                                            // Para BAJA, los datos_nuevos son el contexto de la baja, no campos del activo
                                            $val_nuevo_comparar = ($item_hist['tipo_evento'] != HISTORIAL_TIPO_BAJA && isset($datos_nuevos_hist[$key])) ? $datos_nuevos_hist[$key] : null;
                                            if ($item_hist['tipo_evento'] == HISTORIAL_TIPO_ACTUALIZACION && $val_anterior == $val_nuevo_comparar && array_key_exists($key, $datos_nuevos_hist)) continue; // No mostrar si no cambió en actualización
                                        ?>
                                            <li><strong><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $key))) ?>:</strong> <?= htmlspecialchars(is_array($val_anterior) ? json_encode($val_anterior) : ($val_anterior ?? 'N/A')) ?></li>
                                        <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                    <?php if ($datos_nuevos_hist && ($item_hist['tipo_evento'] == HISTORIAL_TIPO_ACTUALIZACION || $item_hist['tipo_evento'] == HISTORIAL_TIPO_TRASLADO || $item_hist['tipo_evento'] == (defined('HISTORIAL_TIPO_CREACION') ? HISTORIAL_TIPO_CREACION : 'CREACIÓN') || $item_hist['tipo_evento'] == HISTORIAL_TIPO_BAJA )): ?>
                                        <h6><?= ($item_hist['tipo_evento'] == HISTORIAL_TIPO_BAJA) ? 'Contexto de la Baja:' : (($item_hist['tipo_evento'] == (defined('HISTORIAL_TIPO_CREACION') ? HISTORIAL_TIPO_CREACION : 'CREACIÓN')) ? 'Datos Registrados:' : 'Datos Nuevos:') ?></h6>
                                        <ul>
                                        <?php foreach ($datos_nuevos_hist as $key => $val_nuevo): 
                                            $val_anterior_comparar = ($item_hist['tipo_evento'] != (defined('HISTORIAL_TIPO_CREACION') ? HISTORIAL_TIPO_CREACION : 'CREACIÓN') && isset($datos_anteriores_hist[$key])) ? $datos_anteriores_hist[$key] : null;
                                            if ($item_hist['tipo_evento'] == HISTORIAL_TIPO_ACTUALIZACION && $val_nuevo == $val_anterior_comparar && array_key_exists($key, $datos_anteriores_hist)) continue; // No mostrar si no cambió en actualización
                                        ?>
                                            <li><strong><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $key))) ?>:</strong> <?= htmlspecialchars(is_array($val_nuevo) ? json_encode($val_nuevo) : ($val_nuevo ?? 'N/A')) ?></li>
                                        <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="alert alert-secondary mt-3" role="alert">
                <i class="bi bi-info-circle-fill"></i>
            </div>
        <?php endif; ?>
        <div class="mt-4 text-center d-print-none">
             <button type="button" class="btn btn-success" onclick="window.print();"><i class="bi bi-printer-fill"></i> Imprimir Historial</button>
             <a href="buscar.php" class="btn btn-secondary"><i class="bi bi-arrow-left-circle"></i> Volver a Búsqueda</a>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>