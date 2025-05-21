<?php
session_start();
if (!isset($_SESSION['usuario'])) {
    header("Location: login.php");
    exit;
}

require_once 'backend/db.php';
require_once 'backend/historial_helper.php'; // Para las constantes HISTORIAL_TIPO_...

if (isset($conn) && !isset($conexion)) { $conexion = $conn; }
if (!isset($conexion) || !$conexion) {
    error_log("Fallo CRÍTICO de conexión a BD en historial.php.");
    die("Error de conexión a la base de datos.");
}

if (!isset($_GET['id_activo']) || !filter_var($_GET['id_activo'], FILTER_VALIDATE_INT) || $_GET['id_activo'] <= 0) {
    die("ID de activo no válido o no proporcionado.");
}
$id_activo_historial = (int)$_GET['id_activo'];

$activo_info = null;
$stmt_activo = $conexion->prepare("SELECT id, tipo_activo, serie, marca, nombre AS nombre_responsable, cedula AS cedula_responsable, regional FROM activos_tecnologicos WHERE id = ?");
if ($stmt_activo) {
    $stmt_activo->bind_param('i', $id_activo_historial);
    $stmt_activo->execute();
    $result_activo = $stmt_activo->get_result();
    $activo_info = $result_activo->fetch_assoc();
    $stmt_activo->close();
}

if (!$activo_info) {
    die("Activo con ID " . htmlspecialchars($id_activo_historial) . " no encontrado.");
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
        /* Estilos base de editar.php para consistencia */
        body { background: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .logo-container { text-align: center; margin-bottom: 5px; padding-top:10px; }
        .logo-container img { width: 180px; height: 70px; object-fit: contain; } /* Ajustado para consistencia */
        .navbar-custom { background-color: #191970; }
        .navbar-custom .nav-link { color: white !important; font-weight: 500; padding: 0.5rem 1rem;}
        .navbar-custom .nav-link:hover, .navbar-custom .nav-link.active { background-color: #8b0000; color: white; }
        
        /* Contenedor principal para el contenido del historial */
        .container-main-historial {
            max-width: 1000px; /* Ajusta según necesidad */
            margin: 20px auto; /* Centrado y con margen superior */
            background-color: #fff;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        /* Estilos específicos de la página de historial (del código anterior) */
        .page-header { border-bottom: 2px solid #007bff; padding-bottom: 15px; margin-bottom: 25px; }
        .page-header h3 { color: #007bff; }
        .activo-info-card { background-color: #e9ecef; border-left: 5px solid #007bff; }
        .history-entry { border: 1px solid #dee2e6; border-radius: 0.375rem; margin-bottom: 15px; }
        .history-entry .card-header { background-color: #f8f9fa; font-weight: bold; }
        .history-entry .card-body { font-size: 0.9rem; }
        .history-meta { font-size: 0.8em; color: #6c757d; }
        .history-description { margin-top: 8px; }
        .badge-custom { font-size: 0.9em; padding: 0.4em 0.7em; }
        .details-toggle { cursor: pointer; color: #0069d9; text-decoration: none; font-size: 0.85em; }
        .details-toggle:hover { text-decoration: underline; }
        .details-content { background-color: #fcfcfc; border: 1px solid #e0e0e0; padding: 12px; margin-top: 8px; border-radius: 5px; font-size: 0.85em; }
        .details-content ul { padding-left: 18px; margin-bottom: 0; list-style-type: disc; }
        .details-content strong { color: #333; }

        @media print {
            body { padding-top: 0; background-color: #fff; }
            .navbar-custom, .logo-container, .d-print-none { display: none !important; }
            .container-main-historial { box-shadow: none; border-radius: 0; padding: 10px; max-width: 100%; margin-top:0;}
            .page-header { border-bottom: 1px solid #ccc; padding-bottom: 10px; margin-bottom: 15px; }
            .activo-info-card { background-color: #f0f0f0 !important; border-left: 3px solid #555 !important; }
            .collapse.show, .collapse { display: block !important; visibility: visible !important; height: auto !important; }
            .details-toggle { display: none !important; }
        }
    </style>
</head>
<body>
    <div class="logo-container">
        <a href="menu.php"><img src="imagenes/logo3.png" alt="Logo" style="height: 70px; width: auto;"></a>
    </div>

    <nav class="navbar navbar-expand-lg navbar-custom">
        <div class="container-fluid">
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon" style="background-image: url(&quot;data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba(255,255,255,0.8)' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e&quot;);"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                   <li class="nav-item"><a class="nav-link" href="menu.php">Inicio</a></li>
                   <li class="nav-item"><a class="nav-link" href="index.html">Registrar Activo</a></li>                   
                   <li class="nav-item"><a class="nav-link" href="editar.php">Editar Activo</a></li>
                   <li class="nav-item"><a class="nav-link" href="buscar.php">Buscar Activo</a></li>
                   <li class="nav-item"><a class="nav-link" href="informes.php">Informe</a></li>
                   <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
  
                   </ul>
                 <form class="d-flex ms-auto" action="logout.php" method="post">
                    <button class="btn btn-outline-light" type="submit">Cerrar sesión</button>
                 </form>
            </div>
        </div>
    </nav>

    <div class="container-main-historial">
        <div class="page-header d-flex justify-content-between align-items-center">
            <h3 class="mb-0"><i class="bi bi-journal-richtext"></i> Historial del Activo</h3>
            <a href="menu.php" class="btn btn-outline-danger btn-sm d-print-none"><i class="bi bi-x-circle"></i> Cerrar Ventana</a>
        </div>

        <div class="card activo-info-card mb-4">
            <div class="card-header">
                <strong>Información del Activo </strong>
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
                        <p class="mb-0"><strong>Regional Actual:</strong> <?= htmlspecialchars($activo_info['regional'] ?? 'N/A') ?></p>
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

                            if ($item_hist['tipo_evento'] == HISTORIAL_TIPO_ACTUALIZACION && $datos_nuevos_hist && $datos_anteriores_hist) { $has_details_to_show = true; }
                            elseif ($item_hist['tipo_evento'] == HISTORIAL_TIPO_TRASLADO && ($datos_anteriores_hist || $datos_nuevos_hist)) { $has_details_to_show = true; }
                            elseif ($item_hist['tipo_evento'] == (defined('HISTORIAL_TIPO_CREACION') ? HISTORIAL_TIPO_CREACION : 'CREACIÓN') && $datos_nuevos_hist) { $has_details_to_show = true; }
                            elseif ($item_hist['tipo_evento'] == HISTORIAL_TIPO_BAJA && $datos_anteriores_hist) { $has_details_to_show = true; }
                            ?>
                            <?php if ($has_details_to_show): ?>
                            <a class="details-toggle d-print-none" data-bs-toggle="collapse" href="#detailsCollapse_<?= $idx ?>" role="button" aria-expanded="false" aria-controls="detailsCollapse_<?= $idx ?>">
                                <i class="bi bi-caret-down-fill"></i> Ver Detalles del Evento
                            </a>
                            <div class="collapse mt-2" id="detailsCollapse_<?= $idx ?>">
                                <div class="details-content">
                                    <?php if ($item_hist['tipo_evento'] == HISTORIAL_TIPO_ACTUALIZACION && $datos_nuevos_hist && $datos_anteriores_hist): ?>
                                        <h6>Cambios Específicos:</h6>
                                        <ul>
                                        <?php foreach ($datos_nuevos_hist as $key => $val_nuevo): ?>
                                            <?php
                                            $val_anterior_disp = '[CAMPO NO EXISTÍA ANTES O SIN VALOR PREVIO]';
                                            $campo_existia_antes_con_valor_diferente = false;
                                            if (array_key_exists($key, $datos_anteriores_hist)) {
                                                $val_anterior_disp = $datos_anteriores_hist[$key];
                                                if($val_anterior_disp != $val_nuevo) {
                                                    $campo_existia_antes_con_valor_diferente = true;
                                                }
                                            } elseif ($val_nuevo !== null && $val_nuevo !== '') { // Es un campo nuevo con valor
                                                $campo_existia_antes_con_valor_diferente = true;
                                            }

                                            if ($campo_existia_antes_con_valor_diferente) {
                                                echo "<li><strong>" . htmlspecialchars(ucfirst(str_replace('_', ' ', $key))) . ":</strong> '" . htmlspecialchars($val_anterior_disp) . "' &rarr; '" . htmlspecialchars($val_nuevo ?? '') . "'</li>";
                                            }
                                            ?>
                                        <?php endforeach; ?>
                                        </ul>
                                   <?php elseif ($item_hist['tipo_evento'] == HISTORIAL_TIPO_TRASLADO): ?>
                                       <?php if ($datos_anteriores_hist): ?>
                                           <h6>Datos Origen (Responsable y Activo Anterior):</h6>
                                           <ul>
                                               <li><strong>C.C. Resp.:</strong> <?= htmlspecialchars($datos_anteriores_hist['cedula_responsable'] ?? 'N/A') ?></li>
                                               <li><strong>Nombre Resp.:</strong> <?= htmlspecialchars($datos_anteriores_hist['nombre_responsable'] ?? 'N/A') ?></li>
                                               <li><strong>Cargo Resp.:</strong> <?= htmlspecialchars($datos_anteriores_hist['cargo_responsable'] ?? 'N/A') ?></li>
                                               <li><strong>Regional Activo (Anterior):</strong> <?= htmlspecialchars($datos_anteriores_hist['regional_activo_anterior'] ?? 'N/A') ?></li>
                                           </ul>
                                       <?php endif; ?>
                                       <?php if ($datos_nuevos_hist): ?>
                                           <h6>Datos Destino (Responsable y Activo Nuevo):</h6>
                                           <ul>
                                               <li><strong>C.C. Resp.:</strong> <?= htmlspecialchars($datos_nuevos_hist['cedula'] ?? 'N/A') ?></li>
                                               <li><strong>Nombre Resp.:</strong> <?= htmlspecialchars($datos_nuevos_hist['nombre'] ?? 'N/A') ?></li>
                                               <li><strong>Cargo Resp.:</strong> <?= htmlspecialchars($datos_nuevos_hist['cargo'] ?? 'N/A') ?></li>
                                               <li><strong>Regional Activo (Nueva):</strong> <?= htmlspecialchars($datos_nuevos_hist['regional_activo_nueva'] ?? 'N/A') ?></li>
                                           </ul>
                                       <?php endif; ?>
                                   <?php elseif ($item_hist['tipo_evento'] == (defined('HISTORIAL_TIPO_CREACION') ? HISTORIAL_TIPO_CREACION : 'CREACIÓN') && $datos_nuevos_hist):
                                        $campos_excluir_creacion = ['cedula_original_busqueda', 'regional_original_busqueda', 'action', 'submit_button_name', 'id', 'nombre_usuario_display', 'cargo_usuario_display', 'regional_activo_form'];
                                        ?>
                                        <h6>Datos Registrados en Creación:</h6>
                                        <ul>
                                        <?php foreach ($datos_nuevos_hist as $key_crea => $val_crea): ?>
                                            <?php if (!in_array($key_crea, $campos_excluir_creacion) && ($val_crea !== null && $val_crea !== '')): ?>
                                            <li><strong><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $key_crea))) ?>:</strong> '<?= htmlspecialchars(is_array($val_crea) ? json_encode($val_crea) : $val_crea) ?>'</li>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                        </ul>
                                   <?php elseif ($item_hist['tipo_evento'] == HISTORIAL_TIPO_BAJA && $datos_anteriores_hist): ?>
                                       <h6>Datos del Activo al dar de Baja:</h6>
                                       <ul>
                                       <?php foreach ($datos_anteriores_hist as $key_baja => $val_baja): ?>
                                            <?php if ($key_baja != 'id'): ?>
                                           <li><strong><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $key_baja))) ?>:</strong> '<?= htmlspecialchars(is_array($val_baja) ? json_encode($val_baja) : $val_baja) ?>'</li>
                                           <?php endif; ?>
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
                <i class="bi bi-info-circle-fill"></i> No hay eventos de historial registrados para este activo.
            </div>
        <?php endif; ?>
        <div class="mt-4 text-center d-print-none">
             <button type="button" class="btn btn-success" onclick="window.print();"><i class="bi bi-printer-fill"></i> Imprimir Historial</button>
             <button type="button" class="btn btn-secondary" onclick="window.close();"><i class="bi bi-x-lg"></i> Cerrar</button>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>