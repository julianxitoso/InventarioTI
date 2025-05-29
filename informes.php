<?php
error_reporting(E_ALL);
ini_set('display_errors', 1); // Considera desactivar esto en producción y usar logs

session_start();
require_once 'backend/auth_check.php'; 
restringir_acceso_pagina(['admin', 'tecnico', 'auditor']);

require_once 'backend/db.php';

if (isset($conn) && !isset($conexion)) {
    $conexion = $conn;
} elseif (!isset($conn) && !isset($conexion)) {
    // Fallback de conexión si $conexion no está predefinida (no recomendado para producción)
    $servername = "localhost"; $username = "root"; $password = ""; $dbname = "inventario";
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
            case 'asignado': case 'activo': case 'operativo': case 'bueno': case 'nuevo': return 'badge bg-success';
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
if (!defined('HISTORIAL_TIPO_MANTENIMIENTO')) define('HISTORIAL_TIPO_MANTENIMIENTO', 'MANTENIMIENTO');

// Asumimos que at.fecha_compra es la fecha de registro/adquisición del activo
// Si es otro campo como at.fecha_creacion, ajusta aquí. Usaré at.fecha_compra por consistencia con editar.php
$condiciones_fecha_activo = ""; 
$condiciones_fecha_historial = ""; 
$params_fecha = [];
$types_fecha = "";

if (!empty($fecha_desde) && !empty($fecha_hasta)) {
    $fecha_hasta_ajustada = $fecha_hasta . ' 23:59:59';
    $condiciones_fecha_activo = " AND at.fecha_compra BETWEEN ? AND ? "; // Ajustado a fecha_compra
    $condiciones_fecha_historial = " AND h.fecha_evento BETWEEN ? AND ? ";
    $params_fecha = [$fecha_desde, $fecha_hasta_ajustada];
    $types_fecha = "ss";
} elseif (!empty($fecha_desde)) {
    $condiciones_fecha_activo = " AND at.fecha_compra >= ? "; // Ajustado a fecha_compra
    $condiciones_fecha_historial = " AND h.fecha_evento >= ? ";
    $params_fecha = [$fecha_desde];
    $types_fecha = "s";
} elseif (!empty($fecha_hasta)) {
    $fecha_hasta_ajustada = $fecha_hasta . ' 23:59:59';
    $condiciones_fecha_activo = " AND at.fecha_compra <= ? "; // Ajustado a fecha_compra
    $condiciones_fecha_historial = " AND h.fecha_evento <= ? ";
    $params_fecha = [$fecha_hasta_ajustada];
    $types_fecha = "s";
}


if ($tipo_informe_seleccionado !== 'seleccione') {
    $query = "";
    $params_query = $params_fecha; 
    $types_query = $types_fecha;

    // Campos base a seleccionar para la mayoría de informes de activos
    $campos_base_select = "at.id, at.serie, at.marca, at.estado, at.valor_aproximado, at.fecha_compra, at.detalles,
                           ta.nombre_tipo_activo, 
                           u.usuario AS cedula_responsable, u.nombre_completo AS nombre_responsable, 
                           u.regional AS regional_responsable, u.empresa AS empresa_responsable,
                           c.nombre_cargo AS cargo_responsable";
    
    $joins_base = "FROM activos_tecnologicos at 
                   LEFT JOIN tipos_activo ta ON at.id_tipo_activo = ta.id_tipo_activo
                   LEFT JOIN usuarios u ON at.id_usuario_responsable = u.id
                   LEFT JOIN cargos c ON u.id_cargo = c.id_cargo";

    switch ($tipo_informe_seleccionado) {
        case 'general':
            $titulo_informe_actual = "Informe General de Activos por Responsable";
            // Se añade u.aplicaciones_usadas si existe esa columna en 'usuarios'
            $query = "SELECT {$campos_base_select}, u.aplicaciones_usadas 
                      {$joins_base} 
                      WHERE at.estado != 'Dado de Baja' {$condiciones_fecha_activo} 
                      ORDER BY u.empresa ASC, u.usuario ASC, u.nombre_completo ASC, at.id ASC";
            // Las columnas para el HTML se definirán más abajo si es necesario o se toman directamente.
            break;

        case 'por_tipo':
            $titulo_informe_actual = "Informe de Activos por Tipo";
            $query = "SELECT {$campos_base_select} 
                      {$joins_base} 
                      WHERE at.estado != 'Dado de Baja' {$condiciones_fecha_activo} 
                      ORDER BY ta.nombre_tipo_activo ASC, at.id ASC";
            break;

        case 'por_estado':
            $titulo_informe_actual = "Informe de Activos por Estado";
            $query = "SELECT {$campos_base_select} 
                      {$joins_base} 
                      WHERE at.estado != 'Dado de Baja' {$condiciones_fecha_activo} 
                      ORDER BY at.estado ASC, at.id ASC";
            break;

        case 'por_regional':
            $titulo_informe_actual = "Informe de Activos por Regional (del Responsable)";
            $query = "SELECT {$campos_base_select} 
                      {$joins_base} 
                      WHERE at.estado != 'Dado de Baja' AND u.regional IS NOT NULL AND u.regional != '' {$condiciones_fecha_activo} 
                      ORDER BY u.regional ASC, at.id ASC";
            break;

        case 'por_empresa':
            $titulo_informe_actual = "Informe de Activos por Empresa (del Responsable)";
            $query = "SELECT {$campos_base_select} 
                      {$joins_base} 
                      WHERE at.estado != 'Dado de Baja' AND u.empresa IS NOT NULL AND u.empresa != '' {$condiciones_fecha_activo} 
                      ORDER BY u.empresa ASC, at.id ASC";
            break;

        case 'calificacion_por_tipo': 
            $titulo_informe_actual = "Informe de Calificaciones por Activo";
            // `at.satisfaccion_rating` es un campo que parece estar en `activos_tecnologicos` según tu consulta original.
            $query = "SELECT {$campos_base_select}, at.satisfaccion_rating
                      {$joins_base} 
                      WHERE at.satisfaccion_rating IS NOT NULL AND at.estado != 'Dado de Baja' {$condiciones_fecha_activo} 
                      ORDER BY ta.nombre_tipo_activo ASC, at.satisfaccion_rating DESC, at.id ASC";
            $columnas_tabla_html = ["Tipo de Activo", "Marca", "Serie", "Responsable (Cédula)", "Empresa (Resp.)", "Regional (Resp.)", "Estado Activo", "Fecha Compra", "Calificación"];
            break;

        case 'dados_baja':
            $titulo_informe_actual = "Informe de Activos Dados de Baja";
            $tipo_baja_const = defined('HISTORIAL_TIPO_BAJA') ? HISTORIAL_TIPO_BAJA : 'BAJA';
            
            $cond_fecha_baja_coalesce = "";
            // Si hay filtro de fecha, debe aplicar a la fecha del evento de baja o, si no existe, a la fecha de compra del activo.
            // Esto es complejo si queremos que el filtro de fecha aplique a la *fecha de baja efectiva*.
            // La subconsulta ya trae h_baja.fecha_evento. Usaremos $condiciones_fecha_historial para h_baja.fecha_evento.
            // Si no hay filtro de fecha para historial, no se añade.
            // Y si el filtro es para activos (at.fecha_compra), se puede añadir aparte.
            // Por simplicidad, si se filtra por fecha, se filtra la fecha del evento de baja.
            
            // Vamos a usar $condiciones_fecha_historial que se refiere a h.fecha_evento
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
                      " . str_replace('h.fecha_evento', 'h_baja.fecha_evento', $condiciones_fecha_historial) . " -- Ajuste para la subconsulta alias
                      ORDER BY COALESCE(h_baja.fecha_evento, at.fecha_compra) DESC, at.id ASC";
            
            // Ajustar params_query y types_query si hay filtro de fecha
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
                             h.usuario_responsable AS usuario_sistema, -- Usuario que hizo el cambio en el sistema
                             a.id as id_activo_hist, ta.nombre_tipo_activo, a.serie, a.marca AS marca_activo,
                             u_resp.nombre_completo AS nombre_responsable_actual, -- Responsable actual del activo
                             u_resp.empresa AS empresa_responsable_actual
                      FROM historial_activos h 
                      JOIN activos_tecnologicos a ON h.id_activo = a.id
                      LEFT JOIN tipos_activo ta ON a.id_tipo_activo = ta.id_tipo_activo
                      LEFT JOIN usuarios u_resp ON a.id_usuario_responsable = u_resp.id -- Para el responsable actual
                      WHERE (h.tipo_evento IN (?, ?, ?, ?)) 
                      {$condiciones_fecha_historial} 
                      ORDER BY h.fecha_evento DESC LIMIT 100";
            
            // Ajustar params_query y types_query
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

// ...
case 'activos_con_mantenimientos':
    $titulo_informe_actual = "Informe de Activos con Mantenimientos Realizados";
    $tipo_mantenimiento_const = HISTORIAL_TIPO_MANTENIMIENTO;
    
    // CORRECCIÓN: Se quitó el comentario PHP de dentro de la cadena SQL
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
    
    // Ajustar params_query y types_query
    if (!empty($types_fecha)) {
        $params_query = array_merge([$tipo_mantenimiento_const], $params_fecha);
        $types_query = "s" . $types_fecha;
    } else {
        $params_query = [$tipo_mantenimiento_const];
        $types_query = "s";
    }
    $columnas_tabla_html = ["ID Activo", "Tipo", "Marca", "Serie", "Responsable (Cédula)", "Empresa (Resp.)", "Fecha Mant.", "Diagnóstico", "Costo", "Proveedor", "Téc. Interno", "Detalle Reparación"];
    break;
// ...
            
        default:
            $titulo_informe_actual = "Tipo de Informe No Válido";
            $query = ""; 
            break;
    }

    if (!empty($query)) {
        error_log("[INFORME DEBUG] Query para '{$tipo_informe_seleccionado}': " . $query);
        error_log("[INFORME DEBUG] Params: " . print_r($params_query, true) . " Types: " . $types_query);
        $stmt = $conexion->prepare($query);
        if ($stmt) {
            if (!empty($params_query) && !empty($types_query)) { 
                // Necesario verificar el número de elementos en params_query y los caracteres en types_query
                if (strlen($types_query) == count($params_query)) {
                    $stmt->bind_param($types_query, ...$params_query);
                } else {
                    error_log("[INFORME ERROR] Discrepancia en número de tipos y parámetros para '{$tipo_informe_seleccionado}'. Types: '{$types_query}', #Params: " . count($params_query));
                    // No ejecutar si hay discrepancia
                    $datos_para_tabla = []; // Asegurar que no haya datos si la consulta no se ejecuta bien
                }
            }
            // Solo ejecutar si no hubo error en bind_param o si no había params
            if (empty($conexion->error) && (empty($types_query) || strlen($types_query) == count($params_query))) {
                 if ($stmt->execute()) {
                    $resultado_query = $stmt->get_result();
                    if ($resultado_query) {
                        $datos_para_tabla = $resultado_query->fetch_all(MYSQLI_ASSOC);
                        error_log("[INFORME INFO] Consulta '{$tipo_informe_seleccionado}' ejecutada. Filas obtenidas: " . count($datos_para_tabla));
                    } else { 
                        error_log("[INFORME ERROR] Error al obtener resultado de la consulta ({$tipo_informe_seleccionado}): " . $stmt->error); 
                    }
                } else {
                    error_log("[INFORME ERROR] Error al ejecutar consulta ({$tipo_informe_seleccionado}): " . $stmt->error);
                }
            }
            $stmt->close();
        } else { 
            error_log("[INFORME ERROR] Error al preparar la consulta ({$tipo_informe_seleccionado}): " . $conexion->error); 
        }
    }
}

// La conexión se cierra al final del script si es la que se creó aquí.
// Si $conexion vino de db.php, db.php debería manejar su cierre o se cierra al final del script PHP.
// Para evitar errores de cierre doble si db.php ya lo hace, podrías no cerrarla aquí explícitamente,
// o tener una bandera para saber si esta página la creó. Por ahora, la quitaré de aquí.
// if(isset($conexion_creada_aqui) && $conexion_creada_aqui && isset($conexion)) { mysqli_close($conexion); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($titulo_pagina_base) ?> <?= $titulo_informe_actual ? "- " . htmlspecialchars($titulo_informe_actual) : "" ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #ffffff !important; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding-top: 80px; }
        .top-bar-custom { position: fixed; top: 0; left: 0; right: 0; z-index: 1030; display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 1.5rem; background-color: #f8f9fa; border-bottom: 1px solid #dee2e6; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .logo-container-top img { width: auto; height: 75px; object-fit: contain; margin-right: 15px; }
        .user-info-top { font-size: 0.9rem; }
        .container-main { margin-top: 20px; margin-bottom: 40px; }
        h3.page-title, h4.informe-title { color: #191970; font-weight: 600; }
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
    <div class="row mb-5 justify-content-center">
        <div class="col-lg-3 col-md-4 col-sm-6 mb-3"> <a href="#" data-tipo="general" class="card informe-selector-card text-decoration-none <?= ($tipo_informe_seleccionado == 'general') ? 'border-primary shadow' : 'text-dark' ?>"> <div class="card-body"> <i class="bi bi-list-ul text-primary"></i> <h5>General por Responsable</h5></div></a></div>
        <div class="col-lg-3 col-md-4 col-sm-6 mb-3"> <a href="#" data-tipo="por_tipo" class="card informe-selector-card text-decoration-none <?= ($tipo_informe_seleccionado == 'por_tipo') ? 'border-secondary shadow' : 'text-dark' ?>"> <div class="card-body"> <i class="bi bi-tags-fill text-secondary"></i> <h5>Activos por Tipo</h5></div></a></div>
        <div class="col-lg-3 col-md-4 col-sm-6 mb-3"> <a href="#" data-tipo="por_estado" class="card informe-selector-card text-decoration-none <?= ($tipo_informe_seleccionado == 'por_estado') ? 'border-success shadow' : 'text-dark' ?>"> <div class="card-body"> <i class="bi bi-check-circle-fill text-success"></i> <h5>Activos por Estado</h5></div></a></div>
        <div class="col-lg-3 col-md-4 col-sm-6 mb-3"> <a href="#" data-tipo="por_regional" class="card informe-selector-card text-decoration-none <?= ($tipo_informe_seleccionado == 'por_regional') ? 'border-info shadow' : 'text-dark' ?>"> <div class="card-body"> <i class="bi bi-geo-alt-fill text-info"></i> <h5>Activos por Regional (Resp.)</h5></div></a></div>
        <div class="col-lg-3 col-md-4 col-sm-6 mb-3"> <a href="#" data-tipo="por_empresa" class="card informe-selector-card text-decoration-none <?= ($tipo_informe_seleccionado == 'por_empresa') ? 'border-dark shadow' : 'text-dark' ?>"> <div class="card-body"> <i class="bi bi-building text-dark"></i> <h5>Activos por Empresa (Resp.)</h5></div></a></div>
        <div class="col-lg-3 col-md-4 col-sm-6 mb-3"> <a href="#" data-tipo="calificacion_por_tipo" class="card informe-selector-card text-decoration-none <?= ($tipo_informe_seleccionado == 'calificacion_por_tipo') ? 'border-warning shadow' : 'text-dark' ?>"> <div class="card-body"> <i class="bi bi-star-fill text-warning"></i> <h5>Calificaciones por Activo</h5></div></a></div>
        <div class="col-lg-3 col-md-4 col-sm-6 mb-3"> <a href="#" data-tipo="dados_baja" class="card informe-selector-card text-decoration-none <?= ($tipo_informe_seleccionado == 'dados_baja') ? 'border-danger shadow' : 'text-dark' ?>"> <div class="card-body"> <i class="bi bi-trash3-fill text-danger"></i> <h5>Activos Dados de Baja</h5></div></a></div>
        <div class="col-lg-3 col-md-4 col-sm-6 mb-3"> <a href="#" data-tipo="movimientos" class="card informe-selector-card text-decoration-none <?= ($tipo_informe_seleccionado == 'movimientos') ? 'border-secondary shadow' : 'text-dark' ?>"> <div class="card-body"> <i class="bi bi-truck text-secondary"></i> <h5>Movimientos Recientes</h5></div></a></div>
        <div class="col-lg-3 col-md-4 col-sm-6 mb-3"> <a href="#" data-tipo="activos_con_mantenimientos" class="card informe-selector-card text-decoration-none <?= ($tipo_informe_seleccionado == 'activos_con_mantenimientos') ? 'border-primary shadow' : 'text-dark' ?>"> <div class="card-body"> <i class="bi bi-tools text-primary"></i> <h5>Activos con Mantenimientos</h5></div></a></div>
    </div>
    
    <?php if ($tipo_informe_seleccionado !== 'seleccione' && !empty($titulo_informe_actual)) : ?>
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
            // Lógica para mostrar tablas basada en el tipo de informe
            if ($tipo_informe_seleccionado == 'general') {
                $current_group_key_general = null; $asset_item_number_general = 0;
                foreach ($datos_para_tabla as $activo_gen) :
                    // Agrupar por Empresa del responsable, luego por cédula del responsable
                    $responsable_key = ($activo_gen['empresa_responsable'] ?? 'SinEmpresa') . '-' . $activo_gen['cedula_responsable'];
                    if ($responsable_key !== $current_group_key_general) :
                        if ($current_group_key_general !== null) echo '</tbody></table></div></div>'; // Cerrar tabla y grupo anterior
                        $current_group_key_general = $responsable_key; 
                        $asset_item_number_general = 1;
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
                                    <thead><tr><th>#</th><th>Tipo Activo</th><th>Marca</th><th>Serie</th><th>Estado</th><th>Valor</th><th>Regional (Resp.)</th><th>Fecha Compra</th></tr></thead>
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
                        </tr>
            <?php 
                endforeach; 
                if ($current_group_key_general !== null) echo '</tbody></table></div></div>'; // Cerrar la última tabla y grupo
            
            } elseif (in_array($tipo_informe_seleccionado, ['por_tipo', 'por_estado', 'por_regional', 'por_empresa'])) {
                $current_group_key_field = null; $asset_item_number_field = 0; $group_by_field = ''; $group_by_field_label = '';

                switch ($tipo_informe_seleccionado) {
                    case 'por_tipo': $group_by_field = 'nombre_tipo_activo'; $group_by_field_label = 'Tipo de Activo'; break;
                    case 'por_estado': $group_by_field = 'estado'; $group_by_field_label = 'Estado'; break;
                    case 'por_regional': $group_by_field = 'regional_responsable'; $group_by_field_label = 'Regional (Responsable)'; break;
                    case 'por_empresa': $group_by_field = 'empresa_responsable'; $group_by_field_label = 'Empresa (Responsable)'; break; 
                }

                foreach ($datos_para_tabla as $activo_field) :
                    $current_group_value = $activo_field[$group_by_field] ?? 'Sin Asignar'; 
                    if ($current_group_value !== $current_group_key_field) :
                        if ($current_group_key_field !== null) echo '</tbody></table></div></div>';
                        $current_group_key_field = $current_group_value; $asset_item_number_field = 1;
            ?>
                        <div class="report-group-container">
                            <div class="group-info-header"><h4><?= htmlspecialchars($group_by_field_label) ?>: <?= htmlspecialchars($current_group_key_field ?: 'N/A') ?></h4></div>
                            <div class="table-responsive">
                                <table class="table-minimalist">
                                    <thead><tr><th>#</th><th>Serie</th><th>Marca</th><th><?php echo ($tipo_informe_seleccionado != 'por_tipo') ? 'Tipo Activo' : 'Responsable'; ?></th><th>Responsable / C.C.</th><th><?php echo ($tipo_informe_seleccionado != 'por_estado') ? 'Estado' : 'Tipo Activo'; ?></th><th>Valor</th><th><?php echo ($tipo_informe_seleccionado != 'por_regional' && $tipo_informe_seleccionado != 'por_empresa') ? 'Regional / Empresa (Resp.)' : 'Tipo Activo'; ?></th></tr></thead>
                                    <tbody>
            <?php       endif; ?>
                        <tr>
                            <td><span class="asset-item-number"><?= $asset_item_number_field++ ?>.</span></td>
                            <td><?= htmlspecialchars($activo_field['serie'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($activo_field['marca'] ?? 'N/A') ?></td>
                            <td><?php echo htmlspecialchars( ($tipo_informe_seleccionado != 'por_tipo') ? ($activo_field['nombre_tipo_activo'] ?? 'N/A') : ($activo_field['nombre_responsable'] ?? 'N/A') ); ?></td>
                            <td><?= htmlspecialchars($activo_field['nombre_responsable'] ?? 'N/A') ?> (<?= htmlspecialchars($activo_field['cedula_responsable'] ?? 'N/A') ?>)</td>
                            <td><?php if ($tipo_informe_seleccionado != 'por_estado'): ?><span class="<?= getEstadoBadgeClass($activo_field['estado']) ?>"><?= htmlspecialchars($activo_field['estado'] ?? 'N/A') ?></span><?php else: ?><?= htmlspecialchars($activo_field['nombre_tipo_activo'] ?? 'N/A') ?><?php endif; ?></td>
                            <td>$<?= htmlspecialchars(number_format(floatval($activo_field['valor_aproximado'] ?? 0), 0, ',', '.')) ?></td>
                            <td><?php echo ($tipo_informe_seleccionado != 'por_regional' && $tipo_informe_seleccionado != 'por_empresa') ? (htmlspecialchars($activo_field['regional_responsable'] ?? 'N/A') . ' / ' . htmlspecialchars($activo_field['empresa_responsable'] ?? 'N/A')) : htmlspecialchars($activo_field['nombre_tipo_activo'] ?? 'N/A'); ?></td>
                        </tr>
            <?php 
                endforeach; 
                if ($current_group_key_field !== null) echo '</tbody></table></div></div>';
            
            } elseif ($tipo_informe_seleccionado == 'calificacion_por_tipo') { 
            ?>
                <div class="report-group-container"> <div class="table-responsive"> <table class="table-minimalist"> <thead> <tr> <?php foreach ($columnas_tabla_html as $header): ?> <th><?= htmlspecialchars($header) ?></th> <?php endforeach; ?> </tr> </thead> <tbody> <?php foreach ($datos_para_tabla as $activo_calificado) : ?> <tr> <td><?= htmlspecialchars($activo_calificado['nombre_tipo_activo'] ?? 'N/A') ?></td> <td><?= htmlspecialchars($activo_calificado['marca'] ?? 'N/A') ?></td> <td><?= htmlspecialchars($activo_calificado['serie'] ?? 'N/A') ?></td> <td><?= htmlspecialchars($activo_calificado['nombre_responsable'] ?? 'N/A') ?> (<?= htmlspecialchars($activo_calificado['cedula_responsable'] ?? 'N/A')?>)</td> <td><?= htmlspecialchars($activo_calificado['empresa_responsable'] ?? 'N/A') ?></td> <td><?= htmlspecialchars($activo_calificado['regional_responsable'] ?? 'N/A') ?></td> <td><span class="<?= getEstadoBadgeClass($activo_calificado['estado']) ?>"><?= htmlspecialchars($activo_calificado['estado'] ?? 'N/A') ?></span></td> <td><?= htmlspecialchars(!empty($activo_calificado['fecha_compra']) ? date("d/m/Y", strtotime($activo_calificado['fecha_compra'])) : 'N/A') ?></td> <td><?= displayStars(floatval($activo_calificado['satisfaccion_rating'] ?? 0)) ?></td> </tr> <?php endforeach; ?> </tbody> </table> </div> </div>
            <?php
            } elseif ($tipo_informe_seleccionado == 'dados_baja') { 
            ?> 
                <div class="report-group-container"> <div class="table-responsive"> <table class="table-minimalist"> <thead><tr> <?php foreach ($columnas_tabla_html as $header): ?><th><?= htmlspecialchars($header) ?></th><?php endforeach; ?> </tr></thead> <tbody> <?php foreach ($datos_para_tabla as $activo_baja) : ?> <tr> <td><?= htmlspecialchars($activo_baja['id']) ?></td> <td><?= htmlspecialchars($activo_baja['nombre_tipo_activo'] ?? 'N/A') ?></td> <td><?= htmlspecialchars($activo_baja['marca'] ?? 'N/A') ?></td> <td><?= htmlspecialchars($activo_baja['serie'] ?? 'N/A') ?></td> <td><?= htmlspecialchars($activo_baja['empresa_responsable'] ?? 'N/A') ?></td> <td><?= htmlspecialchars($activo_baja['nombre_ultimo_responsable'] ?? 'N/A') ?> (<?= htmlspecialchars($activo_baja['cedula_ultimo_responsable'] ?? 'N/A') ?>)</td> <td><?= htmlspecialchars(!empty($activo_baja['fecha_efectiva_baja']) ? date("d/m/Y H:i:s", strtotime($activo_baja['fecha_efectiva_baja'])) : 'N/A') ?></td> <td style="max-width: 300px; white-space: pre-wrap; word-wrap: break-word;"><?= nl2br(htmlspecialchars($activo_baja['motivo_observaciones_baja'] ?? $activo_baja['detalles_activo'] ?? 'Ver historial')) ?></td> <td><a href="historial.php?id_activo=<?= htmlspecialchars($activo_baja['id']) ?>" class="btn btn-sm btn-outline-info" title="Ver Historial Completo" target="_blank"><i class="bi bi-list-task"></i> Hist.</a></td> </tr> <?php endforeach; ?> </tbody> </table> </div></div> 
            <?php
            } elseif ($tipo_informe_seleccionado == 'movimientos') { 
            ?> 
                <div class="report-group-container"> <div class="table-responsive"> <table class="table-minimalist"> <thead><tr> <?php foreach ($columnas_tabla_html as $header): ?><th><?= htmlspecialchars($header) ?></th><?php endforeach; ?> </tr></thead> <tbody> <?php foreach ($datos_para_tabla as $evento) : ?> <tr> <td><?= htmlspecialchars(!empty($evento['fecha_evento']) ? date("d/m/Y H:i:s", strtotime($evento['fecha_evento'])) : 'N/A') ?></td> <td><span class="badge bg-warning text-dark"><?= htmlspecialchars($evento['tipo_evento']) ?></span></td> <td><?= htmlspecialchars($evento['nombre_tipo_activo'] ?? 'N/A') ?></td> <td><?= htmlspecialchars($evento['serie'] ?? 'N/A') ?></td> <td><?= htmlspecialchars($evento['marca_activo'] ?? 'N/A') ?></td><td><?= htmlspecialchars($evento['empresa_responsable_actual'] ?? 'N/A') ?></td> <td style="max-width: 350px; white-space: pre-wrap; word-wrap: break-word;"><?= nl2br(htmlspecialchars($evento['descripcion_evento'])) ?></td> <td><?= htmlspecialchars($evento['usuario_sistema'] ?? 'N/A') ?></td> <td><a href="editar.php?id_activo_focus=<?= htmlspecialchars($evento['id_activo_hist']) ?>&cedula=<?= htmlspecialchars($datos_para_tabla[0]['cedula_responsable'] ?? '') ?>" class="btn btn-sm btn-outline-info" title="Ver Activo" target="_blank"><i class="bi bi-pencil-square"></i> Activo</a></td> </tr> <?php endforeach; ?> </tbody> </table> </div> </div> 
            <?php
            } elseif ($tipo_informe_seleccionado == 'activos_con_mantenimientos') { 
            ?>
                <div class="report-group-container">
                    <div class="table-responsive">
                        <table class="table-minimalist">
                            <thead> <tr> <?php foreach ($columnas_tabla_html as $header): ?> <th><?= htmlspecialchars($header) ?></th> <?php endforeach; ?> </tr> </thead>
                            <tbody>
                                <?php foreach ($datos_para_tabla as $fila) : ?>
                                    <?php 
                                        $mantenimiento_info = json_decode($fila['datos_mantenimiento_json'] ?? '[]', true);
                                        $fecha_reparacion = $mantenimiento_info['fecha_reparacion'] ?? 'N/A';
                                        if ($fecha_reparacion !== 'N/A' && !empty($fecha_reparacion)) { try { $fecha_reparacion = date("d/m/Y", strtotime($fecha_reparacion)); } catch (Exception $e) { $fecha_reparacion = $mantenimiento_info['fecha_reparacion'];} }
                                        $diagnostico = $mantenimiento_info['diagnostico'] ?? 'N/A';
                                        $costo_reparacion = isset($mantenimiento_info['costo_reparacion']) && is_numeric($mantenimiento_info['costo_reparacion']) ? '$' . number_format(floatval($mantenimiento_info['costo_reparacion']), 0, ',', '.') : 'N/A';
                                        $nombre_proveedor = $mantenimiento_info['nombre_proveedor'] ?? 'N/A';
                                        $nombre_tecnico = $mantenimiento_info['nombre_tecnico_interno'] ?? 'N/A';
                                        $detalle_reparacion_mant = $mantenimiento_info['detalle_reparacion'] ?? 'N/A';
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($fila['activo_id']) ?></td> <td><?= htmlspecialchars($fila['nombre_tipo_activo'] ?? 'N/A') ?></td> <td><?= htmlspecialchars($fila['marca'] ?? 'N/A') ?></td> <td><?= htmlspecialchars($fila['serie'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($fila['nombre_responsable'] ?? 'N/A') ?> (<?= htmlspecialchars($fila['cedula_responsable'] ?? 'N/A') ?>)</td>
                                        <td><?= htmlspecialchars($fila['empresa_responsable'] ?? 'N/A') ?></td> <td><?= htmlspecialchars($fecha_reparacion) ?></td>
                                        <td><?= htmlspecialchars($diagnostico) ?></td> <td><?= htmlspecialchars($costo_reparacion) ?></td>
                                        <td><?= htmlspecialchars($nombre_proveedor) ?></td> <td><?= htmlspecialchars($nombre_tecnico) ?></td>
                                        <td style="max-width: 250px; white-space: pre-wrap; word-wrap: break-word;"><?= nl2br(htmlspecialchars($detalle_reparacion_mant)) ?></td>
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
            <div class="alert alert-info text-center mt-4" role="alert"> <i class="bi bi-exclamation-circle"></i> No hay datos disponibles para el informe<?= (!empty($fecha_desde) || !empty($fecha_hasta)) ? " con los filtros de fecha aplicados" : "" ?>: "<?= htmlspecialchars($titulo_informe_actual) ?>". </div>
        <?php endif; ?>
    <?php elseif ($tipo_informe_seleccionado == 'seleccione'): ?>
            <div class="alert alert-light text-center" role="alert" style="padding: 3rem; border: 1px dashed #ccc;">
                <i class="bi bi-clipboard-data" style="font-size: 3rem; color: #0d6efd;"></i><br><br>
                <h4 class="text-primary">Central de Informes</h4>
                <p class="text-muted fs-5">Por favor, seleccione un tipo de informe de las opciones superiores para visualizar los datos.<br>También puede aplicar filtros de fecha para refinar su búsqueda.</p>
            </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const formFiltros = document.getElementById('formFiltrosInformes');
    const hiddenTipoInforme = document.getElementById('hiddenTipoInforme');
    const collapseDateFiltersEl = document.getElementById('collapseDateFilters');
    
    // Verificar si el elemento colapsable existe antes de intentar crear una instancia
    if (collapseDateFiltersEl) {
        const bsCollapseDateFilters = new bootstrap.Collapse(collapseDateFiltersEl, { toggle: false });
        const fechaDesdeInput = document.getElementById('fecha_desde');
        const fechaHastaInput = document.getElementById('fecha_hasta');
        if ((fechaDesdeInput && fechaDesdeInput.value) || (fechaHastaInput && fechaHastaInput.value)) {
            bsCollapseDateFilters.show();
        }
    }


    document.querySelectorAll('.informe-selector-card').forEach(card => {
        card.addEventListener('click', function(event) {
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