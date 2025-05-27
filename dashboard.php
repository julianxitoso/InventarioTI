<?php
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'backend/auth_check.php';
restringir_acceso_pagina(['admin', 'tecnico', 'auditor']);

require_once 'backend/db.php';
if (!$conn || $conn->connect_error) {
    die("Error crítico de conexión a la base de datos: " . ($conn->connect_error ?? 'Error desconocido'));
}
$conn->set_charset("utf8mb4");

// --- INICIO LÓGICA PARA FILTROS Y AJAX ---
$is_ajax_request = isset($_GET['ajax']) && $_GET['ajax'] == '1';
$filtro_regional_valor = $_GET['filtro_regional'] ?? null;
$filtro_empresa_valor = $_GET['filtro_empresa'] ?? null;
$filtro_tipo_activo_valor = $_GET['filtro_tipo_activo'] ?? null;

$where_clause = " WHERE estado != 'Dado de Baja'";
$params = [];
$types = "";

if ($filtro_regional_valor) {
    $where_clause .= " AND regional = ?";
    $params[] = $filtro_regional_valor;
    $types .= "s";
}
if ($filtro_empresa_valor) {
    $where_clause .= " AND Empresa = ?"; // Asumiendo que la columna es 'Empresa'
    $params[] = $filtro_empresa_valor;
    $types .= "s";
}
if ($filtro_tipo_activo_valor) {
    $where_clause .= " AND tipo_activo = ?";
    $params[] = $filtro_tipo_activo_valor;
    $types .= "s";
}
// --- FIN LÓGICA PARA FILTROS Y AJAX ---


/* --- INICIO DE CONSULTAS SQL PARA EL DASHBOARD (MODIFICADAS PARA FILTROS) --- */

function ejecutarConsultaConFiltro($conexion, $sql_base, $where_clause_con_filtros, $params_filtro, $types_filtro) {
    $sql_completo = $sql_base . $where_clause_con_filtros;
    $stmt = $conexion->prepare($sql_completo);
    if (!$stmt) {
        error_log("Error preparando consulta: " . $conexion->error . " SQL: " . $sql_completo);
        return null;
    }
    if (!empty($params_filtro)) {
        $stmt->bind_param($types_filtro, ...$params_filtro);
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

// 1. Total de Activos Operativos
$result_total = ejecutarConsultaConFiltro($conn, "SELECT COUNT(*) as total FROM activos_tecnologicos", $where_clause, $params, $types);
$total_activos = ($result_total) ? (int)$result_total->fetch_assoc()['total'] : 0;

// 2. Valor Total del Inventario
$result_valor = ejecutarConsultaConFiltro($conn, "SELECT SUM(valor_aproximado) as valor_total FROM activos_tecnologicos", $where_clause, $params, $types);
$valor_total_inventario = ($result_valor) ? (float)$result_valor->fetch_assoc()['valor_total'] : 0;

// 3. Usuarios Únicos con Activos
$sql_usuarios_base = "SELECT COUNT(DISTINCT cedula) as total_usuarios FROM activos_tecnologicos";
$where_usuarios_con_activos = $where_clause . (empty($where_clause) ? " WHERE " : " AND ") . "cedula IS NOT NULL AND cedula != ''";
$result_usuarios = ejecutarConsultaConFiltro($conn, $sql_usuarios_base, $where_usuarios_con_activos, $params, $types);
$total_usuarios_con_activos = ($result_usuarios) ? (int)$result_usuarios->fetch_assoc()['total_usuarios'] : 0;


// 4. Top Estados de Activos
$activos_por_estado_data_kpi = [];
$sql_estado_base = "SELECT estado, COUNT(*) as cantidad FROM activos_tecnologicos";
$sql_estado_group_order = " GROUP BY estado ORDER BY cantidad DESC";
$result_por_estado = ejecutarConsultaConFiltro($conn, $sql_estado_base, $where_clause . $sql_estado_group_order, $params, $types);

if ($result_por_estado) {
    while ($row = $result_por_estado->fetch_assoc()) {
        $activos_por_estado_data_kpi[] = $row;
    }
}

// SI ES UNA SOLICITUD AJAX, DEVOLVEMOS SOLO LOS DATOS KPI EN JSON
if ($is_ajax_request) {
    header('Content-Type: application/json');
    echo json_encode([
        'total_activos' => $total_activos,
        'valor_total_inventario' => $valor_total_inventario,
        'total_usuarios_con_activos' => $total_usuarios_con_activos,
        'activos_por_estado_data_kpi' => $activos_por_estado_data_kpi,
        'filtro_aplicado' => $filtro_regional_valor ?? $filtro_empresa_valor ?? $filtro_tipo_activo_valor ?? 'Ninguno'
    ]);
    $conn->close();
    exit;
}

// --- EL RESTO DE LAS CONSULTAS PARA GRÁFICOS SOLO SE EJECUTAN EN CARGA NORMAL ---
// 5. Datos para Gráfico: Activos por Tipo (estos no se filtran dinámicamente por ahora, pero se podría)
$labels_tipo_activo = [];
$data_tipo_activo = [];
$result_graf_tipo = $conn->query("SELECT tipo_activo, COUNT(*) as cantidad FROM activos_tecnologicos WHERE estado != 'Dado de Baja' GROUP BY tipo_activo ORDER BY cantidad DESC LIMIT 20"); // Ajustado a 20 para consistencia
if ($result_graf_tipo) {
    while ($row = $result_graf_tipo->fetch_assoc()) {
        $labels_tipo_activo[] = $row['tipo_activo'];
        $data_tipo_activo[] = $row['cantidad'];
    }
}

// 6. Datos para Gráfico: Activos por Regional
$labels_regional = [];
$data_regional = [];
$result_graf_regional = $conn->query("SELECT regional, COUNT(*) as cantidad FROM activos_tecnologicos WHERE regional IS NOT NULL AND regional != '' AND estado != 'Dado de Baja' GROUP BY regional ORDER BY cantidad DESC LIMIT 7");
if ($result_graf_regional) {
    while ($row = $result_graf_regional->fetch_assoc()) {
        $labels_regional[] = $row['regional'];
        $data_regional[] = $row['cantidad'];
    }
}

// 7. Datos para Gráfico: Activos por Empresa
$labels_empresa = [];
$data_empresa = [];
$result_graf_empresa = $conn->query("SELECT Empresa, COUNT(*) as cantidad FROM activos_tecnologicos WHERE Empresa IS NOT NULL AND Empresa != '' AND estado != 'Dado de Baja' GROUP BY Empresa ORDER BY cantidad DESC LIMIT 7");
if ($result_graf_empresa) {
    while ($row = $result_graf_empresa->fetch_assoc()) {
        $labels_empresa[] = $row['Empresa'];
        $data_empresa[] = $row['cantidad'];
    }
}

// 8. Últimos 5 Activos Registrados (estos no se filtran dinámicamente)
$ultimos_activos = [];
$result_ultimos = $conn->query("SELECT tipo_activo, marca, serie, fecha_registro, nombre FROM activos_tecnologicos WHERE estado != 'Dado de Baja' ORDER BY id DESC LIMIT 5");
if ($result_ultimos) {
    while($row = $result_ultimos->fetch_assoc()){ $ultimos_activos[] = $row; }
}

function getEstadoColorClass($estado) {
    $estadoLower = strtolower(trim($estado));
    switch ($estadoLower) {
        case 'bueno': return 'bg-success';
        case 'regular': return 'bg-warning';
        case 'malo': return 'bg-danger';
        case 'en mantenimiento': return 'bg-info';
        case 'disponible': case 'en stock': case 'nuevo': return 'bg-primary'; // 'nuevo' añadido
        default: return 'bg-secondary';
    }
}

$conn->close();
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
        .kpi-card .kpi-icon { font-size: 2rem; color: #191970; margin-bottom: 0.5rem; }
        .kpi-card .kpi-value { font-size: 2.25rem; font-weight: 700; color: #1a253c; line-height:1.2; }
        .kpi-card .kpi-label { font-size: 0.9rem; color: #6c757d; margin-top: 0.25rem; }
        .chart-container { background-color: #fff; border-radius: 0.75rem; padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 4px 12px rgba(0,0,0,0.08); border: none; }
        .chart-container h5 { margin-bottom: 1rem; text-align: center; color: #343a40; font-weight: 600;}
        .chart-canvas-wrapper { position: relative; height: 280px; width: 100%; }
        .status-progress-item { margin-bottom: 0.8rem; } .status-progress-item:last-child { margin-bottom: 0; }
        .status-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.3rem; font-size: 0.85em; }
        .status-label { font-weight: 500; color: #495057; } .status-count { font-weight: 600; color: #343a40; }
        .page-header-title { color: #191970; font-weight: 600; cursor: pointer; } /* Añadido cursor pointer */
        .filter-info { font-size: 0.9em; color: #6c757d; margin-bottom: 1rem; text-align: center; }
    </style>
</head>
<body>

<?php
$pagina_actual = basename($_SERVER['PHP_SELF']);
$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Usuario';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'Desconocido';
?>
<div class="top-bar-custom">
    <div class="logo-container-top">
        <a href="menu.php" title="Ir al Menú Principal"> <img src="imagenes/logo.png" alt="Logo Empresa"> </a>
    </div>
    <div class="d-flex align-items-center">
        <span class="text-dark me-3 user-info-top">
            <i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($nombre_usuario_actual_sesion); ?>
            (<?php echo htmlspecialchars(ucfirst($rol_usuario_actual_sesion)); ?>)
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
        <div class="col-xl-3 col-md-6 mb-4"> <div class="kpi-card text-center"> <div class="kpi-icon"><i class="bi bi-laptop"></i></div> <div class="kpi-value" id="kpiTotalActivos"><?php echo $total_activos; ?></div> <div class="kpi-label">Activos Operativos</div> </div> </div>
        <div class="col-xl-3 col-md-6 mb-4"> <div class="kpi-card text-center"> <div class="kpi-icon"><i class="bi bi-cash-coin"></i></div> <div class="kpi-value" id="kpiValorTotal" style="font-size: 1.8rem;">$<?php echo number_format($valor_total_inventario, 0, ',', '.'); ?></div> <div class="kpi-label">Valor Total Inventario</div> </div> </div>
        <div class="col-xl-3 col-md-6 mb-4"> <div class="kpi-card text-center"> <div class="kpi-icon"><i class="bi bi-people-fill"></i></div> <div class="kpi-value" id="kpiTotalUsuarios"><?php echo $total_usuarios_con_activos; ?></div> <div class="kpi-label">Usuarios con Activos</div> </div> </div>
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="kpi-card" style="padding: 1.25rem 1.5rem;">
                <div class="kpi-label text-center" style="margin-top: 0; margin-bottom: 1rem; font-weight: 600;">Estado General Activos</div>
                <div id="kpiEstadoGeneralContainer">
                    <?php /* El contenido se generará por PHP y se actualizará por JS */ ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-4 mb-4"> <div class="chart-container"> <h5>Activos por Tipo (Top 7)</h5> <div class="chart-canvas-wrapper"><canvas id="graficoTipoActivo"></canvas></div> </div> </div>
        <div class="col-lg-4 mb-4"> <div class="chart-container"> <h5>Activos por Regional (Top 7)</h5> <div class="chart-canvas-wrapper"><canvas id="graficoRegional"></canvas></div> </div> </div>
        <div class="col-lg-4 mb-4"> <div class="chart-container"> <h5>Activos por Empresa (Top 7)</h5> <div class="chart-canvas-wrapper"><canvas id="graficoEmpresa"></canvas></div> </div> </div>
    </div>
    
    <?php if(!empty($ultimos_activos)): ?> <div class="row mt-2"> <div class="col-12"> <div class="chart-container">  <h5><i class="bi bi-clock-history"></i> Últimos Activos Registrados</h5> <div class="table-responsive"> <table class="table table-sm table-hover"> <thead><tr><th>Responsable</th><th>Tipo Activo</th><th>Marca</th><th>Serie</th><th>Fecha Registro</th></tr></thead> <tbody><?php foreach($ultimos_activos as $ua): ?><tr><td><?php echo htmlspecialchars($ua['nombre']); ?></td><td><?php echo htmlspecialchars($ua['tipo_activo']); ?></td><td><?php echo htmlspecialchars($ua['marca']); ?></td><td><?php echo htmlspecialchars($ua['serie']); ?></td><td><?php echo htmlspecialchars(date("d/m/Y", strtotime($ua['fecha_registro']))); ?></td></tr><?php endforeach; ?></tbody> </table> </div> </div> </div> </div> <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const defaultChartColors = [ '#191970', '#4682B4', '#6495ED', '#B0C4DE', '#778899', '#708090', '#C0C0C0' ];
    const kpiEstadoGeneralContainer = document.getElementById('kpiEstadoGeneralContainer');
    const kpiTotalActivosEl = document.getElementById('kpiTotalActivos');
    const kpiValorTotalEl = document.getElementById('kpiValorTotal');
    const kpiTotalUsuariosEl = document.getElementById('kpiTotalUsuarios');
    const filterInfoMessageEl = document.getElementById('filterInfoMessage');
    const currentFilterValueEl = document.getElementById('currentFilterValue');
    const resetFilterBtn = document.getElementById('resetFilterBtn');
    const dashboardTitleEl = document.getElementById('dashboardTitle');

    // Función para renderizar los KPIs de estado general
    function renderEstadoGeneralKPI(estadosData, totalActivosFiltrados) {
        kpiEstadoGeneralContainer.innerHTML = ''; // Limpiar
        if (estadosData && estadosData.length > 0) {
            let count = 0;
            estadosData.forEach(estadoInfo => {
                if (++count > 3 && estadosData.length > 4) return; // Mostrar solo top 3 o todos si son 3 o menos
                const cantidad = parseInt(estadoInfo.cantidad);
                const estado = estadoInfo.estado;
                const porcentaje = (totalActivosFiltrados > 0) ? Math.round((cantidad / totalActivosFiltrados) * 100) : 0;
                const colorClass = getEstadoColorClassJS(estado);

                const itemDiv = document.createElement('div');
                itemDiv.className = 'status-progress-item';
                itemDiv.innerHTML = `
                    <div class="status-header">
                        <span class="status-label">${escapeHtml(estado)}</span>
                        <span class="status-count">${cantidad}</span>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar ${colorClass}" role="progressbar" style="width: ${porcentaje}%;" aria-valuenow="${porcentaje}" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                `;
                kpiEstadoGeneralContainer.appendChild(itemDiv);
            });
        } else {
            kpiEstadoGeneralContainer.innerHTML = '<p class="text-muted text-center">No hay datos de estado.</p>';
        }
    }
    
    // Función para escapar HTML en JS (simple)
    function escapeHtml(unsafe) {
        if (unsafe === null || typeof unsafe === 'undefined') return '';
        return unsafe
             .toString()
             .replace(/&/g, "&amp;")
             .replace(/</g, "&lt;")
             .replace(/>/g, "&gt;")
             .replace(/"/g, "&quot;")
             .replace(/'/g, "&#039;");
    }

    // Función JS para obtener clase de color (similar a la de PHP)
    function getEstadoColorClassJS(estado) {
        if (!estado) return 'bg-secondary';
        const estadoLower = estado.toLowerCase().trim();
        switch (estadoLower) {
            case 'bueno': return 'bg-success';
            case 'regular': return 'bg-warning';
            case 'malo': return 'bg-danger';
            case 'en mantenimiento': return 'bg-info';
            case 'disponible': case 'en stock': case 'nuevo': return 'bg-primary';
            default: return 'bg-secondary';
        }
    }
    
    // Cargar estado general inicial (ya que se movió de PHP a JS)
    renderEstadoGeneralKPI(<?php echo json_encode($activos_por_estado_data_kpi); ?>, <?php echo $total_activos; ?>);


    // Función para actualizar los KPIs
    function updateKPIs(filterType = null, filterValue = null) {
        let url = 'dashboard.php?ajax=1';
        if (filterType && filterValue) {
            url += `&filtro_${filterType}=${encodeURIComponent(filterValue)}`;
            filterInfoMessageEl.style.display = 'block';
            currentFilterValueEl.textContent = `${filterType.charAt(0).toUpperCase() + filterType.slice(1)}: ${filterValue}`;
        } else {
            filterInfoMessageEl.style.display = 'none';
        }

        // Mostrar algún indicador de carga (opcional, más avanzado)
        kpiTotalActivosEl.textContent = '...';
        kpiValorTotalEl.textContent = '...';
        kpiTotalUsuariosEl.textContent = '...';
        kpiEstadoGeneralContainer.innerHTML = '<p class="text-muted text-center">Cargando...</p>';

        fetch(url)
            .then(response => response.json())
            .then(data => {
                kpiTotalActivosEl.textContent = data.total_activos;
                kpiValorTotalEl.textContent = '$' + parseFloat(data.valor_total_inventario).toLocaleString('es-CO', { maximumFractionDigits: 0 });
                kpiTotalUsuariosEl.textContent = data.total_usuarios_con_activos;
                renderEstadoGeneralKPI(data.activos_por_estado_data_kpi, data.total_activos);
            })
            .catch(error => {
                console.error('Error al actualizar KPIs:', error);
                filterInfoMessageEl.style.display = 'block';
                currentFilterValueEl.textContent = `Error al cargar datos filtrados.`;
                // Opcional: revertir a los datos originales o mostrar un mensaje de error en los KPIs
            });
    }
    
    // Event listener para limpiar filtros
    if(resetFilterBtn) resetFilterBtn.addEventListener('click', () => updateKPIs());
    if(dashboardTitleEl) dashboardTitleEl.addEventListener('click', () => updateKPIs());


    // Función para manejar clic en gráficos
    function handleChartClick(event, elements, chartInstance, filterType) {
        if (elements.length > 0) {
            const clickedElementIndex = elements[0].index;
            const filterValue = chartInstance.data.labels[clickedElementIndex];
            updateKPIs(filterType, filterValue);
        }
    }

    // Configuración Gráfico: Activos por Tipo
    const labelsTipo = <?php echo json_encode($labels_tipo_activo); ?>; 
    const dataTipo = <?php echo json_encode($data_tipo_activo); ?>;
    const ctxTipo = document.getElementById('graficoTipoActivo');
    if (ctxTipo && labelsTipo.length > 0) {
        const graficoTipoActivo = new Chart(ctxTipo, {
            type: 'bar',
            data: { labels: labelsTipo, datasets: [{ label: 'Cantidad', data: dataTipo, backgroundColor: defaultChartColors }] },
            options: {
                responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
                onClick: (event, elements) => handleChartClick(event, elements, graficoTipoActivo, 'tipo_activo')
            }
        });
    }
    
    // Configuración Gráfico: Activos por Regional
    const labelsRegional = <?php echo json_encode($labels_regional); ?>; 
    const dataRegional = <?php echo json_encode($data_regional); ?>;
    const ctxRegional = document.getElementById('graficoRegional');
    if (ctxRegional && labelsRegional.length > 0) {
        const graficoRegional = new Chart(ctxRegional, {
            type: 'bar',
            data: { labels: labelsRegional, datasets: [{ label: 'Cantidad', data: dataRegional, backgroundColor: defaultChartColors[1] }] }, // Usar un color diferente
            options: {
                responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
                onClick: (event, elements) => handleChartClick(event, elements, graficoRegional, 'regional')
            }
        });
    }
    
    // Configuración Gráfico: Activos por Empresa
    const labelsEmpresa = <?php echo json_encode($labels_empresa); ?>; 
    const dataEmpresa = <?php echo json_encode($data_empresa); ?>;
    const ctxEmpresa = document.getElementById('graficoEmpresa');
    if (ctxEmpresa && labelsEmpresa.length > 0) {
        const graficoEmpresa = new Chart(ctxEmpresa, {
            type: 'pie',
            data: { labels: labelsEmpresa, datasets: [{ label: 'Activos', data: dataEmpresa, backgroundColor: defaultChartColors, borderColor: '#fff', borderWidth: 2 }] },
            options: {
                responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } },
                onClick: (event, elements) => handleChartClick(event, elements, graficoEmpresa, 'empresa')
            }
        });
    }
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>