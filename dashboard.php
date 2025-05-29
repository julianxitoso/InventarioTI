<?php
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'backend/auth_check.php';
restringir_acceso_pagina(['admin', 'tecnico', 'auditor']);

require_once 'backend/db.php';
if (!isset($conexion)) { // Si db.php define $conn y no $conexion
    if (isset($conn)) $conexion = $conn;
    else die("Variable de conexión no definida.");
}

if (!$conexion || (method_exists($conexion, 'connect_error') && $conexion->connect_error)) {
    error_log("Error de conexión a la base de datos en dashboard.php: " . ($conexion->connect_error ?? 'Error desconocido'));
    die("Error crítico de conexión a la base de datos. Por favor, contacte al administrador.");
}
$conexion->set_charset("utf8mb4");

// --- INICIO LÓGICA PARA FILTROS Y AJAX ---
$is_ajax_request = isset($_GET['ajax']) && $_GET['ajax'] == '1';
$filtro_regional_valor = $_GET['filtro_regional'] ?? null;
$filtro_empresa_valor = $_GET['filtro_empresa'] ?? null;
$filtro_tipo_activo_valor = $_GET['filtro_tipo_activo'] ?? null;

$kpi_where_conditions = [];
$kpi_params = [];
$kpi_types = "";

// Siempre excluimos los dados de baja de activos_tecnologicos (alias 'a')
$kpi_where_conditions[] = "a.estado != 'Dado de Baja'";

if ($filtro_regional_valor) {
    $kpi_where_conditions[] = "u.regional = ?"; // Filtra por la regional del usuario
    $kpi_params[] = $filtro_regional_valor;
    $kpi_types .= "s";
}
if ($filtro_empresa_valor) {
    $kpi_where_conditions[] = "u.empresa = ?"; // Filtra por la empresa del usuario
    $kpi_params[] = $filtro_empresa_valor;
    $kpi_types .= "s";
}
if ($filtro_tipo_activo_valor) {
    $kpi_where_conditions[] = "ta.nombre_tipo_activo = ?"; // Filtra por el nombre del tipo de activo
    $kpi_params[] = $filtro_tipo_activo_valor;
    $kpi_types .= "s";
}

$kpi_where_clause = "";
if (!empty($kpi_where_conditions)) {
    $kpi_where_clause = " WHERE " . implode(" AND ", $kpi_where_conditions);
}
// --- FIN LÓGICA PARA FILTROS Y AJAX ---

function ejecutarConsultaConFiltro($conexion_db, $sql_base_select_from_joins, $where_clause_con_filtros, $params_filtro, $types_filtro, $group_order_suffix = "") {
    $sql_completo = $sql_base_select_from_joins . $where_clause_con_filtros . $group_order_suffix;
    $stmt = $conexion_db->prepare($sql_completo);
    if (!$stmt) {
        error_log("Error preparando consulta: " . $conexion_db->error . " SQL: " . $sql_completo);
        return null;
    }
    if (!empty($params_filtro) && !empty($types_filtro)) {
        if (!$stmt->bind_param($types_filtro, ...$params_filtro)) {
             error_log("Error en bind_param: " . $stmt->error . " SQL: " . $sql_completo . " Params: " . json_encode($params_filtro) . " Types: " . $types_filtro);
             $stmt->close();
             return null;
        }
    }
    if (!$stmt->execute()) {
        error_log("Error ejecutando consulta: " . $stmt->error . " SQL: " . $sql_completo);
        $stmt->close();
        return null;
    }
    $result = $stmt->get_result();
    $stmt->close();
    return $result;
}

// --- Base FROM y JOINs para la mayoría de las consultas de KPI y gráficos ---
$sql_from_joins_base = "FROM activos_tecnologicos a 
                        LEFT JOIN usuarios u ON a.id_usuario_responsable = u.id 
                        LEFT JOIN tipos_activo ta ON a.id_tipo_activo = ta.id_tipo_activo
                        LEFT JOIN cargos c ON u.id_cargo = c.id_cargo "; // Añadido JOIN a cargos por si se necesita en el futuro

// --- KPIs ---
$result_total = ejecutarConsultaConFiltro($conexion, "SELECT COUNT(a.id) as total " . $sql_from_joins_base, $kpi_where_clause, $kpi_params, $kpi_types);
$total_activos = ($result_total) ? (int)$result_total->fetch_assoc()['total'] : 0;

$result_valor = ejecutarConsultaConFiltro($conexion, "SELECT SUM(a.valor_aproximado) as valor_total " . $sql_from_joins_base, $kpi_where_clause, $kpi_params, $kpi_types);
$valor_total_inventario = ($result_valor) ? (float)$result_valor->fetch_assoc()['valor_total'] : 0;

// Para total_usuarios_con_activos, necesitamos asegurarnos que contamos solo usuarios distintos que tienen activos que cumplen el filtro
$sql_usuarios_base_select = "SELECT COUNT(DISTINCT a.id_usuario_responsable) as total_usuarios " . $sql_from_joins_base;
// $kpi_where_clause ya filtra por a.estado != 'Dado de Baja', lo que es suficiente.
// Si no hay otros filtros, kpi_where_clause será "WHERE a.estado != 'Dado de Baja'"
// Si hay otros filtros, será "WHERE a.estado != 'Dado de Baja' AND u.regional = ? ..."
$result_usuarios = ejecutarConsultaConFiltro($conexion, $sql_usuarios_base_select, $kpi_where_clause, $kpi_params, $kpi_types);
$total_usuarios_con_activos = ($result_usuarios) ? (int)$result_usuarios->fetch_assoc()['total_usuarios'] : 0;

$activos_por_estado_data_kpi = [];
$sql_estado_base_select = "SELECT a.estado, COUNT(a.id) as cantidad " . $sql_from_joins_base;
$sql_estado_group_order = " GROUP BY a.estado ORDER BY cantidad DESC";
$result_por_estado = ejecutarConsultaConFiltro($conexion, $sql_estado_base_select, $kpi_where_clause . $sql_estado_group_order, $kpi_params, $kpi_types);
if ($result_por_estado) {
    while ($row = $result_por_estado->fetch_assoc()) {
        $activos_por_estado_data_kpi[] = $row;
    }
}

// --- DATOS PARA GRÁFICOS (Carga inicial de datos globales) ---
$labels_tipo_activo_global = []; $data_tipo_activo_global = [];
$result_graf_tipo_g = $conexion->query(
    "SELECT ta.nombre_tipo_activo, COUNT(a.id) as cantidad 
     FROM activos_tecnologicos a
     LEFT JOIN tipos_activo ta ON a.id_tipo_activo = ta.id_tipo_activo
     WHERE a.estado != 'Dado de Baja' 
     GROUP BY ta.nombre_tipo_activo 
     ORDER BY cantidad DESC LIMIT 20"
);
if ($result_graf_tipo_g) {
    while ($row = $result_graf_tipo_g->fetch_assoc()) {
        $labels_tipo_activo_global[] = $row['nombre_tipo_activo'];
        $data_tipo_activo_global[] = $row['cantidad'];
    }
}

$labels_regional_global = []; $data_regional_global = [];
$result_graf_regional_g = $conexion->query(
    "SELECT u.regional, COUNT(a.id) as cantidad 
     FROM activos_tecnologicos a
     LEFT JOIN usuarios u ON a.id_usuario_responsable = u.id
     WHERE u.regional IS NOT NULL AND u.regional != '' AND a.estado != 'Dado de Baja' 
     GROUP BY u.regional 
     ORDER BY cantidad DESC LIMIT 20"
);
if ($result_graf_regional_g) {
    while ($row = $result_graf_regional_g->fetch_assoc()) {
        $labels_regional_global[] = $row['regional'];
        $data_regional_global[] = $row['cantidad'];
    }
}

$labels_empresa_global = []; $data_empresa_global = [];
$result_graf_empresa_g = $conexion->query(
    "SELECT u.empresa AS Empresa, COUNT(a.id) as cantidad 
     FROM activos_tecnologicos a
     LEFT JOIN usuarios u ON a.id_usuario_responsable = u.id
     WHERE u.empresa IS NOT NULL AND u.empresa != '' AND a.estado != 'Dado de Baja' 
     GROUP BY u.empresa 
     ORDER BY cantidad DESC LIMIT 20"
);
if ($result_graf_empresa_g) {
    while ($row = $result_graf_empresa_g->fetch_assoc()) {
        $labels_empresa_global[] = $row['Empresa'];
        $data_empresa_global[] = $row['cantidad'];
    }
}


if ($is_ajax_request) {
    $response_data = [
        'total_activos' => $total_activos,
        'valor_total_inventario' => $valor_total_inventario,
        'total_usuarios_con_activos' => $total_usuarios_con_activos,
        'activos_por_estado_data_kpi' => $activos_por_estado_data_kpi,
        'filtro_aplicado_texto' => $filtro_regional_valor ?? $filtro_empresa_valor ?? $filtro_tipo_activo_valor ?? 'Ninguno'
    ];

    if ($filtro_regional_valor || $filtro_empresa_valor || $filtro_tipo_activo_valor) {
        $labels_tipo_filtrado = []; $data_tipo_filtrado = [];
        $sql_tipo_filtrado_base_select = "SELECT ta.nombre_tipo_activo, COUNT(a.id) as cantidad " . $sql_from_joins_base;
        $result_graf_tipo_f = ejecutarConsultaConFiltro($conexion, $sql_tipo_filtrado_base_select, $kpi_where_clause . " GROUP BY ta.nombre_tipo_activo ORDER BY cantidad DESC LIMIT 20", $kpi_params, $kpi_types);
        if ($result_graf_tipo_f) {
            while ($row = $result_graf_tipo_f->fetch_assoc()) {
                $labels_tipo_filtrado[] = $row['nombre_tipo_activo'];
                $data_tipo_filtrado[] = $row['cantidad'];
            }
        }
        $response_data['labels_tipo_activo_dinamico'] = $labels_tipo_filtrado;
        $response_data['data_tipo_activo_dinamico'] = $data_tipo_filtrado;

        $labels_regional_filtrado = []; $data_regional_filtrado = [];
        $sql_regional_filtrado_base_select = "SELECT u.regional, COUNT(a.id) as cantidad " . $sql_from_joins_base;
        $result_graf_regional_f = ejecutarConsultaConFiltro($conexion, $sql_regional_filtrado_base_select, $kpi_where_clause . " AND u.regional IS NOT NULL AND u.regional != '' GROUP BY u.regional ORDER BY cantidad DESC LIMIT 20", $kpi_params, $kpi_types);
        if ($result_graf_regional_f) {
            while ($row = $result_graf_regional_f->fetch_assoc()) {
                $labels_regional_filtrado[] = $row['regional'];
                $data_regional_filtrado[] = $row['cantidad'];
            }
        }
        $response_data['labels_regional_dinamico'] = $labels_regional_filtrado;
        $response_data['data_regional_dinamico'] = $data_regional_filtrado;
    } else { 
        $response_data['labels_tipo_activo_dinamico'] = $labels_tipo_activo_global;
        $response_data['data_tipo_activo_dinamico'] = $data_tipo_activo_global;
        $response_data['labels_regional_dinamico'] = $labels_regional_global;
        $response_data['data_regional_dinamico'] = $data_regional_global;
    }
    // El gráfico de empresa no se filtra dinámicamente en esta lógica AJAX, siempre usa global
    $response_data['labels_empresa_dinamico'] = $labels_empresa_global;
    $response_data['data_empresa_dinamico'] = $data_empresa_global;


    header('Content-Type: application/json');
    echo json_encode($response_data);
    $conexion->close();
    exit;
}

$labels_tipo_activo = $labels_tipo_activo_global;
$data_tipo_activo = $data_tipo_activo_global;
$labels_regional = $labels_regional_global;
$data_regional = $data_regional_global;
$labels_empresa = $labels_empresa_global; 
$data_empresa = $data_empresa_global;


$ultimos_activos = [];
// --- CAMBIO: Consulta de últimos activos con JOINs ---
$sql_ultimos = "SELECT 
                    ta.nombre_tipo_activo, 
                    a.marca, 
                    a.serie, 
                    a.fecha_registro, 
                    u.nombre_completo AS nombre_responsable 
                FROM activos_tecnologicos a
                LEFT JOIN tipos_activo ta ON a.id_tipo_activo = ta.id_tipo_activo
                LEFT JOIN usuarios u ON a.id_usuario_responsable = u.id
                WHERE a.estado != 'Dado de Baja' 
                ORDER BY a.id DESC LIMIT 5";
$result_ultimos = $conexion->query($sql_ultimos);
if ($result_ultimos) {
    while($row = $result_ultimos->fetch_assoc()){ $ultimos_activos[] = $row; }
}
// --- FIN CAMBIO ---


function getEstadoColorClass($estado) { 
    $estadoLower = strtolower(trim($estado));
    switch ($estadoLower) {
        case 'bueno': return 'bg-success'; case 'regular': return 'bg-warning text-dark'; case 'malo': return 'bg-danger';
        case 'en mantenimiento': return 'bg-info text-dark'; case 'disponible': case 'en stock': case 'nuevo': return 'bg-primary';
        default: return 'bg-secondary';
    }
}
$conexion->close(); 
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard de Activos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.7.0/dist/chart.min.js"></script>
    <style>
        body { background-color: #f0f2f5 !important; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding-top: 90px; }
        .top-bar-custom { position: fixed; top: 0; left: 0; right: 0; z-index: 1030; display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 1.5rem; background-color: #f8f9fa; border-bottom: 1px solid #dee2e6; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .logo-container-top img { width: auto; height: 75px; object-fit: contain; }
        .user-info-top { font-size: 0.9rem; color: #333; }
        .kpi-card { background-color: #fff; border-radius: 0.75rem; padding: 1.25rem; margin-bottom: 1.5rem; box-shadow: 0 4px 12px rgba(0,0,0,0.08); border: none; display: flex; flex-direction: column; justify-content: center; min-height: 140px; }
        .kpi-card .kpi-icon { font-size: 2rem; margin-bottom: 0.5rem; }
        .kpi-card .kpi-value { font-size: 2.25rem; font-weight: 700; line-height:1.2; }
        .kpi-card .kpi-label { font-size: 0.9rem; color: #6c757d; margin-top: 0.25rem; }
        .chart-container { background-color: #fff; border-radius: 0.75rem; padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 4px 12px rgba(0,0,0,0.08); border: none; }
        .chart-container h5 { margin-bottom: 1rem; text-align: center; color: #343a40; font-weight: 600;}
        .chart-canvas-wrapper { position: relative; height: 280px; width: 100%; }
        .status-progress-item { margin-bottom: 0.8rem; } .status-progress-item:last-child { margin-bottom: 0; }
        .status-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.3rem; font-size: 0.85em; }
        .status-label { font-weight: 500; color: #495057; } .status-count { font-weight: 600; color: #343a40; }
        .page-header-title { color: #191970; font-weight: 600; cursor: pointer; text-align:center; }
        .filter-info { font-size: 0.9em; color: #6c757d; margin-bottom: 1rem; text-align: center; }
        .kpi-icon-azul-oscuro { color: #191970; } .kpi-value-azul-oscuro { color: #191970; }
        .kpi-icon-rojo { color: #D52B1E; } .kpi-value-rojo { color: #D52B1E; }
        .kpi-icon-cyan { color: #00ACC1; } .kpi-value-cyan { color: #00ACC1; }
        .kpi-icon-gris { color: #6c757d; } .kpi-value-gris { color: #6c757d; }
    </style>
</head>
<body>

<div class="top-bar-custom">
    <div class="logo-container-top">
        <a href="menu.php" title="Ir al Menú Principal"> <img src="imagenes/logo.png" alt="Logo Empresa"> </a>
    </div>
    <div class="d-flex align-items-center">
        <span class="text-dark me-3 user-info-top">
            <i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($_SESSION['nombre_usuario_completo'] ?? 'Usuario'); ?>
            (<?php echo htmlspecialchars(ucfirst($_SESSION['rol_usuario'] ?? 'Desconocido')); ?>)
        </span>
        <form action="logout.php" method="post" class="d-flex">
            <button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-box-arrow-right"></i> Salir</button>
        </form>
    </div>
</div>

<div class="container-fluid mt-4 px-lg-4 px-2"> 
    <h3 class="mb-1 page-header-title" id="dashboardTitle">Resumen del Inventario</h3>
    <div class="filter-info" id="filterInfoMessage" style="display: none;">Filtrando por: <strong id="currentFilterValue"></strong> <button class="btn btn-sm btn-link p-0" id="resetFilterBtn">(Limpiar)</button></div>

    <div class="row">
        <div class="col-xl-3 col-md-6 mb-4"> <div class="kpi-card text-center"> <div class="kpi-icon kpi-icon-azul-oscuro"><i class="bi bi-laptop"></i></div> <div class="kpi-value kpi-value-azul-oscuro" id="kpiTotalActivos"><?php echo $total_activos; ?></div> <div class="kpi-label">Activos Operativos</div> </div> </div>
        <div class="col-xl-3 col-md-6 mb-4"> <div class="kpi-card text-center"> <div class="kpi-icon kpi-icon-rojo"><i class="bi bi-cash-coin"></i></div> <div class="kpi-value kpi-value-rojo" id="kpiValorTotal" style="font-size: 1.8rem;">$<?php echo number_format($valor_total_inventario, 0, ',', '.'); ?></div> <div class="kpi-label">Valor Total Inventario</div> </div> </div>
        <div class="col-xl-3 col-md-6 mb-4"> <div class="kpi-card text-center"> <div class="kpi-icon kpi-icon-cyan"><i class="bi bi-people-fill"></i></div> <div class="kpi-value kpi-value-cyan" id="kpiTotalUsuarios"><?php echo $total_usuarios_con_activos; ?></div> <div class="kpi-label">Usuarios con Activos</div> </div> </div>
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="kpi-card" style="padding: 1.25rem 1.5rem;">
                <div class="kpi-label text-center" style="margin-top: 0; margin-bottom: 1rem; font-weight: 600; color:#6c757d;">Estado General Activos</div>
                <div id="kpiEstadoGeneralContainer"></div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-4 mb-4"> <div class="chart-container"> <h5>Activos por Tipo</h5> <div class="chart-canvas-wrapper"><canvas id="graficoTipoActivo"></canvas></div> </div> </div>
        <div class="col-lg-4 mb-4"> <div class="chart-container"> <h5>Activos por Regional (Responsable)</h5> <div class="chart-canvas-wrapper"><canvas id="graficoRegional"></canvas></div> </div> </div>
        <div class="col-lg-4 mb-4"> <div class="chart-container"> <h5>Activos por Empresa (Responsable)</h5> <div class="chart-canvas-wrapper"><canvas id="graficoEmpresa"></canvas></div> </div> </div>
    </div>
    
    <?php if(!empty($ultimos_activos)): ?> 
    <div class="row mt-2"> 
        <div class="col-12"> 
            <div class="chart-container"> 
                <h5><i class="bi bi-clock-history"></i> Últimos Activos Registrados</h5> 
                <div class="table-responsive"> 
                    <table class="table table-sm table-hover"> 
                        <thead><tr><th>Responsable</th><th>Tipo Activo</th><th>Marca</th><th>Serie</th><th>Fecha Registro</th></tr></thead> 
                        <tbody>
                            <?php foreach($ultimos_activos as $ua): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($ua['nombre_responsable'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($ua['nombre_tipo_activo'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($ua['marca'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($ua['serie'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars(date("d/m/Y", strtotime($ua['fecha_registro']))); ?></td>
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
    const logoColorPalette = ['#191970','#D52B1E','#00ACC1','#5A7BBF','#E88A84','#6c757d','#A0B8F0','#2E4374'];
    const colorParaArpesod = '#D52B1E'; 
    const colorParaFinansuenos = '#191970';

    const kpiEstadoGeneralContainer = document.getElementById('kpiEstadoGeneralContainer');
    const kpiTotalActivosEl = document.getElementById('kpiTotalActivos');
    const kpiValorTotalEl = document.getElementById('kpiValorTotal');
    const kpiTotalUsuariosEl = document.getElementById('kpiTotalUsuarios');
    const filterInfoMessageEl = document.getElementById('filterInfoMessage');
    const currentFilterValueEl = document.getElementById('currentFilterValue');
    const resetFilterBtn = document.getElementById('resetFilterBtn');
    const dashboardTitleEl = document.getElementById('dashboardTitle');

    let graficoTipoActivoInstance = null;
    let graficoRegionalInstance = null;
    let graficoEmpresaInstance = null;

    const initialLabelsTipo = <?php echo json_encode($labels_tipo_activo); ?>;
    const initialDataTipo = <?php echo json_encode($data_tipo_activo); ?>;
    const initialLabelsRegional = <?php echo json_encode($labels_regional); ?>;
    const initialDataRegional = <?php echo json_encode($data_regional); ?>;
    const initialLabelsEmpresa = <?php echo json_encode($labels_empresa); ?>; // Para el gráfico de empresa
    const initialDataEmpresa = <?php echo json_encode($data_empresa); ?>;   // Para el gráfico de empresa


    function renderEstadoGeneralKPI(estadosData, totalActivosFiltrados) { 
        kpiEstadoGeneralContainer.innerHTML = '';
        if (estadosData && estadosData.length > 0 && totalActivosFiltrados > 0) { // Asegurar que totalActivosFiltrados > 0
            let count = 0;
            estadosData.sort((a, b) => b.cantidad - a.cantidad); 
            estadosData.forEach(estadoInfo => {
                if (++count > 4 && estadosData.length > 4) return; 
                const cantidad = parseInt(estadoInfo.cantidad);
                const estado = estadoInfo.estado;
                const porcentaje = Math.round((cantidad / totalActivosFiltrados) * 100);
                const colorClass = getEstadoColorClassJS(estado);
                const itemDiv = document.createElement('div');
                itemDiv.className = 'status-progress-item';
                itemDiv.innerHTML = `<div class="status-header"><span class="status-label">${escapeHtml(estado)}</span><span class="status-count">${cantidad}</span></div><div class="progress" style="height: 8px;"><div class="progress-bar ${colorClass}" role="progressbar" style="width: ${porcentaje}%;" aria-valuenow="${porcentaje}" aria-valuemin="0" aria-valuemax="100"></div></div>`;
                kpiEstadoGeneralContainer.appendChild(itemDiv);
            });
        } else {
            kpiEstadoGeneralContainer.innerHTML = '<p class="text-muted text-center">No hay datos de estado.</p>';
        }
    }
    function escapeHtml(unsafe) { 
        if (unsafe === null || typeof unsafe === 'undefined') return '';
        return unsafe.toString().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }
    function getEstadoColorClassJS(estado) { 
        if (!estado) return 'bg-secondary';
        const estadoLower = estado.toLowerCase().trim();
        switch (estadoLower) {
            case 'bueno': return 'bg-success'; case 'regular': return 'bg-warning'; case 'malo': return 'bg-danger';
            case 'en mantenimiento': return 'bg-info'; case 'disponible': case 'en stock': case 'nuevo': return 'bg-primary';
            default: return 'bg-secondary';
        }
    }
    renderEstadoGeneralKPI(<?php echo json_encode($activos_por_estado_data_kpi); ?>, <?php echo $total_activos; ?>);

    function updateDashboardData(filterType = null, filterValue = null) {
        let url = 'dashboard.php?ajax=1';
        let filterText = 'General';
        if (filterType && filterValue) {
            url += `&filtro_${filterType}=${encodeURIComponent(filterValue)}`;
            filterText = `${filterType.charAt(0).toUpperCase() + filterType.slice(1)}: ${filterValue}`;
        }
        
        if(filterInfoMessageEl && currentFilterValueEl) {
            if (filterType && filterValue) {
                filterInfoMessageEl.style.display = 'block';
                currentFilterValueEl.textContent = filterText;
            } else {
                filterInfoMessageEl.style.display = 'none';
            }
        }


        kpiTotalActivosEl.textContent = '...'; kpiValorTotalEl.textContent = '...'; kpiTotalUsuariosEl.textContent = '...';
        kpiEstadoGeneralContainer.innerHTML = '<p class="text-muted text-center">Cargando...</p>';
        
        // Limpiar datos de gráficos antes de la llamada AJAX
        if(graficoTipoActivoInstance) { 
            graficoTipoActivoInstance.data.labels = [];
            graficoTipoActivoInstance.data.datasets[0].data = []; 
            graficoTipoActivoInstance.update(); 
        }
        if(graficoRegionalInstance) { 
            graficoRegionalInstance.data.labels = [];
            graficoRegionalInstance.data.datasets[0].data = []; 
            graficoRegionalInstance.update(); 
        }
        // El gráfico de empresa no se actualiza dinámicamente por ahora, se mantiene con datos globales.

        fetch(url)
            .then(response => {
                if (!response.ok) { throw new Error(`Error HTTP: ${response.status}`); }
                return response.json();
            })
            .then(data => {
                if (data.error) {
                    console.error("Error desde el backend:", data.error, data.details || '');
                    if (filterInfoMessageEl && currentFilterValueEl) {
                         filterInfoMessageEl.style.display = 'block';
                         currentFilterValueEl.textContent = `Error al cargar datos.`;
                    }
                    return;
                }

                kpiTotalActivosEl.textContent = data.total_activos;
                kpiValorTotalEl.textContent = '$' + parseFloat(data.valor_total_inventario).toLocaleString('es-CO', { maximumFractionDigits: 0 });
                kpiTotalUsuariosEl.textContent = data.total_usuarios_con_activos;
                renderEstadoGeneralKPI(data.activos_por_estado_data_kpi, data.total_activos);

                if (graficoTipoActivoInstance && data.labels_tipo_activo_dinamico && data.data_tipo_activo_dinamico) {
                    graficoTipoActivoInstance.data.labels = data.labels_tipo_activo_dinamico;
                    graficoTipoActivoInstance.data.datasets[0].data = data.data_tipo_activo_dinamico;
                    graficoTipoActivoInstance.data.datasets[0].backgroundColor = logoColorPalette.slice(0, data.labels_tipo_activo_dinamico.length);
                    graficoTipoActivoInstance.update();
                }
                if (graficoRegionalInstance && data.labels_regional_dinamico && data.data_regional_dinamico) {
                    graficoRegionalInstance.data.labels = data.labels_regional_dinamico;
                    graficoRegionalInstance.data.datasets[0].data = data.data_regional_dinamico;
                    graficoRegionalInstance.data.datasets[0].backgroundColor = logoColorPalette.slice(0, data.labels_regional_dinamico.length);
                    graficoRegionalInstance.update();
                }
            })
            .catch(error => {
                console.error('Error al actualizar Dashboard:', error);
                if (filterInfoMessageEl && currentFilterValueEl) {
                    filterInfoMessageEl.style.display = 'block';
                    currentFilterValueEl.textContent = `Error al cargar datos.`;
                }
            });
    }
    if(resetFilterBtn) resetFilterBtn.addEventListener('click', () => updateDashboardData());
    if(dashboardTitleEl) dashboardTitleEl.addEventListener('click', () => updateDashboardData()); // Para resetear al hacer clic en el título

    function handleChartClick(event, elements, chartInstance, filterType) {
        if (elements.length > 0) {
            const clickedElementIndex = elements[0].index;
            const filterValue = chartInstance.data.labels[clickedElementIndex];
            updateDashboardData(filterType, filterValue);
        }
    }

    const ctxTipo = document.getElementById('graficoTipoActivo');
    if (ctxTipo && initialLabelsTipo.length > 0) {
        graficoTipoActivoInstance = new Chart(ctxTipo, {
            type: 'bar',
            data: { labels: initialLabelsTipo, datasets: [{ label: 'Cantidad', data: initialDataTipo, backgroundColor: logoColorPalette.slice(0, initialLabelsTipo.length) }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }, onClick: (event, elements) => handleChartClick(event, elements, graficoTipoActivoInstance, 'tipo_activo') }
        });
    }
    
    const ctxRegional = document.getElementById('graficoRegional');
    if (ctxRegional && initialLabelsRegional.length > 0) {
        graficoRegionalInstance = new Chart(ctxRegional, {
            type: 'bar',
            data: { labels: initialLabelsRegional, datasets: [{ label: 'Cantidad', data: initialDataRegional, backgroundColor: logoColorPalette.slice(0, initialLabelsRegional.length) }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }, onClick: (event, elements) => handleChartClick(event, elements, graficoRegionalInstance, 'regional') }
        });
    }
    
    const ctxEmpresa = document.getElementById('graficoEmpresa');
    if (ctxEmpresa && initialLabelsEmpresa.length > 0) {
        const empresaChartColors = initialLabelsEmpresa.map((label, index) => {
            if (label && label.toLowerCase().includes('arpesod')) { return colorParaArpesod; }
            else if (label && label.toLowerCase().includes('finansueños')) { return colorParaFinansuenos; }
            return logoColorPalette[(index + 2) % logoColorPalette.length]; 
        });
        graficoEmpresaInstance = new Chart(ctxEmpresa, { 
            type: 'pie',
            data: { labels: initialLabelsEmpresa, datasets: [{ label: 'Activos', data: initialDataEmpresa, backgroundColor: empresaChartColors, borderColor: '#fff', borderWidth: 2 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } }, onClick: (event, elements) => handleChartClick(event, elements, graficoEmpresaInstance, 'empresa') }
        });
    }
});
</script>
</body>
</html>