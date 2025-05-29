<?php
// SUGERENCIA PARA DEPURACIÓN: Añadir temporalmente para facilitar la visualización de logs
// ini_set('log_errors', 1);
// ini_set('error_log', dirname(__FILE__) . '/editar_php_errors.log'); // Creará un log en el mismo directorio
// error_reporting(E_ALL);
// ini_set('display_errors', 0); // No mostrar errores detallados al usuario en producción

// session_start(); // auth_check.php ya lo incluye y gestiona
require_once 'backend/auth_check.php';
restringir_acceso_pagina(['admin', 'tecnico']);

require_once 'backend/db.php';
require_once 'backend/historial_helper.php';

if (!defined('HISTORIAL_TIPO_ACTUALIZACION')) define('HISTORIAL_TIPO_ACTUALIZACION', 'ACTUALIZACIÓN');
if (!defined('HISTORIAL_TIPO_TRASLADO')) define('HISTORIAL_TIPO_TRASLADO', 'TRASLADO');
if (!defined('HISTORIAL_TIPO_BAJA')) define('HISTORIAL_TIPO_BAJA', 'BAJA');
if (!defined('HISTORIAL_TIPO_ELIMINACION_FISICA')) define('HISTORIAL_TIPO_ELIMINACION_FISICA', 'ELIMINACIÓN FÍSICA');

$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Usuario';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'Desconocido';

if (isset($conn) && !isset($conexion)) {
    $conexion = $conn;
}
if (!isset($conexion) || !$conexion || (method_exists($conexion, 'connect_error') && $conexion->connect_error)) {
    error_log("Fallo CRÍTICO de conexión a BD en editar.php: " . ($conexion->connect_error ?? 'Desconocido'));
    die("Error de conexión a la base de datos. No se puede continuar.");
}
$conexion->set_charset("utf8mb4");

$mensaje = "";
$cedula_buscada = '';
$regional_buscada_usuario = '';
$empresa_buscada_usuario = '';
$activos_encontrados = [];
$incluir_dados_baja = false; 

$regionales_usuarios = ['Popayan', 'Bordo', 'Santander', 'Valle', 'Pasto', 'Tuquerres', 'Huila', 'Nacional'];
$empresas_usuarios_disponibles = ['Arpesod', 'Finansueños'];

$regionales_opciones_traslado_usuario = $regionales_usuarios; 
$empresas_opciones_traslado_usuario = $empresas_usuarios_disponibles;

$criterio_buscada_activo = false;
$usuario_actual_sistema_para_historial = $_SESSION['usuario_login'] ?? 'Sistema';

// Determinar los parámetros de búsqueda y el estado del checkbox 'incluir_bajas'
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'confirmar_traslado_masivo') {
        $cedula_buscada = $_POST['cedula_original_busqueda'] ?? '';
        $regional_buscada_usuario = $_POST['regional_original_busqueda'] ?? '';
        $empresa_buscada_usuario = $_POST['empresa_original_busqueda'] ?? '';
        $incluir_dados_baja = isset($_POST['incluir_bajas_original_busqueda']) && $_POST['incluir_bajas_original_busqueda'] === '1';
    } else {
        $cedula_buscada = $_POST['cedula_original_busqueda'] ?? '';
        $regional_buscada_usuario = $_POST['regional_original_busqueda'] ?? '';
        $empresa_buscada_usuario = $_POST['empresa_original_busqueda'] ?? '';
        $incluir_dados_baja = isset($_POST['incluir_bajas_original_busqueda']) && $_POST['incluir_bajas_original_busqueda'] === '1';
    }
    
    if (!empty($cedula_buscada) || !empty($regional_buscada_usuario) || !empty($empresa_buscada_usuario) || $incluir_dados_baja || isset($_POST['buscar_todos_post_flag'])) { 
        $criterio_buscada_activo = true;
    }
} else { // GET request
    $cedula_buscada = $_GET['cedula'] ?? '';
    $regional_buscada_usuario = $_GET['regional'] ?? '';
    $empresa_buscada_usuario = $_GET['empresa'] ?? '';
    $incluir_dados_baja = isset($_GET['incluir_bajas']) && $_GET['incluir_bajas'] === '1';
    if (!empty($cedula_buscada) || !empty($regional_buscada_usuario) || !empty($empresa_buscada_usuario) || (isset($_GET['buscar_todos']) && $_GET['buscar_todos'] === '1') || isset($_GET['incluir_bajas']) ) {
        $criterio_buscada_activo = true;
    }
}

if (isset($_SESSION['pagina_mensaje'])) {
    $mensaje = $_SESSION['pagina_mensaje'];
    unset($_SESSION['pagina_mensaje']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion_realizada = false; 

    if (isset($_POST['editar_activo_submit'])) {
        $accion_realizada = true;
        if (!tiene_permiso_para('editar_activo_detalles')) {
            $mensaje = "<div class='alert alert-danger'>Acción no permitida para su rol.</div>";
        } elseif (empty($_POST['id']) || empty($_POST['tipo_activo_nombre']) || empty($_POST['marca']) || empty($_POST['serie']) || empty($_POST['estado']) || !isset($_POST['valor_aproximado']) || empty($_POST['fecha_compra'])) {
            $mensaje = "<div class='alert alert-danger'>Error: Faltan campos obligatorios del activo para actualizar (Tipo, Marca, Serie, Estado, Valor, Fecha Compra).</div>";
        } else {
            $id_activo_a_editar = (int)$_POST['id'];
            $stmt_datos_previos = $conexion->prepare("SELECT a.*, ta.nombre_tipo_activo 
                                                     FROM activos_tecnologicos a
                                                     LEFT JOIN tipos_activo ta ON a.id_tipo_activo = ta.id_tipo_activo
                                                     WHERE a.id = ?");
            $datos_anteriores_del_activo = null;
            if ($stmt_datos_previos) {
                $stmt_datos_previos->bind_param('i', $id_activo_a_editar);
                $stmt_datos_previos->execute();
                $result_datos_previos = $stmt_datos_previos->get_result();
                $datos_anteriores_del_activo = $result_datos_previos->fetch_assoc();
                if($datos_anteriores_del_activo && !isset($datos_anteriores_del_activo['tipo_activo']) && isset($datos_anteriores_del_activo['nombre_tipo_activo'])) {
                    $datos_anteriores_del_activo['tipo_activo'] = $datos_anteriores_del_activo['nombre_tipo_activo'];
                }
                $stmt_datos_previos->close();
            }

            $nombre_tipo_activo_form = $_POST['tipo_activo_nombre'];
            $id_tipo_activo_para_actualizar = null;
            $stmt_get_tipo_id = $conexion->prepare("SELECT id_tipo_activo FROM tipos_activo WHERE nombre_tipo_activo = ?");
            if($stmt_get_tipo_id){
                $stmt_get_tipo_id->bind_param('s', $nombre_tipo_activo_form);
                $stmt_get_tipo_id->execute();
                $res_tipo_id = $stmt_get_tipo_id->get_result();
                if($row_tipo_id = $res_tipo_id->fetch_assoc()){
                    $id_tipo_activo_para_actualizar = $row_tipo_id['id_tipo_activo'];
                }
                $stmt_get_tipo_id->close();
            }

            if($id_tipo_activo_para_actualizar === null && !empty($nombre_tipo_activo_form)){
                $mensaje = "<div class='alert alert-danger'>Error: Tipo de activo '".htmlspecialchars($nombre_tipo_activo_form)."' no válido.</div>";
            } else {
                $marca_actualizar = $_POST['marca'] ?? '';
                $serie_actualizar = $_POST['serie'] ?? '';
                $estado_actualizar = $_POST['estado'] ?? '';
                $valor_aprox_actualizar = $_POST['valor_aproximado'] ?? '0.00';
                $detalles_actualizar = $_POST['detalles'] ?? '';
                $fecha_compra_actualizar = $_POST['fecha_compra'] ?? null;
                $procesador_actualizar = $_POST['procesador'] ?? null;
                $ram_actualizar = $_POST['ram'] ?? null;
                $disco_duro_actualizar = $_POST['disco_duro'] ?? null;
                $tipo_equipo_actualizar = $_POST['tipo_equipo'] ?? null;
                $red_actualizar = $_POST['red'] ?? null;
                $sistema_operativo_actualizar = $_POST['sistema_operativo'] ?? null;
                $offimatica_actualizar = $_POST['offimatica'] ?? null;
                $antivirus_actualizar = $_POST['antivirus'] ?? null;
                
                $sql_update_activo = "UPDATE activos_tecnologicos SET 
                                        id_tipo_activo=?, marca=?, serie=?, estado=?, valor_aproximado=?, 
                                        detalles=?, procesador=?, ram=?, disco_duro=?, tipo_equipo=?, 
                                        red=?, sistema_operativo=?, offimatica=?, antivirus=?, fecha_compra=?
                                    WHERE id=?";
                
                $stmt = $conexion->prepare($sql_update_activo);
                if (!$stmt) {
                    $mensaje = "<div class='alert alert-danger'>Error al preparar actualización: " . $conexion->error . "</div>";
                } else {
                    $tipos_para_update = 'isssdssssssssssi'; 
                    $params_para_update = [
                        $id_tipo_activo_para_actualizar, $marca_actualizar, $serie_actualizar, $estado_actualizar, $valor_aprox_actualizar,
                        $detalles_actualizar, $procesador_actualizar, $ram_actualizar, $disco_duro_actualizar, $tipo_equipo_actualizar,
                        $red_actualizar, $sistema_operativo_actualizar, $offimatica_actualizar, $antivirus_actualizar, $fecha_compra_actualizar,
                        $id_activo_a_editar
                    ];
                    $stmt->bind_param($tipos_para_update, ...$params_para_update);
                    if ($stmt->execute()) {
                        $mensaje = "<div class='alert alert-success'>Activo ID: " . htmlspecialchars($id_activo_a_editar) . " actualizado.</div>";
                        // ... (Lógica de historial para editar) ...
                    } else {
                        $mensaje = "<div class='alert alert-danger'>Error al actualizar: " . $stmt->error . "</div>";
                    }
                    $stmt->close();
                }
            }
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'confirmar_traslado_masivo') {
        $accion_realizada = true; 
        header('Content-Type: application/json'); 
        $response = ['success' => false, 'message' => 'Error desconocido durante el traslado.'];
        
        error_log("--------------------------------------------------------------------");
        error_log("[TRASLADO DEBUG] Iniciando 'confirmar_traslado_masivo' - " . date("Y-m-d H:i:s"));

        if (!tiene_permiso_para('trasladar_activo')) {
            $response['message'] = 'Acción no permitida para su rol.';
            error_log("[TRASLADO ERROR] Permiso denegado para 'trasladar_activo'. Usuario: " . ($_SESSION['usuario_login'] ?? 'Desconocido'));
            echo json_encode($response);
            exit;
        }

        $ids_activos_str = $_POST['ids_activos_seleccionados_traslado'] ?? '';
        $nueva_cedula_resp_traslado = trim($_POST['nueva_cedula_traslado'] ?? '');
        
        $nueva_regional_usuario_destino = trim($_POST['nueva_regional_traslado'] ?? '');
        $nueva_empresa_usuario_destino = trim($_POST['nueva_empresa_traslado'] ?? '');

        error_log("[TRASLADO DEBUG] POST Data: Cedula Nuevo Resp='{$nueva_cedula_resp_traslado}', Nueva Regional Usuario='{$nueva_regional_usuario_destino}', Nueva Empresa Usuario='{$nueva_empresa_usuario_destino}', IDs Activos Str='{$ids_activos_str}'");

        $id_nuevo_responsable_traslado = null;
        $nuevo_nombre_resp_traslado = ''; 

        if (empty($nueva_cedula_resp_traslado)) {
            $response['message'] = 'Error: La nueva cédula del responsable es obligatoria.';
            error_log("[TRASLADO ERROR] Cédula del nuevo responsable vacía.");
            echo json_encode($response);
            exit;
        }
        if (empty($nueva_regional_usuario_destino) || empty($nueva_empresa_usuario_destino)) {
            $response['message'] = 'Error: Debe seleccionar la nueva regional y empresa para el usuario de destino.';
            error_log("[TRASLADO ERROR] Nueva regional ('{$nueva_regional_usuario_destino}') o empresa ('{$nueva_empresa_usuario_destino}') para el usuario destino está vacía.");
            echo json_encode($response);
            exit;
        }
        
        $stmt_get_new_user = $conexion->prepare("SELECT id, nombre_completo FROM usuarios WHERE usuario = ?");
        if ($stmt_get_new_user) {
            $stmt_get_new_user->bind_param("s", $nueva_cedula_resp_traslado);
            $stmt_get_new_user->execute();
            $res_new_user = $stmt_get_new_user->get_result();
            if ($row_new_user = $res_new_user->fetch_assoc()) {
                $id_nuevo_responsable_traslado = $row_new_user['id'];
                $nuevo_nombre_resp_traslado = $row_new_user['nombre_completo'];
            }
            $stmt_get_new_user->close();
        } else {
            error_log("[TRASLADO ERROR] Fallo al PREPARAR consulta para obtener datos del nuevo usuario (stmt_get_new_user): " . $conexion->error);
        }
        
        error_log("[TRASLADO DEBUG] Búsqueda nuevo usuario: ID={$id_nuevo_responsable_traslado}, Nombre='{$nuevo_nombre_resp_traslado}' para Cédula='{$nueva_cedula_resp_traslado}'");

        if ($id_nuevo_responsable_traslado === null) {
            $response['message'] = 'Error: La nueva cédula del responsable para el traslado no existe en el sistema.';
            error_log("[TRASLADO ERROR] ID del nuevo responsable es NULL después de la búsqueda. Cédula buscada: '{$nueva_cedula_resp_traslado}'");
            echo json_encode($response);
            exit;
        }
        if (empty($ids_activos_str)) {
            $response['message'] = 'Error: No se seleccionaron activos para el traslado.';
            error_log("[TRASLADO ERROR] IDs de activos para trasladar está vacío.");
            echo json_encode($response);
            exit;
        }
        
        $conexion->begin_transaction();
        error_log("[TRASLADO DEBUG] Iniciando transacción BD.");
        try {
            $sql_update_usuario = "UPDATE usuarios SET regional = ?, empresa = ? WHERE id = ?";
            $stmt_update_usuario = $conexion->prepare($sql_update_usuario);

            if (!$stmt_update_usuario) {
                error_log("[TRASLADO ERROR] Fallo al PREPARAR actualización de tabla 'usuarios': " . $conexion->error);
                throw new Exception("Error al preparar la actualización del usuario destino.");
            }

            error_log("[TRASLADO DEBUG] Antes de bind_param para 'usuarios': Regional='{$nueva_regional_usuario_destino}', Empresa='{$nueva_empresa_usuario_destino}', ID Usuario={$id_nuevo_responsable_traslado}");
            $stmt_update_usuario->bind_param("ssi", $nueva_regional_usuario_destino, $nueva_empresa_usuario_destino, $id_nuevo_responsable_traslado);

            if (!$stmt_update_usuario->execute()) {
                error_log("[TRASLADO ERROR] Fallo al EJECUTAR actualización de tabla 'usuarios' para ID {$id_nuevo_responsable_traslado}: " . $stmt_update_usuario->error);
                throw new Exception("Error al actualizar datos del usuario destino.");
            }

            $affected_rows_usuario = $stmt_update_usuario->affected_rows;
            error_log("[TRASLADO INFO] Actualización tabla 'usuarios': ID Usuario={$id_nuevo_responsable_traslado}, Regional='{$nueva_regional_usuario_destino}', Empresa='{$nueva_empresa_usuario_destino}'. Filas afectadas: {$affected_rows_usuario}");
            $stmt_update_usuario->close();

            $ids_array = array_filter(explode(',', $ids_activos_str), 'is_numeric');
            if (empty($ids_array)) {
                error_log("[TRASLADO ERROR] Array de IDs de activos vacío después de filtrar.");
                throw new Exception("No se seleccionaron activos válidos para el traslado (después de filtrar).");
            }
            $placeholders = implode(',', array_fill(0, count($ids_array), '?'));

            $sql_update_activos = "UPDATE activos_tecnologicos SET id_usuario_responsable = ? WHERE id IN ($placeholders)";
            $types_activos = 'i' . str_repeat('i', count($ids_array));
            $params_traslado_activos = array_merge([$id_nuevo_responsable_traslado], $ids_array);
            
            $stmt_update_activos = $conexion->prepare($sql_update_activos);
            if (!$stmt_update_activos) {
                error_log("[TRASLADO ERROR] Fallo al PREPARAR actualización de 'activos_tecnologicos': " . $conexion->error);
                throw new Exception("Error al preparar la actualización de traslado de activos.");
            }

            error_log("[TRASLADO DEBUG] Antes de bind_param para 'activos_tecnologicos': Nuevo Responsable ID={$id_nuevo_responsable_traslado}, IDs Activos=" . implode(",", $ids_array));
            $stmt_update_activos->bind_param($types_activos, ...$params_traslado_activos);

            if (!$stmt_update_activos->execute()) {
                error_log("[TRASLADO ERROR] Fallo al EJECUTAR actualización de 'activos_tecnologicos'. Nuevo Responsable ID={$id_nuevo_responsable_traslado}, Error: " . $stmt_update_activos->error);
                throw new Exception("Error al ejecutar el traslado de activos.");
            }
            $affected_rows_activos = $stmt_update_activos->affected_rows;
            error_log("[TRASLADO INFO] Actualización tabla 'activos_tecnologicos': Nuevo Responsable ID={$id_nuevo_responsable_traslado}. IDs Activos=" . implode(",", $ids_array) . ". Filas afectadas: {$affected_rows_activos}");
            
            foreach ($ids_array as $id_activo) {
                $desc_historial = "Activo trasladado al responsable: " . htmlspecialchars($nuevo_nombre_resp_traslado) . 
                                  " (C.C: " . htmlspecialchars($nueva_cedula_resp_traslado) . ").";
                $desc_historial .= " Regional Usuario Destino: ".htmlspecialchars($nueva_regional_usuario_destino).
                                   ", Empresa Usuario Destino: ".htmlspecialchars($nueva_empresa_usuario_destino);
                
                registrar_evento_historial($conexion, $id_activo, HISTORIAL_TIPO_TRASLADO, $desc_historial, 
                    $usuario_actual_sistema_para_historial, null, 
                    [
                        'destino_id_usuario' => $id_nuevo_responsable_traslado, 
                        'destino_cedula' => $nueva_cedula_resp_traslado, 
                        'destino_nombre' => $nuevo_nombre_resp_traslado, 
                        'nueva_regional_usuario_destino' => $nueva_regional_usuario_destino,
                        'nueva_empresa_usuario_destino' => $nueva_empresa_usuario_destino
                    ]
                );
            }
            
            $conexion->commit();
            error_log("[TRASLADO INFO] Transacción completada (commit).");
            
            if(isset($stmt_update_activos)) $stmt_update_activos->close();

            $response['success'] = true;
            $response['message'] = '¡Traslado completado! ' . count($ids_array) . ' activo(s) reasignados y datos del usuario destino actualizados.';

        } catch (Exception $e) {
            $conexion->rollback();
            error_log("[TRASLADO EXCEPTION] " . $e->getMessage() . ". Transacción revertida (rollback).");
            $response['message'] = "Error en el proceso de traslado: " . $e->getMessage();
        }
        echo json_encode($response);
        exit; 

    } elseif (isset($_POST['eliminar_activo_submit'])) {
        $accion_realizada = true;
        // ... (Tu código para eliminar activo) ...
    } elseif (isset($_POST['submit_dar_baja'])) {
        $accion_realizada = true;
        // ... (Tu código para dar de baja) ...
    }

    if ($accion_realizada && !(isset($_POST['action']) && $_POST['action'] === 'confirmar_traslado_masivo')) {
        $redirect_params = [];
        if (!empty($_POST['cedula_original_busqueda'])) $redirect_params['cedula'] = $_POST['cedula_original_busqueda'];
        if (!empty($_POST['regional_original_busqueda'])) $redirect_params['regional'] = $_POST['regional_original_busqueda'];
        if (!empty($_POST['empresa_original_busqueda'])) $redirect_params['empresa'] = $_POST['empresa_original_busqueda'];
        if (isset($_POST['incluir_bajas_original_busqueda']) && $_POST['incluir_bajas_original_busqueda'] === '1') {
            $redirect_params['incluir_bajas'] = '1';
        }
        
        if ($mensaje) {
            $_SESSION['pagina_mensaje'] = $mensaje;
        }
        header('Location: editar.php?' . http_build_query($redirect_params));
        exit;
    }
}

error_log("--------------------------------------------------------------------");
error_log("[BUSQUEDA DEBUG] Antes de if (\$criterio_buscada_activo) - " . date("Y-m-d H:i:s"));
error_log("[BUSQUEDA DEBUG] \$cedula_buscada = '{$cedula_buscada}'");
error_log("[BUSQUEDA DEBUG] \$regional_buscada_usuario = '{$regional_buscada_usuario}'");
error_log("[BUSQUEDA DEBUG] \$empresa_buscada_usuario = '{$empresa_buscada_usuario}'");
error_log("[BUSQUEDA DEBUG] \$incluir_dados_baja = " . ($incluir_dados_baja ? 'true' : 'false'));
error_log("[BUSQUEDA DEBUG] \$criterio_buscada_activo = " . ($criterio_buscada_activo ? 'true' : 'false'));

if ($criterio_buscada_activo) {
    error_log("[BUSQUEDA DEBUG] Dentro de if (\$criterio_buscada_activo) - Iniciando construcción de SQL.");
    $sql_select = "SELECT 
                        a.*, 
                        u.usuario AS cedula_responsable,
                        u.nombre_completo AS nombre_responsable,
                        c.nombre_cargo AS cargo_responsable,
                        u.regional AS regional_responsable, 
                        u.empresa AS empresa_del_responsable, 
                        ta.nombre_tipo_activo 
                   FROM 
                        activos_tecnologicos a
                   LEFT JOIN 
                        usuarios u ON a.id_usuario_responsable = u.id
                   LEFT JOIN 
                        tipos_activo ta ON a.id_tipo_activo = ta.id_tipo_activo
                   LEFT JOIN 
                        cargos c ON u.id_cargo = c.id_cargo
                   WHERE 1=1"; 

    $params_select_query = [];
    $types_select_query = '';

    if (!$incluir_dados_baja) {
        $sql_select .= " AND a.estado != 'Dado de Baja'";
    }

    if (!empty($cedula_buscada)) {
        $sql_select .= " AND u.usuario = ?"; 
        $params_select_query[] = $cedula_buscada;
        $types_select_query .= 's';
    }
    if (!empty($regional_buscada_usuario)) { 
        $sql_select .= " AND u.regional = ?"; 
        $params_select_query[] = $regional_buscada_usuario;
        $types_select_query .= 's';
    }
    if (!empty($empresa_buscada_usuario)) { 
        $sql_select .= " AND u.empresa = ?"; 
        $params_select_query[] = $empresa_buscada_usuario;
        $types_select_query .= 's';
    }
    
    $sql_select .= " ORDER BY u.nombre_completo ASC, u.usuario ASC, a.id ASC"; 

    error_log("[BUSQUEDA DEBUG] SQL construida: " . $sql_select);
    error_log("[BUSQUEDA DEBUG] Tipos para bind_param: '" . $types_select_query . "'");
    error_log("[BUSQUEDA DEBUG] Parámetros para bind_param: " . print_r($params_select_query, true));

    $stmt_select = $conexion->prepare($sql_select);
    if ($stmt_select) {
        error_log("[BUSQUEDA DEBUG] SQL prepare para búsqueda successful.");
        if (!empty($params_select_query)) {
            $stmt_select->bind_param($types_select_query, ...$params_select_query);
            error_log("[BUSQUEDA DEBUG] bind_param para búsqueda successful.");
        }
        if ($stmt_select->execute()) {
            error_log("[BUSQUEDA DEBUG] SQL execute para búsqueda successful.");
            $result_select = $stmt_select->get_result();
            $activos_encontrados = $result_select->fetch_all(MYSQLI_ASSOC);
            error_log("[BUSQUEDA DEBUG] Número de activos encontrados: " . count($activos_encontrados));
        } else {
            if (empty($mensaje)) $mensaje = "<div class='alert alert-danger'>Error búsqueda: " . $stmt_select->error . "</div>";
            error_log("[BUSQUEDA ERROR] Error al ejecutar la consulta de búsqueda: " . $stmt_select->error);
        }
        $stmt_select->close();
    } else {
        if (empty($mensaje)) $mensaje = "<div class='alert alert-danger'>Error preparando búsqueda: " . $conexion->error . "</div>";
        error_log("[BUSQUEDA ERROR] Error al PREPARAR la consulta de búsqueda: " . $conexion->error . " (SQL: " . $sql_select . ")");
    }
} else {
     error_log("[BUSQUEDA DEBUG] \$criterio_buscada_activo es false, no se ejecuta la consulta de búsqueda.");
}

$opciones_tipo_activo_nombres = []; 
$sql_tipos_form = "SELECT nombre_tipo_activo FROM tipos_activo ORDER BY nombre_tipo_activo ASC";
$result_tipos_form = $conexion->query($sql_tipos_form);
if ($result_tipos_form && $result_tipos_form->num_rows > 0) {
    while($row_form = $result_tipos_form->fetch_assoc()) { 
        $opciones_tipo_activo_nombres[] = $row_form['nombre_tipo_activo'];
    }
}

$opciones_tipo_equipo = ['Portátil', 'Mesa', 'Todo en 1']; 
$opciones_red = ['Cableada', 'Inalámbrica', 'Ambas']; 
$opciones_estado_general_editable = ['Bueno', 'Regular', 'Malo', 'En Mantenimiento', 'Nuevo'];
$opciones_so = ['Windows 10', 'Windows 11', 'Linux', 'MacOS'];
$opciones_offimatica = ['Office 365', 'Office Home And Business', 'Office 2021', 'Office 2019', 'Office 2016', 'LibreOffice', 'Google Workspace'];
$opciones_antivirus = ['Microsoft Defender', 'Bitdefender', 'ESET NOD32 Antivirus', 'McAfee Total Protection', 'Kaspersky'];

// ------------ DEFINICIONES DE FUNCIONES HELPER -------------
if (!function_exists('input_editable')) {
    function input_editable($name, $label, $value, $form_id_suffix_func, $type = 'text', $is_readonly = true, $is_required = false, $col_class = 'col-md-4') {
        $readonly_attr = $is_readonly ? 'readonly' : '';
        $required_attr = $is_required ? 'required' : '';
        $step_attr = ($type === 'number') ? "step='0.01' min='0'" : "";
        $input_id = $name . '-' . $form_id_suffix_func;
        echo "<div class='{$col_class} mb-2'><label for='{$input_id}' class='form-label form-label-sm'>$label</label><input type='$type' name='$name' id='{$input_id}' class='form-control form-control-sm' value='" . htmlspecialchars($value ?? '') . "' $readonly_attr $required_attr $step_attr></div>";
    }
}
if (!function_exists('select_editable')) {
    function select_editable($name, $label, $options_array, $selected_value, $form_id_suffix_func, $is_readonly = true, $is_required = false, $col_class = 'col-md-4') {
        $disabled_attr = $is_readonly ? 'disabled' : '';
        $required_attr = $is_required ? 'required' : '';
        $select_id = $name . '-' . $form_id_suffix_func;
        echo "<div class='{$col_class} mb-2'><label for='{$select_id}' class='form-label form-label-sm'>$label</label><select name='$name' id='{$select_id}' class='form-select form-select-sm' $disabled_attr $required_attr>";
        echo "<option value=''>Seleccione...</option>";
        foreach ($options_array as $opt) {
            $sel = ($opt == $selected_value) ? 'selected' : '';
            echo "<option value=\"" . htmlspecialchars($opt) . "\" $sel>" . htmlspecialchars($opt) . "</option>";
        }
        echo "</select></div>";
    }
}
if (!function_exists('textarea_editable')) {
    function textarea_editable($name, $label, $value, $form_id_suffix_func, $is_readonly = true, $col_class = 'col-md-12') {
        $readonly_attr = $is_readonly ? 'readonly' : '';
        $textarea_id = $name . '-' . $form_id_suffix_func;
        echo "<div class='{$col_class} mb-2'><label for='{$textarea_id}' class='form-label form-label-sm'>$label</label><textarea name='$name' id='{$textarea_id}' class='form-control form-control-sm' rows='2' $readonly_attr>" . htmlspecialchars($value ?? '') . "</textarea></div>";
    }
}
// ------------ FIN DE DEFINICIONES DE FUNCIONES HELPER --------------------------
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Administrar Activos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #ffffff !important; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding-top: 80px; }
        .top-bar-custom { position: fixed; top: 0; left: 0; right: 0; z-index: 1030; display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 1.5rem; background-color: #f8f9fa; border-bottom: 1px solid #dee2e6; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .logo-container-top img { width: auto; height: 75px; object-fit: contain; margin-right: 15px; }
        .user-info-top { font-size: 0.9rem; }
        .btn-custom-search { background-color: #191970; color: white; }
        .btn-custom-search:hover { background-color: #11114e; }
        .user-asset-group { background-color: #fff; padding: 20px; margin-bottom: 25px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.07); }
        .user-info-header { border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: flex-start; }
        .user-info-header .info-block { flex-grow: 1; }
        .user-info-header .actions-block { display: flex; flex-direction: column; align-items: flex-end; gap: 5px; min-width: 220px; text-align: right; }
        .user-info-header h4 { color: #191970; font-weight: 600; margin-bottom: 2px; font-size: 1.1rem; }
        .user-info-header p { margin-bottom: 2px; font-size: 0.95em; color: #555; }
        .asset-form-outer-container { border: 1px solid #e0e0e0; border-radius: 6px; padding: 15px; margin-top: 15px; background-color: #fdfdfd; }
        .asset-form-header { display: flex; align-items: center; margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 8px; }
        .asset-form-header .checkbox-transfer { margin-right: 10px; transform: scale(1.2); }
        .asset-form-header h6 { font-weight: 600; color: #007bff; margin-bottom: 0; flex-grow: 1; }
        .form-label-sm { font-size: 0.85em; margin-bottom: 0.2rem !important; color: #454545; }
        .form-control-sm, .form-select-sm { font-size: 0.85em; padding: 0.3rem 0.6rem; }
        .action-buttons { margin-top: 15px; display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 10px; }
        .card.search-card { box-shadow: 0 2px 8px rgba(0,0,0,0.06); border: none; }
        #listaActivosTrasladar { list-style-type: disc; padding-left: 20px; max-height: 150px; overflow-y: auto; font-size: 0.9em; }
        #listaActivosTrasladar li { margin-bottom: 3px; }
        .activo-enfocado { border: 2px solid #0d6efd !important; box-shadow: 0 0 15px rgba(13,110,253,0.5) !important; animation: pulse-border 1.5s infinite; }
        @keyframes pulse-border { 0% { box-shadow: 0 0 0 0 rgba(13,110,253,0.7); } 70% { box-shadow: 0 0 0 10px rgba(13,110,253,0); } 100% { box-shadow: 0 0 0 0 rgba(13,110,253,0); } }
    </style>
</head>
<body>
    <div class="top-bar-custom">
        <div class="logo-container-top">
            <a href="menu.php" title="Ir a Inicio"><img src="imagenes/logo.png" alt="Logo ARPESOD ASOCIADOS SAS"></a>
        </div>
        <div class="d-flex align-items-center">
            <span class="text-dark me-3 user-info-top">
                <i class="bi bi-person-circle"></i> <?= htmlspecialchars($nombre_usuario_actual_sesion) ?>
                (<?= htmlspecialchars(ucfirst($rol_usuario_actual_sesion)) ?>)
            </span>
            <form action="logout.php" method="post" class="d-flex">
                <button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</button>
            </form>
        </div>
    </div>

    <div class="container-main container mt-4">
        <div class="card search-card p-4">
            <h3 class="page-title mb-4 text-center">Administrar Activos</h3>
            <?php if ($mensaje) echo "<div id='mensaje-global-container' class='mb-3'>$mensaje</div>"; ?>

            <form method="get" class="row g-3 mb-4 align-items-end" action="editar.php">
                <div class="col-md-3"> <label for="cedula_buscar" class="form-label">Buscar por Cédula (Responsable)</label>
                    <input type="text" class="form-control form-control-sm" id="cedula_buscar" name="cedula" value="<?= htmlspecialchars($cedula_buscada) ?>" placeholder="Ingrese cédula">
                </div>
                <div class="col-md-2"> <label for="regional_buscar" class="form-label">Filtrar por Regional (Responsable)</label>
                    <select name="regional" class="form-select form-select-sm" id="regional_buscar">
                        <option value="">-- Todas --</option>
                        <?php foreach ($regionales_usuarios as $r): ?>
                            <option value="<?= htmlspecialchars($r) ?>" <?= ($r == $regional_buscada_usuario) ? 'selected' : '' ?>><?= htmlspecialchars($r) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2"> <label for="empresa_buscar" class="form-label">Filtrar por Empresa (Responsable)</label>
                    <select name="empresa" class="form-select form-select-sm" id="empresa_buscar">
                        <option value="">-- Todas --</option>
                        <?php foreach ($empresas_usuarios_disponibles as $e): ?>
                            <option value="<?= htmlspecialchars($e) ?>" <?= ($e == $empresa_buscada_usuario) ? 'selected' : '' ?>><?= htmlspecialchars($e) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <div class="form-check mt-4 pt-2">
                        <input class="form-check-input" type="checkbox" value="1" id="incluir_bajas_form" name="incluir_bajas" <?= $incluir_dados_baja ? 'checked' : '' ?>>
                        <label class="form-check-label" for="incluir_bajas_form">
                            Incluir Dados de Baja
                        </label>
                    </div>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-custom-search w-100 btn-sm">Buscar</button>
                </div>
            </form>
        </div>

        <?php if ($criterio_buscada_activo): ?>
            <div class="mt-4">
                <?php if (isset($activos_encontrados) && !empty($activos_encontrados)) :
                    $asset_forms_data = [];
                    foreach ($activos_encontrados as $activo_map) {
                        $info_key = $activo_map['cedula_responsable']; 
                        if (!isset($asset_forms_data[$info_key]['info'])) {
                            $asset_forms_data[$info_key]['info'] = [
                                'nombre' => $activo_map['nombre_responsable'],
                                'cargo' => $activo_map['cargo_responsable'],
                                'cedula' => $activo_map['cedula_responsable'],
                                'empresa_responsable' => $activo_map['empresa_del_responsable'] ?? '',
                                'regional_responsable' => $activo_map['regional_responsable'] ?? '' 
                            ];
                        }
                        $asset_forms_data[$info_key]['activos'][] = $activo_map;
                    }

                    foreach ($asset_forms_data as $cedula_grupo => $data_grupo):
                        $btn_acta_text = 'Generar Acta de Entrega';
                        $acta_url_params = "cedula=" . urlencode($data_grupo['info']['cedula']) . "&tipo_acta=entrega";
                        if (!empty($data_grupo['info']['empresa_responsable'])) {
                            $acta_url_params .= "&empresa=" . urlencode($data_grupo['info']['empresa_responsable']);
                        }
                ?>
                        <div class="user-asset-group mt-4" id="user-group-<?= htmlspecialchars($cedula_grupo) ?>">
                            <div class="user-info-header">
                                <div class="info-block">
                                    <h4><?= htmlspecialchars($data_grupo['info']['nombre']) ?> <small class="text-muted">(C.C: <?= htmlspecialchars($data_grupo['info']['cedula']) ?>)</small></h4>
                                    <p><strong>Cargo:</strong> <?= htmlspecialchars($data_grupo['info']['cargo']) ?>
                                        | <strong>Empresa:</strong> <?= htmlspecialchars($data_grupo['info']['empresa_responsable']) ?>
                                        | <strong>Regional:</strong> <?= htmlspecialchars($data_grupo['info']['regional_responsable']) ?>
                                    </p>
                                </div>
                                <div class="actions-block">
                                    <?php if (tiene_permiso_para('trasladar_activo')): ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary btn-transfer-modal"
                                        data-bs-toggle="modal" data-bs-target="#trasladoModal"
                                        data-cedula-origen="<?= htmlspecialchars($data_grupo['info']['cedula']) ?>"
                                        data-nombre-origen="<?= htmlspecialchars($data_grupo['info']['nombre']) ?>"
                                        data-empresa-origen="<?= htmlspecialchars($data_grupo['info']['empresa_responsable'] ?? '') ?>"
                                        data-regional-origen="<?= htmlspecialchars($data_grupo['info']['regional_responsable'] ?? '') ?>">
                                        <i class="bi bi-truck"></i> Trasladar Seleccionados
                                    </button>
                                    <?php endif; ?>
                                    <?php if (tiene_permiso_para('generar_informes')): ?>
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
                                            <input type="checkbox" data-asset-id="<?= $activo_individual['id'] ?>" class="form-check-input checkbox-transfer" title="Seleccionar para trasladar" data-tipo-activo="<?= htmlspecialchars($activo_individual['nombre_tipo_activo'] ?? 'N/A') ?>" data-serie-activo="<?= htmlspecialchars($activo_individual['serie']) ?>">
                                        <?php endif; ?>
                                        <h6> Activo #<?= ($index + 1) ?>: <?= htmlspecialchars($activo_individual['nombre_tipo_activo'] ?? 'N/A') ?> - Serie: <?= htmlspecialchars($activo_individual['serie']) ?> 
                                        </h6>
                                    </div>
                                    <form method="post" action="editar.php" id="form-activo-<?= htmlspecialchars($form_id_individual_activo) ?>">
                                        <input type="hidden" name="id" value="<?= $activo_individual['id'] ?>">
                                        <input type="hidden" name="cedula_original_busqueda" value="<?= htmlspecialchars($cedula_buscada) ?>">
                                        <input type="hidden" name="regional_original_busqueda" value="<?= htmlspecialchars($regional_buscada_usuario) ?>">
                                        <input type="hidden" name="empresa_original_busqueda" value="<?= htmlspecialchars($empresa_buscada_usuario) ?>">
                                        <input type="hidden" name="incluir_bajas_original_busqueda" value="<?= $incluir_dados_baja ? '1' : '0' ?>">

                                        <div class="row">
                                            <?php
                                            input_editable('nombre_usuario_display', 'Usuario Resp.', $data_grupo['info']['nombre'], $form_id_individual_activo, 'text', true);
                                            input_editable('cargo_usuario_display', 'Cargo Resp.', $data_grupo['info']['cargo'], $form_id_individual_activo, 'text', true);
                                            select_editable('tipo_activo_nombre', 'Tipo Activo', $opciones_tipo_activo_nombres, $activo_individual['nombre_tipo_activo'] ?? '', $form_id_individual_activo, true, true);
                                            input_editable('marca', 'Marca', $activo_individual['marca'], $form_id_individual_activo, 'text', true, true);
                                            input_editable('serie', 'Serie', $activo_individual['serie'], $form_id_individual_activo, 'text', true, true);
                                            select_editable('estado', 'Estado Activo', $opciones_estado_general_editable, $activo_individual['estado'], $form_id_individual_activo, true, true);
                                            input_editable('valor_aproximado', 'Valor Aprox.', $activo_individual['valor_aproximado'], $form_id_individual_activo, 'number', true, true);
                                            input_editable('fecha_compra', 'Fecha de Compra', $activo_individual['fecha_compra'] ?? '', $form_id_individual_activo, 'date', true, true);

                                            if (($activo_individual['nombre_tipo_activo'] ?? '') == 'Computador' || !empty($activo_individual['procesador'])) {
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
                    <div class="alert alert-info mt-3">No se encontraron activos con los criterios de búsqueda especificados.</div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div> 

    <div class="modal fade" id="infoModal" tabindex="-1" aria-labelledby="infoModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="infoModalTitle"><i class="bi bi-exclamation-triangle-fill"></i> Atención</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p id="infoModalMessage"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Entendido</button>
                </div>
            </div>
        </div>
    </div>

    <?php if (tiene_permiso_para('trasladar_activo')): ?>
    <div class="modal fade" id="trasladoModal" tabindex="-1" aria-labelledby="trasladoModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title" id="trasladoModalLabel">Trasladar Activos Seleccionados</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 border-end">
                            <p>Del usuario: <strong id="nombreUsuarioOrigenTrasladoModal"></strong><br>
                               C.C: <strong id="cedulaUsuarioOrigenTrasladoModal"></strong><br>
                               Empresa Origen (Usuario): <strong id="empresaUsuarioOrigenTrasladoModal"></strong><br>
                               Regional Origen (Usuario): <strong id="regionalUsuarioOrigenTrasladoModal"></strong></p>
                            <h6>Activos Seleccionados para Traslado:</h6>
                            <ul id="listaActivosTrasladar" class="mb-3"></ul>
                        </div>
                        <div class="col-md-6">
                            <h6>Ingrese los datos del <strong>NUEVO</strong> responsable:</h6>
                            <div class="mb-2"><label for="nueva_cedula_traslado" class="form-label form-label-sm">Nueva Cédula</label><input type="text" class="form-control form-control-sm" id="nueva_cedula_traslado" required></div>
                            <div class="mb-2"><label for="nuevo_nombre_traslado" class="form-label form-label-sm">Nuevo Nombre Completo</label><input type="text" class="form-control form-control-sm" id="nuevo_nombre_traslado" readonly required></div>
                            <div class="mb-2"><label for="nuevo_cargo_traslado" class="form-label form-label-sm">Nuevo Cargo</label><input type="text" class="form-control form-control-sm" id="nuevo_cargo_traslado" readonly required></div>
                            
                            <div class="mb-2">
                                <label for="nueva_regional_traslado" class="form-label form-label-sm">Nueva Regional (del Usuario Destino)</label>
                                <select class="form-select form-select-sm" id="nueva_regional_traslado" required>
                                    <option value="">Seleccione Regional...</option>
                                    <?php foreach ($regionales_opciones_traslado_usuario as $r_opt): ?>
                                        <option value="<?= htmlspecialchars($r_opt) ?>"><?= htmlspecialchars($r_opt) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label for="nueva_empresa_traslado" class="form-label form-label-sm">Nueva Empresa (del Usuario Destino)</label>
                                <select class="form-select form-select-sm" id="nueva_empresa_traslado" required>
                                    <option value="">Seleccione Empresa...</option>
                                    <?php foreach ($empresas_opciones_traslado_usuario as $e_opt): ?>
                                        <option value="<?= htmlspecialchars($e_opt) ?>"><?= htmlspecialchars($e_opt) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-sm btn-primary" id="confirmarTrasladoBtn">Confirmar Traslado</button></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (tiene_permiso_para('dar_baja_activo')): ?>
    <div class="modal fade" id="modalDarBaja" tabindex="-1" aria-labelledby="modalDarBajaLabel" aria-hidden="true">
         <div class="modal-dialog">
            <div class="modal-content">
                <form method="post" action="editar.php" id="formDarBaja">
                    <div class="modal-header"><h5 class="modal-title" id="modalDarBajaLabel">Confirmar Baja de Activo</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                    <div class="modal-body">
                        <p>¿Está seguro que desea dar de baja el activo con serie: <strong id="serieActivoBajaModal"></strong>?</p>
                        <input type="hidden" name="id_activo_baja" id="idActivoBajaModal">
                        <input type="hidden" name="cedula_original_busqueda" id="cedulaOriginalBusquedaBajaModal">
                        <input type="hidden" name="regional_original_busqueda" id="regionalOriginalBusquedaBajaModal">
                        <input type="hidden" name="empresa_original_busqueda" id="empresaOriginalBusquedaBajaModal">
                        <input type="hidden" name="incluir_bajas_original_busqueda" id="incluirBajasOriginalBusquedaBajaModal">
                        
                        <div class="mb-3"><label for="motivo_baja" class="form-label">Motivo de la Baja <span class="text-danger">*</span></label><select class="form-select form-select-sm" id="motivo_baja" name="motivo_baja" required><option value="">Seleccione un motivo...</option><option value="Obsolescencia">Obsolescencia</option><option value="Daño irreparable">Daño irreparable</option><option value="Pérdida">Pérdida</option><option value="Robo">Robo</option><option value="Venta">Venta</option><option value="Donación">Donación</option><option value="Fin de vida útil">Fin de vida útil</option><option value="Otro">Otro (especificar en observaciones)</option></select></div>
                        <div class="mb-3"><label for="observaciones_baja" class="form-label">Observaciones Adicionales</label><textarea class="form-control form-control-sm" id="observaciones_baja" name="observaciones_baja" rows="3"></textarea></div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" name="submit_dar_baja" class="btn btn-sm btn-warning">Confirmar Baja</button></div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <script>
        let infoModalInstance;

        function mostrarInfoModal(titulo, mensaje) {
            const modalElement = document.getElementById('infoModal');
            if (!modalElement) {
                alert(titulo + ": " + mensaje); 
                return;
            }
            const modalTitleElement = document.getElementById('infoModalTitle');
            const modalMessageElement = document.getElementById('infoModalMessage');
            if (!infoModalInstance) { 
                infoModalInstance = new bootstrap.Modal(modalElement); 
            }
            if (modalTitleElement) { 
                modalTitleElement.innerHTML = `<i class="bi bi-exclamation-triangle-fill"></i> ${titulo}`; 
            }
            if (modalMessageElement) { 
                modalMessageElement.textContent = mensaje; 
            }
            if (infoModalInstance) {
                infoModalInstance.show(); 
            }
        }

        function habilitarCamposActivo(formSuffix) {
            const formId = 'form-activo-' + formSuffix;
            const form = document.getElementById(formId);
            if (!form) { 
                console.error('Formulario NO encontrado para habilitar:', formId); 
                return; 
            }
            const elementsToEnable = form.querySelectorAll('input:not([name="nombre_usuario_display"]):not([name="cargo_usuario_display"]), select, textarea');
            elementsToEnable.forEach(el => { 
                el.removeAttribute('readonly'); 
                el.removeAttribute('disabled'); 
            });
            
            const updateButton = form.querySelector('button[name="editar_activo_submit"]');
            if (updateButton) { 
                updateButton.removeAttribute('disabled'); 
            }
            
            const firstEditableField = form.querySelector('select[name="tipo_activo_nombre"]'); 
            if (firstEditableField && !firstEditableField.hasAttribute('disabled')) { 
                firstEditableField.focus(); 
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            <?php if (tiene_permiso_para('trasladar_activo')): ?>
            var trasladoModalEl = document.getElementById('trasladoModal');
            if (trasladoModalEl) {
                var bsTrasladoModal = new bootstrap.Modal(trasladoModalEl, { keyboard: false });
                var cedulaOrigenActualParaModal = ''; 
                
                trasladoModalEl.addEventListener('show.bs.modal', function(event) {
                    var button = event.relatedTarget; 
                    cedulaOrigenActualParaModal = button.getAttribute('data-cedula-origen');
                    let nombreOrigen = button.getAttribute('data-nombre-origen');
                    let empresaOrigen = button.getAttribute('data-empresa-origen');
                    let regionalOrigen = button.getAttribute('data-regional-origen');

                    document.getElementById('nombreUsuarioOrigenTrasladoModal').textContent = nombreOrigen || 'N/A';
                    document.getElementById('cedulaUsuarioOrigenTrasladoModal').textContent = cedulaOrigenActualParaModal || 'N/A';
                    document.getElementById('empresaUsuarioOrigenTrasladoModal').textContent = empresaOrigen || 'N/A';
                    document.getElementById('regionalUsuarioOrigenTrasladoModal').textContent = regionalOrigen || 'N/A';
                    
                    const userGroupDiv = document.getElementById('user-group-' + cedulaOrigenActualParaModal); 
                    const listaActivosUl = document.getElementById('listaActivosTrasladar');
                    listaActivosUl.innerHTML = ''; 

                    if (userGroupDiv) {
                        const checkboxesSeleccionados = userGroupDiv.querySelectorAll('.checkbox-transfer:checked');
                        if (checkboxesSeleccionados.length > 0) {
                            checkboxesSeleccionados.forEach(cb => {
                                let tipo = cb.getAttribute('data-tipo-activo');
                                let serie = cb.getAttribute('data-serie-activo');
                                let idActivo = cb.getAttribute('data-asset-id');
                                let listItem = document.createElement('li');
                                listItem.textContent = `${tipo || 'Desconocido'} (S/N: ${serie || 'N/A'}, ID: ${idActivo})`;
                                listaActivosUl.appendChild(listItem);
                            });
                        } else {
                            listaActivosUl.innerHTML = "<li class='text-danger'>Ningún activo seleccionado. Por favor, marque la casilla de los activos que desea trasladar.</li>";
                        }
                    } else {
                        listaActivosUl.innerHTML = "<li class='text-danger'>Error: No se pudo identificar el grupo de activos del usuario.</li>";
                        console.error("Error: no se encontró un ancestro '#user-group-" + cedulaOrigenActualParaModal + "' para el botón presionado.", button);
                    }
                    document.getElementById('nueva_cedula_traslado').value = '';
                    document.getElementById('nuevo_nombre_traslado').value = ''; 
                    document.getElementById('nuevo_cargo_traslado').value = '';   
                    document.getElementById('nueva_regional_traslado').value = ''; 
                    document.getElementById('nueva_empresa_traslado').value = '';  

                    const inputNuevaCedula = document.getElementById('nueva_cedula_traslado');
                    if (inputNuevaCedula) {
                        inputNuevaCedula.onkeyup = function() {
                            const cedulaIngresada = this.value.trim();
                            if (cedulaIngresada.length >= 5) { 
                                fetch(`buscar_datos_usuario.php?cedula=${encodeURIComponent(cedulaIngresada)}`)
                                .then(response => { if (!response.ok) { throw new Error(`Error HTTP: ${response.status}`); } return response.json(); })
                                .then(data => {
                                    if (data.encontrado) {
                                        document.getElementById('nuevo_nombre_traslado').value = data.nombre_completo || '';
                                        document.getElementById('nuevo_cargo_traslado').value = data.cargo || '';
                                        if (data.regional && document.getElementById('nueva_regional_traslado')) {
                                            document.getElementById('nueva_regional_traslado').value = data.regional;
                                        } else if (document.getElementById('nueva_regional_traslado')) {
                                            document.getElementById('nueva_regional_traslado').value = '';
                                        }
                                        if (data.empresa && document.getElementById('nueva_empresa_traslado')) {
                                            document.getElementById('nueva_empresa_traslado').value = data.empresa;
                                        } else if (document.getElementById('nueva_empresa_traslado')) {
                                            document.getElementById('nueva_empresa_traslado').value = '';
                                        }
                                    } else {
                                        document.getElementById('nuevo_nombre_traslado').value = '';
                                        document.getElementById('nuevo_cargo_traslado').value = '';
                                        if (document.getElementById('nueva_regional_traslado')) document.getElementById('nueva_regional_traslado').value = '';
                                        if (document.getElementById('nueva_empresa_traslado')) document.getElementById('nueva_empresa_traslado').value = '';
                                    }
                                })
                                .catch(error => {
                                    console.error('Error al buscar datos del usuario (fetch):', error);
                                    document.getElementById('nuevo_nombre_traslado').value = '';
                                    document.getElementById('nuevo_cargo_traslado').value = '';
                                    if (document.getElementById('nueva_regional_traslado')) document.getElementById('nueva_regional_traslado').value = '';
                                    if (document.getElementById('nueva_empresa_traslado')) document.getElementById('nueva_empresa_traslado').value = '';
                                });
                            }
                        };
                    }
                });

                document.getElementById('confirmarTrasladoBtn').addEventListener('click', function() {
                    let fd = new FormData();
                    // Parámetros para recargar la página con los filtros correctos después del traslado
                    fd.append('cedula_original_busqueda', document.getElementById('cedula_buscar').value);
                    fd.append('regional_original_busqueda', document.getElementById('regional_buscar').value);
                    fd.append('empresa_original_busqueda', document.getElementById('empresa_buscar').value);
                    fd.append('incluir_bajas_original_busqueda', document.getElementById('incluir_bajas_form').checked ? '1' : '0');
                    
                    fd.append('action', 'confirmar_traslado_masivo');
                    
                    const nCed = document.getElementById('nueva_cedula_traslado').value.trim();
                    const nRegUsuario = document.getElementById('nueva_regional_traslado').value; // Ya es trim() en el backend
                    const nEmpUsuario = document.getElementById('nueva_empresa_traslado').value; // Ya es trim() en el backend

                    if (!nCed || !nRegUsuario || !nEmpUsuario ) {
                        mostrarInfoModal('Completa los campos','La nueva cédula, regional y empresa del usuario destino son obligatorias.');
                        return;
                    }
                    
                    const userGroupDivForSelection = document.getElementById('user-group-' + cedulaOrigenActualParaModal);
                    if (!userGroupDivForSelection) {
                        mostrarInfoModal('Error','No se pudo encontrar el grupo de activos del usuario origen.');
                        return;
                    }
                    const chkSel = userGroupDivForSelection.querySelectorAll('.checkbox-transfer:checked');

                    if (chkSel.length === 0) {
                        mostrarInfoModal('Sin selección','No ha seleccionado activos para trasladar.');
                        return;
                    }
                    let idsSel = [];
                    chkSel.forEach(cb => idsSel.push(cb.getAttribute('data-asset-id') || cb.value));
                    fd.append('ids_activos_seleccionados_traslado', idsSel.join(','));
                    
                    fd.append('nueva_cedula_traslado', nCed); 
                    fd.append('nueva_regional_traslado', nRegUsuario); 
                    fd.append('nueva_empresa_traslado', nEmpUsuario); 
                    
                    bsTrasladoModal.hide(); 
                    
                    fetch('editar.php', { method: 'POST', body: fd })
                    .then(r => {
                        if (!r.ok) { 
                            return r.text().then(text => { throw new Error(`Error HTTP ${r.status} en la respuesta del traslado: ${text}`); });
                        }
                        return r.json();
                    })
                    .then(d => {
                        let msgDiv = document.getElementById('mensaje-global-container');
                        if (!msgDiv) { 
                            msgDiv = document.createElement('div');
                            msgDiv.id = 'mensaje-global-container';
                            const searchCard = document.querySelector('.card.search-card');
                            if(searchCard) {
                                searchCard.insertAdjacentElement('afterend', msgDiv);
                            } else { 
                                document.querySelector('.container-main').insertBefore(msgDiv, document.querySelector('.container-main').firstChild);
                            }
                        }
                        msgDiv.innerHTML = `<div class='alert ${d.success?'alert-success':'alert-danger'} alert-dismissible fade show mt-3'>${d.message}<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>`;
                        if (d.success) {
                            setTimeout(() => {
                                // Construir los parámetros para la redirección basados en lo que se envió
                                let redirectUrl = `editar.php?`;
                                const params = new URLSearchParams();
                                if (fd.get('cedula_original_busqueda')) params.append('cedula', fd.get('cedula_original_busqueda'));
                                if (fd.get('regional_original_busqueda')) params.append('regional', fd.get('regional_original_busqueda'));
                                if (fd.get('empresa_original_busqueda')) params.append('empresa', fd.get('empresa_original_busqueda'));
                                if (fd.get('incluir_bajas_original_busqueda') === '1') params.append('incluir_bajas', '1');
                                if (idsSel.length > 0) params.append('id_activo_focus', idsSel[0]);
                                
                                window.location.href = redirectUrl + params.toString();
                            }, 1500);
                        }
                    }).catch(e => { 
                        console.error('Error en fetch de traslado:', e); 
                        mostrarInfoModal('Error de Traslado',`Error procesando traslado: ${e.message}. Revise la consola y los logs del servidor.`); 
                    });
                });
            }
            <?php endif; ?>

            <?php if (tiene_permiso_para('dar_baja_activo')): ?>
            var modalDarBajaEl = document.getElementById('modalDarBaja');
            if (modalDarBajaEl) {
                 modalDarBajaEl.addEventListener('show.bs.modal', function(event) {
                    var button = event.relatedTarget;
                    var idActivo = button.getAttribute('data-id-activo');
                    var serieActivo = button.getAttribute('data-serie-activo');
                    
                    document.getElementById('idActivoBajaModal').value = idActivo;
                    document.getElementById('serieActivoBajaModal').textContent = serieActivo;
                    document.getElementById('cedulaOriginalBusquedaBajaModal').value = document.getElementById('cedula_buscar') ? document.getElementById('cedula_buscar').value : '';
                    document.getElementById('regionalOriginalBusquedaBajaModal').value = document.getElementById('regional_buscar') ? document.getElementById('regional_buscar').value : '';
                    document.getElementById('empresaOriginalBusquedaBajaModal').value = document.getElementById('empresa_buscar') ? document.getElementById('empresa_buscar').value : '';
                    document.getElementById('incluirBajasOriginalBusquedaBajaModal').value = document.getElementById('incluir_bajas_form') ? (document.getElementById('incluir_bajas_form').checked ? '1' : '0') : '0';
                });
            }
            <?php endif; ?>

            const urlParams = new URLSearchParams(window.location.search);
            const idActivoFocus = urlParams.get('id_activo_focus');
            if (idActivoFocus) {
                const elementoEnfocar = document.getElementById('activo_container_' + idActivoFocus);
                if (elementoEnfocar) {
                    elementoEnfocar.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    elementoEnfocar.classList.add('activo-enfocado');
                }
            }
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>