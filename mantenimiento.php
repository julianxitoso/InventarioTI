<?php
session_start(); // Es buena práctica tenerlo al inicio si vas a usar sesiones
require_once 'backend/auth_check.php';
restringir_acceso_pagina(['admin', 'tecnico']);

require_once 'backend/db.php';
require_once 'backend/historial_helper.php';

if (!defined('HISTORIAL_TIPO_MANTENIMIENTO')) define('HISTORIAL_TIPO_MANTENIMIENTO', 'MANTENIMIENTO');
if (!defined('HISTORIAL_TIPO_BAJA')) define('HISTORIAL_TIPO_BAJA', 'BAJA');
if (!defined('HISTORIAL_TIPO_ELIMINACION_FISICA')) define('HISTORIAL_TIPO_ELIMINACION_FISICA', 'ELIMINACIÓN FÍSICA');


$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Usuario';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'Desconocido';
$usuario_actual_sistema_para_historial = $_SESSION['usuario_login'] ?? 'Sistema';

if (!isset($conexion)) { 
    if (isset($conn)) $conexion = $conn;
    else die("Variable de conexión no definida.");
}

if (!$conexion || (method_exists($conexion, 'connect_error') && $conexion->connect_error)) {
    error_log("Error de conexión a la base de datos en mantenimiento.php: " . ($conexion->connect_error ?? 'Error desconocido'));
    die("Error crítico de conexión a la base de datos. Por favor, contacte al administrador.");
}
$conexion->set_charset("utf8mb4");

$mensaje = "";
$error_mensaje = "";
$serie_buscada = "";
$activo_encontrado = null;
$id_activo_encontrado_js = 'null';
$serie_activo_encontrado_js = '';

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

$proveedores = [];
$result_proveedores = $conexion->query("SELECT id, nombre_proveedor FROM proveedores_mantenimiento ORDER BY nombre_proveedor ASC");
if ($result_proveedores) {
    while ($row = $result_proveedores->fetch_assoc()) { $proveedores[] = $row; }
} else { error_log("Error al obtener proveedores: " . $conexion->error); }

$tecnicos_internos = [];
$sql_tecnicos = "SELECT id, usuario, nombre_completo, rol FROM usuarios WHERE rol IN ('admin', 'tecnico') ORDER BY nombre_completo ASC";
$result_tecnicos = $conexion->query($sql_tecnicos);
if ($result_tecnicos) {
    while ($row = $result_tecnicos->fetch_assoc()) { $tecnicos_internos[] = $row; }
} else { error_log("Error al obtener técnicos internos: " . $conexion->error); }

function fetch_activo_completo($db_conn, $serie_o_id, $es_id = false) {
    $sql = "SELECT 
                a.id, 
                ta.nombre_tipo_activo, 
                a.marca, 
                a.serie, 
                u.nombre_completo AS nombre_responsable, 
                u.usuario AS cedula_responsable, 
                u.empresa AS Empresa_responsable,      -- Empresa del usuario responsable
                u.regional AS regional_del_responsable, -- Regional del usuario responsable
                a.estado AS estado_actual,
                a.valor_aproximado,
                a.fecha_compra,
                a.Codigo_Inv,
                a.detalles
                -- Si activos_tecnologicos AÚN tiene sus propias columnas regional y Empresa para el activo:
                -- , a.regional AS regional_propia_activo
                -- , a.Empresa AS empresa_propia_activo 
                -- Añade aquí CUALQUIER OTRO CAMPO de la tabla 'a' (activos_tecnologicos) que necesites
            FROM 
                activos_tecnologicos a
            LEFT JOIN 
                usuarios u ON a.id_usuario_responsable = u.id
            LEFT JOIN 
                tipos_activo ta ON a.id_tipo_activo = ta.id_tipo_activo
            WHERE ";
    $sql .= $es_id ? "a.id = ?" : "a.serie = ?";
    
    $stmt = $db_conn->prepare($sql);
    if ($stmt) {
        if($es_id) $stmt->bind_param("i", $serie_o_id);
        else $stmt->bind_param("s", $serie_o_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();
        return $data;
    }
    error_log("Error al preparar consulta en fetch_activo_completo: " . $db_conn->error . " SQL: " . $sql);
    return null;
}


if (isset($_GET['buscar_activo_serie']) || isset($_GET['serie_buscada'])) {
    $serie_buscada_param = trim($_GET['serie_buscada'] ?? '');
    if (!empty($serie_buscada_param)) {
        $serie_buscada = $serie_buscada_param;
        $activo_encontrado = fetch_activo_completo($conexion, $serie_buscada, false);

        if ($activo_encontrado) {
            if ($activo_encontrado['estado_actual'] === 'Dado de Baja') {
                $error_mensaje = "El activo con la serie '" . htmlspecialchars($serie_buscada) . "' ya se encuentra 'Dado de Baja'. No se pueden registrar nuevos mantenimientos.";
                $activo_encontrado = null; 
            } else {
                $id_activo_encontrado_js = $activo_encontrado['id'];
                $serie_activo_encontrado_js = $activo_encontrado['serie'];
            }
        } else {
            $error_mensaje = "No se encontró ningún activo con la serie '" . htmlspecialchars($serie_buscada) . "'.";
        }
    } elseif (isset($_GET['buscar_activo_serie'])) { 
        $error_mensaje = "Por favor, ingrese un número de serie para buscar.";
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $serie_buscada_original_post = ""; // Para mantener el contexto de la serie
    if (isset($_POST['serie_buscada_original_post_mantenimiento'])) {
        $serie_buscada_original_post = trim($_POST['serie_buscada_original_post_mantenimiento']);
    } elseif (isset($_POST['serie_buscada_original_post_baja'])) {
        $serie_buscada_original_post = trim($_POST['serie_buscada_original_post_baja']);
    }
    $serie_buscada = $serie_buscada_original_post; // Actualizar $serie_buscada para recarga


    if (isset($_POST['guardar_mantenimiento'])) {
        $id_activo = filter_input(INPUT_POST, 'id_activo_mantenimiento', FILTER_VALIDATE_INT);
        $fecha_reparacion = trim($_POST['fecha_reparacion'] ?? '');
        $diagnostico = trim($_POST['diagnostico'] ?? '');
        $detalle_reparacion = trim($_POST['detalle_reparacion'] ?? '');
        $costo_reparacion_str = trim($_POST['costo_reparacion'] ?? '0');
        $proveedor_id = filter_input(INPUT_POST, 'proveedor_id', FILTER_VALIDATE_INT);
        if (empty($proveedor_id)) $proveedor_id = null;
        
        $tecnico_interno_id_usuario = filter_input(INPUT_POST, 'tecnico_interno_id', FILTER_VALIDATE_INT);
        if (empty($tecnico_interno_id_usuario)) $tecnico_interno_id_usuario = null;
        
        $estado_post_mantenimiento = trim($_POST['estado_post_mantenimiento'] ?? '');

        if (!$id_activo) { $error_mensaje = "ID de activo no válido."; }
        elseif (empty($fecha_reparacion)) { $error_mensaje = "La fecha de reparación es obligatoria."; }
        elseif (empty($diagnostico)) { $error_mensaje = "El diagnóstico es obligatorio."; }
        elseif (empty($detalle_reparacion)) { $error_mensaje = "El detalle de la reparación es obligatorio."; }
        elseif (!is_numeric($costo_reparacion_str) || floatval($costo_reparacion_str) < 0) { $error_mensaje = "El costo de reparación debe ser un número válido y no negativo.";}
        else {
            $costo_reparacion = floatval($costo_reparacion_str);
            $nombre_proveedor = "N/A";
            if ($proveedor_id) {
                $stmt_prov = $conexion->prepare("SELECT nombre_proveedor FROM proveedores_mantenimiento WHERE id = ?");
                if($stmt_prov){ $stmt_prov->bind_param("i", $proveedor_id); $stmt_prov->execute(); $res_prov = $stmt_prov->get_result(); if ($row_prov = $res_prov->fetch_assoc()) { $nombre_proveedor = $row_prov['nombre_proveedor']; } $stmt_prov->close(); }
            }
            $nombre_tecnico_interno = "N/A";
            if ($tecnico_interno_id_usuario) { // Usa el ID numérico del usuario
                $stmt_tec = $conexion->prepare("SELECT nombre_completo, rol FROM usuarios WHERE id = ?"); 
                if($stmt_tec){ 
                    $stmt_tec->bind_param("i", $tecnico_interno_id_usuario); 
                    $stmt_tec->execute(); 
                    $res_tec = $stmt_tec->get_result(); 
                    if ($row_tec = $res_tec->fetch_assoc()) { 
                        $nombre_tecnico_interno = $row_tec['nombre_completo'] . " (".ucfirst($row_tec['rol']).")"; 
                    } 
                    $stmt_tec->close(); 
                }
            }

            $conexion->begin_transaction();
            try {
                $descripcion_historial = "Mantenimiento realizado. Diagnóstico: " . htmlspecialchars($diagnostico) . ".";
                if ($costo_reparacion > 0) $descripcion_historial .= " Costo: $" . number_format($costo_reparacion, 0, ',', '.') . ".";
                if ($nombre_proveedor !== "N/A") $descripcion_historial .= " Proveedor: " . htmlspecialchars($nombre_proveedor) . ".";
                if ($nombre_tecnico_interno !== "N/A") $descripcion_historial .= " Téc. Interno: " . htmlspecialchars($nombre_tecnico_interno) . ".";
                if (!empty($estado_post_mantenimiento)) $descripcion_historial .= " Estado Post-Mant: " . htmlspecialchars($estado_post_mantenimiento) . ".";
                
                $datos_mantenimiento = [
                    'fecha_reparacion' => $fecha_reparacion, 'diagnostico' => $diagnostico, 'detalle_reparacion' => $detalle_reparacion,
                    'costo_reparacion' => $costo_reparacion, 'proveedor_id' => $proveedor_id, 'nombre_proveedor' => $nombre_proveedor,
                    'tecnico_interno_id_usuario' => $tecnico_interno_id_usuario, 'nombre_tecnico_interno' => $nombre_tecnico_interno,
                    'estado_post_mantenimiento' => $estado_post_mantenimiento
                ];
                registrar_evento_historial($conexion, $id_activo, HISTORIAL_TIPO_MANTENIMIENTO, $descripcion_historial, $usuario_actual_sistema_para_historial, null, $datos_mantenimiento);

                if (!empty($estado_post_mantenimiento) && $estado_post_mantenimiento !== 'Dado de Baja') {
                    $stmt_update_estado = $conexion->prepare("UPDATE activos_tecnologicos SET estado = ? WHERE id = ?");
                    if($stmt_update_estado){ $stmt_update_estado->bind_param("si", $estado_post_mantenimiento, $id_activo); $stmt_update_estado->execute(); $stmt_update_estado->close(); }
                    else { throw new Exception("Error al preparar la actualización de estado del activo: " . $conexion->error); }
                }
                $conexion->commit();
                $mensaje = "Mantenimiento registrado exitosamente para el activo ID: " . htmlspecialchars($id_activo) . ".";
                // Limpiar para nueva búsqueda, a menos que el estado sea Malo
                if ($estado_post_mantenimiento !== 'Malo (No se pudo reparar)') {
                    // No limpiamos serie_buscada para la redirección
                    $activo_encontrado = null; $id_activo_encontrado_js = 'null'; $serie_activo_encontrado_js = '';
                } else {
                     // Si fue 'Malo', mantenemos el activo encontrado para el modal de baja
                    $activo_encontrado = fetch_activo_completo($conexion, $id_activo, true);
                    if($activo_encontrado){ $id_activo_encontrado_js = $activo_encontrado['id']; $serie_activo_encontrado_js = $activo_encontrado['serie'];}
                }
            } catch (Exception $e) {
                $conexion->rollback();
                $error_mensaje = "Error al registrar el mantenimiento: " . $e->getMessage();
                error_log("Error en registro de mantenimiento: " . $e->getMessage());
                 if ($id_activo) { 
                    $activo_encontrado = fetch_activo_completo($conexion, $id_activo, true);
                    if($activo_encontrado){ $id_activo_encontrado_js = $activo_encontrado['id']; $serie_activo_encontrado_js = $activo_encontrado['serie'];}
                 }
            }
        }
    } elseif (isset($_POST['submit_dar_baja_desde_mantenimiento'])) {
        if (!tiene_permiso_para('dar_baja_activo')) {
            $error_mensaje = "Acción no permitida para su rol.";
        } elseif (empty($_POST['id_activo_baja_mantenimiento']) || empty($_POST['motivo_baja_mantenimiento'])) {
            $error_mensaje = "Faltan datos para dar de baja el activo (ID o Motivo).";
        } else {
            $id_activo_baja = filter_input(INPUT_POST, 'id_activo_baja_mantenimiento', FILTER_VALIDATE_INT);
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

                        $descripcion_hist_baja = "Activo Dado de Baja desde Mantenimiento. Tipo: " . htmlspecialchars($tipo_activo_baja) . ", Serie: " . htmlspecialchars($serie_baja_hist) . ". Motivo: " . htmlspecialchars($motivo_baja) . ".";
                        if (!empty($observaciones_baja)) { $descripcion_hist_baja .= " Observaciones: " . htmlspecialchars($observaciones_baja); }
                        $descripcion_hist_baja .= " (Responsable anterior: " . htmlspecialchars($responsable_anterior_baja) . ", Empresa anterior (Resp.): " . htmlspecialchars($empresa_anterior_baja_usuario) . ")";
                        
                        $datos_contexto_baja = ['estado_anterior' => $datos_anteriores_del_activo['estado_actual'], 'motivo_baja' => $motivo_baja, 'observaciones_baja' => $observaciones_baja, 'fecha_efectiva_baja' => date('Y-m-d H:i:s'), 'originado_desde' => 'modulo_mantenimiento'];
                        registrar_evento_historial($conexion, $id_activo_baja, HISTORIAL_TIPO_BAJA, $descripcion_hist_baja, $usuario_actual_sistema_para_historial, $datos_anteriores_del_activo, $datos_contexto_baja);
                        $conexion->commit();
                        $mensaje = "Activo ID: " . htmlspecialchars($id_activo_baja) . " dado de baja exitosamente.";
                        $activo_encontrado = null; $id_activo_encontrado_js = 'null'; $serie_activo_encontrado_js = ''; // Limpiar para nueva búsqueda
                        $serie_buscada = ""; // Limpiar la serie buscada para que no se recargue el formulario con el activo recién dado de baja
                    } else {
                        $stmt_baja->close();
                        throw new Exception("El activo no fue actualizado (posiblemente ya estaba dado de baja o el ID no existe).");
                    }
                } catch (Exception $e) {
                    $conexion->rollback();
                    $error_mensaje = "Error al dar de baja: " . $e->getMessage();
                    error_log("Error en dar de baja desde mantenimiento: " . $e->getMessage());
                }
            }
        }
    }
    // --- Redirección después de una acción POST para limpiar el estado POST y mostrar mensajes ---
    if ($mensaje || $error_mensaje) {
        $_SESSION['mantenimiento_mensaje'] = $mensaje;
        $_SESSION['mantenimiento_error_mensaje'] = $error_mensaje;
    }
    $redirect_url = "mantenimiento.php";
    // Si se estaba mostrando un activo y no se dio de baja (o fue "Malo no reparable"), mantener la serie en URL
    if (!empty($serie_buscada_original_post) && ($activo_encontrado || (isset($_POST['guardar_mantenimiento']) && ($_POST['estado_post_mantenimiento'] ?? '') === 'Malo (No se pudo reparar)'))) {
         $redirect_url .= "?serie_buscada=" . urlencode($serie_buscada_original_post);
    }
    header("Location: " . $redirect_url);
    exit;
}

// Leer mensajes de sesión si existen
if (isset($_SESSION['mantenimiento_mensaje'])) {
    $mensaje = $_SESSION['mantenimiento_mensaje'];
    unset($_SESSION['mantenimiento_mensaje']);
}
if (isset($_SESSION['mantenimiento_error_mensaje'])) {
    $error_mensaje = $_SESSION['mantenimiento_error_mensaje'];
    unset($_SESSION['mantenimiento_error_mensaje']);
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Registrar Mantenimiento de Activos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f4f7f6; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding-top: 80px; }
        .top-bar-custom { position: fixed; top: 0; left: 0; right: 0; z-index: 1030; display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 1.5rem; background-color: #ffffff; border-bottom: 1px solid #e0e0e0; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .logo-container-top img { height: 75px; }
        .user-info-top { font-size: 0.9rem; }
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

    <div class="container mt-4">
        <h3 class="mb-4 text-center page-header-title">Registrar Mantenimiento de Activo</h3>

        <?php if ($mensaje): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert"><?= $mensaje ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>
        <?php endif; ?>
        <?php if ($error_mensaje): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert"><?= htmlspecialchars($error_mensaje) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>
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

        <?php if ($activo_encontrado && $activo_encontrado['estado_actual'] !== 'Dado de Baja'): ?>
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
                            
                            <?php 
                            // Para mostrar regional y empresa PROPIAS DEL ACTIVO, si esas columnas aún existen en activos_tecnologicos
                            // y se seleccionaron en fetch_activo_completo con los alias 'regional_propia_activo' y 'empresa_propia_activo'
                            $regional_propia_activo = $activo_encontrado['regional_propia_activo'] ?? null; 
                            $empresa_propia_activo = $activo_encontrado['empresa_propia_activo'] ?? null; 
                            ?>

                            <?php if (!empty($regional_propia_activo)): ?>
                                <dt class="col-sm-5 text-md-end">Regional (Activo):</dt>
                                <dd class="col-sm-7"><?= htmlspecialchars($regional_propia_activo) ?></dd>
                            <?php endif; ?>

                            <?php if (!empty($empresa_propia_activo)): ?>
                                <dt class="col-sm-5 text-md-end">Empresa (Activo):</dt>
                                <dd class="col-sm-7"><?= htmlspecialchars($empresa_propia_activo) ?></dd>
                            <?php endif; ?>
                        </dl>
                    </div>
                </div>
            </div>

            <div class="col-lg-8 mb-4">
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
                                    <label for="fecha_reparacion" class="form-label">Fecha de Reparación <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control form-control-sm" id="fecha_reparacion" name="fecha_reparacion" required value="<?= date('Y-m-d') ?>">
                                </div>
                                <div class="col-md-6">
                                    <label for="costo_reparacion" class="form-label">Costo de Reparación (COP) <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control form-control-sm" id="costo_reparacion" name="costo_reparacion" step="0.01" min="0" value="0" required>
                                </div>
                                <div class="col-md-12">
                                    <label for="diagnostico" class="form-label">Diagnóstico <span class="text-danger">*</span></label>
                                    <select class="form-select form-select-sm" id="diagnostico" name="diagnostico" required>
                                        <option value="">Seleccione un diagnóstico...</option>
                                        <?php foreach ($opciones_diagnostico as $diag): ?>
                                            <option value="<?= htmlspecialchars($diag) ?>"><?= htmlspecialchars($diag) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-12">
                                    <label for="detalle_reparacion" class="form-label">Detalle de la Reparación / Trabajo Realizado <span class="text-danger">*</span></label>
                                    <textarea class="form-control form-control-sm" id="detalle_reparacion" name="detalle_reparacion" rows="3" required></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label for="proveedor_id" class="form-label">Proveedor del Mantenimiento</label>
                                    <select class="form-select form-select-sm" id="proveedor_id" name="proveedor_id">
                                        <option value="">Mantenimiento Interno</option>
                                        <?php foreach ($proveedores as $prov): ?>
                                            <option value="<?= htmlspecialchars($prov['id']) ?>"><?= htmlspecialchars($prov['nombre_proveedor']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="tecnico_interno_id" class="form-label">Técnico Interno Responsable</label>
                                    <select class="form-select form-select-sm" id="tecnico_interno_id" name="tecnico_interno_id">
                                        <option value="">Proveedor Externo</option>
                                        <?php foreach ($tecnicos_internos as $tec): ?>
                                            <?php if (isset($tec['id']) && isset($tec['nombre_completo']) && isset($tec['rol'])): ?>
                                                <option value="<?= htmlspecialchars((string)$tec['id']) ?>">
                                                    <?= htmlspecialchars((string)$tec['nombre_completo']) ?> (<?= htmlspecialchars(ucfirst($tec['rol'])) ?>)
                                                </option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                 <div class="col-md-12">
                                    <label for="estado_post_mantenimiento" class="form-label">Estado del Activo Después del Mantenimiento</label>
                                    <select class="form-select form-select-sm" id="estado_post_mantenimiento" name="estado_post_mantenimiento">
                                        <option value="Bueno">Bueno</option>
                                        <option value="Regular">Regular</option>
                                        <option value="Malo (No se pudo reparar)">Malo (No se pudo reparar)</option>
                                        <option value="En Mantenimiento">En Mantenimiento</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="mt-4 d-flex justify-content-between align-items-center">
                                <button type="submit" name="guardar_mantenimiento" class="btn btn-success"><i class="bi bi-save"></i> Registrar Mantenimiento</button>
                                
                                <button type="button" id="btnAbrirModalBajaMantenimiento" class="btn btn-outline-danger" style="display:none;"
                                        data-bs-toggle="modal" data-bs-target="#modalDarBajaEnMantenimiento">
                                    <i class="bi bi-arrow-down-circle"></i> Iniciar Proceso de Baja
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php elseif ($activo_encontrado && $activo_encontrado['estado_actual'] === 'Dado de Baja'): ?>
            <div class="alert alert-warning mt-3 text-center">
                <i class="bi bi-exclamation-triangle-fill"></i> El activo con serie <strong><?= htmlspecialchars($activo_encontrado['serie']) ?></strong> ya se encuentra "Dado de Baja".<br>
                No se pueden registrar nuevos mantenimientos.
            </div>
        <?php elseif ((isset($_GET['buscar_activo_serie']) || !empty($serie_buscada)) && empty($activo_encontrado) && empty($error_mensaje)): ?>
             <div class="alert alert-warning mt-3 text-center">No se encontró ningún activo con la serie proporcionada.</div>
        <?php endif; ?>
    </div>

    <?php if (tiene_permiso_para('dar_baja_activo') && $activo_encontrado && $activo_encontrado['estado_actual'] !== 'Dado de Baja'): ?>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const selectEstadoPostMantenimiento = document.getElementById('estado_post_mantenimiento');
            const btnAbrirModalBaja = document.getElementById('btnAbrirModalBajaMantenimiento');
            
            const idActivoEncontradoJS = <?= json_encode($id_activo_encontrado_js); ?>;
            const serieActivoEncontradoJS = <?= json_encode($serie_activo_encontrado_js); ?>;
            const serieBuscadaOriginalJS = <?= json_encode($serie_buscada); ?>;

            if (selectEstadoPostMantenimiento && btnAbrirModalBaja) {
                function toggleBajaButton() {
                    if (selectEstadoPostMantenimiento.value === 'Malo (No se pudo reparar)') {
                        btnAbrirModalBaja.style.display = 'inline-block';
                    } else {
                        btnAbrirModalBaja.style.display = 'none';
                    }
                }
                selectEstadoPostMantenimiento.addEventListener('change', toggleBajaButton);
                if (idActivoEncontradoJS !== null && idActivoEncontradoJS !== 'null' && idActivoEncontradoJS !== 0) {
                    toggleBajaButton(); 
                }
            }

            var modalDarBajaMantenimientoEl = document.getElementById('modalDarBajaEnMantenimiento');
            if (modalDarBajaMantenimientoEl && (idActivoEncontradoJS !== null && idActivoEncontradoJS !== 'null' && idActivoEncontradoJS !== 0) ) {
                modalDarBajaMantenimientoEl.addEventListener('show.bs.modal', function(event) {
                    if (idActivoEncontradoJS && serieActivoEncontradoJS) {
                        document.getElementById('idActivoBajaModalMantenimiento').value = idActivoEncontradoJS;
                        document.getElementById('serieActivoBajaModalMantenimiento').textContent = serieActivoEncontradoJS;
                        document.getElementById('serieBuscadaOriginalPostBajaModal').value = serieBuscadaOriginalJS; 

                        const diagnosticoActualEl = document.getElementById('diagnostico');
                        const detalleActualEl = document.getElementById('detalle_reparacion');
                        const motivoBajaSelect = document.getElementById('motivo_baja_mantenimiento');
                        const observacionesBajaTextarea = document.getElementById('observaciones_baja_mantenimiento');
                        
                        const diagnosticoActual = diagnosticoActualEl ? diagnosticoActualEl.value : '';
                        const detalleActual = detalleActualEl ? detalleActualEl.value : '';

                        if (diagnosticoActual.toLowerCase().includes('irreparable') || diagnosticoActual.toLowerCase().includes('daño físico irreparable')) {
                            motivoBajaSelect.value = 'Daño irreparable (Confirmado post-mantenimiento)';
                        } else if (diagnosticoActual.toLowerCase().includes('fin de vida útil')) { 
                             motivoBajaSelect.value = 'Fin de vida útil';
                        } else {
                            motivoBajaSelect.value = 'Daño irreparable (Confirmado post-mantenimiento)'; // O un valor por defecto
                        }
                        
                        if(observacionesBajaTextarea) {
                            if(detalleActual) {
                                observacionesBajaTextarea.value = "Activo presenta '" + diagnosticoActual + "'. Detalle del intento de reparación: " + detalleActual;
                            } else {
                                observacionesBajaTextarea.value = "Activo presenta '" + diagnosticoActual + "'.";
                            }
                        }
                    }
                });
            }
        });
    </script>
</body>
</html>