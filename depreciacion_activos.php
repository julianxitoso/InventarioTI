<?php
require_once 'backend/auth_check.php';
restringir_acceso_pagina(['admin', 'auditor', 'registrador', 'tecnico']);

require_once 'backend/db.php';
if (isset($conn) && !isset($conexion)) { $conexion = $conn; }
if (!isset($conexion) || !$conexion || (method_exists($conexion, 'connect_error') && $conexion->connect_error)) {
    error_log("Fallo CRÍTICO de conexión a BD (depreciacion_activos.php): " . ($conexion->connect_error ?? 'Desconocido'));
    die("Error de conexión a la base de datos.");
}
$conexion->set_charset("utf8mb4");

define('VALOR_UVT_2025', 49799);
define('UMBRAL_UVT_DEPRECIACION', 50);

$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Usuario';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'Desconocido';

$activo_info_display = null;
$depreciacion_info = null;
$error_busqueda = null;
$criterio_busqueda_val = '';
$tipo_criterio_val = '';
$activos_del_responsable_lista = [];
$nombre_responsable_buscado = '';

// ... (resto de tu lógica PHP de get_asset_details_by_id, manejo de GET y POST, y cálculo de depreciación sin cambios) ...
// Esta lógica PHP interna no se modifica para este cambio de diseño de la barra superior.
// Asegúrate de que la lógica que tenías para $activo_info_display, $depreciacion_info, etc., esté completa aquí.
// Por brevedad, la omito aquí, pero debe estar presente en tu archivo.

// Ejemplo de la función (asegúrate que esté completa y correcta en tu archivo)
function get_asset_details_by_id($id, $conn) {
    $sql = "SELECT 
                a.id, a.serie, a.marca, a.estado, a.valor_aproximado, a.valor_residual, 
                a.fecha_compra, a.metodo_depreciacion, a.detalles,
                u.nombre_completo AS nombre_responsable,
                u.usuario AS cedula_responsable,
                c.nombre_cargo AS cargo_responsable,
                ta.nombre_tipo_activo AS nombre_tipo_activo,
                ta.vida_util_sugerida AS vida_util_anios
            FROM activos_tecnologicos a
            LEFT JOIN usuarios u ON a.id_usuario_responsable = u.id
            LEFT JOIN cargos c ON u.id_cargo = c.id_cargo
            LEFT JOIN tipos_activo ta ON a.id_tipo_activo = ta.id_tipo_activo
            WHERE a.id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) { /* ... */ return $stmt->get_result()->fetch_assoc(); } // Simplificado
    return null;
}
// ... (resto del PHP) ...
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['accion']) && $_GET['accion'] === 'ver_depreciacion' && isset($_GET['id_activo_dep'])) {
    $id_activo_seleccionado = (int)$_GET['id_activo_dep'];
    $activo_info_display = get_asset_details_by_id($id_activo_seleccionado, $conexion);
    // ...
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buscar_activo_dep'])) {
    // ... Lógica de búsqueda POST ...
}
// ... Lógica de cálculo de depreciación (importante que esté completa) ...
if ($activo_info_display) {
    if (
        isset($activo_info_display['fecha_compra']) && $activo_info_display['fecha_compra'] &&
        isset($activo_info_display['valor_aproximado']) && is_numeric($activo_info_display['valor_aproximado']) && $activo_info_display['valor_aproximado'] > 0
    ) {
        $valor_compra_activo = (float)$activo_info_display['valor_aproximado'];
        $valor_activo_en_uvt = $valor_compra_activo / VALOR_UVT_2025;

        if ($valor_activo_en_uvt >= UMBRAL_UVT_DEPRECIACION) {
            if (isset($activo_info_display['vida_util_anios']) && (int)$activo_info_display['vida_util_anios'] > 0) {
                // ... (Cálculos de depreciación detallados) ...
                 $depreciacion_info = [ /* ... datos completos ... */ 'aplica_depreciacion_uvt' => true, 'valor_activo_en_uvt' => $valor_activo_en_uvt];
            } else {
                 $depreciacion_info = null; 
                 if (!$error_busqueda) $error_busqueda = "El tipo de activo '".htmlspecialchars($activo_info_display['nombre_tipo_activo'] ?? '')."' no tiene una vida útil asignada.";
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

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Consulta de Depreciación de Activos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        /* Estilos copiados/adaptados de centro_gestion.php y gestiones previas para consistencia */
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            padding-top: 100px; /* Ajustado para nueva barra superior (como en centro_gestion.php) */
            background-color: #f8f9fa; 
        }
        .top-bar-custom { 
            position: fixed; top: 0; left: 0; right: 0; z-index: 1030; 
            display: flex; justify-content: space-between; align-items: center; 
            padding: 0.5rem 1.5rem; background-color: #ffffff; 
            border-bottom: 1px solid #dee2e6; box-shadow: 0 2px 4px rgba(0,0,0,0.05); 
        }
        .logo-container-top img { 
            width: auto; height: 75px; object-fit: contain; margin-right: 15px; 
        }
        .user-info-top { /* Tomado de centro_gestion.php */
            font-size: 0.9rem; 
        }
        
        .container-main { 
            background-color: #ffffff; padding: 25px; border-radius: 8px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.075); 
            margin-top: 20px; /* Margen superior para el contenido principal */
        }
        h1.page-title, h3.page-title { /* Aplicar a ambos h1 y h3 si se usa */
            color: #0d6efd; /* Azul primario de Bootstrap, consistente con otras gestiones */
            font-weight: 600; 
            font-size: 1.75rem; /* Tamaño de título principal */
            text-align: center; /* Centrado como en otras gestiones */
        }
        .page-header-custom-area { /* Contenedor para título y botón volver si se usa estructura de otras gestiones */
            margin-bottom: 1.5rem;
        }

        .card-depreciacion { border-left: 4px solid #0d6efd; }
        .table th { background-color: #e9ecef; font-weight: 600;}
        .table td { font-size: 0.95em; }
        .table-hover tbody tr:hover { background-color: #f1f1f1; }

        @media (max-width: 575.98px) { /* xs screens */
            /* Ya no se necesita apilar la top-bar si se quiere idéntica a centro_gestion.php que no apila */
            /* Si centro_gestion.php SÍ apila su top-bar, entonces estos estilos serían necesarios:
            body { padding-top: 150px; } 
            .top-bar-custom { flex-direction: column; padding: 0.75rem 1rem; }
            .logo-container-top { margin-bottom: 0.5rem; text-align: center; width: 100%; }
            .top-bar-user-info-container { display: flex; flex-direction: column; align-items: center; width: 100%; text-align: center; }
            .top-bar-user-info-container .user-info-top { margin-right: 0; margin-bottom: 0.5rem; }
            */
            h1.page-title, h3.page-title { font-size: 1.5rem !important; } 
            .page-header-custom-area .text-sm-end { text-align: center !important; } 
            .container-main { padding: 15px; }
            .card-body .row.g-3 { flex-direction: column; } 
            .card-body .row.g-3 .col-md-2 button { margin-top: 0.5rem; }
        }
    </style>
</head>
<body>
<div class="top-bar-custom"> 
    <div class="logo-container-top">
        <a href="menu.php" title="Ir a Inicio"><img src="imagenes/logo.png" alt="Logo ARPESOD ASOCIADOS SAS"></a>
    </div>
    <div class="d-flex align-items-center"> 
        <span class="text-dark me-3 user-info-top"><i class="bi bi-person-circle"></i> <?= htmlspecialchars($nombre_usuario_actual_sesion) ?> (<?= htmlspecialchars(ucfirst($rol_usuario_actual_sesion)) ?>)</span>
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

    <div class="container-main"> 
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

        <?php if ($error_busqueda && !($depreciacion_info && isset($depreciacion_info['aplica_depreciacion_uvt']) && !$depreciacion_info['aplica_depreciacion_uvt']) && !($depreciacion_info === null && $activo_info_display && (strpos($error_busqueda, "datos suficientes")!==false || strpos($error_busqueda, "vida útil asignada")!==false))): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error_busqueda) ?></div>
        <?php endif; ?>

        <?php if (!empty($activos_del_responsable_lista)): ?>
            {/* ... Tabla de activos del responsable ... */}
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
        <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buscar_activo_dep']) && empty($activos_del_responsable_lista) && !$error_busqueda): ?>
            <div class="alert alert-warning">No se encontró información para el criterio de búsqueda proporcionado.</div>
        <?php endif; ?>
    </div> 
</div> 

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>