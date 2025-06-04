<?php
error_reporting(E_ALL);
ini_set('display_errors', 1); 

session_start();
require_once 'backend/auth_check.php'; 
restringir_acceso_pagina(['admin', 'tecnico', 'auditor']);

require_once 'backend/db.php';

$conexion_error_msg = null;
if (isset($conn) && !isset($conexion)) {
    $conexion = $conn;
} elseif (!isset($conn) && !isset($conexion)) {
    // Fallback de conexión si $conexion no está predefinida (no recomendado para producción)
    $servername = "localhost"; $username = "root"; $password = ""; $dbname = "inventario";
    $conexion = new mysqli($servername, $username, $password, $dbname);
    if ($conexion->connect_error) {
        error_log("Fallo de conexión a la BD (informes.php fallback): " . $conexion->connect_error);
        $conexion_error_msg = "Error de conexión al servidor. Por favor, intente más tarde.";
    }
}

if ($conexion_error_msg || !isset($conexion) || (method_exists($conexion, 'connect_error') && $conexion->connect_error) || !$conexion) {
    error_log("La variable de conexión a la BD no está disponible o es inválida en informes.php.");
    if(!$conexion_error_msg) $conexion_error_msg = "Error crítico de conexión. Contacte al administrador.";
} else {
    $conexion->set_charset("utf8mb4");
}

$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Usuario';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'Desconocido';

$tipo_informe_seleccionado = $_GET['tipo_informe'] ?? 'seleccione';
$fecha_desde = $_GET['fecha_desde'] ?? '';
$fecha_hasta = $_GET['fecha_hasta'] ?? '';

$titulo_pagina_base = "Central de Informes";
$titulo_informe_actual = "";
$datos_para_tabla = [];
$columnas_tabla_html = []; 

if (!function_exists('getEstadoBadgeClass')) { 
    function getEstadoBadgeClass($estado) {
        $estadoLower = strtolower(trim($estado ?? ''));
        switch ($estadoLower) {
            case 'asignado': case 'activo': case 'operativo': case 'bueno': case 'nuevo': case 'disponible': return 'badge bg-success';
            case 'en mantenimiento': case 'en reparación': case 'regular': return 'badge bg-warning text-dark';
            case 'dado de baja': case 'inactivo': case 'malo': return 'badge bg-danger';
            case 'en préstamo': return 'badge bg-primary';
            case 'vencido': return 'badge bg-danger';
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
if (!defined('HISTORIAL_TIPO_MANTENIMIENTO')) define('HISTORIAL_TIPO_MANTENIMIENTO', 'MANTENIMIENTO');

$condiciones_fecha_activo = ""; 
$condiciones_fecha_historial = ""; 
$condiciones_fecha_prestamo = "";
$params_fecha = [];
$types_fecha = "";

if (!empty($fecha_desde) && !empty($fecha_hasta)) {
    $fecha_hasta_ajustada = $fecha_hasta . ' 23:59:59';
    $condiciones_fecha_activo = " AND at.fecha_compra BETWEEN ? AND ? "; 
    $condiciones_fecha_historial = " AND h.fecha_evento BETWEEN ? AND ? ";
    $condiciones_fecha_prestamo = " AND p.fecha_prestamo BETWEEN ? AND ? ";
    $params_fecha = [$fecha_desde, $fecha_hasta_ajustada];
    $types_fecha = "ss";
} elseif (!empty($fecha_desde)) {
    $condiciones_fecha_activo = " AND at.fecha_compra >= ? "; 
    $condiciones_fecha_historial = " AND h.fecha_evento >= ? ";
    $condiciones_fecha_prestamo = " AND p.fecha_prestamo >= ? ";
    $params_fecha = [$fecha_desde];
    $types_fecha = "s";
} elseif (!empty($fecha_hasta)) {
    $fecha_hasta_ajustada = $fecha_hasta . ' 23:59:59';
    $condiciones_fecha_activo = " AND at.fecha_compra <= ? "; 
    $condiciones_fecha_historial = " AND h.fecha_evento <= ? ";
    $condiciones_fecha_prestamo = " AND p.fecha_prestamo <= ? ";
    $params_fecha = [$fecha_hasta_ajustada];
    $types_fecha = "s";
}


if ($tipo_informe_seleccionado !== 'seleccione' && !$conexion_error_msg) {
    $query = "";
    $params_query = $params_fecha; 
    $types_query = $types_fecha;

    $campos_base_select = "at.id, at.serie, at.marca, at.estado, at.valor_aproximado, at.fecha_compra, at.detalles,
                           ta.nombre_tipo_activo, 
                           u.usuario AS cedula_responsable, u.nombre_completo AS nombre_responsable, 
                           u.regional AS regional_responsable, u.empresa AS empresa_responsable,
                           c.nombre_cargo AS cargo_responsable";
    
    $joins_base = "FROM activos_tecnologicos at 
                   LEFT JOIN tipos_activo ta ON at.id_tipo_activo = ta.id_tipo_activo
                   LEFT JOIN usuarios u ON at.id_usuario_responsable = u.id
                   LEFT JOIN cargos c ON u.id_cargo = c.id_cargo";

    // error_log("[INFORME DEBUG] Valor de \$tipo_informe_seleccionado ANTES del switch: '" . $tipo_informe_seleccionado . "'"); 

    switch ($tipo_informe_seleccionado) {
        case 'general':
            $titulo_informe_actual = "Informe General de Activos por Responsable";
            $query = "SELECT {$campos_base_select}, u.aplicaciones_usadas 
                      {$joins_base} 
                      WHERE at.estado != 'Dado de Baja' {$condiciones_fecha_activo} 
                      ORDER BY u.empresa ASC, u.usuario ASC, u.nombre_completo ASC, at.id ASC";
            $columnas_tabla_html = ["ID", "Tipo Activo", "Marca", "Serie", "Responsable", "C.C. Resp.", "Cargo Resp.", "Empresa Resp.", "Regional Resp.", "Estado Activo", "Valor Aprox.", "Fecha Compra", "Aplicaciones Usadas", "Detalles"];
            break;
        case 'por_tipo':
            $titulo_informe_actual = "Informe de Activos por Tipo";
            $query = "SELECT {$campos_base_select} 
                      {$joins_base} 
                      WHERE at.estado != 'Dado de Baja' {$condiciones_fecha_activo} 
                      ORDER BY ta.nombre_tipo_activo ASC, at.id ASC";
            $columnas_tabla_html = ["Tipo Activo", "ID", "Serie", "Marca", "Responsable", "C.C. Resp.", "Estado Activo", "Valor Aprox.", "Fecha Compra"];
            break;
        case 'por_estado':
             $titulo_informe_actual = "Informe de Activos por Estado";
             $query = "SELECT {$campos_base_select} 
                       {$joins_base} 
                       WHERE 1=1 {$condiciones_fecha_activo} 
                       ORDER BY at.estado ASC, at.id ASC";
            $columnas_tabla_html = ["Estado Activo", "ID", "Tipo Activo", "Marca", "Serie", "Responsable", "C.C. Resp.", "Valor Aprox.", "Fecha Compra"];
            break;
        case 'por_regional':
            $titulo_informe_actual = "Informe de Activos por Regional (del Responsable)";
            $query = "SELECT {$campos_base_select} 
                      {$joins_base} 
                      WHERE at.estado != 'Dado de Baja' AND u.regional IS NOT NULL AND u.regional != '' {$condiciones_fecha_activo} 
                      ORDER BY u.regional ASC, at.id ASC";
            $columnas_tabla_html = ["Regional (Resp.)", "ID", "Tipo Activo", "Marca", "Serie", "Responsable", "C.C. Resp.", "Estado Activo", "Valor Aprox."];
            break;
        case 'por_empresa':
            $titulo_informe_actual = "Informe de Activos por Empresa (del Responsable)";
            $query = "SELECT {$campos_base_select} 
                      {$joins_base} 
                      WHERE at.estado != 'Dado de Baja' AND u.empresa IS NOT NULL AND u.empresa != '' {$condiciones_fecha_activo} 
                      ORDER BY u.empresa ASC, at.id ASC";
            $columnas_tabla_html = ["Empresa (Resp.)", "ID", "Tipo Activo", "Marca", "Serie", "Responsable", "C.C. Resp.", "Estado Activo", "Valor Aprox."];
            break;
        case 'calificacion_por_tipo': 
            $titulo_informe_actual = "Informe de Calificaciones por Activo";
            $query = "SELECT {$campos_base_select}, at.satisfaccion_rating
                      {$joins_base} 
                      WHERE at.satisfaccion_rating IS NOT NULL AND at.estado != 'Dado de Baja' {$condiciones_fecha_activo} 
                      ORDER BY ta.nombre_tipo_activo ASC, at.satisfaccion_rating DESC, at.id ASC";
            $columnas_tabla_html = ["Tipo de Activo", "Marca", "Serie", "Responsable (Cédula)", "Empresa (Resp.)", "Regional (Resp.)", "Estado Activo", "Fecha Compra", "Calificación"];
            break;
        case 'dados_baja':
            $titulo_informe_actual = "Informe de Activos Dados de Baja";
            $tipo_baja_const = defined('HISTORIAL_TIPO_BAJA') ? HISTORIAL_TIPO_BAJA : 'BAJA';
            $query = "SELECT at.id, ta.nombre_tipo_activo, at.marca, at.serie, at.estado, 
                             u.nombre_completo AS nombre_ultimo_responsable, u.usuario AS cedula_ultimo_responsable, 
                             u.empresa AS empresa_responsable, u.regional AS regional_responsable,
                             at.valor_aproximado, at.detalles AS detalles_activo, at.fecha_compra,
                             h_baja.descripcion_evento AS motivo_observaciones_baja, 
                             h_baja.fecha_evento AS fecha_efectiva_baja 
                      FROM activos_tecnologicos at
                      LEFT JOIN tipos_activo ta ON at.id_tipo_activo = ta.id_tipo_activo
                      LEFT JOIN usuarios u ON at.id_usuario_responsable = u.id 
                      LEFT JOIN ( 
                          SELECT h1.id_activo, h1.descripcion_evento, h1.fecha_evento 
                          FROM historial_activos h1 
                          INNER JOIN (
                              SELECT id_activo, MAX(id_historial) as max_id_hist_baja 
                              FROM historial_activos 
                              WHERE tipo_evento = ? 
                              GROUP BY id_activo
                          ) h2 ON h1.id_activo = h2.id_activo AND h1.id_historial = h2.max_id_hist_baja
                      ) h_baja ON at.id = h_baja.id_activo 
                      WHERE at.estado = 'Dado de Baja' 
                      " . str_replace('h.fecha_evento', 'h_baja.fecha_evento', $condiciones_fecha_historial) . "
                      ORDER BY COALESCE(h_baja.fecha_evento, at.fecha_compra) DESC, at.id ASC";
            if (!empty($types_fecha)) {
                $params_query = array_merge([$tipo_baja_const], $params_fecha);
                $types_query = "s" . $types_fecha;
            } else {
                $params_query = [$tipo_baja_const];
                $types_query = "s";
            }
            $columnas_tabla_html = ["ID", "Tipo", "Marca", "Serie", "Empresa (Resp.)", "Últ. Responsable (Cédula)", "Fecha Baja", "Motivo/Obs.", "Acciones"];
            break;
        case 'movimientos':
            $titulo_informe_actual = "Informe de Movimientos y Traslados Recientes";
            $tipo_traslado_const = defined('HISTORIAL_TIPO_TRASLADO') ? HISTORIAL_TIPO_TRASLADO : 'TRASLADO';
            $tipo_asignacion_const = defined('HISTORIAL_TIPO_ASIGNACION_INICIAL') ? HISTORIAL_TIPO_ASIGNACION_INICIAL : 'ASIGNACIÓN INICIAL';
            $tipo_creacion_const = defined('HISTORIAL_TIPO_CREACION') ? HISTORIAL_TIPO_CREACION : 'CREACIÓN';
            $tipo_reactivacion_const = defined('HISTORIAL_TIPO_REACTIVACION') ? HISTORIAL_TIPO_REACTIVACION : 'REACTIVACIÓN';
            $query = "SELECT h.id_historial, h.fecha_evento, h.tipo_evento, h.descripcion_evento, 
                             h.usuario_responsable AS usuario_sistema, 
                             a.id as id_activo_hist, ta.nombre_tipo_activo, a.serie, a.marca AS marca_activo,
                             u_resp.nombre_completo AS nombre_responsable_actual, 
                             u_resp.empresa AS empresa_responsable_actual
                      FROM historial_activos h 
                      JOIN activos_tecnologicos a ON h.id_activo = a.id
                      LEFT JOIN tipos_activo ta ON a.id_tipo_activo = ta.id_tipo_activo
                      LEFT JOIN usuarios u_resp ON a.id_usuario_responsable = u_resp.id 
                      WHERE (h.tipo_evento IN (?, ?, ?, ?)) 
                      {$condiciones_fecha_historial} 
                      ORDER BY h.fecha_evento DESC LIMIT 100";
            $params_base_mov = [$tipo_traslado_const, $tipo_asignacion_const, $tipo_creacion_const, $tipo_reactivacion_const];
            $types_base_mov = "ssss";
            if (!empty($types_fecha)) {
                $params_query = array_merge($params_base_mov, $params_fecha);
                $types_query = $types_base_mov . $types_fecha;
            } else {
                $params_query = $params_base_mov;
                $types_query = $types_base_mov;
            }
            $columnas_tabla_html = ['Fecha', 'Tipo Evento', 'Tipo Activo', 'Serie', 'Marca', 'Empresa (Resp. Actual)', 'Descripción', 'Usuario Sis.', 'Ver Activo'];
            break;
        case 'activos_con_mantenimientos':
            $titulo_informe_actual = "Informe de Activos con Mantenimientos Realizados";
            $tipo_mantenimiento_const = HISTORIAL_TIPO_MANTENIMIENTO;
            $query = "SELECT at.id AS activo_id, ta.nombre_tipo_activo, at.marca, at.serie, 
                             u.nombre_completo AS nombre_responsable, u.usuario AS cedula_responsable, 
                             u.empresa AS empresa_responsable, u.regional AS regional_responsable, 
                             at.estado AS activo_estado, 
                             h.fecha_evento AS fecha_registro_historial, h.datos_nuevos AS datos_mantenimiento_json
                      FROM activos_tecnologicos at 
                      JOIN historial_activos h ON at.id = h.id_activo
                      LEFT JOIN tipos_activo ta ON at.id_tipo_activo = ta.id_tipo_activo
                      LEFT JOIN usuarios u ON at.id_usuario_responsable = u.id
                      WHERE h.tipo_evento = ? AND at.estado != 'Dado de Baja' {$condiciones_fecha_historial} 
                      ORDER BY at.id ASC, h.fecha_evento DESC";
            if (!empty($types_fecha)) {
                $params_query = array_merge([$tipo_mantenimiento_const], $params_fecha);
                $types_query = "s" . $types_fecha;
            } else {
                $params_query = [$tipo_mantenimiento_const];
                $types_query = "s";
            }
            $columnas_tabla_html = ["ID Activo", "Tipo", "Marca", "Serie", "Responsable (Cédula)", "Empresa (Resp.)", "Fecha Mant.", "Diagnóstico", "Costo", "Proveedor", "Téc. Interno", "Detalle Reparación"];
            break;
        case 'activos_en_prestamo': 
            $titulo_informe_actual = "Informe de Activos Actualmente en Préstamo";
            $query = "SELECT 
                        p.id_prestamo, at.id as id_activo, ta.nombre_tipo_activo, at.marca, at.serie, at.Codigo_Inv,
                        u_presta.nombre_completo AS nombre_usuario_presta,
                        u_recibe.nombre_completo AS nombre_usuario_recibe, u_recibe.usuario AS cedula_usuario_recibe,
                        cr.nombre_cargo AS cargo_usuario_recibe, p.fecha_prestamo, p.fecha_devolucion_esperada,
                        p.estado_activo_prestamo, p.observaciones_prestamo, p.estado_prestamo
                      FROM prestamos_activos p
                      JOIN activos_tecnologicos at ON p.id_activo = at.id
                      LEFT JOIN tipos_activo ta ON at.id_tipo_activo = ta.id_tipo_activo
                      JOIN usuarios u_presta ON p.id_usuario_presta = u_presta.id
                      JOIN usuarios u_recibe ON p.id_usuario_recibe = u_recibe.id
                      LEFT JOIN cargos cr ON u_recibe.id_cargo = cr.id_cargo
                      WHERE p.estado_prestamo IN ('Activo', 'Vencido') 
                      {$condiciones_fecha_prestamo} 
                      ORDER BY p.fecha_devolucion_esperada ASC, p.fecha_prestamo ASC";
            $columnas_tabla_html = ["ID Préstamo", "Tipo Activo", "Marca", "Serie", "Cód. Inv.", "Prestado Por", "Prestado A", "Cargo Receptor", "F. Préstamo", "F. Dev. Esperada", "Estado Préstamo", "Estado Activo (al prestar)", "Obs. Préstamo"];
            break;
        default:
            $titulo_informe_actual = "Tipo de Informe No Válido";
            $query = ""; 
            error_log("[INFORME DEBUG] Switch DEFAULT case hit. Valor de \$tipo_informe_seleccionado: '" . $tipo_informe_seleccionado . "'"); 
            break;
    }

    if (!empty($query)) {
        $stmt = $conexion->prepare($query);
        if ($stmt) {
            if (!empty($params_query) && !empty($types_query)) { 
                if (strlen($types_query) == count($params_query)) {
                    $stmt->bind_param($types_query, ...$params_query);
                } else {
                    error_log("[INFORME ERROR] Discrepancia en tipos y parámetros para '{$tipo_informe_seleccionado}'. Types: '{$types_query}', #Params: " . count($params_query));
                    $datos_para_tabla = []; 
                }
            }
            if (empty($conexion->error) && (empty($types_query) || strlen($types_query) == count($params_query))) {
                if ($stmt->execute()) {
                    $resultado_query = $stmt->get_result();
                    if ($resultado_query) {
                        $datos_para_tabla = $resultado_query->fetch_all(MYSQLI_ASSOC);
                    } else { error_log("[INFORME ERROR] Error al obtener resultado ({$tipo_informe_seleccionado}): " . $stmt->error); }
                } else { error_log("[INFORME ERROR] Error al ejecutar consulta ({$tipo_informe_seleccionado}): " . $stmt->error); }
            }
            $stmt->close();
        } else { error_log("[INFORME ERROR] Error al preparar la consulta ({$tipo_informe_seleccionado}): " . $conexion->error); }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($titulo_pagina_base) ?> <?= $titulo_informe_actual ? "- " . htmlspecialchars($titulo_informe_actual) : "" ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding-top: 80px; background-color: #f8f9fa; }
        .top-bar-custom { position: fixed; top: 0; left: 0; right: 0; z-index: 1030; display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 1.5rem; background-color: #ffffff; border-bottom: 1px solid #dee2e6; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .logo-container-top img { width: auto; height: 75px; object-fit: contain; margin-right: 15px; }
        .user-info-top { font-size: 0.9rem; }
        .container-main { margin-top: 20px; margin-bottom: 40px; }
        h3.page-title, h4.informe-title { color: #191970; font-weight: 600; }
        
        .informe-selector-card { 
            transition: transform .2s, box-shadow .2s; 
            cursor: pointer; 
            border: 1px solid #ddd; 
            background-color: #fff; 
            color: #212529; 
            text-decoration: none; /* Asegurar que el enlace no tenga subrayado */
        }
        .informe-selector-card:hover { 
            transform: translateY(-5px); 
            box-shadow: 0 8px 20px rgba(0,0,0,0.12); 
        }
        .informe-selector-card.border-primary.shadow,
        .informe-selector-card.border-secondary.shadow,
        .informe-selector-card.border-success.shadow,
        .informe-selector-card.border-info.shadow,
        .informe-selector-card.border-dark.shadow,
        .informe-selector-card.border-warning.shadow,
        .informe-selector-card.border-danger.shadow { 
            /* Los estilos de la tarjeta seleccionada se aplican por la clase de borde y sombra */
            /* El color del texto dentro de la tarjeta se hereda o se puede definir aquí si es necesario */
        }
        .informe-selector-card .card-body { 
            display: flex; 
            flex-direction: column; 
            justify-content: center; 
            align-items: center; 
            min-height: 130px; 
            text-align: center; 
            padding: 1rem;
        }
        .informe-selector-card i { 
            font-size: 2.2rem; 
            margin-bottom: 0.75rem; 
        }
        .informe-selector-card h5.card-title { /* Más específico para el título de la tarjeta */
            font-size: 1.1rem; 
            font-weight: 500;
            margin-bottom: 0; 
            color: #343a40; /* Color de texto para los títulos de las tarjetas */
        }
         .informe-selector-card:hover h5.card-title {
            color: #0d6efd; /* Opcional: Cambiar color del título al hacer hover en la tarjeta */
        }


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
        
        .filters-toggle-button { font-weight: 500; color: #0d6efd; cursor: pointer; }
        .filters-toggle-button:hover { text-decoration: underline; }
        .filters-section-collapsible { background-color: #f8f9fa; padding: 1rem; border-radius: 0.375rem; margin-top: 0.5rem; border: 1px solid #dee2e6;}
    </style>
</head>
<body>

<div class="top-bar-custom">
    <div class="logo-container-top"> <a href="menu.php" title="Ir a Inicio"> <img src="imagenes/logo.png" alt="Logo ARPESOD ASOCIADOS SAS"></a></div>
    <div class="d-flex align-items-center">
        <span class="text-dark me-3 user-info-top"> <i class="bi bi-person-circle"></i> <?= htmlspecialchars($nombre_usuario_actual_sesion) ?> (<?= htmlspecialchars(ucfirst($rol_usuario_actual_sesion)) ?>) </span>
        <form action="logout.php" method="post" class="d-flex"> <button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</button> </form>
    </div>
</div>

<div class="container-main container">
    <h3 class="page-title text-center mb-3"><?= htmlspecialchars($titulo_pagina_base) ?></h3>

    <div class="mb-3 text-center">
        <a class="filters-toggle-button" data-bs-toggle="collapse" href="#collapseDateFilters" role="button" aria-expanded="false" aria-controls="collapseDateFilters">
            <i class="bi bi-calendar-range"></i> Filtrar por Fechas
        </a>
    </div>
    <div class="collapse mb-4" id="collapseDateFilters">
        <div class="filters-section-collapsible">
            <form method="GET" action="informes.php" id="formFiltrosInformes">
                <input type="hidden" name="tipo_informe" value="<?= htmlspecialchars($tipo_informe_seleccionado) ?>" id="hiddenTipoInforme">
                <div class="row g-2 align-items-end justify-content-center">
                    <div class="col-md-3 col-lg-3">
                        <label for="fecha_desde" class="form-label form-label-sm">Fecha Desde:</label>
                        <input type="date" class="form-control form-control-sm" id="fecha_desde" name="fecha_desde" value="<?= htmlspecialchars($fecha_desde) ?>">
                    </div>
                    <div class="col-md-3 col-lg-3">
                        <label for="fecha_hasta" class="form-label form-label-sm">Fecha Hasta:</label>
                        <input type="date" class="form-control form-control-sm" id="fecha_hasta" name="fecha_hasta" value="<?= htmlspecialchars($fecha_hasta) ?>">
                    </div>
                    <div class="col-md-auto col-lg-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100">Aplicar</button>
                    </div>
                    <div class="col-md-auto col-lg-2">
                        <a href="informes.php?tipo_informe=<?= htmlspecialchars($tipo_informe_seleccionado) ?>" class="btn btn-outline-secondary btn-sm w-100">Limpiar</a>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-lg-4 g-4 mb-5 justify-content-center">
        <div class="col">
            <a href="#" data-tipo="general" class="text-decoration-none">
                <div class="card informe-selector-card h-100 <?= ($tipo_informe_seleccionado == 'general') ? 'border-primary shadow' : '' ?>">
                    <div class="card-body"> <i class="bi bi-list-ul text-primary"></i> <h5 class="card-title mt-2">General por Responsable</h5></div>
                </div>
            </a>
        </div>
        <div class="col">
            <a href="#" data-tipo="por_tipo" class="text-decoration-none">
                <div class="card informe-selector-card h-100 <?= ($tipo_informe_seleccionado == 'por_tipo') ? 'border-secondary shadow' : '' ?>">
                    <div class="card-body"> <i class="bi bi-tags-fill text-secondary"></i> <h5 class="card-title mt-2">Activos por Tipo</h5></div>
                </div>
            </a>
        </div>
        <div class="col">
            <a href="#" data-tipo="por_estado" class="text-decoration-none">
                <div class="card informe-selector-card h-100 <?= ($tipo_informe_seleccionado == 'por_estado') ? 'border-success shadow' : '' ?>">
                    <div class="card-body"> <i class="bi bi-check-circle-fill text-success"></i> <h5 class="card-title mt-2">Activos por Estado</h5></div>
                </div>
            </a>
        </div>
        <div class="col">
            <a href="#" data-tipo="por_regional" class="text-decoration-none">
                <div class="card informe-selector-card h-100 <?= ($tipo_informe_seleccionado == 'por_regional') ? 'border-info shadow' : '' ?>">
                    <div class="card-body"> <i class="bi bi-geo-alt-fill text-info"></i> <h5 class="card-title mt-2">Activos por Regional (Resp.)</h5></div>
                </div>
            </a>
        </div>
        <div class="col">
            <a href="#" data-tipo="por_empresa" class="text-decoration-none">
                <div class="card informe-selector-card h-100 <?= ($tipo_informe_seleccionado == 'por_empresa') ? 'border-dark shadow' : '' ?>">
                    <div class="card-body"> <i class="bi bi-building text-dark"></i> <h5 class="card-title mt-2">Activos por Empresa (Resp.)</h5></div>
                </div>
            </a>
        </div>
        <div class="col">
            <a href="#" data-tipo="calificacion_por_tipo" class="text-decoration-none">
                <div class="card informe-selector-card h-100 <?= ($tipo_informe_seleccionado == 'calificacion_por_tipo') ? 'border-warning shadow' : '' ?>">
                    <div class="card-body"> <i class="bi bi-star-fill text-warning"></i> <h5 class="card-title mt-2">Calificaciones por Activo</h5></div>
                </div>
            </a>
        </div>
        <div class="col">
            <a href="#" data-tipo="dados_baja" class="text-decoration-none">
                <div class="card informe-selector-card h-100 <?= ($tipo_informe_seleccionado == 'dados_baja') ? 'border-danger shadow' : '' ?>">
                    <div class="card-body"> <i class="bi bi-trash3-fill text-danger"></i> <h5 class="card-title mt-2">Activos Dados de Baja</h5></div>
                </div>
            </a>
        </div>
        <div class="col">
            <a href="#" data-tipo="movimientos" class="text-decoration-none">
                <div class="card informe-selector-card h-100 <?= ($tipo_informe_seleccionado == 'movimientos') ? 'border-secondary shadow' : '' ?>">
                    <div class="card-body"> <i class="bi bi-truck text-secondary"></i> <h5 class="card-title mt-2">Movimientos Recientes</h5></div>
                </div>
            </a>
        </div>
        <div class="col">
            <a href="#" data-tipo="activos_con_mantenimientos" class="text-decoration-none">
                <div class="card informe-selector-card h-100 <?= ($tipo_informe_seleccionado == 'activos_con_mantenimientos') ? 'border-primary shadow' : '' ?>">
                    <div class="card-body"> <i class="bi bi-tools text-primary"></i> <h5 class="card-title mt-2">Activos con Mantenimientos</h5></div>
                </div>
            </a>
        </div>
        <div class="col"> 
            <a href="#" data-tipo="activos_en_prestamo" class="text-decoration-none">
                <div class="card informe-selector-card h-100 <?= ($tipo_informe_seleccionado == 'activos_en_prestamo') ? 'border-info shadow' : '' ?>"> 
                    <div class="card-body"> <i class="bi bi-person-bounding-box text-info"></i><h5>Activos en Préstamo</h5></div>
                </div>
            </a>
        </div>
    </div>
    
    <?php if ($tipo_informe_seleccionado !== 'seleccione' && !empty($titulo_informe_actual) && $titulo_informe_actual !== "Tipo de Informe No Válido") : ?>
        <hr class="my-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="informe-title mb-0"><i class="bi bi-table"></i> <?= htmlspecialchars($titulo_informe_actual) ?>
                <?php if(!empty($fecha_desde) || !empty($fecha_hasta)): ?>
                    <small class="text-muted fs-6">(Filtrado 
                        <?php if(!empty($fecha_desde)) echo " desde " . htmlspecialchars(date("d/m/Y", strtotime($fecha_desde))); ?>
                        <?php if(!empty($fecha_hasta)) echo " hasta " . htmlspecialchars(date("d/m/Y", strtotime($fecha_hasta))); ?>
                    )</small>
                <?php endif; ?>
            </h4>
            <?php if (!empty($datos_para_tabla)): ?>
            <a href="exportar_excel.php?tipo_informe=<?= urlencode($tipo_informe_seleccionado) ?>&fecha_desde=<?= urlencode($fecha_desde) ?>&fecha_hasta=<?= urlencode($fecha_hasta) ?>" class="btn btn-sm btn-export">
                <i class="bi bi-file-earmark-excel-fill"></i> Exportar a Excel
            </a>
            <?php endif; ?>
        </div>

        <?php if (!empty($datos_para_tabla)) : ?>
            <?php
            // Lógica de renderizado de tablas
            if ($tipo_informe_seleccionado == 'general') {
                $current_group_key_general = null; $asset_item_number_general = 0;
                foreach ($datos_para_tabla as $activo_gen) :
                    $responsable_key = ($activo_gen['empresa_responsable'] ?? 'SinEmpresa') . '-' . $activo_gen['cedula_responsable'];
                    if ($responsable_key !== $current_group_key_general) :
                        if ($current_group_key_general !== null) echo '</tbody></table></div></div>'; 
                        $current_group_key_general = $responsable_key; $asset_item_number_general = 1;
            ?>
                        <div class="user-asset-group">
                            <div class="user-info-header">
                                <h4><?= htmlspecialchars($activo_gen['nombre_responsable'] ?? 'N/A') ?> 
                                    <small class="text-muted">(Empresa: <?= htmlspecialchars($activo_gen['empresa_responsable'] ?? 'N/A') ?>)</small>
                                </h4>
                                <p><strong>C.C:</strong> <?= htmlspecialchars($activo_gen['cedula_responsable'] ?? 'N/A') ?> | <strong>Cargo:</strong> <?= htmlspecialchars($activo_gen['cargo_responsable'] ?? 'N/A') ?></p>
                            </div>
                            <div class="table-responsive">
                                <table class="table-minimalist">
                                    <thead><tr><th>#</th><th>Tipo Activo</th><th>Marca</th><th>Serie</th><th>Estado</th><th>Valor</th><th>Regional (Resp.)</th><th>Fecha Compra</th><th>Aplicaciones</th><th>Detalles</th></tr></thead>
                                    <tbody>
            <?php       endif; ?>
                                <tr>
                                    <td><span class="asset-item-number"><?= $asset_item_number_general++ ?>.</span></td>
                                    <td><?= htmlspecialchars($activo_gen['nombre_tipo_activo'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($activo_gen['marca'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($activo_gen['serie'] ?? 'N/A') ?></td>
                                    <td><span class="<?= getEstadoBadgeClass($activo_gen['estado']) ?>"><?= htmlspecialchars($activo_gen['estado'] ?? 'N/A') ?></span></td>
                                    <td>$<?= htmlspecialchars(number_format(floatval($activo_gen['valor_aproximado'] ?? 0), 0, ',', '.')) ?></td>
                                    <td><?= htmlspecialchars($activo_gen['regional_responsable'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars(!empty($activo_gen['fecha_compra']) ? date("d/m/Y", strtotime($activo_gen['fecha_compra'])) : 'N/A') ?></td>
                                    <td style="max-width: 200px; white-space: pre-wrap; word-wrap: break-word;"><?= htmlspecialchars($activo_gen['aplicaciones_usadas'] ?? 'N/A') ?></td>
                                    <td style="max-width: 200px; white-space: pre-wrap; word-wrap: break-word;"><?= nl2br(htmlspecialchars($activo_gen['detalles'] ?? 'N/A')) ?></td>
                                </tr>
            <?php 
                endforeach; 
                if ($current_group_key_general !== null) echo '</tbody></table></div></div>';
            } elseif (in_array($tipo_informe_seleccionado, ['por_tipo', 'por_estado', 'por_regional', 'por_empresa'])) {
                $current_group_key_field = null; $asset_item_number_field = 0; $group_by_field = ''; $group_by_field_label = '';
                switch ($tipo_informe_seleccionado) {
                    case 'por_tipo': $group_by_field = 'nombre_tipo_activo'; $group_by_field_label = 'Tipo de Activo'; $columnas_tabla_html = ["#", "Serie", "Marca", "Responsable (C.C.)", "Estado", "Valor", "Fecha Compra"]; break;
                    case 'por_estado': $group_by_field = 'estado'; $group_by_field_label = 'Estado'; $columnas_tabla_html = ["#", "Tipo Activo", "Marca", "Serie", "Responsable (C.C.)", "Valor", "Fecha Compra"]; break;
                    case 'por_regional': $group_by_field = 'regional_responsable'; $group_by_field_label = 'Regional (Responsable)'; $columnas_tabla_html = ["#", "Tipo Activo", "Marca", "Serie", "Responsable (C.C.)", "Estado", "Valor"]; break;
                    case 'por_empresa': $group_by_field = 'empresa_responsable'; $group_by_field_label = 'Empresa (Responsable)'; $columnas_tabla_html = ["#", "Tipo Activo", "Marca", "Serie", "Responsable (C.C.)", "Estado", "Valor"]; break;
                }
                foreach ($datos_para_tabla as $activo_field) :
                    $current_group_value = $activo_field[$group_by_field] ?? 'Sin Asignar'; 
                    if ($current_group_value !== $current_group_key_field) :
                        if ($current_group_key_field !== null) echo '</tbody></table></div></div>';
                        $current_group_key_field = $current_group_value; $asset_item_number_field = 1;
            ?>
                        <div class="report-group-container">
                            <div class="group-info-header"><h4><?= htmlspecialchars($group_by_field_label) ?>: <?= htmlspecialchars($current_group_key_field ?: 'N/A') ?></h4></div>
                            <div class="table-responsive"> <table class="table-minimalist"> <thead><tr>
                                <?php foreach($columnas_tabla_html as $col): echo "<th>".htmlspecialchars($col)."</th>"; endforeach; ?>
                            </tr></thead><tbody>
            <?php       endif; ?>
                        <tr>
                            <td><span class="asset-item-number"><?= $asset_item_number_field++ ?>.</span></td>
                            <?php if($tipo_informe_seleccionado == 'por_tipo'): ?>
                                <td><?= htmlspecialchars($activo_field['serie'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($activo_field['marca'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($activo_field['nombre_responsable'] ?? 'N/A') ?> (<?= htmlspecialchars($activo_field['cedula_responsable'] ?? 'N/A')?>)</td>
                                <td><span class="<?= getEstadoBadgeClass($activo_field['estado']) ?>"><?= htmlspecialchars($activo_field['estado'] ?? 'N/A') ?></span></td>
                            <?php elseif($tipo_informe_seleccionado == 'por_estado'): ?>
                                <td><?= htmlspecialchars($activo_field['nombre_tipo_activo'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($activo_field['marca'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($activo_field['serie'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($activo_field['nombre_responsable'] ?? 'N/A') ?> (<?= htmlspecialchars($activo_field['cedula_responsable'] ?? 'N/A')?>)</td>
                            <?php elseif(in_array($tipo_informe_seleccionado, ['por_regional', 'por_empresa'])): ?>
                                <td><?= htmlspecialchars($activo_field['nombre_tipo_activo'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($activo_field['marca'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($activo_field['serie'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($activo_field['nombre_responsable'] ?? 'N/A') ?> (<?= htmlspecialchars($activo_field['cedula_responsable'] ?? 'N/A')?>)</td>
                                <td><span class="<?= getEstadoBadgeClass($activo_field['estado']) ?>"><?= htmlspecialchars($activo_field['estado'] ?? 'N/A') ?></span></td>
                            <?php endif; ?>
                            <td>$<?= htmlspecialchars(number_format(floatval($activo_field['valor_aproximado'] ?? 0), 0, ',', '.')) ?></td>
                            <?php if($tipo_informe_seleccionado != 'por_regional' && $tipo_informe_seleccionado != 'por_empresa'): ?>
                                <td><?= htmlspecialchars(!empty($activo_field['fecha_compra']) ? date("d/m/Y", strtotime($activo_field['fecha_compra'])) : 'N/A') ?></td>
                            <?php endif; ?>
                        </tr>
            <?php 
                endforeach; 
                if ($current_group_key_field !== null) echo '</tbody></table></div></div>';
            }
            // Para los informes que no tienen agrupación interna compleja y usan $columnas_tabla_html directamente
            elseif (in_array($tipo_informe_seleccionado, ['calificacion_por_tipo', 'dados_baja', 'movimientos', 'activos_con_mantenimientos', 'activos_en_prestamo'])) { 
            ?>
                <div class="report-group-container">
                    <div class="table-responsive">
                        <table class="table-minimalist">
                            <thead>
                                <tr>
                                    <?php foreach ($columnas_tabla_html as $header): ?>
                                        <th><?= htmlspecialchars($header) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($datos_para_tabla as $item) : ?>
                                    <tr>
                                        <?php if ($tipo_informe_seleccionado == 'calificacion_por_tipo'): ?>
                                            <td><?= htmlspecialchars($item['nombre_tipo_activo'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($item['marca'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($item['serie'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($item['nombre_responsable'] ?? 'N/A') ?> (<?= htmlspecialchars($item['cedula_responsable'] ?? 'N/A')?>)</td>
                                            <td><?= htmlspecialchars($item['empresa_responsable'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($item['regional_responsable'] ?? 'N/A') ?></td>
                                            <td><span class="<?= getEstadoBadgeClass($item['estado']) ?>"><?= htmlspecialchars($item['estado'] ?? 'N/A') ?></span></td>
                                            <td><?= htmlspecialchars(!empty($item['fecha_compra']) ? date("d/m/Y", strtotime($item['fecha_compra'])) : 'N/A') ?></td>
                                            <td><?= displayStars(floatval($item['satisfaccion_rating'] ?? 0)) ?></td>
                                        <?php elseif ($tipo_informe_seleccionado == 'dados_baja'): ?>
                                            <td><?= htmlspecialchars($item['id']) ?></td>
                                            <td><?= htmlspecialchars($item['nombre_tipo_activo'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($item['marca'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($item['serie'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($item['empresa_responsable'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($item['nombre_ultimo_responsable'] ?? 'N/A') ?> (<?= htmlspecialchars($item['cedula_ultimo_responsable'] ?? 'N/A') ?>)</td>
                                            <td><?= htmlspecialchars(!empty($item['fecha_efectiva_baja']) ? date("d/m/Y H:i:s", strtotime($item['fecha_efectiva_baja'])) : 'N/A') ?></td>
                                            <td style="max-width: 300px; white-space: pre-wrap; word-wrap: break-word;"><?= nl2br(htmlspecialchars($item['motivo_observaciones_baja'] ?? $item['detalles_activo'] ?? 'Ver historial')) ?></td>
                                            <td><a href="historial.php?id_activo=<?= htmlspecialchars($item['id']) ?>" class="btn btn-sm btn-outline-info" title="Ver Historial Completo" target="_blank"><i class="bi bi-list-task"></i> Hist.</a></td>
                                        <?php elseif ($tipo_informe_seleccionado == 'movimientos'): ?>
                                            <td><?= htmlspecialchars(!empty($item['fecha_evento']) ? date("d/m/Y H:i:s", strtotime($item['fecha_evento'])) : 'N/A') ?></td>
                                            <td><span class="badge bg-warning text-dark"><?= htmlspecialchars($item['tipo_evento']) ?></span></td>
                                            <td><?= htmlspecialchars($item['nombre_tipo_activo'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($item['serie'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($item['marca_activo'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($item['empresa_responsable_actual'] ?? 'N/A') ?></td>
                                            <td style="max-width: 350px; white-space: pre-wrap; word-wrap: break-word;"><?= nl2br(htmlspecialchars($item['descripcion_evento'])) ?></td>
                                            <td><?= htmlspecialchars($item['usuario_sistema'] ?? 'N/A') ?></td>
                                            <td><a href="editar.php?id_activo_focus=<?= htmlspecialchars($item['id_activo_hist']) ?>" class="btn btn-sm btn-outline-info" title="Ver Activo" target="_blank"><i class="bi bi-pencil-square"></i> Activo</a></td>
                                        <?php elseif ($tipo_informe_seleccionado == 'activos_con_mantenimientos'): 
                                            $mantenimiento_info = json_decode($item['datos_mantenimiento_json'] ?? '[]', true);
                                            $fecha_reparacion = $mantenimiento_info['fecha_reparacion'] ?? 'N/A';
                                            if ($fecha_reparacion !== 'N/A' && !empty($fecha_reparacion)) { try { $fecha_reparacion = date("d/m/Y", strtotime($fecha_reparacion)); } catch (Exception $e) { $fecha_reparacion = $mantenimiento_info['fecha_reparacion'];} }
                                            $diagnostico = $mantenimiento_info['diagnostico'] ?? 'N/A';
                                            $costo_reparacion = isset($mantenimiento_info['costo_reparacion']) && is_numeric($mantenimiento_info['costo_reparacion']) ? '$' . number_format(floatval($mantenimiento_info['costo_reparacion']), 0, ',', '.') : 'N/A';
                                            $nombre_proveedor = $mantenimiento_info['nombre_proveedor'] ?? 'N/A';
                                            $nombre_tecnico = $mantenimiento_info['nombre_tecnico_interno'] ?? 'N/A';
                                            $detalle_reparacion_mant = $mantenimiento_info['detalle_reparacion'] ?? 'N/A';
                                        ?>
                                            <td><?= htmlspecialchars($item['activo_id']) ?></td> 
                                            <td><?= htmlspecialchars($item['nombre_tipo_activo'] ?? 'N/A') ?></td> 
                                            <td><?= htmlspecialchars($item['marca'] ?? 'N/A') ?></td> 
                                            <td><?= htmlspecialchars($item['serie'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($item['nombre_responsable'] ?? 'N/A') ?> (<?= htmlspecialchars($item['cedula_responsable'] ?? 'N/A') ?>)</td>
                                            <td><?= htmlspecialchars($item['empresa_responsable'] ?? 'N/A') ?></td> 
                                            <td><?= htmlspecialchars($fecha_reparacion) ?></td>
                                            <td><?= htmlspecialchars($diagnostico) ?></td> 
                                            <td><?= htmlspecialchars($costo_reparacion) ?></td>
                                            <td><?= htmlspecialchars($nombre_proveedor) ?></td> 
                                            <td><?= htmlspecialchars($nombre_tecnico) ?></td>
                                            <td style="max-width: 250px; white-space: pre-wrap; word-wrap: break-word;"><?= nl2br(htmlspecialchars($detalle_reparacion_mant)) ?></td>
                                        <?php elseif ($tipo_informe_seleccionado == 'activos_en_prestamo'): ?>
                                            <td><?= htmlspecialchars($item['id_prestamo']) ?></td>
                                            <td><?= htmlspecialchars($item['nombre_tipo_activo'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($item['marca'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($item['serie'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($item['activo_codigo_inv'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($item['nombre_usuario_presta'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($item['nombre_usuario_recibe'] ?? 'N/A') ?> (<?= htmlspecialchars($item['cedula_usuario_recibe'] ?? 'N/A')?>)</td>
                                            <td><?= htmlspecialchars($item['cargo_usuario_recibe'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars(!empty($item['fecha_prestamo']) ? date("d/m/Y", strtotime($item['fecha_prestamo'])) : 'N/A') ?></td>
                                            <td><?= htmlspecialchars(!empty($item['fecha_devolucion_esperada']) ? date("d/m/Y", strtotime($item['fecha_devolucion_esperada'])) : 'N/A') ?></td>
                                            <td><span class="<?= getEstadoBadgeClass($item['estado_prestamo']) ?>"><?= htmlspecialchars($item['estado_prestamo'] ?? 'N/A') ?></span></td>
                                            <td><?= nl2br(htmlspecialchars($item['estado_activo_prestamo'] ?? 'N/A')) ?></td>
                                            <td><?= nl2br(htmlspecialchars($item['observaciones_prestamo'] ?? 'N/A')) ?></td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php
            } 
            ?>
        <?php else: ?>
            <div class="alert alert-info text-center mt-4" role="alert"> 
                <i class="bi bi-exclamation-circle"></i> No hay datos disponibles para el informe<?= (!empty($fecha_desde) || !empty($fecha_hasta)) ? " con los filtros de fecha aplicados" : "" ?>: "<?= htmlspecialchars($titulo_informe_actual) ?>". 
            </div>
        <?php endif; ?>
    <?php elseif ($tipo_informe_seleccionado == 'seleccione' && !$conexion_error_msg): ?>
         <div class="alert alert-light text-center" role="alert" style="padding: 3rem; border: 1px dashed #ccc;">
             <i class="bi bi-clipboard-data" style="font-size: 3rem; color: #0d6efd;"></i><br><br>
             <h4 class="text-primary">Central de Informes</h4>
             <p class="text-muted fs-5">Por favor, seleccione un tipo de informe de las opciones superiores para visualizar los datos.<br>También puede aplicar filtros de fecha para refinar su búsqueda.</p>
         </div>
    <?php endif; ?>
    <?php if ($conexion_error_msg): ?>
         <div class="alert alert-danger mt-4"><?= $conexion_error_msg ?></div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const formFiltros = document.getElementById('formFiltrosInformes');
    const hiddenTipoInforme = document.getElementById('hiddenTipoInforme');
    const collapseDateFiltersEl = document.getElementById('collapseDateFilters');
    
    if (collapseDateFiltersEl) {
        const bsCollapseDateFilters = new bootstrap.Collapse(collapseDateFiltersEl, { toggle: false });
        const fechaDesdeInput = document.getElementById('fecha_desde');
        const fechaHastaInput = document.getElementById('fecha_hasta');
        if ((fechaDesdeInput && fechaDesdeInput.value) || (fechaHastaInput && fechaHastaInput.value)) {
            bsCollapseDateFilters.show();
        }
    }

    document.querySelectorAll('a[data-tipo]').forEach(cardLink => { 
        cardLink.addEventListener('click', function(event) {
            event.preventDefault();
            const tipoInforme = this.getAttribute('data-tipo'); 
            const fechaDesdeInput = document.getElementById('fecha_desde');
            const fechaHastaInput = document.getElementById('fecha_hasta');

            let url = 'informes.php?tipo_informe=' + tipoInforme;
            if (fechaDesdeInput && fechaDesdeInput.value) {
                url += '&fecha_desde=' + fechaDesdeInput.value;
            }
            if (fechaHastaInput && fechaHastaInput.value) {
                url += '&fecha_hasta=' + fechaHastaInput.value;
            }
            window.location.href = url;
        });
    });
});
</script>
</body>
</html>
