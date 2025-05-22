<?php
session_start();
if (!isset($_SESSION['usuario'])) {
    header("Location: login.php");
    exit;
}

require_once 'backend/db.php'; 
if (isset($conn) && !isset($conexion)) { $conexion = $conn; }
if (!isset($conexion) || !$conexion) { die("Error de conexión a la base de datos."); }
$conexion->set_charset("utf8mb4"); // Asegurar UTF-8

// --- Consultas para KPIs y Gráficos ---

// 1. Cantidad Total de Activos
$result_total_activos = mysqli_query($conexion, "SELECT COUNT(*) as total FROM activos_tecnologicos");
$total_activos = ($result_total_activos) ? mysqli_fetch_assoc($result_total_activos)['total'] : 0;

// 2. Activos por Estado (para el KPI)
$activos_por_estado_data_kpi = [];
$result_por_estado_kpi = mysqli_query($conexion, "SELECT estado, COUNT(*) as cantidad FROM activos_tecnologicos GROUP BY estado ORDER BY cantidad DESC");
if ($result_por_estado_kpi) {
    while ($row = mysqli_fetch_assoc($result_por_estado_kpi)) {
        $activos_por_estado_data_kpi[] = $row;
    }
}

// 3. Número de Usuarios con Activos
$result_total_usuarios = mysqli_query($conexion, "SELECT COUNT(DISTINCT cedula) as total_usuarios FROM activos_tecnologicos WHERE cedula IS NOT NULL AND cedula != ''");
$total_usuarios_con_activos = ($result_total_usuarios) ? mysqli_fetch_assoc($result_total_usuarios)['total_usuarios'] : 0;

// 4. Datos para Gráfico: Activos por Tipo (con detalle de estado para tooltip)
$raw_data_tipo_estado = [];
$result_graf_tipo_detalle = mysqli_query($conexion, 
    "SELECT tipo_activo, estado, COUNT(*) as cantidad 
     FROM activos_tecnologicos 
     WHERE tipo_activo IS NOT NULL AND tipo_activo != ''
     GROUP BY tipo_activo, estado 
     ORDER BY tipo_activo, estado"
);

if ($result_graf_tipo_detalle) {
    while ($row = mysqli_fetch_assoc($result_graf_tipo_detalle)) {
        $raw_data_tipo_estado[] = $row;
    }
}

$tipo_activo_summary = [];
foreach ($raw_data_tipo_estado as $item) {
    if (!isset($tipo_activo_summary[$item['tipo_activo']])) {
        $tipo_activo_summary[$item['tipo_activo']] = [
            'total' => 0,
            'statuses' => []
        ];
    }
    $tipo_activo_summary[$item['tipo_activo']]['total'] += $item['cantidad'];
    // Ordenar statuses para consistencia en tooltip si se desea
    $tipo_activo_summary[$item['tipo_activo']]['statuses'][$item['estado']] = $item['cantidad'];
}

uasort($tipo_activo_summary, function ($a, $b) {
    return $b['total'] - $a['total'];
});

$labels_tipo_activo_new = [];
$data_tipo_activo_total = [];
$detailed_status_data_for_tooltip = [];
$limit_tipos = 15;
$count_tipos = 0;
foreach ($tipo_activo_summary as $tipo => $summary) {
    if ($count_tipos >= $limit_tipos) break;
    $labels_tipo_activo_new[] = $tipo;
    $data_tipo_activo_total[] = $summary['total'];
    $detailed_status_data_for_tooltip[$tipo] = $summary['statuses'];
    $count_tipos++;
}

// 5. Datos para Gráfico: Activos por Regional
$labels_regional = [];
$data_regional = [];
$result_graf_regional = mysqli_query($conexion, "SELECT regional, COUNT(*) as cantidad FROM activos_tecnologicos WHERE regional IS NOT NULL AND regional != '' GROUP BY regional ORDER BY cantidad DESC LIMIT 7");
if ($result_graf_regional) {
    while ($row = mysqli_fetch_assoc($result_graf_regional)) {
        $labels_regional[] = $row['regional'];
        $data_regional[] = $row['cantidad'];
    }
}

// 6. Últimos 5 Activos Registrados
$ultimos_activos = [];
$result_ultimos = mysqli_query($conexion, "SELECT tipo_activo, marca, serie, fecha_registro, nombre FROM activos_tecnologicos ORDER BY fecha_registro DESC, id DESC LIMIT 5");
if ($result_ultimos) {
    while($row = mysqli_fetch_assoc($result_ultimos)){
        $ultimos_activos[] = $row;
    }
}

// 7. Datos para Gráfico: Activos por Empresa (NUEVO)
$labels_empresa = [];
$data_empresa = [];
// Asumiendo que la columna es 'Empresa'. Si es 'empresa', cambiar aquí.
$result_graf_empresa = mysqli_query($conexion, "SELECT Empresa, COUNT(*) as cantidad FROM activos_tecnologicos WHERE Empresa IS NOT NULL AND Empresa != '' GROUP BY Empresa ORDER BY cantidad DESC LIMIT 7");
if ($result_graf_empresa) {
    while ($row = mysqli_fetch_assoc($result_graf_empresa)) {
        $labels_empresa[] = $row['Empresa']; // Usar 'Empresa' como la clave
        $data_empresa[] = $row['cantidad'];
    }
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
        body { background-color: #f4f6f8; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .logo-container { text-align: center; margin-bottom: 5px; padding-top:10px; }
        .logo-container img { width: 180px; height: auto; }
        .navbar-custom { background-color: #191970; }
        .navbar-custom .nav-link { color: white !important; font-weight: 500; padding: 0.5rem 1rem;}
        .navbar-custom .nav-link:hover, .navbar-custom .nav-link.active { background-color: #8b0000; color: white; }

        .kpi-card {
            background-color: #fff; border-radius: 0.5rem; padding: 1.25rem;
            margin-bottom: 1.5rem; box-shadow: 0 2px 10px rgba(0,0,0,0.075);
            text-align: center; min-height: 130px; display: flex;
            flex-direction: column; justify-content: center;
        }
        .kpi-card .kpi-value { font-size: 2.25rem; font-weight: 700; color: #191970; }
        .kpi-card .kpi-label { font-size: 0.9rem; color: #6c757d; margin-top: 0.25rem; text-transform: uppercase; letter-spacing: 0.5px; }
        
        .chart-container { 
            background-color: #fff; border-radius: 0.5rem; padding: 1.5rem;
            margin-bottom: 1.5rem; box-shadow: 0 2px 10px rgba(0,0,0,0.075);
        }
        .chart-container h5 { margin-bottom: 1rem; text-align: center; color: #343a40; font-weight: 600;}
        .chart-canvas-wrapper {
            position: relative;
            height: 280px; /* Altura fija para los gráficos */
            width: 100%;
        }

        .status-list-group .list-group-item { display: flex; justify-content: space-between; align-items: center; border-color: #e9ecef; padding: 0.6rem 1rem; font-size: 0.9em; }
        .status-list-group .badge { font-size: 0.9em; }
        .table-recent-assets { font-size: 0.88rem;}
        .table-recent-assets th { background-color: #f8f9fa; font-weight: 600; color: #343a40; padding: 0.6rem 0.75rem;}
        .table-recent-assets td { padding: 0.6rem 0.75rem; vertical-align: middle;}
    </style>
</head>
<body>

<div class="logo-container">
  <a href="menu.php"><img src="imagenes/logo3.png" alt="Logo" style="height: 70px; width: auto;"></a>
</div>

<nav class="navbar navbar-expand-lg navbar-custom">
    <div class="container-fluid">
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon" style="background-image: url(\"data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba(255,255,255,0.8)' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e\");"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item"><a class="nav-link" href="menu.php">Inicio</a></li>
                <li class="nav-item"><a class="nav-link" href="index.php">Registrar Activo</a></li>
                <li class="nav-item"><a class="nav-link" href="editar.php">Editar Activos</a></li>
                <li class="nav-item"><a class="nav-link" href="buscar.php">Buscar Activos</a></li>
                <li class="nav-item"><a class="nav-link" href="informes.php">Informes</a></li>
                <li class="nav-item"><a class="nav-link active" aria-current="page" href="dashboard.php">Dashboard</a></li>
            </ul>
            <form class="d-flex ms-auto" action="logout.php" method="post">
                <button class="btn btn-outline-light" type="submit">Cerrar sesión</button>
            </form>
        </div>
    </div>
</nav>

<div class="container-fluid mt-4 px-4"> 
    <h3 class="mb-4 text-center" style="color: #343a40; font-weight: 600;">Dashboard de Activos Tecnológicos</h3>

    <div class="row">
        <div class="col-lg-4 col-md-6 col-sm-12">
            <div class="kpi-card">
                <div class="kpi-value"><?= $total_activos ?></div>
                <div class="kpi-label">Total de Activos</div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6 col-sm-12">
            <div class="kpi-card">
                <div class="kpi-value"><?= $total_usuarios_con_activos ?></div>
                <div class="kpi-label">Usuarios con Activos</div>
            </div>
        </div>
        <div class="col-lg-4 col-md-12 col-sm-12">
            <div class="kpi-card">
                <div class="kpi-label" style="margin-bottom:0.5rem; font-size:1rem; color: #343a40;">Activos por Estado</div>
                   <ul class="list-group list-group-flush status-list-group text-start">
                        <?php if (!empty($activos_por_estado_data_kpi)): ?>
                            <?php foreach (array_slice($activos_por_estado_data_kpi, 0, 3) as $estado_info): // Mostrar solo los top 3 o 4 ?>
                                <li class="list-group-item">
                                    <?= htmlspecialchars($estado_info['estado']) ?>
                                    <span class="badge rounded-pill bg-primary"><?= $estado_info['cantidad'] ?></span>
                                </li>
                            <?php endforeach; ?>
                               <?php if(count($activos_por_estado_data_kpi) > 3) echo "<li class='list-group-item text-muted text-center' style='font-size:0.8em;'>... y otros estados</li>"; ?>
                        <?php else: ?>
                            <li class="list-group-item text-muted">No hay datos de estado.</li>
                        <?php endif; ?>
                   </ul>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-4 col-md-12"> 
            <div class="chart-container">
                <h5>Activos por Tipo (Top <?= $limit_tipos ?>)</h5>
                <div class="chart-canvas-wrapper">
                    <canvas id="graficoTipoActivo"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6"> 
            <div class="chart-container">
                <h5>Activos por Regional (Top 7)</h5>
                   <div class="chart-canvas-wrapper">
                    <canvas id="graficoRegional"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6"> 
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
                <h5>Últimos Activos Registrados</h5>
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
        'rgba(25, 25, 112, 0.8)',   // Midnight Blue
        'rgba(70, 130, 180, 0.8)',  // Steel Blue
        'rgba(100, 149, 237, 0.8)', // Cornflower Blue
        'rgba(173, 216, 230, 0.8)', // Light Blue
        'rgba(119, 136, 153, 0.8)', // Light Slate Gray
        'rgba(128, 128, 128, 0.8)', // Gray
        'rgba(192, 192, 192, 0.8)'  // Silver
    ];
    const defaultBorderColors = defaultChartColors.map(color => color.replace('0.8', '1'));

    // Datos para Gráfico de Tipo de Activo (con tooltip modificado)
    const labelsTipo = <?= json_encode($labels_tipo_activo_new) ?>;
    const dataTipoTotal = <?= json_encode($data_tipo_activo_total) ?>;
    const detailedStatusData = <?= json_encode($detailed_status_data_for_tooltip) ?>;

    if (document.getElementById('graficoTipoActivo') && labelsTipo.length > 0) {
        new Chart(document.getElementById('graficoTipoActivo'), {
            type: 'bar', 
            data: {
                labels: labelsTipo,
                datasets: [{
                    label: 'Cantidad Total', // Etiqueta para la leyenda si se muestra
                    data: dataTipoTotal,
                    backgroundColor: defaultChartColors,
                    borderColor: defaultBorderColors,
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: { 
                    y: { 
                        beginAtZero: true, 
                        ticks: { 
                            stepSize: Math.max(1, Math.ceil(Math.max(...dataTipoTotal) / 10)), // Ajustar stepSize
                            precision: 0 
                        } 
                    } 
                },
                plugins: { 
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let tipoActivo = context.label;
                                let total = context.parsed.y;
                                return `${tipoActivo}: ${total} (Total)`;
                            },
                            footer: function(tooltipItems) {
                                const tipoActivo = tooltipItems[0].label;
                                const statuses = detailedStatusData[tipoActivo];
                                let footerLines = [];
                                if (statuses) {
                                    footerLines.push(''); // Espacio
                                    for (const estado in statuses) {
                                        if (statuses.hasOwnProperty(estado)) {
                                            footerLines.push(`${estado}: ${statuses[estado]}`);
                                        }
                                    }
                                }
                                return footerLines;
                            }
                        }
                    }
                } 
            }
        });
    }

    // Datos para Gráfico de Regional
    const labelsRegional = <?= json_encode($labels_regional) ?>;
    const dataRegional = <?= json_encode($data_regional) ?>;
    if (document.getElementById('graficoRegional') && labelsRegional.length > 0) {
        new Chart(document.getElementById('graficoRegional'), {
            type: 'doughnut',
            data: {
                labels: labelsRegional,
                datasets: [{
                    label: 'Activos',
                    data: dataRegional,
                    backgroundColor: defaultChartColors,
                    borderColor: '#fff',
                    borderWidth: 2,
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { padding: 15, boxWidth: 12 }
                    }
                }
            }
        });
    }

    // <<< NUEVO: Datos para Gráfico de Empresa >>>
    const labelsEmpresa = <?= json_encode($labels_empresa) ?>;
    const dataEmpresa = <?= json_encode($data_empresa) ?>;
    if (document.getElementById('graficoEmpresa') && labelsEmpresa.length > 0) {
        new Chart(document.getElementById('graficoEmpresa'), {
            type: 'pie', // Pie es bueno para pocas categorías
            data: {
                labels: labelsEmpresa,
                datasets: [{
                    label: 'Activos',
                    data: dataEmpresa,
                    backgroundColor: defaultChartColors,
                    borderColor: '#fff',
                    borderWidth: 2,
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { padding: 15, boxWidth: 12 }
                    }
                }
            }
        });
    }
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>