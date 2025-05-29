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
    $responsable_cedula_form = trim($_POST['responsable_cedula'] ?? '');
    $responsable_nombre_form = trim($_POST['responsable_nombre'] ?? ''); // Para validación
    // ... (otros campos del responsable del form para validación si los necesitas)

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

    if (empty($responsable_cedula_form) || empty($responsable_nombre_form) /* Agrega otras validaciones de campos del form si es necesario */) {
        $_SESSION['error_global'] = "Faltan datos del responsable en el formulario.";
        header('Location: index.php');
        exit;
    }
    if (empty($activos_lote)) {
        $_SESSION['error_global'] = "No se agregaron activos para registrar.";
        header('Location: index.php');
        exit;
    }

    $id_usuario_responsable_para_guardar = null;
    $sql_get_user_id = "SELECT id FROM usuarios WHERE usuario = ?";
    $stmt_get_user_id = $conexion->prepare($sql_get_user_id);
    if ($stmt_get_user_id) {
        $stmt_get_user_id->bind_param("s", $responsable_cedula_form);
        $stmt_get_user_id->execute();
        $result_user_id = $stmt_get_user_id->get_result();
        if ($row_user_id = $result_user_id->fetch_assoc()) {
            $id_usuario_responsable_para_guardar = $row_user_id['id'];
        }
        $stmt_get_user_id->close();
    }

    if ($id_usuario_responsable_para_guardar === null) {
        $_SESSION['error_global'] = "No se pudo encontrar el usuario responsable con la cédula '" . htmlspecialchars($responsable_cedula_form) . "' en el sistema. Verifique que el usuario exista.";
        header('Location: index.php');
        exit;
    }

    $conexion->begin_transaction();
    $errores_guardado = [];
    $activos_guardados_count = 0;

    if (!empty($aplicaciones_usadas_responsable_string)) {
        $sql_update_usuario = "UPDATE usuarios SET aplicaciones_usadas = ? WHERE id = ?";
        $stmt_update_usuario = $conexion->prepare($sql_update_usuario);
        if ($stmt_update_usuario) {
            $stmt_update_usuario->bind_param("si", $aplicaciones_usadas_responsable_string, $id_usuario_responsable_para_guardar);
            $stmt_update_usuario->execute();
            $stmt_update_usuario->close();
        }
    }

    // --- CAMBIO EN LA CONSULTA SQL: Se eliminan 'regional' y 'Empresa' ---
    $sql = "INSERT INTO activos_tecnologicos (
                id_usuario_responsable, id_tipo_activo, marca, serie, estado, 
                valor_aproximado, Codigo_Inv, detalles, 
                procesador, ram, disco_duro, tipo_equipo, red, sistema_operativo, 
                offimatica, antivirus, satisfaccion_rating, 
                fecha_compra, valor_residual, metodo_depreciacion, fecha_inicio_depreciacion,
                fecha_registro
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"; 
    // Ahora son 21 '?'

    $stmt_activos = $conexion->prepare($sql);

    if (!$stmt_activos) {
        error_log("Error al preparar la consulta de inserción de activos: " . $conexion->error);
        $_SESSION['error_global'] = "Error crítico del sistema (preparación de activos). Contacte al administrador.";
        $conexion->rollback();
        header('Location: index.php');
        exit;
    }

    foreach ($activos_lote as $index => $activo_data) {
        $nombre_tipo_activo_form = trim($activo_data['tipo_activo'] ?? '');
        $id_tipo_activo_para_guardar = null;

        if (!empty($nombre_tipo_activo_form)) {
            $sql_get_tipo_id = "SELECT id_tipo_activo FROM tipos_activo WHERE nombre_tipo_activo = ?";
            $stmt_get_tipo_id = $conexion->prepare($sql_get_tipo_id);
            if ($stmt_get_tipo_id) {
                $stmt_get_tipo_id->bind_param("s", $nombre_tipo_activo_form);
                $stmt_get_tipo_id->execute();
                $result_tipo_id = $stmt_get_tipo_id->get_result();
                if ($row_tipo_id = $result_tipo_id->fetch_assoc()) {
                    $id_tipo_activo_para_guardar = $row_tipo_id['id_tipo_activo'];
                }
                $stmt_get_tipo_id->close();
            }
        }
        
        if ($id_tipo_activo_para_guardar === null && !empty($nombre_tipo_activo_form) ) {
             $errores_guardado[] = "Activo #".($index+1).": El tipo de activo '".htmlspecialchars($nombre_tipo_activo_form)."' no es válido.";
             continue;
        }

        $marca = trim($activo_data['marca'] ?? '');
        $serie = trim($activo_data['serie'] ?? '');
        $estado = trim($activo_data['estado'] ?? '');
        $valor_aproximado_str = trim($activo_data['valor_aproximado'] ?? '');
        $codigo_inv_form = trim($activo_data['codigo_inv'] ?? '');
        $detalles = trim($activo_data['detalles'] ?? '');
        $procesador = trim($activo_data['procesador'] ?? '');
        $ram = trim($activo_data['ram'] ?? '');
        $disco_duro = trim($activo_data['disco_duro'] ?? '');
        $tipo_equipo = trim($activo_data['tipo_equipo'] ?? '');
        $red = trim($activo_data['red'] ?? '');
        $sistema_operativo = trim($activo_data['sistema_operativo'] ?? '');
        $offimatica = trim($activo_data['offimatica'] ?? '');
        $antivirus = trim($activo_data['antivirus'] ?? '');
        $satisfaccion_rating = (isset($activo_data['satisfaccion_rating']) && $activo_data['satisfaccion_rating'] !== '') ? (int)$activo_data['satisfaccion_rating'] : null;
        
        $fecha_compra = empty(trim($activo_data['fecha_compra'] ?? '')) ? null : trim($activo_data['fecha_compra']);
        $valor_residual = trim($activo_data['valor_residual'] ?? '0');
        $metodo_depreciacion = trim($activo_data['metodo_depreciacion'] ?? 'Linea Recta');
        $fecha_inicio_depreciacion = $fecha_compra;

        // --- CAMBIO: Se eliminan $regional_activo y $empresa_activo ya que no se guardan aquí ---
        // $regional_activo = trim($activo_data['regional'] ?? null); 
        // $empresa_activo = trim($activo_data['empresa'] ?? null);   

        if (empty($nombre_tipo_activo_form) || empty($marca) || empty($serie) || empty($estado) || $valor_aproximado_str === '' || $fecha_compra === null) {
            $errores_guardado[] = "Activo #".($index+1)." (Serie: ".htmlspecialchars($serie)."): Faltan campos obligatorios (Tipo, Marca, Serie, Estado, Valor, Fecha Compra).";
            continue;
        }
        $valor_aproximado = filter_var($valor_aproximado_str, FILTER_VALIDATE_FLOAT);
        if ($valor_aproximado === false || $valor_aproximado < 0) {
            $errores_guardado[] = "Activo #".($index+1)." (Serie: ".htmlspecialchars($serie)."): Valor aproximado no es válido.";
            continue;
        }
        
        // --- CAMBIO EN BIND_PARAM: Se quitan las variables y tipos para regional y empresa ---
        $tipos_bind = "iisssdsssssssssssisss"; // 21 parámetros
        $stmt_activos->bind_param( $tipos_bind, 
            $id_usuario_responsable_para_guardar, $id_tipo_activo_para_guardar, $marca, $serie, $estado,
            $valor_aproximado, $codigo_inv_form, $detalles,
            $procesador, $ram, $disco_duro, $tipo_equipo, $red, $sistema_operativo,
            $offimatica, $antivirus, $satisfaccion_rating,
            $fecha_compra, $valor_residual, $metodo_depreciacion, $fecha_inicio_depreciacion
            // Se eliminaron $regional_activo, $empresa_activo de aquí
        );

        try {
            $stmt_activos->execute();
            $id_activo_creado = $conexion->insert_id;
            $activos_guardados_count++;
            $descripcion_historial = "Activo creado. Tipo: ".htmlspecialchars($nombre_tipo_activo_form).", Serie: ".htmlspecialchars($serie);
            $usuario_actual_sistema_para_historial = $_SESSION['usuario_login'] ?? 'Sistema';
            registrar_evento_historial($conexion, $id_activo_creado, HISTORIAL_TIPO_CREACION, $descripcion_historial, $usuario_actual_sistema_para_historial, null, $activo_data);
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() == 1062) { 
                 if (strpos(strtolower($e->getMessage()), 'serie') !== false) { // Convertir a minúsculas para la comparación
                    $errores_guardado[] = "Activo (Serie: ".htmlspecialchars($serie)."): Esta serie ya se encuentra registrada.";
                } elseif (strpos(strtolower($e->getMessage()), 'codigo_inv') !== false) { // Convertir a minúsculas
                    $errores_guardado[] = "Activo (Cód. Inv.: ".htmlspecialchars($codigo_inv_form)."): Este Código de Inventario ya se encuentra registrado.";
                } else {
                    $errores_guardado[] = "Activo (Serie: ".htmlspecialchars($serie)."): Error de entrada duplicada. Verifique serie y código de inventario.";
                }
            } else {
                $errores_guardado[] = "Activo (Serie: ".htmlspecialchars($serie)."): Error de base de datos (#".$e->getCode().") - " . $e->getMessage();
            }
            error_log("Error al guardar activo S/N ".htmlspecialchars($serie).": " . $e->getMessage());
            continue; 
        }
    } 
    $stmt_activos->close();

    if (empty($errores_guardado) && $activos_guardados_count > 0) {
        $conexion->commit();
        $_SESSION['mensaje_global'] = $activos_guardados_count . " activo(s) registrado(s) exitosamente.";
    } elseif ($activos_guardados_count > 0 && !empty($errores_guardado)) {
        $conexion->commit(); 
        $_SESSION['error_global'] = $activos_guardados_count . " activo(s) guardado(s), pero con errores en otros: " . implode("; ", $errores_guardado);
    } else { 
        $conexion->rollback();
        if (empty($errores_guardado)) { // Si no hay errores específicos pero no se guardó nada
            $_SESSION['error_global'] = "No se pudo registrar ningún activo. Verifique los datos.";
        } else {
            $_SESSION['error_global'] = "No se pudo registrar ningún activo. Errores: " . implode("; ", $errores_guardado);
        }
    }

    header('Location: index.php');
    exit;

} else {
    $_SESSION['error_global'] = "Acceso no permitido.";
    header('Location: index.php');
    exit;
}
?>