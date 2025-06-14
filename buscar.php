<?php
// Se usa __DIR__ para crear una ruta absoluta y robusta.
require_once __DIR__ . '/backend/auth_check.php';
restringir_acceso_pagina(['admin', 'tecnico', 'auditor', 'registrador']);

// Se usa __DIR__ para la conexión a la BD.
require_once __DIR__ . '/backend/db.php';

// --- INICIO DEL BLOQUE DE VERIFICACIÓN DE CONEXIÓN MEJORADO ---
$db_connection_error = null;
if (isset($conn) && !isset($conexion)) { $conexion = $conn; }

// Verificamos si la variable $conexion existe y si no hay errores de conexión.
if (!isset($conexion) || (method_exists($conexion, 'connect_error') && $conexion->connect_error) || $conexion === false) {
    $db_connection_error = "Error crítico de conexión a la base de datos. No se pueden cargar los filtros. Contacte al administrador.";
    error_log("Fallo de conexión en buscar.php: " . ($conexion->connect_error ?? 'La variable de conexión no está definida o es falsa.'));
} else {
    // Solo si la conexión es exitosa, preparamos las opciones para los filtros.
    $conexion->set_charset("utf8mb4");
    $opciones_tipos = $conexion->query("SELECT id_tipo_activo, nombre_tipo_activo FROM tipos_activo ORDER BY nombre_tipo_activo")->fetch_all(MYSQLI_ASSOC);
    $opciones_estados = $conexion->query("SELECT DISTINCT estado FROM activos_tecnologicos WHERE estado IS NOT NULL AND estado != '' ORDER BY estado")->fetch_all(MYSQLI_ASSOC);
}
// --- FIN DEL BLOQUE DE VERIFICACIÓN ---

$regionales = ['Popayan', 'Bordo', 'Santander', 'Valle', 'Pasto', 'Tuquerres', 'Huila', 'Nacional']; 
$empresas_disponibles = ['Arpesod', 'Finansueños'];

$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Usuario';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'Desconocido';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Búsqueda Avanzada de Activos</title>
    <link rel="icon" type="image/x-icon" href="imagenes/icono.ico">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f4f6f9; font-family: 'Segoe UI', sans-serif; padding-top: 80px; }
        .top-bar-custom { position: fixed; top: 0; left: 0; right: 0; z-index: 1030; display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 1.5rem; background-color: #f8f9fa; border-bottom: 1px solid #dee2e6; }
        .logo-container-top img { height: 75px; }
        .page-header-title { color: #191970; }
        .accordion-button:not(.collapsed) { color: #ffffff; background-color: #191970; }
        .accordion-button:focus { box-shadow: 0 0 0 .25rem rgba(25, 25, 112, .2); }
        .loader { border: 5px solid #f3f3f3; border-radius: 50%; border-top: 5px solid #191970; width: 50px; height: 50px; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .user-asset-group { background-color: #fff; padding: 20px; margin-bottom: 25px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.07); }
        .user-info-header { border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 15px; }
        .user-info-header h4 { color: #191970; }
        .table-minimalist { font-size: 0.85rem; }
    </style>
</head>
<body>

<div class="top-bar-custom">
    <div class="logo-container-top"><a href="menu.php" title="Ir a Inicio"><img src="imagenes/logo.png" alt="Logo"></a></div><div><span class="text-dark me-3"><i class="bi bi-person-circle"></i> <?= htmlspecialchars($nombre_usuario_actual_sesion) ?> (<?= htmlspecialchars(ucfirst($rol_usuario_actual_sesion)) ?>)</span><form action="logout.php" method="post" class="d-flex"><button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</button></form></div>
</div>

<div class="container mt-4">
    <h3 class="mb-4 text-center page-header-title">Búsqueda Avanzada de Activos</h3>
    
    <?php if ($db_connection_error): ?>
        <div class="alert alert-danger">
            <strong>Error Crítico:</strong> <?= htmlspecialchars($db_connection_error) ?>
        </div>
    <?php else: ?>
        <div class="accordion mb-4" id="acordeon-filtros">
            <div class="accordion-item">
                <h2 class="accordion-header" id="headingOne">
                    <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne" aria-expanded="true" aria-controls="collapseOne">
                        <i class="bi bi-funnel-fill me-2"></i> Panel de Filtros
                    </button>
                </h2>
                <div id="collapseOne" class="accordion-collapse collapse show" aria-labelledby="headingOne">
                    <div class="accordion-body">
                        <form id="form-filtros">
                            <div class="row g-3">
                                <div class="col-md-12"><label for="filtro-q" class="form-label">Búsqueda General</label><input type="text" class="form-control" id="filtro-q" name="q" placeholder="Buscar por Cédula, Nombre, Serie, Cód. Inventario..."></div>
                                <div class="col-md-3"><label for="filtro-tipo" class="form-label">Tipo de Activo</label><select id="filtro-tipo" name="tipo_activo" class="form-select"><option value="">-- Todos --</option><?php foreach ($opciones_tipos as $tipo): ?><option value="<?= $tipo['id_tipo_activo'] ?>"><?= htmlspecialchars($tipo['nombre_tipo_activo']) ?></option><?php endforeach; ?></select></div>
                                <div class="col-md-3"><label for="filtro-estado" class="form-label">Estado del Activo</label><select id="filtro-estado" name="estado" class="form-select"><option value="">-- Todos --</option><?php foreach ($opciones_estados as $estado): ?><option value="<?= htmlspecialchars($estado['estado']) ?>"><?= htmlspecialchars($estado['estado']) ?></option><?php endforeach; ?></select></div>
                                <div class="col-md-3"><label for="filtro-regional" class="form-label">Regional del Responsable</label><select id="filtro-regional" name="regional" class="form-select"><option value="">-- Todas --</option><?php foreach ($regionales as $r): ?><option value="<?= htmlspecialchars($r) ?>"><?= htmlspecialchars($r) ?></option><?php endforeach; ?></select></div>
                                <div class="col-md-3"><label for="filtro-empresa" class="form-label">Empresa del Responsable</label><select id="filtro-empresa" name="empresa" class="form-select"><option value="">-- Todas --</option><?php foreach ($empresas_disponibles as $e): ?><option value="<?= htmlspecialchars($e) ?>"><?= htmlspecialchars($e) ?></option><?php endforeach; ?></select></div>
                                <div class="col-md-3"><label for="filtro-fecha-desde" class="form-label">Fecha Compra Desde</label><input type="date" class="form-control" id="filtro-fecha-desde" name="fecha_desde"></div>
                                <div class="col-md-3"><label for="filtro-fecha-hasta" class="form-label">Fecha Compra Hasta</label><input type="date" class="form-control" id="filtro-fecha-hasta" name="fecha_hasta"></div>
                                <div class="col-md-3 align-self-end"><div class="form-check"><input class="form-check-input" type="checkbox" id="filtro-incluir-bajas" name="incluir_bajas" value="1"><label class="form-check-label" for="filtro-incluir-bajas">Incluir Dados de Baja</label></div></div>
                            </div>
                            <hr class="my-3">
                            <div class="d-flex justify-content-end gap-2">
                                 <button type="button" id="btn-limpiar" class="btn btn-secondary"><i class="bi bi-eraser-fill me-1"></i> Limpiar Filtros</button>
                                 <button type="submit" class="btn btn-primary" style="background-color: #191970;"><i class="bi bi-search me-1"></i> Aplicar Filtros</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div id="contenedor-resultados" class="mt-4">
        <div id="loader" class="d-none justify-content-center mt-5"><div class="loader"></div></div>
        </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Solo ejecutar el script si el formulario de filtros existe (es decir, si no hubo error de BD)
    const formFiltros = document.getElementById('form-filtros');
    if (!formFiltros) return;

    const btnLimpiar = document.getElementById('btn-limpiar');
    const contenedorResultados = document.getElementById('contenedor-resultados');
    const loader = document.getElementById('loader');

    // --- FUNCIÓN 1: OBTENER CLASE PARA BADGE DE ESTADO ---
    function getEstadoBadgeClass(estado) {
        if (!estado) return 'badge bg-secondary';
        const estadoLower = estado.toLowerCase().trim();
        switch (estadoLower) {
            case 'asignado': case 'activo': case 'operativo': case 'bueno': return 'badge bg-success';
            case 'en mantenimiento': case 'en reparación': case 'regular': return 'badge bg-warning text-dark';
            case 'dado de baja': case 'inactivo': case 'malo': return 'badge bg-danger';
            case 'disponible': case 'en stock': return 'badge bg-info text-dark';
            default: return 'badge bg-secondary';
        }
    }

    // --- FUNCIÓN 2: RENDERIZAR RESULTADOS EN HTML ---
    function renderizarResultados(activos) {
        if (!activos || activos.length === 0) {
            contenedorResultados.innerHTML = `<div class="alert alert-warning text-center">No se encontraron activos que coincidan con los criterios.</div>`;
            return;
        }

        const activosAgrupados = activos.reduce((acc, activo) => {
            const key = activo.cedula_responsable || 'SIN-ASIGNAR';
            if (!acc[key]) {
                acc[key] = {
                    info: {
                        cedula: activo.cedula_responsable || 'N/A',
                        nombre: activo.nombre_responsable || 'Activos sin responsable asignado',
                        cargo: activo.cargo_responsable,
                        regional_del_responsable: activo.regional_responsable,
                        empresa_responsable: activo.empresa_del_responsable
                    },
                    activos: []
                };
            }
            acc[key].activos.push(activo);
            return acc;
        }, {});

        let html = `
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5>Resultados: <span class="badge bg-secondary">${activos.length} activo(s)</span></h5>
            </div>
        `;
        
        for (const key in activosAgrupados) {
            const grupo = activosAgrupados[key];
            const info = grupo.info;
            const activosDelGrupo = grupo.activos;
            
            html += `
            <div class="user-asset-group">
                <div class="user-info-header">
                    <div>
                        <h4>${info.nombre || ''}</h4>
                        <p><strong>Cédula:</strong> ${info.cedula} | <strong>Cargo:</strong> ${info.cargo || 'N/A'} | <strong>Regional:</strong> ${info.regional_del_responsable || 'N/A'} | <strong>Empresa:</strong> ${info.empresa_responsable || 'N/A'}</p>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-minimalist table-hover">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Tipo</th><th>Marca</th><th>Serie</th><th>Estado</th>
                                <th>Valor</th><th>F. Compra</th><th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
            `;

            activosDelGrupo.forEach((activo, index) => {
                const fechaCompra = activo.fecha_compra ? new Date(activo.fecha_compra + 'T00:00:00').toLocaleDateString('es-CO') : 'N/A';
                const valorFormateado = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', minimumFractionDigits: 0 }).format(activo.valor_aproximado || 0);

                html += `
                    <tr class="${activo.estado === 'Dado de Baja' ? 'table-danger' : ''}">
                        <td>${index + 1}.</td>
                        <td>${activo.nombre_tipo_activo || 'N/A'}</td>
                        <td>${activo.marca || ''}</td>
                        <td>${activo.serie || ''}</td>
                        <td><span class="${getEstadoBadgeClass(activo.estado)}">${activo.estado || ''}</span></td>
                        <td>${valorFormateado}</td>
                        <td>${fechaCompra}</td>
                        <td>
                            <a href="historial.php?id_activo=${activo.id}" class="btn btn-sm btn-outline-info" title="Ver Historial" target="_blank"><i class="bi bi-list-task"></i></a>
                            <a href="editar.php?id_activo_focus=${activo.id}" class="btn btn-sm btn-outline-primary" title="Editar Activo" target="_blank"><i class="bi bi-pencil-fill"></i></a>
                        </td>
                    </tr>
                `;
            });

            html += `</tbody></table></div></div>`;
        }
        contenedorResultados.innerHTML = html;
    }

    // --- FUNCIÓN 3: REALIZAR BÚSQUEDA CON AJAX ---
    async function realizarBusqueda() {
        loader.classList.remove('d-none');
        loader.classList.add('d-flex');
        contenedorResultados.innerHTML = '';

        const formData = new FormData(formFiltros);
        const params = new URLSearchParams(formData).toString();

        try {
            const response = await fetch(`api/api_buscar.php?${params}`);
            if (!response.ok) {
                const errorData = await response.json().catch(() => ({ error: 'Error desconocido en el servidor.' }));
                throw new Error(`Error en la red: ${response.statusText}. Detalles: ${errorData.error}`);
            }
            const data = await response.json();
            renderizarResultados(data);
        } catch (error) {
            console.error('Error en la búsqueda:', error);
            contenedorResultados.innerHTML = `<div class="alert alert-danger">Error al cargar los datos. Por favor, revise la consola (F12) o contacte al administrador.</div>`;
        } finally {
            loader.classList.add('d-none');
            loader.classList.remove('d-flex');
        }
    }

    // --- EVENT LISTENERS ---
    formFiltros.addEventListener('submit', function(event) {
        event.preventDefault(); 
        realizarBusqueda();
    });

    btnLimpiar.addEventListener('click', function() {
        formFiltros.reset();
        realizarBusqueda();
    });

});
</script>

</body>
</html>