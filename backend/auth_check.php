<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

function verificar_sesion_activa() {
    if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !isset($_SESSION['usuario_id'])) {
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

function es_registrador() {
    return obtener_rol_usuario() === 'registrador';
}

// Definición centralizada de permisos por rol
$GLOBALS['config_permisos_roles'] = [
    'admin' => [
        'ver_menu', 'ver_dashboard', 'buscar_activo', 'ver_historial',
        'generar_informes', 'crear_activo', 'editar_activo_detalles',
        'trasladar_activo', 'dar_baja_activo', 'eliminar_activo_fisico',
        'gestionar_usuarios',
        'ver_depreciacion',         // Permiso para ver módulo de depreciación
        'registrar_mantenimiento'   // <<< NUEVO PERMISO AÑADIDO AQUÍ
    ],
    'tecnico' => [
        'ver_menu', 'ver_dashboard',
        'buscar_activo', 'ver_historial', 'crear_activo',
        'editar_activo_detalles', 'trasladar_activo', 'dar_baja_activo',
        'generar_informes',
        'registrar_mantenimiento'   // <<< NUEVO PERMISO AÑADIDO AQUÍ
    ],
    'auditor' => [
        'ver_menu', 'ver_dashboard',
        'buscar_activo', 'ver_historial', 'generar_informes',
        'ver_depreciacion'         // Auditores también podrían ver depreciación
    ],
    'registrador' => [
        'ver_menu',
        'crear_activo',
        'buscar_activo'
    ]
];

function tiene_permiso_para($accion) {
    $rol = obtener_rol_usuario();
    if (!$rol) return false;

    global $config_permisos_roles;

    if (isset($config_permisos_roles[$rol]) && in_array($accion, $config_permisos_roles[$rol])) {
        return true;
    }
    return false;
}

function restringir_acceso_pagina($roles_o_permisos_permitidos = []) {
    verificar_sesion_activa();
    
    if (empty($roles_o_permisos_permitidos)) {
        return;
    }

    $acceso_concedido = false;
    $primer_elemento = $roles_o_permisos_permitidos[0] ?? null;
    $es_lista_de_roles = in_array($primer_elemento, ['admin', 'tecnico', 'auditor', 'registrador']);

    if ($es_lista_de_roles) {
        $rol_actual = obtener_rol_usuario();
        if (in_array($rol_actual, $roles_o_permisos_permitidos)) {
            $acceso_concedido = true;
        }
    } else {
        foreach ($roles_o_permisos_permitidos as $permiso_requerido) {
            if (tiene_permiso_para($permiso_requerido)) {
                $acceso_concedido = true;
                break; 
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