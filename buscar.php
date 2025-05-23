<?php
// session_start(); // auth_check.php ya lo incluye y gestiona
require_once 'backend/auth_check.php';
// Todos los roles autenticados (admin, tecnico, auditor, registrador) pueden acceder a buscar
restringir_acceso_pagina(['admin', 'tecnico', 'auditor', 'registrador']);

require_once 'backend/db.php';
if (isset($conn) && !isset($conexion)) { $conexion = $conn; }
if (!isset($conexion) || !$conexion || (method_exists($conexion, 'connect_error') && $conexion->connect_error) ) {
    error_log("Error de conexión BD en buscar.php: " . ($conexion->connect_error ?? 'Desconocido'));
    die("Error de conexión a la base de datos. Por favor, intente más tarde o contacte al administrador.");
}
$conexion->set_charset("utf8mb4");

// Captura de datos de sesión para la barra superior
$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Usuario';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'Desconocido';


$cedula_buscada = trim($_GET['cedula'] ?? '');
$regional_buscada = trim($_GET['regional'] ?? '');
$empresa_buscada = trim($_GET['empresa'] ?? '');
$incluir_dados_baja = isset($_GET['incluir_bajas']) && $_GET['incluir_bajas'] === '1';
$buscar_todos_flag = isset($_GET['buscar_todos']) && $_GET['buscar_todos'] === '1';

$activos_encontrados = [];
$regionales = ['Popayan', 'Bordo', 'Santander', 'Valle', 'Pasto', 'Tuquerres', 'Huila', 'Nacional']; 
$empresas_disponibles = ['Arpesod', 'Finansueños'];

$criterio_busqueda_activo = false;

// $nombre_usuario_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Usuario'; // Ya se definió arriba
// $rol_usuario_sesion = obtener_rol_usuario(); // Ya se definió arriba como $rol_usuario_actual_sesion

if (!empty($cedula_buscada) || !empty($regional_buscada) || !empty($empresa_buscada) || $buscar_todos_flag) {
    $criterio_busqueda_activo = true;
    $sql = "SELECT * FROM activos_tecnologicos WHERE 1=1";
    $params = [];
    $types = '';

    if (!$incluir_dados_baja) {
        $sql .= " AND estado != 'Dado de Baja'";
    }

    if (!empty($cedula_buscada)) {
        $sql .= " AND cedula = ?";
        $params[] = $cedula_buscada;
        $types .= 's';
    }
    if (!empty($regional_buscada)) {
        $sql .= " AND regional = ?";
        $params[] = $regional_buscada;
        $types .= 's';
    }
    if (!empty($empresa_buscada)) {
        // Asumiendo que la columna es 'Empresa' basado en depuraciones previas.
        // Si es 'empresa' (minúscula) en tu BD, cambia aquí.
        $sql .= " AND Empresa = ?"; 
        $params[] = $empresa_buscada;
        $types .= 's';
    }
    $sql .= " ORDER BY nombre ASC, cedula ASC, id ASC";

    $stmt = $conexion->prepare($sql);
    $error_consulta = "";
    if ($stmt) {
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        if (!$stmt->execute()) {
            $error_consulta = "Error al ejecutar la búsqueda. Intente más tarde.";
            error_log("Error al ejecutar consulta en buscar.php: " . $stmt->error . " SQL: " . $sql . " Params: " . json_encode($params));
        } else {
            $resultado = $stmt->get_result();
            $activos_encontrados = $resultado->fetch_all(MYSQLI_ASSOC);
        }
        $stmt->close();
    } else {
        $error_consulta = "Error al preparar la consulta de búsqueda. Contacte al administrador.";
        error_log("Error al preparar consulta en buscar.php: " . $conexion->error . " SQL: " . $sql);
    }
}

function getEstadoBadgeClass($estado) {
    $estadoLower = strtolower(trim($estado));
    switch ($estadoLower) {
        case 'asignado': case 'activo': case 'operativo': case 'bueno': return 'badge bg-success';
        case 'en mantenimiento': case 'en reparación': case 'regular': return 'badge bg-warning text-dark';
        case 'dado de baja': case 'inactivo': case 'malo': return 'badge bg-danger';
        case 'disponible': case 'en stock': return 'badge bg-info text-dark';
        default: return 'badge bg-secondary';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Buscar Activos Tecnológicos - Inventario</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { 
            background-color: #ffffff !important; /* Fondo del body blanco */
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding-top: 80px; /* Espacio para la barra superior fija */
        }
        .top-bar-custom {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1030;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 1.5rem;
            background-color: #f8f9fa; 
            border-bottom: 1px solid #dee2e6;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .logo-container-top img {
            width: auto;
            height: 75px;
            object-fit: contain;
            margin-right: 15px;
        }
        .user-info-top {
            font-size: 0.9rem;
        }
        /* Estilos existentes de buscar.php (ajustados o mantenidos) */
        .container-main { margin-top: 20px; margin-bottom: 40px;} /* Este margin-top podría ser innecesario con padding-top en body */
        h3.page-title { color: #333; font-weight: 600; margin-bottom: 25px; }
        .btn-custom-search { background-color: #191970; color: white; }
        .btn-custom-search:hover { background-color: #8b0000; color: white; }
        .table-minimalist { border-collapse: collapse; width: 100%; margin-top: 15px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); border-radius: 6px; overflow: hidden; font-size: 0.85rem; }
        .table-minimalist thead th { background-color: #343a40; color: #fff; font-weight: 600; text-align: left; padding: 10px 12px; border-bottom: 0; white-space: nowrap; }
        .table-minimalist tbody td { padding: 9px 12px; border-bottom: 1px solid #e9ecef; color: #495057; vertical-align: middle; }
        .table-minimalist tbody tr:last-child td { border-bottom: none; }
        .table-minimalist tbody tr:hover { background-color: #f8f9fa; }
        .badge { padding: 0.4em 0.6em; font-size: 0.85em; font-weight: 600; }
        .btn-export { background-color: #198754; border-color: #198754; color: white; font-weight: 500; }
        .btn-export:hover { background-color: #157347; border-color: #146c43; }
        .user-asset-group { background-color: #fff; padding: 20px; margin-bottom: 25px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.07); }
        .user-info-header { border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; }
        .user-info-header .info-block { flex-grow: 1; min-width: 250px; }
        .user-info-header .info-block h4 { color: #37517e; font-weight: 600; margin-bottom: 2px; font-size: 1.1rem;}
        .user-info-header .info-block p { margin-bottom: 2px; font-size: 0.9rem; color: #555; }
        .user-info-header .actions-block { margin-top: 10px; md-margin-top: 0;}
        .asset-item-number { font-weight: bold; min-width: 25px; display: inline-block; text-align: right; margin-right: 5px;}
        .form-label { font-weight: 500; color: #495057; }
        .card.search-card { box-shadow: 0 2px 8px rgba(0,0,0,0.06); border:none; }
        .table-responsive { margin-top: 10px; }
        .page-header-title { color: #191970; } /* Para el título principal de la página */
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
        <h3 class="page-title mb-4 text-center page-header-title">Buscar Activos Tecnológicos</h3>
        <form class="row g-3 mb-2 align-items-end" method="get" action="buscar.php">
            <div class="col-md-3">
                <label for="cedula_buscar" class="form-label">Cédula del Responsable</label>
                <input type="text" class="form-control form-control-sm" id="cedula_buscar" name="cedula" value="<?= htmlspecialchars($cedula_buscada) ?>" placeholder="Número de cédula">
            </div>
            <div class="col-md-2">
                <label for="regional_buscar" class="form-label">Regional del Activo</label>
                <select name="regional" class="form-select form-select-sm" id="regional_buscar">
                    <option value="">-- Todas --</option>
                    <?php foreach ($regionales as $r): ?>
                        <option value="<?= htmlspecialchars($r) ?>" <?= ($r == $regional_buscada) ? 'selected' : '' ?>><?= htmlspecialchars($r) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label for="empresa_buscar" class="form-label">Empresa</label>
                <select name="empresa" class="form-select form-select-sm" id="empresa_buscar">
                    <option value="">-- Todas --</option>
                    <?php foreach ($empresas_disponibles as $e): ?>
                        <option value="<?= htmlspecialchars($e) ?>" <?= ($e == $empresa_buscada) ? 'selected' : '' ?>><?= htmlspecialchars($e) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <div class="form-check mt-4 pt-2">
                    <input class="form-check-input" type="checkbox" value="1" id="incluir_bajas" name="incluir_bajas" <?= $incluir_dados_baja ? 'checked' : '' ?>>
                    <label class="form-check-label" for="incluir_bajas">
                        Incluir Dados de Baja
                    </label>
                </div>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-custom-search w-100 btn-sm">Buscar</button>
            </div>
            
            <?php if (empty($cedula_buscada) && empty($regional_buscada) && empty($empresa_buscada) && !$criterio_busqueda_activo): ?>
            <div class="col-12 text-center mt-3"> 
                <button type="submit" name="buscar_todos" value="1" class="btn btn-outline-secondary btn-sm">
                    Mostrar Todos los Activos <?= !$incluir_dados_baja ? '(Operativos)' : '(Incl. Bajas)' ?>
                </button>
            </div>
            <?php endif; ?>
        </form>
    </div>

    <?php if (!empty($error_consulta)): ?>
        <div class="alert alert-danger mt-3"><strong>Error en la consulta:</strong> <?= htmlspecialchars($error_consulta) ?></div>
    <?php endif; ?>

    <?php if ($criterio_busqueda_activo && empty($error_consulta)): ?>
        <div class="mt-4">
        <?php if (!empty($activos_encontrados)) :
            $activos_agrupados = [];
            foreach ($activos_encontrados as $activo_item) {
                // Usar 'Empresa' como clave si así se devuelve de la BD (basado en debug previo)
                $key_grupo = $activo_item['cedula'] . '-' . $activo_item['nombre'];
                if (!isset($activos_agrupados[$key_grupo])) {
                    $activos_agrupados[$key_grupo]['info'] = [
                        'cedula' => $activo_item['cedula'],
                        'nombre' => $activo_item['nombre'],
                        'cargo' => $activo_item['cargo'],
                        'empresa_responsable' => $activo_item['Empresa'] ?? ($activo_item['empresa'] ?? 'N/A') // Para mostrar en el header del grupo
                    ];
                    $activos_agrupados[$key_grupo]['activos'] = [];
                }
                $activos_agrupados[$key_grupo]['activos'][] = $activo_item;
            }
        ?>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5>Resultados de la Búsqueda: <span class="badge bg-secondary"><?= count($activos_encontrados) ?> activo(s) encontrado(s)</span></h5>
                <form method="get" action="exportar_excel.php" target="_blank" style="display: inline-block;">
                    <input type="hidden" name="tipo_informe" value="busqueda_personalizada">
                    <input type="hidden" name="cedula_export" value="<?= htmlspecialchars($cedula_buscada) ?>">
                    <input type="hidden" name="regional_export" value="<?= htmlspecialchars($regional_buscada) ?>">
                    <input type="hidden" name="empresa_export" value="<?= htmlspecialchars($empresa_buscada) ?>">
                    <input type="hidden" name="incluir_bajas_export" value="<?= $incluir_dados_baja ? '1' : '0' ?>">
                    <button type="submit" class="btn btn-sm btn-export">
                        <i class="bi bi-file-earmark-excel-fill"></i> Exportar Resultados
                    </button>
                </form>
            </div>

            <?php
            foreach ($activos_agrupados as $grupo_info_activos) :
                $responsable_info = $grupo_info_activos['info'];
                $activos_del_responsable = $grupo_info_activos['activos'];
                $asset_item_number = 1;
            ?>
                <div class="user-asset-group">
                    <div class="user-info-header">
                        <div class="info-block">
                            <h4><?= htmlspecialchars($responsable_info['nombre']) ?></h4>
                            <p><strong>Cédula:</strong> <?= htmlspecialchars($responsable_info['cedula']) ?> |
                                <strong>Cargo:</strong> <?= htmlspecialchars($responsable_info['cargo']) ?> |
                                <strong>Empresa:</strong> <?= htmlspecialchars($responsable_info['empresa_responsable']) ?>
                            </p>
                        </div>
                        <div class="actions-block">
                            <?php
                            $hay_activos_operativos = false;
                            foreach ($activos_del_responsable as $a_temp) {
                                if ($a_temp['estado'] != 'Dado de Baja') { $hay_activos_operativos = true; break; }
                            }
                            if ($hay_activos_operativos && (function_exists('tiene_permiso_para') && tiene_permiso_para('generar_informes'))):
                            ?>
                            <a href="generar_acta.php?cedula=<?= htmlspecialchars($responsable_info['cedula']) ?>&tipo_acta=entrega&empresa=<?=urlencode($responsable_info['empresa_responsable'])?>" class="btn btn-sm btn-outline-secondary" target="_blank" title="Generar Acta de Entrega para <?= htmlspecialchars($responsable_info['nombre']) ?>">
                                <i class="bi bi-file-earmark-pdf-fill"></i> Generar Acta
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table-minimalist table-hover">
                            <thead>
                                <tr>
                                    <th>#</th><th>Tipo</th><th>Marca</th><th>Serie</th><th>Estado</th>
                                    <th>Regional</th>
                                    <th>Empresa</th> 
                                    <th>Valor</th><th>Fecha Reg.</th><th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($activos_del_responsable as $activo) : ?>
                                <tr class="<?= ($activo['estado'] == 'Dado de Baja') ? 'table-danger' : '' ?>">
                                    <td><span class="asset-item-number"><?= $asset_item_number++ ?>.</span></td>
                                    <td><?= htmlspecialchars($activo['tipo_activo']) ?></td>
                                    <td><?= htmlspecialchars($activo['marca']) ?></td>
                                    <td><?= htmlspecialchars($activo['serie']) ?></td>
                                    <td><span class="<?= getEstadoBadgeClass($activo['estado']) ?>"><?= htmlspecialchars($activo['estado']) ?></span></td>
                                    <td><?= htmlspecialchars($activo['regional']) ?></td>
                                    <td><?= htmlspecialchars($activo['Empresa'] ?? ($activo['empresa'] ?? 'N/A')) ?></td> 
                                    <td>$<?= htmlspecialchars(number_format(floatval($activo['valor_aproximado']), 0, ',', '.')) ?></td>
                                    <td><?= htmlspecialchars(date("d/m/Y", strtotime($activo['fecha_registro']))) ?></td>
                                    <td>
                                        <a href="historial.php?id_activo=<?= htmlspecialchars($activo['id']) ?>" class="btn btn-sm btn-outline-info" title="Ver Historial Detallado" target="_blank">
                                            <i class="bi bi-list-task"></i>
                                        </a>
                                        <?php if ((function_exists('tiene_permiso_para') && tiene_permiso_para('editar_activo_detalles')) && $activo['estado'] != 'Dado de Baja'): ?>
                                        <a href="editar.php?cedula=<?= htmlspecialchars($activo['cedula']) ?>&regional=<?= htmlspecialchars($activo['regional']) ?>&empresa=<?= htmlspecialchars($activo['Empresa'] ?? ($activo['empresa'] ?? '')) ?>&id_activo_focus=<?= $activo['id'] ?>" class="btn btn-sm btn-outline-primary" title="Editar este activo">
                                            <i class="bi bi-pencil-fill"></i>
                                        </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php elseif ($criterio_busqueda_activo): ?>
            <div class="alert alert-warning mt-3">No se encontraron activos que coincidan con los criterios de búsqueda especificados.</div>
        <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php if (isset($conexion)) { $conexion->close(); } ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>