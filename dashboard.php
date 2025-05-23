<?php
error_reporting(E_ALL); // Para depuración, considera quitar en producción
ini_set('display_errors', 1); // Para depuración, considera quitar en producción
session_start();

if (!isset($_SESSION['usuario_id'])) { // Usar una variable de sesión más robusta como 'usuario_id' o 'loggedin'
    header("Location: login.php");
    exit;
}

require_once 'backend/auth_check.php'; // Para funciones como tiene_permiso_para y es_admin
// Restringir acceso si es necesario, por ejemplo, solo admin, tecnico, auditor pueden ver el dashboard
restringir_acceso_pagina(['admin', 'tecnico', 'auditor']);


require_once 'backend/db.php'; 
if (isset($conn) && !isset($conexion)) { $conexion = $conn; }
if (!isset($conexion) || !$conexion) { die("Error de conexión a la base de datos."); }
$conexion->set_charset("utf8mb4"); 

// Captura de datos de sesión para la barra superior
$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Usuario';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'Desconocido';

// Función para mostrar estrellas (copiada de informes.php para uso en KPI)
if (!function_exists('displayStars')) {
    function displayStars($rating, $totalStars = 5) {
        if ($rating === null || !is_numeric($rating) || $rating < 0) return 'N/A';
        $rating_calc = round(floatval($rating) * 2) / 2; 
        $output = "<span style='color: #f5b301; font-size: 0.9em;'>"; 
        for ($i = 1; $i <= $totalStars; $i++) {
            if ($rating_calc >= $i) $output .= '★';
            elseif ($rating_calc >= $i - 0.5) $output .= '★'; 
            else $output .= '☆';
        }
        $output .= "</span> (" . number_format(floatval($rating), 1) . ")";
        return $output;
    }
}


// --- Consultas para KPIs y Gráficos ---

// 1. Cantidad Total de Activos (Operativos)
$result_total_activos = mysqli_query($conexion, "SELECT COUNT(*) as total FROM activos_tecnologicos WHERE estado != 'Dado de Baja'");
$total_activos = ($result_total_activos) ? mysqli_fetch_assoc($result_total_activos)['total'] : 0;

// 2. Activos por Estado (para el KPI, Operativos)
$activos_por_estado_data_kpi = [];
$result_por_estado_kpi = mysqli_query($conexion, "SELECT estado, COUNT(*) as cantidad FROM activos_tecnologicos WHERE estado != 'Dado de Baja' GROUP BY estado ORDER BY cantidad DESC");
if ($result_por_estado_kpi) {
    while ($row = mysqli_fetch_assoc($result_por_estado_kpi)) {
        $activos_por_estado_data_kpi[] = $row;
    }
}

// 3. Número de Usuarios con Activos (Operativos)
$result_total_usuarios = mysqli_query($conexion, "SELECT COUNT(DISTINCT cedula) as total_usuarios FROM activos_tecnologicos WHERE cedula IS NOT NULL AND cedula != '' AND estado != 'Dado de Baja'");
$total_usuarios_con_activos = ($result_total_usuarios) ? mysqli_fetch_assoc($result_total_usuarios)['total_usuarios'] : 0;

// 4. KPI: Tipo de Activo con Mejor Calificación Promedio (Operativos)
$kpi_mejor_tipo_activo_nombre = "N/A";
$kpi_mejor_tipo_activo_rating_avg = 0;
$query_mejor_tipo = "SELECT tipo_activo, AVG(satisfaccion_rating) as avg_rating
                     FROM activos_tecnologicos
                     WHERE satisfaccion_rating IS NOT NULL AND estado != 'Dado de Baja'
                     GROUP BY tipo_activo
                     ORDER BY avg_rating DESC
                     LIMIT 1";
$result_mejor_tipo = mysqli_query($conexion, $query_mejor_tipo);
if ($result_mejor_tipo && mysqli_num_rows($result_mejor_tipo) > 0) {
    $row_mejor_tipo = mysqli_fetch_assoc($result_mejor_tipo);
    $kpi_mejor_tipo_activo_nombre = $row_mejor_tipo['tipo_activo'];
    $kpi_mejor_tipo_activo_rating_avg = (float)$row_mejor_tipo['avg_rating'];
}

// 5. Datos para Gráfico: Activos por Tipo (con detalle de estado para tooltip, Operativos)
$raw_data_tipo_estado = [];
$result_graf_tipo_detalle = mysqli_query($conexion, 
    "SELECT tipo_activo, estado, COUNT(*) as cantidad 
     FROM activos_tecnologicos 
     WHERE tipo_activo IS NOT NULL AND tipo_activo != '' AND estado != 'Dado de Baja'
     GROUP BY tipo_activo, estado 
     ORDER BY tipo_activo, estado"
);
if ($result_graf_tipo_detalle) {
    while ($row = mysqli_fetch_assoc($result_graf_tipo_detalle)) { $raw_data_tipo_estado[] = $row; }
}
$tipo_activo_summary = [];
foreach ($raw_data_tipo_estado as $item) {
    if (!isset($tipo_activo_summary[$item['tipo_activo']])) {
        $tipo_activo_summary[$item['tipo_activo']] = ['total' => 0, 'statuses' => []];
    }
    $tipo_activo_summary[$item['tipo_activo']]['total'] += $item['cantidad'];
    $tipo_activo_summary[$item['tipo_activo']]['statuses'][$item['estado']] = $item['cantidad'];
}
uasort($tipo_activo_summary, function ($a, $b) { return $b['total'] - $a['total']; });
$labels_tipo_activo_new = []; $data_tipo_activo_total = []; $detailed_status_data_for_tooltip = [];
$limit_tipos = 7; $count_tipos = 0; // Mantener el límite de 7 para este gráfico
foreach ($tipo_activo_summary as $tipo => $summary) {
    if ($count_tipos >= $limit_tipos) break;
    $labels_tipo_activo_new[] = $tipo;
    $data_tipo_activo_total[] = $summary['total'];
    $detailed_status_data_for_tooltip[$tipo] = $summary['statuses'];
    $count_tipos++;
}

// 6. Datos para Gráfico: Activos por Regional (Operativos)
$labels_regional = [];
$data_regional = [];
$result_graf_regional = mysqli_query($conexion, "SELECT regional, COUNT(*) as cantidad FROM activos_tecnologicos WHERE regional IS NOT NULL AND regional != '' AND estado != 'Dado de Baja' GROUP BY regional ORDER BY cantidad DESC LIMIT 7");
if ($result_graf_regional) {
    while ($row = mysqli_fetch_assoc($result_graf_regional)) {
        $labels_regional[] = $row['regional'];
        $data_regional[] = $row['cantidad'];
    }
}

// 7. Datos para Gráfico: Activos por Empresa (Operativos)
$labels_empresa = [];
$data_empresa = [];
$result_graf_empresa = mysqli_query($conexion, "SELECT Empresa, COUNT(*) as cantidad FROM activos_tecnologicos WHERE Empresa IS NOT NULL AND Empresa != '' AND estado != 'Dado de Baja' GROUP BY Empresa ORDER BY cantidad DESC LIMIT 7");
if ($result_graf_empresa) {
    while ($row = mysqli_fetch_assoc($result_graf_empresa)) {
        $labels_empresa[] = $row['Empresa']; 
        $data_empresa[] = $row['cantidad'];
    }
}

// 8. Últimos 5 Activos Registrados (Operativos)
$ultimos_activos = [];
$result_ultimos = mysqli_query($conexion, "SELECT tipo_activo, marca, serie, fecha_registro, nombre FROM activos_tecnologicos WHERE estado != 'Dado de Baja' ORDER BY fecha_registro DESC, id DESC LIMIT 5");
if ($result_ultimos) {
    while($row = mysqli_fetch_assoc($result_ultimos)){ $ultimos_activos[] = $row; }
}

mysqli_close($conexion);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard de Activos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.7.0/dist/chart.min.js"></script>
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

        /* Estilos específicos del Dashboard */
        .kpi-card { background-color: #fff; border-radius: 0.5rem; padding: 1.25rem; margin-bottom: 1.5rem; box-shadow: 0 2px 10px rgba(0,0,0,0.075); text-align: center; min-height: 130px; display: flex; flex-direction: column; justify-content: center; border: 1px solid #e9ecef; }
        .kpi-card .kpi-value { font-size: 2rem; font-weight: 700; color: #191970; line-height:1.2; }
        .kpi-card .kpi-label { font-size: 0.85rem; color: #6c757d; margin-top: 0.25rem; text-transform: uppercase; letter-spacing: 0.5px; }
        .kpi-card .kpi-subtext { font-size: 0.9em; color: #555; margin-top: 4px;}
        
        .chart-container { background-color: #fff; border-radius: 0.5rem; padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 2px 10px rgba(0,0,0,0.075); border: 1px solid #e9ecef; }
        .chart-container h5 { margin-bottom: 1rem; text-align: center; color: #343a40; font-weight: 600;}
        .chart-canvas-wrapper { position: relative; height: 280px; width: 100%; }

        .status-list-group .list-group-item { display: flex; justify-content: space-between; align-items: center; border-color: #e9ecef; padding: 0.5rem 0.8rem; font-size: 0.85em; }
        .status-list-group .badge { font-size: 0.85em; }
        .table-recent-assets { font-size: 0.88rem;}
        .table-recent-assets th { background-color: #f0f2f5; font-weight: 600; color: #343a40; padding: 0.6rem 0.75rem;}
        .table-recent-assets td { padding: 0.6rem 0.75rem; vertical-align: middle;}
        .page-header-title { color: #191970; }
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

<div class="container-fluid mt-4 px-md-4 px-2"> 
    <h3 class="mb-4 text-center page-header-title">Dashboard de Activos Tecnológicos</h3>

    <div class="row">
        <div class="col-xl-3 col-lg-6 col-md-6 col-sm-12 mb-4"> 
            <div class="kpi-card">
                <div class="kpi-value"><?= $total_activos ?></div>
                <div class="kpi-label">Total Activos (Operativos)</div>
            </div>
        </div>
        <div class="col-xl-3 col-lg-6 col-md-6 col-sm-12 mb-4"> 
            <div class="kpi-card">
                <div class="kpi-value"><?= $total_usuarios_con_activos ?></div>
                <div class="kpi-label">Usuarios con Activos</div>
            </div>
        </div>
        <div class="col-xl-3 col-lg-6 col-md-6 col-sm-12 mb-4"> 
            <div class="kpi-card">
                <div class="kpi-value" style="font-size: 1.4rem; margin-bottom: 0.1rem; line-height:1.3;"><?= htmlspecialchars($kpi_mejor_tipo_activo_nombre) ?></div>
                <div class="kpi-label">Tipo Activo Mejor Calificado</div>
                <div class="kpi-subtext"><?= displayStars($kpi_mejor_tipo_activo_rating_avg) ?></div>
            </div>
        </div>
        <div class="col-xl-3 col-lg-6 col-md-6 col-sm-12 mb-4"> 
            <div class="kpi-card" style="padding: 1rem;"> 
                <div class="kpi-label" style="margin-bottom:0.3rem; font-size:0.9rem; color: #343a40;">Top Estados de Activos</div>
                   <ul class="list-group list-group-flush status-list-group text-start" style="font-size: 0.85em;">
                        <?php if (!empty($activos_por_estado_data_kpi)): $count_kpi_estado = 0; ?>
                            <?php foreach ($activos_por_estado_data_kpi as $estado_info): if(++$count_kpi_estado > 3) break; ?>
                                <li class="list-group-item">
                                    <?= htmlspecialchars($estado_info['estado']) ?>
                                    <span class="badge rounded-pill bg-primary"><?= $estado_info['cantidad'] ?></span>
                                </li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li class="list-group-item text-muted">N/A</li>
                        <?php endif; ?>
                   </ul>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-4 col-md-12 mb-4"> 
            <div class="chart-container">
                <h5>Activos por Tipo (Top <?= $limit_tipos ?>)</h5>
                <div class="chart-canvas-wrapper">
                    <canvas id="graficoTipoActivo"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6 mb-4"> 
            <div class="chart-container">
                <h5>Activos por Regional (Top 7)</h5>
                   <div class="chart-canvas-wrapper">
                    <canvas id="graficoRegional"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6 mb-4"> 
            <div class="chart-container">
                <h5>Activos por Empresa (Top 7)</h5>
                <div class="chart-canvas-wrapper">
                    <canvas id="graficoEmpresa"></canvas>
                </div>
            </div>
        </div>
    </div>
    
    <?php if(!empty($ultimos_activos)): ?>
    <div class="row mt-2">
        <div class="col-12">
            <div class="chart-container"> 
                <h5>Últimos Activos Registrados (Operativos)</h5>
                <div class="table-responsive">
                    <table class="table table-sm table-hover table-recent-assets">
                        <thead>
                            <tr>
                                <th>Responsable (Nombre)</th>
                                <th>Tipo Activo</th>
                                <th>Marca</th>
                                <th>Serie</th>
                                <th>Fecha Registro</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($ultimos_activos as $ua): ?>
                            <tr>
                                <td><?= htmlspecialchars($ua['nombre']) ?></td>
                                <td><?= htmlspecialchars($ua['tipo_activo']) ?></td>
                                <td><?= htmlspecialchars($ua['marca']) ?></td>
                                <td><?= htmlspecialchars($ua['serie']) ?></td>
                                <td><?= htmlspecialchars(date("d/m/Y H:i", strtotime($ua['fecha_registro']))) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const defaultChartColors = [
        'rgba(25, 25, 112, 0.8)','rgba(70, 130, 180, 0.8)','rgba(100, 149, 237, 0.8)',
        'rgba(173, 216, 230, 0.8)','rgba(119, 136, 153, 0.8)','rgba(128, 128, 128, 0.8)','rgba(192, 192, 192, 0.8)'
    ];
    const defaultBorderColors = defaultChartColors.map(color => color.replace('0.8', '1'));

    const labelsTipo = <?= json_encode($labels_tipo_activo_new) ?>;
    const dataTipoTotal = <?= json_encode($data_tipo_activo_total) ?>;
    const detailedStatusData = <?= json_encode($detailed_status_data_for_tooltip) ?>;

    if (document.getElementById('graficoTipoActivo') && labelsTipo.length > 0 && dataTipoTotal.length > 0) {
        const maxDataValueTipo = Math.max(...dataTipoTotal);
        new Chart(document.getElementById('graficoTipoActivo'), {
            type: 'bar', 
            data: { labels: labelsTipo, datasets: [{ label: 'Cantidad Total', data: dataTipoTotal, backgroundColor: defaultChartColors, borderColor: defaultBorderColors, borderWidth: 1 }] },
            options: {
                responsive: true, maintainAspectRatio: false,
                scales: { y: { beginAtZero: true, ticks: { stepSize: Math.max(1, Math.ceil(maxDataValueTipo / 8)) || 1, precision: 0 } } },
                plugins: { 
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) { let tipoActivo = context.label; let total = context.parsed.y; return `${tipoActivo}: ${total} (Total)`; },
                            footer: function(tooltipItems) {
                                const tipoActivo = tooltipItems[0].label; const statuses = detailedStatusData[tipoActivo]; let footerLines = [];
                                if (statuses) { footerLines.push(''); for (const estado in statuses) { if (statuses.hasOwnProperty(estado)) { footerLines.push(`${estado}: ${statuses[estado]}`); } } }
                                return footerLines;
                            }
                        }
                    }
                } 
            }
        });
    }

    const labelsRegional = <?= json_encode($labels_regional) ?>;
    const dataRegional = <?= json_encode($data_regional) ?>;
    if (document.getElementById('graficoRegional') && labelsRegional.length > 0 && dataRegional.length > 0) {
        new Chart(document.getElementById('graficoRegional'), {
            type: 'doughnut',
            data: { labels: labelsRegional, datasets: [{ label: 'Activos', data: dataRegional, backgroundColor: defaultChartColors, borderColor: '#fff', borderWidth: 2, hoverOffset: 4 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { padding: 15, boxWidth: 12 } } } }
        });
    }

    const labelsEmpresa = <?= json_encode($labels_empresa) ?>;
    const dataEmpresa = <?= json_encode($data_empresa) ?>;
    if (document.getElementById('graficoEmpresa') && labelsEmpresa.length > 0 && dataEmpresa.length > 0) {
        new Chart(document.getElementById('graficoEmpresa'), {
            type: 'pie', 
            data: { labels: labelsEmpresa, datasets: [{ label: 'Activos', data: dataEmpresa, backgroundColor: defaultChartColors, borderColor: '#fff', borderWidth: 2, hoverOffset: 4 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { padding: 15, boxWidth: 12 } } } }
        });
    }
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>