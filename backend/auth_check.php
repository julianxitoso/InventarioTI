<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

function verificar_sesion_activa() {
    if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !isset($_SESSION['usuario_id'])) { // Verificación más robusta
        // Guardar la URL solicitada para redirigir después del login
        // $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI']; // Opcional
        header("Location: login.php?error=sesion_requerida");
        exit;
    }
}

function obtener_rol_usuario() {
    return $_SESSION['rol_usuario'] ?? null;
}

function es_admin() {
    return obtener_rol_usuario() === 'admin';
}

function es_tecnico() {
    return obtener_rol_usuario() === 'tecnico';
}

function es_auditor() {
    return obtener_rol_usuario() === 'auditor';
}

function es_registrador() { // <<< Nueva función helper
    return obtener_rol_usuario() === 'registrador';
}

// Definición centralizada de permisos por rol
// Esto puede estar aquí o en un archivo de configuración separado si crece mucho
$GLOBALS['config_permisos_roles'] = [
    'admin' => [
        'ver_menu', 'ver_dashboard', 'buscar_activo', 'ver_historial', 
        'generar_informes', 'crear_activo', 'editar_activo_detalles', 
        'trasladar_activo', 'dar_baja_activo', 'eliminar_activo_fisico', 
        'gestionar_usuarios' // Para acceder a registrar_usuario.php
    ],
    'tecnico' => [
        'ver_menu', 'ver_dashboard', // O un dashboard limitado
        'buscar_activo', 'ver_historial', 'crear_activo', 
        'editar_activo_detalles', 'trasladar_activo', 'dar_baja_activo',
        'generar_informes' // Técnicos usualmente pueden necesitar generar informes
    ],
    'auditor' => [
        'ver_menu', 'ver_dashboard', // O un dashboard de solo lectura
        'buscar_activo', 'ver_historial', 'generar_informes'
    ],
    'registrador' => [ // <<< PERMISOS ESPECÍFICOS PARA EL ROL REGISTRADOR
        'ver_menu',       // Para poder ver el menú y sus opciones limitadas
        'crear_activo',   // Permiso para acceder a index.php (registrar) y guardar_activo.php
        'buscar_activo'   // Permiso para acceder a buscar.php
        // 'ver_historial' // Opcional: Si quieres que puedan ver el historial de los activos que buscan
    ]
];

function tiene_permiso_para($accion) {
    $rol = obtener_rol_usuario();
    if (!$rol) return false;

    global $config_permisos_roles; // Acceder al array global

    if (isset($config_permisos_roles[$rol]) && in_array($accion, $config_permisos_roles[$rol])) {
        return true;
    }
    return false;
}

function restringir_acceso_pagina($roles_o_permisos_permitidos = []) {
    verificar_sesion_activa(); // Primero asegura que hay sesión
    
    if (empty($roles_o_permisos_permitidos)) { // Si no se especifican, solo se requiere estar logueado
        return;
    }

    $acceso_concedido = false;
    // Verificar si el primer elemento es un rol conocido, si no, asumir que son permisos
    $primer_elemento = $roles_o_permisos_permitidos[0] ?? null;
    $es_lista_de_roles = in_array($primer_elemento, ['admin', 'tecnico', 'auditor', 'registrador']);

    if ($es_lista_de_roles) {
        $rol_actual = obtener_rol_usuario();
        if (in_array($rol_actual, $roles_o_permisos_permitidos)) {
            $acceso_concedido = true;
        }
    } else { // Asumir que es una lista de permisos de acción
        foreach ($roles_o_permisos_permitidos as $permiso_requerido) {
            if (tiene_permiso_para($permiso_requerido)) {
                $acceso_concedido = true;
                break; // Si tiene al menos uno de los permisos requeridos (lógica OR)
                       // Si necesitas que tenga TODOS los permisos, cambia esta lógica
            }
        }
    }

    if (!$acceso_concedido) {
        $_SESSION['error_acceso_pagina'] = "Acceso Denegado: No tiene los permisos necesarios para acceder a esta página o realizar esta acción.";
        header("Location: menu.php"); 
        exit;
    }
}
?>