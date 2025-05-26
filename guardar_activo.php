<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
require_once 'backend/auth_check.php';
restringir_acceso_pagina(['admin', 'tecnico', 'registrador']);

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
    $responsable_regional = trim($_POST['responsable_regional'] ?? '');
    $responsable_empresa = trim($_POST['responsable_empresa'] ?? '');

    // --- CAPTURA DE DATOS DE APLICACIONES DEL RESPONSABLE ---
    $aplicaciones_seleccionadas_raw = $_POST['responsable_aplicaciones'] ?? [];
    $otros_aplicaciones_texto = trim($_POST['responsable_aplicaciones_otros_texto'] ?? '');
    $aplicaciones_para_guardar_array = [];
    if (is_array($aplicaciones_seleccionadas_raw)) {
        foreach ($aplicaciones_seleccionadas_raw as $app) {
            if ($app === 'Otros' && !empty($otros_aplicaciones_texto)) {
                $aplicaciones_para_guardar_array[] = 'Otros: ' . htmlspecialchars($otros_aplicaciones_texto);
            } elseif ($app !== 'Otros') {
                $aplicaciones_para_guardar_array[] = htmlspecialchars($app);
            }
        }
    }
    $aplicaciones_usadas_responsable_string = implode(', ', $aplicaciones_para_guardar_array);

    $activos_lote = $_POST['activos'] ?? [];

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

    $conexion->begin_transaction();
    $errores_guardado = [];
    $activos_guardados_count = 0;
    $ids_activos_creados = [];

    // --- ACTUALIZAR APLICACIONES DEL USUARIO RESPONSABLE ---
    if (!empty($aplicaciones_usadas_responsable_string)) {
        $sql_update_usuario = "UPDATE usuarios SET aplicaciones_usadas = ? WHERE usuario = ?";
        $stmt_update_usuario = $conexion->prepare($sql_update_usuario);
        if ($stmt_update_usuario) {
            $stmt_update_usuario->bind_param("ss", $aplicaciones_usadas_responsable_string, $responsable_cedula);
            if (!$stmt_update_usuario->execute()) {
                error_log("Advertencia: No se pudo actualizar 'aplicaciones_usadas' para el usuario ".$responsable_cedula.": " . $stmt_update_usuario->error);
            }
            $stmt_update_usuario->close();
        } else {
            error_log("Advertencia: Error al preparar la actualización de 'aplicaciones_usadas' para el usuario ".$responsable_cedula.": " . $conexion->error);
        }
    }

    // Consulta SQL con todas las columnas, incluyendo las de depreciación
    $sql = "INSERT INTO activos_tecnologicos (
                cedula, nombre, cargo, regional, Empresa, 
                tipo_activo, marca, serie, estado, valor_aproximado, codigo_inv, detalles, 
                procesador, ram, disco_duro, tipo_equipo, red, sistema_operativo, 
                offimatica, antivirus, satisfaccion_rating, 
                fecha_compra, vida_util, valor_residual, metodo_depreciacion,
                fecha_registro
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"; // 25 '?'
            
    $stmt_activos = $conexion->prepare($sql);

    if (!$stmt_activos) {
        error_log("Error al preparar la consulta de inserción de activos: " . $conexion->error);
        $_SESSION['error_global'] = "Error crítico del sistema (preparación de activos). Contacte al administrador.";
        $conexion->rollback();
        header('Location: index.php');
        exit;
    }

    foreach ($activos_lote as $index => $activo_data) {
        // --- Datos existentes ---
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

        // --- Datos de depreciación ---
        $fecha_compra = trim($activo_data['fecha_compra'] ?? '');
        $vida_util = trim($activo_data['vida_util'] ?? null);
        $valor_residual = trim($activo_data['valor_residual'] ?? null);
        $metodo_depreciacion = trim($activo_data['metodo_depreciacion'] ?? '');
        
        if (empty($fecha_compra)) {
            $fecha_compra = null;
        }
        
        // --- Validaciones ---
        if (empty($tipo_activo) || empty($marca) || empty($serie) || empty($estado) || $valor_aproximado_str === '') {
            $errores_guardado[] = "Activo #".($index+1).": Faltan campos obligatorios.";
            continue;
        }
        $valor_aproximado = filter_var($valor_aproximado_str, FILTER_VALIDATE_FLOAT);
        if ($valor_aproximado === false || $valor_aproximado < 0) {
            $errores_guardado[] = "Activo #".($index+1)." (Serie: ".$serie."): Valor aproximado no es válido.";
            continue;
        }
        
        // --- CORRECCIÓN FINAL ---
        // La cadena de tipos ahora tiene 25 caracteres para coincidir con las 25 variables.
        $stmt_activos->bind_param(
            "sssssssssdssssssssssisids", 
            $responsable_cedula, $responsable_nombre, $responsable_cargo, $responsable_regional, $responsable_empresa,
            $tipo_activo, $marca, $serie, $estado, $valor_aproximado, $codigo_inv, $detalles,
            $procesador, $ram, $disco_duro, $tipo_equipo, $red, $sistema_operativo,
            $offimatica, $antivirus, $satisfaccion_rating,
            $fecha_compra,
            $vida_util,
            $valor_residual,
            $metodo_depreciacion
        );

        if ($stmt_activos->execute()) {
            $id_activo_creado = $conexion->insert_id;
            $ids_activos_creados[] = $id_activo_creado;
            $activos_guardados_count++;

            $descripcion_historial = "Activo creado. Tipo: ".htmlspecialchars($tipo_activo).", Serie: ".htmlspecialchars($serie);
            $datos_creacion_activo = $activo_data;
            $datos_creacion_activo['cedula_responsable'] = $responsable_cedula;
            
            $usuario_actual_sistema_para_historial = $_SESSION['usuario_login'] ?? 'Sistema';
            registrar_evento_historial($conexion, $id_activo_creado, HISTORIAL_TIPO_CREACION, $descripcion_historial, $usuario_actual_sistema_para_historial, null, $datos_creacion_activo);
        } else {
            $errores_guardado[] = "Activo #".($index+1)." (Serie: ".$serie."): Error al guardar - " . $stmt_activos->error;
            error_log("Error al guardar activo (lote) S/N ".$serie.": " . $stmt_activos->error);
        }
    } 
    $stmt_activos->close();

    // Lógica de commit/rollback y mensajes
    if (empty($errores_guardado) && $activos_guardados_count > 0) {
        $conexion->commit();
        $_SESSION['mensaje_global'] = $activos_guardados_count . " activo(s) registrado(s) exitosamente para " . htmlspecialchars($responsable_nombre) . ".";
    } elseif ($activos_guardados_count > 0 && !empty($errores_guardado)) {
        $conexion->commit(); 
        $_SESSION['error_global'] = $activos_guardados_count . " activo(s) guardado(s), pero con errores en otros: " . implode("; ", $errores_guardado);
    } else { 
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