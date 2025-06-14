<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../backend/auth_check.php';
restringir_acceso_pagina(['admin', 'auditor', 'registrador', 'tecnico']);

require_once __DIR__ . '/../backend/db.php';

if (isset($conn) && !isset($conexion)) { $conexion = $conn; }
if (!isset($conexion) || (method_exists($conexion, 'connect_error') && $conexion->connect_error) || $conexion === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Error de conexión a la base de datos desde la API.']);
    exit;
}
$conexion->set_charset("utf8mb4");

// --- INICIO DE MODIFICACIÓN: Definir constantes y calcular umbral ---
define('VALOR_UVT_2025', 49799);
define('UMBRAL_UVT_DEPRECIACION', 50);
$umbral_valor_minimo_cop = VALOR_UVT_2025 * UMBRAL_UVT_DEPRECIACION;
// --- FIN DE MODIFICACIÓN ---

// --- Recolección de filtros ---
$q = trim($_GET['q'] ?? '');
$tipo_activo = trim($_GET['tipo_activo'] ?? '');
$regional = trim($_GET['regional'] ?? '');
$empresa = trim($_GET['empresa'] ?? '');
$fecha_desde = trim($_GET['fecha_desde'] ?? '');
$fecha_hasta = trim($_GET['fecha_hasta'] ?? '');
$estado_depreciacion = trim($_GET['estado_depreciacion'] ?? '');

// --- Construcción de la consulta SÚPER ESTRICTA ---
$sql = "SELECT 
            a.id, a.serie, a.marca, a.estado, a.valor_aproximado, a.valor_residual, 
            a.fecha_compra, a.metodo_depreciacion, a.detalles, 
            u.nombre_completo AS nombre_responsable,
            u.usuario AS cedula_responsable,
            c.nombre_cargo AS cargo_responsable,
            ta.nombre_tipo_activo AS nombre_tipo_activo,
            ta.vida_util_sugerida AS vida_util_anios
        FROM 
            activos_tecnologicos a
        LEFT JOIN usuarios u ON a.id_usuario_responsable = u.id
        LEFT JOIN cargos c ON u.id_cargo = c.id_cargo
        LEFT JOIN tipos_activo ta ON a.id_tipo_activo = ta.id_tipo_activo
        WHERE 
            a.estado != 'Dado de Baja' 
            AND a.fecha_compra IS NOT NULL 
            AND ta.vida_util_sugerida > 0
            AND a.valor_aproximado >= ?"; // <-- Condición de valor mínimo en UVT

$params = [$umbral_valor_minimo_cop]; // El primer parámetro siempre será el umbral de valor
$types = 'd'; // 'd' para tipo double/decimal
$condiciones = [];

// El resto de los filtros se añaden dinámicamente
if (!empty($q)) {
    $condiciones[] = "(a.serie LIKE ? OR a.Codigo_Inv LIKE ? OR u.usuario = ? OR u.nombre_completo LIKE ?)";
    $searchTerm = "%{$q}%";
    array_push($params, $searchTerm, $searchTerm, $q, $searchTerm);
    $types .= 'ssss';
}
if (!empty($tipo_activo)) {
    $condiciones[] = "a.id_tipo_activo = ?";
    $params[] = $tipo_activo;
    $types .= 'i';
}
// ... (resto de los if para cada filtro)
if (!empty($regional)) { $condiciones[] = "u.regional = ?"; $params[] = $regional; $types .= 's'; }
if (!empty($empresa)) { $condiciones[] = "u.empresa = ?"; $params[] = $empresa; $types .= 's'; }
if (!empty($fecha_desde)) { $condiciones[] = "a.fecha_compra >= ?"; $params[] = $fecha_desde; $types .= 's'; }
if (!empty($fecha_hasta)) { $condiciones[] = "a.fecha_compra <= ?"; $params[] = $fecha_hasta; $types .= 's'; }

// Filtro especial por estado de depreciación
if (!empty($estado_depreciacion)) {
    switch ($estado_depreciacion) {
        case 'en_curso':
            $condiciones[] = "DATE_ADD(a.fecha_compra, INTERVAL ta.vida_util_sugerida YEAR) > CURDATE()";
            break;
        case 'depreciado':
            $condiciones[] = "DATE_ADD(a.fecha_compra, INTERVAL ta.vida_util_sugerida YEAR) <= CURDATE()";
            break;
        case 'proximo':
            $condiciones[] = "DATE_ADD(a.fecha_compra, INTERVAL ta.vida_util_sugerida YEAR) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 6 MONTH)";
            break;
    }
}

if (count($condiciones) > 0) {
    $sql .= " AND " . implode(" AND ", $condiciones);
}

$sql .= " ORDER BY a.id DESC";

$stmt = $conexion->prepare($sql);
if ($stmt) {
    // El bind_param ahora incluirá el umbral de valor al principio si hay otros parámetros
    $stmt->bind_param($types, ...$params);
    
    if ($stmt->execute()) {
        $resultado = $stmt->get_result();
        $activos = $resultado->fetch_all(MYSQLI_ASSOC);
        echo json_encode($activos);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Error al ejecutar la búsqueda.', 'sql_error' => $stmt->error]);
    }
    $stmt->close();
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Error al preparar la consulta.', 'sql_error' => $conexion->error]);
}
$conexion->close();
?>