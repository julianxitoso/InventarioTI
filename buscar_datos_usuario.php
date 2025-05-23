<?php
header('Content-Type: application/json');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// backend/buscar_datos_usuario.php
ob_start(); // Inicia el buffer de salida para capturar cualquier salida inesperada

header('Content-Type: application/json');

require_once 'backend/auth_check.php';
// Si quieres que este endpoint sea público, comenta la siguiente línea:
// verificar_sesion_activa(); 

require_once 'backend/db.php';

if (isset($conn) && !isset($conexion)) {
    $conexion = $conn;
}

$response = [
    'encontrado' => false,
    'mensaje' => 'No se proporcionó una cédula válida.',
    'nombre_completo' => '',
    'cargo' => '',
    'regional' => '',        
    'empresa' => '',         
    'aplicaciones_usadas' => '',
    'error' => null
];

if (!isset($conexion) || (method_exists($conexion, 'connect_error') && $conexion->connect_error) || !$conexion) {
    $response['error'] = 'Error de conexión a la base de datos.';
    error_log("Error de conexión BD en buscar_datos_usuario.php: " . ($conexion->connect_error ?? 'Desconocido'));
    ob_end_clean(); // Limpiar buffer antes de salida
    echo json_encode($response);
    exit;
}
$conexion->set_charset("utf8mb4");

if (isset($_GET['cedula']) && !empty(trim($_GET['cedula']))) {
    $cedula_buscada = trim($_GET['cedula']);

    // 1. Buscar en la tabla 'usuarios'
    // Columnas esperadas en 'usuarios': usuario (para la cédula), nombre_completo, cargo, regional, empresa, aplicaciones_usadas
    $sql_usuario = "SELECT nombre_completo, cargo, regional, empresa, aplicaciones_usadas 
                    FROM usuarios 
                    WHERE usuario = ? 
                    LIMIT 1";
    
    $stmt_usuario = $conexion->prepare($sql_usuario);

    if ($stmt_usuario) {
        $stmt_usuario->bind_param("s", $cedula_buscada);
        if ($stmt_usuario->execute()) {
            $resultado_usuario = $stmt_usuario->get_result();
            if ($fila_usuario = $resultado_usuario->fetch_assoc()) {
                $response['encontrado'] = true;
                $response['mensaje'] = 'Usuario encontrado en el sistema.';
                $response['nombre_completo'] = $fila_usuario['nombre_completo'] ?? ''; // Asegurar que existen o usar default
                $response['cargo'] = $fila_usuario['cargo'] ?? '';
                $response['regional'] = $fila_usuario['regional'] ?? '';
                $response['empresa'] = $fila_usuario['empresa'] ?? '';
                $response['aplicaciones_usadas'] = $fila_usuario['aplicaciones_usadas'] ?? '';
            } else {
                // 2. Si no se encuentra en 'usuarios', buscar en 'activos_tecnologicos'
                // Columnas esperadas en 'activos_tecnologicos': cedula, nombre, cargo, regional, Empresa (con E mayúscula para empresa)
                $sql_activos = "SELECT nombre, cargo, regional, Empresa 
                                FROM activos_tecnologicos 
                                WHERE cedula = ? 
                                ORDER BY fecha_registro DESC 
                                LIMIT 1";
                $stmt_activos = $conexion->prepare($sql_activos);
                if ($stmt_activos) {
                    $stmt_activos->bind_param("s", $cedula_buscada);
                    if ($stmt_activos->execute()) {
                        $resultado_activos = $stmt_activos->get_result();
                        if ($fila_activos = $resultado_activos->fetch_assoc()) {
                            $response['encontrado'] = true;
                            $response['mensaje'] = 'Datos del responsable encontrados en registros de activos (no es usuario del sistema).';
                            $response['nombre_completo'] = $fila_activos['nombre'] ?? '';
                            $response['cargo'] = $fila_activos['cargo'] ?? '';
                            $response['regional'] = $fila_activos['regional'] ?? '';
                            $response['empresa'] = $fila_activos['Empresa'] ?? ''; // Nota la 'E' mayúscula
                            // aplicaciones_usadas se queda vacío porque no está en esta tabla.
                        } else {
                             $response['mensaje'] = 'Cédula no encontrada.';
                        }
                    } else {
                        $response['error'] = 'Error al ejecutar consulta de activos.';
                        error_log("Error ejecución consulta activos en buscar_datos_usuario.php: " . $stmt_activos->error);
                    }
                    $stmt_activos->close();
                } else {
                    $response['error'] = 'Error al preparar consulta de activos: ' . $conexion->error;
                    error_log("Error preparación consulta activos en buscar_datos_usuario.php: " . $conexion->error);
                }
            }
        } else {
            $response['error'] = 'Error al ejecutar la consulta de usuario.';
            error_log("Error ejecución consulta usuario en buscar_datos_usuario.php: " . $stmt_usuario->error);
        }
        $stmt_usuario->close();
    } else {
        $response['error'] = 'Error al preparar la consulta de usuario: ' . $conexion->error;
        error_log("Error preparación consulta usuario en buscar_datos_usuario.php: " . $conexion->error);
    }
} else {
    $response['error'] = 'Cédula no proporcionada o vacía.';
}

if (isset($conexion)) {
    $conexion->close();
}

ob_end_clean(); // Limpiar cualquier salida accidental ANTES de enviar el JSON
echo json_encode($response);
exit;
?>