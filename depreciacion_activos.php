<?php
require_once __DIR__ . '/backend/auth_check.php';
restringir_acceso_pagina(['admin', 'auditor', 'registrador', 'tecnico']);

require_once __DIR__ . '/backend/db.php';
if (isset($conn) && !isset($conexion)) { $conexion = $conn; }

$error_conexion_db = null;
$opciones_tipos = [];
$regionales = ['Popayan', 'Bordo', 'Santander', 'Valle', 'Pasto', 'Tuquerres', 'Huila', 'Nacional'];
$empresas_disponibles = ['Arpesod', 'Finansueños'];

if (!isset($conexion) || (method_exists($conexion, 'connect_error') && $conexion->connect_error) || $conexion === false) {
    $error_conexion_db = "Error crítico de conexión a la base de datos. Funcionalidad limitada.";
    error_log("Fallo CRÍTICO de conexión a BD (depreciacion.php): " . ($conexion->connect_error ?? 'Desconocido'));
} else {
    $conexion->set_charset("utf8mb4");
    // Cargar opciones para los filtros solo si la conexión es exitosa
    $result_tipos = $conexion->query("SELECT id_tipo_activo, nombre_tipo_activo FROM tipos_activo ORDER BY nombre_tipo_activo");
    if ($result_tipos) {
        $opciones_tipos = $result_tipos->fetch_all(MYSQLI_ASSOC);
    }
}

$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Usuario';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'Desconocido';

// Asegúrate que estos valores estén actualizados según la normativa vigente
define('VALOR_UVT_2025', 49799); 
define('UMBRAL_UVT_DEPRECIACION', 50);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Análisis de Depreciación de Activos</title>
    <link rel="icon" type="image/x-icon" href="imagenes/icono.ico">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding-top: 80px; background-color: #f4f6f9; }
        .top-bar-custom { position: fixed; top: 0; left: 0; right: 0; z-index: 1030; display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 1.5rem; background-color: #ffffff; border-bottom: 1px solid #dee2e6; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .logo-container-top img { height: 75px; }
        .page-header-title { color: #0d6efd; font-weight: 600; }
        .accordion-button:not(.collapsed) { color: #ffffff; background-color: #0d6efd; }
        .accordion-button:focus { box-shadow: 0 0 0 .25rem rgba(13, 110, 253, .25); }
        .loader { border: 5px solid #f3f3f3; border-radius: 50%; border-top: 5px solid #0d6efd; width: 40px; height: 40px; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        #columna-resultados .list-group-item { cursor: pointer; border-radius: .5rem; margin-bottom: 5px; border: 1px solid #ddd;}
        #columna-resultados .list-group-item:hover { background-color: #e9ecef; }
        #columna-resultados .list-group-item.active { background-color: #0d6efd; border-color: #0d6efd; color: white; }
        #columna-detalles { background-color: #ffffff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 5px rgba(0,0,0,0.08); min-height: 500px; }
        .card-depreciacion { border-left: 4px solid #0d6efd; }
    </style>
</head>
<body>
<div class="top-bar-custom">
    <div class="logo-container-top">
        <a href="menu.php" title="Ir a Inicio"><img src="imagenes/logo.png" alt="Logo"></a>
    </div>
    <div class="d-flex align-items-center">
        <span class="text-dark me-3"><i class="bi bi-person-circle"></i> <?= htmlspecialchars($nombre_usuario_actual_sesion) ?> (<?= htmlspecialchars(ucfirst($rol_usuario_actual_sesion)) ?>)</span>
        <form action="logout.php" method="post" class="d-flex">
            <button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</button>
        </form>
    </div>
</div>

<div class="container-fluid mt-4 px-lg-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="page-header-title mb-0"><i class="bi bi-calculator-fill"></i> Análisis de Depreciación</h3>
        <a href="menu.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left-circle"></i> Volver al Menú</a>
    </div>

    <?php if ($error_conexion_db): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error_conexion_db) ?></div>
    <?php else: ?>
        <div class="accordion mb-4" id="acordeon-filtros">
            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseFiltros"><i class="bi bi-funnel-fill me-2"></i> Panel de Filtros</button>
                </h2>
                <div id="collapseFiltros" class="accordion-collapse collapse show">
                    <div class="accordion-body bg-light">
                        <form id="form-filtros">
                             <div class="row g-3">
                                <div class="col-lg-12"><input type="text" class="form-control" name="q" placeholder="Buscar por Serie, Cód. Inventario, Cédula o Nombre..."></div>
                                <div class="col-md-3"><select name="tipo_activo" class="form-select"><option value="">-- Tipo Activo --</option><?php foreach($opciones_tipos as $t) echo "<option value='{$t['id_tipo_activo']}'>".htmlspecialchars($t['nombre_tipo_activo'])."</option>"; ?></select></div>
                                <div class="col-md-3"><select name="regional" class="form-select"><option value="">-- Regional --</option><?php foreach($regionales as $r) echo "<option value='".htmlspecialchars($r)."'>".htmlspecialchars($r)."</option>"; ?></select></div>
                                <div class="col-md-3"><select name="empresa" class="form-select"><option value="">-- Empresa --</option><?php foreach($empresas_disponibles as $e) echo "<option value='".htmlspecialchars($e)."'>".htmlspecialchars($e)."</option>"; ?></select></div>
                                <div class="col-md-3"><select name="estado_depreciacion" class="form-select"><option value="">-- Estado Depreciación --</option><option value="en_curso">En Curso</option><option value="depreciado">Totalmente Depreciado</option><option value="proximo">Próximo a Vencer (6m)</option></select></div>
                                <div class="col-md-3"><label class="form-label small mb-0">Compra Desde:</label><input type="date" class="form-control form-control-sm" name="fecha_desde"></div>
                                <div class="col-md-3"><label class="form-label small mb-0">Compra Hasta:</label><input type="date" class="form-control form-control-sm" name="fecha_hasta"></div>
                            </div>
                            <hr class="my-3">
                            <div class="d-flex justify-content-end gap-2">
                                 <button type="button" id="btn-limpiar" class="btn btn-secondary"><i class="bi bi-eraser-fill me-1"></i> Limpiar</button>
                                 <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i> Consultar</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-5">
                <h5 class="text-muted">Resultados de Búsqueda</h5>
                <div id="columna-resultados" class="list-group" style="max-height: 600px; overflow-y: auto;">
                    <div class="d-flex justify-content-center mt-5 d-none" id="loader"><div class="loader"></div></div>
                    <div class="text-center p-5 text-muted" id="placeholder-resultados">Use los filtros para buscar activos.</div>
                </div>
            </div>
            <div class="col-lg-7">
                 <h5 class="text-muted">Detalles del Activo Seleccionado</h5>
                <div id="columna-detalles">
                    <div class="text-center p-5 text-muted">Seleccione un activo de la lista de resultados para ver sus detalles de depreciación aquí.</div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const formFiltros = document.getElementById('form-filtros');
    if (!formFiltros) return; // No ejecutar si el formulario no existe (por error de BD)

    const btnLimpiar = document.getElementById('btn-limpiar');
    const resultadosContainer = document.getElementById('columna-resultados');
    const detallesContainer = document.getElementById('columna-detalles');
    const loader = document.getElementById('loader');
    const placeholderResultados = document.getElementById('placeholder-resultados');
    
    let activosCache = []; // Guardar los resultados en caché para no pedirlos de nuevo

    const VALOR_UVT = <?= VALOR_UVT_2025 ?>;
    const UMBRAL_UVT = <?= UMBRAL_UVT_DEPRECIACION ?>;

    formFiltros.addEventListener('submit', function(e) {
        e.preventDefault();
        realizarBusqueda();
    });

    // --- INICIO DE LA MODIFICACIÓN ---
    btnLimpiar.addEventListener('click', function() {
        // 1. Limpiar los campos del formulario
        formFiltros.reset();
        // 2. Limpiar la caché de resultados
        activosCache = [];
        // 3. Restaurar el contenedor de resultados a su estado inicial
        resultadosContainer.innerHTML = ''; // Limpiar la lista/loader
        placeholderResultados.classList.remove('d-none'); // Mostrar el placeholder original
        // 4. Restaurar el contenedor de detalles
        detallesContainer.innerHTML = '<div class="text-center p-5 text-muted">Seleccione un activo de la lista de resultados para ver sus detalles de depreciación aquí.</div>';
    });
    // --- FIN DE LA MODIFICACIÓN ---

    // Delegación de eventos para los clics en la lista de resultados
    resultadosContainer.addEventListener('click', function(e) {
        const item = e.target.closest('.list-group-item');
        if (item) {
            e.preventDefault();
            const activeItem = resultadosContainer.querySelector('.list-group-item.active');
            if(activeItem) activeItem.classList.remove('active');
            item.classList.add('active');

            const index = parseInt(item.dataset.index, 10);
            const activoSeleccionado = activosCache[index];
            if (activoSeleccionado) {
                mostrarDetalles(activoSeleccionado);
            }
        }
    });

    async function realizarBusqueda() {
        loader.classList.remove('d-none');
        placeholderResultados.classList.add('d-none');
        resultadosContainer.innerHTML = '';
        resultadosContainer.appendChild(loader);
        detallesContainer.innerHTML = '<div class="text-center p-5 text-muted">Seleccione un activo de la lista de resultados.</div>';
        
        const formData = new FormData(formFiltros);
        const params = new URLSearchParams(formData).toString();

        try {
            const response = await fetch(`api/api_depreciacion.php?${params}`);
            if (!response.ok) throw new Error(`Error del servidor: ${response.statusText}`);
            
            const data = await response.json();
            activosCache = data;
            renderizarLista(data);
        } catch (error) {
            console.error('Error en la búsqueda AJAX:', error);
            resultadosContainer.innerHTML = `<div class="alert alert-danger">Error al cargar los datos.</div>`;
        } finally {
            if(loader.parentNode) loader.remove();
        }
    }

    function renderizarLista(activos) {
        resultadosContainer.innerHTML = '';
        if (!activos || activos.length === 0) {
            // Ahora este mensaje solo aparecerá después de una búsqueda real sin resultados
            if (new URLSearchParams(new FormData(formFiltros)).toString().length > 0) {
                 resultadosContainer.innerHTML = '<div class="text-center p-5 text-muted">No se encontraron activos con los criterios seleccionados.</div>';
            } else {
                 resultadosContainer.appendChild(placeholderResultados);
                 placeholderResultados.classList.remove('d-none');
            }
            return;
        }

        activos.forEach((activo, index) => {
            const item = document.createElement('a');
            item.href = '#';
            item.className = 'list-group-item list-group-item-action';
            item.dataset.index = index;
            item.innerHTML = `
                <div class="d-flex w-100 justify-content-between">
                    <h6 class="mb-1">${activo.nombre_tipo_activo || 'N/A'} - ${activo.marca || 'N/A'}</h6>
                    <small>ID: ${activo.id}</small>
                </div>
                <p class="mb-1 small">S/N: ${activo.serie || 'N/A'}</p>
                <small class="text-muted">Resp: ${activo.nombre_responsable || 'Sin asignar'}</small>
            `;
            resultadosContainer.appendChild(item);
        });
    }

    function mostrarDetalles(activo) {
        const valorCompra = parseFloat(activo.valor_aproximado || 0);
        const valorResidual = parseFloat(activo.valor_residual || 0);
        const vidaUtilAnios = parseInt(activo.vida_util_anios || 0, 10);
        const fechaCompra = activo.fecha_compra;
        let depreciacion = {};
        let mesesTranscurridos = 0; 

        const valorEnUVT = VALOR_UVT > 0 ? (valorCompra / VALOR_UVT) : 0;
        
        if (valorEnUVT < UMBRAL_UVT) {
            depreciacion.aplica = false;
            depreciacion.mensaje_no_aplica = `Activo no aplica para Depreciar (Valor: ${valorEnUVT.toFixed(2)} UVT < ${UMBRAL_UVT} UVT).`;
            depreciacion.valorEnLibros = valorCompra;
        } else if (fechaCompra && vidaUtilAnios > 0 && valorCompra > 0) {
            depreciacion.aplica = true;
            const fechaInicio = new Date(fechaCompra + 'T00:00:00');
            const fechaActual = new Date();
            
            if (fechaActual >= fechaInicio) {
                mesesTranscurridos = (fechaActual.getFullYear() - fechaInicio.getFullYear()) * 12 + (fechaActual.getMonth() - fechaInicio.getMonth());
            }

            const vidaUtilMeses = vidaUtilAnios * 12;
            const valorDepreciable = Math.max(0, valorCompra - valorResidual);
            
            depreciacion.depMensual = vidaUtilMeses > 0 ? valorDepreciable / vidaUtilMeses : 0;
            depreciacion.depAnual = depreciacion.depMensual * 12;
            
            const mesesADepreciar = Math.min(mesesTranscurridos, vidaUtilMeses);
            depreciacion.depAcumulada = depreciacion.depMensual * mesesADepreciar;
            if (depreciacion.depAcumulada > valorDepreciable) depreciacion.depAcumulada = valorDepreciable;

            depreciacion.valorEnLibros = valorCompra - depreciacion.depAcumulada;
            if (depreciacion.valorEnLibros < valorResidual) depreciacion.valorEnLibros = valorResidual;

            depreciacion.mesesRestantes = Math.max(0, vidaUtilMeses - mesesTranscurridos);
            
            if (fechaActual < fechaInicio) {
                depreciacion.estado = 'No iniciada';
            } else if (depreciacion.mesesRestantes <= 0) {
                depreciacion.estado = 'Totalmente Depreciado';
            } else {
                depreciacion.estado = 'En Curso';
            }
        }

        const f = (num) => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', minimumFractionDigits: 0 }).format(num || 0);
        const escape = (str) => str ? String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;') : 'N/A';

        let htmlDetalles = `
            <div class="card h-100">
                <div class="card-header fw-bold"><i class="bi bi-info-circle-fill"></i> Información del Activo</div>
                <div class="card-body small">
                    <p><strong>ID:</strong> ${escape(activo.id)}</p>
                    <p><strong>Tipo:</strong> ${escape(activo.nombre_tipo_activo)}</p>
                    <p><strong>Marca/Serie:</strong> ${escape(activo.marca)} / ${escape(activo.serie)}</p>
                    <p><strong>Responsable:</strong> ${escape(activo.nombre_responsable)} (C.C: ${escape(activo.cedula_responsable)})</p>
                    <hr>
                    <p><strong>Fecha Compra:</strong> ${fechaCompra ? new Date(fechaCompra + 'T00:00:00').toLocaleDateString('es-CO') : 'N/A'}</p>
                    <p><strong>Valor Compra:</strong> ${f(valorCompra)} (${valorEnUVT.toFixed(2)} UVT)</p>
                    <p><strong>Valor Residual:</strong> ${f(valorResidual)}</p>
                    <p><strong>Vida Útil (Tipo):</strong> ${vidaUtilAnios} años</p>
                </div>
            </div>`;
        
        let htmlCalculo = `
            <div class="card card-depreciacion h-100 mt-3 mt-lg-0">
                <div class="card-header fw-bold"><i class="bi bi-graph-down"></i> Cálculo de Depreciación (a hoy: ${new Date().toLocaleDateString('es-CO')})</div>
                <div class="card-body">`;
        
        if(depreciacion.aplica) {
            htmlCalculo += `
                    <p><strong>Meses Transcurridos / Restantes:</strong> ${mesesTranscurridos} / ${depreciacion.mesesRestantes}</p>
                    <table class="table table-sm table-bordered">
                        <tbody>
                            <tr><th>Depreciación Mensual:</th><td>${f(depreciacion.depMensual)}</td></tr>
                            <tr class="text-danger"><th>Depreciación Acumulada:</th><td class="fw-bold">${f(depreciacion.depAcumulada)}</td></tr>
                            <tr class="text-success"><th>Valor en Libros Actual:</th><td class="fw-bold">${f(depreciacion.valorEnLibros)}</td></tr>
                        </tbody>
                    </table>
                    <p class="mt-3 text-center"><strong>Estado:</strong> <span class="badge fs-6 bg-primary">${escape(depreciacion.estado)}</span></p>
            `;
        } else if (depreciacion.mensaje_no_aplica) {
            htmlCalculo += `
                <div class="alert alert-warning text-center"><i class="bi bi-exclamation-triangle-fill me-2"></i> ${escape(depreciacion.mensaje_no_aplica)}</div>
                <p><strong>Valor en Libros Actual:</strong> ${f(depreciacion.valorEnLibros)}</p>`;
        } else {
             htmlCalculo += `<div class="alert alert-secondary text-center">Datos insuficientes para el cálculo.</div>`;
        }
        htmlCalculo += `</div></div>`;
        detallesContainer.innerHTML = htmlDetalles + htmlCalculo;
    }
});
</script>

</body>
</html>