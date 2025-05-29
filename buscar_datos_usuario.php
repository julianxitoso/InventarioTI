<?php
// Al inicio de buscar_datos_usuario.php

// ...
require_once 'backend/auth_check.php';
// ...
require_once 'backend/db.php';
// ...

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
    ob_end_clean();
    echo json_encode($response);
    exit;
}
$conexion->set_charset("utf8mb4");

if (isset($_GET['cedula']) && !empty(trim($_GET['cedula']))) {
    $cedula_buscada = trim($_GET['cedula']);

    // --- CAMBIO PRINCIPAL: Consulta a 'usuarios' con JOIN a 'cargos' ---
    $sql_usuario = "SELECT 
                        u.nombre_completo, 
                        c.nombre_cargo AS cargo, 
                        u.regional, 
                        u.empresa, 
                        u.aplicaciones_usadas 
                    FROM 
                        usuarios u
                    LEFT JOIN 
                        cargos c ON u.id_cargo = c.id_cargo 
                    WHERE 
                        u.usuario = ? 
                    LIMIT 1";
    
    $stmt_usuario = $conexion->prepare($sql_usuario);

    if ($stmt_usuario) {
        $stmt_usuario->bind_param("s", $cedula_buscada);
        if ($stmt_usuario->execute()) {
            $resultado_usuario = $stmt_usuario->get_result();
            if ($fila_usuario = $resultado_usuario->fetch_assoc()) {
                $response['encontrado'] = true;
                $response['mensaje'] = 'Usuario encontrado en el sistema.';
                $response['nombre_completo'] = $fila_usuario['nombre_completo'] ?? '';
                $response['cargo'] = $fila_usuario['cargo'] ?? ''; // Ahora viene de c.nombre_cargo
                $response['regional'] = $fila_usuario['regional'] ?? '';
                $response['empresa'] = $fila_usuario['empresa'] ?? '';
                $response['aplicaciones_usadas'] = $fila_usuario['aplicaciones_usadas'] ?? '';
            } else {
                // --- CAMBIO: Se elimina la lógica de fallback para buscar en activos_tecnologicos ---
                // La fuente autoritativa de los datos del responsable es la tabla 'usuarios'.
                $response['mensaje'] = 'Cédula no encontrada en el registro de usuarios.';
                // --- FIN DEL CAMBIO ---
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
    // El mensaje inicial de $response ya cubre esto, pero se puede poner uno más específico si se quiere.
    // $response['error'] = 'Cédula no proporcionada o vacía.';
}

if (isset($conexion)) {
    $conexion->close();
}

ob_end_clean(); 
echo json_encode($response);
exit;
?>