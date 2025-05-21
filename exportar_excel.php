<?php
session_start(); // Necesario antes de auth_check si no está ya en db.php o historial_helper.php

// 1. Usar la conexión de db.php y el helper de historial para las constantes
require_once 'backend/auth_check.php'; // Para verificar_sesion_activa() y constantes
verificar_sesion_activa(); // Asegurar que el usuario está logueado

require_once 'backend/db.php'; // Para $conexion

if (isset($conn) && !isset($conexion)) {
    $conexion = $conn;
}

if (!isset($conexion) || (method_exists($conexion, 'connect_error') && $conexion->connect_error)) {
    error_log("Error de conexión a la base de datos (exportar_excel.php): " . ($conexion->connect_error ?? 'No disponible'));
    die("Error de conexión a la base de datos. Por favor, intente más tarde o contacte al administrador.");
}
$conexion->set_charset("utf8mb4");

$tipo_informe = $_GET['tipo_informe'] ?? 'general';

$query = "";
$params = [];
$types = '';
$filename = "Exportacion_Activos_" . date('Ymd_His') . ".xls";
$column_headers = [];
$data_keys = [];

// Columnas comunes de la tabla activos_tecnologicos que se podrían usar
$columnas_base_activo = [
    'Cédula Resp.' => 'cedula', 'Nombre Resp.' => 'nombre', 'Cargo Resp.' => 'cargo',
    'Tipo Activo' => 'tipo_activo', 'Marca' => 'marca', 'Serie' => 'serie',
    'Procesador' => 'procesador', 'RAM' => 'ram', 'Disco Duro' => 'disco_duro',
    'Tipo Equipo' => 'tipo_equipo', 'Red' => 'red', 'SO' => 'sistema_operativo',
    'Offimática' => 'offimatica', 'Antivirus' => 'antivirus', 'Estado' => 'estado',
    'Valor Aprox.' => 'valor_aproximado', 'Regional Activo' => 'regional',
    'Detalles' => 'detalles', 'Fecha Registro' => 'fecha_registro',
    'Empresa' => 'Empresa', 'Código Inv.' => 'Codigo_Inv' // Nuevos campos de tu SQL
];


switch ($tipo_informe) {
    case 'general':
        $filename = "Informe_General_Activos_" . date('Ymd') . ".xls";
        $query = "SELECT * FROM activos_tecnologicos WHERE estado != 'Dado de Baja' ORDER BY nombre ASC, cedula ASC, id ASC"; // Orden por nombre del responsable
        $column_headers = array_keys($columnas_base_activo);
        $data_keys = array_values($columnas_base_activo);
        break;

    case 'por_tipo':
        $filename = "Informe_Activos_Por_Tipo_" . date('Ymd') . ".xls";
        $query = "SELECT * FROM activos_tecnologicos WHERE estado != 'Dado de Baja' ORDER BY tipo_activo ASC, id ASC";
        $column_headers = ['Tipo Activo', 'Serie', 'Marca', 'Estado', 'Nombre Resp.', 'Cédula Resp.', 'Regional Activo', 'Valor Aprox.'];
        $data_keys = ['tipo_activo', 'serie', 'marca', 'estado', 'nombre', 'cedula', 'regional', 'valor_aproximado'];
        break;

    case 'por_estado':
        $filename = "Informe_Activos_Por_Estado_" . date('Ymd') . ".xls";
        $query = "SELECT * FROM activos_tecnologicos WHERE estado != 'Dado de Baja' ORDER BY estado ASC, id ASC";
        $column_headers = ['Estado', 'Tipo Activo', 'Serie', 'Marca', 'Nombre Resp.', 'Cédula Resp.', 'Regional Activo', 'Valor Aprox.'];
        $data_keys = ['estado', 'tipo_activo', 'serie', 'marca', 'nombre', 'cedula', 'regional', 'valor_aproximado'];
        break;

    case 'por_regional':
        $filename = "Informe_Activos_Por_Regional_" . date('Ymd') . ".xls";
        $query = "SELECT * FROM activos_tecnologicos WHERE estado != 'Dado de Baja' ORDER BY regional ASC, id ASC";
        $column_headers = ['Regional Activo', 'Tipo Activo', 'Serie', 'Marca', 'Estado', 'Nombre Resp.', 'Cédula Resp.', 'Valor Aprox.'];
        $data_keys = ['regional', 'tipo_activo', 'serie', 'marca', 'estado', 'nombre', 'cedula', 'valor_aproximado'];
        break;

    case 'dados_baja':
        $filename = "Informe_Activos_Dados_Baja_" . date('Ymd') . ".xls";
        $query = "SELECT * FROM activos_tecnologicos WHERE estado = 'Dado de Baja' ORDER BY fecha_registro DESC, id ASC";
        $column_headers = ['Tipo Activo', 'Marca', 'Serie', 'Estado', 'Últ. Responsable', 'Cédula Resp.', 'Regional Activo', 'Valor Aprox.', 'Fecha Registro', 'Detalles'];
        $data_keys = ['tipo_activo', 'marca', 'serie', 'estado', 'nombre', 'cedula', 'regional', 'valor_aproximado', 'fecha_registro', 'detalles'];
        break;

    case 'busqueda_personalizada': // Nuevo caso para la búsqueda desde buscar.php
        $filename = "Busqueda_Activos_" . date('Ymd') . ".xls";
        $cedula_filtro = $_GET['cedula_export'] ?? '';
        $regional_filtro = $_GET['regional_export'] ?? '';
        $incluir_bajas_filtro = isset($_GET['incluir_bajas_export']) && $_GET['incluir_bajas_export'] === '1';

        $query = "SELECT * FROM activos_tecnologicos WHERE 1=1";
        $params_bp = []; // Renombrado para evitar conflicto
        $types_bp = '';  // Renombrado

        if (!$incluir_bajas_filtro) {
            $query .= " AND estado != 'Dado de Baja'";
        }
        if (!empty($cedula_filtro)) {
            $query .= " AND cedula = ?";
            $params_bp[] = $cedula_filtro;
            $types_bp .= 's';
        }
        if (!empty($regional_filtro)) {
            $query .= " AND regional = ?";
            $params_bp[] = $regional_filtro;
            $types_bp .= 's';
        }
        $query .= " ORDER BY nombre ASC, cedula ASC, id ASC";

        $params = $params_bp; // Asignar a las variables globales usadas por bind_param
        $types = $types_bp;

        $column_headers = array_keys($columnas_base_activo); // Reutilizar columnas base
        $data_keys = array_values($columnas_base_activo);
        break;

    case 'movimientos':
        $filename = "Informe_Movimientos_Recientes_" . date('Ymd') . ".xls";
        // Usar constantes definidas en historial_helper.php
        $tipo_traslado_const = defined('HISTORIAL_TIPO_TRASLADO') ? HISTORIAL_TIPO_TRASLADO : 'TRASLADO';
        $tipo_creacion_const = defined('HISTORIAL_TIPO_CREACION') ? HISTORIAL_TIPO_CREACION : 'CREACIÓN';
        // Podrías añadir más tipos de eventos si los consideras "movimientos"

        $query = "SELECT h.fecha_evento, h.tipo_evento, h.descripcion_evento, h.usuario_responsable,
                         a.tipo_activo, a.serie, a.marca AS marca_activo
                  FROM historial_activos h
                  JOIN activos_tecnologicos a ON h.id_activo = a.id
                  WHERE h.tipo_evento = ? OR h.tipo_evento = ? /* O cualquier otro evento relevante */
                  ORDER BY h.fecha_evento DESC LIMIT 200";

        $params = [$tipo_traslado_const, $tipo_creacion_const];
        $types = 'ss';

        $column_headers = ['Fecha Evento', 'Tipo Evento', 'Activo (Tipo)', 'Serie Activo', 'Marca Activo', 'Descripción Evento', 'Usuario Sistema'];
        $data_keys = ['fecha_evento', 'tipo_evento', 'tipo_activo', 'serie', 'marca_activo', 'descripcion_evento', 'usuario_responsable'];
        break;

    default:
        error_log("Tipo de informe no válido para exportar: " . htmlspecialchars($tipo_informe));
        die("Tipo de informe no válido para exportación. Contacte al administrador.");
}

$stmt = $conexion->prepare($query);

if ($stmt) {
    if (!empty($params) && !empty($types)) {
        $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) {
        error_log("Error al ejecutar la consulta para exportar (tipo: " . htmlspecialchars($tipo_informe) . "): " . $stmt->error);
        die("Error al generar el informe. Por favor, intente más tarde.");
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
            foreach ($data_keys as $key) {
                $value = $fila[$key] ?? '';
                if ($key == 'valor_aproximado' && is_numeric($value)) {
                    $cell_value = number_format(floatval($value), 2, ',', ''); // Usar coma para decimal, sin separador de miles para Excel
                } elseif ($key == 'fecha_registro' || $key == 'fecha_evento') {
                    $cell_value = !empty($value) ? date("d/m/Y H:i:s", strtotime($value)) : '';
                } else {
                    $cell_value = $value;
                }
                echo "<td style='padding: 5px; border: 1px solid #ccc;'>" . htmlspecialchars($cell_value) . "</td>";
            }
            echo "</tr>";
        }
    } else {
        echo "<tr><td colspan='" . count($column_headers) . "' style='text-align:center; padding: 10px;'>No hay datos para mostrar en este informe.</td></tr>";
    }
    echo "</table>";
    $stmt->close();
} else {
    error_log("Error en exportar_excel.php (preparación de consulta para '" . htmlspecialchars($tipo_informe) . "'): " . $conexion->error);
    echo "Error al preparar la consulta para el informe: " . htmlspecialchars($tipo_informe) . ". Contacte al administrador.";
}
$conexion->close();
exit;
?>