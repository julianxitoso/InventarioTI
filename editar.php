<?php
// session_start(); // auth_check.php ya lo incluye y gestiona
require_once 'backend/auth_check.php';
// Solo 'admin' y 'tecnico' pueden acceder a esta página para editar, trasladar o dar de baja
restringir_acceso_pagina(['admin', 'tecnico']);

require_once 'backend/db.php';
require_once 'backend/historial_helper.php';

// Definir constantes de historial si no están en historial_helper.php (idealmente deberían estar allí)
if (!defined('HISTORIAL_TIPO_ACTUALIZACION')) define('HISTORIAL_TIPO_ACTUALIZACION', 'ACTUALIZACIÓN');
if (!defined('HISTORIAL_TIPO_TRASLADO')) define('HISTORIAL_TIPO_TRASLADO', 'TRASLADO');
if (!defined('HISTORIAL_TIPO_BAJA')) define('HISTORIAL_TIPO_BAJA', 'BAJA');


if (isset($conn) && !isset($conexion)) { $conexion = $conn; }
if (!isset($conexion) || !$conexion || (method_exists($conexion, 'connect_error') && $conexion->connect_error) ) {
    error_log("Fallo CRÍTICO de conexión a BD en editar.php: " . ($conexion->connect_error ?? 'Desconocido'));
    die("Error de conexión a la base de datos. No se puede continuar.");
}
$conexion->set_charset("utf8mb4");

$mensaje = "";
$cedula_buscada = '';
$regional_buscada = '';
$activos_encontrados = [];
$regionales = ['Popayan', 'Bordo', 'Santander', 'Valle', 'Pasto', 'Tuquerres', 'Huila', 'Nacional', 'Finansueños'];
$criterio_buscada_activo = false;
$usuario_actual_sistema_para_historial = $_SESSION['usuario_login'] ?? 'Sistema'; // Usar el nombre de login para el historial

// Obtener datos del usuario de la sesión para la navbar
$nombre_usuario_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Usuario';
$rol_usuario_sesion = obtener_rol_usuario(); // Para condicionales en la vista

// Determinar los criterios de búsqueda actuales y si una acción POST ocurrió
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $criterio_buscada_activo = true;
    $cedula_buscada = $_POST['cedula_original_busqueda'] ?? $_POST['cedula_original_busqueda_baja'] ?? ($_GET['cedula'] ?? '');
    $regional_buscada = $_POST['regional_original_busqueda'] ?? $_POST['regional_original_busqueda_baja'] ?? ($_GET['regional'] ?? '');
} else {
    $cedula_buscada = $_GET['cedula'] ?? '';
    $regional_buscada = $_GET['regional'] ?? '';
    if (!empty($cedula_buscada) || !empty($regional_buscada)) {
        $criterio_buscada_activo = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_SESSION['traslado_info'])) {
    unset($_SESSION['traslado_info']);
}

// Procesamiento de acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['editar_activo_submit'])) {
        if (!tiene_permiso_para('editar_activo_detalles')) {
            $mensaje = "<div class='alert alert-danger'>Acción no permitida para su rol.</div>";
        } elseif (empty($_POST['id']) || empty($_POST['tipo_activo']) || empty($_POST['marca']) || empty($_POST['serie']) || empty($_POST['estado']) || !isset($_POST['valor_aproximado']) || empty($_POST['regional_activo_form'])) {
            $mensaje = "<div class='alert alert-danger'>Error: Faltan campos obligatorios del activo para actualizar.</div>";
        } else {
            $id_activo_a_editar = (int)$_POST['id'];
            $stmt_datos_previos = $conexion->prepare("SELECT * FROM activos_tecnologicos WHERE id = ?");
            $datos_anteriores_del_activo = null;
            if ($stmt_datos_previos) {
                $stmt_datos_previos->bind_param('i', $id_activo_a_editar);
                $stmt_datos_previos->execute();
                $result_datos_previos = $stmt_datos_previos->get_result();
                $datos_anteriores_del_activo = $result_datos_previos->fetch_assoc();
                $stmt_datos_previos->close();
            }

            $sql = "UPDATE activos_tecnologicos SET tipo_activo=?, marca=?, serie=?, estado=?, valor_aproximado=?, detalles=?, regional=?, procesador=?, ram=?, disco_duro=?, tipo_equipo=?, red=?, sistema_operativo=?, offimatica=?, antivirus=? WHERE id=?";
            $stmt = $conexion->prepare($sql);
            if (!$stmt) { $mensaje = "<div class='alert alert-danger'>Error al preparar actualización: " . $conexion->error . "</div>"; }
            else {
                $stmt->bind_param('ssssdssssssssssi',
                    $_POST['tipo_activo'], $_POST['marca'], $_POST['serie'], $_POST['estado'], $_POST['valor_aproximado'],
                    $_POST['detalles'], $_POST['regional_activo_form'], $_POST['procesador'], $_POST['ram'], $_POST['disco_duro'],
                    $_POST['tipo_equipo'], $_POST['red'], $_POST['sistema_operativo'], $_POST['offimatica'], $_POST['antivirus'],
                    $id_activo_a_editar);
                if ($stmt->execute()) {
                    $mensaje = "<div class='alert alert-success'>Activo ID: ".htmlspecialchars($id_activo_a_editar)." actualizado.</div>";
                    if ($datos_anteriores_del_activo) {
                        $datos_nuevos_post = $_POST; $datos_nuevos_para_historial = []; $cambios_descripcion_array = [];
                        $campos_a_comparar = ['tipo_activo'=>'tipo_activo', 'marca'=>'marca', 'serie'=>'serie', 'estado'=>'estado', 'valor_aproximado'=>'valor_aproximado', 'detalles'=>'detalles', 'regional_activo_form'=>'regional', 'procesador'=>'procesador', 'ram'=>'ram', 'disco_duro'=>'disco_duro', 'tipo_equipo'=>'tipo_equipo', 'red'=>'red', 'sistema_operativo'=>'sistema_operativo', 'offimatica'=>'offimatica', 'antivirus'=>'antivirus'];
                        foreach ($campos_a_comparar as $post_key => $db_key) {
                            $valor_nuevo = $datos_nuevos_post[$post_key] ?? null; $valor_anterior = $datos_anteriores_del_activo[$db_key] ?? null;
                            if ($valor_nuevo !== $valor_anterior) {
                                $datos_nuevos_para_historial[$db_key] = $valor_nuevo;
                                $cambios_descripcion_array[] = ucfirst(str_replace('_',' ',$db_key)).": de '".htmlspecialchars($valor_anterior ?? 'N/A')."' a '".htmlspecialchars($valor_nuevo ?? 'N/A')."'";
                            }
                        }
                        if (!empty($cambios_descripcion_array)) {
                            $descripcion_historial = "Actualización: " . implode("; ", $cambios_descripcion_array);
                            $datos_anteriores_especificos = []; foreach(array_keys($datos_nuevos_para_historial) as $ckey){ $datos_anteriores_especificos[$ckey] = $datos_anteriores_del_activo[$ckey]??null;}
                            registrar_evento_historial($conexion, $id_activo_a_editar, HISTORIAL_TIPO_ACTUALIZACION, $descripcion_historial, $usuario_actual_sistema_para_historial, $datos_anteriores_especificos, $datos_nuevos_para_historial);
                        }
                    }
                } else { $mensaje = "<div class='alert alert-danger'>Error al actualizar: " . $stmt->error . "</div>"; }
                $stmt->close();
            }
        }
    } elseif (isset($_POST['eliminar_activo_submit'])) {
        if (!tiene_permiso_para('eliminar_activo_fisico')) {
            $mensaje = "<div class='alert alert-danger'>Acción no permitida para su rol.</div>";
        } elseif (empty($_POST['id'])) {
            $mensaje = "<div class='alert alert-danger'>ID no provisto para eliminar.</div>";
        } else {
            $id_activo_a_eliminar = (int)$_POST['id'];
            $datos_activo_a_eliminar_hist = null;
            $stmt_info_activo = $conexion->prepare("SELECT * FROM activos_tecnologicos WHERE id = ?");
            if($stmt_info_activo){
                $stmt_info_activo->bind_param('i', $id_activo_a_eliminar); $stmt_info_activo->execute();
                $datos_activo_a_eliminar_hist = $stmt_info_activo->get_result()->fetch_assoc(); $stmt_info_activo->close();
            }
            if ($datos_activo_a_eliminar_hist) {
                $descripcion_baja = "Activo ELIMINADO FÍSICAMENTE. Tipo: ".htmlspecialchars($datos_activo_a_eliminar_hist['tipo_activo']??'N/A').", Serie: ".htmlspecialchars($datos_activo_a_eliminar_hist['serie']??'N/A');
                registrar_evento_historial($conexion, $id_activo_a_eliminar, HISTORIAL_TIPO_BAJA, $descripcion_baja, $usuario_actual_sistema_para_historial, $datos_activo_a_eliminar_hist, null);
            }
            $stmt = $conexion->prepare("DELETE FROM activos_tecnologicos WHERE id=?");
            if (!$stmt) { $mensaje = "<div class='alert alert-danger'>Error preparación borrado: ".$conexion->error."</div>"; }
            else {
                $stmt->bind_param('i', $id_activo_a_eliminar);
                if ($stmt->execute()) { $mensaje = "<div class='alert alert-success'>Activo ID: ".htmlspecialchars($id_activo_a_eliminar)." eliminado físicamente.</div>"; }
                else { $mensaje = "<div class='alert alert-danger'>Error al eliminar físicamente: ".$stmt->error."</div>"; }
                $stmt->close();
            }
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'confirmar_traslado_masivo') {
        if (!tiene_permiso_para('trasladar_activo')) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Acción no permitida para su rol.']);
            exit;
        }
        // ... (Lógica de confirmar_traslado_masivo con su propio registro de historial - SIN CAMBIOS INTERNOS, ya usa $usuario_actual_sistema_para_historial) ...
        header('Content-Type: application/json'); $response = ['success' => false, 'message' => '', 'data_acta' => null];
        $activos_a_trasladar_ids_str = $_POST['ids_activos_seleccionados_traslado'] ?? '';
        $activos_a_trasladar_ids = !empty($activos_a_trasladar_ids_str) ? explode(',', $activos_a_trasladar_ids_str) : [];
        $nueva_cedula = $conexion->real_escape_string(trim($_POST['nueva_cedula_traslado'] ?? ''));
        $nuevo_nombre = $conexion->real_escape_string(trim($_POST['nuevo_nombre_traslado'] ?? ''));
        $nuevo_cargo = $conexion->real_escape_string(trim($_POST['nuevo_cargo_traslado'] ?? ''));
        $nueva_regional_traslado = $conexion->real_escape_string(trim($_POST['nueva_regional_traslado'] ?? ''));
        $cedula_origen_del_traslado = $_POST['cedula_usuario_origen_hidden'] ?? '';
        $datos_origen_para_sesion_acta = null; $datos_responsable_origen_para_historial = ['cedula' => $cedula_origen_del_traslado, 'nombre' => null, 'cargo' => null];

        if (!empty($cedula_origen_del_traslado)) {
            $stmt_origen_resp = $conexion->prepare("SELECT nombre, cargo FROM activos_tecnologicos WHERE cedula = ? LIMIT 1");
            if($stmt_origen_resp) {
                $stmt_origen_resp->bind_param("s", $cedula_origen_del_traslado); $stmt_origen_resp->execute();
                $res_origen_resp = $stmt_origen_resp->get_result();
                if($fila_origen_resp = $res_origen_resp->fetch_assoc()){
                    $datos_origen_para_sesion_acta = ['cedula' => $cedula_origen_del_traslado, 'nombre' => $fila_origen_resp['nombre'], 'cargo' => $fila_origen_resp['cargo']];
                    $datos_responsable_origen_para_historial['nombre'] = $fila_origen_resp['nombre']; $datos_responsable_origen_para_historial['cargo'] = $fila_origen_resp['cargo'];
                } $stmt_origen_resp->close();
            }
        }
        if (empty($activos_a_trasladar_ids)) { $response['message'] = "No seleccionó activos."; }
        elseif (empty($nueva_cedula)||empty($nuevo_nombre)||empty($nuevo_cargo)||empty($nueva_regional_traslado)){ $response['message'] = "Datos del nuevo responsable incompletos."; }
        else {
            $activos_a_trasladar_ids_int = array_map('intval', $activos_a_trasladar_ids);
            $activos_a_trasladar_ids_int = array_filter($activos_a_trasladar_ids_int, function($v){return $v>0;});
            if(empty($activos_a_trasladar_ids_int)){ $response['message'] = "IDs de activo no válidos."; }
            else {
                $regionales_origen_activos = [];
                if(!empty($activos_a_trasladar_ids_int)){
                    $ids_p_hist = implode(',', array_fill(0,count($activos_a_trasladar_ids_int),'?'));
                    $stmt_reg_o = $conexion->prepare("SELECT id, regional, tipo_activo, serie FROM activos_tecnologicos WHERE id IN ($ids_p_hist)");
                    if($stmt_reg_o){
                        $stmt_reg_o->bind_param(str_repeat('i',count($activos_a_trasladar_ids_int)), ...$activos_a_trasladar_ids_int); $stmt_reg_o->execute();
                        $res_reg_o = $stmt_reg_o->get_result();
                        while($row_r = $res_reg_o->fetch_assoc()){ $regionales_origen_activos[$row_r['id']]=['regional'=>$row_r['regional'],'tipo_activo'=>$row_r['tipo_activo'],'serie'=>$row_r['serie']];}
                        $stmt_reg_o->close();
                    }
                }
                $ids_sql = implode(',',$activos_a_trasladar_ids_int);
                $sql_t = "UPDATE activos_tecnologicos SET cedula=?, nombre=?, cargo=?, regional=? WHERE id IN ($ids_sql)";
                $stmt_t = $conexion->prepare($sql_t);
                if(!$stmt_t){ $response['message'] = "Error preparando traslado: ".$conexion->error; }
                else {
                    $stmt_t->bind_param('ssss', $nueva_cedula, $nuevo_nombre, $nuevo_cargo, $nueva_regional_traslado);
                    if($stmt_t->execute()){
                        $num_af = $stmt_t->affected_rows;
                        if($num_af > 0){
                            $response['success'] = true; $response['message'] = "$num_af activo(s) trasladado(s).";
                            $_SESSION['ultimo_traslado_para_acta'] = ['cedula_destino'=>$nueva_cedula,'nombre_destino'=>$nuevo_nombre,'cargo_destino'=>$nuevo_cargo,'regional_destino'=>$nueva_regional_traslado,'ids_activos'=>implode(',',$activos_a_trasladar_ids_int),'usuario_entrega_operacion'=>$usuario_actual_sistema_para_historial,'datos_origen'=>$datos_origen_para_sesion_acta];
                            $response['data_acta'] = $_SESSION['ultimo_traslado_para_acta'];
                            $datos_destino_hist = ['cedula'=>$nueva_cedula,'nombre'=>$nuevo_nombre,'cargo'=>$nuevo_cargo,'regional_activo_nueva'=>$nueva_regional_traslado];
                            foreach($activos_a_trasladar_ids_int as $id_a_t){
                                $info_a_o = $regionales_origen_activos[$id_a_t]??['regional'=>'N/A','tipo_activo'=>'N/A','serie'=>'N/A'];
                                $desc_t_h = "Activo ".htmlspecialchars($info_a_o['tipo_activo'])." S/N: ".htmlspecialchars($info_a_o['serie'])." trasladado de ".htmlspecialchars($datos_responsable_origen_para_historial['nombre']??$cedula_origen_del_traslado)." (C.C: ".htmlspecialchars($cedula_origen_del_traslado).", Regional Activo Anterior: ".htmlspecialchars($info_a_o['regional']).") a ".htmlspecialchars($nuevo_nombre)." (C.C: ".htmlspecialchars($nueva_cedula).", Regional Activo Nueva: ".htmlspecialchars($nueva_regional_traslado).").";
                                $datos_o_e_h = ['cedula_responsable'=>$cedula_origen_del_traslado,'nombre_responsable'=>$datos_responsable_origen_para_historial['nombre'],'cargo_responsable'=>$datos_responsable_origen_para_historial['cargo'],'regional_activo_anterior'=>$info_a_o['regional']];
                                registrar_evento_historial($conexion,$id_a_t,HISTORIAL_TIPO_TRASLADO,$desc_t_h,$usuario_actual_sistema_para_historial,$datos_o_e_h,$datos_destino_hist);
                            }
                        } else { $response['message'] = "Traslado procesado, sin filas afectadas.";}
                    } else { $response['message'] = "Error ejecutando traslado: ".$stmt_t->error; }
                    $stmt_t->close();
                }
            }
        }
        echo json_encode($response); exit;

    } elseif (isset($_POST['submit_dar_baja'])) {
        if (!tiene_permiso_para('dar_baja_activo')) {
            $mensaje = "<div class='alert alert-danger'>Acción no permitida para su rol.</div>";
        } elseif (empty($_POST['id_activo_baja']) || empty($_POST['motivo_baja'])) {
            $mensaje = "<div class='alert alert-danger'>Faltan datos para dar de baja el activo (ID o Motivo).</div>";
        } else {
            $id_activo_baja = filter_input(INPUT_POST, 'id_activo_baja', FILTER_VALIDATE_INT);
            $motivo_baja = trim($_POST['motivo_baja']);
            $observaciones_baja = trim($_POST['observaciones_baja'] ?? '');

            $cedula_buscada = $_POST['cedula_original_busqueda_baja'] ?? ''; // Para recargar vista
            $regional_buscada = $_POST['regional_original_busqueda_baja'] ?? ''; // Para recargar vista

            $stmt_datos_previos = $conexion->prepare("SELECT * FROM activos_tecnologicos WHERE id = ?");
            $datos_anteriores_del_activo = null;
            if ($stmt_datos_previos) {
                $stmt_datos_previos->bind_param('i', $id_activo_baja); $stmt_datos_previos->execute();
                $datos_anteriores_del_activo = $stmt_datos_previos->get_result()->fetch_assoc(); $stmt_datos_previos->close();
            }

            if (!$datos_anteriores_del_activo) {
                $mensaje = "<div class='alert alert-danger'>Activo a dar de baja no encontrado (ID: ".htmlspecialchars($id_activo_baja).").</div>";
            } elseif ($datos_anteriores_del_activo['estado'] === 'Dado de Baja') {
                $mensaje = "<div class='alert alert-warning'>El activo ID: ".htmlspecialchars($id_activo_baja)." ya está Dado de Baja.</div>";
            } else {
                $sql_baja = "UPDATE activos_tecnologicos SET estado = 'Dado de Baja' WHERE id = ?";
                $stmt_baja = $conexion->prepare($sql_baja);
                if ($stmt_baja) {
                    $stmt_baja->bind_param('i', $id_activo_baja);
                    if ($stmt_baja->execute()) {
                        $mensaje = "<div class='alert alert-success'>Activo ID: ".htmlspecialchars($id_activo_baja)." dado de baja.</div>";
                        $descripcion_hist_baja = "Activo dado de baja. Motivo: ".htmlspecialchars($motivo_baja).".";
                        if(!empty($observaciones_baja)){ $descripcion_hist_baja .= " Observaciones: ".htmlspecialchars($observaciones_baja);}
                        $datos_contexto_baja = ['estado_anterior'=>$datos_anteriores_del_activo['estado'],'motivo_baja'=>$motivo_baja,'observaciones_baja'=>$observaciones_baja,'fecha_efectiva_baja'=>date('Y-m-d H:i:s')];
                        registrar_evento_historial($conexion, $id_activo_baja, HISTORIAL_TIPO_BAJA, $descripcion_hist_baja, $usuario_actual_sistema_para_historial, $datos_anteriores_del_activo, $datos_contexto_baja);
                    } else { $mensaje = "<div class='alert alert-danger'>Error al dar de baja: ".$stmt_baja->error."</div>"; }
                    $stmt_baja->close();
                } else { $mensaje = "<div class='alert alert-danger'>Error preparando baja: ".$conexion->error."</div>"; }
            }
        }
    }
} // Fin de if ($_SERVER['REQUEST_METHOD'] === 'POST')

// Lógica para cargar activos a mostrar (se ejecuta siempre, después de POST o en GET)
if ($criterio_buscada_activo) {
    $sql_select = "SELECT * FROM activos_tecnologicos WHERE estado != 'Dado de Baja'";
    $params_select_query = []; $types_select_query = '';

    if (!empty($cedula_buscada)) {
        $sql_select .= " AND cedula = ?";
        $params_select_query[] = $cedula_buscada; $types_select_query .= 's';
    }
    if (!empty($regional_buscada)) {
        $sql_select .= " AND regional = ?";
        $params_select_query[] = $regional_buscada; $types_select_query .= 's';
    }
    $sql_select .= " ORDER BY cedula ASC, id ASC";

    $stmt_select = $conexion->prepare($sql_select);
    if ($stmt_select) {
        if (!empty($params_select_query)) { $stmt_select->bind_param($types_select_query, ...$params_select_query); }
        if ($stmt_select->execute()) {
            $activos_encontrados = $stmt_select->get_result()->fetch_all(MYSQLI_ASSOC);
        } else { $mensaje .= "<div class='alert alert-danger'>Error búsqueda: ".$stmt_select->error."</div>"; }
        $stmt_select->close();
    } else { $mensaje .= "<div class='alert alert-danger'>Error preparando búsqueda: ".$conexion->error."</div>"; }
}

$opciones_tipo_activo = ['Computador', 'Monitor', 'Impresora', 'Escáner', 'DVR', 'Contadora Billetes', 'Contadora Monedas', 'Celular', 'Impresora Térmica', 'Combo Teclado y Mouse', 'Diadema', 'Adaptador Multipuertos / Red', 'Router'];
$opciones_tipo_equipo = ['Portátil', 'Mesa', 'Todo en 1'];
$opciones_red = ['Cableada', 'Inalámbrica', 'Ambas'];
$opciones_estado_general = ['Bueno', 'Regular', 'Malo', 'En Mantenimiento', 'Dado de Baja'];
$opciones_so = ['Windows 10', 'Windows 11', 'Linux', 'MacOS', 'Otro SO', 'N/A SO'];
$opciones_offimatica = ['Office 365', 'Office Home And Business', 'Office 2021', 'Office 2019', 'Office 2016', 'LibreOffice', 'Google Workspace', 'Otro Office', 'N/A Office'];
$opciones_antivirus = ['Microsoft Defender', 'Bitdefender', 'ESET NOD32 Antivirus', 'McAfee Total Protection', 'Kaspersky', 'N/A Antivirus', 'Otro Antivirus'];

function input_editable($name, $label, $value, $form_id_suffix_func, $type = 'text', $is_readonly = true, $is_required = false, $col_class = 'col-md-4') {
    $readonly_attr = $is_readonly ? 'readonly' : ''; $required_attr = $is_required ? 'required' : '';
    $step_attr = ($type === 'number') ? "step='0.01' min='0'" : ""; $input_id = $name . '-' . $form_id_suffix_func;
    echo "<div class='{$col_class} mb-2'><label for='{$input_id}' class='form-label form-label-sm'>$label</label><input type='$type' name='$name' id='{$input_id}' class='form-control form-control-sm' value='" . htmlspecialchars($value ?? '') . "' $readonly_attr $required_attr $step_attr></div>";
}
function select_editable($name, $label, $options_array, $selected_value, $form_id_suffix_func, $is_readonly = true, $is_required = false, $col_class = 'col-md-4') {
    $disabled_attr = $is_readonly ? 'disabled' : ''; $required_attr = $is_required ? 'required' : '';
    $select_id = $name . '-' . $form_id_suffix_func;
    echo "<div class='{$col_class} mb-2'><label for='{$select_id}' class='form-label form-label-sm'>$label</label><select name='$name' id='{$select_id}' class='form-select form-select-sm' $disabled_attr $required_attr>";
    echo "<option value=''>Seleccione...</option>";
    foreach ($options_array as $opt) { $sel = ($opt == $selected_value) ? 'selected' : ''; echo "<option value=\"".htmlspecialchars($opt)."\" $sel>" . htmlspecialchars($opt) . "</option>"; }
    echo "</select></div>";
}
function textarea_editable($name, $label, $value, $form_id_suffix_func, $is_readonly = true, $col_class = 'col-md-12') {
    $readonly_attr = $is_readonly ? 'readonly' : ''; $textarea_id = $name . '-' . $form_id_suffix_func;
    echo "<div class='{$col_class} mb-2'><label for='{$textarea_id}' class='form-label form-label-sm'>$label</label><textarea name='$name' id='{$textarea_id}' class='form-control form-control-sm' rows='2' $readonly_attr>" . htmlspecialchars($value ?? '') . "</textarea></div>";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Editar, Trasladar y Dar Baja a Activos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .container-main { margin-top: 20px; margin-bottom: 40px; }
        h3.page-title { color: #333; font-weight: 600; margin-bottom: 25px; }
        .logo-container { text-align: center; margin-bottom: 5px; padding-top:10px; }
        .logo-container img { width: 180px; height: 70px; object-fit: contain;}
        .navbar-custom { background-color: #191970; }
        .navbar-custom .nav-link { color: white !important; font-weight: 500; padding: 0.5rem 1rem;}
        .navbar-custom .nav-link:hover, .navbar-custom .nav-link.active { background-color: #8b0000; color: white; }
        .btn-custom-search { background-color: #191970; color: white; }
        .btn-custom-search:hover { background-color: #8b0000; color: white; }
        .user-asset-group { background-color: #fff; padding: 20px; margin-bottom: 25px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.07); }
        .user-info-header { border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: flex-start; }
        .user-info-header .info-block { flex-grow: 1; }
        .user-info-header .actions-block { display: flex; flex-direction: column; align-items: flex-end; gap: 5px; min-width: 220px; text-align: right; }
        .user-info-header h4 { color: #37517e; font-weight: 600; margin-bottom: 2px; font-size: 1.1rem;}
        .user-info-header p { margin-bottom: 2px; font-size: 0.95em; color: #555; }
        .asset-form-outer-container { border: 1px solid #e0e0e0; border-radius: 6px; padding: 15px; margin-top:15px; background-color: #fdfdfd; }
        .asset-form-header { display: flex; align-items: center; margin-bottom:15px; border-bottom: 1px solid #eee; padding-bottom: 8px;}
        .asset-form-header .checkbox-transfer { margin-right: 10px; transform: scale(1.2); }
        .asset-form-header h6 { font-weight: 600; color: #007bff; margin-bottom:0; flex-grow: 1;}
        .form-label-sm { font-size: 0.85em; margin-bottom: 0.2rem !important; color:#454545; }
        .form-control-sm, .form-select-sm { font-size: 0.85em; padding: 0.3rem 0.6rem; }
        .action-buttons { margin-top: 15px; display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 10px;}
        .card.search-card { box-shadow: 0 2px 8px rgba(0,0,0,0.06); border:none; }
        #listaActivosTrasladar { list-style-type: disc; padding-left: 20px; max-height: 150px; overflow-y: auto; font-size:0.9em;}
        #listaActivosTrasladar li { margin-bottom: 3px;}
        /* Estilo para el activo enfocado */
        .activo-enfocado {
            border: 2px solid #0d6efd !important; /* Un borde azul llamativo */
            box-shadow: 0 0 15px rgba(13,110,253,0.5) !important; /* Una sombra para resaltarlo */
            animation: pulse-border 1.5s infinite;
        }
        @keyframes pulse-border {
            0% { box-shadow: 0 0 0 0 rgba(13,110,253,0.7); }
            70% { box-shadow: 0 0 0 10px rgba(13,110,253,0); }
            100% { box-shadow: 0 0 0 0 rgba(13,110,253,0); }
        }
    </style>
</head>
<body>
    <div class="logo-container"> <a href="menu.php"><img src="imagenes/logo3.png" alt="Logo ARPESOD ASOCIADOS SAS"></a> </div>
    <nav class="navbar navbar-expand-lg navbar-custom">
        <div class="container-fluid">
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon" style="background-image: url(&quot;data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba(255,255,255,0.8)' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e&quot;);"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                   <li class="nav-item"><a class="nav-link <?= (basename($_SERVER['PHP_SELF']) == 'menu.php') ? 'active' : '' ?>" href="menu.php">Inicio</a></li>
                   <?php if (tiene_permiso_para('crear_activo')): ?>
                       <li class="nav-item"><a class="nav-link <?= (basename($_SERVER['PHP_SELF']) == 'index.php') ? 'active' : '' ?>" href="index.php">Registrar Activo</a></li>
                   <?php endif; ?>
                   <?php if (tiene_permiso_para('editar_activo_detalles') || tiene_permiso_para('trasladar_activo') || tiene_permiso_para('dar_baja_activo') ): ?>
                       <li class="nav-item"><a class="nav-link <?= (basename($_SERVER['PHP_SELF']) == 'editar.php') ? 'active' : '' ?>" aria-current="page" href="editar.php">Editar/Trasladar/Baja</a></li>
                   <?php endif; ?>
                   <?php if (tiene_permiso_para('buscar_activo')): ?>
                       <li class="nav-item"><a class="nav-link <?= (basename($_SERVER['PHP_SELF']) == 'buscar.php') ? 'active' : '' ?>" href="buscar.php">Buscar Activos</a></li>
                   <?php endif; ?>
                   <?php if (tiene_permiso_para('generar_informes')): ?>
                       <li class="nav-item"><a class="nav-link <?= (basename($_SERVER['PHP_SELF']) == 'informes.php') ? 'active' : '' ?>" href="informes.php">Informes</a></li>
                   <?php endif; ?>
                   <?php if (tiene_permiso_para('ver_dashboard')): ?>
                        <li class="nav-item"><a class="nav-link <?= (basename($_SERVER['PHP_SELF']) == 'dashboard.php') ? 'active' : '' ?>" href="dashboard.php">Dashboard</a></li>
                   <?php endif; ?>
                   <?php if (es_admin()): ?>
                        <?php endif; ?>
                </ul>
                 <form class="d-flex ms-auto" action="logout.php" method="post">
                    <button class="btn btn-outline-light" type="submit">Cerrar sesión</button>
                 </form>
            </div>
        </div>
    </nav>

    <div class="container-main container mt-4">
        <div class="card search-card p-4">
            <h3 class="page-title mb-4 text-center">Administrar Activos (Operativos)</h3>
            <?php if ($mensaje) echo "<div id='mensaje-global-container' class='mb-3'>$mensaje</div>"; ?>
            <form method="get" class="row g-3 mb-4 align-items-end" action="editar.php">
                <div class="col-md-5"> <label for="cedula_buscar" class="form-label">Buscar por Cédula</label> <input type="text" class="form-control" id="cedula_buscar" name="cedula" value="<?= htmlspecialchars($cedula_buscada) ?>" placeholder="Ingrese cédula"> </div>
                <div class="col-md-5"> <label for="regional_buscar" class="form-label">Filtrar por Regional</label> <select name="regional" class="form-select" id="regional_buscar"> <option value="">-- Todas las Regionales --</option> <?php foreach ($regionales as $r): ?> <option value="<?= htmlspecialchars($r) ?>" <?= ($r == $regional_buscada) ? 'selected' : '' ?>><?= htmlspecialchars($r) ?></option> <?php endforeach; ?> </select> </div>
                <div class="col-md-2"> <button type="submit" class="btn btn-custom-search w-100">Buscar</button> </div>
            </form>
        </div>

        <?php if ($criterio_buscada_activo): ?>
            <div class="mt-4">
            <?php if (isset($stmt_select) && !$stmt_select && $conexion && !empty($conexion->error) && !isset($_POST['action'])): ?>
                <div class='alert alert-danger'>Error al preparar la consulta de búsqueda: <?= htmlspecialchars($conexion->error) ?></div>
            <?php elseif (!empty($activos_encontrados)) :
                $asset_forms_data = [];
                foreach ($activos_encontrados as $activo) {
                    $asset_forms_data[$activo['cedula']]['info'] = ['nombre' => $activo['nombre'], 'cargo' => $activo['cargo'], 'cedula' => $activo['cedula']];
                    $asset_forms_data[$activo['cedula']]['activos'][] = $activo;
                }

                foreach ($asset_forms_data as $cedula_grupo => $data_grupo):
                    $btn_acta_text = 'Generar Acta de Entrega';
                    $acta_url_params = "cedula=" . urlencode($cedula_grupo) . "&tipo_acta=entrega";
            ?>
                    <div class="user-asset-group mt-4" id="user-group-<?= htmlspecialchars($cedula_grupo) ?>">
                        <div class="user-info-header">
                            <div class="info-block">
                                <h4><?= htmlspecialchars($data_grupo['info']['nombre']) ?> <small class="text-muted">(C.C: <?= htmlspecialchars($data_grupo['info']['cedula']) ?>)</small></h4>
                                <p><strong>Cargo:</strong> <?= htmlspecialchars($data_grupo['info']['cargo']) ?></p>
                            </div>
                            <div class="actions-block">
                                <?php if (tiene_permiso_para('trasladar_activo')): ?>
                                <button type="button" class="btn btn-sm btn-outline-primary btn-transfer-modal" data-bs-toggle="modal" data-bs-target="#trasladoModal" data-cedula-origen="<?= htmlspecialchars($cedula_grupo) ?>">
                                    <i class="bi bi-truck"></i> Trasladar Seleccionados
                                </button>
                                <?php endif; ?>
                                <?php if (tiene_permiso_para('generar_informes')): // O un permiso más específico para actas ?>
                                <a href="generar_acta.php?<?= $acta_url_params ?>" class="btn btn-sm btn-outline-secondary btn-generate-acta" target="_blank" title="<?= $btn_acta_text ?>">
                                    <i class="bi bi-file-earmark-pdf"></i> <?= $btn_acta_text ?>
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php foreach ($data_grupo['activos'] as $index => $activo_individual):
                            $form_id_individual_activo = $activo_individual['id'];
                            $id_activo_focus_get = $_GET['id_activo_focus'] ?? null;
                            $clase_enfocada = ($id_activo_focus_get && $id_activo_focus_get == $activo_individual['id']) ? 'activo-enfocado' : '';
                        ?>
                            <div class="asset-form-outer-container <?= $clase_enfocada ?>" id="activo_container_<?= htmlspecialchars($form_id_individual_activo) ?>">
                                <div class="asset-form-header">
                                    <?php if (tiene_permiso_para('trasladar_activo')): ?>
                                    <input type="checkbox" data-asset-id="<?= $activo_individual['id'] ?>" class="form-check-input checkbox-transfer" title="Seleccionar para trasladar" data-tipo-activo="<?= htmlspecialchars($activo_individual['tipo_activo']) ?>" data-serie-activo="<?= htmlspecialchars($activo_individual['serie']) ?>">
                                    <?php endif; ?>
                                    <h6> Activo #<?= ($index + 1) ?>: <?= htmlspecialchars($activo_individual['tipo_activo']) ?> - Serie: <?= htmlspecialchars($activo_individual['serie']) ?></h6>
                                </div>
                                <form method="post" action="editar.php" id="form-activo-<?= htmlspecialchars($form_id_individual_activo) ?>">
                                    <input type="hidden" name="id" value="<?= $activo_individual['id'] ?>">
                                    <input type="hidden" name="cedula_original_busqueda" value="<?= htmlspecialchars($cedula_buscada) ?>">
                                    <input type="hidden" name="regional_original_busqueda" value="<?= htmlspecialchars($regional_buscada) ?>">
                                    <div class="row">
                                        <?php
                                        input_editable('nombre_usuario_display', 'Usuario Actual', $activo_individual['nombre'], $form_id_individual_activo, 'text', true);
                                        input_editable('cargo_usuario_display', 'Cargo Actual', $activo_individual['cargo'], $form_id_individual_activo, 'text', true);
                                        select_editable('regional_activo_form', 'Regional Activo', $regionales, $activo_individual['regional'], $form_id_individual_activo, true, true);
                                        select_editable('tipo_activo', 'Tipo Activo', $opciones_tipo_activo, $activo_individual['tipo_activo'], $form_id_individual_activo, true, true);
                                        input_editable('marca', 'Marca', $activo_individual['marca'], $form_id_individual_activo, 'text', true, true);
                                        input_editable('serie', 'Serie', $activo_individual['serie'], $form_id_individual_activo, 'text', true, true);
                                        select_editable('estado', 'Estado Activo', $opciones_estado_general, $activo_individual['estado'], $form_id_individual_activo, true, true);
                                        input_editable('valor_aproximado', 'Valor Aprox.', $activo_individual['valor_aproximado'], $form_id_individual_activo, 'number', true, true);
                                        if ($activo_individual['tipo_activo'] == 'Computador' || !empty($activo_individual['procesador'])) {
                                            echo "<div class='col-12'><hr class='my-2'><h6 class='mt-2 text-muted small'>Detalles de Computador</h6></div>";
                                            input_editable('procesador', 'Procesador', $activo_individual['procesador'], $form_id_individual_activo, 'text', true, false, 'col-md-3');
                                            input_editable('ram', 'RAM', $activo_individual['ram'], $form_id_individual_activo, 'text', true, false, 'col-md-3');
                                            input_editable('disco_duro', 'Disco Duro', $activo_individual['disco_duro'], $form_id_individual_activo, 'text', true, false, 'col-md-3');
                                            select_editable('tipo_equipo', 'Tipo Equipo (PC)', $opciones_tipo_equipo, $activo_individual['tipo_equipo'], $form_id_individual_activo, true, false, 'col-md-3');
                                            select_editable('red', 'Red (PC)', $opciones_red, $activo_individual['red'], $form_id_individual_activo, true, false, 'col-md-3');
                                            select_editable('sistema_operativo', 'SO', $opciones_so, $activo_individual['sistema_operativo'], $form_id_individual_activo, true, false, 'col-md-3');
                                            select_editable('offimatica', 'Offimática', $opciones_offimatica, $activo_individual['offimatica'], $form_id_individual_activo, true, false, 'col-md-3');
                                            select_editable('antivirus', 'Antivirus', $opciones_antivirus, $activo_individual['antivirus'], $form_id_individual_activo, true, false, 'col-md-3');
                                        }
                                        textarea_editable('detalles', 'Detalles Adicionales', $activo_individual['detalles'], $form_id_individual_activo, true, 'col-md-12');
                                        ?>
                                    </div>
                                    <div class="action-buttons">
                                        <?php if (tiene_permiso_para('editar_activo_detalles')): ?>
                                            <button type="button" class="btn btn-sm btn-outline-info" onclick="habilitarCamposActivo('<?= htmlspecialchars($form_id_individual_activo) ?>')"> <i class="bi bi-pencil-square"></i> Habilitar Edición</button>
                                            <button type="submit" name="editar_activo_submit" class="btn btn-sm btn-success" disabled> <i class="bi bi-check-circle-fill"></i> Guardar Cambios</button>
                                        <?php endif; ?>
                                        <?php if (tiene_permiso_para('dar_baja_activo')): ?>
                                            <button type="button" class="btn btn-sm btn-warning btn-dar-baja" data-bs-toggle="modal" data-bs-target="#modalDarBaja" data-id-activo="<?= $activo_individual['id'] ?>" data-serie-activo="<?= htmlspecialchars($activo_individual['serie']) ?>">
                                                <i class="bi bi-arrow-down-circle"></i> Dar de Baja
                                            </button>
                                        <?php endif; ?>
                                        <?php if (tiene_permiso_para('eliminar_activo_fisico')): ?>
                                            <button type="submit" name="eliminar_activo_submit" class="btn btn-sm btn-danger" onclick="return confirm('ADVERTENCIA:\n¿Está seguro que desea ELIMINAR PERMANENTEMENTE este activo (Serie: <?= htmlspecialchars($activo_individual['serie']) ?>)?\n\nEsta acción NO SE PUEDE DESHACER y borrará el activo de la base de datos.\n\nPara inactivar un activo conservando su historial, utilice la opción DAR DE BAJA.')"> <i class="bi bi-trash3-fill"></i> Eliminar Físico</button>
                                        <?php endif; ?>
                                    </div>
                                </form>

                                <div class="mt-3 text-center">
                                    <a href="historial.php?id_activo=<?= htmlspecialchars($activo_individual['id']) ?>" class="btn btn-sm btn-outline-secondary" target="_blank">
                                        <i class="bi bi-list-task"></i> Ver Historial Detallado
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
            <?php
                endforeach;
                ?>
            <?php elseif ($criterio_buscada_activo && empty($activos_encontrados)) : ?>
                <div class="alert alert-info mt-3">No se encontraron activos (operativos) con los criterios de búsqueda especificados.</div>
            <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if (tiene_permiso_para('trasladar_activo')): ?>
    <div class="modal fade" id="trasladoModal" tabindex="-1" aria-labelledby="trasladoModalLabel" aria-hidden="true"> <div class="modal-dialog modal-lg"> <div class="modal-content"> <div class="modal-header"> <h5 class="modal-title" id="trasladoModalLabel">Trasladar Activos Seleccionados</h5> <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button> </div> <div class="modal-body"> <div class="row"> <div class="col-md-6 border-end"> <p>Del usuario: <strong id="nombreUsuarioOrigenTrasladoModal"></strong><br> C.C: <strong id="cedulaUsuarioOrigenTrasladoModal"></strong> </p> <h6>Activos Seleccionados para Traslado:</h6> <ul id="listaActivosTrasladar" class="mb-3"></ul> </div> <div class="col-md-6"> <h6>Ingrese los datos del <strong>NUEVO</strong> responsable y su regional:</h6> <div class="mb-2"> <label for="nueva_cedula_traslado" class="form-label form-label-sm">Nueva Cédula</label> <input type="text" class="form-control form-control-sm" id="nueva_cedula_traslado" required> </div> <div class="mb-2"> <label for="nuevo_nombre_traslado" class="form-label form-label-sm">Nuevo Nombre Completo</label> <input type="text" class="form-control form-control-sm" id="nuevo_nombre_traslado" required> </div> <div class="mb-2"> <label for="nuevo_cargo_traslado" class="form-label form-label-sm">Nuevo Cargo</label> <input type="text" class="form-control form-control-sm" id="nuevo_cargo_traslado" required> </div> <div class="mb-2"> <label for="nueva_regional_traslado" class="form-label form-label-sm">Nueva Regional</label> <select class="form-select form-select-sm" id="nueva_regional_traslado" required> <option value="">Seleccione Regional...</option> <?php foreach ($regionales as $r): ?> <option value="<?= htmlspecialchars($r) ?>"><?= htmlspecialchars($r) ?></option> <?php endforeach; ?> </select> </div> </div> </div> </div> <div class="modal-footer"> <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button> <button type="button" class="btn btn-sm btn-primary" id="confirmarTrasladoBtn">Confirmar Traslado</button> </div> </div> </div> </div>
    <?php endif; ?>

    <?php if (tiene_permiso_para('dar_baja_activo')): ?>
    <div class="modal fade" id="modalDarBaja" tabindex="-1" aria-labelledby="modalDarBajaLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post" action="editar.php" id="formDarBaja">
                    <div class="modal-header">
                        <h5 class="modal-title" id="modalDarBajaLabel">Confirmar Baja de Activo</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>¿Está seguro que desea dar de baja el activo con serie: <strong id="serieActivoBajaModal"></strong>?</p>
                        <input type="hidden" name="id_activo_baja" id="idActivoBajaModal">
                        <input type="hidden" name="cedula_original_busqueda_baja" id="cedulaOriginalBusquedaBajaModal">
                        <input type="hidden" name="regional_original_busqueda_baja" id="regionalOriginalBusquedaBajaModal">
                        <div class="mb-3">
                            <label for="motivo_baja" class="form-label">Motivo de la Baja <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" id="motivo_baja" name="motivo_baja" required>
                                <option value="">Seleccione un motivo...</option>
                                <option value="Obsolescencia">Obsolescencia</option>
                                <option value="Daño irreparable">Daño irreparable</option>
                                <option value="Pérdida">Pérdida</option>
                                <option value="Robo">Robo</option>
                                <option value="Venta">Venta</option>
                                <option value="Donación">Donación</option>
                                <option value="Fin de vida útil">Fin de vida útil</option>
                                <option value="Otro">Otro (especificar en observaciones)</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="observaciones_baja" class="form-label">Observaciones Adicionales</label>
                            <textarea class="form-control form-control-sm" id="observaciones_baja" name="observaciones_baja" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="submit_dar_baja" class="btn btn-sm btn-warning">Confirmar Baja</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
        function habilitarCamposActivo(formSuffix) {
            const formId = 'form-activo-' + formSuffix; const form = document.getElementById(formId);
            if (!form) { console.error('Formulario NO encontrado:', formId); return; }
            const elementsToEnable = form.querySelectorAll('input:not([name="nombre_usuario_display"]):not([name="cargo_usuario_display"]), select, textarea');
            elementsToEnable.forEach(el => { el.removeAttribute('readonly'); el.removeAttribute('disabled'); });
            const updateButton = form.querySelector('button[name="editar_activo_submit"]');
            if (updateButton) { updateButton.removeAttribute('disabled');}
            const firstEditableField = form.querySelector('select[name="tipo_activo"]');
            if (firstEditableField && !firstEditableField.hasAttribute('disabled')) { firstEditableField.focus(); }
        }

        document.addEventListener('DOMContentLoaded', function () {
            <?php if (tiene_permiso_para('trasladar_activo')): ?>
            var trasladoModalEl = document.getElementById('trasladoModal');
            if (trasladoModalEl) {
                var bsTrasladoModal = new bootstrap.Modal(trasladoModalEl); var cedulaOrigenActualParaModal = '';
                trasladoModalEl.addEventListener('show.bs.modal', function (event) {
                    var button = event.relatedTarget; cedulaOrigenActualParaModal = button.getAttribute('data-cedula-origen');
                    var userGroupDiv = document.getElementById(`user-group-${cedulaOrigenActualParaModal}`);
                    var listaActivosUl = document.getElementById('listaActivosTrasladar'); listaActivosUl.innerHTML = '';
                    if(userGroupDiv){
                        var nombreUsuarioPElement = userGroupDiv.querySelector('.user-info-header h4');
                        var cedulaUsuarioPElement = userGroupDiv.querySelector('.user-info-header h4 small.text-muted');
                        var nombreUsuarioOrigen = nombreUsuarioPElement ? nombreUsuarioPElement.textContent.split(' (C.C:')[0].trim() : 'N/A';
                        var cedulaTextoCompleto = cedulaUsuarioPElement ? cedulaUsuarioPElement.textContent : '';
                        var cedulaMatch = cedulaTextoCompleto.match(/\(C\.C: (.*?)\)/);
                        var cedulaOrigenDisplay = cedulaMatch && cedulaMatch[1] ? cedulaMatch[1] : cedulaOrigenActualParaModal;
                        document.getElementById('cedulaUsuarioOrigenTrasladoModal').textContent = cedulaOrigenDisplay;
                        document.getElementById('nombreUsuarioOrigenTrasladoModal').textContent = nombreUsuarioOrigen;
                        const checkboxesSeleccionados = userGroupDiv.querySelectorAll('.checkbox-transfer:checked');
                        if (checkboxesSeleccionados.length > 0) {
                            checkboxesSeleccionados.forEach(cb => {
                                let tipo = cb.getAttribute('data-tipo-activo'); let serie = cb.getAttribute('data-serie-activo');
                                let idActivo = cb.getAttribute('data-asset-id') || cb.value;
                                let listItem = document.createElement('li'); listItem.textContent = `${tipo||'Desconocido'} (S/N: ${serie||'N/A'}, ID: ${idActivo})`;
                                listaActivosUl.appendChild(listItem);
                            });
                        } else {
                            let listItem = document.createElement('li'); listItem.textContent = "Ningún activo seleccionado."; listItem.classList.add('text-danger');
                            listaActivosUl.appendChild(listItem);
                        }
                    }
                    document.getElementById('nueva_cedula_traslado').value = ''; document.getElementById('nuevo_nombre_traslado').value = '';
                    document.getElementById('nuevo_cargo_traslado').value = ''; document.getElementById('nueva_regional_traslado').value = '';
                    const inputNuevaCedula = document.getElementById('nueva_cedula_traslado');
                    const inputNuevoNombre = document.getElementById('nuevo_nombre_traslado');
                    const inputNuevoCargo = document.getElementById('nuevo_cargo_traslado'); let timeoutId = null;
                    inputNuevaCedula.onkeyup = function () {
                        clearTimeout(timeoutId); const cedulaIngresada = this.value.trim();
                        if (cedulaIngresada.length >= 5) {
                            timeoutId = setTimeout(function() {
                                fetch(`buscar_datos_usuario.php?cedula=${encodeURIComponent(cedulaIngresada)}`)
                                .then(response => response.json()).then(data => {
                                    if (data.encontrado) { inputNuevoNombre.value = data.nombre; inputNuevoCargo.value = data.cargo; }
                                }).catch(error => console.error('Error:', error));
                            }, 700);
                        }
                    };
                });
                document.getElementById('confirmarTrasladoBtn').addEventListener('click', function() {
                    let fd = new FormData(); fd.append('cedula_original_busqueda',document.getElementById('cedula_buscar').value);
                    fd.append('regional_original_busqueda',document.getElementById('regional_buscar').value);
                    fd.append('cedula_usuario_origen_hidden',cedulaOrigenActualParaModal); fd.append('action','confirmar_traslado_masivo');
                    const nCed = document.getElementById('nueva_cedula_traslado').value.trim();
                    const nNom = document.getElementById('nuevo_nombre_traslado').value.trim();
                    const nCar = document.getElementById('nuevo_cargo_traslado').value.trim();
                    const nReg = document.getElementById('nueva_regional_traslado').value;
                    if(!nCed||!nNom||!nCar||!nReg){alert('Datos del nuevo responsable incompletos.'); return;}
                    const chkSel = document.getElementById('user-group-'+cedulaOrigenActualParaModal).querySelectorAll('.checkbox-transfer:checked');
                    if(chkSel.length===0){alert('No ha seleccionado activos.');bsTrasladoModal.hide();return;}
                    let idsSel = []; chkSel.forEach(cb=>idsSel.push(cb.getAttribute('data-asset-id')||cb.value));
                    fd.append('ids_activos_seleccionados_traslado',idsSel.join(','));
                    fd.append('nueva_cedula_traslado',nCed); fd.append('nuevo_nombre_traslado',nNom);
                    fd.append('nuevo_cargo_traslado',nCar); fd.append('nueva_regional_traslado',nReg);
                    bsTrasladoModal.hide();
                    fetch('editar.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
                        let msgDiv = document.getElementById('mensaje-global-container');
                        if(!msgDiv){ msgDiv=document.createElement('div'); msgDiv.id='mensaje-global-container'; document.querySelector('.card.search-card').insertAdjacentElement('afterend',msgDiv);}
                        msgDiv.innerHTML = `<div class='alert ${d.success?'alert-success':'alert-danger'} alert-dismissible fade show mt-3'>${d.message}<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>`;
                        if(d.success){
                            if(d.data_acta && confirm('Traslado exitoso. ¿Generar Acta de Traslado?')){
                                let p = new URLSearchParams(); p.append('tipo_acta','traslado');
                                if(d.data_acta.cedula_destino)p.append('cedula_destino',d.data_acta.cedula_destino);
                                if(d.data_acta.nombre_destino)p.append('nombre_destino',d.data_acta.nombre_destino);
                                if(d.data_acta.cargo_destino)p.append('cargo_destino',d.data_acta.cargo_destino);
                                if(d.data_acta.regional_destino)p.append('regional_destino',d.data_acta.regional_destino);
                                if(d.data_acta.ids_activos)p.append('ids_activos',d.data_acta.ids_activos);
                                if(d.data_acta.usuario_entrega_operacion)p.append('usuario_entrega_operacion',d.data_acta.usuario_entrega_operacion);
                                if(d.data_acta.datos_origen){
                                    if(d.data_acta.datos_origen.cedula)p.append('cedula_origen',d.data_acta.datos_origen.cedula);
                                    if(d.data_acta.datos_origen.nombre)p.append('nombre_origen',d.data_acta.datos_origen.nombre);
                                    if(d.data_acta.datos_origen.cargo)p.append('cargo_origen',d.data_acta.datos_origen.cargo);
                                }
                                if(p.has('cedula_destino')&&p.has('ids_activos')&&p.has('nombre_destino')){window.open('generar_acta.php?'+p.toString(),'_blank');}
                                else{alert('Faltan datos para generar acta.');}
                            }
                            setTimeout(()=>{ const u=new URL(window.location.href);window.location.href=`editar.php?cedula=${encodeURIComponent(u.searchParams.get('cedula')||'')}&regional=${encodeURIComponent(u.searchParams.get('regional')||'')}`;},1500);
                        }
                    }).catch(e=>{console.error('Error:',e);alert('Error procesando traslado.');});
                });
            }
            <?php endif; // Cierre de if tiene_permiso_para('trasladar_activo') para el modal de traslado ?>

            <?php if (tiene_permiso_para('dar_baja_activo')): ?>
            var modalDarBajaEl = document.getElementById('modalDarBaja');
            if (modalDarBajaEl) {
                modalDarBajaEl.addEventListener('show.bs.modal', function (event) {
                    var button = event.relatedTarget;
                    var idActivo = button.getAttribute('data-id-activo');
                    var serieActivo = button.getAttribute('data-serie-activo');
                    var modalTitle = modalDarBajaEl.querySelector('#modalDarBajaLabel');
                    var serieActivoSpanModal = modalDarBajaEl.querySelector('#serieActivoBajaModal');
                    var inputIdActivoBajaModal = modalDarBajaEl.querySelector('#idActivoBajaModal');
                    var inputCedulaBusquedaBaja = modalDarBajaEl.querySelector('#cedulaOriginalBusquedaBajaModal');
                    var inputRegionalBusquedaBaja = modalDarBajaEl.querySelector('#regionalOriginalBusquedaBajaModal');

                    modalTitle.textContent = 'Confirmar Baja de Activo S/N: ' + serieActivo;
                    serieActivoSpanModal.textContent = serieActivo;
                    inputIdActivoBajaModal.value = idActivo;
                    inputCedulaBusquedaBaja.value = document.getElementById('cedula_buscar').value;
                    inputRegionalBusquedaBaja.value = document.getElementById('regional_buscar').value;
                    document.getElementById('motivo_baja').value = '';
                    document.getElementById('observaciones_baja').value = '';
                });
            }
            <?php endif; // Cierre de if tiene_permiso_para('dar_baja_activo') para el modal de baja ?>

            // Scroll al activo enfocado si se pasa id_activo_focus en la URL
            const urlParams = new URLSearchParams(window.location.search);
            const idActivoFocus = urlParams.get('id_activo_focus');
            if (idActivoFocus) {
                const elementoActivo = document.getElementById('activo_container_' + idActivoFocus);
                if (elementoActivo) {
                    elementoActivo.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    // Opcional: remover la clase después de un tiempo para que no pulse indefinidamente
                    // setTimeout(() => { elementoActivo.classList.remove('activo-enfocado'); }, 5000);
                }
            }
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>