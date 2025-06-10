<?php
// Asegúrate de que estas líneas estén al principio y descomentadas para depuración si persisten problemas:
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

session_start(); 
require_once 'backend/auth_check.php';
restringir_acceso_pagina(['admin', 'tecnico']);

require_once 'backend/db.php';
require_once 'backend/historial_helper.php';

// Definiciones de constantes de historial
if (!defined('HISTORIAL_TIPO_MANTENIMIENTO_INICIADO')) define('HISTORIAL_TIPO_MANTENIMIENTO_INICIADO', 'MANTENIMIENTO INICIADO');
if (!defined('HISTORIAL_TIPO_MANTENIMIENTO_FINALIZADO')) define('HISTORIAL_TIPO_MANTENIMIENTO_FINALIZADO', 'MANTENIMIENTO FINALIZADO');
if (!defined('HISTORIAL_TIPO_MANTENIMIENTO')) define('HISTORIAL_TIPO_MANTENIMIENTO', 'MANTENIMIENTO'); // Para mantenimientos rápidos/preventivos
if (!defined('HISTORIAL_TIPO_BAJA')) define('HISTORIAL_TIPO_BAJA', 'BAJA');
if (!defined('HISTORIAL_TIPO_ELIMINACION_FISICA')) define('HISTORIAL_TIPO_ELIMINACION_FISICA', 'ELIMINACIÓN FÍSICA');


$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Usuario';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'Desconocido';
$usuario_actual_sistema_para_historial = $_SESSION['usuario_login'] ?? 'Sistema';

$conexion_error_msg = null;
if (isset($conn) && !isset($conexion)) { $conexion = $conn; }
if (!isset($conexion) || !$conexion || (method_exists($conexion, 'connect_error') && $conexion->connect_error)) {
    $db_conn_error_detail = method_exists($conexion, 'connect_error') ? $conexion->connect_error : 'Desconocido';
    error_log("Error de conexión BD en mantenimiento.php: " . $db_conn_error_detail);
    $conexion_error_msg = "<div class='alert alert-danger'>Error crítico de conexión a la base de datos. Funcionalidad limitada.</div>";
} else {
    $conexion->set_charset("utf8mb4");
}

$mensaje = $_SESSION['mantenimiento_mensaje'] ?? "";
$error_mensaje = $_SESSION['mantenimiento_error_mensaje'] ?? "";
unset($_SESSION['mantenimiento_mensaje'], $_SESSION['mantenimiento_error_mensaje']);

$serie_buscada = trim($_GET['serie_buscada'] ?? '');
$activo_encontrado = null;
$activo_esta_en_mantenimiento = false; 

$opciones_diagnostico = [
    'Falla de Hardware (General)', 'Falla de Componente Específico', 'Falla de Software (Sistema Operativo)',
    'Falla de Software (Aplicación)', 'Mantenimiento Preventivo', 'Limpieza Interna/Externa',
    'Actualización de Componentes', 'Actualización de Software/Firmware', 'Error de Configuración',
    'Daño Físico Accidental', 'Problema de Red/Conectividad', 'Falla Eléctrica',
    'Infección Virus/Malware', 'Desgaste por Uso', 'Otro (Detallar)'
];
$opciones_motivo_baja = [
    'Obsolescencia', 'Daño irreparable (Confirmado post-mantenimiento)', 'Pérdida', 'Robo', 'Venta',
    'Donación', 'Fin de vida útil', 'Otro (especificar en observaciones)'
];
$estados_finales_operativos = ['Bueno', 'Regular', 'Malo'];

$proveedores = [];
$tecnicos_internos = [];

if (!$conexion_error_msg) {
    $result_proveedores = $conexion->query("SELECT id, nombre_proveedor FROM proveedores_mantenimiento ORDER BY nombre_proveedor ASC");
    if ($result_proveedores) { while ($row = $result_proveedores->fetch_assoc()) { $proveedores[] = $row; }}
    else { error_log("Error al obtener proveedores: " . $conexion->error); }

    $sql_tecnicos = "SELECT id, usuario, nombre_completo, rol FROM usuarios WHERE rol IN ('admin', 'tecnico') AND activo = 1 ORDER BY nombre_completo ASC";
    $result_tecnicos = $conexion->query($sql_tecnicos);
    if ($result_tecnicos) { while ($row = $result_tecnicos->fetch_assoc()) { $tecnicos_internos[] = $row; }}
    else { error_log("Error al obtener técnicos internos: " . $conexion->error); }
}

function fetch_activo_completo($db_conn, $serie_o_id, $es_id = false) {
    if (!$db_conn || (method_exists($db_conn, 'connect_error') && $db_conn->connect_error) ) return null;
    $sql = "SELECT 
                a.id, 
                ta.nombre_tipo_activo, 
                a.marca, 
                a.serie, 
                u.nombre_completo AS nombre_responsable, 
                u.usuario AS cedula_responsable, 
                u.empresa AS Empresa_responsable, 
                u.regional AS regional_del_responsable, 
                a.estado AS estado_actual, 
                a.valor_aproximado, 
                a.fecha_compra, 
                a.Codigo_Inv, 
                a.detalles
            FROM activos_tecnologicos a
            LEFT JOIN usuarios u ON a.id_usuario_responsable = u.id
            LEFT JOIN tipos_activo ta ON a.id_tipo_activo = ta.id_tipo_activo
            WHERE ";
    $sql .= $es_id ? "a.id = ?" : "a.serie = ?";
    
    $stmt = $db_conn->prepare($sql);
    if ($stmt) {
        if($es_id) $stmt->bind_param("i", $serie_o_id); else $stmt->bind_param("s", $serie_o_id);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            $data = $result->fetch_assoc(); 
            $stmt->close();
            return $data; 
        } else {
            error_log("Error al ejecutar consulta en fetch_activo_completo: " . $stmt->error);
        }
        $stmt->close();
    } else {
        error_log("Error al preparar consulta en fetch_activo_completo: " . $db_conn->error);
    }
    return null;
}

if (!$conexion_error_msg && (isset($_GET['buscar_activo_serie']) || !empty($serie_buscada))) {
    if(empty($serie_buscada) && isset($_GET['serie_buscada'])) $serie_buscada = trim($_GET['serie_buscada']);
    
    if (!empty($serie_buscada)) {
        $activo_encontrado = fetch_activo_completo($conexion, $serie_buscada, false);

        if ($activo_encontrado) {
            if ($activo_encontrado['estado_actual'] === 'Dado de Baja') {
                $error_mensaje = "El activo con la serie '" . htmlspecialchars($serie_buscada) . "' ya se encuentra 'Dado de Baja'. No se pueden realizar más acciones de mantenimiento.";
                $activo_encontrado = null; 
            } elseif ($activo_encontrado['estado_actual'] === 'En Mantenimiento') {
                $activo_esta_en_mantenimiento = true;
            }
        } else {
            if(empty($error_mensaje) && !$conexion_error_msg) $error_mensaje = "No se encontró ningún activo con la serie '" . htmlspecialchars($serie_buscada) . "'.";
        }
    } elseif (isset($_GET['buscar_activo_serie'])) { 
        $error_mensaje = "Por favor, ingrese un número de serie para buscar.";
    }
}

// --- LÓGICA POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$conexion_error_msg) {
    $serie_buscada_para_redireccion = ""; 
    $id_activo_afectado_post = null;

    $id_usuario_que_registra = $_SESSION['usuario_id'] ?? null;
    if (empty($id_usuario_que_registra)) { 
        $_SESSION['mantenimiento_error_mensaje'] = "Error de sesión. Por favor, inicie sesión nuevamente.";
        header("Location: mantenimiento.php"); exit;
    }

    // ------ REGISTRAR NUEVO MANTENIMIENTO / O MANTENIMIENTO RÁPIDO COMPLETADO ------
    if (isset($_POST['registrar_nuevo_mantenimiento_submit'])) {
        $id_activo = filter_input(INPUT_POST, 'id_activo_mantenimiento', FILTER_VALIDATE_INT);
        $id_activo_afectado_post = $id_activo;
        $serie_buscada_original_post = trim($_POST['serie_buscada_original_post_mantenimiento'] ?? '');
        $serie_buscada_para_redireccion = $serie_buscada_original_post;
        
        $fecha_inicio_mantenimiento = trim($_POST['fecha_inicio_mantenimiento'] ?? '');
        $diagnostico = trim($_POST['diagnostico_nuevo_mant'] ?? '');
        $detalle_trabajo_inicial = trim($_POST['detalle_trabajo_inicial_mant'] ?? '');
        $costo_estimado_str = trim($_POST['costo_estimado_mant'] ?? '0');
        $proveedor_id_nuevo_mant = filter_input(INPUT_POST, 'proveedor_id_nuevo_mant', FILTER_VALIDATE_INT) ?: null;
        $tecnico_interno_id_nuevo_mant = filter_input(INPUT_POST, 'tecnico_interno_id_nuevo_mant', FILTER_VALIDATE_INT) ?: null;
        $mantenimiento_completado_check = isset($_POST['mantenimiento_completado_check']);
        $estado_final_si_completado = trim($_POST['estado_final_nuevo_mant'] ?? '');

        if (!$id_activo || empty($fecha_inicio_mantenimiento) || empty($diagnostico) || ($mantenimiento_completado_check && empty($estado_final_si_completado)) ) {
            $error_mensaje = "Nuevo Mantenimiento: Datos obligatorios faltantes. Asegúrese de llenar Fecha Inicio, Diagnóstico y Estado Final (si está completado).";
        } elseif ($mantenimiento_completado_check && !in_array($estado_final_si_completado, $estados_finales_operativos)){
            $error_mensaje = "Nuevo Mantenimiento: Si el mantenimiento está completado, debe seleccionar un estado final válido (Bueno, Regular, Malo).";
        } elseif (!is_numeric($costo_estimado_str) || floatval($costo_estimado_str) < 0) {
            $error_mensaje = "El costo debe ser un número válido y no negativo.";
        } else {
            $costo_estimado = floatval($costo_estimado_str);
            $nombre_proveedor = "N/A"; 
            if($proveedor_id_nuevo_mant) { 
                $prov_sel = array_filter($proveedores, function($p) use ($proveedor_id_nuevo_mant) { return $p['id'] == $proveedor_id_nuevo_mant; });
                if(!empty($prov_sel)) $nombre_proveedor = reset($prov_sel)['nombre_proveedor'];
            }
            $nombre_tecnico = "N/A"; 
            if($tecnico_interno_id_nuevo_mant) { 
                $tec_sel = array_filter($tecnicos_internos, function($t) use ($tecnico_interno_id_nuevo_mant) { return $t['id'] == $tecnico_interno_id_nuevo_mant; });
                if(!empty($tec_sel)) $nombre_tecnico = reset($tec_sel)['nombre_completo'] . " (" . ucfirst(reset($tec_sel)['rol']) . ")";
            }
            
            $conexion->begin_transaction();
            try {
                $datos_anteriores_del_activo_reg = fetch_activo_completo($conexion, $id_activo, true);
                $estado_anterior_del_activo = $datos_anteriores_del_activo_reg['estado_actual'] ?? 'Desconocido';

                $estado_a_actualizar_activo = $mantenimiento_completado_check ? $estado_final_si_completado : 'En Mantenimiento';
                $tipo_historial = $mantenimiento_completado_check ? HISTORIAL_TIPO_MANTENIMIENTO_FINALIZADO : HISTORIAL_TIPO_MANTENIMIENTO_INICIADO; // Ajustado
                
                $descripcion_historial = $mantenimiento_completado_check ? "Mantenimiento completado. Estado final: " . htmlspecialchars($estado_a_actualizar_activo) : "Inicio de mantenimiento.";
                $descripcion_historial .= " Diagnóstico: " . htmlspecialchars($diagnostico) . ".";
                if ($costo_estimado > 0) $descripcion_historial .= " Costo Estimado/Real: $" . number_format($costo_estimado, 0, ',', '.') . ".";
                if ($nombre_proveedor !== "N/A") $descripcion_historial .= " Proveedor: " . htmlspecialchars($nombre_proveedor) . ".";
                if ($nombre_tecnico !== "N/A") $descripcion_historial .= " Téc. Interno: " . htmlspecialchars($nombre_tecnico) . ".";
                if (!empty($detalle_trabajo_inicial)) $descripcion_historial .= " Detalle: " . htmlspecialchars($detalle_trabajo_inicial) . ".";
                
                $datos_mantenimiento_reg = [
                    'fecha_mantenimiento' => $fecha_inicio_mantenimiento, // Renombrado para consistencia
                    'diagnostico' => $diagnostico, 
                    'detalle_trabajo' => $detalle_trabajo_inicial, 
                    'costo_mantenimiento' => $costo_estimado,
                    'proveedor_id' => $proveedor_id_nuevo_mant, 
                    'nombre_proveedor' => $nombre_proveedor,
                    'tecnico_interno_id' => $tecnico_interno_id_nuevo_mant, 
                    'nombre_tecnico' => $nombre_tecnico,
                    'fue_completado_en_registro' => $mantenimiento_completado_check,
                    'estado_final_si_completado' => $mantenimiento_completado_check ? $estado_a_actualizar_activo : null
                ];
                registrar_evento_historial($conexion, $id_activo, $tipo_historial, $descripcion_historial, $usuario_actual_sistema_para_historial, ['estado_activo' => $estado_anterior_del_activo], $datos_mantenimiento_reg);

                if ($estado_a_actualizar_activo !== $estado_anterior_del_activo) {
                    $stmt_update_estado = $conexion->prepare("UPDATE activos_tecnologicos SET estado = ? WHERE id = ?");
                    if($stmt_update_estado){ $stmt_update_estado->bind_param("si", $estado_a_actualizar_activo, $id_activo); $stmt_update_estado->execute(); $stmt_update_estado->close(); }
                    else { throw new Exception("Error al preparar la actualización de estado: " . $conexion->error); }
                }
                
                $conexion->commit();
                $mensaje = "Mantenimiento registrado/actualizado exitosamente para el activo S/N: " . htmlspecialchars($serie_buscada_original_post) . ".";
                if ($estado_a_actualizar_activo === 'En Mantenimiento' || ($mantenimiento_completado_check && $estado_a_actualizar_activo === 'Malo')) {
                    // Mantener la serie buscada
                } else {
                     $serie_buscada_para_redireccion = ""; 
                }

            } catch (Exception $e) {
                $conexion->rollback(); $error_mensaje = "Error: " . $e->getMessage();
            }
        }
    } 
    // ------ FINALIZAR MANTENIMIENTO EXISTENTE ------
    elseif (isset($_POST['finalizar_mantenimiento_existente_submit'])) {
        $id_activo_finalizar = filter_input(INPUT_POST, 'id_activo_finalizar', FILTER_VALIDATE_INT);
        $id_activo_afectado_post = $id_activo_finalizar;
        $serie_buscada_original_post = trim($_POST['serie_buscada_original_post_finalizar'] ?? '');
        $serie_buscada_para_redireccion = $serie_buscada_original_post;

        $fecha_finalizacion = trim($_POST['fecha_finalizacion_mant'] ?? '');
        $estado_final_activo = trim($_POST['estado_final_existente_mant'] ?? '');
        $observaciones_finalizacion = trim($_POST['observaciones_finalizacion_mant'] ?? '');
        $costo_adicional_str = trim($_POST['costo_adicional_final_mant'] ?? '0');

        if (!$id_activo_finalizar || empty($fecha_finalizacion) || empty($estado_final_activo) || !in_array($estado_final_activo, $estados_finales_operativos)) {
            $error_mensaje = "Finalizar Mantenimiento: ID Activo, Fecha de Finalización y Estado Final Válido son obligatorios.";
        } elseif (!is_numeric($costo_adicional_str) || floatval($costo_adicional_str) < 0) {
            $error_mensaje = "El costo adicional debe ser un número válido y no negativo.";
        } else {
            $costo_adicional = floatval($costo_adicional_str);
            $conexion->begin_transaction();
            try {
                $activo_antes_de_finalizar = fetch_activo_completo($conexion, $id_activo_finalizar, true);
                if(!$activo_antes_de_finalizar){ throw new Exception("No se encontró el activo ID: $id_activo_finalizar para finalizar mantenimiento.");}
                $estado_anterior_real = $activo_antes_de_finalizar['estado_actual'] ?? 'En Mantenimiento';

                $stmt_update_estado_fin = $conexion->prepare("UPDATE activos_tecnologicos SET estado = ? WHERE id = ?");
                if(!$stmt_update_estado_fin) throw new Exception("Error preparando actualización de estado (finalizar): " . $conexion->error);
                $stmt_update_estado_fin->bind_param("si", $estado_final_activo, $id_activo_finalizar);
                if(!$stmt_update_estado_fin->execute()) throw new Exception("Error actualizando estado (finalizar): " . $stmt_update_estado_fin->error);
                $stmt_update_estado_fin->close();
                
                error_log("[MANTENIMIENTO DEBUG] Activo ID $id_activo_finalizar actualizado a estado: $estado_final_activo");

                $descripcion_hist_fin = "Mantenimiento finalizado. Activo cambiado a estado: " . htmlspecialchars($estado_final_activo) . ".";
                if(!empty($observaciones_finalizacion)) $descripcion_hist_fin .= " Observaciones: " . htmlspecialchars($observaciones_finalizacion) . ".";
                if($costo_adicional > 0) $descripcion_hist_fin .= " Costo adicional/final: $" . number_format($costo_adicional, 0, ',', '.') . ".";
                
                $datos_mantenimiento_fin = [
                    'fecha_finalizacion' => $fecha_finalizacion,
                    'estado_final_activo' => $estado_final_activo,
                    'observaciones_finalizacion' => $observaciones_finalizacion,
                    'costo_adicional_final' => $costo_adicional
                ];
                registrar_evento_historial($conexion, $id_activo_finalizar, HISTORIAL_TIPO_MANTENIMIENTO_FINALIZADO, $descripcion_hist_fin, $usuario_actual_sistema_para_historial, ['estado_activo' => $estado_anterior_real], $datos_mantenimiento_fin);

                $conexion->commit();
                $mensaje = "Mantenimiento finalizado exitosamente para el activo S/N: " . htmlspecialchars($serie_buscada_original_post) . ". Nuevo estado: " . htmlspecialchars($estado_final_activo) . ".";
                if ($estado_final_activo === 'Malo') {
                    // Mantener serie_buscada para que se muestre la opción de baja
                } else {
                     $serie_buscada_para_redireccion = ""; 
                }
               
            } catch (Exception $e) {
                $conexion->rollback();
                $error_mensaje = "Error al finalizar mantenimiento: " . $e->getMessage();
                error_log("Error en transacción de finalizar mantenimiento: " . $e->getMessage());
            }
        }
    } 
    // ------ DAR DE BAJA (desde el modal que se abre en mantenimiento) ------
    elseif (isset($_POST['submit_dar_baja_desde_mantenimiento'])) {
        if (!tiene_permiso_para('dar_baja_activo')) {
            $error_mensaje = "Acción no permitida para su rol.";
        } elseif (empty($_POST['id_activo_baja_mantenimiento']) || empty($_POST['motivo_baja_mantenimiento'])) {
            $error_mensaje = "Faltan datos para dar de baja el activo (ID o Motivo).";
        } else {
            $id_activo_baja = filter_input(INPUT_POST, 'id_activo_baja_mantenimiento', FILTER_VALIDATE_INT);
            $serie_buscada_original_post = trim($_POST['serie_buscada_original_post_baja'] ?? '');
            // $id_activo_afectado_post se usa para la redirección si se mantiene la serie
            $id_activo_afectado_post = $id_activo_baja; 

            $motivo_baja = trim($_POST['motivo_baja_mantenimiento']);
            $observaciones_baja = trim($_POST['observaciones_baja_mantenimiento'] ?? '');
            
            $datos_anteriores_del_activo = fetch_activo_completo($conexion, $id_activo_baja, true);
            
            if (!$datos_anteriores_del_activo) { $error_mensaje = "Activo a dar de baja no encontrado (ID: " . htmlspecialchars($id_activo_baja) . ")."; }
            elseif ($datos_anteriores_del_activo['estado_actual'] === 'Dado de Baja') { $error_mensaje = "El activo ID: " . htmlspecialchars($id_activo_baja) . " ya está Dado de Baja."; }
            else {
                $conexion->begin_transaction();
                try {
                    $sql_baja = "UPDATE activos_tecnologicos SET estado = 'Dado de Baja' WHERE id = ?";
                    $stmt_baja = $conexion->prepare($sql_baja);
                    if (!$stmt_baja) throw new Exception("Error preparando baja: " . $conexion->error);
                    $stmt_baja->bind_param('i', $id_activo_baja);
                    if (!$stmt_baja->execute()) throw new Exception("Error al dar de baja: " . $stmt_baja->error);
                    
                    if ($stmt_baja->affected_rows > 0) {
                        $stmt_baja->close();
                        $tipo_activo_baja = $datos_anteriores_del_activo['nombre_tipo_activo'] ?? 'N/A';
                        $serie_baja_hist = $datos_anteriores_del_activo['serie'] ?? 'N/A';
                        $responsable_anterior_baja = $datos_anteriores_del_activo['nombre_responsable'] ?? 'N/A';
                        $empresa_anterior_baja_usuario = $datos_anteriores_del_activo['Empresa_responsable'] ?? 'N/A';

                        $descripcion_hist_baja = "Activo Dado de Baja. Tipo: " . htmlspecialchars($tipo_activo_baja) . ", Serie: " . htmlspecialchars($serie_baja_hist) . ". Motivo: " . htmlspecialchars($motivo_baja) . ".";
                        if (!empty($observaciones_baja)) { $descripcion_hist_baja .= " Observaciones: " . htmlspecialchars($observaciones_baja); }
                        $descripcion_hist_baja .= " (Responsable anterior: " . htmlspecialchars($responsable_anterior_baja) . ", Empresa anterior (Resp.): " . htmlspecialchars($empresa_anterior_baja_usuario) . ")";
                        
                        $datos_contexto_baja = ['estado_anterior' => $datos_anteriores_del_activo['estado_actual'], 'motivo_baja' => $motivo_baja, 'observaciones_baja' => $observaciones_baja, 'fecha_efectiva_baja' => date('Y-m-d H:i:s'), 'originado_desde' => 'modulo_mantenimiento'];
                        registrar_evento_historial($conexion, $id_activo_baja, HISTORIAL_TIPO_BAJA, $descripcion_hist_baja, $usuario_actual_sistema_para_historial, $datos_anteriores_del_activo, $datos_contexto_baja);
                        $conexion->commit();
                        $mensaje = "Activo ID: " . htmlspecialchars($id_activo_baja) . " dado de baja exitosamente.";
                        $serie_buscada_para_redireccion = ""; // Limpiar la serie para que la página no intente recargar el activo dado de baja
                    } else {
                        $stmt_baja->close();
                        throw new Exception("El activo no fue actualizado (posiblemente ya estaba dado de baja o el ID no existe).");
                    }
                } catch (Exception $e) {
                    $conexion->rollback();
                    $error_mensaje = "Error al dar de baja: " . $e->getMessage();
                    error_log("Error en dar de baja desde mantenimiento: " . $e->getMessage());
                    $serie_buscada_para_redireccion = $serie_buscada_original_post; 
                }
            }
        }
    }

    if (!empty($mensaje) || !empty($error_mensaje)) {
        $_SESSION['mantenimiento_mensaje'] = $mensaje;
        $_SESSION['mantenimiento_error_mensaje'] = $error_mensaje;
    }
    
    $redirect_url = "mantenimiento.php";
    if (!empty($serie_buscada_para_redireccion)) { 
         $redirect_url .= "?serie_buscada=" . urlencode($serie_buscada_para_redireccion);
    }
    header("Location: " . $redirect_url);
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrar Mantenimiento de Activos</title>
    <link rel="icon" type="image/x-icon" href="imagenes/icono.ico">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f4f7f6; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding-top: 95px; display: flex; flex-direction: column; min-height:100vh; }
        .top-bar-custom { position: fixed; top: 0; left: 0; right: 0; z-index: 1030; display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 1.5rem; background-color: #ffffff; border-bottom: 1px solid #e0e0e0; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .logo-container-top img { height: 75px; width:auto; object-fit:contain; margin-right: 15px;}
        .user-info-top { font-size: 0.9rem; }
        .main-content-area { flex-grow: 1; } 
        .card-custom { background-color: #ffffff; border-radius: 0.75rem; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15); }
        .page-header-title { color: #191970; font-weight: 600; }
        .form-label { font-weight: 500; color: #495057; }
        .input-group-text { background-color: #e9ecef; border-right: none; }
        .form-control:focus { border-color: #191970; box-shadow: 0 0 0 0.25rem rgba(25, 25, 112, 0.25); }
        .btn-primary { background-color: #191970; border-color: #191970; }
        .btn-primary:hover { background-color: #111150; border-color: #0d0d3e; }
        .asset-info-card { border-left: 5px solid #191970; }
        .asset-info-card dt { font-weight: 600; color: #333; }
        .asset-info-card dd { margin-left: 0; color: #555; }
        .footer-custom {
            font-size: 0.9rem;
            background-color: #f8f9fa; 
            border-top: 1px solid #dee2e6; 
            padding: 1rem 0; 
            margin-top: auto; 
        }
        .footer-custom a i { color: #6c757d; transition: color 0.2s ease-in-out; }
        .footer-custom a i:hover { color: #0d6efd !important; }
    </style>
</head>
<body>
    <div class="top-bar-custom">
        <div class="logo-container-top">
            <a href="menu.php" title="Ir a Inicio"><img src="imagenes/logo.png" alt="Logo Empresa"></a>
        </div>
        <div class="d-flex align-items-center">
            <span class="text-dark me-3 user-info-top"><i class="bi bi-person-circle"></i> <?= htmlspecialchars($nombre_usuario_actual_sesion) ?> (<?= htmlspecialchars(ucfirst($rol_usuario_actual_sesion)) ?>)</span>
            <form action="logout.php" method="post" class="d-flex">
                <button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-box-arrow-right"></i> Salir</button>
            </form>
        </div>
    </div>

    <div class="container mt-4 main-content-area">
        <h3 class="mb-4 text-center page-header-title">Registrar/Finalizar Mantenimiento de Activo</h3>

        <?php if (!empty($mensaje)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert"><?= $mensaje ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>
        <?php endif; ?>
        <?php if (!empty($error_mensaje)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert"><?= htmlspecialchars($error_mensaje) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>
        <?php endif; ?>
         <?php if ($conexion_error_msg): ?>
            <div class="alert alert-danger"><?= $conexion_error_msg ?></div>
        <?php endif; ?>

        <div class="card card-custom p-3 p-md-4 mb-4">
            <form method="GET" action="mantenimiento.php">
                <label for="serie_buscada_input" class="form-label">Buscar Activo por Número de Serie</label>
                <div class="input-group">
                    <span class="input-group-text" id="search-icon"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control form-control-lg" id="serie_buscada_input" name="serie_buscada" value="<?= htmlspecialchars($serie_buscada) ?>" placeholder="Ingrese serial completo del activo..." required>
                    <button class="btn btn-primary" type="submit" name="buscar_activo_serie">Buscar</button>
                </div>
            </form>
        </div>

        <?php if ($activo_encontrado): ?>
            <div class="row">
                <div class="col-lg-4 mb-4">
                    <div class="card card-custom asset-info-card h-100">
                        <div class="card-header bg-light border-0">
                            <h5 class="mb-0 text-primary"><i class="bi bi-info-circle-fill"></i> Información del Activo</h5>
                        </div>
                        <div class="card-body">
                            <dl class="row mb-0">
                                <dt class="col-sm-5 text-md-end">ID Activo:</dt>
                                <dd class="col-sm-7" id="infoIdActivo"><?= htmlspecialchars($activo_encontrado['id'] ?? 'N/A') ?></dd>
                                <dt class="col-sm-5 text-md-end">Tipo:</dt>
                                <dd class="col-sm-7"><?= htmlspecialchars($activo_encontrado['nombre_tipo_activo'] ?? 'N/A') ?></dd>
                                <dt class="col-sm-5 text-md-end">Marca:</dt>
                                <dd class="col-sm-7"><?= htmlspecialchars($activo_encontrado['marca'] ?? 'N/A') ?></dd>
                                <dt class="col-sm-5 text-md-end">Serie:</dt>
                                <dd class="col-sm-7" id="infoSerieActivo"><?= htmlspecialchars($activo_encontrado['serie'] ?? 'N/A') ?></dd>
                                <dt class="col-sm-5 text-md-end">Estado Actual:</dt>
                                <dd class="col-sm-7"><strong><?= htmlspecialchars($activo_encontrado['estado_actual'] ?? 'N/A') ?></strong></dd>
                                <dt class="col-sm-5 text-md-end">Responsable:</dt>
                                <dd class="col-sm-7"><?= htmlspecialchars($activo_encontrado['nombre_responsable'] ?? 'N/A') ?></dd>
                                <dt class="col-sm-5 text-md-end">C.C:</dt>
                                <dd class="col-sm-7"><?= htmlspecialchars($activo_encontrado['cedula_responsable'] ?? 'N/A') ?></dd>
                                <dt class="col-sm-5 text-md-end">Empresa:</dt>
                                <dd class="col-sm-7"><?= htmlspecialchars($activo_encontrado['Empresa_responsable'] ?? 'N/A') ?></dd>
                                <dt class="col-sm-5 text-md-end">Regional:</dt>
                                <dd class="col-sm-7"><?= htmlspecialchars($activo_encontrado['regional_del_responsable'] ?? 'N/A') ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>

                <div class="col-lg-8 mb-4">
                    <?php if ($activo_esta_en_mantenimiento): ?>
                        <div class="card card-custom h-100">
                            <div class="card-header bg-light border-0">
                                <h5 class="mb-0 text-primary"><i class="bi bi-check2-circle"></i> Finalizar Mantenimiento Registrado</h5>
                            </div>
                            <div class="card-body">
                                <p class="alert alert-info small"><i class="bi bi-info-circle-fill"></i> El activo S/N: <strong><?= htmlspecialchars($activo_encontrado['serie']) ?></strong> está actualmente "En Mantenimiento". Registre la finalización.</p>
                                <form method="POST" action="mantenimiento.php" id="formFinalizarMantenimiento">
                                    <input type="hidden" name="id_activo_finalizar" value="<?= htmlspecialchars($activo_encontrado['id']) ?>">
                                    <input type="hidden" name="serie_buscada_original_post_finalizar" value="<?= htmlspecialchars($serie_buscada) ?>">
                                    <div class="row g-3">
                                        <div class="col-md-6 mb-3">
                                            <label for="fecha_finalizacion_mant" class="form-label">Fecha de Finalización <span class="text-danger">*</span></label>
                                            <input type="date" class="form-control form-control-sm" id="fecha_finalizacion_mant" name="fecha_finalizacion_mant" value="<?= date('Y-m-d') ?>" required>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="estado_final_existente_mant" class="form-label">Nuevo Estado del Activo <span class="text-danger">*</span></label>
                                            <select class="form-select form-select-sm" id="estado_final_existente_mant" name="estado_final_existente_mant" required>
                                                <option value="">Seleccione...</option>
                                                <?php foreach($estados_finales_operativos as $estado_op): ?>
                                                    <option value="<?= htmlspecialchars($estado_op) ?>"><?= htmlspecialchars($estado_op) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-12 mb-3">
                                            <label for="observaciones_finalizacion_mant" class="form-label">Observaciones de Finalización</label>
                                            <textarea class="form-control form-control-sm" id="observaciones_finalizacion_mant" name="observaciones_finalizacion_mant" rows="2"></textarea>
                                        </div>
                                         <div class="col-md-6 mb-3">
                                            <label for="costo_adicional_final_mant" class="form-label">Costo Adicional/Final (COP)</label>
                                            <input type="number" class="form-control form-control-sm" id="costo_adicional_final_mant" name="costo_adicional_final_mant" step="0.01" min="0" value="0">
                                        </div>
                                    </div>
                                    <div class="mt-3 d-flex justify-content-between">
                                        <button type="submit" name="finalizar_mantenimiento_existente_submit" class="btn btn-success"><i class="bi bi-check-all"></i> Finalizar Mantenimiento</button>
                                        <?php if (tiene_permiso_para('dar_baja_activo')): ?>
                                        <button type="button" class="btn btn-outline-danger btn-sm" 
                                                data-bs-toggle="modal" data-bs-target="#modalDarBajaEnMantenimiento"
                                                onclick="prepararModalBaja('<?= htmlspecialchars($activo_encontrado['id']) ?>', '<?= htmlspecialchars($activo_encontrado['serie']) ?>', '<?= htmlspecialchars($serie_buscada) ?>')">
                                            <i class="bi bi-trash3"></i> Dar de Baja este Activo
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php else: // Formulario para registrar nuevo mantenimiento ?>
                        <div class="card card-custom h-100">
                            <div class="card-header bg-light border-0">
                                <h5 class="mb-0 text-primary"><i class="bi bi-tools"></i> Registrar Nuevo Mantenimiento</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="mantenimiento.php" id="formRegistrarMantenimiento">
                                    <input type="hidden" name="id_activo_mantenimiento" value="<?= htmlspecialchars($activo_encontrado['id']) ?>">
                                    <input type="hidden" name="serie_buscada_original_post_mantenimiento" value="<?= htmlspecialchars($serie_buscada) ?>">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label for="fecha_inicio_mantenimiento" class="form-label">Fecha de Inicio Mantenimiento <span class="text-danger">*</span></label>
                                            <input type="date" class="form-control form-control-sm" id="fecha_inicio_mantenimiento" name="fecha_inicio_mantenimiento" required value="<?= date('Y-m-d') ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label for="costo_estimado_mant" class="form-label">Costo Estimado/Real (COP)</label>
                                            <input type="number" class="form-control form-control-sm" id="costo_estimado_mant" name="costo_estimado_mant" step="0.01" min="0" value="0">
                                        </div>
                                        <div class="col-md-12">
                                            <label for="diagnostico_nuevo_mant" class="form-label">Diagnóstico Inicial <span class="text-danger">*</span></label>
                                            <select class="form-select form-select-sm" id="diagnostico_nuevo_mant" name="diagnostico_nuevo_mant" required>
                                                <option value="">Seleccione un diagnóstico...</option>
                                                <?php foreach ($opciones_diagnostico as $diag): ?>
                                                    <option value="<?= htmlspecialchars($diag) ?>"><?= htmlspecialchars($diag) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-12">
                                            <label for="detalle_trabajo_inicial_mant" class="form-label">Detalle del Trabajo a Realizar/Realizado</label>
                                            <textarea class="form-control form-control-sm" id="detalle_trabajo_inicial_mant" name="detalle_trabajo_inicial_mant" rows="2"></textarea>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="proveedor_id_nuevo_mant" class="form-label">Proveedor (Si aplica)</label>
                                            <select class="form-select form-select-sm" id="proveedor_id_nuevo_mant" name="proveedor_id_nuevo_mant">
                                                <option value="">Mantenimiento Interno / N/A</option>
                                                <?php foreach ($proveedores as $prov): ?>
                                                    <option value="<?= htmlspecialchars($prov['id']) ?>"><?= htmlspecialchars($prov['nombre_proveedor']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="tecnico_interno_id_nuevo_mant" class="form-label">Técnico Interno Asignado</label>
                                            <select class="form-select form-select-sm" id="tecnico_interno_id_nuevo_mant" name="tecnico_interno_id_nuevo_mant">
                                                <option value="">Proveedor Externo / N/A</option>
                                                <?php foreach ($tecnicos_internos as $tec): ?>
                                                    <option value="<?= htmlspecialchars((string)$tec['id']) ?>">
                                                        <?= htmlspecialchars((string)$tec['nombre_completo']) ?> (<?= htmlspecialchars(ucfirst($tec['rol'])) ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-12 mt-3">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" value="1" id="mantenimiento_completado_check" name="mantenimiento_completado_check">
                                                <label class="form-check-label" for="mantenimiento_completado_check">
                                                    Mantenimiento completado con este registro (y activo queda operativo/malo)
                                                </label>
                                            </div>
                                        </div>
                                        <div class="col-md-12 mt-2" id="seccion_estado_final_nuevo_mant" style="display:none;">
                                            <label for="estado_final_nuevo_mant" class="form-label">Estado Final del Activo <span class="text-danger">*</span></label>
                                            <select class="form-select form-select-sm" id="estado_final_nuevo_mant" name="estado_final_nuevo_mant">
                                                <option value="">Seleccione estado...</option>
                                                <option value="Bueno">Bueno</option>
                                                <option value="Regular">Regular</option>
                                                <option value="Malo">Malo (requiere acción adicional o baja)</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="mt-4 d-flex justify-content-between align-items-center">
                                        <button type="submit" name="registrar_nuevo_mantenimiento_submit" id="btnSubmitNuevoMantenimiento" class="btn btn-success"><i class="bi bi-save"></i> Iniciar/Registrar Mantenimiento</button>
                                        <?php if (tiene_permiso_para('dar_baja_activo')): ?>
                                        <button type="button" id="btnAbrirModalBajaDesdeNuevoMant" class="btn btn-outline-danger btn-sm" style="display:none;"
                                                data-bs-toggle="modal" data-bs-target="#modalDarBajaEnMantenimiento"
                                                 onclick="prepararModalBaja('<?= htmlspecialchars($activo_encontrado['id']) ?>', '<?= htmlspecialchars($activo_encontrado['serie']) ?>', '<?= htmlspecialchars($serie_buscada) ?>')">
                                            <i class="bi bi-trash3"></i> Iniciar Proceso de Baja
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php elseif ((isset($_GET['buscar_activo_serie']) || !empty($serie_buscada)) && empty($activo_encontrado) && empty($error_mensaje) && !$conexion_error_msg): ?>
            <div class="alert alert-warning mt-3 text-center">No se encontró ningún activo con la serie proporcionada.</div>
        <?php endif; ?>
    </div>

    <?php if (tiene_permiso_para('dar_baja_activo')): ?>
    <div class="modal fade" id="modalDarBajaEnMantenimiento" tabindex="-1" aria-labelledby="modalDarBajaEnMantenimientoLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post" action="mantenimiento.php" id="formDarBajaMantenimiento">
                    <div class="modal-header">
                        <h5 class="modal-title" id="modalDarBajaEnMantenimientoLabel"><i class="bi bi-exclamation-triangle-fill text-danger"></i> Confirmar Baja de Activo</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>Está a punto de dar de baja el activo con serie: <strong id="serieActivoBajaModalMantenimiento"></strong>.</p>
                        <p>Esta acción cambiará su estado a "Dado de Baja" y se registrará en el historial.</p>
                        
                        <input type="hidden" name="id_activo_baja_mantenimiento" id="idActivoBajaModalMantenimiento">
                        <input type="hidden" name="serie_buscada_original_post_baja" id="serieBuscadaOriginalPostBajaModal">

                        <div class="mb-3">
                            <label for="motivo_baja_mantenimiento" class="form-label">Motivo de la Baja <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" id="motivo_baja_mantenimiento" name="motivo_baja_mantenimiento" required>
                                <option value="">Seleccione un motivo...</option>
                                <?php foreach ($opciones_motivo_baja as $motivo): ?>
                                    <option value="<?= htmlspecialchars($motivo) ?>"><?= htmlspecialchars($motivo) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="observaciones_baja_mantenimiento" class="form-label">Observaciones Adicionales para la Baja</label>
                            <textarea class="form-control form-control-sm" id="observaciones_baja_mantenimiento" name="observaciones_baja_mantenimiento" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="submit_dar_baja_desde_mantenimiento" class="btn btn-sm btn-danger"><i class="bi bi-check-circle-fill"></i> Confirmar Baja Definitiva</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <footer class="footer-custom mt-auto py-3 bg-light border-top shadow-sm">
        <div class="container text-center">
            <div class="row align-items-center">
                <div class="col-md-6 text-md-start mb-2 mb-md-0">
                    <small class="text-muted">Sitio web desarrollado por <a href="https://www.julianxitoso.com" target="_blank" rel="noopener noreferrer" class="text-decoration-none text-primary">@julianxitoso.com</a></small>
                </div>
                <div class="col-md-6 text-md-end">
                    <a href="https://facebook.com/tu_pagina" target="_blank" class="text-muted me-3" title="Facebook">
                        <i class="bi bi-facebook" style="font-size: 1.5rem;"></i>
                    </a>
                    <a href="https://instagram.com/tu_usuario" target="_blank" class="text-muted me-3" title="Instagram">
                        <i class="bi bi-instagram" style="font-size: 1.5rem;"></i>
                    </a>
                    <a href="https://tiktok.com/@tu_usuario" target="_blank" class="text-muted" title="TikTok">
                        <i class="bi bi-tiktok" style="font-size: 1.5rem;"></i>
                    </a>
                </div>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Lógica para el formulario de "Registrar Nuevo Mantenimiento"
            const mantCompletadoCheck = document.getElementById('mantenimiento_completado_check');
            const seccionEstadoFinalNuevoMant = document.getElementById('seccion_estado_final_nuevo_mant');
            const selectEstadoFinalNuevoMant = document.getElementById('estado_final_nuevo_mant');
            const btnSubmitNuevoMantenimiento = document.getElementById('btnSubmitNuevoMantenimiento');
            const btnAbrirModalBajaDesdeNuevoMant = document.getElementById('btnAbrirModalBajaDesdeNuevoMant');
            
            const idActivoEncontradoJS = <?= json_encode($activo_encontrado['id'] ?? null); ?>;
            const serieActivoEncontradoJS = <?= json_encode($activo_encontrado['serie'] ?? ''); ?>;
            const serieBuscadaOriginalJS = <?= json_encode($serie_buscada); ?>;

            if (mantCompletadoCheck && seccionEstadoFinalNuevoMant && btnSubmitNuevoMantenimiento) {
                function toggleEstadoFinalVisibility() {
                    if (mantCompletadoCheck.checked) {
                        seccionEstadoFinalNuevoMant.style.display = 'block';
                        selectEstadoFinalNuevoMant.required = true;
                        btnSubmitNuevoMantenimiento.innerHTML = '<i class="bi bi-save"></i> Registrar y Completar Mantenimiento';
                        // Mostrar botón de baja si el estado es 'Malo'
                         if(btnAbrirModalBajaDesdeNuevoMant && selectEstadoFinalNuevoMant.value === 'Malo'){
                             btnAbrirModalBajaDesdeNuevoMant.style.display = 'inline-block';
                         } else if (btnAbrirModalBajaDesdeNuevoMant) {
                             btnAbrirModalBajaDesdeNuevoMant.style.display = 'none';
                         }
                    } else {
                        seccionEstadoFinalNuevoMant.style.display = 'none';
                        selectEstadoFinalNuevoMant.required = false;
                        selectEstadoFinalNuevoMant.value = ''; 
                        btnSubmitNuevoMantenimiento.innerHTML = '<i class="bi bi-play-circle"></i> Iniciar Mantenimiento';
                        if(btnAbrirModalBajaDesdeNuevoMant) btnAbrirModalBajaDesdeNuevoMant.style.display = 'none';
                    }
                }
                mantCompletadoCheck.addEventListener('change', toggleEstadoFinalVisibility);
                if(idActivoEncontradoJS){ 
                    toggleEstadoFinalVisibility(); 
                }
            }

            if (selectEstadoFinalNuevoMant && btnAbrirModalBajaDesdeNuevoMant) {
                selectEstadoFinalNuevoMant.addEventListener('change', function() {
                    if (this.value === 'Malo' && mantCompletadoCheck.checked) { // Solo mostrar si el check también está activo
                        btnAbrirModalBajaDesdeNuevoMant.style.display = 'inline-block';
                    } else {
                        btnAbrirModalBajaDesdeNuevoMant.style.display = 'none';
                    }
                });
                // Estado inicial del botón de baja
                if (idActivoEncontradoJS && selectEstadoFinalNuevoMant.value === 'Malo' && mantCompletadoCheck && mantCompletadoCheck.checked) {
                     btnAbrirModalBajaDesdeNuevoMant.style.display = 'inline-block';
                } else if (btnAbrirModalBajaDesdeNuevoMant) {
                     btnAbrirModalBajaDesdeNuevoMant.style.display = 'none';
                }
            }

            // Lógica para el modal "Dar de Baja" (poblar datos)
            var modalDarBajaMantenimientoEl = document.getElementById('modalDarBajaEnMantenimiento');
            if (modalDarBajaMantenimientoEl && idActivoEncontradoJS ) { // Solo si hay un activo cargado
                modalDarBajaMantenimientoEl.addEventListener('show.bs.modal', function(event) {
                    // La función prepararModalBaja se llama desde el onclick del botón
                });
            }
        });

        // Definición global de la función para que sea accesible desde el onclick
        function prepararModalBaja(idActivo, serieActivo, serieBuscadaOriginal) {
            const modalIdActivoEl = document.getElementById('idActivoBajaModalMantenimiento');
            const modalSerieActivoEl = document.getElementById('serieActivoBajaModalMantenimiento');
            const modalSerieBuscadaOrigEl = document.getElementById('serieBuscadaOriginalPostBajaModal');
            
            if(modalIdActivoEl) modalIdActivoEl.value = idActivo;
            if(modalSerieActivoEl) modalSerieActivoEl.textContent = serieActivo;
            if(modalSerieBuscadaOrigEl) modalSerieBuscadaOrigEl.value = serieBuscadaOriginal;

            const diagnosticoActualEl = document.getElementById('diagnostico_nuevo_mant');
            const detalleActualEl = document.getElementById('detalle_trabajo_inicial_mant');
            const observacionesBajaTextarea = document.getElementById('observaciones_baja_mantenimiento');
            const motivoBajaSelect = document.getElementById('motivo_baja_mantenimiento');
            const estadoPostMant = document.getElementById('estado_final_nuevo_mant');
            const estadoFinalExistente = document.getElementById('estado_final_existente_mant');

            let diag = "", detalle = "";

            if (document.getElementById('formFinalizarMantenimiento') && estadoFinalExistente && estadoFinalExistente.value === 'Malo'){
                 diag = "Confirmado como 'Malo' tras finalizar mantenimiento.";
                 detalle = document.getElementById('observaciones_finalizacion_mant')?.value || "";
            } else if (document.getElementById('formRegistrarMantenimiento') && estadoPostMant && estadoPostMant.value === 'Malo' && document.getElementById('mantenimiento_completado_check')?.checked) {
                diag = diagnosticoActualEl ? diagnosticoActualEl.value : '';
                detalle = detalleActualEl ? detalleActualEl.value : '';
            }
            
            if (diag || detalle) {
                if(motivoBajaSelect) motivoBajaSelect.value = 'Daño irreparable (Confirmado post-mantenimiento)';
                let obs = "";
                if(diag) obs += "Contexto: " + diag + ". ";
                if(detalle) obs += "Detalle Adicional: " .trim() + detalle + "."; // Corrección de concatenación
                if(observacionesBajaTextarea) observacionesBajaTextarea.value = obs.trim();
            } else {
                 if(motivoBajaSelect) motivoBajaSelect.value = ''; 
                 if(observacionesBajaTextarea) observacionesBajaTextarea.value = '';
            }
        }
    </script>
</body>
</html>
