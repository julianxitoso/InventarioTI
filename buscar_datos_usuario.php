<?php
// buscar_datos_usuario.php
header('Content-Type: application/json'); // Indicar que la respuesta será JSON

// Incluir y verificar sesión activa
require_once 'backend/auth_check.php';
verificar_sesion_activa(); // Asegura que solo usuarios logueados puedan usar este endpoint

require_once 'backend/db.php'; // Tu archivo de conexión

if (isset($conn) && !isset($conexion)) {
    $conexion = $conn;
}

$response = ['encontrado' => false, 'nombre' => '', 'cargo' => '', 'error' => null];

if (!isset($conexion) || (method_exists($conexion, 'connect_error') && $conexion->connect_error) || !$conexion) {
    $response['error'] = 'Error de conexión a la base de datos.';
    error_log("Error de conexión BD en buscar_datos_usuario.php: " . ($conexion->connect_error ?? 'Desconocido'));
    echo json_encode($response);
    exit;
}
$conexion->set_charset("utf8mb4");

if (isset($_GET['cedula']) && !empty(trim($_GET['cedula']))) {
    $cedula = trim($_GET['cedula']);

    // Buscamos en activos_tecnologicos.
    // Se asume que el nombre y cargo son (o deberían ser) consistentes para una cédula.
    // Si tienes una tabla 'empleados' o 'responsables', sería mejor consultarla.
    $stmt = $conexion->prepare("SELECT nombre, cargo FROM activos_tecnologicos WHERE cedula = ? ORDER BY fecha_registro DESC LIMIT 1");
    // Se añade ORDER BY fecha_registro DESC para intentar obtener los datos más recientes asociados a esa cédula.

    if ($stmt) {
        $stmt->bind_param("s", $cedula);
        if ($stmt->execute()) {
            $resultado = $stmt->get_result();
            if ($fila = $resultado->fetch_assoc()) {
                $response['encontrado'] = true;
                $response['nombre'] = $fila['nombre'];
                $response['cargo'] = $fila['cargo'];
            } else {
                $response['mensaje'] = 'Cédula no encontrada con activos asociados.';
                // Podrías opcionalmente buscar en una tabla de 'empleados' si esta existe y no se encontró en activos.
            }
        } else {
            $response['error'] = 'Error al ejecutar la consulta.';
            error_log("Error ejecución consulta en buscar_datos_usuario.php: " . $stmt->error);
        }
        $stmt->close();
    } else {
        $response['error'] = 'Error al preparar la consulta: ' . $conexion->error;
        error_log("Error preparación consulta en buscar_datos_usuario.php: " . $conexion->error);
    }
    // $conexion->close(); // Se cierra al final del script
} else {
    $response['error'] = 'Cédula no proporcionada o vacía.';
}

if (isset($conexion)) {
    $conexion->close();
}

echo json_encode($response);
exit;
?>