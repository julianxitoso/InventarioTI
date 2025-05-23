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

$nombre_usuario_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Usuario';
$rol_usuario_sesion = $_SESSION['rol_usuario'] ?? '';

$regionales = ['Popayan', 'Bordo', 'Santander', 'Valle', 'Pasto', 'Tuquerres', 'Huila', 'Nacional'];
$empresas_disponibles = ['Arpesod', 'Finansueños'];
$opciones_tipo_activo = ['Computador', 'Monitor', 'Impresora', 'Escáner', 'DVR', 'Contadora Billetes', 'Contadora Monedas', 'Celular', 'Impresora Térmica', 'Combo Teclado y Mouse', 'Diadema', 'Adaptador Multipuertos / Red', 'Router', 'Otro'];
$opciones_tipo_equipo = ['Portátil', 'Mesa', 'Todo en 1', 'N/A'];
$opciones_red = ['Cableada', 'Inalámbrica', 'Ambas', 'N/A'];
$opciones_estado_general = ['Bueno', 'Regular', 'Malo', 'Nuevo'];
$opciones_so = ['Windows 10', 'Windows 11', 'Linux', 'MacOS', 'Otro SO', 'N/A SO'];
$opciones_offimatica = ['Office 365', 'Office Home And Business', 'Office 2021', 'Office 2019', 'Office 2016', 'LibreOffice', 'Google Workspace', 'Otro Office', 'N/A Office'];
$opciones_antivirus = ['Microsoft Defender', 'Bitdefender', 'ESET NOD32 Antivirus', 'McAfee Total Protection', 'Kaspersky', 'N/A Antivirus', 'Otro Antivirus'];

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
        .card.form-card { box-shadow: 0 4px 12px rgba(0,0,0,0.08); border: none; }
        .form-label { font-weight: 500; color: #495057; }
        .form-section { border: 1px solid #e0e0e0; padding: 20px; border-radius: 8px; margin-bottom: 20px; background-color: #fff; }
        .table-activos-agregados th { font-size: 0.9em; }
        .table-activos-agregados td { font-size: 0.85em; vertical-align: middle; }

        .star-rating { display: inline-block; direction: rtl; font-size: 0; }
        .star-rating input[type="radio"] { display: none; }
        .star-rating label.star-label { color: #ccc; font-size: 1.8rem; padding: 0 0.05em; cursor: pointer; display: inline-block; transition: color 0.2s ease-in-out; }
        .star-rating input[type="radio"]:checked ~ label.star-label,
        .star-rating label.star-label:hover,
        .star-rating label.star-label:hover ~ label.star-label { color: #f5b301; }
        .star-rating input[type="radio"]:checked + label.star-label:hover,
        .star-rating input[type="radio"]:checked ~ label.star-label:hover,
        .star-rating input[type="radio"]:checked ~ label.star-label:hover ~ label.star-label,
        .star-rating label.star-label:hover ~ input[type="radio"]:checked ~ label.star-label { color: #f5b301; }
        .btn-remove-asset { font-size: 0.8em; padding: 0.2rem 0.5rem; }
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
    <h3 class="page-title text-center mb-4">Registrar Activos (por Responsable)</h3>

    <?php if ($mensaje_global): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert"><?= htmlspecialchars($mensaje_global) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>
    <?php endif; ?>
    <?php if ($error_global): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert"><?= htmlspecialchars($error_global) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>
    <?php endif; ?>

    <form action="guardar_activo.php" method="post" id="formRegistrarLoteActivos">
        <div class="form-section" id="seccionResponsable">
            <h5 class="mb-3 text-primary">1. Información del Responsable</h5>
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label for="cedula" class="form-label">Cédula <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="cedula" name="responsable_cedula" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label for="nombre" class="form-label">Nombre Completo <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="nombre" name="responsable_nombre" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label for="cargo" class="form-label">Cargo <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="cargo" name="responsable_cargo" required>
                </div>
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
                    <label for="empresa_activo" class="form-label">Empresa (Asignada al Responsable) <span class="text-danger">*</span></label>
                    <select class="form-select" id="empresa_responsable" name="responsable_empresa" required>
                        <option value="">Seleccione...</option>
                        <?php foreach ($empresas_disponibles as $e): ?><option value="<?= htmlspecialchars($e) ?>"><?= htmlspecialchars($e) ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <button type="button" class="btn btn-info btn-sm" id="btnConfirmarResponsable">Confirmar Responsable y Agregar Activos</button>
        </div>

        <div class="form-section" id="seccionAgregarActivo" style="display: none;">
            <h5 class="mb-3 text-primary">2. Agregar Activo para <strong id="nombreResponsableDisplay"></strong></h5>
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label for="tipo_activo" class="form-label">Tipo de Activo <span class="text-danger">*</span></label>
                    <select class="form-select" id="tipo_activo" name="activo_tipo_activo">
                        <option value="">Seleccione...</option>
                        <?php foreach ($opciones_tipo_activo as $opcion): ?><option value="<?= htmlspecialchars($opcion) ?>"><?= htmlspecialchars($opcion) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label for="marca" class="form-label">Marca <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="marca" name="activo_marca">
                </div>
                <div class="col-md-4 mb-3">
                    <label for="serie" class="form-label">Serie / Serial <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="serie" name="activo_serie">
                </div>
            </div>
            <div class="row">
                 <div class="col-md-4 mb-3">
                    <label for="estado" class="form-label">Estado del Activo <span class="text-danger">*</span></label>
                    <select class="form-select" id="estado" name="activo_estado">
                        <option value="Nuevo">Nuevo</option>
                        <?php foreach ($opciones_estado_general as $opcion): if($opcion !== 'Nuevo' && $opcion !== 'Dado de Baja') { ?><option value="<?= htmlspecialchars($opcion) ?>"><?= htmlspecialchars($opcion) ?></option><?php } endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label for="valor_aproximado" class="form-label">Valor Aproximado <span class="text-danger">*</span></label>
                    <input type="number" class="form-control" id="valor_aproximado" name="activo_valor_aproximado" step="0.01" min="0">
                </div>
                 <div class="col-md-4 mb-3">
                    <label for="codigo_inv" class="form-label">Código Inventario (Opcional)</label>
                    <input type="text" class="form-control" id="codigo_inv" name="activo_codigo_inv">
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
            <div class="mb-3">
                <label for="detalles" class="form-label">Detalles Adicionales (Observaciones)</label>
                <textarea class="form-control" id="detalles" name="activo_detalles" rows="2"></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label d-block">Califica tu nivel de satisfacción con este activo:</label>
                <div class="star-rating" id="activo_satisfaccion_rating_container">
                    <input type="radio" id="activo_star5" name="activo_satisfaccion_rating" value="5" /><label class="star-label" for="activo_star5" title="5 estrellas">☆</label>
                    <input type="radio" id="activo_star4" name="activo_satisfaccion_rating" value="4" /><label class="star-label" for="activo_star4" title="4 estrellas">☆</label>
                    <input type="radio" id="activo_star3" name="activo_satisfaccion_rating" value="3" /><label class="star-label" for="activo_star3" title="3 estrellas">☆</label>
                    <input type="radio" id="activo_star2" name="activo_satisfaccion_rating" value="2" /><label class="star-label" for="activo_star2" title="2 estrellas">☆</label>
                    <input type="radio" id="activo_star1" name="activo_satisfaccion_rating" value="1" /><label class="star-label" for="activo_star1" title="1 estrella">☆</label>
                </div>
            </div>
            <button type="button" class="btn btn-success" id="btnAgregarActivoTabla"><i class="bi bi-plus-circle"></i> Agregar Activo a la Lista</button>
        </div>

        <div class="form-section mt-4" id="seccionTablaActivos" style="display: none;">
            <h5 class="mb-3 text-primary">3. Activos para Registrar a <strong id="nombreResponsableTabla"></strong></h5>
            <div class="table-responsive">
                <table class="table table-sm table-bordered table-hover table-activos-agregados">
                    <thead>
                        <tr>
                            <th>Tipo</th><th>Marca</th><th>Serie</th><th>Estado</th><th>Valor</th><th>Satisfacción</th><th>Acción</th>
                        </tr>
                    </thead>
                    <tbody id="tablaActivosBody">
                        </tbody>
                </table>
            </div>
             <p id="noActivosMensaje" class="text-muted">Aún no se han agregado activos a la lista.</p>
        </div>
        
        <div class="mt-4 d-grid gap-2">
            <button type="submit" class="btn btn-primary btn-lg" id="btnGuardarTodo" disabled><i class="bi bi-save"></i> Guardar Todos los Activos y Finalizar</button>
        </div>
    </form>
</div>

<script>
    // Array para almacenar los activos agregados temporalmente
    let activosParaGuardar = [];
    let responsableConfirmado = false;

    const formPrincipal = document.getElementById('formRegistrarLoteActivos');
    const seccionResponsable = document.getElementById('seccionResponsable');
    const seccionAgregarActivo = document.getElementById('seccionAgregarActivo');
    const seccionTablaActivos = document.getElementById('seccionTablaActivos');
    const btnConfirmarResponsable = document.getElementById('btnConfirmarResponsable');
    const btnAgregarActivoTabla = document.getElementById('btnAgregarActivoTabla');
    const btnGuardarTodo = document.getElementById('btnGuardarTodo');
    const tablaActivosBody = document.getElementById('tablaActivosBody');
    const noActivosMensaje = document.getElementById('noActivosMensaje');
    
    const camposResponsableIds = ['cedula', 'nombre', 'cargo', 'regional', 'empresa_responsable'];
    const camposActivoIds = {
        tipo_activo: 'tipo_activo', marca: 'marca', serie: 'serie', estado: 'estado',
        valor_aproximado: 'valor_aproximado', codigo_inv: 'codigo_inv', detalles: 'detalles',
        procesador: 'activo_procesador', ram: 'activo_ram', disco_duro: 'activo_disco_duro',
        tipo_equipo: 'activo_tipo_equipo', red: 'activo_red', sistema_operativo: 'activo_so',
        offimatica: 'activo_offimatica', antivirus: 'activo_antivirus',
        satisfaccion_rating_name: 'activo_satisfaccion_rating' // name de los radios
    };

    // Autocompletar datos del responsable y bloquear campos
    btnConfirmarResponsable.addEventListener('click', function() {
        let valido = true;
        camposResponsableIds.forEach(id => {
            const input = document.getElementById(id);
            if (!input.value.trim()) {
                valido = false;
                input.classList.add('is-invalid');
            } else {
                input.classList.remove('is-invalid');
            }
        });

        if (valido) {
            camposResponsableIds.forEach(id => {
                document.getElementById(id).setAttribute('readonly', true);
            });
            this.disabled = true;
            this.innerHTML = '<i class="bi bi-check-circle-fill"></i> Responsable Confirmado';
            seccionAgregarActivo.style.display = 'block';
            seccionTablaActivos.style.display = 'block';
            btnGuardarTodo.disabled = false; // Habilitar solo si hay responsable, pero idealmente si hay activos
            document.getElementById('nombreResponsableDisplay').textContent = document.getElementById('nombre').value;
            document.getElementById('nombreResponsableTabla').textContent = document.getElementById('nombre').value;
            responsableConfirmado = true;
             //Llamada opcional para buscar datos si existe la función y el endpoint
            const cedulaVal = document.getElementById('cedula').value.trim();
            if(cedulaVal && typeof buscar_datos_usuario_ajax === 'function'){
                buscar_datos_usuario_ajax(cedulaVal, function(data){
                    if(data.encontrado){
                        if(document.getElementById('nombre').value === '') document.getElementById('nombre').value = data.nombre;
                        if(document.getElementById('cargo').value === '') document.getElementById('cargo').value = data.cargo;
                        // Actualizar el display si se autocompletó
                        document.getElementById('nombreResponsableDisplay').textContent = document.getElementById('nombre').value;
                        document.getElementById('nombreResponsableTabla').textContent = document.getElementById('nombre').value;
                    }
                });
            }
        } else {
            alert('Por favor, complete todos los campos de información del responsable.');
        }
    });
    
    // Mostrar/ocultar campos de computador en formulario de activo
    document.getElementById(camposActivoIds.tipo_activo).addEventListener('change', function() {
        document.getElementById('campos_computador_form_activo').style.display = (this.value === 'Computador') ? 'block' : 'none';
    });

    btnAgregarActivoTabla.addEventListener('click', function() {
        if (!responsableConfirmado) {
            alert("Primero debe confirmar los datos del responsable.");
            return;
        }
        const activo = {};
        let activoValido = true;
        let camposActivoForm = {}; // Para obtener elementos del DOM
        
        // Recolectar datos del formulario del activo
        for (const key in camposActivoIds) {
            if (key === 'satisfaccion_rating_name') {
                const ratingChecked = document.querySelector(`input[name="${camposActivoIds[key]}"]:checked`);
                activo[key] = ratingChecked ? ratingChecked.value : null;
            } else {
                const inputElement = document.getElementById(camposActivoIds[key]);
                 if (inputElement) {
                    activo[key] = inputElement.value.trim();
                    camposActivoForm[key] = inputElement; // Guardar referencia al elemento
                 } else {
                    console.warn("Elemento no encontrado para activo: ", camposActivoIds[key]);
                    activo[key] = ''; // o null
                 }
            }
        }

        // Validación básica de campos de activo
        if (!activo.tipo_activo || !activo.marca || !activo.serie || !activo.estado || !activo.valor_aproximado) {
            alert('Complete los campos obligatorios del activo: Tipo, Marca, Serie, Estado, Valor.');
            activoValido = false;
        }
        // Validar que valor aproximado sea número
        if(isNaN(parseFloat(activo.valor_aproximado)) && activo.valor_aproximado !== '') {
            alert('El valor aproximado debe ser un número.');
            activoValido = false;
        }


        if (activoValido) {
            activosParaGuardar.push(activo);
            actualizarTablaActivos();
            limpiarFormularioActivo(camposActivoForm);
            document.getElementById(camposActivoIds.tipo_activo).dispatchEvent(new Event('change')); // Resetear campos de PC
        }
    });

    function actualizarTablaActivos() {
        tablaActivosBody.innerHTML = ''; // Limpiar tabla
        if (activosParaGuardar.length === 0) {
            noActivosMensaje.style.display = 'block';
            btnGuardarTodo.disabled = true; // Deshabilitar si no hay activos
            return;
        }
        noActivosMensaje.style.display = 'none';
        btnGuardarTodo.disabled = false; // Habilitar si hay activos

        activosParaGuardar.forEach((activo, index) => {
            const fila = tablaActivosBody.insertRow();
            fila.insertCell().textContent = activo.tipo_activo || 'N/A';
            fila.insertCell().textContent = activo.marca || 'N/A';
            fila.insertCell().textContent = activo.serie || 'N/A';
            fila.insertCell().textContent = activo.estado || 'N/A';
            fila.insertCell().textContent = activo.valor_aproximado || 'N/A';
            
            let estrellasDisplay = '';
            if (activo.satisfaccion_rating_name) { // Usa la clave correcta con la que se guardó
                for (let i = 0; i < 5; i++) {
                    estrellasDisplay += (i < parseInt(activo.satisfaccion_rating_name)) ? '★' : '☆';
                }
            } else {
                estrellasDisplay = 'N/A';
            }
            fila.insertCell().innerHTML = `<span style="color: #f5b301; font-size:1.2em;">${estrellasDisplay}</span>`;
            
            const celdaAccion = fila.insertCell();
            const btnEliminar = document.createElement('button');
            btnEliminar.type = 'button';
            btnEliminar.classList.add('btn', 'btn-danger', 'btn-sm', 'btn-remove-asset');
            btnEliminar.innerHTML = '<i class="bi bi-trash"></i>';
            btnEliminar.title = 'Eliminar de la lista';
            btnEliminar.onclick = function() {
                eliminarActivoDeLista(index);
            };
            celdaAccion.appendChild(btnEliminar);
        });
    }

    function limpiarFormularioActivo(camposActivoFormElements) {
         for (const key in camposActivoFormElements) {
            if(camposActivoFormElements[key]) camposActivoFormElements[key].value = '';
         }
         // Resetear radios de estrellas
        const radiosEstrellas = document.querySelectorAll(`input[name="${camposActivoIds.satisfaccion_rating_name}"]`);
        radiosEstrellas.forEach(radio => radio.checked = false);
        // El select de estado volver a 'Nuevo' por defecto
        document.getElementById(camposActivoIds.estado).value = 'Nuevo';
    }

    function eliminarActivoDeLista(index) {
        activosParaGuardar.splice(index, 1);
        actualizarTablaActivos();
    }

    formPrincipal.addEventListener('submit', function(event) {
        if (activosParaGuardar.length === 0 || !responsableConfirmado) {
            alert('Debe confirmar un responsable y agregar al menos un activo a la lista antes de guardar.');
            event.preventDefault(); // Detener el envío del formulario
            return false;
        }
        // Limpiar inputs del formulario de activo individual para no enviarlos como campos sueltos
        for (const key in camposActivoIds) {
             const inputElement = document.getElementById(camposActivoIds[key]);
             if (inputElement && key !== 'satisfaccion_rating_name') { // No limpiar el name de los radios
                inputElement.disabled = true; // Deshabilitar para que no se envíen
             }
        }
        document.querySelectorAll(`input[name="${camposActivoIds.satisfaccion_rating_name}"]`).forEach(r => r.disabled = true);


        // Crear inputs hidden para cada activo en la lista
        activosParaGuardar.forEach((activo, index) => {
            for (const propiedad in activo) {
                const inputHidden = document.createElement('input');
                inputHidden.type = 'hidden';
                // Los nombres serán como activos[0][tipo_activo], activos[0][marca], etc.
                // Y para la satisfacción: activos[0][satisfaccion_rating]
                let fieldName = propiedad;
                if (propiedad === 'satisfaccion_rating_name') { // Asegurar el nombre correcto para el backend
                    fieldName = 'satisfaccion_rating';
                }
                inputHidden.name = `activos[${index}][${fieldName}]`;
                inputHidden.value = activo[propiedad];
                formPrincipal.appendChild(inputHidden);
            }
        });
        // Los campos del responsable (cedula, nombre, etc.) ya tienen sus names y se enviarán.
    });


</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>