<?php
// Descomentar para depuración si persisten problemas:
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'backend/auth_check.php'; 
restringir_acceso_pagina(['admin', 'tecnico', 'auditor']);

require_once 'backend/db.php';

$conexion_error_msg = null;
if (isset($conn) && !isset($conexion)) {
    $conexion = $conn;
} elseif (!isset($conn) && !isset($conexion)) {
    $servername = "localhost"; $username = "root"; $password = ""; $dbname = "inventario";
    $conexion = new mysqli($servername, $username, $password, $dbname);
    if ($conexion->connect_error) {
        error_log("Fallo de conexión a la BD (dashboard.php fallback): " . $conexion->connect_error);
        $conexion_error_msg = "Error de conexión al servidor. Por favor, intente más tarde.";
    }
}

if ($conexion_error_msg || !isset($conexion) || (method_exists($conexion, 'connect_error') && $conexion->connect_error) || !$conexion) {
    error_log("La variable de conexión a la BD no está disponible o es inválida en dashboard.php.");
    if(!$conexion_error_msg) $conexion_error_msg = "Error crítico de conexión. Contacte al administrador.";
} else {
    $conexion->set_charset("utf8mb4");
}

$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Usuario';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'Desconocido';

$is_ajax_request = isset($_GET['ajax']) && $_GET['ajax'] == '1';
$filtro_regional_valor = $_GET['filtro_regional'] ?? null;
$filtro_empresa_valor = $_GET['filtro_empresa'] ?? null;
$filtro_tipo_activo_valor = $_GET['filtro_tipo_activo'] ?? null;

$global_where_conditions = [];
$global_params = [];
$global_types = "";

if ($filtro_regional_valor) {
    $global_where_conditions[] = "u.regional = ?";
    $global_params[] = $filtro_regional_valor;
    $global_types .= "s";
}
if ($filtro_empresa_valor) {
    $global_where_conditions[] = "u.empresa = ?";
    $global_params[] = $filtro_empresa_valor;
    $global_types .= "s";
}
if ($filtro_tipo_activo_valor) {
    $global_where_conditions[] = "ta.nombre_tipo_activo = ?";
    $global_params[] = $filtro_tipo_activo_valor;
    $global_types .= "s";
}

$global_where_clause_str = "";
if (!empty($global_where_conditions)) {
    $global_where_clause_str = " AND " . implode(" AND ", $global_where_conditions);
}

function ejecutarConsultaConFiltro($conexion_db, $sql_base_select_from_joins, $main_where_condition, $additional_where_conditions_str, $params_filtro, $types_filtro, $group_order_suffix = "") {
    if (!$conexion_db || (method_exists($conexion_db, 'connect_error') && $conexion_db->connect_error)) {
        error_log("ejecutarConsultaConFiltro: Conexión a BD no válida.");
        return null;
    }
    $where_clause_completa = "";
    if (!empty($main_where_condition)) {
        $where_clause_completa = " WHERE " . $main_where_condition;
        if (!empty($additional_where_conditions_str)) {
            $where_clause_completa .= $additional_where_conditions_str;
        }
    } elseif (!empty($additional_where_conditions_str)) {
        $where_clause_completa = " WHERE " . ltrim(trim($additional_where_conditions_str), 'AND ');
    }

    $sql_completo = $sql_base_select_from_joins . $where_clause_completa . $group_order_suffix;
    $stmt = $conexion_db->prepare($sql_completo);
    if (!$stmt) {
        error_log("Error preparando consulta: " . $conexion_db->error . " SQL: " . $sql_completo . " Params: " . print_r($params_filtro, true));
        return null;
    }
    if (!empty($params_filtro) && !empty($types_filtro)) {
        if (strlen($types_filtro) != count($params_filtro)) {
            error_log("Discrepancia en bind_param: Types='{$types_filtro}', NParams=" . count($params_filtro) . " SQL: " . $sql_completo);
            $stmt->close(); return null;
        }
        if (!$stmt->bind_param($types_filtro, ...$params_filtro)) {
            error_log("Error en bind_param: " . $stmt->error . " SQL: " . $sql_completo);
            $stmt->close(); return null;
        }
    }
    if (!$stmt->execute()) {
        error_log("Error ejecutando consulta: " . $stmt->error . " SQL: " . $sql_completo);
        $stmt->close(); return null;
    }
    $result = $stmt->get_result();
    $stmt->close();
    return $result;
}

$sql_from_joins_base = "FROM activos_tecnologicos a 
                        LEFT JOIN usuarios u ON a.id_usuario_responsable = u.id 
                        LEFT JOIN tipos_activo ta ON a.id_tipo_activo = ta.id_tipo_activo
                        LEFT JOIN cargos c ON u.id_cargo = c.id_cargo ";

$total_activos = 0;
$valor_total_inventario = 0;
$total_usuarios_con_activos = 0;
$activos_por_estado_data_kpi = [];

if(!$conexion_error_msg) {
    $result_total = ejecutarConsultaConFiltro($conexion, "SELECT COUNT(a.id) as total " . $sql_from_joins_base, "a.estado != 'Dado de Baja'", $global_where_clause_str, $global_params, $global_types);
    $total_activos_row = $result_total ? $result_total->fetch_assoc() : null;
    $total_activos = $total_activos_row ? (int)$total_activos_row['total'] : 0;

    $result_valor = ejecutarConsultaConFiltro($conexion, "SELECT SUM(a.valor_aproximado) as valor_total " . $sql_from_joins_base, "a.estado != 'Dado de Baja'", $global_where_clause_str, $global_params, $global_types);
    $valor_total_inventario_row = $result_valor ? $result_valor->fetch_assoc() : null;
    $valor_total_inventario = $valor_total_inventario_row ? (float)$valor_total_inventario_row['valor_total'] : 0;

    $sql_usuarios_base_select = "SELECT COUNT(DISTINCT a.id_usuario_responsable) as total_usuarios " . $sql_from_joins_base;
    $result_usuarios = ejecutarConsultaConFiltro($conexion, $sql_usuarios_base_select, "a.estado != 'Dado de Baja'", $global_where_clause_str, $global_params, $global_types);
    $total_usuarios_con_activos_row = $result_usuarios ? $result_usuarios->fetch_assoc() : null;
    $total_usuarios_con_activos = $total_usuarios_con_activos_row ? (int)$total_usuarios_con_activos_row['total_usuarios'] : 0;
    
    $estados_deseados_kpi_array = ['Bueno', 'Regular', 'Malo'];
    $estados_deseados_kpi_sql_in = "'" . implode("', '", $estados_deseados_kpi_array) . "'";
    
    $main_condition_estado_kpi = "a.estado IN ({$estados_deseados_kpi_sql_in})";
    
    $sql_estado_base_select = "SELECT a.estado, COUNT(a.id) as cantidad " . $sql_from_joins_base;
    $sql_estado_group_order = " GROUP BY a.estado ORDER BY FIELD(a.estado, " . $estados_deseados_kpi_sql_in . ")"; 
    
    $result_por_estado = ejecutarConsultaConFiltro($conexion, $sql_estado_base_select, $main_condition_estado_kpi, $global_where_clause_str, $global_params, $global_types, $sql_estado_group_order);
    
    if ($result_por_estado) {
        while ($row = $result_por_estado->fetch_assoc()) {
            $activos_por_estado_data_kpi[] = $row;
        }
    }
    error_log("[Dashboard PHP] KPI Estados Data: " . print_r($activos_por_estado_data_kpi, true)); 
}

$labels_tipo_activo_global = []; $data_tipo_activo_global = [];
$labels_regional_global = []; $data_regional_global = [];
$labels_empresa_global = []; $data_empresa_global = [];

if(!$conexion_error_msg){
    $result_graf_tipo_g = $conexion->query(
        "SELECT COALESCE(ta.nombre_tipo_activo, 'Sin Tipo Asignado') as nombre_tipo_activo, COUNT(a.id) as cantidad 
         FROM activos_tecnologicos a
         LEFT JOIN tipos_activo ta ON a.id_tipo_activo = ta.id_tipo_activo
         WHERE a.estado != 'Dado de Baja' 
         GROUP BY ta.nombre_tipo_activo 
         ORDER BY cantidad DESC LIMIT 20"
    );
    if ($result_graf_tipo_g) {
        while ($row = $result_graf_tipo_g->fetch_assoc()) {
            $labels_tipo_activo_global[] = $row['nombre_tipo_activo'];
            $data_tipo_activo_global[] = (int)$row['cantidad'];
        }
    } else { error_log("Dashboard Error (Tipo Activo Global): " . $conexion->error); }
    error_log("[Dashboard PHP] Global Tipo Labels: " . print_r($labels_tipo_activo_global, true));
    error_log("[Dashboard PHP] Global Tipo Data: " . print_r($data_tipo_activo_global, true));


    $result_graf_regional_g = $conexion->query(
        "SELECT COALESCE(u.regional, 'Sin Regional Asignada') as regional, COUNT(a.id) as cantidad 
         FROM activos_tecnologicos a
         LEFT JOIN usuarios u ON a.id_usuario_responsable = u.id
         WHERE a.estado != 'Dado de Baja' 
         GROUP BY u.regional 
         ORDER BY cantidad DESC LIMIT 20"
    );
    if ($result_graf_regional_g) {
        while ($row = $result_graf_regional_g->fetch_assoc()) {
            $labels_regional_global[] = $row['regional'];
            $data_regional_global[] = (int)$row['cantidad'];
        }
    } else { error_log("Dashboard Error (Regional Global): " . $conexion->error); }
    error_log("[Dashboard PHP] Global Regional Labels: " . print_r($labels_regional_global, true));
    error_log("[Dashboard PHP] Global Regional Data: " . print_r($data_regional_global, true));


    $result_graf_empresa_g = $conexion->query(
        "SELECT COALESCE(u.empresa, 'Sin Empresa Asignada') AS Empresa, COUNT(a.id) as cantidad 
         FROM activos_tecnologicos a
         LEFT JOIN usuarios u ON a.id_usuario_responsable = u.id
         WHERE a.estado != 'Dado de Baja' 
         GROUP BY u.empresa 
         ORDER BY cantidad DESC LIMIT 20"
    );
    if ($result_graf_empresa_g) {
        while ($row = $result_graf_empresa_g->fetch_assoc()) {
            $labels_empresa_global[] = $row['Empresa'];
            $data_empresa_global[] = (int)$row['cantidad'];
        }
    } else { error_log("Dashboard Error (Empresa Global): " . $conexion->error); }
    error_log("[Dashboard PHP] Global Empresa Labels: " . print_r($labels_empresa_global, true));
    error_log("[Dashboard PHP] Global Empresa Data: " . print_r($data_empresa_global, true));
}


if ($is_ajax_request && !$conexion_error_msg) {
    $response_data = [
        'total_activos' => $total_activos,
        'valor_total_inventario' => $valor_total_inventario,
        'total_usuarios_con_activos' => $total_usuarios_con_activos,
        'activos_por_estado_data_kpi' => $activos_por_estado_data_kpi, 
        'filtro_aplicado_texto' => $filtro_regional_valor ?? $filtro_empresa_valor ?? $filtro_tipo_activo_valor ?? 'Ninguno'
    ];

    // Recalcular datos de gráficos filtrados para AJAX
    if ($filtro_regional_valor || $filtro_empresa_valor || $filtro_tipo_activo_valor) {
        $labels_tipo_filtrado = []; $data_tipo_filtrado = [];
        $sql_tipo_filtrado_base_select = "SELECT COALESCE(ta.nombre_tipo_activo, 'Sin Tipo Asignado') as nombre_tipo_activo, COUNT(a.id) as cantidad " . $sql_from_joins_base;
        $result_graf_tipo_f = ejecutarConsultaConFiltro($conexion, $sql_tipo_filtrado_base_select, "a.estado != 'Dado de Baja'", $global_where_clause_str , $global_params, $global_types, " GROUP BY ta.nombre_tipo_activo ORDER BY cantidad DESC LIMIT 20");
        if ($result_graf_tipo_f) {
            while ($row = $result_graf_tipo_f->fetch_assoc()) {
                $labels_tipo_filtrado[] = $row['nombre_tipo_activo'];
                $data_tipo_filtrado[] = (int)$row['cantidad'];
            }
        }
        $response_data['labels_tipo_activo_dinamico'] = $labels_tipo_filtrado;
        $response_data['data_tipo_activo_dinamico'] = $data_tipo_filtrado;

        $labels_regional_filtrado = []; $data_regional_filtrado = [];
        $sql_regional_filtrado_base_select = "SELECT COALESCE(u.regional, 'Sin Regional Asignada') as regional, COUNT(a.id) as cantidad " . $sql_from_joins_base;
        $result_graf_regional_f = ejecutarConsultaConFiltro($conexion, $sql_regional_filtrado_base_select, "a.estado != 'Dado de Baja'", $global_where_clause_str . " AND u.regional IS NOT NULL AND u.regional != ''", $global_params, $global_types, " GROUP BY u.regional ORDER BY cantidad DESC LIMIT 20");
        if ($result_graf_regional_f) {
            while ($row = $result_graf_regional_f->fetch_assoc()) {
                $labels_regional_filtrado[] = $row['regional'];
                $data_regional_filtrado[] = (int)$row['cantidad'];
            }
        }
        $response_data['labels_regional_dinamico'] = $labels_regional_filtrado;
        $response_data['data_regional_dinamico'] = $data_regional_filtrado;
        
        $labels_empresa_filtrado = []; $data_empresa_filtrado = [];
        $sql_empresa_filtrado_base_select = "SELECT COALESCE(u.empresa, 'Sin Empresa Asignada') as empresa, COUNT(a.id) as cantidad " . $sql_from_joins_base;
        $result_graf_empresa_f = ejecutarConsultaConFiltro($conexion, $sql_empresa_filtrado_base_select, "a.estado != 'Dado de Baja'", $global_where_clause_str . " AND u.empresa IS NOT NULL AND u.empresa != ''", $global_params, $global_types, " GROUP BY u.empresa ORDER BY cantidad DESC LIMIT 20");
        if($result_graf_empresa_f){
            while($row = $result_graf_empresa_f->fetch_assoc()){
                $labels_empresa_filtrado[] = $row['empresa'];
                $data_empresa_filtrado[] = (int)$row['cantidad'];
            }
        }
        $response_data['labels_empresa_dinamico'] = $labels_empresa_filtrado;
        $response_data['data_empresa_dinamico'] = $data_empresa_filtrado;

    } else { 
        $response_data['labels_tipo_activo_dinamico'] = $labels_tipo_activo_global;
        $response_data['data_tipo_activo_dinamico'] = $data_tipo_activo_global;
        $response_data['labels_regional_dinamico'] = $labels_regional_global;
        $response_data['data_regional_dinamico'] = $data_regional_global;
        $response_data['labels_empresa_dinamico'] = $labels_empresa_global;
        $response_data['data_empresa_dinamico'] = $data_empresa_global;
    }
    
    header('Content-Type: application/json');
    echo json_encode($response_data);
    if (isset($conexion) && $conexion) { $conexion->close(); }
    exit;
}

$labels_tipo_activo = $labels_tipo_activo_global;
$data_tipo_activo = $data_tipo_activo_global;
$labels_regional = $labels_regional_global;
$data_regional = $data_regional_global;
$labels_empresa = $labels_empresa_global; 
$data_empresa = $data_empresa_global;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard de Activos</title>
    <link rel="icon" type="image/x-icon" href="imagenes/icono.ico">
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
        
        .kpi-estado-titulo { text-align: center; font-weight: 600; color: #495057; margin-bottom: 1rem; font-size: 0.95rem;}
        .estado-general-grid { display: grid; grid-template-columns: repeat(3, minmax(25px, 1fr)); gap: 0.75rem; }
        .estado-item-card {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            padding: 0.75rem;
            text-align: center;
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
        }
        .estado-item-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 0.3rem 0.8rem rgba(0,0,0,.1);
        }
        .estado-item-card .estado-nombre { font-size: 0.85rem; font-weight: 500; display: block; margin-bottom: 0.25rem; color: #495057;}
        .estado-item-card .estado-cantidad { font-size: 1.5rem; font-weight: 700; display: block; }

        .page-header-title { color: #191970; font-weight: 600; cursor: pointer; text-align:center; }
        .filter-info { font-size: 0.9em; color: #6c757d; margin-bottom: 1rem; text-align: center; }
        .kpi-icon-azul-oscuro { color: #191970; } .kpi-value-azul-oscuro { color: #191970; }
        .kpi-icon-rojo { color: #D52B1E; } .kpi-value-rojo { color: #D52B1E; }
        .kpi-icon-cyan { color: #00ACC1; } .kpi-value-cyan { color: #00ACC1; }
        .kpi-icon-gris { color: #6c757d; } .kpi-value-gris { color: #6c757d; }

        .text-kpi-success { color: #198754 !important; }
        .text-kpi-warning { color: #ffc107 !important; }
        .text-kpi-danger { color: #dc3545 !important; }
        .text-kpi-primary { color: #0d6efd !important; }
        .text-kpi-secondary { color: #6c757d !important; }
    </style>
</head>
<body>

<div class="top-bar-custom">
    <div class="logo-container-top">
        <a href="menu.php" title="Ir al Menú Principal"> <img src="imagenes/logo.png" alt="Logo Empresa"></a>
    </div>
    <div class="user-info-top-container d-flex align-items-center">
        <span class="user-info-top text-dark me-3"><i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($_SESSION['nombre_usuario_completo'] ?? 'Usuario'); ?>
            (<?php echo htmlspecialchars(ucfirst($_SESSION['rol_usuario'] ?? 'Desconocido')); ?>)
        </span>
        <form action="logout.php" method="post" class="d-flex"><button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-box-arrow-right"></i> Salir</button></form>
    </div>
</div>

<div class="container-fluid mt-4 px-lg-4 px-2"> 
    <h3 class="mb-1 page-header-title" id="dashboardTitle">Resumen del Inventario</h3>
    <div class="filter-info" id="filterInfoMessage" style="display: none;">Filtrando por: <strong id="currentFilterValue"></strong> <button class="btn btn-sm btn-link p-0" id="resetFilterBtn">(Limpiar)</button></div>

    <div class="row">
        <div class="col-xl-3 col-md-6 mb-4"> 
            <div class="kpi-card text-center"> 
                <div class="kpi-icon kpi-icon-azul-oscuro"><i class="bi bi-laptop"></i></div> 
                <div class="kpi-value kpi-value-azul-oscuro" id="kpiTotalActivos"><?= $total_activos; ?></div> 
                <div class="kpi-label">Activos Operativos</div> 
            </div> 
        </div>
        <div class="col-xl-3 col-md-6 mb-4"> 
            <div class="kpi-card text-center"> 
                <div class="kpi-icon kpi-icon-rojo"><i class="bi bi-cash-coin"></i></div> 
                <div class="kpi-value kpi-value-rojo" id="kpiValorTotal" style="font-size: 1.8rem;">$<?= number_format($valor_total_inventario, 0, ',', '.'); ?></div> 
                <div class="kpi-label">Valor Total Inventario</div> 
            </div> 
        </div>
        <div class="col-xl-3 col-md-6 mb-4"> 
            <div class="kpi-card text-center"> 
                <div class="kpi-icon kpi-icon-cyan"><i class="bi bi-people-fill"></i></div> 
                <div class="kpi-value kpi-value-cyan" id="kpiTotalUsuarios"><?= $total_usuarios_con_activos; ?></div> 
                <div class="kpi-label">Usuarios con Activos</div> 
            </div> 
        </div>
        
        <div class="col-xl-3 col-md-6 mb-4"> 
            <div class="kpi-card">
                <div class="kpi-estado-titulo">Estado General de Activos</div>
                <div id="kpiEstadoGeneralContainer" class="estado-general-grid">
                </div>
            </div> 
        </div>
    </div>

    <div class="row">
        <div class="col-lg-4 mb-4"> <div class="chart-container"> <h5>Activos por Tipo (Operativos)</h5> <div class="chart-canvas-wrapper"><canvas id="graficoTipoActivo"></canvas></div> </div> </div>
        <div class="col-lg-4 mb-4"> <div class="chart-container"> <h5>Activos por Regional (Responsable - Operativos)</h5> <div class="chart-canvas-wrapper"><canvas id="graficoRegional"></canvas></div> </div> </div>
        <div class="col-lg-4 mb-4"> <div class="chart-container"> <h5>Activos por Empresa (Responsable - Operativos)</h5> <div class="chart-canvas-wrapper"><canvas id="graficoEmpresa"></canvas></div> </div> </div>
    </div>
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

    const initialDataPHP = {
        totalActivos: <?= json_encode($total_activos) ?>,
        valorTotalInventario: <?= json_encode($valor_total_inventario) ?>,
        totalUsuariosConActivos: <?= json_encode($total_usuarios_con_activos) ?>,
        activosPorEstadoKpi: <?= json_encode($activos_por_estado_data_kpi ?? []) ?>,
        labelsTipoActivoGlobal: <?= json_encode($labels_tipo_activo_global ?? []) ?>,
        dataTipoActivoGlobal: <?= json_encode($data_tipo_activo_global ?? []) ?>,
        labelsRegionalGlobal: <?= json_encode($labels_regional_global ?? []) ?>,
        dataRegionalGlobal: <?= json_encode($data_regional_global ?? []) ?>,
        labelsEmpresaGlobal: <?= json_encode($labels_empresa_global ?? []) ?>,
        dataEmpresaGlobal: <?= json_encode($data_empresa_global ?? []) ?>
    };

    function getEstadoColorClassJS(estado) { 
        if (!estado) return 'text-kpi-secondary';
        const estadoLower = estado.toLowerCase().trim();
        switch (estadoLower) {
            case 'bueno': return 'text-kpi-success'; 
            case 'regular': return 'text-kpi-warning';
            case 'malo': return 'text-kpi-danger';   
            default: return 'text-kpi-secondary'; 
        }
    }

    function renderEstadoGeneralKPI(estadosData) { 
        if(!kpiEstadoGeneralContainer) {
            console.error("Contenedor kpiEstadoGeneralContainer no encontrado");
            return;
        }
        kpiEstadoGeneralContainer.innerHTML = ''; 
        const estadosPermitidos = ['Bueno', 'Regular', 'Malo'];
        let totalMostradoEnKPI = 0; // Para contar cuántos activos se muestran en este KPI
        let foundDataForPermittedStates = false;

        if (estadosData && Array.isArray(estadosData)) {
            estadosPermitidos.forEach(estadoPermitido => {
                const estadoInfo = estadosData.find(e => e.estado === estadoPermitido);
                const cantidad = estadoInfo ? parseInt(estadoInfo.cantidad) : 0;
                
                if (estadoInfo) { 
                    totalMostradoEnKPI += cantidad; // Sumar al total de este KPI
                    foundDataForPermittedStates = true;
                }
                
                const colorClassText = getEstadoColorClassJS(estadoPermitido);
                
                const itemCard = document.createElement('div');
                itemCard.className = 'estado-item-card';
                
                const nombreSpan = document.createElement('span');
                nombreSpan.className = 'estado-nombre';
                nombreSpan.textContent = escapeHtml(estadoPermitido);
                
                const cantidadSpan = document.createElement('span');
                cantidadSpan.className = 'estado-cantidad ' + colorClassText;
                cantidadSpan.textContent = cantidad;
                
                itemCard.appendChild(nombreSpan);
                itemCard.appendChild(cantidadSpan);
                kpiEstadoGeneralContainer.appendChild(itemCard);
            });
        }
        
        // Mensaje si, después de iterar los estados permitidos, no se encontró data para ninguno *proveniente de la consulta*
        if (!foundDataForPermittedStates && (!estadosData || estadosData.length === 0) ) {
             kpiEstadoGeneralContainer.innerHTML = '<p class="text-muted text-center small p-2">No hay datos de estado disponibles para los filtros aplicados.</p>';
        } else if (!foundDataForPermittedStates && estadosData && estadosData.length > 0) {
             // Esto podría pasar si los datos de 'estadosData' no incluyen 'Nuevo', 'Bueno', 'Regular', 'Malo'
             kpiEstadoGeneralContainer.innerHTML = '<p class="text-muted text-center small p-2">No se encontraron activos en estados: Nuevo, Bueno, Regular o Malo.</p>';
        }
    }
    
    function escapeHtml(unsafe) { 
        if (unsafe === null || typeof unsafe === 'undefined') return '';
        return unsafe.toString().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }

    renderEstadoGeneralKPI(initialDataPHP.activosPorEstadoKpi);

    function updateDashboardData(filterType = null, filterValue = null) {
        let url = 'dashboard.php?ajax=1';
        let filterText = 'General (Operativos)';
        if (filterType && filterValue) {
            url += `&filtro_${filterType}=${encodeURIComponent(filterValue)}`;
            let tipoFiltroTexto = filterType.charAt(0).toUpperCase() + filterType.slice(1).replace('_', ' ');
             if(filterType === 'tipo_activo') tipoFiltroTexto = 'Tipo de Activo';
            filterText = `${tipoFiltroTexto}: ${filterValue}`;
        }
        
        if(filterInfoMessageEl && currentFilterValueEl) {
            if (filterType && filterValue) {
                filterInfoMessageEl.style.display = 'block';
                currentFilterValueEl.textContent = filterText;
            } else {
                filterInfoMessageEl.style.display = 'none';
            }
        }

        if(kpiTotalActivosEl) kpiTotalActivosEl.textContent = '...'; 
        if(kpiValorTotalEl) kpiValorTotalEl.textContent = '...'; 
        if(kpiTotalUsuariosEl) kpiTotalUsuariosEl.textContent = '...';
        if(kpiEstadoGeneralContainer) kpiEstadoGeneralContainer.innerHTML = '<div class="text-center text-muted p-2"><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Cargando...</span></div></div>';
        
        if(graficoTipoActivoInstance) { graficoTipoActivoInstance.data.labels = []; graficoTipoActivoInstance.data.datasets[0].data = []; graficoTipoActivoInstance.update(); }
        if(graficoRegionalInstance) { graficoRegionalInstance.data.labels = []; graficoRegionalInstance.data.datasets[0].data = []; graficoRegionalInstance.update(); }
        if(graficoEmpresaInstance) { graficoEmpresaInstance.data.labels = []; graficoEmpresaInstance.data.datasets[0].data = []; graficoEmpresaInstance.update(); }

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

                if(kpiTotalActivosEl) kpiTotalActivosEl.textContent = data.total_activos;
                if(kpiValorTotalEl) kpiValorTotalEl.textContent = '$' + parseFloat(data.valor_total_inventario).toLocaleString('es-CO', { maximumFractionDigits: 0 });
                if(kpiTotalUsuariosEl) kpiTotalUsuariosEl.textContent = data.total_usuarios_con_activos;
                renderEstadoGeneralKPI(data.activos_por_estado_data_kpi);

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
                 if (graficoEmpresaInstance && data.labels_empresa_dinamico && data.data_empresa_dinamico) {
                    const empresaChartColorsDinamico = data.labels_empresa_dinamico.map((label, index) => {
                        if (label && label.toLowerCase().includes('arpesod')) { return colorParaArpesod; }
                        else if (label && label.toLowerCase().includes('finansueños')) { return colorParaFinansuenos; }
                        return logoColorPalette[(index + 2) % logoColorPalette.length]; 
                    });
                    graficoEmpresaInstance.data.labels = data.labels_empresa_dinamico;
                    graficoEmpresaInstance.data.datasets[0].data = data.data_empresa_dinamico;
                    graficoEmpresaInstance.data.datasets[0].backgroundColor = empresaChartColorsDinamico;
                    graficoEmpresaInstance.update();
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
    if(dashboardTitleEl) dashboardTitleEl.addEventListener('click', () => updateDashboardData()); 

    function handleChartClick(event, elements, chartInstance, filterType) {
        if (elements.length > 0) {
            const clickedElementIndex = elements[0].index;
            const filterValue = chartInstance.data.labels[clickedElementIndex];
            updateDashboardData(filterType, filterValue);
        }
    }

    const ctxTipo = document.getElementById('graficoTipoActivo');
    if (ctxTipo && initialDataPHP.labelsTipoActivoGlobal && initialDataPHP.labelsTipoActivoGlobal.length > 0) { // Verificación robusta
        graficoTipoActivoInstance = new Chart(ctxTipo, {
            type: 'bar',
            data: { labels: initialDataPHP.labelsTipoActivoGlobal, datasets: [{ label: 'Cantidad', data: initialDataPHP.dataTipoActivoGlobal, backgroundColor: logoColorPalette.slice(0, initialDataPHP.labelsTipoActivoGlobal.length) }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }, onClick: (event, elements) => handleChartClick(event, elements, graficoTipoActivoInstance, 'tipo_activo') }
        });
    } else if(ctxTipo) {
        ctxTipo.parentElement.innerHTML = '<p class="text-center text-muted small">No hay datos suficientes para el gráfico de Tipos de Activo.</p>';
    }
    
    const ctxRegional = document.getElementById('graficoRegional');
    if (ctxRegional && initialDataPHP.labelsRegionalGlobal && initialDataPHP.labelsRegionalGlobal.length > 0) {
        graficoRegionalInstance = new Chart(ctxRegional, {
            type: 'bar',
            data: { labels: initialDataPHP.labelsRegionalGlobal, datasets: [{ label: 'Cantidad', data: initialDataPHP.dataRegionalGlobal, backgroundColor: logoColorPalette.slice(0, initialDataPHP.labelsRegionalGlobal.length) }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }, onClick: (event, elements) => handleChartClick(event, elements, graficoRegionalInstance, 'regional') }
        });
    } else if(ctxRegional) {
        ctxRegional.parentElement.innerHTML = '<p class="text-center text-muted small">No hay datos suficientes para el gráfico de Regionales.</p>';
    }
    
    const ctxEmpresa = document.getElementById('graficoEmpresa');
    if (ctxEmpresa && initialDataPHP.labelsEmpresaGlobal && initialDataPHP.labelsEmpresaGlobal.length > 0) {
        const empresaChartColors = initialDataPHP.labelsEmpresaGlobal.map((label, index) => {
            if (label && label.toLowerCase().includes('arpesod')) { return colorParaArpesod; }
            else if (label && label.toLowerCase().includes('finansueños')) { return colorParaFinansuenos; }
            return logoColorPalette[(index + 2) % logoColorPalette.length]; 
        });
        graficoEmpresaInstance = new Chart(ctxEmpresa, { 
            type: 'pie',
            data: { labels: initialDataPHP.labelsEmpresaGlobal, datasets: [{ label: 'Activos', data: initialDataPHP.dataEmpresaGlobal, backgroundColor: empresaChartColors, borderColor: '#fff', borderWidth: 2 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } }, onClick: (event, elements) => handleChartClick(event, elements, graficoEmpresaInstance, 'empresa') }
        });
    } else if(ctxEmpresa) {
        ctxEmpresa.parentElement.innerHTML = '<p class="text-center text-muted small">No hay datos suficientes para el gráfico de Empresas.</p>';
    }
});
</script>
</body>
</html>
