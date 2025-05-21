<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

function verificar_sesion_activa() {
    if (!isset($_SESSION['usuario_id'])) {
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

function tiene_permiso_para($accion) {
    $rol = obtener_rol_usuario();
    if (!$rol) return false;

    switch ($accion) {
        case 'ver_menu':
        case 'ver_dashboard':
        case 'buscar_activo':       // Todos pueden buscar
        case 'ver_historial':       // Todos pueden ver historial si llegan al activo
        case 'generar_informes':    // Todos pueden generar informes
            return in_array($rol, ['admin', 'tecnico', 'auditor']);

        case 'crear_activo':
        case 'editar_activo_detalles': // Editar campos como marca, serie, SO, etc.
        case 'trasladar_activo':
        case 'dar_baja_activo':        // Técnico SÍ PUEDE dar de baja lógica
            return in_array($rol, ['admin', 'tecnico']);

        case 'eliminar_activo_fisico': // El botón de borrado físico DELETE
        case 'gestionar_usuarios':     // Futura página para administración de usuarios
            return $rol === 'admin';
        
        default:
            return false;
    }
}

function restringir_acceso_pagina($roles_permitidos = []) {
    verificar_sesion_activa(); // Primero asegura que hay sesión
    $rol_actual = obtener_rol_usuario();

    if (empty($roles_permitidos)) { // Si no se especifican roles, solo se requiere estar logueado
        return;
    }
    if (!in_array($rol_actual, $roles_permitidos)) {
        $_SESSION['mensaje_error_global'] = "Acceso Denegado: No tiene los permisos necesarios para acceder a esta página o realizar esta acción.";
        header("Location: menu.php"); // O dashboard.php o una página de acceso_denegado.php
        exit;
    }
}
?>