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
$regional_buscada = '';
$empresa_buscada = ''; // <<< Nueva variable para el filtro de empresa
$activos_encontrados = [];

// Actualizar listas de regionales y definir empresas
$regionales = ['Popayan', 'Bordo', 'Santander', 'Valle', 'Pasto', 'Tuquerres', 'Huila', 'Nacional']; // 'Finansueños' quitado
$empresas_disponibles = ['Arpesod', 'Finansueños']; // <<< Definición de empresas

$criterio_buscada_activo = false;
$usuario_actual_sistema_para_historial = $_SESSION['usuario_login'] ?? 'Sistema';

$nombre_usuario_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Usuario';
$rol_usuario_sesion = obtener_rol_usuario();

// Determinar los criterios de búsqueda actuales y si una acción POST ocurrió
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $criterio_buscada_activo = true;
    // Unificar nombres de campos ocultos para la búsqueda original
    $cedula_buscada = $_POST['cedula_original_busqueda'] ?? ($_GET['cedula'] ?? '');
    $regional_buscada = $_POST['regional_original_busqueda'] ?? ($_GET['regional'] ?? '');
    $empresa_buscada = $_POST['empresa_original_busqueda'] ?? ($_GET['empresa'] ?? ''); // <<< Capturar empresa
} else { // GET request
    $cedula_buscada = $_GET['cedula'] ?? '';
    $regional_buscada = $_GET['regional'] ?? '';
    $empresa_buscada = $_GET['empresa'] ?? ''; // <<< Capturar empresa
    if (!empty($cedula_buscada) || !empty($regional_buscada) || !empty($empresa_buscada)) { // <<< Añadir empresa a la condición
        $criterio_buscada_activo = true;
    }
}


if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_SESSION['traslado_info'])) {
    unset($_SESSION['traslado_info']);
}

// Procesamiento de acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Mantener los criterios de búsqueda originales para recargar la vista
    // Estos se tomarán de $_POST['*_original_busqueda'] ya asignados arriba
    // $cedula_buscada, $regional_buscada, $empresa_buscada ya tienen los valores correctos.

    if (isset($_POST['editar_activo_submit'])) {
        if (!tiene_permiso_para('editar_activo_detalles')) {
            $mensaje = "<div class='alert alert-danger'>Acción no permitida para su rol.</div>";
        } elseif (empty($_POST['id']) || empty($_POST['tipo_activo']) || empty($_POST['marca']) || empty($_POST['serie']) || empty($_POST['estado']) || !isset($_POST['valor_aproximado']) || empty($_POST['regional_activo_form'])) {
            $mensaje = "<div class='alert alert-danger'>Error: Faltan campos obligatorios del activo para actualizar.</div>";
        } else {
            $id_activo_a_editar = (int)$_POST['id'];
            // Asegurarse que la columna 'Empresa' (con E mayúscula) esté en la tabla si es la que se usa.
            // Si la columna se llama 'empresa' (minúscula), el UPDATE también debe usar 'empresa'.
            // Aquí se asume que la columna 'Empresa' NO se edita directamente en este formulario,
            // pero si se hiciera, se necesitaría un campo y añadirlo al UPDATE y a $campos_a_comparar.
            // Por ahora, solo nos enfocamos en el FILTRO de búsqueda.

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
            if (!$stmt) {
                $mensaje = "<div class='alert alert-danger'>Error al preparar actualización: " . $conexion->error . "</div>";
            } else {
                $stmt->bind_param(
                    'ssssdssssssssssi',
                    $_POST['tipo_activo'],
                    $_POST['marca'],
                    $_POST['serie'],
                    $_POST['estado'],
                    $_POST['valor_aproximado'],
                    $_POST['detalles'],
                    $_POST['regional_activo_form'],
                    $_POST['procesador'],
                    $_POST['ram'],
                    $_POST['disco_duro'],
                    $_POST['tipo_equipo'],
                    $_POST['red'],
                    $_POST['sistema_operativo'],
                    $_POST['offimatica'],
                    $_POST['antivirus'],
                    $id_activo_a_editar
                );
                if ($stmt->execute()) {
                    $mensaje = "<div class='alert alert-success'>Activo ID: " . htmlspecialchars($id_activo_a_editar) . " actualizado.</div>";
                    if ($datos_anteriores_del_activo) {
                        $datos_nuevos_post = $_POST;
                        $datos_nuevos_para_historial = [];
                        $cambios_descripcion_array = [];
                        // Asegúrate que los nombres de columna en $datos_anteriores_del_activo coincidan (ej. 'Empresa' vs 'empresa')
                        $campos_a_comparar = [
                            'tipo_activo' => 'tipo_activo',
                            'marca' => 'marca',
                            'serie' => 'serie',
                            'estado' => 'estado',
                            'valor_aproximado' => 'valor_aproximado',
                            'detalles' => 'detalles',
                            'regional_activo_form' => 'regional', // 'regional_activo_form' es el name del input, 'regional' es la columna DB
                            'procesador' => 'procesador',
                            'ram' => 'ram',
                            'disco_duro' => 'disco_duro',
                            'tipo_equipo' => 'tipo_equipo',
                            'red' => 'red',
                            'sistema_operativo' => 'sistema_operativo',
                            'offimatica' => 'offimatica',
                            'antivirus' => 'antivirus'
                            // Si 'Empresa' fuera editable aquí, se añadiría: 'nombre_input_empresa' => 'Empresa' (o 'empresa')
                        ];
                        foreach ($campos_a_comparar as $post_key => $db_key) {
                            $valor_nuevo = $datos_nuevos_post[$post_key] ?? null;
                            // Usar 'Empresa' si esa es la clave devuelta por fetch_assoc, o 'empresa' si es minúscula.
                            // Basado en el debug de buscar.php, la clave podría ser 'Empresa'.
                            $valor_anterior = $datos_anteriores_del_activo[$db_key] ?? ($datos_anteriores_del_activo[ucfirst($db_key)] ?? null); // Intenta también con mayúscula inicial
                            if ($db_key === 'regional' && isset($datos_anteriores_del_activo['regional'])) { // caso específico para regional
                                $valor_anterior = $datos_anteriores_del_activo['regional'];
                            }


                            if ($valor_nuevo !== $valor_anterior) {
                                $datos_nuevos_para_historial[$db_key] = $valor_nuevo;
                                $cambios_descripcion_array[] = ucfirst(str_replace('_', ' ', $db_key)) . ": de '" . htmlspecialchars($valor_anterior ?? 'N/A') . "' a '" . htmlspecialchars($valor_nuevo ?? 'N/A') . "'";
                            }
                        }
                        if (!empty($cambios_descripcion_array)) {
                            $descripcion_historial = "Actualización: " . implode("; ", $cambios_descripcion_array);
                            $datos_anteriores_especificos = [];
                            foreach (array_keys($datos_nuevos_para_historial) as $ckey) {
                                $datos_anteriores_especificos[$ckey] = $datos_anteriores_del_activo[$ckey] ?? ($datos_anteriores_del_activo[ucfirst($ckey)] ?? null);
                            }
                            registrar_evento_historial($conexion, $id_activo_a_editar, HISTORIAL_TIPO_ACTUALIZACION, $descripcion_historial, $usuario_actual_sistema_para_historial, $datos_anteriores_especificos, $datos_nuevos_para_historial);
                        }
                    }
                } else {
                    $mensaje = "<div class='alert alert-danger'>Error al actualizar: " . $stmt->error . "</div>";
                }
                $stmt->close();
            }
        }
    } elseif (isset($_POST['eliminar_activo_submit'])) {
        // ... (código de eliminar sin cambios relevantes para el filtro de empresa en la recarga) ...
        if (!tiene_permiso_para('eliminar_activo_fisico')) {
            $mensaje = "<div class='alert alert-danger'>Acción no permitida para su rol.</div>";
        } elseif (empty($_POST['id'])) {
            $mensaje = "<div class='alert alert-danger'>ID no provisto para eliminar.</div>";
        } else {
            $id_activo_a_eliminar = (int)$_POST['id'];
            $datos_activo_a_eliminar_hist = null;
            $stmt_info_activo = $conexion->prepare("SELECT * FROM activos_tecnologicos WHERE id = ?");
            if ($stmt_info_activo) {
                $stmt_info_activo->bind_param('i', $id_activo_a_eliminar);
                $stmt_info_activo->execute();
                $datos_activo_a_eliminar_hist = $stmt_info_activo->get_result()->fetch_assoc();
                $stmt_info_activo->close();
            }
            if ($datos_activo_a_eliminar_hist) {
                $descripcion_baja = "Activo ELIMINADO FÍSICAMENTE. Tipo: " . htmlspecialchars($datos_activo_a_eliminar_hist['tipo_activo'] ?? 'N/A') . ", Serie: " . htmlspecialchars($datos_activo_a_eliminar_hist['serie'] ?? 'N/A');
                // Usar 'Empresa' si esa es la clave devuelta
                $descripcion_baja .= ", Empresa Anterior: " . htmlspecialchars($datos_activo_a_eliminar_hist['Empresa'] ?? ($datos_activo_a_eliminar_hist['empresa'] ?? 'N/A'));
                registrar_evento_historial($conexion, $id_activo_a_eliminar, HISTORIAL_TIPO_BAJA, $descripcion_baja, $usuario_actual_sistema_para_historial, $datos_activo_a_eliminar_hist, null);
            }
            $stmt = $conexion->prepare("DELETE FROM activos_tecnologicos WHERE id=?");
            if (!$stmt) {
                $mensaje = "<div class='alert alert-danger'>Error preparación borrado: " . $conexion->error . "</div>";
            } else {
                $stmt->bind_param('i', $id_activo_a_eliminar);
                if ($stmt->execute()) {
                    $mensaje = "<div class='alert alert-success'>Activo ID: " . htmlspecialchars($id_activo_a_eliminar) . " eliminado físicamente.</div>";
                } else {
                    $mensaje = "<div class='alert alert-danger'>Error al eliminar físicamente: " . $stmt->error . "</div>";
                }
                $stmt->close();
            }
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'confirmar_traslado_masivo') {
    
        header('Content-Type: application/json');
        $response = ['success' => false, 'message' => 'Error desconocido durante el traslado.'];
    
        if (!tiene_permiso_para('trasladar_activo')) {
            $response['message'] = 'Acción no permitida para su rol.';
            echo json_encode($response);
            exit;
        }
    
        // --- Capturar y validar todos los datos del POST ---
        $cedula_origen = $_POST['cedula_usuario_origen_hidden'] ?? '';
        $ids_activos_str = $_POST['ids_activos_seleccionados_traslado'] ?? '';
        
        $nueva_cedula = $_POST['nueva_cedula_traslado'] ?? '';
        $nuevo_nombre = $_POST['nuevo_nombre_traslado'] ?? '';
        $nuevo_cargo = $_POST['nuevo_cargo_traslado'] ?? '';
        $nueva_regional = $_POST['nueva_regional_traslado'] ?? '';
        $nueva_empresa = $_POST['nueva_empresa_traslado'] ?? ''; // <<< Capturar la nueva empresa
    
        // Validar que los datos no estén vacíos
        if (empty($ids_activos_str) || empty($nueva_cedula) || empty($nuevo_nombre) || empty($nuevo_cargo) || empty($nueva_regional) || empty($nueva_empresa)) {
            $response['message'] = 'Error: Faltan datos para realizar el traslado. Verifique que todos los campos del nuevo responsable estén completos.';
            echo json_encode($response);
            exit;
        }
    
        // --- Lógica de la Base de Datos ---
        $conexion->begin_transaction(); // Iniciar transacción para seguridad
    
        try {
            $ids_array = explode(',', $ids_activos_str);
            $ids_array = array_filter($ids_array, 'is_numeric'); // Asegurarse que solo sean números
    
            if (empty($ids_array)) {
                throw new Exception("No se seleccionaron activos válidos para el traslado.");
            }
    
            // Preparar placeholders para la cláusula IN (...)
            $placeholders = implode(',', array_fill(0, count($ids_array), '?'));
            
            // ***** LA CORRECCIÓN CLAVE ESTÁ AQUÍ *****
            // 1. Se añade "Empresa = ?" a la consulta SQL. Tu columna se llama 'Empresa' con mayúscula.
            $sql_update = "UPDATE activos_tecnologicos SET cedula = ?, nombre = ?, cargo = ?, regional = ?, Empresa = ? WHERE id IN ($placeholders)";
            
            $stmt_update = $conexion->prepare($sql_update);
            if (!$stmt_update) {
                throw new Exception("Error al preparar la actualización: " . $conexion->error);
            }
    
            // 2. Se añade 's' para la empresa y la variable $nueva_empresa al bind_param
            $types = 'sssss' . str_repeat('i', count($ids_array));
            $params = array_merge([$nueva_cedula, $nuevo_nombre, $nuevo_cargo, $nueva_regional, $nueva_empresa], $ids_array);
            
            $stmt_update->bind_param($types, ...$params);
    
            if (!$stmt_update->execute()) {
                throw new Exception("Error al ejecutar el traslado: " . $stmt_update->error);
            }
    
            // --- Registrar en el historial para cada activo ---
            // (Esta parte es opcional pero muy recomendada)
            foreach($ids_array as $id_activo) {
                $desc_historial = "Activo trasladado al responsable: " . htmlspecialchars($nuevo_nombre) . " (C.C: " . htmlspecialchars($nueva_cedula) . ", Empresa: " . htmlspecialchars($nueva_empresa) . ")";
                registrar_evento_historial($conexion, $id_activo, HISTORIAL_TIPO_TRASLADO, $desc_historial, $_SESSION['usuario_login'] ?? 'Sistema', null, ['destino_cedula' => $nueva_cedula, 'destino_nombre' => $nuevo_nombre, 'destino_empresa' => $nueva_empresa]);
            }
    
            $conexion->commit(); // Confirmar los cambios si todo fue exitoso
            $stmt_update->close();
    
            $response['success'] = true;
            $response['message'] = '¡Traslado completado exitosamente! ' . count($ids_array) . ' activo(s) fueron reasignados.';
            // Aquí podrías añadir datos para generar el acta de traslado si lo deseas
            // $response['data_acta'] = [...];
    
        } catch (Exception $e) {
            $conexion->rollback(); // Revertir los cambios en caso de error
            $response['message'] = $e->getMessage();
            error_log("Error en traslado masivo: " . $e->getMessage());
        }
    
        echo json_encode($response);
        exit;

    } elseif (isset($_POST['submit_dar_baja'])) {
        // ... (código de dar baja sin cambios relevantes para el filtro de empresa en la recarga) ...
        if (!tiene_permiso_para('dar_baja_activo')) {
            $mensaje = "<div class='alert alert-danger'>Acción no permitida para su rol.</div>";
        } elseif (empty($_POST['id_activo_baja']) || empty($_POST['motivo_baja'])) {
            $mensaje = "<div class='alert alert-danger'>Faltan datos para dar de baja el activo (ID o Motivo).</div>";
        } else {
            $id_activo_baja = filter_input(INPUT_POST, 'id_activo_baja', FILTER_VALIDATE_INT);
            $motivo_baja = trim($_POST['motivo_baja']);
            $observaciones_baja = trim($_POST['observaciones_baja'] ?? '');

            // $cedula_buscada, $regional_buscada, $empresa_buscada ya deberían estar seteados desde el inicio del POST

            $stmt_datos_previos = $conexion->prepare("SELECT * FROM activos_tecnologicos WHERE id = ?");
            $datos_anteriores_del_activo = null;
            if ($stmt_datos_previos) {
                $stmt_datos_previos->bind_param('i', $id_activo_baja);
                $stmt_datos_previos->execute();
                $datos_anteriores_del_activo = $stmt_datos_previos->get_result()->fetch_assoc();
                $stmt_datos_previos->close();
            }

            if (!$datos_anteriores_del_activo) {
                $mensaje = "<div class='alert alert-danger'>Activo a dar de baja no encontrado (ID: " . htmlspecialchars($id_activo_baja) . ").</div>";
            } elseif ($datos_anteriores_del_activo['estado'] === 'Dado de Baja') {
                $mensaje = "<div class='alert alert-warning'>El activo ID: " . htmlspecialchars($id_activo_baja) . " ya está Dado de Baja.</div>";
            } else {
                $sql_baja = "UPDATE activos_tecnologicos SET estado = 'Dado de Baja' WHERE id = ?";
                $stmt_baja = $conexion->prepare($sql_baja);
                if ($stmt_baja) {
                    $stmt_baja->bind_param('i', $id_activo_baja);
                    if ($stmt_baja->execute()) {
                        $mensaje = "<div class='alert alert-success'>Activo ID: " . htmlspecialchars($id_activo_baja) . " dado de baja.</div>";
                        $descripcion_hist_baja = "Activo dado de baja. Motivo: " . htmlspecialchars($motivo_baja) . ".";
                        if (!empty($observaciones_baja)) {
                            $descripcion_hist_baja .= " Observaciones: " . htmlspecialchars($observaciones_baja);
                        }
                        // Incluir empresa en el contexto si es relevante
                        $empresa_anterior_baja = $datos_anteriores_del_activo['Empresa'] ?? ($datos_anteriores_del_activo['empresa'] ?? 'N/A');
                        $descripcion_hist_baja .= " (Empresa Anterior: " . htmlspecialchars($empresa_anterior_baja) . ")";

                        $datos_contexto_baja = ['estado_anterior' => $datos_anteriores_del_activo['estado'], 'motivo_baja' => $motivo_baja, 'observaciones_baja' => $observaciones_baja, 'fecha_efectiva_baja' => date('Y-m-d H:i:s'), 'empresa_anterior' => $empresa_anterior_baja];
                        registrar_evento_historial($conexion, $id_activo_baja, HISTORIAL_TIPO_BAJA, $descripcion_hist_baja, $usuario_actual_sistema_para_historial, $datos_anteriores_del_activo, $datos_contexto_baja);
                    } else {
                        $mensaje = "<div class='alert alert-danger'>Error al dar de baja: " . $stmt_baja->error . "</div>";
                    }
                    $stmt_baja->close();
                } else {
                    $mensaje = "<div class='alert alert-danger'>Error preparando baja: " . $conexion->error . "</div>";
                }
            }
        }
    }
} // Fin de if ($_SERVER['REQUEST_METHOD'] === 'POST')

// Lógica para cargar activos a mostrar (se ejecuta siempre, después de POST o en GET)
if ($criterio_buscada_activo) {
    // Usar 'Empresa' como nombre de columna si así se determinó en buscar.php
    // Si la columna en la BD es 'empresa' (minúscula), usar 'empresa'.
    // Asumiendo que la columna es 'Empresa' en la BD basado en el array key de buscar.php
    $sql_select = "SELECT * FROM activos_tecnologicos WHERE estado != 'Dado de Baja'";
    $params_select_query = [];
    $types_select_query = '';

    if (!empty($cedula_buscada)) {
        $sql_select .= " AND cedula = ?";
        $params_select_query[] = $cedula_buscada;
        $types_select_query .= 's';
    }
    if (!empty($regional_buscada)) {
        $sql_select .= " AND regional = ?";
        $params_select_query[] = $regional_buscada;
        $types_select_query .= 's';
    }
    if (!empty($empresa_buscada)) { // <<< Añadir filtro de empresa
        // Basado en el debug de buscar.php donde la clave del array era 'Empresa',
        // es probable que el nombre de la columna en la BD sea 'Empresa'.
        $sql_select .= " AND Empresa = ?"; // <<< Usando 'Empresa' con E mayúscula
        $params_select_query[] = $empresa_buscada;
        $types_select_query .= 's';
    }
    $sql_select .= " ORDER BY cedula ASC, id ASC";

    $stmt_select = $conexion->prepare($sql_select);
    if ($stmt_select) {
        if (!empty($params_select_query)) {
            $stmt_select->bind_param($types_select_query, ...$params_select_query);
        }
        if ($stmt_select->execute()) {
            $activos_encontrados = $stmt_select->get_result()->fetch_all(MYSQLI_ASSOC);
        } else {
            $mensaje .= "<div class='alert alert-danger'>Error búsqueda: " . $stmt_select->error . "</div>";
        }
        $stmt_select->close();
    } else {
        $mensaje .= "<div class='alert alert-danger'>Error preparando búsqueda: " . $conexion->error . "</div>";
    }
}

// ... (resto de las definiciones de opciones y funciones helper sin cambios)
$opciones_tipo_activo = ['Computador', 'Monitor', 'Impresora', 'Escáner', 'DVR', 'Contadora Billetes', 'Contadora Monedas', 'Celular', 'Impresora Térmica', 'Combo Teclado y Mouse', 'Diadema', 'Adaptador Multipuertos / Red', 'Router'];
$opciones_tipo_equipo = ['Portátil', 'Mesa', 'Todo en 1'];
$opciones_red = ['Cableada', 'Inalámbrica', 'Ambas'];
$opciones_estado_general = ['Bueno', 'Regular', 'Malo', 'En Mantenimiento', 'Dado de Baja'];
$opciones_so = ['Windows 10', 'Windows 11', 'Linux', 'MacOS', 'Otro SO', 'N/A SO'];
$opciones_offimatica = ['Office 365', 'Office Home And Business', 'Office 2021', 'Office 2019', 'Office 2016', 'LibreOffice', 'Google Workspace', 'Otro Office', 'N/A Office'];
$opciones_antivirus = ['Microsoft Defender', 'Bitdefender', 'ESET NOD32 Antivirus', 'McAfee Total Protection', 'Kaspersky', 'N/A Antivirus', 'Otro Antivirus'];

function input_editable($name, $label, $value, $form_id_suffix_func, $type = 'text', $is_readonly = true, $is_required = false, $col_class = 'col-md-4')
{
    $readonly_attr = $is_readonly ? 'readonly' : '';
    $required_attr = $is_required ? 'required' : '';
    $step_attr = ($type === 'number') ? "step='0.01' min='0'" : "";
    $input_id = $name . '-' . $form_id_suffix_func;
    echo "<div class='{$col_class} mb-2'><label for='{$input_id}' class='form-label form-label-sm'>$label</label><input type='$type' name='$name' id='{$input_id}' class='form-control form-control-sm' value='" . htmlspecialchars($value ?? '') . "' $readonly_attr $required_attr $step_attr></div>";
}
function select_editable($name, $label, $options_array, $selected_value, $form_id_suffix_func, $is_readonly = true, $is_required = false, $col_class = 'col-md-4')
{
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
function textarea_editable($name, $label, $value, $form_id_suffix_func, $is_readonly = true, $col_class = 'col-md-12')
{
    $readonly_attr = $is_readonly ? 'readonly' : '';
    $textarea_id = $name . '-' . $form_id_suffix_func;
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
        body { 
            background-color: #ffffff !important; /* Fondo del body blanco */
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding-top: 80px; /* Espacio para la barra superior fija */
        }
        .top-bar-custom {
            position: fixed; /* Fija la barra en la parte superior */
            top: 0;
            left: 0;
            right: 0;
            z-index: 1030; /* Asegura que esté por encima de otros elementos */
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 1.5rem; /* Ajusta el padding según necesites */
            background-color: #f8f9fa; /* Un color de fondo claro para la barra */
            border-bottom: 1px solid #dee2e6;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .logo-container-top img {
            width: auto; /* Ancho automático */
            height: 75px; /* Altura fija para el logo en la barra */
            object-fit: contain;
            margin-right: 15px; /* Espacio a la derecha del logo */
        }
        .user-info-top {
            font-size: 0.9rem;
        }
        .btn-custom-search {
            background-color: #191970;
            color: white;
        }

        .btn-custom-search:hover {
            background-color: #8b0000;
            color: white;
        }

        .user-asset-group {
            background-color: #fff;
            padding: 20px;
            margin-bottom: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.07);
        }

        .user-info-header {
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .user-info-header .info-block {
            flex-grow: 1;
        }

        .user-info-header .actions-block {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 5px;
            min-width: 220px;
            text-align: right;
        }

        .user-info-header h4 {
            color: #37517e;
            font-weight: 600;
            margin-bottom: 2px;
            font-size: 1.1rem;
        }

        .user-info-header p {
            margin-bottom: 2px;
            font-size: 0.95em;
            color: #555;
        }

        .asset-form-outer-container {
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            padding: 15px;
            margin-top: 15px;
            background-color: #fdfdfd;
        }

        .asset-form-header {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
            border-bottom: 1px solid #eee;
            padding-bottom: 8px;
        }

        .asset-form-header .checkbox-transfer {
            margin-right: 10px;
            transform: scale(1.2);
        }

        .asset-form-header h6 {
            font-weight: 600;
            color: #007bff;
            margin-bottom: 0;
            flex-grow: 1;
        }

        .form-label-sm {
            font-size: 0.85em;
            margin-bottom: 0.2rem !important;
            color: #454545;
        }

        .form-control-sm,
        .form-select-sm {
            font-size: 0.85em;
            padding: 0.3rem 0.6rem;
        }

        .action-buttons {
            margin-top: 15px;
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 10px;
        }

        .card.search-card {
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            border: none;
        }

        #listaActivosTrasladar {
            list-style-type: disc;
            padding-left: 20px;
            max-height: 150px;
            overflow-y: auto;
            font-size: 0.9em;
        }

        #listaActivosTrasladar li {
            margin-bottom: 3px;
        }

        .activo-enfocado {
            border: 2px solid #0d6efd !important;
            box-shadow: 0 0 15px rgba(13, 110, 253, 0.5) !important;
            animation: pulse-border 1.5s infinite;
        }

        @keyframes pulse-border {
            0% {
                box-shadow: 0 0 0 0 rgba(13, 110, 253, 0.7);
            }

            70% {
                box-shadow: 0 0 0 10px rgba(13, 110, 253, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(13, 110, 253, 0);
            }
        }
    </style>
</head>

<body>
<div class="top-bar-custom">
        <div class="logo-container-top">
            <a href="menu.php" title="Ir a Inicio">
                <img src="imagenes/logo.png" alt="Logo ARPESOD ASOCIADOS SAS">
            </a>
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
            <h3 class="page-title mb-4 text-center">Administrar Activos (Operativos)</h3>
            <?php if ($mensaje) echo "<div id='mensaje-global-container' class='mb-3'>$mensaje</div>"; ?>

            <form method="get" class="row g-3 mb-4 align-items-end" action="editar.php">
                <div class="col-md-4"> <label for="cedula_buscar" class="form-label">Buscar por Cédula</label>
                    <input type="text" class="form-control" id="cedula_buscar" name="cedula" value="<?= htmlspecialchars($cedula_buscada) ?>" placeholder="Ingrese cédula">
                </div>
                <div class="col-md-3"> <label for="regional_buscar" class="form-label">Filtrar por Regional</label>
                    <select name="regional" class="form-select" id="regional_buscar">
                        <option value="">-- Todas las Regionales --</option>
                        <?php foreach ($regionales as $r): ?>
                            <option value="<?= htmlspecialchars($r) ?>" <?= ($r == $regional_buscada) ? 'selected' : '' ?>><?= htmlspecialchars($r) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3"> <label for="empresa_buscar" class="form-label">Filtrar por Empresa</label>
                    <select name="empresa" class="form-select" id="empresa_buscar">
                        <option value="">-- Todas las Empresas --</option>
                        <?php foreach ($empresas_disponibles as $e): ?>
                            <option value="<?= htmlspecialchars($e) ?>" <?= ($e == $empresa_buscada) ? 'selected' : '' ?>><?= htmlspecialchars($e) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-custom-search w-100">Buscar</button>
                </div>
            </form>
        </div>

        <?php if ($criterio_buscada_activo): ?>
            <div class="mt-4">
                <?php if (isset($stmt_select) && !$stmt_select && $conexion && !empty($conexion->error) && !isset($_POST['action'])): ?>
                    <div class='alert alert-danger'>Error al preparar la consulta de búsqueda: <?= htmlspecialchars($conexion->error) ?></div>
                    <?php elseif (!empty($activos_encontrados)) :
                    $asset_forms_data = [];
                    foreach ($activos_encontrados as $activo_map) { // Renombrado $activo a $activo_map para evitar colisión con $activo_individual
                        $info_key = $activo_map['cedula'];
                        if (!isset($asset_forms_data[$info_key]['info'])) {
                            $asset_forms_data[$info_key]['info'] = [
                                'nombre' => $activo_map['nombre'],
                                'cargo' => $activo_map['cargo'],
                                'cedula' => $activo_map['cedula'],
                                'empresa_responsable' => $activo_map['Empresa'] ?? ($activo_map['empresa'] ?? ''),
                                'regional' => $activo_map['regional'] // <<< LÍNEA AÑADIDA
                            ];
                        }
                        $asset_forms_data[$info_key]['activos'][] = $activo_map;
                    }

                    foreach ($asset_forms_data as $cedula_grupo => $data_grupo):
                        $btn_acta_text = 'Generar Acta de Entrega';
                        $acta_url_params = "cedula=" . urlencode($cedula_grupo) . "&tipo_acta=entrega";
                        if (!empty($data_grupo['info']['empresa_responsable'])) { // Añadir empresa a los parámetros del acta si existe
                            $acta_url_params .= "&empresa=" . urlencode($data_grupo['info']['empresa_responsable']);
                        }
                    ?>
                        <div class="user-asset-group mt-4" id="user-group-<?= htmlspecialchars($cedula_grupo) ?>">
                            <div class="user-info-header">
                                <div class="info-block">
                                    <h4><?= htmlspecialchars($data_grupo['info']['nombre']) ?> <small class="text-muted">(C.C: <?= htmlspecialchars($data_grupo['info']['cedula']) ?>)</small></h4>
                                    <p><strong>Cargo:</strong> <?= htmlspecialchars($data_grupo['info']['cargo']) ?>
                                        <?php if (!empty($data_grupo['info']['empresa_responsable'])): ?>
                                            | <strong>Empresa:</strong> <?= htmlspecialchars($data_grupo['info']['empresa_responsable']) ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <div class="actions-block">
                                    <?php if (tiene_permiso_para('trasladar_activo')): ?>
                                        <button type="button" class="btn btn-sm btn-outline-primary btn-transfer-modal"
                                            data-bs-toggle="modal"
                                            data-bs-target="#trasladoModal"
                                            data-cedula-origen="<?= htmlspecialchars($data_grupo['info']['cedula']) ?>"
                                            data-nombre-origen="<?= htmlspecialchars($data_grupo['info']['nombre']) ?>"
                                            data-empresa-origen="<?= htmlspecialchars($data_grupo['info']['empresa_responsable'] ?? '') ?>"
                                            data-regional-origen="<?= htmlspecialchars($data_grupo['info']['regional'] ?? '') ?>">
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
                                            <input type="checkbox" data-asset-id="<?= $activo_individual['id'] ?>" class="form-check-input checkbox-transfer" title="Seleccionar para trasladar" data-tipo-activo="<?= htmlspecialchars($activo_individual['tipo_activo']) ?>" data-serie-activo="<?= htmlspecialchars($activo_individual['serie']) ?>">
                                        <?php endif; ?>
                                        <h6> Activo #<?= ($index + 1) ?>: <?= htmlspecialchars($activo_individual['tipo_activo']) ?> - Serie: <?= htmlspecialchars($activo_individual['serie']) ?> (Empresa: <?= htmlspecialchars($activo_individual['Empresa'] ?? ($activo_individual['empresa'] ?? 'N/A')) ?>)</h6>
                                    </div>
                                    <form method="post" action="editar.php" id="form-activo-<?= htmlspecialchars($form_id_individual_activo) ?>">
                                        <input type="hidden" name="id" value="<?= $activo_individual['id'] ?>">
                                        <input type="hidden" name="cedula_original_busqueda" value="<?= htmlspecialchars($cedula_buscada) ?>">
                                        <input type="hidden" name="regional_original_busqueda" value="<?= htmlspecialchars($regional_buscada) ?>">
                                        <input type="hidden" name="empresa_original_busqueda" value="<?= htmlspecialchars($empresa_buscada) ?>">

                                        <div class="row">
                                            <?php
                                            input_editable('nombre_usuario_display', 'Usuario Actual', $activo_individual['nombre'], $form_id_individual_activo, 'text', true);
                                            input_editable('cargo_usuario_display', 'Cargo Actual', $activo_individual['cargo'], $form_id_individual_activo, 'text', true);
                                            // La empresa del activo individual no se edita aquí, se muestra en el encabezado del activo.
                                            // Si se quisiera editar, se añadiría un select_editable para 'Empresa' aquí.
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
                    endforeach; // Fin foreach ($asset_forms_data as ...)
                    ?>
                <?php elseif ($criterio_buscada_activo && empty($activos_encontrados)) : ?>
                    <div class="alert alert-info mt-3">No se encontraron activos (operativos) con los criterios de búsqueda especificados.</div>
                <?php endif; ?>
            </div>
        <?php endif; // Fin if ($criterio_buscada_activo) 
        ?>
    </div> <?php if (tiene_permiso_para('trasladar_activo')): ?>
        <div class="modal fade" id="trasladoModal" tabindex="-1" aria-labelledby="trasladoModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="trasladoModalLabel">Trasladar Activos Seleccionados</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 border-end">
                                <p>Del usuario: <strong id="nombreUsuarioOrigenTrasladoModal"></strong><br>
                                    C.C: <strong id="cedulaUsuarioOrigenTrasladoModal"></strong><br>
                                    Empresa Origen: <strong id="empresaUsuarioOrigenTrasladoModal"></strong><br>
                                    Regional Origen: <strong id="regionalUsuarioOrigenTrasladoModal"></strong> </p>
                                <h6>Activos Seleccionados para Traslado:</h6>
                                <ul id="listaActivosTrasladar" class="mb-3"></ul>
                            </div>
                            <div class="col-md-6">
                                <h6>Ingrese los datos del <strong>NUEVO</strong> responsable, su regional y empresa:</h6>
                                <div class="mb-2">
                                    <label for="nueva_cedula_traslado" class="form-label form-label-sm">Nueva Cédula</label>
                                    <input type="text" class="form-control form-control-sm" id="nueva_cedula_traslado" required>
                                </div>
                                <div class="mb-2">
                                    <label for="nuevo_nombre_traslado" class="form-label form-label-sm">Nuevo Nombre Completo</label>
                                    <input type="text" class="form-control form-control-sm" id="nuevo_nombre_traslado" required>
                                </div>
                                <div class="mb-2">
                                    <label for="nuevo_cargo_traslado" class="form-label form-label-sm">Nuevo Cargo</label>
                                    <input type="text" class="form-control form-control-sm" id="nuevo_cargo_traslado" required>
                                </div>
                                <div class="mb-2">
                                    <label for="nueva_regional_traslado" class="form-label form-label-sm">Nueva Regional (del activo)</label>
                                    <select class="form-select form-select-sm" id="nueva_regional_traslado" required>
                                        <option value="">Seleccione Regional...</option>
                                        <?php foreach ($regionales as $r): ?>
                                            <option value="<?= htmlspecialchars($r) ?>"><?= htmlspecialchars($r) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-2"> {/* <<< Nuevo campo para Empresa en Traslado */}
                                        <label for="nueva_empresa_traslado" class="form-label form-label-sm">Nueva Empresa (del activo)</label>
                                        <select class="form-select form-select-sm" id="nueva_empresa_traslado" required>
                                            <option value="">Seleccione Empresa...</option>
                                            <?php foreach ($empresas_disponibles as $e_option): ?>
                                                <option value="<?= htmlspecialchars($e_option) ?>"><?= htmlspecialchars($e_option) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="button" class="btn btn-sm btn-primary" id="confirmarTrasladoBtn">Confirmar Traslado</button>
                    </div>
                </div>
            </div>
        </div>
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
                            <input type="hidden" name="cedula_original_busqueda" id="cedulaOriginalBusquedaBajaModal" value="<?= htmlspecialchars($cedula_buscada) ?>">
                            <input type="hidden" name="regional_original_busqueda" id="regionalOriginalBusquedaBajaModal" value="<?= htmlspecialchars($regional_buscada) ?>">
                            <input type="hidden" name="empresa_original_busqueda" id="empresaOriginalBusquedaBajaModal" value="<?= htmlspecialchars($empresa_buscada) ?>">

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
            // ... (tu función habilitarCamposActivo sin cambios)
            const formId = 'form-activo-' + formSuffix;
            const form = document.getElementById(formId);
            if (!form) {
                console.error('Formulario NO encontrado:', formId);
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
            const firstEditableField = form.querySelector('select[name="tipo_activo"]');
            if (firstEditableField && !firstEditableField.hasAttribute('disabled')) {
                firstEditableField.focus();
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            <?php if (tiene_permiso_para('trasladar_activo')): ?>
                var trasladoModalEl = document.getElementById('trasladoModal');
                if (trasladoModalEl) {
                    var bsTrasladoModal = new bootstrap.Modal(trasladoModalEl);
                    var cedulaOrigenActualParaModal = ''; // Se usará para encontrar el userGroupDiv

                    trasladoModalEl.addEventListener('show.bs.modal', function(event) {
                        var button = event.relatedTarget; // El botón que abrió el modal

                        // --- Obtener datos de los atributos del botón (esto ya lo tenías bien) ---
                        cedulaOrigenActualParaModal = button.getAttribute('data-cedula-origen');
                        let nombreOrigen = button.getAttribute('data-nombre-origen');
                        let empresaOrigen = button.getAttribute('data-empresa-origen');
                        let regionalOrigen = button.getAttribute('data-regional-origen');

                        // --- Poblar los datos del usuario origen en el modal ---
                        document.getElementById('nombreUsuarioOrigenTrasladoModal').textContent = nombreOrigen || 'N/A';
                        document.getElementById('cedulaUsuarioOrigenTrasladoModal').textContent = cedulaOrigenActualParaModal || 'N/A';
                        document.getElementById('empresaUsuarioOrigenTrasladoModal').textContent = empresaOrigen || 'N/A';
                        document.getElementById('regionalUsuarioOrigenTrasladoModal').textContent = regionalOrigen || 'N/A';

                        // --- LA MEJORA PRINCIPAL: Encontrar el contenedor usando .closest() ---
                        const userGroupDiv = button.closest('.user-asset-group');
                        const listaActivosUl = document.getElementById('listaActivosTrasladar');
                        listaActivosUl.innerHTML = ''; // Limpiar lista previa

                        if (userGroupDiv) {
                            // Ahora la búsqueda de checkboxes se hace dentro del contenedor encontrado
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
                                // Este es el mensaje que estabas viendo. Ahora solo debería aparecer si de verdad no hay nada seleccionado.
                                listaActivosUl.innerHTML = "<li class='text-danger'>Ningún activo seleccionado. Por favor, marque la casilla de los activos que desea trasladar.</li>";
                            }
                        } else {
                            listaActivosUl.innerHTML = "<li class='text-danger'>Error: No se pudo identificar el grupo de activos del usuario.</li>";
                            console.error("Error: no se encontró un ancestro '.user-asset-group' para el botón presionado.", button);
                        }
                        // Limpiar campos del nuevo responsable
                        document.getElementById('nueva_cedula_traslado').value = '';
                        document.getElementById('nuevo_nombre_traslado').value = '';
                        document.getElementById('nuevo_cargo_traslado').value = '';
                        document.getElementById('nueva_regional_traslado').value = '';
                        document.getElementById('nueva_empresa_traslado').value = '';

                        const inputNuevaCedula = document.getElementById('nueva_cedula_traslado');
                        if (inputNuevaCedula) { // Verificar que el input exista
                            inputNuevaCedula.onkeyup = function() {
                                const cedulaIngresada = this.value.trim();
                                if (cedulaIngresada.length >= 5) { // O la longitud que consideres para buscar
                                    // Asegúrate que la ruta a buscar_datos_usuario.php sea correcta
                                    fetch(`buscar_datos_usuario.php?cedula=${encodeURIComponent(cedulaIngresada)}`)
                                        .then(response => {
                                            if (!response.ok) {
                                                throw new Error(`Error HTTP: ${response.status}`);
                                            }
                                            return response.json();
                                        })
                                        .then(data => {
                                            if (data.encontrado) {
                                                document.getElementById('nuevo_nombre_traslado').value = data.nombre_completo || '';
                                                document.getElementById('nuevo_cargo_traslado').value = data.cargo || '';
                                                document.getElementById('nueva_regional_traslado').value = data.regional || '';
                                                document.getElementById('nueva_empresa_traslado').value = data.empresa || '';
                                            } else {
                                                // Opcional: Limpiar campos si no se encuentra, excepto la cédula
                                                document.getElementById('nuevo_nombre_traslado').value = '';
                                                document.getElementById('nuevo_cargo_traslado').value = '';
                                                document.getElementById('nueva_regional_traslado').value = '';
                                                document.getElementById('nueva_empresa_traslado').value = '';
                                            }
                                        })
                                        .catch(error => {
                                            console.error('Error al buscar datos del usuario (fetch):', error);
                                            // Limpiar campos en caso de error de red o JSON malformado
                                            document.getElementById('nuevo_nombre_traslado').value = '';
                                            document.getElementById('nuevo_cargo_traslado').value = '';
                                            document.getElementById('nueva_regional_traslado').value = '';
                                            document.getElementById('nueva_empresa_traslado').value = '';
                                        });
                                }
                            };
                        }
                    });

                    document.getElementById('confirmarTrasladoBtn').addEventListener('click', function() {
                        let fd = new FormData();
                        // Añadir los filtros actuales para la recarga
                        fd.append('cedula_original_busqueda', document.getElementById('cedula_buscar').value);
                        fd.append('regional_original_busqueda', document.getElementById('regional_buscar').value);
                        fd.append('empresa_original_busqueda', document.getElementById('empresa_buscar').value); // <<< Añadir empresa_buscar

                        fd.append('cedula_usuario_origen_hidden', cedulaOrigenActualParaModal);
                        fd.append('action', 'confirmar_traslado_masivo');

                        const nCed = document.getElementById('nueva_cedula_traslado').value.trim();
                        const nNom = document.getElementById('nuevo_nombre_traslado').value.trim();
                        const nCar = document.getElementById('nuevo_cargo_traslado').value.trim();
                        const nReg = document.getElementById('nueva_regional_traslado').value;
                        const nEmp = document.getElementById('nueva_empresa_traslado').value; // <<< Obtener nueva empresa

                        if (!nCed || !nNom || !nCar || !nReg || !nEmp) {
                            alert('Datos del nuevo responsable o empresa incompletos.');
                            return;
                        } // <<< Añadir nEmp a la validación

                        const chkSel = document.getElementById('user-group-' + cedulaOrigenActualParaModal).querySelectorAll('.checkbox-transfer:checked');
                        if (chkSel.length === 0) {
                            alert('No ha seleccionado activos.');
                            bsTrasladoModal.hide();
                            return;
                        }
                        let idsSel = [];
                        chkSel.forEach(cb => idsSel.push(cb.getAttribute('data-asset-id') || cb.value));
                        fd.append('ids_activos_seleccionados_traslado', idsSel.join(','));
                        fd.append('nueva_cedula_traslado', nCed);
                        fd.append('nuevo_nombre_traslado', nNom);
                        fd.append('nuevo_cargo_traslado', nCar);
                        fd.append('nueva_regional_traslado', nReg);
                        fd.append('nueva_empresa_traslado', nEmp); // <<< Enviar nueva empresa al backend

                        bsTrasladoModal.hide();
                        fetch('editar.php', {
                            method: 'POST',
                            body: fd
                        }).then(r => r.json()).then(d => {
                            let msgDiv = document.getElementById('mensaje-global-container');
                            if (!msgDiv) {
                                msgDiv = document.createElement('div');
                                msgDiv.id = 'mensaje-global-container';
                                document.querySelector('.card.search-card').insertAdjacentElement('afterend', msgDiv);
                            }
                            msgDiv.innerHTML = `<div class='alert ${d.success?'alert-success':'alert-danger'} alert-dismissible fade show mt-3'>${d.message}<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>`;
                            if (d.success) {
                                if (d.data_acta && confirm('Traslado exitoso. ¿Generar Acta de Traslado?')) {
                                    // ... (tu código para generar acta, asegúrate que incluya la nueva empresa si es necesario) ...
                                }
                                // Recargar la página manteniendo los filtros
                                setTimeout(() => {
                                    const cedulaF = encodeURIComponent(document.getElementById('cedula_buscar').value || '');
                                    const regionalF = encodeURIComponent(document.getElementById('regional_buscar').value || '');
                                    const empresaF = encodeURIComponent(document.getElementById('empresa_buscar').value || ''); // <<< Obtener filtro empresa
                                    window.location.href = `editar.php?cedula=${cedulaF}&regional=${regionalF}&empresa=${empresaF}`; // <<< Añadir empresa a la URL de recarga
                                }, 1500);
                            }
                        }).catch(e => {
                            console.error('Error:', e);
                            alert('Error procesando traslado.');
                        });
                    });
                }
            <?php endif; ?>

            <?php if (tiene_permiso_para('dar_baja_activo')): ?>
                var modalDarBajaEl = document.getElementById('modalDarBaja');
                if (modalDarBajaEl) {
                    modalDarBajaEl.addEventListener('show.bs.modal', function(event) {
                        // ... (tu código del modal de baja) ...
                        // Asegurarse que los campos ocultos para la búsqueda se llenen correctamente
                        document.getElementById('cedulaOriginalBusquedaBajaModal').value = document.getElementById('cedula_buscar').value;
                        document.getElementById('regionalOriginalBusquedaBajaModal').value = document.getElementById('regional_buscar').value;
                        document.getElementById('empresaOriginalBusquedaBajaModal').value = document.getElementById('empresa_buscar').value; // <<< Llenar empresa
                    });
                }
            <?php endif; ?>

            const urlParams = new URLSearchParams(window.location.search);
            const idActivoFocus = urlParams.get('id_activo_focus');
            if (idActivoFocus) {
                // ... (tu código de scroll al activo enfocado) ...
            }
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>