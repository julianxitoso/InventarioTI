<?php
require_once 'backend/auth_check.php';
restringir_acceso_pagina(['admin', 'auditor', 'registrador', 'tecnico']);

require_once 'backend/db.php';
if (isset($conn) && !isset($conexion)) { $conexion = $conn; }
if (!isset($conexion) || !$conexion) { die("Error de conexión a la base de datos."); }
$conexion->set_charset("utf8mb4");

$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Usuario';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'Desconocido';

$activo_info_display = null;
$depreciacion_info = null;
$error_busqueda = null;
$criterio_busqueda_val = '';
$tipo_criterio_val = '';
$activos_del_responsable_lista = []; // --- CAMBIO/NUEVO --- Array para la lista de activos por cédula
$nombre_responsable_buscado = ''; // --- CAMBIO/NUEVO --- Para mostrar el nombre del responsable buscado

// --- CAMBIO/NUEVO --- Manejar la selección de un activo específico de la lista
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['accion']) && $_GET['accion'] === 'ver_depreciacion' && isset($_GET['id_activo_dep'])) {
    $id_activo_seleccionado = (int)$_GET['id_activo_dep'];
    $tipo_criterio_val = $_GET['tipo_criterio_original'] ?? 'serie'; // Mantener el tipo de criterio
    $criterio_busqueda_val = $_GET['criterio_original'] ?? '';     // Mantener el criterio original

    $sql_seleccionado = "SELECT a.*, u.nombre_completo AS nombre_usuario_sistema 
                         FROM activos_tecnologicos a 
                         LEFT JOIN usuarios u ON a.cedula = u.usuario 
                         WHERE a.id = ?";
    $stmt_seleccionado = $conexion->prepare($sql_seleccionado);
    if ($stmt_seleccionado) {
        $stmt_seleccionado->bind_param("i", $id_activo_seleccionado);
        $stmt_seleccionado->execute();
        $result_seleccionado = $stmt_seleccionado->get_result();
        if ($result_seleccionado->num_rows > 0) {
            $activo_data_raw = $result_seleccionado->fetch_assoc();
            $activo_info_display = $activo_data_raw;
            // Recalcular depreciación para este activo específico
            // (El código de cálculo de depreciación se moverá a una función para reutilizarlo)
        } else {
            $error_busqueda = "No se pudo encontrar el activo seleccionado con ID: " . $id_activo_seleccionado;
        }
        $stmt_seleccionado->close();
    } else {
        $error_busqueda = "Error al preparar la consulta para el activo seleccionado: " . $conexion->error;
    }
    // Si se seleccionó un activo, y se cargó, proceder a calcular depreciación (ver más abajo)
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buscar_activo_dep'])) {
    $criterio_busqueda_val = trim($_POST['criterio_busqueda']);
    $tipo_criterio_val = $_POST['tipo_criterio']; 

    if (empty($criterio_busqueda_val)) {
        $error_busqueda = "Por favor, ingrese un criterio de búsqueda.";
    } else {
        $stmt = null;
        if ($tipo_criterio_val === 'serie') {
            $sql = "SELECT a.*, u.nombre_completo AS nombre_usuario_sistema 
                    FROM activos_tecnologicos a 
                    LEFT JOIN usuarios u ON a.cedula = u.usuario 
                    WHERE a.serie = ?";
            $stmt = $conexion->prepare($sql);
            if($stmt) $stmt->bind_param("s", $criterio_busqueda_val);
        } elseif ($tipo_criterio_val === 'cedula') {
            // --- CAMBIO/NUEVO --- Ahora esta consulta SÍ traerá todos los activos de esa cédula
            $sql = "SELECT a.id, a.tipo_activo, a.marca, a.serie, a.estado, u.nombre_completo AS nombre_usuario_sistema, a.nombre AS nombre_responsable_activo
                    FROM activos_tecnologicos a 
                    LEFT JOIN usuarios u ON a.cedula = u.usuario 
                    WHERE a.cedula = ? ORDER BY a.tipo_activo, a.id DESC"; 
            $stmt = $conexion->prepare($sql);
            if($stmt) $stmt->bind_param("s", $criterio_busqueda_val);
        } else {
            $error_busqueda = "Tipo de criterio no válido.";
        }

        if ($stmt) {
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                if ($tipo_criterio_val === 'serie') {
                    $activo_data_raw = $result->fetch_assoc(); 
                    $activo_info_display = $activo_data_raw;
                    // El cálculo de depreciación se hará después de este bloque if/else
                } elseif ($tipo_criterio_val === 'cedula') {
                    // --- CAMBIO/NUEVO --- Llenar el array con todos los activos del responsable
                    while ($row = $result->fetch_assoc()) {
                        $activos_del_responsable_lista[] = $row;
                    }
                    if (!empty($activos_del_responsable_lista)) {
                        // Tomar el nombre del responsable del primer activo encontrado para mostrarlo
                        $nombre_responsable_buscado = $activos_del_responsable_lista[0]['nombre_responsable_activo'] ?? ($activos_del_responsable_lista[0]['nombre_usuario_sistema'] ?? 'Desconocido');
                    } else {
                         $error_busqueda = "No se encontraron activos para la cédula: '" . htmlspecialchars($criterio_busqueda_val) . "'.";
                    }
                }
            } else {
                $error_busqueda = "No se encontró ningún activo/responsable con el criterio: '" . htmlspecialchars($criterio_busqueda_val) . "'.";
            }
            $stmt->close();
        }  elseif (!$error_busqueda) { // Si $stmt no se pudo preparar
             $error_busqueda = "Error al procesar la búsqueda: " . $conexion->error;
        }
    }
}

// --- CAMBIO/NUEVO --- Lógica de cálculo de depreciación (puede ser una función)
// Se ejecuta si $activo_info_display tiene datos (ya sea por búsqueda directa por serie o por selección de la lista)
if ($activo_info_display) {
    if ($activo_info_display['fecha_inicio_depreciacion'] && $activo_info_display['valor_aproximado'] > 0 && $activo_info_display['vida_util_anios'] > 0) {
        $fecha_inicio = new DateTime($activo_info_display['fecha_inicio_depreciacion']);
        $fecha_actual = new DateTime();
        if ($fecha_actual < $fecha_inicio) { $meses_transcurridos = 0; } 
        else { $intervalo = $fecha_inicio->diff($fecha_actual); $meses_transcurridos = ($intervalo->y * 12) + $intervalo->m; }

        $valor_compra = (float)$activo_info_display['valor_aproximado'];
        $valor_residual = (float)$activo_info_display['valor_residual'];
        $vida_util_anios = (int)$activo_info_display['vida_util_anios'];
        $vida_util_meses = $vida_util_anios * 12;
        $valor_depreciable = max(0, $valor_compra - $valor_residual);

        $depreciacion_info = [ /* ... (el array de depreciacion_info como lo tenías) ... */ 
            'dep_anual' => 0, 'dep_mensual' => 0, 'dep_acumulada' => 0,
            'valor_en_libros' => $valor_compra, 'meses_transcurridos' => $meses_transcurridos,
            'vida_util_meses' => $vida_util_meses, 'meses_restantes' => $vida_util_meses,
            'estado_depreciacion' => ($fecha_actual < $fecha_inicio) ? 'No iniciada' : 'Calculando...'
        ];

        if ($vida_util_anios > 0 && $valor_depreciable > 0) {
            $depreciacion_info['dep_anual'] = $valor_depreciable / $vida_util_anios;
            $depreciacion_info['dep_mensual'] = $depreciacion_info['dep_anual'] / 12;
            if ($meses_transcurridos > 0) {
                $meses_a_depreciar = min($meses_transcurridos, $vida_util_meses);
                $depreciacion_info['dep_acumulada'] = $depreciacion_info['dep_mensual'] * $meses_a_depreciar;
                if ($depreciacion_info['dep_acumulada'] > $valor_depreciable) { $depreciacion_info['dep_acumulada'] = $valor_depreciable; }
                $depreciacion_info['valor_en_libros'] = $valor_compra - $depreciacion_info['dep_acumulada'];
                if ($depreciacion_info['valor_en_libros'] < $valor_residual) { $depreciacion_info['valor_en_libros'] = $valor_residual; }
            } else { $depreciacion_info['dep_acumulada'] = 0; $depreciacion_info['valor_en_libros'] = $valor_compra; }
        }
        $depreciacion_info['meses_restantes'] = max(0, $vida_util_meses - $meses_transcurridos);

        if ($fecha_actual < $fecha_inicio) { $depreciacion_info['estado_depreciacion'] = 'No iniciada'; }
        elseif ($depreciacion_info['valor_en_libros'] <= $valor_residual || $meses_transcurridos >= $vida_util_meses ) {
            $depreciacion_info['dep_acumulada'] = $valor_depreciable; $depreciacion_info['valor_en_libros'] = $valor_residual;
            $depreciacion_info['estado_depreciacion'] = 'Totalmente Depreciado'; $depreciacion_info['meses_restantes'] = 0;
        } else { $depreciacion_info['estado_depreciacion'] = 'En Curso'; }
    } else {
        if (!$error_busqueda) { // Solo mostrar este error si no hay uno más general
             $error_busqueda = "El activo seleccionado no tiene datos suficientes para calcular la depreciación (fecha de inicio, valor o vida útil). Revise que el activo tenga una fecha de compra válida.";
        }
        $depreciacion_info = null; // No hay info de depreciación
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Consulta de Depreciación de Activos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        /* ... (tus estilos CSS existentes sin cambios) ... */
        body { background-color: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding-top: 80px; }
        .top-bar-custom { position: fixed; top: 0; left: 0; right: 0; z-index: 1030; display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 1.5rem; background-color: #ffffff; border-bottom: 1px solid #dee2e6; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .logo-container-top img { width: auto; height: 75px; object-fit: contain; margin-right: 15px; }
        .user-info-top { font-size: 0.9rem; }
        .container-main { background-color: #ffffff; padding: 25px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.075); }
        .page-title { color: #191970; }
        .card-depreciacion { border-left: 4px solid #191970; }
        .table th { background-color: #e9ecef; font-weight: 600;}
        .table td { font-size: 0.95em; }
        .table-hover tbody tr:hover { background-color: #f1f1f1; cursor: pointer; }
    </style>
</head>
<body>
<div class="top-bar-custom">
    <div class="logo-container-top"><a href="menu.php" title="Ir a Inicio"><img src="imagenes/logo.png" alt="Logo ARPESOD ASOCIADOS SAS"></a></div>
    <div class="d-flex align-items-center"><span class="text-dark me-3 user-info-top"><i class="bi bi-person-circle"></i> <?= htmlspecialchars($nombre_usuario_actual_sesion) ?> (<?= htmlspecialchars(ucfirst($rol_usuario_actual_sesion)) ?>)</span><form action="logout.php" method="post" class="d-flex"><button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</button></form></div>
</div>

<div class="container-main container mt-4">
    <div class="row mb-4">
        <div class="col">
            <h3 class="page-title"><i class="bi bi-calculator-fill"></i> Consulta de Depreciación de Activos</h3>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <form method="POST" action="depreciacion_activos.php">
                <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label for="tipo_criterio" class="form-label">Buscar por:</label>
                        <select class="form-select" id="tipo_criterio" name="tipo_criterio">
                            <option value="serie" <?= ($tipo_criterio_val === 'serie') ? 'selected' : '' ?>>Número de Serie</option>
                            <option value="cedula" <?= ($tipo_criterio_val === 'cedula') ? 'selected' : '' ?>>Cédula del Responsable</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label for="criterio_busqueda" class="form-label">Criterio:</label>
                        <input type="text" class="form-control" id="criterio_busqueda" name="criterio_busqueda" value="<?= htmlspecialchars($criterio_busqueda_val) ?>" required>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" name="buscar_activo_dep" class="btn btn-primary w-100"><i class="bi bi-search"></i> Consultar</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php if ($error_busqueda): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error_busqueda) ?></div>
    <?php endif; ?>

    <?php if (!empty($activos_del_responsable_lista)): ?>
        <div class="card mb-4">
            <div class="card-header fw-bold"><i class="bi bi-list-ul"></i> Activos Encontrados para <?= htmlspecialchars($nombre_responsable_buscado) ?> (C.C: <?= htmlspecialchars($criterio_busqueda_val) ?>)</div>
            <div class="card-body table-responsive">
                <p class="text-muted small">Seleccione un activo de la lista para ver sus detalles de depreciación.</p>
                <table class="table table-hover table-sm">
                    <thead>
                        <tr>
                            <th>ID Activo</th>
                            <th>Tipo</th>
                            <th>Marca</th>
                            <th>Serie</th>
                            <th>Estado</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activos_del_responsable_lista as $activo_item): ?>
                            <tr>
                                <td><?= htmlspecialchars($activo_item['id']) ?></td>
                                <td><?= htmlspecialchars($activo_item['tipo_activo']) ?></td>
                                <td><?= htmlspecialchars($activo_item['marca']) ?></td>
                                <td><?= htmlspecialchars($activo_item['serie']) ?></td>
                                <td><?= htmlspecialchars($activo_item['estado']) ?></td>
                                <td>
                                    <a href="depreciacion_activos.php?accion=ver_depreciacion&id_activo_dep=<?= $activo_item['id'] ?>&tipo_criterio_original=<?=urlencode($tipo_criterio_val)?>&criterio_original=<?=urlencode($criterio_busqueda_val)?>" class="btn btn-outline-primary btn-sm">
                                        <i class="bi bi-eye-fill"></i> Ver Depreciación
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
    <?php if ($activo_info_display && $depreciacion_info): ?>
    <div class="row">
        <div class="col-md-6 mb-4">
            <div class="card h-100">
                <div class="card-header fw-bold"><i class="bi bi-info-circle-fill"></i> Información del Activo</div>
                <div class="card-body">
                    <p><strong>ID Activo:</strong> <?= htmlspecialchars($activo_info_display['id'] ?? 'N/A') ?></p>
                    <p><strong>Tipo:</strong> <?= htmlspecialchars($activo_info_display['tipo_activo'] ?? 'N/A') ?></p>
                    <p><strong>Marca:</strong> <?= htmlspecialchars($activo_info_display['marca'] ?? 'N/A') ?></p>
                    <p><strong>Serie:</strong> <?= htmlspecialchars($activo_info_display['serie'] ?? 'N/A') ?></p>
                    <p><strong>Responsable:</strong> <?= htmlspecialchars($activo_info_display['nombre'] ?? 'N/A') ?> (C.C: <?= htmlspecialchars($activo_info_display['cedula'] ?? 'N/A') ?>)</p>
                    <p><strong>Cargo Responsable:</strong> <?= htmlspecialchars($activo_info_display['cargo'] ?? 'N/A') ?></p>
                    <hr>
                    <p><strong>Fecha Compra/Inicio Dep.:</strong> <?= $activo_info_display['fecha_inicio_depreciacion'] ? htmlspecialchars(date("d/m/Y", strtotime($activo_info_display['fecha_inicio_depreciacion']))) : 'N/A' ?></p>
                    <p><strong>Valor Compra:</strong> $<?= htmlspecialchars(number_format($activo_info_display['valor_aproximado'], 2, ',', '.')) ?></p>
                    <p><strong>Valor Residual:</strong> $<?= htmlspecialchars(number_format($activo_info_display['valor_residual'], 2, ',', '.')) ?></p>
                    <p><strong>Vida Útil:</strong> <?= htmlspecialchars($activo_info_display['vida_util_anios']) ?> años (<?= htmlspecialchars($depreciacion_info['vida_util_meses']) ?> meses)</p>
                    <p><strong>Método:</strong> <?= htmlspecialchars($activo_info_display['metodo_depreciacion']) ?></p>
                </div>
            </div>
        </div>

        <div class="col-md-6 mb-4">
            <div class="card card-depreciacion h-100">
                <div class="card-header fw-bold"><i class="bi bi-graph-down"></i> Cálculo de Depreciación (a hoy: <?= date("d/m/Y") ?>)</div>
                <div class="card-body">
                    <p><strong>Meses Transcurridos:</strong> <?= htmlspecialchars($depreciacion_info['meses_transcurridos']) ?> de <?= htmlspecialchars($depreciacion_info['vida_util_meses']) ?></p>
                    <p><strong>Meses Restantes:</strong> <?= htmlspecialchars($depreciacion_info['meses_restantes']) ?></p>
                    <table class="table table-sm table-bordered">
                        <tbody>
                            <tr><th>Depreciación Anual:</th><td>$<?= htmlspecialchars(number_format($depreciacion_info['dep_anual'], 2, ',', '.')) ?></td></tr>
                            <tr><th>Depreciación Mensual:</th><td>$<?= htmlspecialchars(number_format($depreciacion_info['dep_mensual'], 2, ',', '.')) ?></td></tr>
                            <tr><th>Depreciación Acumulada:</th><td class="text-danger fw-bold">$<?= htmlspecialchars(number_format($depreciacion_info['dep_acumulada'], 2, ',', '.')) ?></td></tr>
                            <tr><th>Valor en Libros Actual:</th><td class="text-success fw-bold">$<?= htmlspecialchars(number_format($depreciacion_info['valor_en_libros'], 2, ',', '.')) ?></td></tr>
                        </tbody>
                    </table>
                     <p class="mt-3 text-center"><strong>Estado de Depreciación:</strong> 
                        <span class="badge fs-6 <?= 
                            ($depreciacion_info['estado_depreciacion'] === 'Totalmente Depreciado') ? 'bg-secondary' : 
                            (($depreciacion_info['estado_depreciacion'] === 'En Curso') ? 'bg-warning text-dark' : 'bg-info text-dark') 
                        ?>">
                            <?= htmlspecialchars($depreciacion_info['estado_depreciacion']) ?>
                        </span>
                    </p>
                </div>
            </div>
        </div>
    </div>
    <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($activos_del_responsable_lista) && !$error_busqueda): ?>
        <div class="alert alert-warning">No se encontró información para el criterio de búsqueda proporcionado.</div>
    <?php endif; ?>
     <div class="mt-4 text-center">
        <a href="menu.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left-circle"></i> Volver al Menú</a>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>