<?php
// session_start(); // Ya se inicia en auth_check.php
require_once 'backend/auth_check.php';
restringir_acceso_pagina(['admin', 'tecnico']); // Solo admin y técnico pueden registrar activos

require_once 'backend/db.php';
require_once 'backend/historial_helper.php'; // Para registrar el historial

if (isset($conn) && !isset($conexion)) { $conexion = $conn; }
if (!isset($conexion) || !$conexion || (method_exists($conexion, 'connect_error') && $conexion->connect_error)) {
    error_log("Error de conexión BD en guardar_activo.php: " . ($conexion->connect_error ?? 'Desconocido'));
    echo "Error crítico de conexión. Contacte al administrador.";
    exit;
}
$conexion->set_charset("utf8mb4");

$error = "";
$usuario_actual_sistema_login = $_SESSION['usuario_login'] ?? 'Sistema'; // Usar el nombre de login para el historial

function sanitizar($data, $db_connection) {
    if ($data === null) return '';
    return mysqli_real_escape_string($db_connection, trim($data));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cedula = sanitizar($_POST['cedula'] ?? null, $conexion);
    $nombre = sanitizar($_POST['nombre'] ?? null, $conexion);
    $cargo = sanitizar($_POST['cargo'] ?? null, $conexion);
    $tipo_activo = sanitizar($_POST['tipo'] ?? null, $conexion);
    $marca = sanitizar($_POST['marca'] ?? null, $conexion);
    $serie = sanitizar($_POST['serie'] ?? null, $conexion);
    $estado = sanitizar($_POST['estado'] ?? null, $conexion);
    $valor_aproximado_raw = $_POST['valor'] ?? null;
    $valor_aproximado = null;
    if ($valor_aproximado_raw !== null && is_numeric($valor_aproximado_raw) && $valor_aproximado_raw >= 0) {
        $valor_aproximado = floatval($valor_aproximado_raw);
    } elseif ($valor_aproximado_raw !== null && $valor_aproximado_raw !== '') {
        $error .= "El 'Valor Aprox.' debe ser un número válido y no negativo. ";
    }
    $detalles = sanitizar($_POST['detalles'] ?? null, $conexion);
    $regional = sanitizar($_POST['regional'] ?? null, $conexion);
    $procesador = sanitizar($_POST['procesador'] ?? null, $conexion);
    $ram = sanitizar($_POST['ram'] ?? null, $conexion);
    $disco_duro = sanitizar($_POST['disco'] ?? null, $conexion);
    $tipo_equipo = sanitizar($_POST['tipo_equipo'] ?? null, $conexion);
    $red = sanitizar($_POST['red'] ?? null, $conexion);
    $sistema_operativo = sanitizar($_POST['so'] ?? null, $conexion);
    $offimatica = sanitizar($_POST['offimatica'] ?? null, $conexion);
    $antivirus = sanitizar($_POST['antivirus'] ?? null, $conexion);

    // Validaciones obligatorias (puedes añadir más)
    if (empty($cedula)) $error .= "Cédula es obligatoria. ";
    if (empty($nombre)) $error .= "Nombre es obligatorio. ";
    // ... (resto de tus validaciones) ...
    if (empty($tipo_activo)) $error .= "Tipo de Activo es obligatorio. ";
    if (empty($marca)) $error .= "Marca es obligatoria. ";
    if (empty($serie)) $error .= "Serie es obligatoria. ";
    if (empty($estado)) $error .= "Estado es obligatorio. ";
     if ($valor_aproximado_raw === null || $valor_aproximado_raw === '') {
         $error .= "Valor Aproximado es obligatorio. ";
     }
    if (empty($regional)) $error .= "Regional es obligatoria. ";


    if (empty($error)) {
        $sql = "INSERT INTO activos_tecnologicos (
                    cedula, nombre, cargo, tipo_activo, marca, serie, estado, valor_aproximado, detalles, regional,
                    procesador, ram, disco_duro, tipo_equipo, red, sistema_operativo, offimatica, antivirus, fecha_registro
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $conexion->prepare($sql);
        if ($stmt === false) {
            error_log("Error al preparar la consulta (guardar_activo.php): " . $conexion->error);
            echo "Error del servidor al preparar la consulta.";
            if (isset($conexion)) { $conexion->close(); }
            exit;
        }

        $stmt->bind_param(
            "sssssssdssssssssss",
            $cedula, $nombre, $cargo, $tipo_activo, $marca, $serie, $estado,
            $valor_aproximado,
            $detalles, $regional, $procesador, $ram, $disco_duro, $tipo_equipo,
            $red, $sistema_operativo, $offimatica, $antivirus
        );

        if ($stmt->execute()) {
            $id_nuevo_activo = $conexion->insert_id;
            echo "Activo (S/N: " . htmlspecialchars($serie) . ") registrado correctamente para " . htmlspecialchars($nombre) . ".";

            // HISTORIAL: Registrar creación
            $descripcion_creacion = "Activo creado y asignado a: " . htmlspecialchars($nombre) .
                                   " (C.C: " . htmlspecialchars($cedula) . ", Cargo: " . htmlspecialchars($cargo) . "). " .
                                   "Tipo: " . htmlspecialchars($tipo_activo) . ", Serie: " . htmlspecialchars($serie) .
                                   ", Marca: " . htmlspecialchars($marca) . ", Regional: " . htmlspecialchars($regional) . ".";
            
            $datos_nuevos_hist = array_filter([ // Solo datos relevantes para el historial de creación
                'cedula' => $cedula, 'nombre_responsable' => $nombre, 'cargo_responsable' => $cargo,
                'tipo_activo' => $tipo_activo, 'marca' => $marca, 'serie' => $serie, 'estado_inicial' => $estado,
                'valor_aproximado' => $valor_aproximado, 'regional' => $regional, 'detalles_iniciales' => $detalles,
                'procesador' => $procesador, 'ram' => $ram, 'disco_duro' => $disco_duro, 'tipo_equipo' => $tipo_equipo,
                'red' => $red, 'sistema_operativo' => $sistema_operativo, 'offimatica' => $offimatica, 'antivirus' => $antivirus
            ]);

            registrar_evento_historial(
                $conexion,
                $id_nuevo_activo,
                (defined('HISTORIAL_TIPO_CREACION') ? HISTORIAL_TIPO_CREACION : 'CREACIÓN'), // Usa la constante
                $descripcion_creacion,
                $usuario_actual_sistema_login,
                null,
                $datos_nuevos_hist
            );

        } else {
            $error_message = "Error al registrar el activo: " . $stmt->error;
            echo $error_message;
            error_log($error_message . " SQL: " . $sql, 0);
        }
        $stmt->close();
    } else {
        echo rtrim("Errores: " . $error, " ");
    }
} else {
    echo "Método de solicitud no válido.";
}

if (isset($conexion)) {
    $conexion->close();
}
?>