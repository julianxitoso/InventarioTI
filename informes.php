<?php
error_reporting(E_ALL); // Temporal para depuración
ini_set('display_errors', 1); // Temporal para depuración

session_start();
// auth_check.php DEBE estar primero para que las funciones de sesión y permiso estén disponibles
require_once 'backend/auth_check.php'; 
// Ejemplo: Restringir acceso a informes a ciertos roles
restringir_acceso_pagina(['admin', 'tecnico', 'auditor']);


require_once 'backend/db.php';
// La función historial_helper.php no parece usarse directamente aquí, pero la dejamos por si acaso
// require_once 'backend/historial_helper.php'; 

if (isset($conn) && !isset($conexion)) {
    $conexion = $conn;
} elseif (!isset($conn) && !isset($conexion)) {
    // Fallback de conexión
    $servername = "localhost";
    $username = "root";
    $password = ""; 
    $dbname = "inventario";
    $conexion = new mysqli($servername, $username, $password, $dbname);
    if ($conexion->connect_error) {
        error_log("Fallo de conexión a la BD (informes.php fallback): " . $conexion->connect_error);
        die("Error de conexión al servidor. Por favor, intente más tarde.");
    }
}

if (!isset($conexion) || (method_exists($conexion, 'connect_error') && $conexion->connect_error) || !$conexion) {
    error_log("La variable de conexión a la BD no está disponible o es inválida en informes.php.");
    die("Error crítico de conexión. Contacte al administrador.");
}
$conexion->set_charset("utf8mb4");

// Captura de datos de sesión para la barra superior
$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Usuario';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'Desconocido';


$tipo_informe_seleccionado = $_GET['tipo_informe'] ?? 'seleccione';
$titulo_pagina_base = "Central de Informes";
$titulo_informe_actual = "";
$datos_para_tabla = [];
$columnas_tabla_html = []; 

if (!function_exists('getEstadoBadgeClass')) {
    function getEstadoBadgeClass($estado) {
        $estadoLower = strtolower(trim($estado));
        switch ($estadoLower) {
            case 'asignado': case 'activo': case 'operativo': case 'bueno': return 'badge bg-success';
            case 'en mantenimiento': case 'en reparación': case 'regular': return 'badge bg-warning text-dark';
            case 'dado de baja': case 'inactivo': case 'malo': return 'badge bg-danger';
            case 'disponible': case 'en stock': return 'badge bg-info text-dark';
            default: return 'badge bg-secondary';
        }
    }
}

if (!function_exists('displayStars')) {
    function displayStars($rating, $totalStars = 5) {
        if ($rating === null || !is_numeric($rating) || $rating < 0) return 'N/A';
        $rating_calc = round(floatval($rating) * 2) / 2; 
        $output = "<span style='color: #f5b301; font-size: 1.1em;'>"; 
        for ($i = 1; $i <= $totalStars; $i++) {
            if ($rating_calc >= $i) $output .= '★';
            elseif ($rating_calc >= $i - 0.5) $output .= '★'; 
            else $output .= '☆';
        }
        $output .= "</span> (" . number_format(floatval($rating), 1) . ")";
        return $output;
    }
}

// ... (TODA TU LÓGICA DE switch case para los diferentes informes VA AQUÍ - SIN CAMBIOS INTERNOS) ...
// Asegúrate que la columna 'Empresa' se use consistentemente (mayúscula o minúscula)
// en tus queries SQL si la seleccionas o filtras por ella.
if ($tipo_informe_seleccionado !== 'seleccione') {
    switch ($tipo_informe_seleccionado) {
        case 'general':
            $titulo_informe_actual = "Informe General de Activos (Agrupado por Responsable)";
            $query = "SELECT activos_tecnologicos.*, 
                             activos_tecnologicos.nombre as nombre_responsable_directo, 
                             activos_tecnologicos.cedula as cedula_responsable_directo,
                             activos_tecnologicos.cargo as cargo_responsable_directo
                      FROM activos_tecnologicos 
                      WHERE estado != 'Dado de Baja'
                      ORDER BY Empresa ASC, cedula_responsable_directo ASC, nombre_responsable_directo ASC, id ASC";
            $resultado_query = mysqli_query($conexion, $query);
            if ($resultado_query) { $datos_para_tabla = mysqli_fetch_all($resultado_query, MYSQLI_ASSOC); }
            else { error_log("Error en la consulta (general): " . mysqli_error($conexion)); }
            break;

        case 'por_tipo':
            $titulo_informe_actual = "Informe de Activos por Tipo";
            $query = "SELECT * FROM activos_tecnologicos WHERE estado != 'Dado de Baja' ORDER BY tipo_activo ASC, id ASC";
            $resultado_query = mysqli_query($conexion, $query);
            if ($resultado_query) $datos_para_tabla = mysqli_fetch_all($resultado_query, MYSQLI_ASSOC);
            else error_log("Error en la consulta (por_tipo): " . mysqli_error($conexion));
            break;

        case 'por_estado':
            $titulo_informe_actual = "Informe de Activos por Estado (Excluye 'Dado de Baja')";
            $query = "SELECT * FROM activos_tecnologicos WHERE estado != 'Dado de Baja' ORDER BY estado ASC, id ASC";
            $resultado_query = mysqli_query($conexion, $query);
            if ($resultado_query) $datos_para_tabla = mysqli_fetch_all($resultado_query, MYSQLI_ASSOC);
            else error_log("Error en la consulta (por_estado): " . mysqli_error($conexion));
            break;

        case 'por_regional':
            $titulo_informe_actual = "Informe de Activos por Regional (Excluye 'Dado de Baja')";
            $query = "SELECT * FROM activos_tecnologicos WHERE estado != 'Dado de Baja' ORDER BY regional ASC, id ASC";
            $resultado_query = mysqli_query($conexion, $query);
            if ($resultado_query) $datos_para_tabla = mysqli_fetch_all($resultado_query, MYSQLI_ASSOC);
            else error_log("Error en la consulta (por_regional): " . mysqli_error($conexion));
            break;
        
        case 'por_empresa':
            $titulo_informe_actual = "Informe de Activos por Empresa (Excluye 'Dado de Baja')";
            $query = "SELECT * FROM activos_tecnologicos WHERE estado != 'Dado de Baja' AND Empresa IS NOT NULL AND Empresa != '' ORDER BY Empresa ASC, id ASC";
            $resultado_query = mysqli_query($conexion, $query);
            if ($resultado_query) { $datos_para_tabla = mysqli_fetch_all($resultado_query, MYSQLI_ASSOC); }
            else { error_log("Error en la consulta (por_empresa): " . mysqli_error($conexion));}
            break;

        case 'calificacion_por_tipo': 
            $titulo_informe_actual = "Informe de Calificaciones Individuales por Activo"; 
            $query = "SELECT at.id, at.tipo_activo, at.marca, at.serie, at.nombre AS nombre_responsable, at.cedula AS cedula_responsable, at.Empresa, at.regional, at.estado, at.satisfaccion_rating, at.fecha_registro FROM activos_tecnologicos at WHERE at.satisfaccion_rating IS NOT NULL AND at.estado != 'Dado de Baja' ORDER BY at.tipo_activo ASC, at.satisfaccion_rating DESC, at.id ASC";
            $resultado_query = mysqli_query($conexion, $query);
            if ($resultado_query) { $datos_para_tabla = mysqli_fetch_all($resultado_query, MYSQLI_ASSOC); }
            else { error_log("Error en la consulta (calificacion_individual_activos): " . mysqli_error($conexion));}
            $columnas_tabla_html = ["Tipo de Activo", "Marca", "Serie", "Responsable", "Empresa", "Regional", "Estado", "Fecha Registro", "Calificación"];
            break;

        case 'dados_baja':
            $titulo_informe_actual = "Informe de Activos Dados de Baja";
            $tipo_baja_const = defined('HISTORIAL_TIPO_BAJA') ? HISTORIAL_TIPO_BAJA : 'BAJA';
            $query = "SELECT at.id, at.tipo_activo, at.marca, at.serie, at.estado, at.Empresa, at.valor_aproximado, at.regional, at.detalles AS detalles_activo, at.fecha_registro, at.nombre AS nombre_ultimo_responsable, at.cedula AS cedula_ultimo_responsable, h_baja.descripcion_evento AS motivo_observaciones_baja, h_baja.fecha_evento AS fecha_efectiva_baja FROM activos_tecnologicos at LEFT JOIN ( SELECT h1.id_activo, h1.descripcion_evento, h1.fecha_evento FROM historial_activos h1 INNER JOIN (SELECT id_activo, MAX(id_historial) as max_id_hist_baja FROM historial_activos WHERE tipo_evento = '$tipo_baja_const' GROUP BY id_activo) h2 ON h1.id_activo = h2.id_activo AND h1.id_historial = h2.max_id_hist_baja ) h_baja ON at.id = h_baja.id_activo WHERE at.estado = 'Dado de Baja' ORDER BY COALESCE(h_baja.fecha_evento, at.fecha_registro) DESC, at.id ASC";
            $resultado_query = mysqli_query($conexion, $query);
            if ($resultado_query) $datos_para_tabla = mysqli_fetch_all($resultado_query, MYSQLI_ASSOC);
            else error_log("Error en la consulta (dados_baja): " . mysqli_error($conexion));
            $columnas_tabla_html = ["ID", "Tipo", "Marca", "Serie", "Empresa", "Últ. Responsable", "Fecha Baja", "Motivo/Obs.", "Acciones"];
            break;

        case 'movimientos':
            $titulo_informe_actual = "Informe de Movimientos y Traslados Recientes";
            $tipo_traslado_const = defined('HISTORIAL_TIPO_TRASLADO') ? HISTORIAL_TIPO_TRASLADO : 'TRASLADO';
            $query = "SELECT h.id_historial, h.fecha_evento, h.tipo_evento, h.descripcion_evento, h.usuario_responsable, a.id as id_activo_hist, a.tipo_activo, a.serie, a.marca AS marca_activo, a.Empresa AS empresa_activo FROM historial_activos h JOIN activos_tecnologicos a ON h.id_activo = a.id WHERE h.tipo_evento = '$tipo_traslado_const' OR h.tipo_evento = '".(defined('HISTORIAL_TIPO_ASIGNACION_INICIAL') ? HISTORIAL_TIPO_ASIGNACION_INICIAL : 'ASIGNACIÓN INICIAL')."' OR h.tipo_evento = '".(defined('HISTORIAL_TIPO_CREACION') ? HISTORIAL_TIPO_CREACION : 'CREACIÓN')."' ORDER BY h.fecha_evento DESC LIMIT 100";
            $resultado_query = mysqli_query($conexion, $query);
            if ($resultado_query) $datos_para_tabla = mysqli_fetch_all($resultado_query, MYSQLI_ASSOC);
            else error_log("Error en la consulta (movimientos): " . mysqli_error($conexion));
            $columnas_tabla_html = ['Fecha', 'Tipo Evento', 'Activo', 'Serie', 'Marca', 'Empresa', 'Descripción', 'Usuario Sis.', 'Ver Activo'];
            break;
        
        default:
            $titulo_informe_actual = "Tipo de Informe No Válido";
            $datos_para_tabla = [];
            break;
    }
}

if(isset($conexion)) { mysqli_close($conexion); } // Cerrar conexión después de todas las consultas
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($titulo_pagina_base) ?> <?= $titulo_informe_actual ? "- " . htmlspecialchars($titulo_informe_actual) : "" ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
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
        
        /* Estilos existentes de informes.php */
        .container-main { margin-top: 20px; margin-bottom: 40px; }
        h3.page-title, h4.informe-title { color: #333; font-weight: 600; }
        .informe-selector-card { transition: transform .2s, box-shadow .2s; cursor: pointer; border: 1px solid #ddd; }
        .informe-selector-card:hover { transform: translateY(-5px); box-shadow: 0 8px 20px rgba(0,0,0,0.12); }
        .informe-selector-card .card-body { display: flex; flex-direction: column; justify-content: center; align-items: center; min-height: 130px; text-align: center; }
        .informe-selector-card i { font-size: 2.2rem; margin-bottom: 0.75rem; }
        .informe-selector-card h5 { font-size: 1.1rem; font-weight: 500;}
        .table-minimalist { border-collapse: collapse; width: 100%; margin-top: 15px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); border-radius: 8px; overflow: hidden; font-size: 0.85rem; }
        .table-minimalist thead th { background-color: #343a40; color: #fff; font-weight: 600; text-align: left; padding: 12px 15px; border-bottom: 0; white-space: nowrap; }
        .table-minimalist tbody td { padding: 10px 15px; border-bottom: 1px solid #e9ecef; color: #495057; vertical-align: middle; }
        .table-minimalist tbody tr:last-child td { border-bottom: none; }
        .table-minimalist tbody tr:hover { background-color: #f8f9fa; }
        .badge { padding: 0.45em 0.7em; font-size: 0.88em; font-weight: 500; }
        .btn-export { background-color: #198754; border-color: #198754; color: white; font-weight: 500; }
        .btn-export:hover { background-color: #157347; border-color: #146c43; }
        .user-asset-group, .report-group-container { background-color: #fff; padding: 20px; margin-bottom: 25px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.07); }
        .user-info-header, .group-info-header { border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 15px; }
        .user-info-header h4, .group-info-header h4 { color: #37517e; font-weight: 600; margin-bottom: 2px; font-size: 1.2rem;}
        .user-info-header p, .group-info-header p { margin-bottom: 2px; font-size: 0.95em; color: #555; }
        .asset-item-number { font-weight: bold; min-width: 25px; display: inline-block; text-align: right; margin-right: 5px;}
        .page-header-title { color: #191970; } /* Para el título principal de la página */
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

<div class="container-main container">
    <h3 class="page-title text-center mb-4 page-header-title"><?= htmlspecialchars($titulo_pagina_base) ?></h3>

    <div class="row mb-5 justify-content-center">
        <div class="col-lg-3 col-md-4 col-sm-6 mb-3"> <a href="informes.php?tipo_informe=general" class="card informe-selector-card text-decoration-none <?= ($tipo_informe_seleccionado == 'general') ? 'border-primary shadow' : 'text-dark' ?>"> <div class="card-body"> <i class="bi bi-list-ul text-primary"></i> <h5>General por Responsable</h5></div></a></div>
        <div class="col-lg-3 col-md-4 col-sm-6 mb-3"> <a href="informes.php?tipo_informe=por_tipo" class="card informe-selector-card text-decoration-none <?= ($tipo_informe_seleccionado == 'por_tipo') ? 'border-secondary shadow' : 'text-dark' ?>"> <div class="card-body"> <i class="bi bi-tags-fill text-secondary"></i> <h5>Activos por Tipo</h5></div></a></div>
        <div class="col-lg-3 col-md-4 col-sm-6 mb-3"> <a href="informes.php?tipo_informe=por_estado" class="card informe-selector-card text-decoration-none <?= ($tipo_informe_seleccionado == 'por_estado') ? 'border-success shadow' : 'text-dark' ?>"> <div class="card-body"> <i class="bi bi-check-circle-fill text-success"></i> <h5>Activos por Estado</h5></div></a></div>
        <div class="col-lg-3 col-md-4 col-sm-6 mb-3"> <a href="informes.php?tipo_informe=por_regional" class="card informe-selector-card text-decoration-none <?= ($tipo_informe_seleccionado == 'por_regional') ? 'border-info shadow' : 'text-dark' ?>"> <div class="card-body"> <i class="bi bi-geo-alt-fill text-info"></i> <h5>Activos por Regional</h5></div></a></div>
        <div class="col-lg-3 col-md-4 col-sm-6 mb-3"> <a href="informes.php?tipo_informe=por_empresa" class="card informe-selector-card text-decoration-none <?= ($tipo_informe_seleccionado == 'por_empresa') ? 'border-dark shadow' : 'text-dark' ?>"> <div class="card-body"> <i class="bi bi-building text-dark"></i> <h5>Activos por Empresa</h5></div></a></div>
        <div class="col-lg-3 col-md-4 col-sm-6 mb-3"> <a href="informes.php?tipo_informe=calificacion_por_tipo" class="card informe-selector-card text-decoration-none <?= ($tipo_informe_seleccionado == 'calificacion_por_tipo') ? 'border-warning shadow' : 'text-dark' ?>"> <div class="card-body"> <i class="bi bi-star-fill text-warning"></i> <h5>Calificaciones por Activo</h5></div></a></div>
        <div class="col-lg-3 col-md-4 col-sm-6 mb-3"> <a href="informes.php?tipo_informe=dados_baja" class="card informe-selector-card text-decoration-none <?= ($tipo_informe_seleccionado == 'dados_baja') ? 'border-danger shadow' : 'text-dark' ?>"> <div class="card-body"> <i class="bi bi-trash3-fill text-danger"></i> <h5>Activos Dados de Baja</h5></div></a></div>
        <div class="col-lg-3 col-md-4 col-sm-6 mb-3"> <a href="informes.php?tipo_informe=movimientos" class="card informe-selector-card text-decoration-none <?= ($tipo_informe_seleccionado == 'movimientos') ? 'border-secondary shadow' : 'text-dark' ?>"> <div class="card-body"> <i class="bi bi-truck text-secondary"></i> <h5>Movimientos Recientes</h5></div></a></div>
    </div>
    
    <?php if ($tipo_informe_seleccionado !== 'seleccione' && !empty($titulo_informe_actual)) : ?>
        <hr class="my-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="informe-title mb-0"><i class="bi bi-table"></i> <?= htmlspecialchars($titulo_informe_actual) ?></h4>
            <?php if (!empty($datos_para_tabla)): ?>
            <a href="exportar_excel.php?tipo_informe=<?= urlencode($tipo_informe_seleccionado) ?>" class="btn btn-sm btn-export">
                <i class="bi bi-file-earmark-excel-fill"></i> Exportar a Excel
            </a>
            <?php endif; ?>
        </div>

        <?php if (!empty($datos_para_tabla)) : ?>
            <?php
            // ... (TU LÓGICA EXISTENTE PARA MOSTRAR LAS TABLAS DE CADA INFORME) ...
            // El código para 'general', 'por_tipo', 'por_estado', 'por_regional', 'por_empresa',
            // 'calificacion_por_tipo', 'dados_baja', 'movimientos' que ya tenías y funcionaba,
            // se mantiene aquí. Solo asegúrate que la columna 'Empresa' se acceda
            // consistentemente como $fila['Empresa'] o $fila['empresa'] según tu BD.
            if ($tipo_informe_seleccionado == 'general') {
                $current_group_key_general = null; $asset_item_number_general = 0; $current_empresa_general = null;
                foreach ($datos_para_tabla as $activo_gen) :
                    $responsable_key = ($activo_gen['Empresa'] ?? 'SinEmpresa') . '-' . $activo_gen['cedula_responsable_directo'];
                    if ($responsable_key !== $current_group_key_general) :
                        if ($current_group_key_general !== null) echo '</tbody></table></div></div>';
                        $current_group_key_general = $responsable_key; $current_empresa_general = $activo_gen['Empresa'] ?? ($activo_gen['empresa'] ?? 'N/A'); $asset_item_number_general = 1;
            ?> <div class="user-asset-group"> <div class="user-info-header"> <h4><?= htmlspecialchars($activo_gen['nombre_responsable_directo']) ?> <small class="text-muted">(Empresa: <?= htmlspecialchars($current_empresa_general) ?>)</small></h4> <p><strong>C.C:</strong> <?= htmlspecialchars($activo_gen['cedula_responsable_directo']) ?> | <strong>Cargo:</strong> <?= htmlspecialchars($activo_gen['cargo_responsable_directo']) ?></p> </div> <div class="table-responsive"> <table class="table-minimalist"> <thead><tr><th>#</th><th>Tipo</th><th>Marca</th><th>Serie</th><th>Estado</th><th>Valor</th><th>Regional</th><th>Fecha Reg.</th></tr></thead> <tbody> <?php endif; ?> <tr> <td><span class="asset-item-number"><?= $asset_item_number_general++ ?>.</span></td> <td><?= htmlspecialchars($activo_gen['tipo_activo']) ?></td> <td><?= htmlspecialchars($activo_gen['marca']) ?></td> <td><?= htmlspecialchars($activo_gen['serie']) ?></td> <td><span class="<?= getEstadoBadgeClass($activo_gen['estado']) ?>"><?= htmlspecialchars($activo_gen['estado']) ?></span></td> <td>$<?= htmlspecialchars(number_format(floatval($activo_gen['valor_aproximado']), 0, ',', '.')) ?></td> <td><?= htmlspecialchars($activo_gen['regional']) ?></td> <td><?= htmlspecialchars(date("d/m/Y", strtotime($activo_gen['fecha_registro']))) ?></td> </tr> <?php endforeach; if ($current_group_key_general !== null) echo '</tbody></table></div></div>';

            } elseif (in_array($tipo_informe_seleccionado, ['por_tipo', 'por_estado', 'por_regional', 'por_empresa'])) {
                $current_group_key_field = null; $asset_item_number_field = 0; $group_by_field = '';
                switch ($tipo_informe_seleccionado) {
                    case 'por_tipo': $group_by_field = 'tipo_activo'; break;
                    case 'por_estado': $group_by_field = 'estado'; break;
                    case 'por_regional': $group_by_field = 'regional'; break;
                    case 'por_empresa': $group_by_field = 'Empresa'; break; 
                }
                $group_by_field_label = ucfirst(str_replace('_', ' ', $group_by_field));
                if ($tipo_informe_seleccionado == 'por_empresa') { $group_by_field_label = 'Empresa';}

                foreach ($datos_para_tabla as $activo_field) :
                    $current_group_value = $activo_field[$group_by_field] ?? 'Sin Asignar'; 
                    if ($current_group_value !== $current_group_key_field) :
                        if ($current_group_key_field !== null) echo '</tbody></table></div></div>';
                        $current_group_key_field = $current_group_value; $asset_item_number_field = 1;
            ?> <div class="report-group-container"> <div class="group-info-header"><h4><?= htmlspecialchars($group_by_field_label) ?>: <?= htmlspecialchars($current_group_key_field ?: 'N/A') ?></h4></div> <div class="table-responsive"> <table class="table-minimalist"> <?php if ($tipo_informe_seleccionado == 'por_empresa'): ?> <thead><tr><th>#</th><th>Tipo Activo</th><th>Marca</th><th>Serie</th><th>Responsable</th><th>C.C.</th><th>Estado</th><th>Valor</th><th>Regional</th></tr></thead> <?php else: ?> <thead><tr><th>#</th><th>Serie</th><th>Marca</th><th>Responsable</th><th>C.C.</th><th><?= ($group_by_field != 'estado') ? 'Estado' : 'Tipo Activo' ?></th><th>Valor</th><th><?= ($group_by_field != 'regional') ? 'Regional' : (($group_by_field != 'tipo_activo') ? 'Tipo Activo': ($activo_field['Empresa'] ?? $activo_field['empresa'] ?? 'N/A')) ?></th></tr></thead> <?php endif; ?> <tbody> <?php endif; ?> <tr> <td><span class="asset-item-number"><?= $asset_item_number_field++ ?>.</span></td> <?php if ($tipo_informe_seleccionado == 'por_empresa'): ?> <td><?= htmlspecialchars($activo_field['tipo_activo']) ?></td> <td><?= htmlspecialchars($activo_field['marca']) ?></td> <td><?= htmlspecialchars($activo_field['serie']) ?></td> <td><?= htmlspecialchars($activo_field['nombre'] ?? 'N/A') ?></td> <td><?= htmlspecialchars($activo_field['cedula'] ?? 'N/A') ?></td> <td><span class="<?= getEstadoBadgeClass($activo_field['estado']) ?>"><?= htmlspecialchars($activo_field['estado']) ?></span></td> <td>$<?= htmlspecialchars(number_format(floatval($activo_field['valor_aproximado']), 0, ',', '.')) ?></td> <td><?= htmlspecialchars($activo_field['regional']) ?></td> <?php else: ?> <td><?= htmlspecialchars($activo_field['serie']) ?></td> <td><?= htmlspecialchars($activo_field['marca']) ?></td> <td><?= htmlspecialchars($activo_field['nombre'] ?? 'N/A') ?></td> <td><?= htmlspecialchars($activo_field['cedula'] ?? 'N/A') ?></td> <td> <?php if ($group_by_field != 'estado'): ?><span class="<?= getEstadoBadgeClass($activo_field['estado']) ?>"><?= htmlspecialchars($activo_field['estado']) ?></span><?php else: ?><?= htmlspecialchars($activo_field['tipo_activo']) ?><?php endif; ?> </td> <td>$<?= htmlspecialchars(number_format(floatval($activo_field['valor_aproximado']), 0, ',', '.')) ?></td> <td><?= htmlspecialchars( ($group_by_field != 'regional') ? $activo_field['regional'] : ( ($group_by_field != 'tipo_activo') ? $activo_field['tipo_activo'] : ($activo_field['Empresa'] ?? $activo_field['empresa'] ?? 'N/A') ) ) ?></td> <?php endif; ?> </tr> <?php endforeach; if ($current_group_key_field !== null) echo '</tbody></table></div></div>';
            
            } elseif ($tipo_informe_seleccionado == 'calificacion_por_tipo') { 
            ?>
                <div class="report-group-container"> <div class="table-responsive"> <table class="table-minimalist"> <thead> <tr> <?php foreach ($columnas_tabla_html as $header): ?> <th><?= htmlspecialchars($header) ?></th> <?php endforeach; ?> </tr> </thead> <tbody> <?php foreach ($datos_para_tabla as $activo_calificado) : ?> <tr> <td><?= htmlspecialchars($activo_calificado['tipo_activo']) ?></td> <td><?= htmlspecialchars($activo_calificado['marca']) ?></td> <td><?= htmlspecialchars($activo_calificado['serie']) ?></td> <td><?= htmlspecialchars($activo_calificado['nombre_responsable'] ?? 'N/A') ?> (<?= htmlspecialchars($activo_calificado['cedula_responsable'] ?? 'N/A')?>)</td> <td><?= htmlspecialchars($activo_calificado['Empresa'] ?? ($activo_calificado['empresa'] ?? 'N/A')) ?></td> <td><?= htmlspecialchars($activo_calificado['regional']) ?></td> <td><span class="<?= getEstadoBadgeClass($activo_calificado['estado']) ?>"><?= htmlspecialchars($activo_calificado['estado']) ?></span></td> <td><?= htmlspecialchars(date("d/m/Y", strtotime($activo_calificado['fecha_registro']))) ?></td> <td><?= displayStars(floatval($activo_calificado['satisfaccion_rating'])) ?></td> </tr> <?php endforeach; ?> </tbody> </table> </div> </div>
            <?php
            } elseif ($tipo_informe_seleccionado == 'dados_baja') {
                 ?> <div class="report-group-container"> <div class="table-responsive"> <table class="table-minimalist"> <thead><tr> <?php foreach ($columnas_tabla_html as $header): ?><th><?= htmlspecialchars($header) ?></th><?php endforeach; ?> </tr></thead> <tbody> <?php foreach ($datos_para_tabla as $activo_baja) : ?> <tr> <td><?= htmlspecialchars($activo_baja['id']) ?></td> <td><?= htmlspecialchars($activo_baja['tipo_activo']) ?></td> <td><?= htmlspecialchars($activo_baja['marca']) ?></td> <td><?= htmlspecialchars($activo_baja['serie']) ?></td> <td><?= htmlspecialchars($activo_baja['Empresa'] ?? ($activo_baja['empresa'] ?? 'N/A')) ?></td> <td><?= htmlspecialchars($activo_baja['nombre_ultimo_responsable'] ?? 'N/A') ?> (<?= htmlspecialchars($activo_baja['cedula_ultimo_responsable'] ?? 'N/A') ?>)</td> <td><?= htmlspecialchars(!empty($activo_baja['fecha_efectiva_baja']) ? date("d/m/Y H:i:s", strtotime($activo_baja['fecha_efectiva_baja'])) : 'N/A') ?></td> <td style="max-width: 300px; white-space: pre-wrap; word-wrap: break-word;"><?= nl2br(htmlspecialchars($activo_baja['motivo_observaciones_baja'] ?? $activo_baja['detalles_activo'] ?? 'Ver historial')) ?></td> <td><a href="historial.php?id_activo=<?= htmlspecialchars($activo_baja['id']) ?>" class="btn btn-sm btn-outline-info" title="Ver Historial Completo" target="_blank"><i class="bi bi-list-task"></i> Hist.</a></td> </tr> <?php endforeach; ?> </tbody> </table> </div></div> <?php
            } elseif ($tipo_informe_seleccionado == 'movimientos') {
                 ?> <div class="report-group-container"> <div class="table-responsive"> <table class="table-minimalist"> <thead><tr> <?php foreach ($columnas_tabla_html as $header): ?><th><?= htmlspecialchars($header) ?></th><?php endforeach; ?> </tr></thead> <tbody> <?php foreach ($datos_para_tabla as $evento) : ?> <tr> <td><?= htmlspecialchars(!empty($evento['fecha_evento']) ? date("d/m/Y H:i:s", strtotime($evento['fecha_evento'])) : 'N/A') ?></td> <td><span class="badge bg-warning text-dark"><?= htmlspecialchars($evento['tipo_evento']) ?></span></td> <td><?= htmlspecialchars($evento['tipo_activo'] ?? 'N/A') ?></td> <td><?= htmlspecialchars($evento['serie'] ?? 'N/A') ?></td> <td><?= htmlspecialchars($evento['marca_activo'] ?? 'N/A') ?></td> <td><?= htmlspecialchars($evento['empresa_activo'] ?? 'N/A') ?></td> <td style="max-width: 350px; white-space: pre-wrap; word-wrap: break-word;"><?= nl2br(htmlspecialchars($evento['descripcion_evento'])) ?></td> <td><?= htmlspecialchars($evento['usuario_responsable'] ?? 'N/A') ?></td> <td><a href="historial.php?id_activo=<?= htmlspecialchars($evento['id_activo_hist']) ?>" class="btn btn-sm btn-outline-info" title="Ver Historial Completo del Activo" target="_blank"><i class="bi bi-list-task"></i> Activo</a></td> </tr> <?php endforeach; ?> </tbody> </table> </div> </div> <?php
            } 
            ?>
        <?php else: ?>
            <div class="alert alert-info text-center mt-4" role="alert">
                <i class="bi bi-exclamation-circle"></i> No hay datos disponibles para el informe: "<?= htmlspecialchars($titulo_informe_actual) ?>".
            </div>
        <?php endif; ?>
    <?php elseif ($tipo_informe_seleccionado == 'seleccione'): ?>
           <div class="alert alert-light text-center" role="alert" style="padding: 3rem; border: 1px dashed #ccc;">
             <i class="bi bi-clipboard-data" style="font-size: 3rem; color: #0d6efd;"></i><br><br>
             <h4 class="text-primary">Central de Informes</h4>
             <p class="text-muted fs-5">Por favor, seleccione un tipo de informe de las opciones superiores para visualizar los datos.</p>
           </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>