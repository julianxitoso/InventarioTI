<?php
session_start(); 

require_once 'backend/auth_check.php'; 
restringir_acceso_pagina(['admin', 'tecnico', 'auditor']); // verificar_sesion_activa() es parte de restringir_acceso_pagina

require_once 'backend/db.php'; 

if (isset($conn) && !isset($conexion)) {
    $conexion = $conn;
}

if (!isset($conexion) || (method_exists($conexion, 'connect_error') && $conexion->connect_error)) {
    error_log("Error de conexión a la base de datos (exportar_excel.php): " . ($conexion->connect_error ?? 'No disponible'));
    die("Error de conexión a la base de datos. Por favor, intente más tarde o contacte al administrador.");
}
$conexion->set_charset("utf8mb4");

$tipo_informe = $_GET['tipo_informe'] ?? 'general';
$fecha_desde = $_GET['fecha_desde'] ?? '';
$fecha_hasta = $_GET['fecha_hasta'] ?? '';

$query = "";
$params = []; // Para todos los parámetros de la consulta principal
$types = '';  // Para todos los tipos de la consulta principal

$filename = "Exportacion_Activos_" . date('Ymd_His') . ".xls";
$column_headers = [];
$data_keys = []; // Las claves que se usarán para acceder a los datos de $fila[$key]

// Definiciones de constantes de historial si no están en historial_helper.php
if (!defined('HISTORIAL_TIPO_TRASLADO')) define('HISTORIAL_TIPO_TRASLADO', 'TRASLADO');
if (!defined('HISTORIAL_TIPO_ASIGNACION_INICIAL')) define('HISTORIAL_TIPO_ASIGNACION_INICIAL', 'ASIGNACIÓN INICIAL');
if (!defined('HISTORIAL_TIPO_CREACION')) define('HISTORIAL_TIPO_CREACION', 'CREACIÓN');
if (!defined('HISTORIAL_TIPO_REACTIVACION')) define('HISTORIAL_TIPO_REACTIVACION', 'REACTIVACIÓN');
if (!defined('HISTORIAL_TIPO_BAJA')) define('HISTORIAL_TIPO_BAJA', 'BAJA');
if (!defined('HISTORIAL_TIPO_MANTENIMIENTO')) define('HISTORIAL_TIPO_MANTENIMIENTO', 'MANTENIMIENTO');


// Lógica para construir condiciones de fecha
$condiciones_fecha_activo_sql = ""; // Para at.fecha_compra
$condiciones_fecha_historial_sql = ""; // Para h.fecha_evento
$params_fecha_arr = [];
$types_fecha_str = "";

if (!empty($fecha_desde) && !empty($fecha_hasta)) {
    $fecha_hasta_ajustada = $fecha_hasta . ' 23:59:59';
    $condiciones_fecha_activo_sql = " AND at.fecha_compra BETWEEN ? AND ? ";
    $condiciones_fecha_historial_sql = " AND h.fecha_evento BETWEEN ? AND ? ";
    $params_fecha_arr = [$fecha_desde, $fecha_hasta_ajustada];
    $types_fecha_str = "ss";
} elseif (!empty($fecha_desde)) {
    $condiciones_fecha_activo_sql = " AND at.fecha_compra >= ? ";
    $condiciones_fecha_historial_sql = " AND h.fecha_evento >= ? ";
    $params_fecha_arr = [$fecha_desde];
    $types_fecha_str = "s";
} elseif (!empty($fecha_hasta)) {
    $fecha_hasta_ajustada = $fecha_hasta . ' 23:59:59';
    $condiciones_fecha_activo_sql = " AND at.fecha_compra <= ? ";
    $condiciones_fecha_historial_sql = " AND h.fecha_evento <= ? ";
    $params_fecha_arr = [$fecha_hasta_ajustada];
    $types_fecha_str = "s";
}

// Campos base y JOINS para la mayoría de los informes de activos
$campos_select_comunes = "at.id AS id_activo, at.serie, at.marca, at.estado, at.valor_aproximado, 
                          at.fecha_compra, at.detalles, at.procesador, at.ram, at.disco_duro,
                          at.tipo_equipo, at.red, at.sistema_operativo, at.offimatica, at.antivirus,
                          at.Codigo_Inv AS codigo_inventario, 
                          ta.nombre_tipo_activo, 
                          u.usuario AS cedula_responsable, u.nombre_completo AS nombre_responsable, 
                          u.regional AS regional_responsable, u.empresa AS empresa_responsable,
                          c.nombre_cargo AS cargo_responsable";

$joins_comunes = "FROM activos_tecnologicos at 
                  LEFT JOIN tipos_activo ta ON at.id_tipo_activo = ta.id_tipo_activo
                  LEFT JOIN usuarios u ON at.id_usuario_responsable = u.id
                  LEFT JOIN cargos c ON u.id_cargo = c.id_cargo";

switch ($tipo_informe) {
    case 'general':
        $filename = "Informe_General_Activos_" . date('Ymd') . ".xls";
        // Nota: u.aplicaciones_usadas se mantiene si existe en tu tabla usuarios
        $query = "SELECT {$campos_select_comunes}, u.aplicaciones_usadas 
                  {$joins_comunes} 
                  WHERE at.estado != 'Dado de Baja' {$condiciones_fecha_activo_sql} 
                  ORDER BY u.empresa ASC, u.usuario ASC, u.nombre_completo ASC, at.id ASC";
        $params = $params_fecha_arr;
        $types = $types_fecha_str;
        $column_headers = ['ID Activo', 'Serie', 'Marca', 'Tipo Activo', 'Estado', 'Valor Aprox.', 'Fecha Compra', 'Cód. Inv.',
                           'Cédula Resp.', 'Nombre Resp.', 'Cargo Resp.', 'Regional Resp.', 'Empresa Resp.',
                           'Procesador', 'RAM', 'Disco Duro', 'Tipo Equipo', 'Red', 'SO', 'Offimática', 'Antivirus', 'Detalles', 'Aplicaciones Usadas'];
        $data_keys = ['id_activo', 'serie', 'marca', 'nombre_tipo_activo', 'estado', 'valor_aproximado', 'fecha_compra', 'codigo_inventario',
                      'cedula_responsable', 'nombre_responsable', 'cargo_responsable', 'regional_responsable', 'empresa_responsable',
                      'procesador', 'ram', 'disco_duro', 'tipo_equipo', 'red', 'sistema_operativo', 'offimatica', 'antivirus', 'detalles', 'aplicaciones_usadas'];
        break;

    case 'por_tipo':
        $filename = "Informe_Activos_Por_Tipo_" . date('Ymd') . ".xls";
        $query = "SELECT {$campos_select_comunes} 
                  {$joins_comunes} 
                  WHERE at.estado != 'Dado de Baja' {$condiciones_fecha_activo_sql} 
                  ORDER BY ta.nombre_tipo_activo ASC, at.id ASC";
        $params = $params_fecha_arr;
        $types = $types_fecha_str;
        $column_headers = ['Tipo Activo', 'Serie', 'Marca', 'Estado', 'Nombre Resp.', 'Cédula Resp.', 'Regional Resp.', 'Empresa Resp.', 'Valor Aprox.', 'Fecha Compra'];
        $data_keys = ['nombre_tipo_activo', 'serie', 'marca', 'estado', 'nombre_responsable', 'cedula_responsable', 'regional_responsable', 'empresa_responsable', 'valor_aproximado', 'fecha_compra'];
        break;

    case 'por_estado':
        $filename = "Informe_Activos_Por_Estado_" . date('Ymd') . ".xls";
        $query = "SELECT {$campos_select_comunes} 
                  {$joins_comunes} 
                  WHERE at.estado != 'Dado de Baja' {$condiciones_fecha_activo_sql} 
                  ORDER BY at.estado ASC, at.id ASC";
        $params = $params_fecha_arr;
        $types = $types_fecha_str;
        $column_headers = ['Estado', 'Tipo Activo', 'Serie', 'Marca', 'Nombre Resp.', 'Cédula Resp.', 'Regional Resp.', 'Empresa Resp.', 'Valor Aprox.', 'Fecha Compra'];
        $data_keys = ['estado', 'nombre_tipo_activo', 'serie', 'marca', 'nombre_responsable', 'cedula_responsable', 'regional_responsable', 'empresa_responsable', 'valor_aproximado', 'fecha_compra'];
        break;

    case 'por_regional':
        $filename = "Informe_Activos_Por_Regional_Resp_" . date('Ymd') . ".xls";
        $query = "SELECT {$campos_select_comunes} 
                  {$joins_comunes} 
                  WHERE at.estado != 'Dado de Baja' AND u.regional IS NOT NULL AND u.regional != '' {$condiciones_fecha_activo_sql} 
                  ORDER BY u.regional ASC, at.id ASC";
        $params = $params_fecha_arr;
        $types = $types_fecha_str;
        $column_headers = ['Regional Resp.', 'Tipo Activo', 'Serie', 'Marca', 'Estado', 'Nombre Resp.', 'Cédula Resp.', 'Empresa Resp.', 'Valor Aprox.', 'Fecha Compra'];
        $data_keys = ['regional_responsable', 'nombre_tipo_activo', 'serie', 'marca', 'estado', 'nombre_responsable', 'cedula_responsable', 'empresa_responsable', 'valor_aproximado', 'fecha_compra'];
        break;

    case 'por_empresa':
        $filename = "Informe_Activos_Por_Empresa_Resp_" . date('Ymd') . ".xls";
        $query = "SELECT {$campos_select_comunes} 
                  {$joins_comunes} 
                  WHERE at.estado != 'Dado de Baja' AND u.empresa IS NOT NULL AND u.empresa != '' {$condiciones_fecha_activo_sql} 
                  ORDER BY u.empresa ASC, at.id ASC";
        $params = $params_fecha_arr;
        $types = $types_fecha_str;
        $column_headers = ['Empresa Resp.', 'Tipo Activo', 'Serie', 'Marca', 'Estado', 'Nombre Resp.', 'Cédula Resp.', 'Regional Resp.', 'Valor Aprox.', 'Fecha Compra'];
        $data_keys = ['empresa_responsable', 'nombre_tipo_activo', 'serie', 'marca', 'estado', 'nombre_responsable', 'cedula_responsable', 'regional_responsable', 'valor_aproximado', 'fecha_compra'];
        break;
    
    case 'calificacion_por_tipo':
        $filename = "Informe_Calificaciones_Por_Activo_" . date('Ymd') . ".xls";
        $query = "SELECT {$campos_select_comunes}, at.satisfaccion_rating
                  {$joins_comunes} 
                  WHERE at.satisfaccion_rating IS NOT NULL AND at.estado != 'Dado de Baja' {$condiciones_fecha_activo_sql} 
                  ORDER BY ta.nombre_tipo_activo ASC, at.satisfaccion_rating DESC, at.id ASC";
        $params = $params_fecha_arr;
        $types = $types_fecha_str;
        $column_headers = ["Tipo de Activo", "Marca", "Serie", "Responsable", "Cédula Resp.", "Empresa (Resp.)", "Regional (Resp.)", "Estado Activo", "Fecha Compra", "Calificación"];
        $data_keys = ['nombre_tipo_activo', 'marca', 'serie', 'nombre_responsable', 'cedula_responsable', 'empresa_responsable', 'regional_responsable', 'estado', 'fecha_compra', 'satisfaccion_rating'];
        break;

    case 'dados_baja':
        $filename = "Informe_Activos_Dados_Baja_" . date('Ymd') . ".xls";
        $tipo_baja_const = HISTORIAL_TIPO_BAJA;
        
        // El filtro de fecha principal ($condiciones_fecha_historial_sql) se aplica a h_baja.fecha_evento
        $query = "SELECT at.id AS id_activo, ta.nombre_tipo_activo, at.marca, at.serie, at.estado, 
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
                          WHERE tipo_evento = ?  -- Placeholder para $tipo_baja_const
                          GROUP BY id_activo
                      ) h2 ON h1.id_activo = h2.id_activo AND h1.id_historial = h2.max_id_hist_baja
                  ) h_baja ON at.id = h_baja.id_activo 
                  WHERE at.estado = 'Dado de Baja' 
                  " . str_replace('h.fecha_evento', 'h_baja.fecha_evento', $condiciones_fecha_historial_sql) . "
                  ORDER BY COALESCE(h_baja.fecha_evento, at.fecha_compra) DESC, at.id ASC";
        
        $params = [$tipo_baja_const];
        $types = "s";
        if (!empty($types_fecha_str)) {
            $params = array_merge($params, $params_fecha_arr);
            $types .= $types_fecha_str;
        }
        $column_headers = ['ID Activo', 'Tipo Activo', 'Marca', 'Serie', 'Estado', 'Empresa (Últ. Resp.)', 'Regional (Últ. Resp.)', 'Últ. Responsable', 'Cédula (Últ. Resp.)', 'Valor Aprox.', 'Fecha Compra', 'Fecha Baja', 'Motivo/Obs. Baja', 'Detalles Activo'];
        $data_keys = ['id_activo', 'nombre_tipo_activo', 'marca', 'serie', 'estado', 'empresa_responsable', 'regional_responsable', 'nombre_ultimo_responsable', 'cedula_ultimo_responsable', 'valor_aproximado', 'fecha_compra', 'fecha_efectiva_baja', 'motivo_observaciones_baja', 'detalles_activo'];
        break;

    case 'movimientos':
        $filename = "Informe_Movimientos_Recientes_" . date('Ymd') . ".xls";
        $tipo_traslado_const = HISTORIAL_TIPO_TRASLADO;
        $tipo_asignacion_const = HISTORIAL_TIPO_ASIGNACION_INICIAL;
        $tipo_creacion_const = HISTORIAL_TIPO_CREACION;
        $tipo_reactivacion_const = HISTORIAL_TIPO_REACTIVACION;

        $query = "SELECT h.fecha_evento, h.tipo_evento, h.descripcion_evento, 
                         h.usuario_responsable AS usuario_sistema, 
                         a.id as id_activo, ta.nombre_tipo_activo, a.serie, a.marca AS marca_activo,
                         u_resp.nombre_completo AS nombre_responsable_actual, 
                         u_resp.empresa AS empresa_responsable_actual,
                         u_resp.regional AS regional_responsable_actual
                  FROM historial_activos h 
                  JOIN activos_tecnologicos a ON h.id_activo = a.id
                  LEFT JOIN tipos_activo ta ON a.id_tipo_activo = ta.id_tipo_activo
                  LEFT JOIN usuarios u_resp ON a.id_usuario_responsable = u_resp.id 
                  WHERE (h.tipo_evento IN (?, ?, ?, ?)) 
                  {$condiciones_fecha_historial_sql} 
                  ORDER BY h.fecha_evento DESC LIMIT 200";
        
        $params = [$tipo_traslado_const, $tipo_asignacion_const, $tipo_creacion_const, $tipo_reactivacion_const];
        $types = "ssss";
        if (!empty($types_fecha_str)) {
            $params = array_merge($params, $params_fecha_arr);
            $types .= $types_fecha_str;
        }
        $column_headers = ['Fecha Evento', 'Tipo Evento', 'ID Activo', 'Tipo Activo', 'Serie', 'Marca', 'Resp. Actual', 'Empresa (Resp. Actual)', 'Regional (Resp. Actual)', 'Descripción Evento', 'Usuario Sistema'];
        $data_keys = ['fecha_evento', 'tipo_evento', 'id_activo', 'nombre_tipo_activo', 'serie', 'marca_activo', 'nombre_responsable_actual', 'empresa_responsable_actual', 'regional_responsable_actual', 'descripcion_evento', 'usuario_sistema'];
        break;
    
    case 'activos_con_mantenimientos':
        $filename = "Informe_Activos_Con_Mantenimientos_" . date('Ymd') . ".xls";
        $tipo_mantenimiento_const = HISTORIAL_TIPO_MANTENIMIENTO;
        
        $query = "SELECT at.id AS activo_id, ta.nombre_tipo_activo, at.marca, at.serie, 
                         u.nombre_completo AS nombre_responsable, u.usuario AS cedula_responsable, 
                         u.empresa AS empresa_responsable, u.regional AS regional_responsable, 
                         at.estado AS activo_estado, 
                         h.fecha_evento AS fecha_registro_historial_mant, h.datos_nuevos AS datos_mantenimiento_json 
                  FROM activos_tecnologicos at 
                  JOIN historial_activos h ON at.id = h.id_activo
                  LEFT JOIN tipos_activo ta ON at.id_tipo_activo = ta.id_tipo_activo
                  LEFT JOIN usuarios u ON at.id_usuario_responsable = u.id
                  WHERE h.tipo_evento = ? AND at.estado != 'Dado de Baja' {$condiciones_fecha_historial_sql} 
                  ORDER BY at.id ASC, h.fecha_evento DESC";
        
        $params = [$tipo_mantenimiento_const];
        $types = "s";
        if (!empty($types_fecha_str)) {
            $params = array_merge($params, $params_fecha_arr);
            $types .= $types_fecha_str;
        }
        // Los data_keys para este informe deben coincidir con cómo se procesa el JSON
        $column_headers = ["ID Activo", "Tipo Activo", "Marca", "Serie", "Responsable", "Cédula Resp.", "Empresa Resp.", "Regional Resp.", "Estado Activo", "Fecha Mant. (Hist.)", 
                           "Fecha Reparación", "Diagnóstico", "Costo", "Proveedor", "Téc. Interno", "Detalle Reparación"];
        // $data_keys se manejarán de forma especial en el bucle while debido al JSON
        break;


    // El caso 'busqueda_personalizada' se elimina porque los filtros de fecha ahora aplican a todos los informes.
    // Si necesitas un informe con filtros específicos diferentes a fecha, se debería añadir como un nuevo tipo.

    default:
        error_log("Tipo de informe no válido para exportar: " . htmlspecialchars($tipo_informe));
        die("Tipo de informe no válido para exportación.");
}

$stmt = $conexion->prepare($query);

if ($stmt) {
    if (!empty($params) && !empty($types)) {
        // Verificar que el número de tipos y parámetros coincida
        if (strlen($types) == count($params)) {
            $stmt->bind_param($types, ...$params);
        } else {
            error_log("Error en exportar_excel.php: Discrepancia en bind_param. Types: '{$types}', #Params: " . count($params) . " para informe '{$tipo_informe}'");
            die("Error interno al generar el informe (cod: BP).");
        }
    }
    if (!$stmt->execute()) {
        error_log("Error al ejecutar la consulta para exportar (tipo: " . htmlspecialchars($tipo_informe) . "): " . $stmt->error . " Query: " . $query);
        die("Error al generar el informe. Por favor, intente más tarde (cod: EX).");
    }
    $resultado = $stmt->get_result();

    header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"" . preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $filename) . "\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo "\xEF\xBB\xBF"; // BOM para UTF-8

    echo "<table border='1' style='border-collapse: collapse; font-family: Arial, sans-serif; font-size: 10pt;'>";
    echo "<tr style='background-color: #f2f2f2; font-weight: bold;'>";
    foreach ($column_headers as $header) {
        echo "<th style='padding: 5px; border: 1px solid #ccc;'>" . htmlspecialchars($header) . "</th>";
    }
    echo "</tr>";

    if ($resultado->num_rows > 0) {
        while ($fila = $resultado->fetch_assoc()) {
            echo "<tr>";
            if ($tipo_informe == 'activos_con_mantenimientos') {
                // Manejo especial para informe con JSON
                echo "<td style='padding: 5px; border: 1px solid #ccc;'>" . htmlspecialchars($fila['activo_id'] ?? '') . "</td>";
                echo "<td style='padding: 5px; border: 1px solid #ccc;'>" . htmlspecialchars($fila['nombre_tipo_activo'] ?? '') . "</td>";
                echo "<td style='padding: 5px; border: 1px solid #ccc;'>" . htmlspecialchars($fila['marca'] ?? '') . "</td>";
                echo "<td style='padding: 5px; border: 1px solid #ccc;'>" . htmlspecialchars($fila['serie'] ?? '') . "</td>";
                echo "<td style='padding: 5px; border: 1px solid #ccc;'>" . htmlspecialchars($fila['nombre_responsable'] ?? '') . "</td>";
                echo "<td style='padding: 5px; border: 1px solid #ccc;'>" . htmlspecialchars($fila['cedula_responsable'] ?? '') . "</td>";
                echo "<td style='padding: 5px; border: 1px solid #ccc;'>" . htmlspecialchars($fila['empresa_responsable'] ?? '') . "</td>";
                echo "<td style='padding: 5px; border: 1px solid #ccc;'>" . htmlspecialchars($fila['regional_responsable'] ?? '') . "</td>";
                echo "<td style='padding: 5px; border: 1px solid #ccc;'>" . htmlspecialchars($fila['activo_estado'] ?? '') . "</td>";
                echo "<td style='padding: 5px; border: 1px solid #ccc;'>" . (!empty($fila['fecha_registro_historial_mant']) ? htmlspecialchars(date("d/m/Y H:i:s", strtotime($fila['fecha_registro_historial_mant']))) : '') . "</td>";
                
                $mantenimiento_info = json_decode($fila['datos_mantenimiento_json'] ?? '[]', true);
                $fecha_reparacion_excel = $mantenimiento_info['fecha_reparacion'] ?? '';
                if ($fecha_reparacion_excel && $fecha_reparacion_excel !== 'N/A') { try { $fecha_reparacion_excel = date("d/m/Y", strtotime($fecha_reparacion_excel)); } catch (Exception $e) { /* Mantener valor original si falla formato */ } }

                echo "<td style='padding: 5px; border: 1px solid #ccc;'>" . htmlspecialchars($fecha_reparacion_excel) . "</td>";
                echo "<td style='padding: 5px; border: 1px solid #ccc;'>" . htmlspecialchars($mantenimiento_info['diagnostico'] ?? '') . "</td>";
                $costo_excel = (isset($mantenimiento_info['costo_reparacion']) && is_numeric($mantenimiento_info['costo_reparacion'])) ? number_format(floatval($mantenimiento_info['costo_reparacion']), 2, ',', '') : '';
                echo "<td style='padding: 5px; border: 1px solid #ccc;'>" . htmlspecialchars($costo_excel) . "</td>";
                echo "<td style='padding: 5px; border: 1px solid #ccc;'>" . htmlspecialchars($mantenimiento_info['nombre_proveedor'] ?? '') . "</td>";
                echo "<td style='padding: 5px; border: 1px solid #ccc;'>" . htmlspecialchars($mantenimiento_info['nombre_tecnico_interno'] ?? '') . "</td>";
                echo "<td style='padding: 5px; border: 1px solid #ccc;'>" . htmlspecialchars($mantenimiento_info['detalle_reparacion'] ?? '') . "</td>";

            } else {
                foreach ($data_keys as $key) {
                    $value = $fila[$key] ?? '';
                    if (($key == 'valor_aproximado' || $key == 'satisfaccion_rating') && is_numeric($value)) {
                        $cell_value = number_format(floatval($value), 2, ',', ''); 
                    } elseif (in_array($key, ['fecha_registro', 'fecha_compra', 'fecha_evento', 'fecha_efectiva_baja', 'fecha_registro_historial_mant']) ) {
                        $cell_value = !empty($value) ? date("d/m/Y H:i:s", strtotime($value)) : '';
                        // Para solo fecha, sin hora:
                        // $cell_value = !empty($value) ? date("d/m/Y", strtotime($value)) : '';
                    } else {
                        $cell_value = $value;
                    }
                    echo "<td style='padding: 5px; border: 1px solid #ccc;'>" . htmlspecialchars($cell_value) . "</td>";
                }
            }
            echo "</tr>";
        }
    } else {
        echo "<tr><td colspan='" . count($column_headers) . "' style='text-align:center; padding: 10px;'>No hay datos para mostrar en este informe.</td></tr>";
    }
    echo "</table>";
    $stmt->close();
} else {
    error_log("Error en exportar_excel.php (preparación de consulta para '" . htmlspecialchars($tipo_informe) . "'): " . $conexion->error . " Query: " . $query);
    echo "Error al preparar la consulta para el informe: " . htmlspecialchars($tipo_informe) . ". Contacte al administrador. (cod: PREP)";
}
if (isset($conexion)) { // Cerrar conexión si fue abierta por este script o por db.php
    $conexion->close();
}
exit;
?>