<?php
// session_start(); // auth_check.php ya lo incluye y gestiona
require_once 'backend/auth_check.php';
restringir_acceso_pagina(['admin', 'tecnico', 'registrador']);

require_once 'backend/db.php';
if (isset($conn) && !isset($conexion)) { $conexion = $conn; }
if (!isset($conexion) || !$conexion) { die("Error de conexión a la base de datos en index.php."); }
$conexion->set_charset("utf8mb4");

$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Usuario';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'Desconocido';


// --- LECTURA DESDE LA BASE DE DATOS (ESTO YA ESTÁ CORRECTO) ---
// Obtenemos el nombre Y la vida útil sugerida desde la BD
$opciones_tipo_activo = [];
$vida_util_map = []; // Mapa para asociar: Nombre -> Vida Útil

$sql_tipos = "SELECT nombre_tipo_activo, vida_util_sugerida FROM tipos_activo ORDER BY nombre_tipo_activo ASC";
$result_tipos = $conexion->query($sql_tipos);
if ($result_tipos && $result_tipos->num_rows > 0) {
    while($row = $result_tipos->fetch_assoc()) {
        $opciones_tipo_activo[] = $row['nombre_tipo_activo'];
        // Llenamos el mapa con los datos de la base de datos
        $vida_util_map[$row['nombre_tipo_activo']] = (int)$row['vida_util_sugerida']; // Aseguramos que sea un número
    }
} else {
    // Fallback por si la consulta falla o no hay tipos de activo
    $opciones_tipo_activo = ['Computador', 'Monitor', 'Impresora', 'Escáner', 'Otro'];
    $vida_util_map = ['Computador' => 5, 'Monitor' => 5, 'Impresora' => 5, 'Escáner' => 5, 'Otro' => 5];
}
// --- FIN LECTURA ---


// Definición de otras opciones para los selects del formulario
$regionales = ['Popayan', 'Bordo', 'Santander', 'Valle', 'Pasto', 'Tuquerres', 'Huila', 'Nacional'];
$empresas_disponibles = ['Arpesod', 'Finansueños'];
$opciones_tipo_equipo = ['Portátil', 'Mesa', 'Todo en 1'];
$opciones_red = ['Cableada', 'Inalámbrica', 'Ambas'];
$opciones_estado_general = ['Bueno', 'Regular', 'Malo'];
$opciones_so = ['Windows 10', 'Windows 11', 'Linux', 'MacOS'];
$opciones_offimatica = ['Office 365', 'Office Home And Business', 'Office 2021', 'Office 2019', 'Office 2016', 'LibreOffice', 'Google Workspace'];
$opciones_antivirus = ['Microsoft Defender', 'Bitdefender', 'ESET NOD32 Antivirus', 'McAfee Total Protection', 'Kaspersky'];
$aplicaciones_mas_usadas = ['Manager', 'Excel', 'Word', 'Power Point', 'WhatsApp Web', 'Siesa', 'Finansueños', 'Correo', 'Internet', 'Otros'];

$mensaje_global = $_SESSION['mensaje_global'] ?? null;
$error_global = $_SESSION['error_global'] ?? null;
unset($_SESSION['mensaje_global']);
unset($_SESSION['error_global']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Registrar Activos por Lote</title>
    <link rel="icon" type="image/x-icon" href="imagenes/icono.ico">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #ffffff !important; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding-top: 80px; }
        .top-bar-custom { position: fixed; top: 0; left: 0; right: 0; z-index: 1030; display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 1.5rem; background-color: #f8f9fa; border-bottom: 1px solid #dee2e6; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .logo-container-top img { width: auto; height: 75px; object-fit: contain; margin-right: 15px; }
        .user-info-top { font-size: 0.9rem; }
        .btn-principal, #btnGuardarTodo, #btnAgregarActivoTabla { background-color: #191970; border-color: #191970; color: #ffffff; }
        .btn-principal:hover, #btnGuardarTodo:hover, #btnAgregarActivoTabla:hover { background-color: #111150; border-color: #111150; color: #ffffff; }
        #infoModal .modal-header { background-color: #191970; color: #ffffff; }
        #infoModal .modal-header .btn-close { filter: invert(1) grayscale(100%) brightness(200%); }
        #infoModal .modal-title i { margin-right: 8px; }
        .card.form-card { box-shadow: 0 4px 12px rgba(0,0,0,0.08); border: none; }
        .form-label { font-weight: 500; color: #495057; }
        .form-section { border: 1px solid #e0e0e0; padding: 20px; border-radius: 8px; margin-bottom: 20px; background-color: #fff; }
        .table-activos-agregados th { font-size: 0.9em; }
        .table-activos-agregados td { font-size: 0.85em; vertical-align: middle; }
        .star-rating { display: inline-block; direction: rtl; font-size: 0; }
        .star-rating input[type="radio"] { display: none; }
        .star-rating label.star-label { color: #ccc; font-size: 1.8rem; padding: 0 0.05em; cursor: pointer; display: inline-block; transition: color 0.2s ease-in-out; }
        .star-rating input[type="radio"]:checked ~ label.star-label, .star-rating label.star-label:hover, .star-rating label.star-label:hover ~ label.star-label { color: #f5b301; }
        .star-rating input[type="radio"]:checked + label.star-label:hover, .star-rating input[type="radio"]:checked ~ label.star-label:hover, .star-rating input[type="radio"]:checked ~ label.star-label:hover ~ label.star-label, .star-rating label.star-label:hover ~ input[type="radio"]:checked ~ label.star-label { color: #f5b301; }
        .btn-remove-asset { font-size: 0.8em; padding: 0.2rem 0.5rem; }
        #infoAplicacionesExistentes { font-size: 0.85em; }
        input:read-only, select:disabled { background-color: #e9ecef; cursor: not-allowed; }
        .rating-invalid {
            border: 1px solid #dc3545; /* Color de peligro de Bootstrap */
            border-radius: 8px;
            padding: 5px;
            box-shadow: 0 0 0 0.25rem rgba(220, 53, 69, 0.25);
        }
        .footer-custom {
            font-size: 0.9rem; background-color: #f8f9fa; 
            border-top: 1px solid #dee2e6; 
        }
        .footer-custom a i { color: #6c757d; transition: color 0.2s ease-in-out; }
        .footer-custom a i:hover { color: #0d6efd !important; }
    </style>
</head>
<body>
<div class="top-bar-custom">
    <div class="logo-container-top">
        <a href="menu.php" title="Ir a Inicio"><img src="imagenes/logo.png" alt="Logo ARPESOD ASOCIADOS SAS"></a>
    </div>
    <div class="d-flex align-items-center">
        <span class="text-dark me-3 user-info-top"><i class="bi bi-person-circle"></i> <?= htmlspecialchars($nombre_usuario_actual_sesion) ?> (<?= htmlspecialchars(ucfirst($rol_usuario_actual_sesion)) ?>)</span>
        <form action="logout.php" method="post" class="d-flex"><button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</button></form>
    </div>
</div>

<div class="container-main container mt-4">
    <h3 class="page-title text-center mb-4">Registrar Activos (por Responsable)</h3>

    <?php if ($mensaje_global): ?><div class="alert alert-success alert-dismissible fade show" role="alert"><?= htmlspecialchars($mensaje_global) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
    <?php if ($error_global): ?><div class="alert alert-danger alert-dismissible fade show" role="alert"><?= htmlspecialchars($error_global) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>

    <form action="guardar_activo.php" method="post" id="formRegistrarLoteActivos">
        <div class="form-section" id="seccionResponsable">
            <h5 class="mb-3">1. Información del Responsable</h5>
            <div class="row">
                <div class="col-md-4 mb-3"><label for="cedula" class="form-label">Cédula <span class="text-danger">*</span></label><input type="text" class="form-control" id="cedula" name="responsable_cedula" required></div>
                <div class="col-md-4 mb-3"><label for="nombre" class="form-label">Nombre Completo <span class="text-danger">*</span></label><input type="text" class="form-control" id="nombre" name="responsable_nombre" required></div>
                <div class="col-md-4 mb-3"><label for="cargo" class="form-label">Cargo <span class="text-danger">*</span></label><input type="text" class="form-control" id="cargo" name="responsable_cargo" required></div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="regional" class="form-label">Regional (Asignada al Responsable) <span class="text-danger">*</span></label>
                    <select class="form-select" id="regional" name="responsable_regional" required>
                        <option value="">Seleccione...</option>
                        <?php foreach ($regionales as $r): ?><option value="<?= htmlspecialchars($r) ?>"><?= htmlspecialchars($r) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="empresa_responsable" class="form-label">Empresa (Asignada al Responsable) <span class="text-danger">*</span></label>
                    <select class="form-select" id="empresa_responsable" name="responsable_empresa" required>
                        <option value="">Seleccione...</option>
                        <?php foreach ($empresas_disponibles as $e): ?><option value="<?= htmlspecialchars($e) ?>"><?= htmlspecialchars($e) ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Aplicaciones que más usa el responsable: <span class="text-danger">*</span></label>
                <div class="p-2 border rounded" id="contenedorCheckboxesAplicaciones">
                    <?php foreach ($aplicaciones_mas_usadas as $app): ?>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" name="responsable_aplicaciones[]" value="<?= htmlspecialchars($app) ?>" id="app_<?= htmlspecialchars(str_replace(' ', '_', $app)) ?>">
                            <label class="form-check-label" for="app_<?= htmlspecialchars(str_replace(' ', '_', $app)) ?>"><?= htmlspecialchars($app) ?></label>
                        </div>
                    <?php endforeach; ?>
                    <input type="text" class="form-control form-control-sm mt-2" id="responsable_aplicaciones_otros_texto" name="responsable_aplicaciones_otros_texto" placeholder="Especifique cuál(es)" style="display: none;">
                </div>
            </div>
            <div id="contenedor-botones-responsable">
                <button type="button" class="btn btn-info btn-sm" id="btnConfirmarResponsable">Confirmar Responsable y Agregar Activos</button>
                <button type="button" class="btn btn-secondary btn-sm" id="btnEditarResponsable" style="display: none;"><i class="bi bi-pencil"></i> Editar Responsable</button>
            </div>
        </div>
        
        <div class="form-section" id="seccionAgregarActivo" style="display: none;">
             <h5 class="mb-3">2. Agregar Activo para <strong id="nombreResponsableDisplay"></strong></h5>
             <div class="row">
                 <div class="col-md-4 mb-3">
                    <label for="tipo_activo" class="form-label">Tipo de Activo <span class="text-danger">*</span></label>
                    <select class="form-select" id="tipo_activo" name="activo_tipo_activo" required>
                        <option value="">Seleccione...</option>
                        <?php foreach ($opciones_tipo_activo as $opcion): ?>
                        <option value="<?= htmlspecialchars($opcion) ?>"><?= htmlspecialchars($opcion) ?></option>
                        <?php endforeach; ?>
                    </select>
                 </div>

                 <div class="col-md-4 mb-3" id="campo_tipo_impresora_container" style="display: none;">
                    <label for="tipo_impresora" class="form-label">Tipo de Impresora <span class="text-danger">*</span></label>
                    <select class="form-select" id="tipo_impresora" name="activo_tipo_impresora">
                        <option value="">Seleccione...</option>
                        <option value="Laser">Laser</option>
                        <option value="Tinta">Tinta</option>
                    </select>
                 </div>
                 <div class="col-md-4 mb-3"><label for="marca" class="form-label">Marca <span class="text-danger">*</span></label><input type="text" class="form-control" id="marca" name="activo_marca" required></div>
                 <div class="col-md-4 mb-3"><label for="serie" class="form-label">Serie / Serial <span class="text-danger">*</span></label><input type="text" class="form-control" id="serie" name="activo_serie" required></div>
            </div>
            <div class="row">
                 <div class="col-md-4 mb-3"><label for="estado" class="form-label">Estado del Activo <span class="text-danger">*</span></label><select class="form-select" id="estado" name="activo_estado" required><option value="Seleccione">Seleccione</option><?php foreach ($opciones_estado_general as $opcion): if($opcion !== 'Nuevo' && $opcion !== 'Dado de Baja') { ?><option value="<?= htmlspecialchars($opcion) ?>"><?= htmlspecialchars($opcion) ?></option><?php } endforeach; ?></select></div>
                 <div class="col-md-4 mb-3"><label for="valor_aproximado" class="form-label">Valor del Activo (Compra) <span class="text-danger">*</span></label><input type="number" class="form-control" id="valor_aproximado" name="activo_valor_aproximado" step="0.01" min="0" required></div>
                 <div class="col-md-4 mb-3"><label for="codigo_inv" class="form-label">Código Inventario (Opcional)</label><input type="text" class="form-control" id="codigo_inv" name="activo_codigo_inv"></div>
            </div>
            
            <hr class="my-3">
            <h6 class="mb-3 text-primary">Información para Depreciación (Automático)</h6>
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label for="fecha_compra" class="form-label">Fecha de Compra <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" id="fecha_compra" name="activo_fecha_compra" required>
                </div>
                <div class="col-md-3 mb-3">
                    <label for="vida_util" class="form-label">Vida Útil (Años)</label>
                    <input type="number" class="form-control" id="vida_util" name="activo_vida_util" readonly>
                </div>
                 <div class="col-md-3 mb-3">
                    <label for="metodo_depreciacion" class="form-label">Método Depreciación</label>
                    <select class="form-select" id="metodo_depreciacion" name="activo_metodo_depreciacion" disabled>
                        <option value="Linea Recta" selected>Línea Recta</option>
                    </select>
                 </div>
                <div class="col-md-3 mb-3">
                    <label for="valor_residual" class="form-label">Valor Residual</label>
                    <input type="number" class="form-control" id="valor_residual" name="activo_valor_residual" value="0" readonly>
                </div>
            </div>

            <div id="campos_computador_form_activo" style="display: none;">
                <hr class="my-3"><h6 class="mb-3 text-muted">Detalles Específicos (si es Computador)</h6>
                <div class="row">
                    <div class="col-md-4 mb-3"><label for="activo_procesador" class="form-label">Procesador</label><input type="text" class="form-control" id="activo_procesador" name="activo_procesador"></div>
                    <div class="col-md-4 mb-3"><label for="activo_ram" class="form-label">RAM</label><input type="text" class="form-control" id="activo_ram" name="activo_ram"></div>
                    <div class="col-md-4 mb-3"><label for="activo_disco_duro" class="form-label">Disco Duro</label><input type="text" class="form-control" id="activo_disco_duro" name="activo_disco_duro"></div>
                </div>
                <div class="row">
                    <div class="col-md-3 mb-3"><label for="activo_tipo_equipo" class="form-label">Tipo Equipo</label><select class="form-select" id="activo_tipo_equipo" name="activo_tipo_equipo"><option value="">Seleccione...</option><?php foreach ($opciones_tipo_equipo as $opcion): ?><option value="<?= htmlspecialchars($opcion) ?>"><?= htmlspecialchars($opcion) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-3 mb-3"><label for="activo_red" class="form-label">Red</label><select class="form-select" id="activo_red" name="activo_red"><option value="">Seleccione...</option><?php foreach ($opciones_red as $opcion): ?><option value="<?= htmlspecialchars($opcion) ?>"><?= htmlspecialchars($opcion) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-3 mb-3"><label for="activo_so" class="form-label">SO</label><select class="form-select" id="activo_so" name="activo_sistema_operativo"><option value="">Seleccione...</option><?php foreach ($opciones_so as $opcion): ?><option value="<?= htmlspecialchars($opcion) ?>"><?= htmlspecialchars($opcion) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-3 mb-3"><label for="activo_offimatica" class="form-label">Offimática</label><select class="form-select" id="activo_offimatica" name="activo_offimatica"><option value="">Seleccione...</option><?php foreach ($opciones_offimatica as $opcion): ?><option value="<?= htmlspecialchars($opcion) ?>"><?= htmlspecialchars($opcion) ?></option><?php endforeach; ?></select></div>
                </div>
                <div class="row"><div class="col-md-4 mb-3"><label for="activo_antivirus" class="form-label">Antivirus</label><select class="form-select" id="activo_antivirus" name="activo_antivirus"><option value="">Seleccione...</option><?php foreach ($opciones_antivirus as $opcion): ?><option value="<?= htmlspecialchars($opcion) ?>"><?= htmlspecialchars($opcion) ?></option><?php endforeach; ?></select></div></div>
            </div>
            <div class="mb-3"><label for="detalles" class="form-label">Detalles Adicionales (Observaciones)</label><textarea class="form-control" id="detalles" name="activo_detalles" rows="2"></textarea></div>
            <div class="mb-3"><label class="form-label d-block">Califica tu nivel de satisfacción con este activo: <span class="text-danger">*</span></label><div class="star-rating" id="activo_satisfaccion_rating_container"><input type="radio" id="activo_star5" name="activo_satisfaccion_rating" value="5" /><label class="star-label" for="activo_star5" title="5 estrellas">☆</label><input type="radio" id="activo_star4" name="activo_satisfaccion_rating" value="4" /><label class="star-label" for="activo_star4" title="4 estrellas">☆</label><input type="radio" id="activo_star3" name="activo_satisfaccion_rating" value="3" /><label class="star-label" for="activo_star3" title="3 estrellas">☆</label><input type="radio" id="activo_star2" name="activo_satisfaccion_rating" value="2" /><label class="star-label" for="activo_star2" title="2 estrellas">☆</label><input type="radio" id="activo_star1" name="activo_satisfaccion_rating" value="1" /><label class="star-label" for="activo_star1" title="1 estrella">☆</label></div></div>
            <button type="button" class="btn btn-success" id="btnAgregarActivoTabla"><i class="bi bi-plus-circle"></i> Agregar Activo a la Lista</button>
        </div>

        <div class="form-section mt-4" id="seccionTablaActivos" style="display: none;">
            <h5 class="mb-3">3. Activos para Registrar a <strong id="nombreResponsableTabla"></strong></h5>
            <div class="table-responsive">
                <table class="table table-sm table-bordered table-hover table-activos-agregados">
                    <thead>
                        <tr>
                            <th>Tipo</th>
                            <th>Marca</th>
                            <th>Serie</th>
                            <th>F. Compra</th>
                            <th>Valor</th>
                            <th>Vida Útil</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody id="tablaActivosBody"></tbody>
                </table>
            </div>
            <p id="noActivosMensaje" class="text-muted">Aún no se han agregado activos a la lista.</p>
        </div>
        
        <div class="mt-4 d-grid gap-2">
            <button type="button" class="btn btn-primary btn-lg" id="btnGuardarTodo" disabled><i class="bi bi-save"></i> Guardar Todos los Activos y Finalizar</button>
        </div>
    </form>
</div>
<div class="modal fade" id="infoModal" tabindex="-1" aria-labelledby="infoModalLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="infoModalTitle"><i class="bi bi-exclamation-triangle-fill"></i> Atención</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><p id="infoModalMessage"></p></div><div class="modal-footer"><button type="button" class="btn btn-primary" data-bs-dismiss="modal">Entendido</button></div></div></div></div>

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

<script>
    // Todo el script hasta la función de envío permanece igual
    let activosParaGuardar = [];
    let responsableConfirmado = false; 
    let infoModalInstance;
    const formPrincipal = document.getElementById('formRegistrarLoteActivos');
    const seccionResponsable = document.getElementById('seccionResponsable');
    const seccionAgregarActivo = document.getElementById('seccionAgregarActivo');
    const seccionTablaActivos = document.getElementById('seccionTablaActivos');
    const btnConfirmarResponsable = document.getElementById('btnConfirmarResponsable');
    const btnEditarResponsable = document.getElementById('btnEditarResponsable');
    const btnAgregarActivoTabla = document.getElementById('btnAgregarActivoTabla');
    const btnGuardarTodo = document.getElementById('btnGuardarTodo');
    const tablaActivosBody = document.getElementById('tablaActivosBody');
    const noActivosMensaje = document.getElementById('noActivosMensaje');
    const inputCedulaResponsable = document.getElementById('cedula');
    const camposPrincipalesResponsableIds = ['nombre', 'cargo', 'regional', 'empresa_responsable'];
    const ratingContainer = document.getElementById('activo_satisfaccion_rating_container');
    const divInfoAplicaciones = document.createElement('div');
    divInfoAplicaciones.id = 'infoAplicacionesExistentes';
    divInfoAplicaciones.classList.add('form-text', 'mb-2', 'mt-1', 'p-2', 'border', 'border-info', 'rounded', 'bg-light');
    divInfoAplicaciones.style.display = 'none';
    const contenedorCheckboxesApp = document.getElementById('contenedorCheckboxesAplicaciones');
      if (contenedorCheckboxesApp) {
          contenedorCheckboxesApp.parentNode.insertBefore(divInfoAplicaciones, contenedorCheckboxesApp);
      }
    
    // --- INICIO: MODIFICACIÓN para incluir el nuevo campo de impresora ---
    const camposActivoIds = {
        tipo_activo: 'tipo_activo', 
        tipo_impresora: 'tipo_impresora', // <--- AÑADIDO
        marca: 'marca', 
        serie: 'serie', 
        estado: 'estado',
        valor_aproximado: 'valor_aproximado', 
        codigo_inv: 'codigo_inv', 
        detalles: 'detalles',
        fecha_compra: 'fecha_compra',
        vida_util: 'vida_util',
        valor_residual: 'valor_residual',
        metodo_depreciacion: 'metodo_depreciacion',
        procesador: 'activo_procesador', 
        ram: 'activo_ram', 
        disco_duro: 'activo_disco_duro',
        tipo_equipo: 'activo_tipo_equipo', 
        red: 'activo_red', 
        sistema_operativo: 'activo_so',
        offimatica: 'activo_offimatica', 
        antivirus: 'activo_antivirus',
        satisfaccion_rating_name: 'activo_satisfaccion_rating'
    };
    // --- FIN: MODIFICACIÓN ---

    const campoTipoActivo = document.getElementById('tipo_activo');
    const campoVidaUtil = document.getElementById('vida_util');

    // --- INICIO DE LA MODIFICACIÓN ---
    // Reemplazamos el objeto estático con uno generado dinámicamente por PHP.
    const vidaUtilPorTipo = <?php echo json_encode($vida_util_map); ?>;
    // --- FIN DE LA MODIFICACIÓN ---

    // --- INICIO: Lógica para campo condicional de Impresora ---
    const campoTipoImpresoraContainer = document.getElementById('campo_tipo_impresora_container');
    const campoTipoImpresoraSelect = document.getElementById('tipo_impresora');

    campoTipoActivo.addEventListener('change', function() {
        const tipoSeleccionado = this.value;
        // La lógica sigue funcionando igual, pero ahora usa los datos de la BD
        campoVidaUtil.value = vidaUtilPorTipo[tipoSeleccionado] || '';
        
        // Mostrar/ocultar campos de computador
        document.getElementById('campos_computador_form_activo').style.display = (tipoSeleccionado === 'Computador') ? 'block' : 'none';
        
        // Mostrar/ocultar campo de tipo de impresora
        if (tipoSeleccionado === 'Impresora') {
            campoTipoImpresoraContainer.style.display = 'block';
            campoTipoImpresoraSelect.required = true;
        } else {
            campoTipoImpresoraContainer.style.display = 'none';
            campoTipoImpresoraSelect.required = false;
            campoTipoImpresoraSelect.value = ''; // Limpiar valor si se oculta
        }
    });
    // --- FIN: Lógica para campo condicional ---

    btnAgregarActivoTabla.addEventListener('click', function() {
        if (!responsableConfirmado) {
            mostrarInfoModal('Confirme primero', 'Primero debe confirmar los datos del responsable.');
            return;
        }
        const activo = {}; let activoValido = true; let camposActivoForm = {};
        for (const key in camposActivoIds) {
            const inputElement = document.getElementById(camposActivoIds[key]);
            if (key === 'satisfaccion_rating_name') {
                const ratingChecked = document.querySelector(`input[name="${camposActivoIds[key]}"]:checked`);
                activo[key] = ratingChecked ? ratingChecked.value : null;
            } else if (inputElement) {
                // Solo se guarda el valor del tipo de impresora si su contenedor es visible
                if (key === 'tipo_impresora' && campoTipoImpresoraContainer.style.display === 'none') {
                    activo[key] = '';
                } else {
                    activo[key] = inputElement.value.trim();
                }
                camposActivoForm[key] = inputElement;
            } else {
                activo[key] = '';
            }
        }
        
// --- INICIO DEL NUEVO BLOQUE DE VALIDACIÓN ---

let formEsValido = true;

// 1. Mapeo de los campos requeridos estándar y sus elementos.
const camposAValidar = {
    tipo_activo: document.getElementById('tipo_activo'),
    marca: document.getElementById('marca'),
    serie: document.getElementById('serie'),
    estado: document.getElementById('estado'),
    valor_aproximado: document.getElementById('valor_aproximado'),
    fecha_compra: document.getElementById('fecha_compra')
};

// 2. Bucle para validar cada campo estándar.
for (const key in camposAValidar) {
    const campoElemento = camposAValidar[key];
    // Usamos el objeto 'activo' que ya tiene los valores limpios (trim)
    if (!activo[key]) {
        campoElemento.classList.add('is-invalid');
        formEsValido = false;
    } else {
        campoElemento.classList.remove('is-invalid');
    }
}

    // 3. Validación especial para campos condicionales y personalizados.

    // Valida el tipo de impresora SOLO si el activo es una 'Impresora'
    const tipoImpresoraSelect = document.getElementById('tipo_impresora');
    if (activo.tipo_activo === 'Impresora') {
        if (!activo.tipo_impresora) {
            tipoImpresoraSelect.classList.add('is-invalid');
            formEsValido = false;
        } else {
            tipoImpresoraSelect.classList.remove('is-invalid');
        }
    } else {
        // Nos aseguramos de que no se quede marcado como inválido si cambiamos el tipo de activo.
        tipoImpresoraSelect.classList.remove('is-invalid');
    }

    // Valida la calificación por estrellas
    const ratingContainer = document.getElementById('activo_satisfaccion_rating_container');
    if (!activo.satisfaccion_rating_name) {
        ratingContainer.classList.add('rating-invalid');
        formEsValido = false;
    } else {
        ratingContainer.classList.remove('rating-invalid');
    }

    // 4. Verificación final. Si algo falló, muestra el modal y detiene.
    if (!formEsValido) {
        mostrarInfoModal('Campos Incompletos', 'Por favor, complete todos los campos obligatorios resaltados en rojo.');
    } else if (isNaN(parseFloat(activo.valor_aproximado))) {
        // Mantenemos la validación de si el valor es un número, aunque el campo no esté vacío.
        mostrarInfoModal('Formato incorrecto', 'El valor del activo debe ser un número.');
        document.getElementById('valor_aproximado').classList.add('is-invalid');
    } else {
        // Si todo está perfecto, agrega el activo a la lista y limpia el formulario.
        activosParaGuardar.push(activo);
        actualizarTablaActivos();
        limpiarFormularioActivo(camposActivoForm);
        // Resetea cualquier campo que pudiera haber quedado como inválido
        for (const key in camposAValidar) {
            camposAValidar[key].classList.remove('is-invalid');
        }
        tipoImpresoraSelect.classList.remove('is-invalid');
        ratingContainer.classList.remove('rating-invalid');
        campoTipoActivo.dispatchEvent(new Event('change'));
    }
    // --- FIN DEL NUEVO BLOQUE DE VALIDACIÓN ---
    });
    function actualizarTablaActivos() {
        tablaActivosBody.innerHTML = '';
        if (activosParaGuardar.length === 0) {
            noActivosMensaje.style.display = 'block';
            btnGuardarTodo.disabled = true;
            return;
        }
        noActivosMensaje.style.display = 'none';
        btnGuardarTodo.disabled = false;
        activosParaGuardar.forEach((activo, index) => {
            const fila = tablaActivosBody.insertRow();
            // Si es una impresora, podemos añadir el tipo al texto
            let tipoDisplay = activo.tipo_impresora ? `${activo.tipo_activo} (${activo.tipo_impresora})` : (activo.tipo_activo || 'N/A');
            fila.insertCell().textContent = tipoDisplay;
            fila.insertCell().textContent = activo.marca || 'N/A';
            fila.insertCell().textContent = activo.serie || 'N/A';
            fila.insertCell().textContent = activo.fecha_compra || 'N/A';
            fila.insertCell().textContent = activo.valor_aproximado ? new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP' }).format(activo.valor_aproximado) : 'N/A';
            fila.insertCell().textContent = activo.vida_util ? `${activo.vida_util} años` : 'N/A';
            const celdaAccion = fila.insertCell();
            const btnEliminar = document.createElement('button');
            btnEliminar.type = 'button';
            btnEliminar.classList.add('btn', 'btn-danger', 'btn-sm', 'btn-remove-asset');
            btnEliminar.innerHTML = '<i class="bi bi-trash"></i>';
            btnEliminar.title = 'Eliminar de la lista';
            btnEliminar.onclick = function() { eliminarActivoDeLista(index); };
            celdaAccion.appendChild(btnEliminar);
        });
    }
    function limpiarFormularioActivo(camposActivoFormElements) {
        for (const key in camposActivoFormElements) {
            if (key !== 'vida_util' && key !== 'valor_residual' && key !== 'metodo_depreciacion') {
                if (camposActivoFormElements[key]) camposActivoFormElements[key].value = '';
            }
        }
        const radiosEstrellas = document.querySelectorAll(`input[name="activo_satisfaccion_rating"]`);
        radiosEstrellas.forEach(radio => radio.checked = false);
        document.getElementById('estado').value = 'Nuevo';
        document.getElementById('tipo_activo').dispatchEvent(new Event('change'));
    }
    function eliminarActivoDeLista(index) {
        activosParaGuardar.splice(index, 1);
        actualizarTablaActivos();
    }
    // ... el resto del script para el responsable, modal, etc., va aquí y no cambia...
    function setPrincipalResponsableFieldsDisabled(isDisabled) {
        camposPrincipalesResponsableIds.forEach(id => {
            const campo = document.getElementById(id);
            if (campo) campo.disabled = isDisabled;
        });
    }
    function setAplicacionesFieldsDisabled(isDisabled) {
        document.querySelectorAll('#contenedorCheckboxesAplicaciones .form-check-input').forEach(checkbox => {
            checkbox.disabled = isDisabled;
        });
        const textoOtros = document.getElementById('responsable_aplicaciones_otros_texto');
        if (textoOtros) textoOtros.disabled = isDisabled;
    }
    btnConfirmarResponsable.addEventListener('click', function() {
        let valido = true;
        if (!inputCedulaResponsable.value.trim()) {
            valido = false; inputCedulaResponsable.classList.add('is-invalid');
        } else { inputCedulaResponsable.classList.remove('is-invalid'); }
        camposPrincipalesResponsableIds.forEach(id => {
            const input = document.getElementById(id);
            if (!input.disabled && !input.value.trim()) {
                valido = false; input.classList.add('is-invalid');
            } else if (!input.disabled) { input.classList.remove('is-invalid'); }
        });
        const appsCheckboxes = document.querySelectorAll('#contenedorCheckboxesAplicaciones .form-check-input:not([disabled])');
        let appsSeleccionadas = 0;
        appsCheckboxes.forEach(chk => { if(chk.checked) appsSeleccionadas++; });
        const otrosAppTextoInput = document.getElementById('responsable_aplicaciones_otros_texto');
        const otrosAppCheckbox = document.getElementById('app_Otros');
        const otrosAppTexto = otrosAppTextoInput ? otrosAppTextoInput.value.trim() : '';
        if (appsCheckboxes.length > 0 && !appsCheckboxes[0].disabled) { 
            if (appsSeleccionadas === 0 && !(otrosAppCheckbox && otrosAppCheckbox.checked && otrosAppTexto !== '')) {
                 valido = false;
                 mostrarInfoModal('Campos incompletos', 'Por favor, seleccione al menos una aplicación que más usa el responsable o especifique en "Otros".');
            } else if (otrosAppCheckbox && otrosAppCheckbox.checked && otrosAppTexto === '') {
                 valido = false;
                 mostrarInfoModal('Campos incompletos', 'Si marca "Otros" en aplicaciones, por favor especifique cuál(es).');
            }
        }
        if (valido) {
            inputCedulaResponsable.disabled = true;
            setPrincipalResponsableFieldsDisabled(true);
            setAplicacionesFieldsDisabled(true);
            this.style.display = 'none';
            btnEditarResponsable.style.display = 'inline-block';
            seccionAgregarActivo.style.display = 'block';
            seccionTablaActivos.style.display = 'block';
            document.getElementById('nombreResponsableDisplay').textContent = document.getElementById('nombre').value;
            document.getElementById('nombreResponsableTabla').textContent = document.getElementById('nombre').value;
            responsableConfirmado = true;
        } else {
            let errorMostrado = false;
            if (document.querySelector('.is-invalid')) { 
                 mostrarInfoModal('Campos incompletos', 'Por favor, complete todos los campos de información del responsable marcados con *.');
                 errorMostrado = true;
            }
        }
    });
    btnEditarResponsable.addEventListener('click', function() {
        inputCedulaResponsable.disabled = false;
        setPrincipalResponsableFieldsDisabled(false);
        this.style.display = 'none';
        btnConfirmarResponsable.style.display = 'inline-block';
        seccionAgregarActivo.style.display = 'none';
        seccionTablaActivos.style.display = 'none';
        btnGuardarTodo.disabled = true;
        responsableConfirmado = false;
        inputCedulaResponsable.dispatchEvent(new Event('blur'));
        inputCedulaResponsable.focus();
    });
    const checkOtrosApp = document.getElementById('app_Otros');
    const textoOtrosApp = document.getElementById('responsable_aplicaciones_otros_texto');
    if (checkOtrosApp) {
        checkOtrosApp.addEventListener('change', function() {
            if (this.checked && !this.disabled) {
                textoOtrosApp.style.display = 'block';
                textoOtrosApp.setAttribute('required', 'true');
            } else {
                textoOtrosApp.style.display = 'none';
                textoOtrosApp.removeAttribute('required');
                if (!this.disabled) textoOtrosApp.value = '';
            }
        });
    }
    inputCedulaResponsable.addEventListener('blur', function() {
        const cedulaVal = this.value.trim();
        const checkBoxesAppsNodeList = document.querySelectorAll('#contenedorCheckboxesAplicaciones .form-check-input');
        const textoOtrosAppInput = document.getElementById('responsable_aplicaciones_otros_texto');
        const checkOtrosAppInput = document.getElementById('app_Otros');
        setPrincipalResponsableFieldsDisabled(false);
        btnEditarResponsable.style.display = 'none';
        btnConfirmarResponsable.style.display = 'inline-block';
        checkBoxesAppsNodeList.forEach(cb => { cb.checked = false; cb.disabled = false; });
        if (textoOtrosAppInput) { textoOtrosAppInput.value = ''; textoOtrosAppInput.disabled = false; textoOtrosAppInput.style.display = 'none';}
        if (checkOtrosAppInput) { checkOtrosAppInput.checked = false; checkOtrosAppInput.disabled = false; }
        if (cedulaVal) {
            buscar_datos_usuario_ajax(cedulaVal, function(data) {
                if (data.encontrado) {
                    ['nombre', 'cargo', 'regional', 'empresa_responsable'].forEach(id => {
                        const inputElement = document.getElementById(id);
                        const dataKey = id === 'nombre' ? 'nombre_completo' : (id === 'empresa_responsable' ? 'empresa' : id);
                        if (inputElement) { inputElement.value = data[dataKey] || ''; }
                    });
                    setPrincipalResponsableFieldsDisabled(true);
                    btnEditarResponsable.style.display = 'inline-block';
                    if (data.aplicaciones_usadas && data.aplicaciones_usadas.trim() !== '') {
                        divInfoAplicaciones.innerHTML = `📝 <strong>Información:</strong> Este responsable ya tiene las siguientes aplicaciones asignadas: <br><strong>${data.aplicaciones_usadas}</strong>.<br>Estos campos han sido bloqueados. Para modificarlos, use "Gestionar Usuarios".`;
                        divInfoAplicaciones.style.display = 'block';
                        const appsGuardadas = data.aplicaciones_usadas.split(',').map(app => app.trim());
                        let otrasAppsTextoGuardado = '';
                        appsGuardadas.forEach(appGuardada => {
                            if (appGuardada.startsWith('Otros: ')) {
                                otrasAppsTextoGuardado = appGuardada.substring('Otros: '.length);
                            }
                        });
                        checkBoxesAppsNodeList.forEach(cb => {
                            if ((cb.value === "Otros" && otrasAppsTextoGuardado) || appsGuardadas.includes(cb.value)) {
                                cb.checked = true;
                            }
                            cb.disabled = true;
                        });
                        if (checkOtrosAppInput && otrasAppsTextoGuardado) {
                             checkOtrosAppInput.checked = true;
                             if (textoOtrosAppInput) {
                                  textoOtrosAppInput.value = otrasAppsTextoGuardado;
                                  textoOtrosAppInput.style.display = 'block';
                                  textoOtrosAppInput.disabled = true;
                             }
                        } else if (textoOtrosAppInput) {
                            textoOtrosAppInput.style.display = 'none';
                            textoOtrosAppInput.disabled = true;
                        }
                         if (checkOtrosAppInput) checkOtrosAppInput.disabled = true;
                    } else { 
                        divInfoAplicaciones.textContent = 'Este responsable aún no tiene aplicaciones frecuentes registradas. Por favor, selecciónalas.';
                        divInfoAplicaciones.style.display = 'block';
                        setAplicacionesFieldsDisabled(false);
                    }
                } else { 
                    document.getElementById('nombre').value = '';
                    document.getElementById('cargo').value = '';
                    document.getElementById('regional').value = '';
                    document.getElementById('empresa_responsable').value = '';
                    setPrincipalResponsableFieldsDisabled(false);
                    setAplicacionesFieldsDisabled(false);
                    divInfoAplicaciones.style.display = 'none';
                }
            });
        } else {
            document.getElementById('nombre').value = '';
            document.getElementById('cargo').value = '';
            document.getElementById('regional').value = '';
            document.getElementById('empresa_responsable').value = '';
            setPrincipalResponsableFieldsDisabled(false);
            divInfoAplicaciones.style.display = 'none';
            setAplicacionesFieldsDisabled(false);
            if (checkOtrosAppInput) checkOtrosAppInput.dispatchEvent(new Event('change'));
        }
    });
    function mostrarInfoModal(titulo, mensaje) {
        const modalElement = document.getElementById('infoModal');
        const modalTitleElement = document.getElementById('infoModalTitle');
        const modalMessageElement = document.getElementById('infoModalMessage');
        if (!infoModalInstance && modalElement) { infoModalInstance = new bootstrap.Modal(modalElement); }
        if (modalTitleElement) { modalTitleElement.innerHTML = `<i class="bi bi-exclamation-triangle-fill"></i> ${titulo}`; }
        if (modalMessageElement) { modalMessageElement.textContent = mensaje; }
        if (infoModalInstance) { infoModalInstance.show(); }
    }
    function buscar_datos_usuario_ajax(cedula, callback) {
        if (!cedula) {
            callback({ encontrado: false, mensaje: 'Cédula no proporcionada.' });
            return;
        }
        fetch(`buscar_datos_usuario.php?cedula=${encodeURIComponent(cedula)}`)
            .then(response => { if (!response.ok) { throw new Error('Error en la red: ' + response.statusText); } return response.json(); })
            .then(data => { callback(data); })
            .catch(error => { console.error('Error AJAX:', error); callback({ encontrado: false, mensaje: 'Error al contactar el servidor.' }); });
    }

    // USAR UN EVENTO 'CLICK' EN LUGAR DE 'SUBMIT'
    btnGuardarTodo.addEventListener('click', function() {
        console.log("--- Botón 'Guardar Todo' presionado. Iniciando proceso. ---");

        if (!responsableConfirmado) { 
            mostrarInfoModal('Confirme Responsable', 'Primero debe confirmar los datos del responsable usando el botón correspondiente.');
            return;
        }
        if (activosParaGuardar.length === 0) {
            mostrarInfoModal('Sin Activos', 'Debe agregar al menos un activo a la lista antes de guardar.');
            return;
        }

        console.log("Validaciones pasadas. Preparando formulario para envío.");

        inputCedulaResponsable.disabled = false;
        setPrincipalResponsableFieldsDisabled(false);
        setAplicacionesFieldsDisabled(false); 
        document.getElementById('metodo_depreciacion').disabled = false;

        formPrincipal.querySelectorAll('input[type="hidden"]').forEach(el => {
            if (el.name.startsWith('activos[')) {
                el.remove();
            }
        });

        activosParaGuardar.forEach((activo, index) => {
            for (const propiedad in activo) {
                const inputHidden = document.createElement('input');
                inputHidden.type = 'hidden';
                let fieldName = propiedad;
                if (propiedad === 'satisfaccion_rating_name') { fieldName = 'satisfaccion_rating'; }
                inputHidden.name = `activos[${index}][${fieldName}]`;
                inputHidden.value = activo[propiedad] === null ? '' : activo[propiedad];
                formPrincipal.appendChild(inputHidden);
            }
        });

        // --- INICIO DEL BLOQUE DE EVENTOS DE AUTOCORRECCIÓN ---

        // Lista de IDs de los campos a monitorear
        const idsCamposParaMonitorear = [
            'tipo_activo', 
            'tipo_impresora', 
            'marca', 
            'serie', 
            'estado', 
            'valor_aproximado', 
            'fecha_compra'
        ];

        // Agregamos un 'oyente' a cada campo
        idsCamposParaMonitorear.forEach(id => {
            const campo = document.getElementById(id);
            if (campo) {
                // 'change' funciona bien para selects y fechas.
                // 'input' funciona para campos de texto y número, para que se quite al escribir.
                campo.addEventListener('change', () => campo.classList.remove('is-invalid'));
                campo.addEventListener('input', () => campo.classList.remove('is-invalid'));
            }
        });

        // El 'oyente' para las estrellas (que ya tenías) también lo dejamos aquí para tener todo junto
        const ratingContainer = document.getElementById('activo_satisfaccion_rating_container');
        if (ratingContainer) {
            ratingContainer.addEventListener('change', function() {
                this.classList.remove('rating-invalid');
            });
        }
    });
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>