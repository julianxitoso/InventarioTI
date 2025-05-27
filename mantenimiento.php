<?php
session_start();
require_once 'backend/auth_check.php';
restringir_acceso_pagina(['admin', 'tecnico']);

require_once 'backend/db.php';
require_once 'backend/historial_helper.php';

if (!defined('HISTORIAL_TIPO_MANTENIMIENTO')) define('HISTORIAL_TIPO_MANTENIMIENTO', 'MANTENIMIENTO');
if (!defined('HISTORIAL_TIPO_BAJA')) define('HISTORIAL_TIPO_BAJA', 'BAJA'); // Asegurarse que esté definida

$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Usuario';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'Desconocido';
$usuario_actual_sistema_para_historial = $_SESSION['usuario_login'] ?? 'Sistema';

if (!$conn || $conn->connect_error) {
    error_log("Error de conexión a la base de datos en mantenimiento.php: " . ($conn->connect_error ?? 'Error desconocido'));
    die("Error crítico de conexión a la base de datos. Por favor, contacte al administrador.");
}
$conn->set_charset("utf8mb4");

$mensaje = "";
$error_mensaje = "";
$serie_buscada = ""; // Para mantener el valor de búsqueda entre requests
$activo_encontrado = null;
$id_activo_encontrado_js = 'null';
$serie_activo_encontrado_js = '';

// --- Opciones para desplegables ---
$opciones_diagnostico = [
    'Falla de Hardware (General)', 'Falla de Componente Específico', 'Falla de Software (Sistema Operativo)',
    'Falla de Software (Aplicación)', 'Mantenimiento Preventivo', 'Limpieza Interna/Externa',
    'Actualización de Componentes', 'Actualización de Software/Firmware', 'Error de Configuración',
    'Daño Físico Accidental', 'Problema de Red/Conectividad', 'Falla Eléctrica',
    'Infección Virus/Malware', 'Desgaste por Uso', 'Otro (Detallar)'
];
$opciones_motivo_baja = [ // Opciones para el modal de baja
    'Obsolescencia', 'Daño irreparable (Confirmado post-mantenimiento)', 'Pérdida', 'Robo', 'Venta',
    'Donación', 'Fin de vida útil', 'Otro (especificar en observaciones)'
];

$proveedores = [];
$result_proveedores = $conn->query("SELECT id, nombre_proveedor FROM proveedores_mantenimiento ORDER BY nombre_proveedor ASC");
if ($result_proveedores) {
    while ($row = $result_proveedores->fetch_assoc()) { $proveedores[] = $row; }
} else { error_log("Error al obtener proveedores: " . $conn->error); }

$tecnicos_internos = [];
$sql_tecnicos = "SELECT usuario, nombre_completo, rol FROM usuarios WHERE rol IN ('admin', 'tecnico') ORDER BY nombre_completo ASC"; // Corregido: Añadir rol
$result_tecnicos = $conn->query($sql_tecnicos);
if ($result_tecnicos) {
    while ($row = $result_tecnicos->fetch_assoc()) { $tecnicos_internos[] = $row; }
} else { error_log("Error al obtener técnicos internos: " . $conn->error); }

// --- Lógica de Búsqueda de Activo (GET request) ---
if (isset($_GET['buscar_activo_serie'])) {
    $serie_buscada_get = trim($_GET['serie_buscada']);
    if (!empty($serie_buscada_get)) {
        $serie_buscada = $serie_buscada_get; // Mantener la serie buscada
        $stmt_buscar = $conn->prepare("SELECT id, tipo_activo, marca, serie, nombre AS nombre_responsable, cedula AS cedula_responsable, Empresa, estado AS estado_actual FROM activos_tecnologicos WHERE serie = ?");
        if ($stmt_buscar) {
            $stmt_buscar->bind_param("s", $serie_buscada);
            $stmt_buscar->execute();
            $result_activo = $stmt_buscar->get_result();
            if ($result_activo->num_rows > 0) {
                $activo_encontrado = $result_activo->fetch_assoc();
                if ($activo_encontrado['estado_actual'] === 'Dado de Baja') {
                    $error_mensaje = "El activo con la serie '" . htmlspecialchars($serie_buscada) . "' ya se encuentra 'Dado de Baja'. No se pueden registrar nuevos mantenimientos ni dar de baja nuevamente.";
                    $activo_encontrado = null;
                } else {
                    $id_activo_encontrado_js = $activo_encontrado['id'];
                    $serie_activo_encontrado_js = $activo_encontrado['serie'];
                }
            } else {
                $error_mensaje = "No se encontró ningún activo con la serie '" . htmlspecialchars($serie_buscada) . "'.";
            }
            $stmt_buscar->close();
        } else {
            $error_mensaje = "Error al preparar la búsqueda del activo: " . $conn->error;
        }
    } else {
        $error_mensaje = "Por favor, ingrese un número de serie para buscar.";
    }
}

// --- Lógica de Procesamiento de Formularios (POST request) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Mantener la serie buscada si viene de un POST para recargar la info del activo
    if (isset($_POST['serie_buscada_original_post_mantenimiento'])) {
        $serie_buscada = trim($_POST['serie_buscada_original_post_mantenimiento']);
    } elseif (isset($_POST['serie_buscada_original_post_baja'])) {
        $serie_buscada = trim($_POST['serie_buscada_original_post_baja']);
    }


    // --- Registro de Mantenimiento ---
    if (isset($_POST['guardar_mantenimiento'])) {
        $id_activo = filter_input(INPUT_POST, 'id_activo_mantenimiento', FILTER_VALIDATE_INT);
        // ... (resto de la captura de datos para mantenimiento como ya la tenías) ...
        $fecha_reparacion = trim($_POST['fecha_reparacion'] ?? '');
        $diagnostico = trim($_POST['diagnostico'] ?? '');
        $detalle_reparacion = trim($_POST['detalle_reparacion'] ?? '');
        $costo_reparacion_str = trim($_POST['costo_reparacion'] ?? '0');
        $proveedor_id = filter_input(INPUT_POST, 'proveedor_id', FILTER_VALIDATE_INT);
        if (empty($proveedor_id)) $proveedor_id = null;
        $tecnico_interno_id = filter_input(INPUT_POST, 'tecnico_interno_id', FILTER_VALIDATE_INT);
        if (empty($tecnico_interno_id)) $tecnico_interno_id = null;
        $estado_post_mantenimiento = trim($_POST['estado_post_mantenimiento'] ?? '');

        if (!$id_activo) { $error_mensaje = "ID de activo no válido."; }
        elseif (empty($fecha_reparacion)) { $error_mensaje = "La fecha de reparación es obligatoria."; }
        // ... (resto de tus validaciones para mantenimiento) ...
        else {
            $costo_reparacion = floatval($costo_reparacion_str);
            $nombre_proveedor = "N/A";
            if ($proveedor_id) { /* ... tu lógica para obtener nombre_proveedor ... */ 
                $stmt_prov = $conn->prepare("SELECT nombre_proveedor FROM proveedores_mantenimiento WHERE id = ?");
                if($stmt_prov){ $stmt_prov->bind_param("i", $proveedor_id); $stmt_prov->execute(); $res_prov = $stmt_prov->get_result(); if ($row_prov = $res_prov->fetch_assoc()) { $nombre_proveedor = $row_prov['nombre_proveedor']; } $stmt_prov->close(); }
            }
            $nombre_tecnico_interno = "N/A";
            if ($tecnico_interno_id) { /* ... tu lógica para obtener nombre_tecnico_interno y rol ... */
                $stmt_tec = $conn->prepare("SELECT nombre_completo, rol FROM usuarios WHERE id_usuario = ?");
                if($stmt_tec){ $stmt_tec->bind_param("i", $tecnico_interno_id); $stmt_tec->execute(); $res_tec = $stmt_tec->get_result(); if ($row_tec = $res_tec->fetch_assoc()) { $nombre_tecnico_interno = $row_tec['nombre_completo'] . " (".ucfirst($row_tec['rol']).")"; } $stmt_tec->close(); }
            }

            $conn->begin_transaction();
            try {
                $descripcion_historial = "Mantenimiento realizado. Diagnóstico: " . htmlspecialchars($diagnostico) . ".";
                if ($costo_reparacion > 0) $descripcion_historial .= " Costo: $" . number_format($costo_reparacion, 0, ',', '.') . ".";
                if ($nombre_proveedor !== "N/A") $descripcion_historial .= " Proveedor: " . htmlspecialchars($nombre_proveedor) . ".";
                if ($nombre_tecnico_interno !== "N/A") $descripcion_historial .= " Téc. Interno: " . htmlspecialchars($nombre_tecnico_interno) . ".";
                if (!empty($estado_post_mantenimiento)) $descripcion_historial .= " Estado Post-Mant: " . htmlspecialchars($estado_post_mantenimiento) . ".";
                
                $datos_mantenimiento = [ /* ... tus datos de mantenimiento ... */ 
                    'fecha_reparacion' => $fecha_reparacion, 'diagnostico' => $diagnostico, 'detalle_reparacion' => $detalle_reparacion,
                    'costo_reparacion' => $costo_reparacion, 'proveedor_id' => $proveedor_id, 'nombre_proveedor' => $nombre_proveedor,
                    'tecnico_interno_id' => $tecnico_interno_id, 'nombre_tecnico_interno' => $nombre_tecnico_interno,
                    'estado_post_mantenimiento' => $estado_post_mantenimiento
                ];
                registrar_evento_historial($conn, $id_activo, HISTORIAL_TIPO_MANTENIMIENTO, $descripcion_historial, $usuario_actual_sistema_para_historial, null, $datos_mantenimiento);

                if (!empty($estado_post_mantenimiento) && $estado_post_mantenimiento !== 'Dado de Baja') {
                    $stmt_update_estado = $conn->prepare("UPDATE activos_tecnologicos SET estado = ? WHERE id = ?");
                    if($stmt_update_estado){ $stmt_update_estado->bind_param("si", $estado_post_mantenimiento, $id_activo); $stmt_update_estado->execute(); $stmt_update_estado->close(); }
                    else { throw new Exception("Error al preparar la actualización de estado del activo: " . $conn->error); }
                }
                $conn->commit();
                $mensaje = "Mantenimiento registrado exitosamente para el activo ID: " . htmlspecialchars($id_activo) . ".";
                // No limpiar $activo_encontrado si el estado es "Malo (No se pudo reparar)" para que el botón de baja siga activo
                if ($estado_post_mantenimiento !== 'Malo (No se pudo reparar)') {
                    $activo_encontrado = null; $serie_buscada = ""; $id_activo_encontrado_js = 'null'; $serie_activo_encontrado_js = '';
                }
            } catch (Exception $e) {
                $conn->rollback();
                $error_mensaje = "Error al registrar el mantenimiento: " . $e->getMessage();
                error_log("Error en registro de mantenimiento: " . $e->getMessage());
            }
        }
    // --- Fin Registro de Mantenimiento ---

    // --- Inicio Procesar Baja desde Mantenimiento ---
    } elseif (isset($_POST['submit_dar_baja_desde_mantenimiento'])) {
        if (!tiene_permiso_para('dar_baja_activo')) {
            $error_mensaje = "Acción no permitida para su rol.";
        } elseif (empty($_POST['id_activo_baja_mantenimiento']) || empty($_POST['motivo_baja_mantenimiento'])) {
            $error_mensaje = "Faltan datos para dar de baja el activo (ID o Motivo).";
        } else {
            $id_activo_baja = filter_input(INPUT_POST, 'id_activo_baja_mantenimiento', FILTER_VALIDATE_INT);
            $motivo_baja = trim($_POST['motivo_baja_mantenimiento']);
            $observaciones_baja = trim($_POST['observaciones_baja_mantenimiento'] ?? '');
            $serie_buscada = trim($_POST['serie_buscada_original_post_baja'] ?? ''); // Para recargar la búsqueda

            $stmt_datos_previos = $conn->prepare("SELECT * FROM activos_tecnologicos WHERE id = ?");
            $datos_anteriores_del_activo = null;
            if ($stmt_datos_previos) {
                $stmt_datos_previos->bind_param('i', $id_activo_baja);
                $stmt_datos_previos->execute();
                $result_previos = $stmt_datos_previos->get_result();
                if ($result_previos->num_rows > 0) {
                    $datos_anteriores_del_activo = $result_previos->fetch_assoc();
                }
                $stmt_datos_previos->close();
            }

            if (!$datos_anteriores_del_activo) {
                $error_mensaje = "Activo a dar de baja no encontrado (ID: " . htmlspecialchars($id_activo_baja) . ").";
            } elseif ($datos_anteriores_del_activo['estado'] === 'Dado de Baja') {
                $error_mensaje = "El activo ID: " . htmlspecialchars($id_activo_baja) . " ya está Dado de Baja.";
            } else {
                $conn->begin_transaction();
                try {
                    $sql_baja = "UPDATE activos_tecnologicos SET estado = 'Dado de Baja' WHERE id = ?";
                    $stmt_baja = $conn->prepare($sql_baja);
                    if (!$stmt_baja) throw new Exception("Error preparando baja: " . $conn->error);
                    
                    $stmt_baja->bind_param('i', $id_activo_baja);
                    if (!$stmt_baja->execute()) throw new Exception("Error al dar de baja: " . $stmt_baja->error);
                    $stmt_baja->close();

                    $descripcion_hist_baja = "Activo Dado de Baja desde Mantenimiento. Motivo: " . htmlspecialchars($motivo_baja) . ".";
                    if (!empty($observaciones_baja)) { $descripcion_hist_baja .= " Observaciones: " . htmlspecialchars($observaciones_baja); }
                    
                    $datos_contexto_baja = ['estado_anterior' => $datos_anteriores_del_activo['estado'], 'motivo_baja' => $motivo_baja, 'observaciones_baja' => $observaciones_baja, 'fecha_efectiva_baja' => date('Y-m-d H:i:s'), 'originado_desde' => 'modulo_mantenimiento'];
                    registrar_evento_historial($conn, $id_activo_baja, HISTORIAL_TIPO_BAJA, $descripcion_hist_baja, $usuario_actual_sistema_para_historial, $datos_anteriores_del_activo, $datos_contexto_baja);
                    
                    $conn->commit();
                    $mensaje = "Activo ID: " . htmlspecialchars($id_activo_baja) . " dado de baja exitosamente.";
                    $activo_encontrado = null; // Limpiar para no mostrar más el formulario de mantenimiento para este activo
                    $id_activo_encontrado_js = 'null';
                    $serie_activo_encontrado_js = '';

                } catch (Exception $e) {
                    $conn->rollback();
                    $error_mensaje = "Error al dar de baja: " . $e->getMessage();
                    error_log("Error en dar de baja desde mantenimiento: " . $e->getMessage());
                }
            }
        }
    }
    // Fin Procesar Baja desde Mantenimiento

    // Si se realizó una acción POST y $serie_buscada tiene valor,
    // intentamos recargar el activo para mostrar su estado actualizado (o que no se encontró si fue dado de baja)
    if (!empty($serie_buscada) && !$activo_encontrado && ($error_mensaje == "" && $mensaje != "")) { // Solo si no hubo error y si hay un mensaje de exito
        $stmt_buscar_post_accion = $conn->prepare("SELECT id, tipo_activo, marca, serie, nombre AS nombre_responsable, cedula AS cedula_responsable, Empresa, estado AS estado_actual FROM activos_tecnologicos WHERE serie = ?");
        if ($stmt_buscar_post_accion) {
            $stmt_buscar_post_accion->bind_param("s", $serie_buscada);
            $stmt_buscar_post_accion->execute();
            $result_activo_post = $stmt_buscar_post_accion->get_result();
            if ($result_activo_post->num_rows > 0) {
                $activo_encontrado = $result_activo_post->fetch_assoc();
                if ($activo_encontrado['estado_actual'] === 'Dado de Baja') {
                     // $mensaje .= " Este activo ahora está 'Dado de Baja'."; // Ya se informa
                }
                $id_activo_encontrado_js = $activo_encontrado['id'];
                $serie_activo_encontrado_js = $activo_encontrado['serie'];
            } else {
                // El activo ya no se encuentra bajo los criterios normales (ej. fue dado de baja y la búsqueda inicial los excluye)
                // O la serie era incorrecta
                 if($mensaje == "" && $error_mensaje == "") $error_mensaje = "El activo con serie '".htmlspecialchars($serie_buscada)."' ya no está disponible para mantenimiento (pudo ser dado de baja).";
            }
            $stmt_buscar_post_accion->close();
        }
    }
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
        body { background-color: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding-top: 80px; }
        .top-bar-custom { position: fixed; top: 0; left: 0; right: 0; z-index: 1030; display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 1.5rem; background-color: #ffffff; border-bottom: 1px solid #dee2e6; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .logo-container-top img { height: 75px; }
        .card-custom { background-color: #ffffff; border-radius: 0.5rem; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,.075); }
    </style>
</head>
<body>
    <div class="top-bar-custom">
        <div class="logo-container-top">
            <a href="menu.php" title="Ir a Inicio"><img src="imagenes/logo.png" alt="Logo Empresa"></a>
        </div>
        <div class="d-flex align-items-center">
            <span class="text-dark me-3"><i class="bi bi-person-circle"></i> <?= htmlspecialchars($nombre_usuario_actual_sesion) ?> (<?= htmlspecialchars(ucfirst($rol_usuario_actual_sesion)) ?>)</span>
            <form action="logout.php" method="post" class="d-flex">
                <button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-box-arrow-right"></i> Salir</button>
            </form>
        </div>
    </div>

    <div class="container mt-4">
        <h3 class="mb-4 text-center" style="color: #191970;">Registrar Mantenimiento de Activo</h3>

        <?php if ($mensaje): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= $mensaje // Ya es HTML seguro si viene de PHP bien construido ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if ($error_mensaje): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($error_mensaje) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="card card-custom p-4 mb-4">
            <h5 class="card-title mb-3">1. Buscar Activo por Número de Serie</h5>
            <form method="GET" action="mantenimiento.php" class="row g-3 align-items-end">
                <div class="col-md-10">
                    <label for="serie_buscada_input" class="form-label">Número de Serie del Activo</label>
                    <input type="text" class="form-control" id="serie_buscada_input" name="serie_buscada" value="<?= htmlspecialchars($serie_buscada) ?>" required placeholder="Ingrese el serial completo">
                </div>
                <div class="col-md-2">
                    <button type="submit" name="buscar_activo_serie" class="btn btn-primary w-100">Buscar</button>
                </div>
            </form>
        </div>

        <?php if ($activo_encontrado && $activo_encontrado['estado_actual'] !== 'Dado de Baja'): ?>
        <div class="card card-custom p-4">
            <h5 class="card-title mb-3">2. Registrar Mantenimiento para el Activo</h5>
            <div class="alert alert-info">
                <strong>Activo Encontrado:</strong><br>
                ID: <span id="infoIdActivo"><?= htmlspecialchars($activo_encontrado['id']) ?></span><br>
                Tipo: <?= htmlspecialchars($activo_encontrado['tipo_activo']) ?><br>
                Marca: <?= htmlspecialchars($activo_encontrado['marca']) ?><br>
                Serie: <span id="infoSerieActivo"><?= htmlspecialchars($activo_encontrado['serie']) ?></span><br>
                Estado Actual: <strong><?= htmlspecialchars($activo_encontrado['estado_actual']) ?></strong><br>
                Responsable: <?= htmlspecialchars($activo_encontrado['nombre_responsable'] ?? 'N/A') ?> (C.C: <?= htmlspecialchars($activo_encontrado['cedula_responsable'] ?? 'N/A') ?>)<br>
                Empresa Asignada: <?= htmlspecialchars($activo_encontrado['Empresa'] ?? 'N/A') ?>
            </div>

            <form method="POST" action="mantenimiento.php" id="formRegistrarMantenimiento">
                <input type="hidden" name="id_activo_mantenimiento" value="<?= htmlspecialchars($activo_encontrado['id']) ?>">
                <input type="hidden" name="serie_buscada_original_post_mantenimiento" value="<?= htmlspecialchars($serie_buscada) ?>">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="fecha_reparacion" class="form-label">Fecha de Reparación <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="fecha_reparacion" name="fecha_reparacion" required value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-6">
                        <label for="costo_reparacion" class="form-label">Costo de Reparación (COP) <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="costo_reparacion" name="costo_reparacion" step="0.01" min="0" value="0" required>
                    </div>
                    <div class="col-md-12">
                        <label for="diagnostico" class="form-label">Diagnóstico <span class="text-danger">*</span></label>
                        <select class="form-select" id="diagnostico" name="diagnostico" required>
                            <option value="">Seleccione un diagnóstico...</option>
                            <?php foreach ($opciones_diagnostico as $diag): ?>
                                <option value="<?= htmlspecialchars($diag) ?>"><?= htmlspecialchars($diag) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-12">
                        <label for="detalle_reparacion" class="form-label">Detalle de la Reparación / Trabajo Realizado <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="detalle_reparacion" name="detalle_reparacion" rows="3" required></textarea>
                    </div>
                    <div class="col-md-6">
                        <label for="proveedor_id" class="form-label">Proveedor del Mantenimiento (Si aplica)</label>
                        <select class="form-select" id="proveedor_id" name="proveedor_id">
                            <option value="">N/A o Mantenimiento Interno</option>
                            <?php foreach ($proveedores as $prov): ?>
                                <option value="<?= htmlspecialchars($prov['id']) ?>"><?= htmlspecialchars($prov['nombre_proveedor']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label for="tecnico_interno_id" class="form-label">Técnico Interno Responsable (Si aplica)</label>
                        <select class="form-select" id="tecnico_interno_id" name="tecnico_interno_id">
                            <option value="">N/A o Proveedor Externo</option>
                            <?php foreach ($tecnicos_internos as $tec): ?>
                                <?php if (isset($tec['usuario']) && isset($tec['nombre_completo']) && isset($tec['rol'])): ?>
                                    <option value="<?= htmlspecialchars((string)$tec['usuario']) ?>">
                                        <?= htmlspecialchars((string)$tec['nombre_completo']) ?> (<?= htmlspecialchars(ucfirst($tec['rol'])) ?>)
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                     <div class="col-md-12"> 
                        <label for="estado_post_mantenimiento" class="form-label">Estado del Activo Después del Mantenimiento</label>
                        <select class="form-select" id="estado_post_mantenimiento" name="estado_post_mantenimiento">
                            <option value="">Sin Cambios / No Aplica</option>
                            <option value="Bueno">Bueno</option>
                            <option value="Regular">Regular</option>
                            <option value="Malo (No se pudo reparar)">Malo (No se pudo reparar)</option>
                            <option value="En Mantenimiento">En Mantenimiento (Proceso largo)</option>
                        </select>
                    </div>
                </div>
                
                <div class="mt-3 d-flex justify-content-between align-items-center">
                    <button type="submit" name="guardar_mantenimiento" class="btn btn-success">Registrar Mantenimiento</button>
                    
                    <button type="button" id="btnAbrirModalBajaMantenimiento" class="btn btn-danger" style="display:none;"
                            data-bs-toggle="modal" data-bs-target="#modalDarBajaEnMantenimiento">
                        <i class="bi bi-arrow-down-circle"></i> Iniciar Proceso de Baja para este Activo
                    </button>
                </div>
            </form>
        </div>
        <?php elseif ($activo_encontrado && $activo_encontrado['estado_actual'] === 'Dado de Baja'): ?>
            <div class="alert alert-warning mt-3">
                El activo con serie <strong><?= htmlspecialchars($activo_encontrado['serie']) ?></strong> ya se encuentra "Dado de Baja". No se pueden registrar nuevos mantenimientos.
            </div>
        <?php endif; ?>

    </div>

    <?php if (tiene_permiso_para('dar_baja_activo')): ?>
    <div class="modal fade" id="modalDarBajaEnMantenimiento" tabindex="-1" aria-labelledby="modalDarBajaEnMantenimientoLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post" action="mantenimiento.php" id="formDarBajaMantenimiento">
                    <div class="modal-header">
                        <h5 class="modal-title" id="modalDarBajaEnMantenimientoLabel">Confirmar Baja de Activo</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>¿Está seguro que desea dar de baja el activo con serie: <strong id="serieActivoBajaModalMantenimiento"></strong>?</p>
                        <p><small>(El estado del activo se cambiará a "Dado de Baja")</small></p>
                        
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
                        <button type="submit" name="submit_dar_baja_desde_mantenimiento" class="btn btn-sm btn-warning">Confirmar Baja</button>
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
            
            // Estas variables se llenan desde PHP si se encontró un activo
            const idActivoEncontradoJS = <?= json_encode($id_activo_encontrado_js); ?>;
            const serieActivoEncontradoJS = <?= json_encode($serie_activo_encontrado_js); ?>;
            const serieBuscadaOriginalJS = <?= json_encode($serie_buscada); ?>; // La serie que se usó para la búsqueda

            if (selectEstadoPostMantenimiento && btnAbrirModalBaja) {
                function toggleBajaButton() {
                    if (selectEstadoPostMantenimiento.value === 'Malo (No se pudo reparar)') {
                        btnAbrirModalBaja.style.display = 'inline-block';
                    } else {
                        btnAbrirModalBaja.style.display = 'none';
                    }
                }
                selectEstadoPostMantenimiento.addEventListener('change', toggleBajaButton);
                toggleBajaButton(); // Llamar al cargar por si el estado ya está preseleccionado
            }

            var modalDarBajaMantenimientoEl = document.getElementById('modalDarBajaEnMantenimiento');
            if (modalDarBajaMantenimientoEl && idActivoEncontradoJS !== null && idActivoEncontradoJS !== 'null' ) {
                modalDarBajaMantenimientoEl.addEventListener('show.bs.modal', function(event) {
                    document.getElementById('idActivoBajaModalMantenimiento').value = idActivoEncontradoJS;
                    document.getElementById('serieActivoBajaModalMantenimiento').textContent = serieActivoEncontradoJS;
                    document.getElementById('serieBuscadaOriginalPostBajaModal').value = serieBuscadaOriginalJS;


                    const diagnosticoActual = document.getElementById('diagnostico').value;
                    const detalleActual = document.getElementById('detalle_reparacion').value;
                    const motivoBajaSelect = document.getElementById('motivo_baja_mantenimiento');
                    const observacionesBajaTextarea = document.getElementById('observaciones_baja_mantenimiento');

                    if (diagnosticoActual.toLowerCase().includes('irreparable') || diagnosticoActual.toLowerCase().includes('daño físico irreparable')) {
                        motivoBajaSelect.value = 'Daño irreparable (Confirmado post-mantenimiento)';
                    } else if (diagnosticoActual === 'Fin de vida útil') {
                         motivoBajaSelect.value = 'Fin de vida útil';
                    } else {
                        motivoBajaSelect.value = 'Daño irreparable (Confirmado post-mantenimiento)'; // Default para este flujo
                    }
                    
                    // Pre-llenar observaciones con el detalle del mantenimiento
                    if(detalleActual) {
                        observacionesBajaTextarea.value = "Dado de baja tras intento de mantenimiento. Detalle original: " + detalleActual;
                    } else {
                        observacionesBajaTextarea.value = "";
                    }
                });
            }
        });
    </script>
</body>
</html>