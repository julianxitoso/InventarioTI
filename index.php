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

// Tu PHP para definir $regionales, $empresas_disponibles, etc., no cambia.
$regionales = ['Popayan', 'Bordo', 'Santander', 'Valle', 'Pasto', 'Tuquerres', 'Huila', 'Nacional'];
$empresas_disponibles = ['Arpesod', 'Finansueños'];
$opciones_tipo_activo = ['Computador', 'Monitor', 'Impresora', 'Escáner', 'DVR', 'Contadora Billetes', 'Contadora Monedas', 'Celular', 'Impresora Térmica', 'Combo Teclado y Mouse', 'Diadema', 'Adaptador Multipuertos / Red', 'Router', 'Otro'];
$opciones_tipo_equipo = ['Portátil', 'Mesa', 'Todo en 1', 'N/A'];
$opciones_red = ['Cableada', 'Inalámbrica', 'Ambas', 'N/A'];
$opciones_estado_general = ['Bueno', 'Regular', 'Malo', 'Nuevo'];
$opciones_so = ['Windows 10', 'Windows 11', 'Linux', 'MacOS', 'Otro SO', 'N/A SO'];
$opciones_offimatica = ['Office 365', 'Office Home And Business', 'Office 2021', 'Office 2019', 'Office 2016', 'LibreOffice', 'Google Workspace', 'Otro Office', 'N/A Office'];
$opciones_antivirus = ['Microsoft Defender', 'Bitdefender', 'ESET NOD32 Antivirus', 'McAfee Total Protection', 'Kaspersky', 'N/A Antivirus', 'Otro Antivirus'];
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
        /* --- AJUSTE/NUEVO --- Estilo para el mensaje de info de aplicaciones */
        #infoAplicacionesExistentes { font-size: 0.85em; }
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
                <div class="col-md-4 mb-3"><label for="tipo_activo" class="form-label">Tipo de Activo <span class="text-danger">*</span></label><select class="form-select" id="tipo_activo" name="activo_tipo_activo"><option value="">Seleccione...</option><?php foreach ($opciones_tipo_activo as $opcion): ?><option value="<?= htmlspecialchars($opcion) ?>"><?= htmlspecialchars($opcion) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-4 mb-3"><label for="marca" class="form-label">Marca <span class="text-danger">*</span></label><input type="text" class="form-control" id="marca" name="activo_marca"></div>
                <div class="col-md-4 mb-3"><label for="serie" class="form-label">Serie / Serial <span class="text-danger">*</span></label><input type="text" class="form-control" id="serie" name="activo_serie"></div>
            </div>
            <div class="row">
                <div class="col-md-4 mb-3"><label for="estado" class="form-label">Estado del Activo <span class="text-danger">*</span></label><select class="form-select" id="estado" name="activo_estado"><option value="Nuevo">Nuevo</option><?php foreach ($opciones_estado_general as $opcion): if($opcion !== 'Nuevo' && $opcion !== 'Dado de Baja') { ?><option value="<?= htmlspecialchars($opcion) ?>"><?= htmlspecialchars($opcion) ?></option><?php } endforeach; ?></select></div>
                <div class="col-md-4 mb-3"><label for="valor_aproximado" class="form-label">Valor Aproximado <span class="text-danger">*</span></label><input type="number" class="form-control" id="valor_aproximado" name="activo_valor_aproximado" step="0.01" min="0"></div>
                <div class="col-md-4 mb-3"><label for="codigo_inv" class="form-label">Código Inventario (Opcional)</label><input type="text" class="form-control" id="codigo_inv" name="activo_codigo_inv"></div>
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
            <div class="mb-3"><label class="form-label d-block">Califica tu nivel de satisfacción con este activo:</label><div class="star-rating" id="activo_satisfaccion_rating_container"><input type="radio" id="activo_star5" name="activo_satisfaccion_rating" value="5" /><label class="star-label" for="activo_star5" title="5 estrellas">☆</label><input type="radio" id="activo_star4" name="activo_satisfaccion_rating" value="4" /><label class="star-label" for="activo_star4" title="4 estrellas">☆</label><input type="radio" id="activo_star3" name="activo_satisfaccion_rating" value="3" /><label class="star-label" for="activo_star3" title="3 estrellas">☆</label><input type="radio" id="activo_star2" name="activo_satisfaccion_rating" value="2" /><label class="star-label" for="activo_star2" title="2 estrellas">☆</label><input type="radio" id="activo_star1" name="activo_satisfaccion_rating" value="1" /><label class="star-label" for="activo_star1" title="1 estrella">☆</label></div></div>
            <button type="button" class="btn btn-success" id="btnAgregarActivoTabla"><i class="bi bi-plus-circle"></i> Agregar Activo a la Lista</button>
        </div>

        <div class="form-section mt-4" id="seccionTablaActivos" style="display: none;">
            <h5 class="mb-3">3. Activos para Registrar a <strong id="nombreResponsableTabla"></strong></h5>
            <div class="table-responsive"><table class="table table-sm table-bordered table-hover table-activos-agregados"><thead><tr><th>Tipo</th><th>Marca</th><th>Serie</th><th>Estado</th><th>Valor</th><th>Satisfacción</th><th>Acción</th></tr></thead><tbody id="tablaActivosBody"></tbody></table></div>
            <p id="noActivosMensaje" class="text-muted">Aún no se han agregado activos a la lista.</p>
        </div>
        
        <div class="mt-4 d-grid gap-2">
            <button type="submit" class="btn btn-primary btn-lg" id="btnGuardarTodo" disabled><i class="bi bi-save"></i> Guardar Todos los Activos y Finalizar</button>
        </div>
    </form>
</div>
<div class="modal fade" id="infoModal" tabindex="-1" aria-labelledby="infoModalLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="infoModalTitle"><i class="bi bi-exclamation-triangle-fill"></i> Atención</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><p id="infoModalMessage"></p></div><div class="modal-footer"><button type="button" class="btn btn-primary" data-bs-dismiss="modal">Entendido</button></div></div></div></div>
    
<script>
    let activosParaGuardar = [];
    let responsableConfirmado = false; // Indica si se ha pasado la validación inicial del responsable
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
    const divInfoAplicaciones = document.createElement('div');
    divInfoAplicaciones.id = 'infoAplicacionesExistentes';
    divInfoAplicaciones.classList.add('form-text', 'mb-2', 'mt-1', 'p-2', 'border', 'border-info', 'rounded', 'bg-light');
    divInfoAplicaciones.style.display = 'none';
    const contenedorCheckboxesApp = document.getElementById('contenedorCheckboxesAplicaciones');
     if (contenedorCheckboxesApp) {
        contenedorCheckboxesApp.parentNode.insertBefore(divInfoAplicaciones, contenedorCheckboxesApp);
    }

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

        if (appsCheckboxes.length > 0 && !appsCheckboxes[0].disabled) { // Solo validar si los checkboxes de apps están habilitados
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
            if (document.querySelector('.is-invalid')) { // Si hay errores de validación de campos principales
                 mostrarInfoModal('Campos incompletos', 'Por favor, complete todos los campos de información del responsable marcados con *.');
                 errorMostrado = true;
            }
            // Si el error no fue por campos principales, y ya se mostró el de aplicaciones, no mostrar otro.
            // Esta parte previene mostrar el error de "complete todos los campos" si ya se mostró el de "seleccione apps".
             if (!errorMostrado && !(appsCheckboxes.length > 0 && !appsCheckboxes[0].disabled && (appsSeleccionadas === 0 || (otrosAppCheckbox && otrosAppCheckbox.checked && otrosAppTexto === '')))){
                // Este if es para que no se sobreescriba el mensaje de apps si ya se mostró.
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
        
        inputCedulaResponsable.dispatchEvent(new Event('blur')); // Re-evaluar estado basado en la cédula actual
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

        // Siempre habilitar campos principales al inicio del blur para permitir nueva entrada o autocompletado
        setPrincipalResponsableFieldsDisabled(false);
        btnEditarResponsable.style.display = 'none';
        btnConfirmarResponsable.style.display = 'inline-block';

        // Resetear checkboxes de aplicaciones: limpiar selección y habilitar
        checkBoxesAppsNodeList.forEach(cb => { cb.checked = false; cb.disabled = false; });
        if (textoOtrosAppInput) { textoOtrosAppInput.value = ''; textoOtrosAppInput.disabled = false; textoOtrosAppInput.style.display = 'none';}
        if (checkOtrosAppInput) { checkOtrosAppInput.checked = false; checkOtrosAppInput.disabled = false; }


        if (cedulaVal) {
            buscar_datos_usuario_ajax(cedulaVal, function(data) {
                if (data.encontrado) {
                    // --- AJUSTE/NUEVO --- Forzar autocompletado de campos principales
                    ['nombre', 'cargo', 'regional', 'empresa_responsable'].forEach(id => {
                        const inputElement = document.getElementById(id);
                        const dataKey = id === 'nombre' ? 'nombre_completo' : (id === 'empresa_responsable' ? 'empresa' : id);
                        if (inputElement) { // Siempre actualizar si se encontró data
                            inputElement.value = data[dataKey] || ''; 
                        }
                    });
                    // --- FIN AJUSTE/NUEVO ---

                    setPrincipalResponsableFieldsDisabled(true); // Bloquear campos principales
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
                    // --- AJUSTE/NUEVO --- Limpiar campos principales si el usuario no fue encontrado
                    document.getElementById('nombre').value = '';
                    document.getElementById('cargo').value = '';
                    document.getElementById('regional').value = '';
                    document.getElementById('empresa_responsable').value = '';
                    // --- FIN AJUSTE/NUEVO ---
                    setPrincipalResponsableFieldsDisabled(false);
                    setAplicacionesFieldsDisabled(false);
                    divInfoAplicaciones.style.display = 'none';
                }
            });
        } else {
            // --- AJUSTE/NUEVO --- Limpiar campos principales si la cédula está vacía
            document.getElementById('nombre').value = '';
            document.getElementById('cargo').value = '';
            document.getElementById('regional').value = '';
            document.getElementById('empresa_responsable').value = '';
            // --- FIN AJUSTE/NUEVO ---
            setPrincipalResponsableFieldsDisabled(false);
            divInfoAplicaciones.style.display = 'none';
            setAplicacionesFieldsDisabled(false);
            if (checkOtrosAppInput) checkOtrosAppInput.dispatchEvent(new Event('change'));
        }
    });
    
    // El resto de tus funciones (mostrarInfoModal, manejo de tabla de activos, etc.)
    // deberían funcionar bien con estos cambios.
    // Asegúrate de que la función buscar_datos_usuario_ajax esté definida y funcione correctamente.

    const camposActivoIds = {
        tipo_activo: 'tipo_activo', marca: 'marca', serie: 'serie', estado: 'estado',
        valor_aproximado: 'valor_aproximado', codigo_inv: 'codigo_inv', detalles: 'detalles',
        procesador: 'activo_procesador', ram: 'activo_ram', disco_duro: 'activo_disco_duro',
        tipo_equipo: 'activo_tipo_equipo', red: 'activo_red', sistema_operativo: 'activo_so',
        offimatica: 'activo_offimatica', antivirus: 'activo_antivirus',
        satisfaccion_rating_name: 'activo_satisfaccion_rating'
    };
    
    function mostrarInfoModal(titulo, mensaje) {
        const modalElement = document.getElementById('infoModal');
        const modalTitleElement = document.getElementById('infoModalTitle'); 
        const modalMessageElement = document.getElementById('infoModalMessage'); 
        if (!infoModalInstance && modalElement) { infoModalInstance = new bootstrap.Modal(modalElement); }
        if (modalTitleElement) { modalTitleElement.innerHTML = `<i class="bi bi-exclamation-triangle-fill"></i> ${titulo}`; }
        if (modalMessageElement) { modalMessageElement.textContent = mensaje; }
        if (infoModalInstance) { infoModalInstance.show(); }
    }
    
    document.getElementById('tipo_activo').addEventListener('change', function() {
        document.getElementById('campos_computador_form_activo').style.display = (this.value === 'Computador') ? 'block' : 'none';
    });
    
    btnAgregarActivoTabla.addEventListener('click', function() {
        if (!responsableConfirmado) {
            mostrarInfoModal('Confirme primero', 'Primero debe confirmar los datos del responsable usando el botón correspondiente.');
            return;
        }
        const activo = {}; let activoValido = true; let camposActivoForm = {};
        for (const key in camposActivoIds) {
            if (key === 'satisfaccion_rating_name') {
                const ratingChecked = document.querySelector(`input[name="${camposActivoIds[key]}"]:checked`);
                activo[key] = ratingChecked ? ratingChecked.value : null;
            } else {
                const inputElement = document.getElementById(camposActivoIds[key]);
                if (inputElement) { activo[key] = inputElement.value.trim(); camposActivoForm[key] = inputElement; } else { activo[key] = ''; }
            }
        }
        if (!activo.tipo_activo || !activo.marca || !activo.serie || !activo.estado || !activo.valor_aproximado) {
            mostrarInfoModal('Campos incompletos', 'Complete los campos obligatorios del activo: Tipo, Marca, Serie, Estado, Valor.'); activoValido = false;
        }
        if(isNaN(parseFloat(activo.valor_aproximado)) && activo.valor_aproximado !== '') {
            mostrarInfoModal('Formato incorrecto', 'El valor aproximado debe ser un número.'); activoValido = false;
        }
        if (activoValido) {
            activosParaGuardar.push(activo); actualizarTablaActivos(); limpiarFormularioActivo(camposActivoForm);
            document.getElementById('tipo_activo').dispatchEvent(new Event('change'));
        }
    });

    function actualizarTablaActivos() {
        tablaActivosBody.innerHTML = '';
        if (activosParaGuardar.length === 0) { noActivosMensaje.style.display = 'block'; btnGuardarTodo.disabled = true; return; }
        noActivosMensaje.style.display = 'none'; btnGuardarTodo.disabled = false;
        activosParaGuardar.forEach((activo, index) => {
            const fila = tablaActivosBody.insertRow();
            fila.insertCell().textContent = activo.tipo_activo || 'N/A';
            fila.insertCell().textContent = activo.marca || 'N/A';
            fila.insertCell().textContent = activo.serie || 'N/A';
            fila.insertCell().textContent = activo.estado || 'N/A';
            fila.insertCell().textContent = activo.valor_aproximado || 'N/A';
            let estrellasDisplay = '';
            if (activo.satisfaccion_rating_name) { for (let i = 0; i < 5; i++) { estrellasDisplay += (i < parseInt(activo.satisfaccion_rating_name)) ? '★' : '☆'; } } else { estrellasDisplay = 'N/A'; }
            fila.insertCell().innerHTML = `<span style="color: #f5b301; font-size:1.2em;">${estrellasDisplay}</span>`;
            const celdaAccion = fila.insertCell(); const btnEliminar = document.createElement('button');
            btnEliminar.type = 'button'; btnEliminar.classList.add('btn', 'btn-danger', 'btn-sm', 'btn-remove-asset');
            btnEliminar.innerHTML = '<i class="bi bi-trash"></i>'; btnEliminar.title = 'Eliminar de la lista';
            btnEliminar.onclick = function() { eliminarActivoDeLista(index); }; celdaAccion.appendChild(btnEliminar);
        });
    }

    function limpiarFormularioActivo(camposActivoFormElements) {
        for (const key in camposActivoFormElements) { if(camposActivoFormElements[key]) camposActivoFormElements[key].value = ''; }
        const radiosEstrellas = document.querySelectorAll(`input[name="activo_satisfaccion_rating"]`);
        radiosEstrellas.forEach(radio => radio.checked = false);
        document.getElementById('estado').value = 'Nuevo';
    }

    function eliminarActivoDeLista(index) { activosParaGuardar.splice(index, 1); actualizarTablaActivos(); }
    
    // --- AJUSTE/NUEVO --- Lógica de habilitación de campos ANTES del submit
    formPrincipal.addEventListener('submit', function(event) {
        if (!responsableConfirmado) { 
            mostrarInfoModal('Confirme Responsable', 'Primero debe confirmar los datos del responsable usando el botón correspondiente.');
            event.preventDefault(); return false;
        }
        if (activosParaGuardar.length === 0) {
            mostrarInfoModal('Sin Activos', 'Debe agregar al menos un activo a la lista antes de guardar.');
            event.preventDefault(); return false;
        }
    
        // Habilitar TODOS los campos del responsable (incluyendo cédula y apps) ANTES de enviar el formulario
        // ya que el backend los espera, y los campos deshabilitados no se envían.
        inputCedulaResponsable.disabled = false;
        setPrincipalResponsableFieldsDisabled(false);
        setAplicacionesFieldsDisabled(false); 

        // Deshabilitar campos del formulario de activo individual (los que están en la sección 2)
        // para que no se envíen sueltos, ya que sus datos están en el array activosParaGuardar.
        for (const key in camposActivoIds) {
            const inputElement = document.getElementById(camposActivoIds[key]);
            if (inputElement && key !== 'satisfaccion_rating_name') { inputElement.disabled = true; }
        }
        document.querySelectorAll(`input[name="${camposActivoIds.satisfaccion_rating_name}"]`).forEach(r => r.disabled = true);

        // Crear inputs hidden para cada activo en la lista
        activosParaGuardar.forEach((activo, index) => {
            for (const propiedad in activo) {
                const inputHidden = document.createElement('input');
                inputHidden.type = 'hidden';
                let fieldName = propiedad;
                if (propiedad === 'satisfaccion_rating_name') { fieldName = 'satisfaccion_rating'; }
                inputHidden.name = `activos[${index}][${fieldName}]`;
                inputHidden.value = activo[propiedad] === null ? '' : activo[propiedad]; // Enviar string vacío si es null
                formPrincipal.appendChild(inputHidden);
            }
        });
    });

    function buscar_datos_usuario_ajax(cedula, callback) {
        if (!cedula) { callback({ encontrado: false, mensaje: 'Cédula no proporcionada.' }); return; }
        fetch(`buscar_datos_usuario.php?cedula=${encodeURIComponent(cedula)}`)
            .then(response => { if (!response.ok) { throw new Error('Error en la red: ' + response.statusText); } return response.json(); })
            .then(data => { callback(data); })
            .catch(error => { console.error('Error AJAX:', error); callback({ encontrado: false, mensaje: 'Error al contactar el servidor.' }); });
    }
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>