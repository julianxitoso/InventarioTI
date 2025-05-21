<?php
session_start();
if (!isset($_SESSION['usuario'])) {
    header("Location: login.php");
    exit;
}

require_once 'backend/db.php'; 
if (isset($conn) && !isset($conexion)) { $conexion = $conn; }
if (!isset($conexion) || !$conexion) { die("Error de conexión a la base de datos."); }

// --- Consultas para KPIs y Gráficos ---

// 1. Cantidad Total de Activos
$result_total_activos = mysqli_query($conexion, "SELECT COUNT(*) as total FROM activos_tecnologicos");
$total_activos = ($result_total_activos) ? mysqli_fetch_assoc($result_total_activos)['total'] : 0;

// (KPI de Valor Estimado ELIMINADO)

// 2. Activos por Estado
$activos_por_estado_data = [];
$result_por_estado = mysqli_query($conexion, "SELECT estado, COUNT(*) as cantidad FROM activos_tecnologicos GROUP BY estado ORDER BY cantidad DESC");
if ($result_por_estado) {
    while ($row = mysqli_fetch_assoc($result_por_estado)) {
        $activos_por_estado_data[] = $row;
    }
}

// 3. Número de Usuarios con Activos
$result_total_usuarios = mysqli_query($conexion, "SELECT COUNT(DISTINCT cedula) as total_usuarios FROM activos_tecnologicos WHERE cedula IS NOT NULL AND cedula != ''");
$total_usuarios_con_activos = ($result_total_usuarios) ? mysqli_fetch_assoc($result_total_usuarios)['total_usuarios'] : 0;

// 4. Datos para Gráfico: Activos por Tipo
$labels_tipo_activo = [];
$data_tipo_activo = [];
// Podrías aumentar el LIMIT si tienes muchos tipos de activo y quieres ver más
$result_graf_tipo = mysqli_query($conexion, "SELECT tipo_activo, COUNT(*) as cantidad FROM activos_tecnologicos GROUP BY tipo_activo ORDER BY cantidad DESC LIMIT 7"); 
if ($result_graf_tipo) {
    while ($row = mysqli_fetch_assoc($result_graf_tipo)) {
        $labels_tipo_activo[] = $row['tipo_activo'];
        $data_tipo_activo[] = $row['cantidad'];
    }
}

// 5. Datos para Gráfico: Activos por Regional
$labels_regional = [];
$data_regional = [];
$result_graf_regional = mysqli_query($conexion, "SELECT regional, COUNT(*) as cantidad FROM activos_tecnologicos GROUP BY regional ORDER BY cantidad DESC LIMIT 7");
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
        .navbar-custom .nav-link:hover { background-color: #8b0000; color: white; }

        .kpi-card {
            background-color: #fff; border-radius: 0.5rem; padding: 1.25rem;
            margin-bottom: 1.5rem; box-shadow: 0 2px 10px rgba(0,0,0,0.075);
            text-align: center; min-height: 130px; display: flex;
            flex-direction: column; justify-content: center;
        }
        .kpi-card .kpi-value { font-size: 2.25rem; font-weight: 700; color: #191970; }
        .kpi-card .kpi-label { font-size: 0.9rem; color: #6c757d; margin-top: 0.25rem; text-transform: uppercase; letter-spacing: 0.5px; }
        
        .chart-container { /* Para los gráficos y la tabla de últimos activos */
            background-color: #fff; border-radius: 0.5rem; padding: 1.5rem;
            margin-bottom: 1.5rem; box-shadow: 0 2px 10px rgba(0,0,0,0.075);
        }
        .chart-container h5 { margin-bottom: 1rem; text-align: center; color: #343a40; font-weight: 600;}
        .chart-canvas-wrapper { /* Nuevo wrapper para controlar altura si es necesario */
             position: relative;
             height: 300px; /* Altura fija para los gráficos, ajusta según necesidad */
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
                <li class="nav-item"><a class="nav-link" href="index.html">Registrar Activo</a></li>
                <li class="nav-item"><a class="nav-link" href="editar.php">Editar Activos</a></li>
                <li class="nav-item"><a class="nav-link" href="buscar.php">Buscar Activos</a></li>
                <li class="nav-item"><a class="nav-link" href="informes.php">Informes</a></li>
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
                    <?php if (!empty($activos_por_estado_data)): ?>
                        <?php foreach (array_slice($activos_por_estado_data, 0, 3) as $estado_info): ?>
                            <li class="list-group-item">
                                <?= htmlspecialchars($estado_info['estado']) ?>
                                <span class="badge rounded-pill bg-primary"><?= $estado_info['cantidad'] ?></span>
                            </li>
                        <?php endforeach; ?>
                         <?php if(count($activos_por_estado_data) > 3) echo "<li class='list-group-item text-muted text-center' style='font-size:0.8em;'>... y otros estados</li>"; ?>
                    <?php else: ?>
                        <li class="list-group-item text-muted">No hay datos de estado.</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-6 col-md-12"> 
            <div class="chart-container">
                <h5>Activos por Tipo (Top 7)</h5>
                <div class="chart-canvas-wrapper">
                    <canvas id="graficoTipoActivo"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-6 col-md-12">
            <div class="chart-container">
                <h5>Activos por Regional (Top 7)</h5>
                 <div class="chart-canvas-wrapper">
                    <canvas id="graficoRegional"></canvas>
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
    const defaultChartColors = [ // Paleta de colores más corporativos/neutros
        'rgba(25, 25, 112, 0.8)',   // Azul Oscuro (principal de tu navbar)
        'rgba(70, 130, 180, 0.8)',  // Azul Acero
        'rgba(100, 149, 237, 0.8)', // Azul Maíz
        'rgba(135, 206, 230, 0.8)', // Azul Cielo Claro
        'rgba(112, 128, 144, 0.8)', // Gris Pizarra
        'rgba(128, 128, 128, 0.8)', // Gris
        'rgba(169, 169, 169, 0.8)'  // Gris Oscuro (para más variedad)
    ];
    const defaultBorderColors = defaultChartColors.map(color => color.replace('0.8', '1'));


    // Datos para Gráfico de Tipo de Activo
    const labelsTipo = <?= json_encode($labels_tipo_activo) ?>;
    const dataTipo = <?= json_encode($data_tipo_activo) ?>;
    if (document.getElementById('graficoTipoActivo') && labelsTipo.length > 0) {
        new Chart(document.getElementById('graficoTipoActivo'), {
            type: 'bar', 
            data: {
                labels: labelsTipo,
                datasets: [{
                    label: 'Cantidad',
                    data: dataTipo,
                    backgroundColor: defaultChartColors,
                    borderColor: defaultBorderColors,
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false, // Permitir que la altura del wrapper controle
                scales: { y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 } } },
                plugins: { legend: { display: false } } 
            }
        });
    }

    // Datos para Gráfico de Regional
    const labelsRegional = <?= json_encode($labels_regional) ?>;
    const dataRegional = <?= json_encode($data_regional) ?>;
    if (document.getElementById('graficoRegional') && labelsRegional.length > 0) {
        new Chart(document.getElementById('graficoRegional'), {
            type: 'doughnut', // Doughnut es visualmente atractivo y ahorra espacio vs pie
            data: {
                labels: labelsRegional,
                datasets: [{
                    label: 'Activos',
                    data: dataRegional,
                    backgroundColor: defaultChartColors,
                    borderColor: '#fff', // Borde blanco para separar segmentos
                    borderWidth: 2,
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false, // Permitir que la altura del wrapper controle
                plugins: {
                    legend: {
                        position: 'bottom', // Leyenda abajo para doughnuts/pies
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