<?php
session_start();
if (!isset($_SESSION['usuario'])) {
    header("Location: login.php");
    exit;
}

// Configurar locale para nombres de meses en español
@setlocale(LC_TIME, 'es_ES.UTF-8', 'es_ES', 'esp'); // Intenta varias opciones comunes

// 1. INCLUIR EL AUTOLOADER DE COMPOSER
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} else {
    error_log("Error Crítico: No se encuentra el archivo autoload.php de Composer en generar_acta.php.");
    die("Error de configuración: No se puede generar el PDF. Contacte al administrador.");
}

// 2. CONEXIÓN A LA BASE DE DATOS
require_once 'backend/db.php';
if (isset($conn) && !isset($conexion)) { $conexion = $conn; }

if (!isset($conexion) || !$conexion) {
    error_log("Error de conexión a la BD no disponible en generar_acta.php.");
    die("Error de conexión a la base de datos. Contacte al administrador.");
}

$tipo_acta = $_GET['tipo_acta'] ?? 'entrega';

// ***** MODIFICACIÓN 1: Definir $texto_certificacion_base *****
$texto_certificacion_base = "Se deja constancia y se certifica que los activos listados en la presente acta corresponden al siguiente tipo de movimiento:";

$empleado_recibe_info = null;
$empleado_entrega_info = null;
$activos_para_acta = [];
$titulo_acta = ""; // Se definirá más abajo
$texto_certificacion_tipo_descriptivo = ""; // Para el texto descriptivo del tipo de movimiento
$check_ingreso_class = "";
$check_traslado_class = "";


if ($tipo_acta === 'traslado') {
    $cedula_destino_recibe = urldecode($_GET['cedula_destino'] ?? '');
    $nombre_destino_recibe = urldecode($_GET['nombre_destino'] ?? ''); // Viene de editar.php
    $cargo_destino_recibe = urldecode($_GET['cargo_destino'] ?? '');   // Viene de editar.php
    $regional_destino_recibe = urldecode($_GET['regional_destino'] ?? '');// Viene de editar.php

    $ids_activos_trasladados_str = $_GET['ids_activos'] ?? '';

    // Datos del empleado que ENTREGA (el responsable anterior)
    $cedula_origen_entrega = urldecode($_GET['cedula_origen'] ?? '');
    $nombre_origen_entrega = urldecode($_GET['nombre_origen'] ?? '');
    $cargo_origen_entrega = urldecode($_GET['cargo_origen'] ?? '');

    // Quién realiza la operación en el sistema (para "Autorizado por" o como referencia)
    $usuario_que_realizo_operacion = urldecode($_GET['usuario_entrega_operacion'] ?? $_SESSION['usuario']);

    if (empty($cedula_destino_recibe) || empty($nombre_destino_recibe) || empty($ids_activos_trasladados_str)) {
        die("Faltan datos para generar el acta de traslado (cédula/nombre destino o IDs de activos).");
    }

    $empleado_recibe_info = [
        'cedula' => $cedula_destino_recibe,
        'nombre' => $nombre_destino_recibe,
        'cargo' => $cargo_destino_recibe ?: 'N/A', // Usar N/A si el cargo está vacío
        'regional' => $regional_destino_recibe ?: 'N/A'
    ];

    if (empty($cedula_origen_entrega) || empty($nombre_origen_entrega)) {
        error_log("Advertencia: Datos incompletos del empleado origen para el acta de traslado. Cédula Destino: " . $cedula_destino_recibe);
        $empleado_entrega_info = [
            'nombre' => $usuario_que_realizo_operacion,
            'cedula' => 'N/A (Operación)',
            'cargo' => 'Representante Empresa (Autoriza Traslado)'
        ];
    } else {
        $empleado_entrega_info = [
            'nombre' => $nombre_origen_entrega,
            'cedula' => $cedula_origen_entrega,
            'cargo' => $cargo_origen_entrega ?: 'N/A'
        ];
    }

    $ids_array = array_map('intval', explode(',', $ids_activos_trasladados_str));
    if (empty($ids_array)) { die("No se proporcionaron IDs de activos válidos para el traslado."); }
    $ids_placeholders = implode(',', array_fill(0, count($ids_array), '?'));
    $types_ids = str_repeat('i', count($ids_array));

    $sql_activos = "SELECT * FROM activos_tecnologicos WHERE id IN ($ids_placeholders) ORDER BY tipo_activo ASC";
    $stmt_activos = $conexion->prepare($sql_activos);
    if (!$stmt_activos) { die("Error preparando consulta de activos trasladados: " . $conexion->error); }
    $stmt_activos->bind_param($types_ids, ...$ids_array);
    $stmt_activos->execute();
    $result_activos_trasl = $stmt_activos->get_result();
    while ($row = $result_activos_trasl->fetch_assoc()) { $activos_para_acta[] = $row; }
    $stmt_activos->close();

    $titulo_acta = "ACTA DE TRASLADO DE ACTIVOS FIJOS";
    $texto_certificacion_tipo_descriptivo = "Traslado"; // Usar esta variable para el texto
    $check_ingreso_class = "";
    $check_traslado_class = "checked";

} else { // Acta de entrega normal
    $cedula_empleado = $_GET['cedula'] ?? '';
    if (empty($cedula_empleado)) { die("Cédula del empleado no proporcionada para acta de entrega."); }

    $stmt_empleado_info = $conexion->prepare("SELECT nombre, cargo, regional FROM activos_tecnologicos WHERE cedula = ? LIMIT 1");
    if (!$stmt_empleado_info) { die("Error preparando consulta de empleado: " . $conexion->error); }
    $stmt_empleado_info->bind_param("s", $cedula_empleado);
    $stmt_empleado_info->execute();
    $result_empleado_info = $stmt_empleado_info->get_result();
    $empleado_recibe_info = $result_empleado_info->fetch_assoc();
    if ($empleado_recibe_info) $empleado_recibe_info['cedula'] = $cedula_empleado;
    $stmt_empleado_info->close();

    if (!$empleado_recibe_info) { die("Empleado con cédula " . htmlspecialchars($cedula_empleado) . " no encontrado."); }

    $stmt_activos = $conexion->prepare("SELECT * FROM activos_tecnologicos WHERE cedula = ? ORDER BY tipo_activo ASC, id ASC");
    if (!$stmt_activos) { die("Error preparando consulta de activos: " . $conexion->error); }
    $stmt_activos->bind_param("s", $cedula_empleado);
    $stmt_activos->execute();
    $result_activos = $stmt_activos->get_result();
    while ($row = $result_activos->fetch_assoc()) { $activos_para_acta[] = $row; }
    $stmt_activos->close();

    $empleado_entrega_info = [ // Quien entrega por parte de la empresa para una entrega normal
        'nombre' => $_SESSION['usuario'],
        'cedula' => 'N/A (Operación)',
        'cargo' => 'Representante Empresa'
    ];
    $titulo_acta = "ACTA DE ENTREGA DE ACTIVOS FIJOS";
    $texto_certificacion_tipo_descriptivo = "Ingreso (Asignación Inicial / Nueva)"; // Usar esta variable
    $check_ingreso_class = "checked";
    $check_traslado_class = "";
}

$conexion->close();

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Acta - <?= htmlspecialchars($empleado_recibe_info['nombre'] ?? 'N/A') ?></title>
    <style>
        /* ... (tu CSS existente sin cambios) ... */
        @page { margin: 0.8cm 1.2cm; }
        body { font-family: Arial, sans-serif; font-size: 9.5pt; color: #333; }
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        .header-table td { vertical-align: middle; padding: 0; }
        .logo { width: 150px; height: auto; }
        .company-titles { text-align: center; font-size: 8.5pt; }
        .company-titles .main-title { font-size: 10.5pt; font-weight: bold; margin-top: 3px;}
        .document-info { font-size: 8.5pt; margin-bottom: 10px; }
        .document-info table { width: 100%; }
        .document-info td { padding: 1px 0; }
        .section-title { font-weight: bold; margin-top: 10px; margin-bottom: 3px; font-size: 10pt; }
        .commitment-text { font-size: 9pt; text-align: justify; margin-bottom: 10px; line-height: 1.3; }
        .assets-table { width: 100%; border-collapse: collapse; margin-top: 8px; margin-bottom: 10px; font-size: 8.5pt; }
        .assets-table th, .assets-table td { border: 1px solid #555; padding: 4px; text-align: left; word-wrap: break-word; }
        .assets-table th { background-color: #EAEAEA; font-weight: bold; }
        .observations-general { margin-top: 10px; margin-bottom:10px; font-size: 9pt; }
        .movement-type { margin-bottom: 15px; font-size: 9pt; }
        .checkbox { display: inline-block; width: 10px; height: 10px; border: 1px solid #000; margin-right: 4px; vertical-align: middle; position: relative; top: -1px;}
        .checked::after { content: "X"; display: block; text-align: center; font-size: 9px; line-height: 10px; font-weight:bold; }

        .signatures { margin-top: 25px; width: 100%; font-size: 9pt; border-collapse: collapse; table-layout: fixed; }
        .signatures td {
            width: 33.33%;
            text-align: center;
            vertical-align: bottom; /* Alinea el contenido de la celda en la parte inferior */
            padding: 0 5px;
            border: none;
        }
        .signature-content { /* Div para agrupar texto y línea de firma */
            padding-top: 30px; /* Espacio para la firma física arriba de la línea */
            min-height: 75px;  /* Altura mínima para consistencia visual */
            display: flex;
            flex-direction: column;
            justify-content: flex-end; /* Alinea el contenido del div (texto) al final (abajo) */
        }
        .signature-line { width: 90%; border-bottom: 1px solid #000; margin: 0 auto 3px auto; height: 15px; } /* Línea de firma */
        .footer-text { font-size: 7.5pt; text-align: center; margin-top: 20px; color: #888;}
    </style>
</head>
<body>
    <table class="header-table">
        <tr>
            <td style="width: 25%; text-align:left;"><img src="imagenes/logo3.png" alt="Logo Empresa" class="logo"></td>
            <td style="width: 50%;" class="company-titles">
                PROCESO EVALUACIÓN Y CONTROL<br>
                PROCEDIMIENTO DE AUDITORIA INTERNA<br>
                ARPESOD ASOCIADOS SAS<br>
                NIT. 900.333.755-6<br>
                <div class="main-title"><?= htmlspecialchars($titulo_acta) ?></div>
            </td>
            <td style="width: 25%; text-align: right; font-size: 9pt; vertical-align: top;">
                {/* Acta N°: [NÚMERO SI APLICA] */}
            </td>
        </tr>
    </table>

    <div class="document-info">
        <table>
            <tr>
                <td><strong>Fecha:</strong> <?= date("d") . " de " . ucfirst(strftime("%B", mktime(0,0,0,date("m")))) . " de " . date("Y") ?></td>
                <td><strong>Área que Recibe:</strong> <?= htmlspecialchars($empleado_recibe_info['cargo'] ?? 'N/A') ?></td>
            </tr>
            <tr>
                <td><strong>Regional que Recibe:</strong> <?= htmlspecialchars($empleado_recibe_info['regional'] ?? 'N/A') ?></td>
                <td><strong>Punto de Venta:</strong> __________________</td>
            </tr>
        </table>
    </div>

    <?php if ($tipo_acta === 'entrega'): ?>
    <p class="commitment-text">
        Para formalizar la entrega, en la presente acta quedarán consignados los equipos y/o elementos de oficina que están bajo la responsabilidad, buen uso y cuidado del empleado(a) que recibe. Los daños ocasionados por negligencia o mal uso comprobado podrán ser descontados conforme a las políticas de la empresa.
    </p>
    <p class="commitment-text">
        Cuando haya terminación del contrato laboral o retiro voluntario, el empleado(a) debe hacer entrega de los activos fijos aquí estipulados a su jefe inmediato o a la persona designada por la empresa, en las mismas condiciones de recepción, salvo el deterioro normal por el uso adecuado. Este procedimiento es requisito indispensable para la expedición del paz y salvo laboral.
    </p>
    <?php elseif ($tipo_acta === 'traslado'): ?>
    <p class="commitment-text">
        Por medio de la presente se formaliza el traslado de los siguientes activos tecnológicos propiedad de ARPESOD ASOCIADOS SAS.
        Los activos listados a continuación son entregados por: <strong><?= htmlspecialchars($empleado_entrega_info['nombre'] ?? 'N/A') ?></strong>
        (C.C./Ref: <?= htmlspecialchars($empleado_entrega_info['cedula'] ?? 'N/A') ?>, Cargo: <?= htmlspecialchars($empleado_entrega_info['cargo'] ?? 'N/A') ?>)
        y recibidos por: <strong><?= htmlspecialchars($empleado_recibe_info['nombre'] ?? 'N/A') ?></strong>
        (C.C: <?= htmlspecialchars($empleado_recibe_info['cedula'] ?? 'N/A') ?>, Cargo: <?= htmlspecialchars($empleado_recibe_info['cargo'] ?? 'N/A') ?>), quien asume la responsabilidad por su buen uso y cuidado.
    </p>
    <?php endif; ?>

    <div class="section-title">ACTIVOS OBJETO DE <?= strtoupper($tipo_acta) ?>:</div>
    <?php if (!empty($activos_para_acta)): ?>
    <table class="assets-table">
        <thead>
            <tr>
                <th style="width:25%;">Serie / Código Interno</th>
                <th style="width:15%;">Marca</th>
                <th style="width:30%;">Descripción del Activo (Tipo)</th>
                <th style="width:10%;">Estado</th>
                <th style="width:20%;">Observaciones / Detalles Específicos</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($activos_para_acta as $activo): ?>
            <tr>
                <td><?= htmlspecialchars($activo['serie'] ?: 'N/A') ?></td>
                <td><?= htmlspecialchars($activo['marca'] ?? 'N/A') ?></td>
                <td><?= htmlspecialchars($activo['tipo_activo'] ?? 'N/A') ?></td>
                <td><?= htmlspecialchars($activo['estado'] ?? 'N/A') ?></td>
                <td>
                    <?php
                    $detalles_comp = [];
                    if (strtolower($activo['tipo_activo'] ?? '') == 'computador') {
                        if (!empty($activo['procesador'])) $detalles_comp[] = "Proc: " . htmlspecialchars($activo['procesador']);
                        if (!empty($activo['ram'])) $detalles_comp[] = "RAM: " . htmlspecialchars($activo['ram']);
                        if (!empty($activo['disco_duro'])) $detalles_comp[] = "Disco: " . htmlspecialchars($activo['disco_duro']);
                        if (!empty($activo['tipo_equipo'])) $detalles_comp[] = "Tipo PC: " . htmlspecialchars($activo['tipo_equipo']);
                        if (!empty($activo['sistema_operativo'])) $detalles_comp[] = "SO: " . htmlspecialchars($activo['sistema_operativo']);
                        if (!empty($activo['offimatica'])) $detalles_comp[] = "Office: " . htmlspecialchars($activo['offimatica']);
                        if (!empty($activo['antivirus'])) $detalles_comp[] = "AV: " . htmlspecialchars($activo['antivirus']);
                    }
                    if (!empty($activo['detalles'])) {
                         $detalles_comp[] = ($activo['tipo_activo'] == 'Computador' ? "Otros: ":"") . htmlspecialchars($activo['detalles']);
                    }
                    echo !empty($detalles_comp) ? implode("; ", $detalles_comp) : 'N/A';
                    ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
        <p>No hay activos para listar en esta acta.</p>
    <?php endif; ?>

    <div class="observations-general section-title">OBSERVACIONES GENERALES ADICIONALES:</div>
    <div style="border: 1px solid #ccc; min-height: 40px; padding: 5px; margin-bottom:15px; background-color: #fdfdfd;">
        &nbsp;
    </div>

    <div class="movement-type">
        <p><?= htmlspecialchars($texto_certificacion_base) ?></p>
        <p style="margin-bottom: 5px;"><strong>Tipo de Movimiento Efectuado:</strong> <?= htmlspecialchars($texto_certificacion_tipo_descriptivo) ?></p>
        <span class="checkbox <?= $check_ingreso_class ?>"></span> Ingreso (Asignación Inicial / Nueva)
        <span style="margin-left:15px;"><span class="checkbox <?= $check_traslado_class ?>"></span> Traslado</span>
    </div>

    <table class="signatures">
        <tr>
            <td>
                <div class="signature-content">
                    <div class="signature-line"></div>
                    <strong>Entrega (<?= ($tipo_acta === 'traslado') ? 'Empleado Anterior/Saliente' : 'Representante Empresa' ?>)</strong><br>
                    Nombre: <?= htmlspecialchars($empleado_entrega_info['nombre'] ?? 'N/A') ?><br>
                    C.C./Ref: <?= htmlspecialchars($empleado_entrega_info['cedula'] ?? 'N/A') ?><br>
                    Cargo: <?= htmlspecialchars($empleado_entrega_info['cargo'] ?? 'N/A') ?>
                </div>
            </td>
            <td>
                <div class="signature-content">
                    <div class="signature-line"></div>
                    <strong>Recibe (Empleado)</strong><br>
                    Nombre: <?= htmlspecialchars($empleado_recibe_info['nombre'] ?? 'N/A') ?><br>
                    C.C.: <?= htmlspecialchars($empleado_recibe_info['cedula'] ?? 'N/A') ?>
                </div>
            </td>
             <td>
                <div class="signature-content">
                    <div class="signature-line"></div>
                    <strong>Autorizado Por (Jefe Inmediato/Gerencia)</strong><br>
                    Nombre: _________________________<br>
                    Cargo: __________________________
                </div>
            </td>
        </tr>
         <tr>
            <td style="padding-top: 2px;">Fecha: <?= ($tipo_acta === 'traslado' && isset($empleado_entrega_info['cedula']) && $empleado_entrega_info['cedula'] !== 'N/A (Operación)') ? '________________' : date("d/m/Y") ?></td>
            <td style="padding-top: 2px;">Fecha: <?= date("d/m/Y") ?></td>
            <td style="padding-top: 2px;">Fecha: ________________</td>
        </tr>
    </table>
    <div class="footer-text">
        Original: Archivo Empleado / Copia: Empleado
    </div>
</body>
</html>
<?php
$html = ob_get_clean();

try {
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8', 'format' => 'A4',
        'margin_left' => 12, 'margin_right' => 12,
        'margin_top' => 10, 'margin_bottom' => 10,
        'default_font_size' => 9, 'default_font' => 'helvetica'


        
    ]);

    $pdf_title = ($tipo_acta === 'traslado' ? "Acta Traslado - " : "Acta Entrega - ") . ($empleado_recibe_info['nombre'] ?? 'N_A');
    $mpdf->SetTitle($pdf_title);
    $mpdf->SetAuthor("ARPESOD ASOCIADOS SAS");

    $mpdf->WriteHTML($html);

    $nombre_archivo_sanitizado = preg_replace('/[^A-Za-z0-9_\-]/', '_', $empleado_recibe_info['nombre'] ?? 'Desconocido');
    $nombre_archivo_prefijo = ($tipo_acta === 'traslado' ? "Acta_Traslado_" : "Acta_Entrega_");
    $nombre_archivo = $nombre_archivo_prefijo . $nombre_archivo_sanitizado . "_" . ($empleado_recibe_info['cedula'] ?? 'SC') . ".pdf";

    $mpdf->Output($nombre_archivo, \Mpdf\Output\Destination::INLINE);
    exit;

} catch (\Mpdf\MpdfException $e) {
    error_log('Error al generar el PDF (generar_acta.php): ' . $e->getMessage());
    die ('Error al generar el PDF. Por favor, contacte al administrador. Detalles del error han sido registrados.');
}
?>