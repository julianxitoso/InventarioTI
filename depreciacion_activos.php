<?php
// Descomentar para depuración si es necesario, pero asegurarse que estén comentadas en producción
// ini_set('display_errors', 1); 
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

session_start();
require_once 'backend/auth_check.php';
restringir_acceso_pagina(['admin', 'auditor', 'registrador', 'tecnico']);

require_once 'backend/db.php';
if (isset($conn) && !isset($conexion)) { $conexion = $conn; }
if (!isset($conexion) || !$conexion || (method_exists($conexion, 'connect_error') && $conexion->connect_error)) {
    error_log("Fallo CRÍTICO de conexión a BD (depreciacion_activos.php): " . ($conexion->connect_error ?? 'Desconocido'));
    $error_conexion_db = "Error crítico de conexión a la base de datos. Funcionalidad limitada.";
} else {
    $conexion->set_charset("utf8mb4");
    $error_conexion_db = null;
}

define('VALOR_UVT_2025', 49799);
define('UMBRAL_UVT_DEPRECIACION', 50);

$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Usuario';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'Desconocido';

$activo_info_display = null;
$depreciacion_info = null;
$error_busqueda = null;
$criterio_busqueda_val = '';
$tipo_criterio_val = 'serie'; 
$activos_del_responsable_lista = [];
$nombre_responsable_buscado = '';

function get_asset_details_by_id($id, $conn) {
    if (!$conn || (method_exists($conn, 'connect_error') && $conn->connect_error)) return null;
    $sql = "SELECT 
                a.id, a.serie, a.marca, a.estado, a.valor_aproximado, a.valor_residual, 
                a.fecha_compra, a.metodo_depreciacion, a.detalles, 
                u.nombre_completo AS nombre_responsable,
                u.usuario AS cedula_responsable,
                c.nombre_cargo AS cargo_responsable,
                ta.nombre_tipo_activo AS nombre_tipo_activo,
                ta.vida_util_sugerida AS vida_util_anios
            FROM 
                activos_tecnologicos a
            LEFT JOIN 
                usuarios u ON a.id_usuario_responsable = u.id
            LEFT JOIN 
                cargos c ON u.id_cargo = c.id_cargo
            LEFT JOIN
                tipos_activo ta ON a.id_tipo_activo = ta.id_tipo_activo
            WHERE 
                a.id = ?";
    
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $data = $result->fetch_assoc();
                $stmt->close();
                return $data;
            }
        } else {
            error_log("Error al ejecutar consulta en get_asset_details_by_id: " . $stmt->error);
        }
        $stmt->close();
    } else {
        error_log("Error al preparar consulta en get_asset_details_by_id: " . $conn->error);
    }
    return null;
}

if (!$error_conexion_db) { 
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['accion']) && $_GET['accion'] === 'ver_depreciacion' && isset($_GET['id_activo_dep'])) {
        $id_activo_seleccionado = (int)$_GET['id_activo_dep'];
        $tipo_criterio_val = $_GET['tipo_criterio_original'] ?? 'serie'; 
        $criterio_busqueda_val = $_GET['criterio_original'] ?? '';     

        $activo_info_display = get_asset_details_by_id($id_activo_seleccionado, $conexion);
        if (!$activo_info_display) {
            $error_busqueda = "No se pudo encontrar el activo seleccionado con ID: " . htmlspecialchars($id_activo_seleccionado);
            error_log("Error en GET ver_depreciacion: Activo no encontrado con ID: " . $id_activo_seleccionado);
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buscar_activo_dep'])) {
        $criterio_busqueda_val = trim($_POST['criterio_busqueda']);
        $tipo_criterio_val = $_POST['tipo_criterio']; 
        error_log("[DEPRECIACION_BUSQUEDA] POST recibido. Tipo: {$tipo_criterio_val}, Criterio: {$criterio_busqueda_val}");

        if (empty($criterio_busqueda_val)) {
            $error_busqueda = "Por favor, ingrese un criterio de búsqueda.";
        } else {
            $stmt = null;
            $sql = ""; 

            if ($tipo_criterio_val === 'serie') {
                $sql = "SELECT 
                            a.id, a.serie, a.marca, a.estado, a.valor_aproximado, a.valor_residual, 
                            a.fecha_compra, a.metodo_depreciacion, a.detalles, 
                            u.nombre_completo AS nombre_responsable, u.usuario AS cedula_responsable,
                            c.nombre_cargo AS cargo_responsable, ta.nombre_tipo_activo AS nombre_tipo_activo,
                            ta.vida_util_sugerida AS vida_util_anios
                        FROM activos_tecnologicos a
                        LEFT JOIN usuarios u ON a.id_usuario_responsable = u.id
                        LEFT JOIN cargos c ON u.id_cargo = c.id_cargo
                        LEFT JOIN tipos_activo ta ON a.id_tipo_activo = ta.id_tipo_activo
                        WHERE a.serie = ?";
                $stmt = $conexion->prepare($sql);
                if($stmt) { $stmt->bind_param("s", $criterio_busqueda_val); } 
                else { error_log("[DEPRECIACION_BUSQUEDA] Error al preparar SQL por SERIE: " . $conexion->error); }
            } elseif ($tipo_criterio_val === 'cedula') {
                $sql = "SELECT 
                            a.id, ta.nombre_tipo_activo AS nombre_tipo_activo, a.marca, a.serie, a.estado, 
                            u.nombre_completo AS nombre_responsable, u.usuario AS cedula_responsable,
                            c.nombre_cargo AS cargo_responsable 
                        FROM activos_tecnologicos a 
                        LEFT JOIN usuarios u ON a.id_usuario_responsable = u.id 
                        LEFT JOIN tipos_activo ta ON a.id_tipo_activo = ta.id_tipo_activo
                        LEFT JOIN cargos c ON u.id_cargo = c.id_cargo
                        WHERE u.usuario = ? AND a.estado != 'Dado de Baja'
                        ORDER BY ta.nombre_tipo_activo, a.id DESC"; 
                $stmt = $conexion->prepare($sql);
                if($stmt) { $stmt->bind_param("s", $criterio_busqueda_val); } 
                else { error_log("[DEPRECIACION_BUSQUEDA] Error al preparar SQL por CEDULA: " . $conexion->error); }
            } else { $error_busqueda = "Tipo de criterio no válido."; }

            if ($stmt) {
                if(!$stmt->execute()){
                    error_log("[DEPRECIACION_BUSQUEDA] Error al ejecutar la consulta (Tipo: {$tipo_criterio_val}): " . $stmt->error);
                    $error_busqueda = "Error al realizar la búsqueda (ejecución).";
                } else {
                    $result = $stmt->get_result();
                    error_log("[DEPRECIACION_BUSQUEDA] Consulta ejecutada. Filas encontradas: " . $result->num_rows);
                    if ($result->num_rows > 0) {
                        if ($tipo_criterio_val === 'serie') {
                            $activo_info_display = $result->fetch_assoc();
                            error_log("[DEPRECIACION_BUSQUEDA] Activo encontrado por serie: ID " . ($activo_info_display['id'] ?? 'N/A'));
                        } elseif ($tipo_criterio_val === 'cedula') {
                            while ($row = $result->fetch_assoc()) { $activos_del_responsable_lista[] = $row; }
                            if (!empty($activos_del_responsable_lista)) {
                                $nombre_responsable_buscado = $activos_del_responsable_lista[0]['nombre_responsable'] ?? 'Responsable Desconocido';
                                error_log("[DEPRECIACION_BUSQUEDA] Activos por cédula para: " . $nombre_responsable_buscado . ". Cantidad: " . count($activos_del_responsable_lista));
                            } else { $error_busqueda = "No se encontraron activos para la cédula: '" . htmlspecialchars($criterio_busqueda_val) . "'."; }
                        }
                    } else { $error_busqueda = "No se encontró ningún activo o responsable con el criterio: '" . htmlspecialchars($criterio_busqueda_val) . "'. Verifique los datos."; }
                }
                $stmt->close();
            } elseif (!$error_busqueda) { $error_busqueda = "Error al preparar la búsqueda. Contacte al administrador."; }
        }
    }

    if ($activo_info_display) {
        if (
            isset($activo_info_display['fecha_compra']) && $activo_info_display['fecha_compra'] &&
            isset($activo_info_display['valor_aproximado']) && is_numeric($activo_info_display['valor_aproximado']) && $activo_info_display['valor_aproximado'] > 0
        ) {
            $valor_compra_activo = (float)$activo_info_display['valor_aproximado'];
            $valor_activo_en_uvt = VALOR_UVT_2025 > 0 ? ($valor_compra_activo / VALOR_UVT_2025) : 0;

            if ($valor_activo_en_uvt >= UMBRAL_UVT_DEPRECIACION) {
                if (isset($activo_info_display['vida_util_anios']) && (int)$activo_info_display['vida_util_anios'] > 0) {
                    $fecha_inicio = new DateTime($activo_info_display['fecha_compra']);
                    $activo_info_display['fecha_inicio_depreciacion'] = $activo_info_display['fecha_compra'];
                    $fecha_actual = new DateTime();
                    $meses_transcurridos = 0;
                    if ($fecha_actual >= $fecha_inicio) {
                        $intervalo = $fecha_inicio->diff($fecha_actual); 
                        $meses_transcurridos = ($intervalo->y * 12) + $intervalo->m;
                    }
                    $valor_residual = isset($activo_info_display['valor_residual']) ? (float)$activo_info_display['valor_residual'] : 0.0;
                    $vida_util_anios_val = (int)$activo_info_display['vida_util_anios'];
                    $vida_util_meses = $vida_util_anios_val * 12;
                    $valor_depreciable = max(0, $valor_compra_activo - $valor_residual);
                    $depreciacion_info = [
                        'dep_anual' => 0, 'dep_mensual' => 0, 'dep_acumulada' => 0, 
                        'valor_en_libros' => $valor_compra_activo, 'meses_transcurridos' => $meses_transcurridos, 
                        'vida_util_meses' => $vida_util_meses, 'meses_restantes' => $vida_util_meses, 
                        'estado_depreciacion' => ($fecha_actual < $fecha_inicio) ? 'No iniciada' : 'Calculando...', 
                        'aplica_depreciacion_uvt' => true, 
                        'valor_activo_en_uvt' => $valor_activo_en_uvt
                    ];
                    if ($vida_util_meses > 0 && $valor_depreciable > 0) {
                        $depreciacion_info['dep_mensual'] = $valor_depreciable / $vida_util_meses;
                        $depreciacion_info['dep_anual'] = $depreciacion_info['dep_mensual'] * 12;
                        if ($meses_transcurridos > 0) {
                            $meses_a_depreciar = min($meses_transcurridos, $vida_util_meses);
                            $depreciacion_info['dep_acumulada'] = $depreciacion_info['dep_mensual'] * $meses_a_depreciar;
                            if ($depreciacion_info['dep_acumulada'] > $valor_depreciable) { $depreciacion_info['dep_acumulada'] = $valor_depreciable; }
                            $depreciacion_info['valor_en_libros'] = $valor_compra_activo - $depreciacion_info['dep_acumulada'];
                            if ($depreciacion_info['valor_en_libros'] < $valor_residual) { $depreciacion_info['valor_en_libros'] = $valor_residual; }
                        } else { 
                            $depreciacion_info['dep_acumulada'] = 0; 
                            $depreciacion_info['valor_en_libros'] = $valor_compra_activo; 
                        }
                    }
                    $depreciacion_info['meses_restantes'] = max(0, $vida_util_meses - $meses_transcurridos);
                    if ($fecha_actual < $fecha_inicio) { $depreciacion_info['estado_depreciacion'] = 'No iniciada'; } 
                    elseif ($depreciacion_info['valor_en_libros'] <= $valor_residual || $meses_transcurridos >= $vida_util_meses ) {
                        if ($vida_util_meses > 0) { $depreciacion_info['dep_acumulada'] = $valor_depreciable; $depreciacion_info['valor_en_libros'] = $valor_residual;}
                        $depreciacion_info['estado_depreciacion'] = 'Totalmente Depreciado'; $depreciacion_info['meses_restantes'] = 0;
                    } else { $depreciacion_info['estado_depreciacion'] = 'En Curso'; }
                } else { 
                    $depreciacion_info = null; 
                    if (!$error_busqueda) $error_busqueda = "El tipo de activo '".htmlspecialchars($activo_info_display['nombre_tipo_activo'] ?? '')."' no tiene una vida útil asignada para calcular la depreciación.";
                }
            } else { 
                $depreciacion_info = [
                    'aplica_depreciacion_uvt' => false,
                    'mensaje_no_aplica' => "Activo no aplica para Depreciar (Valor: " . number_format($valor_activo_en_uvt, 2, ',', '.') . " UVT < " . UMBRAL_UVT_DEPRECIACION . " UVT).",
                    'valor_en_libros' => $valor_compra_activo, 
                    'estado_depreciacion' => 'No Aplica (UVT)',
                    'valor_activo_en_uvt' => $valor_activo_en_uvt
                ];
            }
        } else { 
            if (!$error_busqueda) {
                $error_busqueda = "El activo seleccionado (ID: " . htmlspecialchars($activo_info_display['id'] ?? '') . ", Serie: " . htmlspecialchars($activo_info_display['serie'] ?? 'N/A') . ") no tiene datos suficientes para calcular la depreciación (valor o fecha de compra).";
            }
            $depreciacion_info = null;
        }
    }
} 
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Consulta de Depreciación de Activos</title>
    <link rel="icon" type="image/x-icon" href="imagenes/icono.ico">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding-top: 110px; background-color: #eef2f5; font-size: 0.92rem; display: flex; flex-direction: column; min-height: 100vh; }
        .top-bar-custom { position: fixed; top: 0; left: 0; right: 0; z-index: 1030; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; padding: 0.5rem 1.5rem; background-color: #ffffff; border-bottom: 1px solid #dee2e6; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .logo-container-top img { height: 75px; width: auto; }
        .user-info-top-container .user-info-top { font-size: 0.8rem; } 
        .user-info-top-container .btn { font-size: 0.8rem; } 
        .page-header-custom-area { margin-bottom: 1.5rem; }
        h1.page-title, h3.page-title { color: #0d6efd; font-weight: 600; font-size: 1.75rem; text-align: center; }
        .container-main-content { background-color: #ffffff; padding: 25px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.075); flex-grow: 1; }
        .card-depreciacion { border-left: 4px solid #0d6efd; }
        .table th { background-color: #e9ecef; font-weight: 600;}
        .table td { font-size: 0.95em; }
        .table-hover tbody tr:hover { background-color: #f1f1f1; }
        @media (max-width: 575.98px) {
            body { padding-top: 150px; } 
            .top-bar-custom { flex-direction: column; padding: 0.75rem 1rem; }
            .logo-container-top { margin-bottom: 0.5rem; text-align: center; width: 100%; }
            .user-info-top-container { display: flex; flex-direction: column; align-items: center; width: 100%; text-align: center; }
            .user-info-top-container .user-info-top { margin-right: 0; margin-bottom: 0.5rem; }
            h1.page-title, h3.page-title { font-size: 1.4rem !important; } 
            .page-header-custom-area .text-sm-end { text-align: center !important; } 
            .container-main-content { padding: 15px; }
            .card-body .row.g-3 { flex-direction: column; } 
            .card-body .row.g-3 .col-md-2 button { margin-top: 0.5rem; }
        }
    </style>
</head>
<body>
<div class="top-bar-custom">
    <div class="logo-container-top"><a href="menu.php" title="Ir a Inicio"><img src="imagenes/logo.png" alt="Logo"></a></div>
    <div class="user-info-top-container d-flex align-items-center">
        <span class="user-info-top text-dark me-3"><i class="bi bi-person-circle"></i> <?= htmlspecialchars($nombre_usuario_actual_sesion) ?> (<?= htmlspecialchars(ucfirst($rol_usuario_actual_sesion)) ?>)</span>
        <form action="logout.php" method="post" class="d-flex"><button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</button></form>
    </div>
</div>

<div class="container mt-4"> 
    <div class="page-header-custom-area"> 
        <h3 class="page-title text-center mb-3"> 
            <i class="bi bi-calculator-fill"></i> Consulta de Depreciación de Activos
        </h3>
        <div class="text-center text-sm-end">
             <a href="menu.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left-circle"></i> Volver al Menú
            </a>
        </div>
    </div>

    <main class="container-main-content"> 
        <?php if ($error_conexion_db): ?>
            <div class="alert alert-danger"><?= $error_conexion_db ?></div>
        <?php else: ?>
            <div class="card mb-4">
                <div class="card-body">
                    <form method="POST" action="depreciacion_activos.php">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-4">
                                <label for="tipo_criterio" class="form-label">Buscar por:</label>
                                <select class="form-select form-select-sm" id="tipo_criterio" name="tipo_criterio">
                                    <option value="serie" <?= ($tipo_criterio_val === 'serie') ? 'selected' : '' ?>>Número de Serie</option>
                                    <option value="cedula" <?= ($tipo_criterio_val === 'cedula') ? 'selected' : '' ?>>Cédula del Responsable</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="criterio_busqueda" class="form-label">Criterio:</label>
                                <input type="text" class="form-control form-control-sm" id="criterio_busqueda" name="criterio_busqueda" value="<?= htmlspecialchars($criterio_busqueda_val) ?>" required>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" name="buscar_activo_dep" class="btn btn-primary btn-sm w-100"><i class="bi bi-search"></i> Consultar</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <?php if ($error_busqueda): ?>
                <div class="alert alert-warning"><?= $error_busqueda ?></div>
            <?php endif; ?>

            <?php if (!empty($activos_del_responsable_lista)): ?>
                <div class="card mb-4">
                    <div class="card-header fw-bold"><i class="bi bi-list-ul"></i> Activos Encontrados para <?= htmlspecialchars($nombre_responsable_buscado) ?> (C.C: <?= htmlspecialchars($criterio_busqueda_val) ?>)</div>
                    <div class="card-body table-responsive">
                        <p class="text-muted small">Seleccione un activo de la lista para ver sus detalles de depreciación.</p>
                        <table class="table table-hover table-sm">
                            <thead><tr><th>ID</th><th>Tipo</th><th>Marca</th><th>Serie</th><th>Estado</th><th>Acción</th></tr></thead>
                            <tbody>
                                <?php foreach ($activos_del_responsable_lista as $activo_item): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($activo_item['id'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($activo_item['nombre_tipo_activo'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($activo_item['marca'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($activo_item['serie'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($activo_item['estado'] ?? 'N/A') ?></td>
                                        <td>
                                            <a href="depreciacion_activos.php?accion=ver_depreciacion&id_activo_dep=<?= $activo_item['id'] ?>&tipo_criterio_original=<?=urlencode($tipo_criterio_val)?>&criterio_original=<?=urlencode($criterio_busqueda_val)?>" class="btn btn-outline-primary btn-sm">
                                                <i class="bi bi-eye-fill"></i> Ver
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($activo_info_display): ?>
            <div class="row">
                <div class="col-lg-6 mb-4">
                    <div class="card h-100">
                        <div class="card-header fw-bold"><i class="bi bi-info-circle-fill"></i> Información del Activo</div>
                        <div class="card-body">
                            <p><strong>ID Activo:</strong> <?= htmlspecialchars($activo_info_display['id'] ?? 'N/A') ?></p>
                            <p><strong>Tipo:</strong> <?= htmlspecialchars($activo_info_display['nombre_tipo_activo'] ?? 'N/A') ?></p>
                            <p><strong>Marca:</strong> <?= htmlspecialchars($activo_info_display['marca'] ?? 'N/A') ?></p>
                            <p><strong>Serie:</strong> <?= htmlspecialchars($activo_info_display['serie'] ?? 'N/A') ?></p>
                            <p><strong>Responsable:</strong> <?= htmlspecialchars($activo_info_display['nombre_responsable'] ?? 'N/A') ?> (C.C: <?= htmlspecialchars($activo_info_display['cedula_responsable'] ?? 'N/A') ?>)</p>
                            <p><strong>Cargo Resp.:</strong> <?= htmlspecialchars($activo_info_display['cargo_responsable'] ?? 'N/A') ?></p>
                            <hr>
                            <p><strong>Fecha Compra:</strong> <?= $activo_info_display['fecha_compra'] ? htmlspecialchars(date("d/m/Y", strtotime($activo_info_display['fecha_compra']))) : 'N/A' ?></p>
                            <p><strong>Valor Compra:</strong> $<?= htmlspecialchars(number_format(floatval($activo_info_display['valor_aproximado'] ?? 0), 0, ',', '.')) ?></p>
                            <p><strong>Valor en UVT (Compra):</strong> <?= isset($depreciacion_info['valor_activo_en_uvt']) ? number_format($depreciacion_info['valor_activo_en_uvt'], 2, ',', '.') . ' UVT' : (isset($activo_info_display['valor_aproximado']) && VALOR_UVT_2025 > 0 ? number_format(floatval($activo_info_display['valor_aproximado'])/VALOR_UVT_2025, 2, ',', '.') . ' UVT' : 'N/A') ?></p>
                            <p><strong>Valor Residual:</strong> $<?= htmlspecialchars(number_format(floatval($activo_info_display['valor_residual'] ?? 0), 0, ',', '.')) ?></p>
                            <p><strong>Vida Útil (Tipo Activo):</strong> <?= htmlspecialchars($activo_info_display['vida_util_anios'] ?? 'N/A') ?> años</p>
                            <p><strong>Método Depreciación (Activo):</strong> <?= htmlspecialchars($activo_info_display['metodo_depreciacion'] ?? 'Línea Recta') ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6 mb-4">
                    <div class="card card-depreciacion h-100">
                        <div class="card-header fw-bold"><i class="bi bi-graph-down"></i> Cálculo de Depreciación (a hoy: <?= date("d/m/Y") ?>)</div>
                        <div class="card-body">
                            <?php if ($depreciacion_info && isset($depreciacion_info['aplica_depreciacion_uvt']) && $depreciacion_info['aplica_depreciacion_uvt']): ?>
                                <p><strong>Meses Transcurridos:</strong> <?= htmlspecialchars($depreciacion_info['meses_transcurridos']) ?> de <?= htmlspecialchars($depreciacion_info['vida_util_meses']) ?></p>
                                <p><strong>Meses Restantes:</strong> <?= htmlspecialchars($depreciacion_info['meses_restantes']) ?></p>
                                <table class="table table-sm table-bordered">
                                    <tbody>
                                        <tr><th>Depreciación Anual Estimada:</th><td>$<?= htmlspecialchars(number_format($depreciacion_info['dep_anual'], 0, ',', '.')) ?></td></tr>
                                        <tr><th>Depreciación Mensual Estimada:</th><td>$<?= htmlspecialchars(number_format($depreciacion_info['dep_mensual'], 0, ',', '.')) ?></td></tr>
                                        <tr><th class="text-danger">Depreciación Acumulada:</th><td class="text-danger fw-bold">$<?= htmlspecialchars(number_format($depreciacion_info['dep_acumulada'], 0, ',', '.')) ?></td></tr>
                                        <tr><th class="text-success">Valor en Libros Actual:</th><td class="text-success fw-bold">$<?= htmlspecialchars(number_format($depreciacion_info['valor_en_libros'], 0, ',', '.')) ?></td></tr>
                                    </tbody>
                                </table>
                                <p class="mt-3 text-center"><strong>Estado de Depreciación:</strong> 
                                    <span class="badge fs-6 <?= ($depreciacion_info['estado_depreciacion'] === 'Totalmente Depreciado') ? 'bg-secondary' : (($depreciacion_info['estado_depreciacion'] === 'En Curso') ? 'bg-primary' : (($depreciacion_info['estado_depreciacion'] === 'No iniciada') ? 'bg-info text-dark' : 'bg-light text-dark')) ?>">
                                        <?= htmlspecialchars($depreciacion_info['estado_depreciacion']) ?>
                                    </span>
                                </p>
                            <?php elseif ($depreciacion_info && isset($depreciacion_info['aplica_depreciacion_uvt']) && !$depreciacion_info['aplica_depreciacion_uvt']): ?>
                                <div class="alert alert-warning text-center" role="alert">
                                    <i class="bi bi-exclamation-triangle-fill me-2"></i> 
                                    <?= htmlspecialchars($depreciacion_info['mensaje_no_aplica']) ?>
                                </div>
                                 <p><strong>Valor en Libros Actual:</strong> $<?= htmlspecialchars(number_format(floatval($activo_info_display['valor_aproximado'] ?? 0), 0, ',', '.')) ?></p>
                            <?php elseif ($error_busqueda && (strpos($error_busqueda, "datos suficientes") !== false || strpos($error_busqueda, "vida útil asignada") !== false) ): ?>
                                 <div class="alert alert-warning text-center" role="alert">
                                    <i class="bi bi-exclamation-triangle-fill me-2"></i> 
                                    <?= htmlspecialchars($error_busqueda) ?>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-secondary text-center" role="alert">
                                    No hay información de depreciación disponible o el activo no tiene los datos necesarios para el cálculo.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; // Fin de if (!$error_conexion_db) ?>
    </main> 
</div> 

<footer class="footer-custom mt-auto py-3 bg-light border-top shadow-sm">
        <div class="container text-center">
            <div class="row align-items-center">
                <div class="col-md-6 text-md-start mb-2 mb-md-0">
                    <small class="text-muted">Sitio web desarrollado por <a href="https://www.julianxitoso.com" target="_blank" rel="noopener noreferrer" class="text-decoration-none text-primary">@julianxitoso.com</a></small>
                </div>
                <div class="col-md-6 text-md-end">
                    <a href="https://facebook.com/tu_pagina" target="_blank" class="text-muted me-3" title="Facebook">
                        <i class="bi bi-facebook" style="font-size: 1.5rem;"></i>
                    </a>
                    <a href="https://instagram.com/tu_usuario" target="_blank" class="text-muted me-3" title="Instagram">
                        <i class="bi bi-instagram" style="font-size: 1.5rem;"></i>
                    </a>
                    <a href="https://tiktok.com/@tu_usuario" target="_blank" class="text-muted" title="TikTok">
                        <i class="bi bi-tiktok" style="font-size: 1.5rem;"></i>
                    </a>
                </div>
            </div>
        </div>
    </footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
