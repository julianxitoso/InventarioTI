<?php
session_start();
require_once 'backend/auth_check.php';
restringir_acceso_pagina(['admin', 'tecnico']);

require_once 'backend/db.php';
require_once 'backend/historial_helper.php';

if (!defined('HISTORIAL_TIPO_CREACION')) define('HISTORIAL_TIPO_CREACION', 'CREACIÓN');

if (isset($conn) && !isset($conexion)) { $conexion = $conn; }
if (!isset($conexion) || !$conexion) {
    $_SESSION['error_global'] = "Error de conexión a la base de datos.";
    header('Location: index.php');
    exit;
}
$conexion->set_charset("utf8mb4");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // --- Captura de Datos del Responsable ---
    $responsable_cedula = trim($_POST['responsable_cedula'] ?? '');
    $responsable_nombre = trim($_POST['responsable_nombre'] ?? '');
    $responsable_cargo = trim($_POST['responsable_cargo'] ?? '');
    $responsable_regional = trim($_POST['responsable_regional'] ?? ''); // Regional del responsable/activos
    $responsable_empresa = trim($_POST['responsable_empresa'] ?? '');   // Empresa del responsable/activos

    // --- Captura de los Activos (array) ---
    $activos_lote = $_POST['activos'] ?? [];

    // --- Validaciones Básicas ---
    if (empty($responsable_cedula) || empty($responsable_nombre) || empty($responsable_cargo) || empty($responsable_regional) || empty($responsable_empresa)) {
        $_SESSION['error_global'] = "Faltan datos del responsable.";
        header('Location: index.php');
        exit;
    }
    if (empty($activos_lote)) {
        $_SESSION['error_global'] = "No se agregaron activos para registrar.";
        header('Location: index.php');
        exit;
    }

    $conexion->begin_transaction(); // Iniciar transacción para asegurar atomicidad
    $errores_guardado = [];
    $activos_guardados_count = 0;
    $ids_activos_creados = [];

    // Preparar la consulta SQL una vez fuera del bucle
    // Nombre de columna 'Empresa' para la BD. Si es 'empresa', cambiar aquí.
    $sql = "INSERT INTO activos_tecnologicos (
                cedula, nombre, cargo, regional, Empresa, 
                tipo_activo, marca, serie, estado, valor_aproximado, codigo_inv, detalles, 
                procesador, ram, disco_duro, tipo_equipo, red, sistema_operativo, 
                offimatica, antivirus, satisfaccion_rating, fecha_registro
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    $stmt = $conexion->prepare($sql);

    if (!$stmt) {
        error_log("Error al preparar la consulta de inserción masiva: " . $conexion->error);
        $_SESSION['error_global'] = "Error crítico del sistema (preparación). Contacte al administrador.";
        $conexion->rollback(); // Revertir si la preparación falla
        header('Location: index.php');
        exit;
    }

    foreach ($activos_lote as $index => $activo_data) {
        // Extraer y validar datos de cada activo
        $tipo_activo = trim($activo_data['tipo_activo'] ?? '');
        $marca = trim($activo_data['marca'] ?? '');
        $serie = trim($activo_data['serie'] ?? '');
        $estado = trim($activo_data['estado'] ?? '');
        $valor_aproximado_str = trim($activo_data['valor_aproximado'] ?? '');
        
        $codigo_inv = trim($activo_data['codigo_inv'] ?? '');
        $detalles = trim($activo_data['detalles'] ?? '');
        $procesador = trim($activo_data['procesador'] ?? '');
        $ram = trim($activo_data['ram'] ?? '');
        $disco_duro = trim($activo_data['disco_duro'] ?? '');
        $tipo_equipo = trim($activo_data['tipo_equipo'] ?? '');
        $red = trim($activo_data['red'] ?? '');
        $sistema_operativo = trim($activo_data['sistema_operativo'] ?? '');
        $offimatica = trim($activo_data['offimatica'] ?? '');
        $antivirus = trim($activo_data['antivirus'] ?? '');
        
        $satisfaccion_rating = null;
        if (isset($activo_data['satisfaccion_rating']) && $activo_data['satisfaccion_rating'] !== '') {
            $rating_value = filter_var($activo_data['satisfaccion_rating'], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "max_range" => 5]]);
            if ($rating_value !== false) $satisfaccion_rating = $rating_value;
        }

        // Validaciones por activo
        if (empty($tipo_activo) || empty($marca) || empty($serie) || empty($estado) || $valor_aproximado_str === '') {
            $errores_guardado[] = "Activo #".($index+1).": Faltan campos obligatorios (Tipo, Marca, Serie, Estado, Valor).";
            continue; // Saltar este activo y continuar con el siguiente
        }
        $valor_aproximado = filter_var($valor_aproximado_str, FILTER_VALIDATE_FLOAT);
        if ($valor_aproximado === false || $valor_aproximado < 0) {
            $errores_guardado[] = "Activo #".($index+1)." (Serie: ".$serie."): Valor aproximado no es válido.";
            continue;
        }
        

        $stmt->bind_param(
            "sssssssssdssssssssssi", // <<< CADENA DE TIPOS CORREGIDA
            $responsable_cedula, $responsable_nombre, $responsable_cargo, $responsable_regional, $responsable_empresa,
            $tipo_activo, $marca, $serie, $estado, $valor_aproximado, $codigo_inv, $detalles,
            $procesador, $ram, $disco_duro, $tipo_equipo, $red, $sistema_operativo,
            $offimatica, $antivirus, $satisfaccion_rating
        );

        if ($stmt->execute()) {
            $id_activo_creado = $conexion->insert_id;
            $ids_activos_creados[] = $id_activo_creado;
            $activos_guardados_count++;

            // Registrar en historial
            $descripcion_historial = "Activo creado. Tipo: ".htmlspecialchars($tipo_activo).", Serie: ".htmlspecialchars($serie);
            $descripcion_historial .= ". Asignado a: ".htmlspecialchars($responsable_nombre)." (C.C: ".htmlspecialchars($responsable_cedula).")";
            $descripcion_historial .= ". Empresa: ".htmlspecialchars($responsable_empresa).". Regional: ".htmlspecialchars($responsable_regional).".";
            if($satisfaccion_rating !== null) $descripcion_historial .= " Satisfacción: ".$satisfaccion_rating." estrellas.";

            $datos_creacion_activo = $activo_data; // Datos del activo específico
            $datos_creacion_activo['cedula_responsable'] = $responsable_cedula; // Añadir info del responsable
            $datos_creacion_activo['nombre_responsable'] = $responsable_nombre;
            $datos_creacion_activo['cargo_responsable'] = $responsable_cargo;
            $datos_creacion_activo['regional_asignada'] = $responsable_regional;
            $datos_creacion_activo['empresa_asignada'] = $responsable_empresa;

            $usuario_actual_sistema_para_historial = $_SESSION['usuario_login'] ?? 'Sistema';
            registrar_evento_historial($conexion, $id_activo_creado, HISTORIAL_TIPO_CREACION, $descripcion_historial, $usuario_actual_sistema_para_historial, null, $datos_creacion_activo);
        } else {
            $errores_guardado[] = "Activo #".($index+1)." (Serie: ".$serie."): Error al guardar - " . $stmt->error;
            error_log("Error al guardar activo (lote) S/N ".$serie.": " . $stmt->error);
        }
    } // Fin foreach
    $stmt->close();

    if (empty($errores_guardado) && $activos_guardados_count > 0) {
        $conexion->commit();
        $_SESSION['mensaje_global'] = $activos_guardados_count . " activo(s) registrado(s) exitosamente para " . htmlspecialchars($responsable_nombre) . ". IDs: " . implode(", ", $ids_activos_creados);
    } elseif ($activos_guardados_count > 0 && !empty($errores_guardado)) {
        $conexion->commit(); // Guardar los que sí se pudieron
        $_SESSION['error_global'] = $activos_guardados_count . " activo(s) guardado(s), pero con errores en otros: " . implode("; ", $errores_guardado);
    } else { // Ninguno guardado o todos con error
        $conexion->rollback();
        $_SESSION['error_global'] = "No se pudo registrar ningún activo. Errores: " . implode("; ", $errores_guardado);
        if (empty($errores_guardado)) $_SESSION['error_global'] = "No se pudo registrar ningún activo debido a un error desconocido.";
    }

    header('Location: index.php');
    exit;

} else {
    $_SESSION['error_global'] = "Acceso no permitido.";
    header('Location: index.php');
    exit;
}
?>