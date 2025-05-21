<?php
session_start();
if (!isset($_SESSION['usuario'])) {
    header("Location: login.php");
    exit;
}

require_once 'backend/db.php';
// Incluir para las constantes de tipo de evento, si se usan para filtrar o mostrar
require_once 'backend/historial_helper.php';

// Verificar si la conexión se estableció en db.php
if (isset($conn) && !isset($conexion)) {
    $conexion = $conn;
} elseif (!isset($conn) && !isset($conexion)) {
    // Fallback de conexión si $conexion no fue definida por db.php
    $servername = "localhost";
    $username = "root";
    $password = ""; // Tu contraseña
    $dbname = "inventario";
    $conexion = new mysqli($servername, $username, $password, $dbname);
    if ($conexion->connect_error) {
        error_log("Fallo de conexión a la BD (informes.php fallback): " . $conexion->connect_error);
        die("Error de conexión al servidor. Por favor, intente más tarde.");
    }
}
// Última verificación de la conexión
if (!isset($conexion) || (method_exists($conexion, 'connect_error') && $conexion->connect_error) || !$conexion) {
    error_log("La variable de conexión a la BD no está disponible o es inválida en informes.php.");
    die("Error crítico de conexión. Contacte al administrador.");
}
$conexion->set_charset("utf8mb4");


$tipo_informe_seleccionado = $_GET['tipo_informe'] ?? 'seleccione'; // 'seleccione' para mostrar la página de bienvenida
$titulo_pagina_base = "Central de Informes";
$titulo_informe_actual = "";
$datos_para_tabla = []; // Un array genérico para los datos de cualquier informe
$columnas_tabla_html = []; // Para definir columnas dinámicamente en la vista HTML

// Función para obtener la clase CSS del badge de estado
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

// Lógica para determinar el título específico del informe y preparar datos
if ($tipo_informe_seleccionado !== 'seleccione') {
    switch ($tipo_informe_seleccionado) {
        case 'general':
            $titulo_informe_actual = "Informe General de Activos (Agrupado por Responsable)";
            $query = "SELECT activos_tecnologicos.*, 
                             activos_tecnologicos.nombre as nombre_responsable_directo, 
                             activos_tecnologicos.cedula as cedula_responsable_directo,
                             activos_tecnologicos.cargo as cargo_responsable_directo
                      FROM activos_tecnologicos 
                      WHERE estado != 'Dado de Baja' /* Excluir dados de baja del general */
                      ORDER BY cedula_responsable_directo ASC, nombre_responsable_directo ASC, id ASC";
            $resultado_query = mysqli_query($conexion, $query);
            if ($resultado_query) {
                $datos_para_tabla = mysqli_fetch_all($resultado_query, MYSQLI_ASSOC);
            } else {
                error_log("Error en la consulta (general): " . mysqli_error($conexion));
            }
            break;

        case 'por_tipo':
            $titulo_informe_actual = "Informe de Activos por Tipo";
            $query = "SELECT * FROM activos_tecnologicos WHERE estado != 'Dado de Baja' ORDER BY tipo_activo ASC, id ASC";
            $resultado_query = mysqli_query($conexion, $query);
            if ($resultado_query) $datos_para_tabla = mysqli_fetch_all($resultado_query, MYSQLI_ASSOC);
            else error_log("Error en la consulta (por_tipo): " . mysqli_error($conexion));
            break;

        case 'por_estado':
            $titulo_informe_actual = "Informe de Activos por Estado (Excluye 'Dado de Baja')";
            $query = "SELECT * FROM activos_tecnologicos WHERE estado != 'Dado de Baja' ORDER BY estado ASC, id ASC";
            $resultado_query = mysqli_query($conexion, $query);
            if ($resultado_query) $datos_para_tabla = mysqli_fetch_all($resultado_query, MYSQLI_ASSOC);
            else error_log("Error en la consulta (por_estado): " . mysqli_error($conexion));
            break;

        case 'por_regional':
            $titulo_informe_actual = "Informe de Activos por Regional (Excluye 'Dado de Baja')";
            $query = "SELECT * FROM activos_tecnologicos WHERE estado != 'Dado de Baja' ORDER BY regional ASC, id ASC";
            $resultado_query = mysqli_query($conexion, $query);
            if ($resultado_query) $datos_para_tabla = mysqli_fetch_all($resultado_query, MYSQLI_ASSOC);
            else error_log("Error en la consulta (por_regional): " . mysqli_error($conexion));
            break;

        case 'dados_baja':
            $titulo_informe_actual = "Informe de Activos Dados de Baja";
            $tipo_baja_const = defined('HISTORIAL_TIPO_BAJA') ? HISTORIAL_TIPO_BAJA : 'BAJA';
            $query = "SELECT
                        at.id, at.tipo_activo, at.marca, at.serie, at.estado,
                        at.valor_aproximado, at.regional, at.detalles AS detalles_activo, at.fecha_registro,
                        at.nombre AS nombre_ultimo_responsable, 
                        at.cedula AS cedula_ultimo_responsable,   
                        h_baja.descripcion_evento AS motivo_observaciones_baja,
                        h_baja.fecha_evento AS fecha_efectiva_baja
                      FROM
                        activos_tecnologicos at
                      LEFT JOIN (
                          SELECT
                              h1.id_activo,
                              h1.descripcion_evento,
                              h1.fecha_evento
                          FROM
                              historial_activos h1
                          INNER JOIN (
                              SELECT
                                  id_activo,
                                  MAX(id_historial) as max_id_hist_baja
                              FROM
                                  historial_activos
                              WHERE
                                  tipo_evento = '$tipo_baja_const'
                              GROUP BY
                                  id_activo
                          ) h2 ON h1.id_activo = h2.id_activo AND h1.id_historial = h2.max_id_hist_baja
                      ) h_baja ON at.id = h_baja.id_activo
                      WHERE
                        at.estado = 'Dado de Baja'
                      ORDER BY
                        COALESCE(h_baja.fecha_evento, at.fecha_registro) DESC, at.id ASC";
            $resultado_query = mysqli_query($conexion, $query);
            if ($resultado_query) {
                $datos_para_tabla = mysqli_fetch_all($resultado_query, MYSQLI_ASSOC);
            } else {
                error_log("Error en la consulta (dados_baja): " . mysqli_error($conexion));
            }
            $columnas_tabla_html = [
                "ID Activo", "Tipo Activo", "Marca", "Serie", "Últ. Responsable",
                "Fecha Efectiva Baja", "Motivo/Observaciones de Baja", "Acciones"
            ];
            break;

        case 'movimientos':
            $titulo_informe_actual = "Informe de Movimientos y Traslados Recientes";
            $tipo_traslado_const = defined('HISTORIAL_TIPO_TRASLADO') ? HISTORIAL_TIPO_TRASLADO : 'TRASLADO';
            // Ajusta los tipos de evento según lo que consideres "movimiento"
            // Podrías añadir HISTORIAL_TIPO_CREACION si la creación implica asignación
            $query = "SELECT h.id_historial, h.fecha_evento, h.tipo_evento, h.descripcion_evento, h.usuario_responsable,
                             a.id as id_activo_hist, a.tipo_activo, a.serie, a.marca AS marca_activo
                      FROM historial_activos h
                      JOIN activos_tecnologicos a ON h.id_activo = a.id
                      WHERE h.tipo_evento = '$tipo_traslado_const' 
                         OR h.tipo_evento = '".(defined('HISTORIAL_TIPO_ASIGNACION_INICIAL') ? HISTORIAL_TIPO_ASIGNACION_INICIAL : 'ASIGNACIÓN INICIAL')."' 
                         OR h.tipo_evento = '".(defined('HISTORIAL_TIPO_CREACION') ? HISTORIAL_TIPO_CREACION : 'CREACIÓN')."' /* si creación es un movimiento */
                      ORDER BY h.fecha_evento DESC LIMIT 100";
            $resultado_query = mysqli_query($conexion, $query);
            if ($resultado_query) {
                $datos_para_tabla = mysqli_fetch_all($resultado_query, MYSQLI_ASSOC);
            } else {
                error_log("Error en la consulta (movimientos): " . mysqli_error($conexion));
            }
            $columnas_tabla_html = [
                'Fecha Evento', 'Tipo Evento', 'Activo (Tipo)', 'Serie Activo', 'Marca Activo', 'Descripción Evento', 'Usuario Sistema', 'Ver Activo'
            ];
            break;
        
        default:
            $titulo_informe_actual = "Tipo de Informe No Válido";
            $datos_para_tabla = [];
            break;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($titulo_pagina_base) ?> <?= $titulo_informe_actual ? "- " . htmlspecialchars($titulo_informe_actual) : "" ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .container-main { margin-top: 20px; margin-bottom: 40px; }
        h3.page-title, h4.informe-title { color: #333; font-weight: 600; }
        .logo-container { text-align: center; margin-bottom: 5px; padding-top:10px; }
        .logo-container img { width: 180px; height: 70px; object-fit: contain; }
        .navbar-custom { background-color: #191970; }
        .navbar-custom .nav-link { color: white !important; font-weight: 500; padding: 0.5rem 1rem;}
        .navbar-custom .nav-link:hover, .navbar-custom .nav-link.active { background-color: #8b0000; color: white; }

        .informe-selector-card {
            transition: transform .2s, box-shadow .2s;
            cursor: pointer; border: 1px solid #ddd;
        }
        .informe-selector-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.12);
        }
        .informe-selector-card .card-body {
            display: flex; flex-direction: column; justify-content: center;
            align-items: center; min-height: 130px; text-align: center;
        }
        .informe-selector-card i { font-size: 2.2rem; margin-bottom: 0.75rem; }
        .informe-selector-card h5 { font-size: 1.1rem; font-weight: 500;}

        .table-minimalist {
            border-collapse: collapse; width: 100%; margin-top: 15px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06); border-radius: 8px; overflow: hidden; font-size: 0.85rem;
        }
        .table-minimalist thead th {
            background-color: #343a40; color: #fff; font-weight: 600;
            text-align: left; padding: 12px 15px; border-bottom: 0; white-space: nowrap;
        }
        .table-minimalist tbody td {
            padding: 10px 15px; border-bottom: 1px solid #e9ecef; color: #495057; vertical-align: middle;
        }
        .table-minimalist tbody tr:last-child td { border-bottom: none; }
        .table-minimalist tbody tr:hover { background-color: #f8f9fa; }
        .badge { padding: 0.45em 0.7em; font-size: 0.88em; font-weight: 500; }
        .btn-export { background-color: #198754; border-color: #198754; color: white; font-weight: 500; }
        .btn-export:hover { background-color: #157347; border-color: #146c43; }

        .user-asset-group, .report-group-container {
            background-color: #fff; padding: 20px; margin-bottom: 25px;
            border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.07);
        }
        .user-info-header, .group-info-header {
            border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 15px;
        }
        .user-info-header h4, .group-info-header h4 { color: #37517e; font-weight: 600; margin-bottom: 2px; font-size: 1.2rem;}
        .user-info-header p, .group-info-header p { margin-bottom: 2px; font-size: 0.95em; color: #555; }
        .asset-item-number { font-weight: bold; min-width: 25px; display: inline-block; text-align: right; margin-right: 5px;}
    </style>
</head>
<body>

<div class="logo-container">
  <a href="menu.php"><img src="imagenes/logo3.png" alt="Logo ARPESOD ASOCIADOS SAS"></a>
</div>

<nav class="navbar navbar-expand-lg navbar-custom">
    <div class="container-fluid">
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon" style="background-image: url(&quot;data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba(255,255,255,0.8)' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e&quot;);"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
               <li class="nav-item"><a class="nav-link" href="menu.php">Inicio</a></li>
               <li class="nav-item"><a class="nav-link" href="index.html">Registrar Activo</a></li>
               <li class="nav-item"><a class="nav-link" href="editar.php">Editar/Trasladar</a></li>
               <li class="nav-item"><a class="nav-link" href="buscar.php">Buscar Activos</a></li>
               <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
               <li class="nav-item"><a class="nav-link <?= ($tipo_informe_seleccionado !== 'seleccione' || strpos($_SERVER['REQUEST_URI'], 'informes.php') !== false) ? 'active' : '' ?>" aria-current="page" href="informes.php">Informes</a></li>
            </ul>
            <form class="d-flex ms-auto" action="logout.php" method="post">
                <button class="btn btn-outline-light" type="submit">Cerrar sesión</button>
            </form>
        </div>
    </div>
</nav>

<div class="container-main container mt-4">
    <h3 class="page-title text-center mb-4"><?= htmlspecialchars($titulo_pagina_base) ?></h3>

    <div class="row mb-5 justify-content-center">
        <div class="col-lg-3 col-md-4 col-sm-6 mb-3">
            <a href="informes.php?tipo_informe=general" class="card informe-selector-card text-decoration-none <?= ($tipo_informe_seleccionado == 'general') ? 'border-primary shadow' : 'text-dark' ?>">
                <div class="card-body"> <i class="bi bi-list-ul text-primary"></i> <h5>General por Responsable</h5></div>
            </a>
        </div>
        <div class="col-lg-3 col-md-4 col-sm-6 mb-3">
            <a href="informes.php?tipo_informe=por_tipo" class="card informe-selector-card text-decoration-none <?= ($tipo_informe_seleccionado == 'por_tipo') ? 'border-secondary shadow' : 'text-dark' ?>">
                <div class="card-body"> <i class="bi bi-tags-fill text-secondary"></i> <h5>Activos por Tipo</h5></div>
            </a>
        </div>
        <div class="col-lg-3 col-md-4 col-sm-6 mb-3">
            <a href="informes.php?tipo_informe=por_estado" class="card informe-selector-card text-decoration-none <?= ($tipo_informe_seleccionado == 'por_estado') ? 'border-success shadow' : 'text-dark' ?>">
                <div class="card-body"> <i class="bi bi-check-circle-fill text-success"></i> <h5>Activos por Estado</h5></div>
            </a>
        </div>
        <div class="col-lg-3 col-md-4 col-sm-6 mb-3">
            <a href="informes.php?tipo_informe=por_regional" class="card informe-selector-card text-decoration-none <?= ($tipo_informe_seleccionado == 'por_regional') ? 'border-info shadow' : 'text-dark' ?>">
                <div class="card-body"> <i class="bi bi-geo-alt-fill text-info"></i> <h5>Activos por Regional</h5></div>
            </a>
        </div>
        <div class="col-lg-3 col-md-4 col-sm-6 mb-3">
            <a href="informes.php?tipo_informe=dados_baja" class="card informe-selector-card text-decoration-none <?= ($tipo_informe_seleccionado == 'dados_baja') ? 'border-danger shadow' : 'text-dark' ?>">
                <div class="card-body"> <i class="bi bi-trash3-fill text-danger"></i> <h5>Activos Dados de Baja</h5></div>
            </a>
        </div>
        <div class="col-lg-3 col-md-4 col-sm-6 mb-3">
            <a href="informes.php?tipo_informe=movimientos" class="card informe-selector-card text-decoration-none <?= ($tipo_informe_seleccionado == 'movimientos') ? 'border-warning shadow' : 'text-dark' ?>">
                <div class="card-body"> <i class="bi bi-truck text-warning"></i> <h5>Movimientos Recientes</h5></div>
            </a>
        </div>
    </div>
    
    <?php if ($tipo_informe_seleccionado !== 'seleccione' && !empty($titulo_informe_actual)) : ?>
        <hr class="my-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="informe-title mb-0"><i class="bi bi-table"></i> <?= htmlspecialchars($titulo_informe_actual) ?></h4>
            <?php if (!empty($datos_para_tabla)): // Mostrar botón solo si hay datos para el informe actual ?>
            <a href="exportar_excel.php?tipo_informe=<?= urlencode($tipo_informe_seleccionado) ?>" class="btn btn-sm btn-export">
                <i class="bi bi-file-earmark-excel-fill"></i> Exportar a Excel
            </a>
            <?php endif; ?>
        </div>

        <?php if (!empty($datos_para_tabla)) : ?>
            <?php
            if ($tipo_informe_seleccionado == 'general') {
                $current_group_key_general = null; $asset_item_number_general = 0;
                foreach ($datos_para_tabla as $activo_gen) :
                    if ($activo_gen['cedula_responsable_directo'] !== $current_group_key_general) :
                        if ($current_group_key_general !== null) echo '</tbody></table></div></div>';
                        $current_group_key_general = $activo_gen['cedula_responsable_directo']; $asset_item_number_general = 1;
            ?>
                        <div class="user-asset-group">
                            <div class="user-info-header">
                                <h4><?= htmlspecialchars($activo_gen['nombre_responsable_directo']) ?></h4>
                                <p><strong>C.C:</strong> <?= htmlspecialchars($activo_gen['cedula_responsable_directo']) ?> | <strong>Cargo:</strong> <?= htmlspecialchars($activo_gen['cargo_responsable_directo']) ?></p>
                            </div>
                            <div class="table-responsive">
                                <table class="table-minimalist">
                                    <thead><tr><th>#</th><th>Tipo</th><th>Marca</th><th>Serie</th><th>Estado</th><th>Valor</th><th>Regional</th><th>Fecha Reg.</th></tr></thead>
                                    <tbody>
            <?php
                    endif;
            ?>
                                    <tr>
                                        <td><span class="asset-item-number"><?= $asset_item_number_general++ ?>.</span></td>
                                        <td><?= htmlspecialchars($activo_gen['tipo_activo']) ?></td>
                                        <td><?= htmlspecialchars($activo_gen['marca']) ?></td>
                                        <td><?= htmlspecialchars($activo_gen['serie']) ?></td>
                                        <td><span class="<?= getEstadoBadgeClass($activo_gen['estado']) ?>"><?= htmlspecialchars($activo_gen['estado']) ?></span></td>
                                        <td><?= htmlspecialchars(number_format(floatval($activo_gen['valor_aproximado']), 0, ',', '.')) ?></td>
                                        <td><?= htmlspecialchars($activo_gen['regional']) ?></td>
                                        <td><?= htmlspecialchars(date("d/m/Y", strtotime($activo_gen['fecha_registro']))) ?></td>
                                    </tr>
            <?php
                endforeach;
                if ($current_group_key_general !== null) echo '</tbody></table></div></div>';
            } elseif (in_array($tipo_informe_seleccionado, ['por_tipo', 'por_estado', 'por_regional'])) {
                $current_group_key_field = null; $asset_item_number_field = 0;
                $group_by_field = ($tipo_informe_seleccionado == 'por_tipo') ? 'tipo_activo' : (($tipo_informe_seleccionado == 'por_estado') ? 'estado' : 'regional');
                $group_by_field_label = ucfirst(str_replace('_', ' ', $group_by_field));

                foreach ($datos_para_tabla as $activo_field) :
                    if ($activo_field[$group_by_field] !== $current_group_key_field) :
                        if ($current_group_key_field !== null) echo '</tbody></table></div></div>';
                        $current_group_key_field = $activo_field[$group_by_field]; $asset_item_number_field = 1;
            ?>
                        <div class="report-group-container">
                            <div class="group-info-header"><h4><?= $group_by_field_label ?>: <?= htmlspecialchars($current_group_key_field) ?></h4></div>
                            <div class="table-responsive">
                                <table class="table-minimalist">
                                     <thead><tr><th>#</th><th>Serie</th><th>Marca</th><th>Responsable</th><th>C.C.</th><th><?= ($group_by_field != 'estado') ? 'Estado' : 'Tipo Activo' ?></th><th>Valor</th><th><?= ($group_by_field != 'regional') ? 'Regional' : 'Tipo Activo' ?></th></tr></thead>
                                    <tbody>
            <?php
                    endif;
            ?>
                                    <tr>
                                        <td><span class="asset-item-number"><?= $asset_item_number_field++ ?>.</span></td>
                                        <td><?= htmlspecialchars($activo_field['serie']) ?></td>
                                        <td><?= htmlspecialchars($activo_field['marca']) ?></td>
                                        <td><?= htmlspecialchars($activo_field['nombre']) ?></td>
                                        <td><?= htmlspecialchars($activo_field['cedula']) ?></td>
                                        <td>
                                            <?php if ($group_by_field != 'estado'): ?>
                                                <span class="<?= getEstadoBadgeClass($activo_field['estado']) ?>"><?= htmlspecialchars($activo_field['estado']) ?></span>
                                            <?php else: ?>
                                                <?= htmlspecialchars($activo_field['tipo_activo']) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars(number_format(floatval($activo_field['valor_aproximado']), 0, ',', '.')) ?></td>
                                        <td><?= htmlspecialchars(($group_by_field != 'regional') ? $activo_field['regional'] : $activo_field['tipo_activo']) ?></td>
                                    </tr>
            <?php
                endforeach;
                if ($current_group_key_field !== null) echo '</tbody></table></div></div>';
            } elseif ($tipo_informe_seleccionado == 'dados_baja') {
            ?>
                <div class="report-group-container"> <div class="table-responsive">
                        <table class="table-minimalist">
                            <thead><tr>
                                <?php foreach ($columnas_tabla_html as $header): ?>
                                    <th><?= htmlspecialchars($header) ?></th>
                                <?php endforeach; ?>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($datos_para_tabla as $activo_baja) : ?>
                                <tr>
                                    <td><?= htmlspecialchars($activo_baja['id']) ?></td>
                                    <td><?= htmlspecialchars($activo_baja['tipo_activo']) ?></td>
                                    <td><?= htmlspecialchars($activo_baja['marca']) ?></td>
                                    <td><?= htmlspecialchars($activo_baja['serie']) ?></td>
                                    <td><?= htmlspecialchars($activo_baja['nombre_ultimo_responsable'] ?? 'N/A') ?> (<?= htmlspecialchars($activo_baja['cedula_ultimo_responsable'] ?? 'N/A') ?>)</td>
                                    <td><?= htmlspecialchars(!empty($activo_baja['fecha_efectiva_baja']) ? date("d/m/Y H:i:s", strtotime($activo_baja['fecha_efectiva_baja'])) : 'N/A') ?></td>
                                    <td style="max-width: 300px; white-space: pre-wrap; word-wrap: break-word;"><?= nl2br(htmlspecialchars($activo_baja['motivo_observaciones_baja'] ?? $activo_baja['detalles_activo'] ?? 'Ver historial')) ?></td>
                                    <td>
                                        <a href="historial.php?id_activo=<?= htmlspecialchars($activo_baja['id']) ?>" class="btn btn-sm btn-outline-info" title="Ver Historial Completo" target="_blank">
                                            <i class="bi bi-list-task"></i> Hist.
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php
            } elseif ($tipo_informe_seleccionado == 'movimientos') {
            ?>
                <div class="report-group-container">
                    <div class="table-responsive">
                        <table class="table-minimalist">
                            <thead><tr>
                                <?php foreach ($columnas_tabla_html as $header => $db_key): // Asumiendo $columnas_tabla_html está definido con los headers correctos para este informe ?>
                                    <th><?= htmlspecialchars($header) ?></th>
                                <?php endforeach; ?>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($datos_para_tabla as $evento) : ?>
                                <tr>
                                    <td><?= htmlspecialchars(!empty($evento['fecha_evento']) ? date("d/m/Y H:i:s", strtotime($evento['fecha_evento'])) : 'N/A') ?></td>
                                    <td><span class="badge bg-warning text-dark"><?= htmlspecialchars($evento['tipo_evento']) ?></span></td>
                                    <td><?= htmlspecialchars($evento['tipo_activo'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($evento['serie'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($evento['marca_activo'] ?? 'N/A') ?></td>
                                    <td style="max-width: 350px; white-space: pre-wrap; word-wrap: break-word;"><?= nl2br(htmlspecialchars($evento['descripcion_evento'])) ?></td>
                                    <td><?= htmlspecialchars($evento['usuario_responsable'] ?? 'N/A') ?></td>
                                    <td>
                                        <a href="historial.php?id_activo=<?= htmlspecialchars($evento['id_activo_hist']) ?>" class="btn btn-sm btn-outline-info" title="Ver Historial Completo del Activo" target="_blank">
                                            <i class="bi bi-list-task"></i> Activo
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php
            } // Fin de la lógica de visualización específica por informe
            ?>
        <?php else: // Si $datos_para_tabla está vacío para el informe seleccionado ?>
            <div class="alert alert-info text-center mt-4" role="alert">
                <i class="bi bi-exclamation-circle"></i> No hay datos disponibles para el informe: "<?= htmlspecialchars($titulo_informe_actual) ?>".
            </div>
        <?php endif; ?>
    <?php elseif ($tipo_informe_seleccionado == 'seleccione'): ?>
         <div class="alert alert-light text-center" role="alert" style="padding: 3rem; border: 1px dashed #ccc;">
            <i class="bi bi-clipboard-data" style="font-size: 3rem; color: #0d6efd;"></i><br><br>
            <h4 class="text-primary">Central de Informes</h4>
            <p class="text-muted fs-5">Por favor, seleccione un tipo de informe de las opciones superiores para visualizar los datos.</p>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>