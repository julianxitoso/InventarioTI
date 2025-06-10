<?php
session_start();
require_once 'backend/auth_check.php'; // Asegúrate que este archivo exista y funcione
restringir_acceso_pagina(['admin']); 

require_once 'backend/db.php'; // Asegúrate que este archivo exista y funcione

if (isset($conn) && !isset($conexion)) {
    $conexion = $conn;
}
if (!isset($conexion) || !$conexion || (method_exists($conexion, 'connect_error') && $conexion->connect_error)) {
    error_log("GESTIONAR_USUARIOS: Error de conexión a la BD: " . ($conexion->connect_error ?? 'Desconocido'));
    die("Error de conexión a la base de datos. Por favor, revisa la configuración.");
}
$conexion->set_charset("utf8mb4");

$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Administrador';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'admin';
$id_usuario_actual_sesion = $_SESSION['id_usuario'] ?? null; 

$mensaje_accion = $_SESSION['mensaje_accion_usuarios'] ?? null;
if (isset($_SESSION['mensaje_accion_usuarios'])) {
    unset($_SESSION['mensaje_accion_usuarios']);
}

$usuario_para_editar = null;

// --- OBTENER DATOS PARA FORMULARIOS (DESPLEGABLES) ---
$cargos_form = [];
$result_cargos_dd = $conexion->query("SELECT nombre_cargo FROM cargos ORDER BY nombre_cargo ASC");
if ($result_cargos_dd) { while ($row_dd = $result_cargos_dd->fetch_assoc()) { $cargos_form[] = $row_dd['nombre_cargo']; } }

$empresas_form = [];
$result_empresas_dd = $conexion->query("SELECT DISTINCT empresa FROM usuarios WHERE empresa IS NOT NULL AND empresa != '' ORDER BY empresa ASC");
if ($result_empresas_dd) { while ($row_dd = $result_empresas_dd->fetch_assoc()) { $empresas_form[] = $row_dd['empresa']; } }

$regionales_form = [];
$result_regionales_dd = $conexion->query("SELECT DISTINCT regional FROM usuarios WHERE regional IS NOT NULL AND regional != '' ORDER BY regional ASC");
if ($result_regionales_dd) { while ($row_dd = $result_regionales_dd->fetch_assoc()) { $regionales_form[] = $row_dd['regional']; } }

$roles_form = ['admin', 'auditor', 'tecnico', 'registrador']; 

// Funciones
if (!function_exists('formatoTitulo')) {
    function formatoTitulo($string) { return mb_convert_case(trim($string), MB_CASE_TITLE, "UTF-8"); }
}
if (!function_exists('getRolBadgeClass')) {
    function getRolBadgeClass($rol) {
        $rolLower = strtolower(trim($rol ?? ''));
        switch ($rolLower) {
            case 'admin': return 'badge rounded-pill bg-success';
            case 'auditor': return 'badge rounded-pill bg-primary';
            case 'tecnico': return 'badge rounded-pill bg-danger';
            case 'registrador': return 'badge rounded-pill bg-warning text-dark';
            default: return 'badge rounded-pill bg-secondary';
        }
    }
}

// --- LÓGICA POST (CREAR, ACTUALIZAR, ELIMINAR) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ------ CREAR USUARIO ------
    if (isset($_POST['crear_usuario_submit'])) {
        $nuevo_usuario_login = trim($_POST['nuevo_usuario_login']);
        $nuevo_nombre_completo = formatoTitulo(trim($_POST['nuevo_nombre_completo']));
        $nueva_clave = $_POST['nueva_clave'];
        $confirmar_nueva_clave = $_POST['confirmar_nueva_clave'];
        $nombre_cargo_seleccionado = trim($_POST['nuevo_cargo']);
        $nueva_empresa = $_POST['nueva_empresa'] ?? '';
        $nueva_regional = $_POST['nueva_regional'] ?? '';
        $nuevo_rol = $_POST['nuevo_rol'];
        $nuevo_activo = isset($_POST['nuevo_activo']) ? 1 : 0;
        $mensaje_creacion_error = '';

        if (empty($nuevo_usuario_login) || empty($nuevo_nombre_completo) || empty($nueva_clave) || empty($nombre_cargo_seleccionado) || empty($nuevo_rol)) {
            $mensaje_creacion_error = "<div class='alert alert-danger'>Creación: Usuario, Nombre, Clave, Cargo y Rol son obligatorios.</div>";
        } elseif ($nueva_clave !== $confirmar_nueva_clave) {
            $mensaje_creacion_error = "<div class='alert alert-danger'>Creación: Las contraseñas no coinciden.</div>";
        } else {
            $id_cargo_nuevo = null;
            if (!empty($nombre_cargo_seleccionado)) {
                $stmt_get_cargo = $conexion->prepare("SELECT id_cargo FROM cargos WHERE nombre_cargo = ?");
                if ($stmt_get_cargo) {
                    $stmt_get_cargo->bind_param("s", $nombre_cargo_seleccionado); $stmt_get_cargo->execute();
                    $result_cargo_obj = $stmt_get_cargo->get_result();
                    if ($row_cargo_obj = $result_cargo_obj->fetch_assoc()) { $id_cargo_nuevo = $row_cargo_obj['id_cargo']; }
                    $stmt_get_cargo->close();
                }
                if ($id_cargo_nuevo === null) { // Si se proveyó cargo pero no se encontró ID
                     $mensaje_creacion_error = "<div class='alert alert-danger'>Creación: El cargo seleccionado '" . htmlspecialchars($nombre_cargo_seleccionado) . "' no es válido.</div>";
                }
            } // Cargo es obligatorio, el primer if ya lo captura si está vacío.

            if (empty($mensaje_creacion_error)) {
                $stmt_check = $conexion->prepare("SELECT id FROM usuarios WHERE usuario = ?");
                $stmt_check->bind_param("s", $nuevo_usuario_login); $stmt_check->execute();
                $stmt_check->store_result();
                if ($stmt_check->num_rows > 0) {
                    $mensaje_creacion_error = "<div class='alert alert-danger'>Creación: El nombre de usuario (cédula) '" . htmlspecialchars($nuevo_usuario_login) . "' ya existe.</div>";
                } else {
                    $clave_hasheada = password_hash($nueva_clave, PASSWORD_DEFAULT);
                    $sql_insert = "INSERT INTO usuarios (usuario, clave, nombre_completo, id_cargo, empresa, regional, rol, activo) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt_insert = $conexion->prepare($sql_insert);
                    $stmt_insert->bind_param("sssisssi", $nuevo_usuario_login, $clave_hasheada, $nuevo_nombre_completo, $id_cargo_nuevo, $nueva_empresa, $nueva_regional, $nuevo_rol, $nuevo_activo);
                    if ($stmt_insert->execute()) {
                        $_SESSION['mensaje_accion_usuarios'] = "<div class='alert alert-success'>Usuario '" . htmlspecialchars($nuevo_usuario_login) . "' creado exitosamente.</div>";
                        header("Location: gestionar_usuarios.php"); exit;
                    } else {
                        $mensaje_creacion_error = "<div class='alert alert-danger'>Creación: Error al crear el usuario: " . $stmt_insert->error . "</div>";
                        error_log("GESTIONAR_USUARIOS: Error al CREAR usuario: " . $stmt_insert->error);
                    }
                    $stmt_insert->close();
                }
                $stmt_check->close();
            }
        }
        if (!empty($mensaje_creacion_error)) {
            $_SESSION['mensaje_accion_usuarios'] = $mensaje_creacion_error;
            header("Location: gestionar_usuarios.php?error_creacion=1"); exit;
        }
    } 
    // ------ EDITAR USUARIO ------
    elseif (isset($_POST['editar_usuario_submit'])) {
        $id_usuario_editar = filter_input(INPUT_POST, 'id_usuario_editar', FILTER_VALIDATE_INT);
        $edit_usuario_login = trim($_POST['edit_usuario_login']);
        $edit_nombre_completo = formatoTitulo(trim($_POST['edit_nombre_completo']));
        $nombre_cargo_seleccionado_edit = trim($_POST['edit_cargo']);
        $edit_empresa = $_POST['edit_empresa'] ?? '';
        $edit_regional = $_POST['edit_regional'] ?? '';
        $edit_rol = $_POST['edit_rol'];
        $edit_activo = isset($_POST['edit_activo']) ? 1 : 0;
        $cambiar_clave_check = isset($_POST['edit_cambiar_clave_check']);
        $mensaje_error_edicion = '';

        if (empty($id_usuario_editar) || empty($edit_usuario_login) || empty($edit_nombre_completo) || empty($nombre_cargo_seleccionado_edit) || empty($edit_rol)) {
            $mensaje_error_edicion = "<div class='alert alert-danger'>Edición: Todos los campos marcados con * son obligatorios.</div>";
        }

        if (empty($mensaje_error_edicion)) {
            $stmt_check_cedula_edit = $conexion->prepare("SELECT id FROM usuarios WHERE usuario = ? AND id != ?");
            $stmt_check_cedula_edit->bind_param("si", $edit_usuario_login, $id_usuario_editar);
            $stmt_check_cedula_edit->execute(); $stmt_check_cedula_edit->store_result();
            if ($stmt_check_cedula_edit->num_rows > 0) {
                $mensaje_error_edicion = "<div class='alert alert-danger'>Edición: El nombre de usuario (cédula) '" . htmlspecialchars($edit_usuario_login) . "' ya está en uso por otro usuario.</div>";
            }
            $stmt_check_cedula_edit->close();
        }
        
        $id_cargo_editado = null;
        if (empty($mensaje_error_edicion) && !empty($nombre_cargo_seleccionado_edit)) {
            $stmt_get_cargo_edit = $conexion->prepare("SELECT id_cargo FROM cargos WHERE nombre_cargo = ?");
            if ($stmt_get_cargo_edit) {
                $stmt_get_cargo_edit->bind_param("s", $nombre_cargo_seleccionado_edit); $stmt_get_cargo_edit->execute();
                $result_cargo_edit = $stmt_get_cargo_edit->get_result();
                if ($row_cargo_edit = $result_cargo_edit->fetch_assoc()) { $id_cargo_editado = $row_cargo_edit['id_cargo']; } 
                else { $mensaje_error_edicion = "<div class='alert alert-danger'>Edición: El cargo seleccionado '" . htmlspecialchars($nombre_cargo_seleccionado_edit) . "' no es válido.</div>"; }
                $stmt_get_cargo_edit->close();
            } else { $mensaje_error_edicion = "<div class='alert alert-danger'>Edición: Error al verificar el cargo.</div>"; }
        } elseif (empty($nombre_cargo_seleccionado_edit) && empty($mensaje_error_edicion)) { // Cargo es obligatorio
             $mensaje_error_edicion = "<div class='alert alert-danger'>Edición: El campo Cargo es obligatorio.</div>";
        }


        $params_sql = []; $types_sql = ""; $sql_set_parts = [];
        $sql_set_parts[] = "usuario = ?"; $params_sql[] = $edit_usuario_login; $types_sql .= "s";
        $sql_set_parts[] = "nombre_completo = ?"; $params_sql[] = $edit_nombre_completo; $types_sql .= "s";
        $sql_set_parts[] = "id_cargo = ?"; $params_sql[] = $id_cargo_editado; $types_sql .= "i";
        $sql_set_parts[] = "empresa = ?"; $params_sql[] = $edit_empresa; $types_sql .= "s";
        $sql_set_parts[] = "regional = ?"; $params_sql[] = $edit_regional; $types_sql .= "s";
        $sql_set_parts[] = "rol = ?"; $params_sql[] = $edit_rol; $types_sql .= "s";
        $sql_set_parts[] = "activo = ?"; $params_sql[] = $edit_activo; $types_sql .= "i";

        if ($cambiar_clave_check && empty($mensaje_error_edicion)) {
            $edit_clave = $_POST['edit_clave'] ?? ''; $edit_confirmar_clave = $_POST['edit_confirmar_clave'] ?? '';
            if (empty($edit_clave)) { $mensaje_error_edicion = "<div class='alert alert-danger'>Edición: Si elige cambiar la contraseña, la nueva contraseña no puede estar vacía.</div>"; } 
            elseif ($edit_clave !== $edit_confirmar_clave) { $mensaje_error_edicion = "<div class='alert alert-danger'>Edición: Las nuevas contraseñas no coinciden.</div>"; } 
            else { $clave_hasheada_edit = password_hash($edit_clave, PASSWORD_DEFAULT); $sql_set_parts[] = "clave = ?"; $params_sql[] = $clave_hasheada_edit; $types_sql .= "s"; }
        }
        
        if (empty($mensaje_error_edicion)) {
            $params_sql[] = $id_usuario_editar; $types_sql .= "i";
            $sql_update = "UPDATE usuarios SET " . implode(", ", $sql_set_parts) . " WHERE id = ?";
            $stmt_update = $conexion->prepare($sql_update);
            if ($stmt_update) {
                $stmt_update->bind_param($types_sql, ...$params_sql);
                if ($stmt_update->execute()) {
                    if ($stmt_update->affected_rows > 0) { $_SESSION['mensaje_accion_usuarios'] = "<div class='alert alert-success'>Usuario '" . htmlspecialchars($edit_nombre_completo) . "' actualizado exitosamente.</div>"; } 
                    else { $_SESSION['mensaje_accion_usuarios'] = "<div class='alert alert-info'>No se realizaron cambios detectables en el usuario.</div>"; }
                    header("Location: gestionar_usuarios.php"); exit;
                } else { $mensaje_error_edicion = "<div class='alert alert-danger'>Error al actualizar el usuario: " . $stmt_update->error . "</div>"; error_log("GESTIONAR_USUARIOS: Error al ACTUALIZAR usuario ID {$id_usuario_editar}: " . $stmt_update->error); }
                $stmt_update->close();
            } else { $mensaje_error_edicion = "<div class='alert alert-danger'>Error al preparar la actualización del usuario: ". $conexion->error ."</div>"; error_log("GESTIONAR_USUARIOS: Error al PREPARAR update usuario ID {$id_usuario_editar}: " . $conexion->error); }
        }

        if (!empty($mensaje_error_edicion)) {
            $_SESSION['mensaje_accion_usuarios'] = $mensaje_error_edicion;
            header("Location: gestionar_usuarios.php?accion=editar&id=" . $id_usuario_editar); exit;
        }
    } 
    // ------ ELIMINAR USUARIO ------
    elseif (isset($_POST['eliminar_usuario_submit']) && isset($_POST['id_usuario_a_eliminar'])) {
        if ($rol_usuario_actual_sesion !== 'admin') { 
            $_SESSION['mensaje_accion_usuarios'] = "<div class='alert alert-danger'>Acción no permitida.</div>";
            header("Location: gestionar_usuarios.php"); exit;
        }
        $id_usuario_eliminar = filter_input(INPUT_POST, 'id_usuario_a_eliminar', FILTER_VALIDATE_INT);
        if ($id_usuario_eliminar) {
            $nombre_usuario_a_eliminar = '';
            $stmt_check_info = $conexion->prepare("SELECT usuario FROM usuarios WHERE id = ?");
            if ($stmt_check_info) {
                $stmt_check_info->bind_param("i", $id_usuario_eliminar); $stmt_check_info->execute();
                $res_info = $stmt_check_info->get_result();
                if($row_info = $res_info->fetch_assoc()){ $nombre_usuario_a_eliminar = $row_info['usuario']; }
                $stmt_check_info->close();
            }
            $es_admin_a_eliminar = ($id_usuario_eliminar == 1 && strtolower($nombre_usuario_a_eliminar) === 'admin');
            $es_uno_mismo_a_eliminar = ($id_usuario_actual_sesion !== null && $id_usuario_eliminar == $id_usuario_actual_sesion);
            if ($es_admin_a_eliminar || $es_uno_mismo_a_eliminar) {
                $_SESSION['mensaje_accion_usuarios'] = "<div class='alert alert-danger'>No se puede eliminar al administrador principal o a sí mismo.</div>";
            } else {
                $stmt_check_activos = $conexion->prepare("SELECT COUNT(*) as total_activos FROM activos_tecnologicos WHERE id_usuario_responsable = ? AND estado != 'Dado de Baja'");
                $stmt_check_activos->bind_param("i", $id_usuario_eliminar); $stmt_check_activos->execute();
                $res_activos = $stmt_check_activos->get_result()->fetch_assoc(); $stmt_check_activos->close();
                if ($res_activos['total_activos'] > 0) {
                    $_SESSION['mensaje_accion_usuarios'] = "<div class='alert alert-warning'>No se puede eliminar el usuario porque tiene " . $res_activos['total_activos'] . " activo(s) asignado(s). Reasigne estos activos primero.</div>";
                } else {
                    $stmt_delete = $conexion->prepare("DELETE FROM usuarios WHERE id = ?");
                    if ($stmt_delete) {
                        $stmt_delete->bind_param("i", $id_usuario_eliminar);
                        if ($stmt_delete->execute()) { 
                            if ($stmt_delete->affected_rows > 0) { $_SESSION['mensaje_accion_usuarios'] = "<div class='alert alert-success'>Usuario eliminado exitosamente.</div>"; } 
                            else { $_SESSION['mensaje_accion_usuarios'] = "<div class='alert alert-warning'>No se encontró el usuario para eliminar.</div>"; }
                        } else { $_SESSION['mensaje_accion_usuarios'] = "<div class='alert alert-danger'>Error al eliminar el usuario: " . $stmt_delete->error . "</div>"; error_log("GESTIONAR_USUARIOS: Error ELIMINAR ID {$id_usuario_eliminar}: " . $stmt_delete->error); }
                        $stmt_delete->close();
                    } else { $_SESSION['mensaje_accion_usuarios'] = "<div class='alert alert-danger'>Error al preparar la eliminación.</div>"; error_log("GESTIONAR_USUARIOS: Error PREPARAR delete ID {$id_usuario_eliminar}: " . $conexion->error); }
                }
            }
        } else { $_SESSION['mensaje_accion_usuarios'] = "<div class='alert alert-danger'>ID de usuario inválido para eliminar.</div>"; }
        header("Location: gestionar_usuarios.php"); exit;
    }
}

// --- LÓGICA GET (CARGAR DATOS PARA EDITAR, ACTIVAR/DESACTIVAR) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['accion']) && isset($_GET['id'])) {
    $accion_get = $_GET['accion'];
    $id_usuario_get = (int)$_GET['id'];
    if ($id_usuario_get > 0) {
        if ($accion_get === 'editar') {
            $stmt_edit = $conexion->prepare("SELECT u.id, u.usuario, u.nombre_completo, u.id_cargo, c.nombre_cargo, u.empresa, u.regional, u.rol, u.activo
                                             FROM usuarios u LEFT JOIN cargos c ON u.id_cargo = c.id_cargo WHERE u.id = ?");
            if ($stmt_edit) {
                $stmt_edit->bind_param("i", $id_usuario_get); $stmt_edit->execute();
                $result_edit_get = $stmt_edit->get_result(); // Renombrado para evitar conflicto
                if ($result_edit_get->num_rows === 1) { $usuario_para_editar = $result_edit_get->fetch_assoc(); } 
                else { $_SESSION['mensaje_accion_usuarios'] = "<div class='alert alert-warning'>Usuario no encontrado para editar (ID: {$id_usuario_get}).</div>"; header("Location: gestionar_usuarios.php"); exit; }
                $stmt_edit->close();
            } else { $_SESSION['mensaje_accion_usuarios'] = "<div class='alert alert-danger'>Error al preparar datos para edición.</div>"; error_log("GESTIONAR_USUARIOS: Error PREPARAR edit ID {$id_usuario_get}: " . $conexion->error); header("Location: gestionar_usuarios.php"); exit;}
        } elseif ($accion_get === 'activar' || $accion_get === 'desactivar') {
            $usuario_info_toggle = null;
            $stmt_get_user_toggle = $conexion->prepare("SELECT usuario FROM usuarios WHERE id = ?");
            if($stmt_get_user_toggle){
                $stmt_get_user_toggle->bind_param("i", $id_usuario_get); $stmt_get_user_toggle->execute();
                $res_user_toggle = $stmt_get_user_toggle->get_result();
                $usuario_info_toggle = $res_user_toggle->fetch_assoc(); $stmt_get_user_toggle->close();
            }
            $es_admin_a_cambiar = ($id_usuario_get == 1 && isset($usuario_info_toggle['usuario']) && strtolower($usuario_info_toggle['usuario']) === 'admin');
            $es_uno_mismo_a_cambiar = ($id_usuario_actual_sesion !== null && $id_usuario_get == $id_usuario_actual_sesion);
            if ($es_admin_a_cambiar || $es_uno_mismo_a_cambiar) { $_SESSION['mensaje_accion_usuarios'] = "<div class='alert alert-danger'>No se puede cambiar el estado del administrador principal o de uno mismo.</div>"; } 
            else { 
                $nuevo_estado = ($accion_get === 'activar') ? 1 : 0; $texto_accion = ($nuevo_estado === 1) ? 'activado' : 'desactivado';
                $stmt_toggle = $conexion->prepare("UPDATE usuarios SET activo = ? WHERE id = ?");
                $stmt_toggle->bind_param("ii", $nuevo_estado, $id_usuario_get);
                if ($stmt_toggle->execute()) { 
                    if ($stmt_toggle->affected_rows > 0) { $_SESSION['mensaje_accion_usuarios'] = "<div class='alert alert-info'>Usuario ha sido $texto_accion.</div>"; } 
                    else { $_SESSION['mensaje_accion_usuarios'] = "<div class='alert alert-warning'>No se pudo cambiar el estado (quizás ya estaba así).</div>"; }
                } else { $_SESSION['mensaje_accion_usuarios'] = "<div class='alert alert-danger'>Error al cambiar estado del usuario.</div>"; }
                $stmt_toggle->close();
            }
            header("Location: gestionar_usuarios.php"); exit;
        }
    }
}

// --- OBTENER LISTA DE USUARIOS PARA LA TABLA ---
$usuarios_listados = [];
$sql_listar = "SELECT u.id, u.usuario, u.nombre_completo, c.nombre_cargo, u.empresa, u.regional, u.rol, u.activo
               FROM usuarios u LEFT JOIN cargos c ON u.id_cargo = c.id_cargo ORDER BY u.nombre_completo ASC";
$result_listar_table = $conexion->query($sql_listar); // Renombrado para claridad
if ($result_listar_table) {
    while ($row_listar_table = $result_listar_table->fetch_assoc()) { $usuarios_listados[] = $row_listar_table; }
} else {
    $db_error_info = $conexion->error ?? 'Error desconocido en consulta de listado.';
    error_log("GESTIONAR_USUARIOS: Error al listar usuarios: " . $db_error_info . " SQL: " . $sql_listar);
    $mensaje_accion = "<div class='alert alert-danger'>Error crítico al cargar la lista de usuarios. (" . htmlspecialchars($db_error_info) . "). Por favor, contacte al administrador.</div>";
}

// Determinar si el modal de creación debe abrirse automáticamente
$abrir_modal_creacion_js = false;
if (isset($_GET['error_creacion']) && $_GET['error_creacion'] == '1' && !empty($mensaje_accion)) {
    $palabras_clave_creacion = ["Creación:", "ya existe", "cargo seleccionado", "obligatorios", "contraseñas no coinciden", "no es válido"];
    if (is_string($mensaje_accion)) { // Asegurarse que $mensaje_accion sea string
        $mensaje_lower = strtolower($mensaje_accion);
        foreach ($palabras_clave_creacion as $palabra) {
            if (strpos($mensaje_lower, strtolower($palabra)) !== false) {
                $abrir_modal_creacion_js = true;
                break;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gestión de Usuarios</title>
    <link rel="icon" type="image/x-icon" href="imagenes/icono.ico">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            padding-top: 110px; 
            background-color: #eef2f5; 
            font-size: 0.92rem; 
        }
        .top-bar-custom { 
            position: fixed; top: 0; left: 0; right: 0; z-index: 1030; 
            display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; 
            padding: 0.5rem 1.5rem; background-color: #ffffff; 
            border-bottom: 1px solid #dee2e6; box-shadow: 0 1px 3px rgba(0,0,0,0.05); 
        }
        .logo-container-top img { height: 75px; width: auto; }
        .top-bar-user-info .navbar-text { font-size: 0.8rem; }
        .top-bar-user-info .btn { font-size: 0.8rem; }

        .page-title-area {
            margin-bottom: 1.5rem; 
        }
        h1.page-title { /* Aplicar directamente a h1 con la clase */
            color: #0d6efd; /* Azul primario de Bootstrap */
            font-weight: 600; 
            font-size: 1.75rem; 
        }
        .card { border: none; box-shadow: 0 0 10px rgba(0,0,0,0.06); }
        .card-header { background-color: #f8f9fa; border-bottom: 1px solid #dee2e6; font-weight: 500; color: #495057; font-size: 1.05rem; }
        .table thead th { background-color: #4A5568; color: white; font-weight: 500; vertical-align: middle; font-size: 0.85rem; padding: 0.6rem 0.75rem; }
        .table tbody td { vertical-align: middle; font-size: 0.85rem; padding: 0.6rem 0.75rem; }
        .form-label { font-weight: 500; color: #495057; font-size: 0.85rem; }
        .container.mt-4 {max-width: 1320px;}
        .action-icon { font-size: 1rem; text-decoration: none; margin-right: 0.3rem; } /* Para botones de acciones en tabla */


        @media (max-width: 575.98px) { /* xs screens */
            body { padding-top: 150px; } 
            .top-bar-custom { flex-direction: column; padding: 0.75rem 1rem; }
            .logo-container-top { margin-bottom: 0.5rem; text-align: center; width: 100%; }
            .top-bar-user-info { display: flex; flex-direction: column; align-items: center; width: 100%; text-align: center; }
            .top-bar-user-info .navbar-text { margin-right: 0; margin-bottom: 0.5rem; }
            
            h1.page-title { font-size: 1.5rem !important; } 
            .page-title-area .text-sm-end { text-align: center !important; } 
        }
    </style>
</head>
<body>
<div class="top-bar-custom">
    <div class="logo-container-top"><a href="menu.php"><img src="imagenes/logo.png" alt="Logo"></a></div>
    <div class="top-bar-user-info">
        <span class="navbar-text me-sm-3"><i class="bi bi-person-circle"></i> <?= htmlspecialchars($nombre_usuario_actual_sesion) ?> (<?= htmlspecialchars(ucfirst($rol_usuario_actual_sesion)) ?>)</span>
        <a href="logout.php" class="btn btn-outline-danger btn-sm"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</a>
    </div>
</div>

<div class="container mt-4">
    <div class="page-title-area mb-3">
        <div class="d-flex flex-column flex-sm-row justify-content-sm-between align-items-center">
            <div class="mb-2 mb-sm-0">
                <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#modalCrearUsuario">
                    <i class="bi bi-plus-circle"></i> Crear Nuevo Usuario
                </button>
            </div>
            <h1 class="page-title text-center text-sm-center text-md-center text-lg-center text-xl-center">
                <i class="bi bi-people-fill"></i> Gestión de Usuarios
            </h1>
            <div>
                <a href="centro_gestion.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left-circle"></i> Volver al Centro de Gestión
                </a>
            </div>
        </div>
    </div>

    <?php if ($mensaje_accion && is_string($mensaje_accion)) echo "<div class='mb-3'>{$mensaje_accion}</div>"; ?>

    <div class="card">
        <div class="card-header"><i class="bi bi-list-ul"></i> Usuarios Registrados</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover table-sm mb-0">
                    <thead>
                        <tr>
                            <th>Usuario (C.C)</th> <th>Nombre Completo</th> <th>Cargo</th> <th>Empresa</th>
                            <th>Regional</th> <th>Rol</th> <th>Estado</th> <th style="min-width: 130px;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($usuarios_listados)): ?>
                            <?php foreach ($usuarios_listados as $usuario_fila): ?>
                                <tr>
                                    <td><?= htmlspecialchars($usuario_fila['usuario']) ?></td>
                                    <td><?= htmlspecialchars($usuario_fila['nombre_completo']) ?></td>
                                    <td><?= htmlspecialchars($usuario_fila['nombre_cargo'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($usuario_fila['empresa'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($usuario_fila['regional'] ?? 'N/A') ?></td>
                                    <td><span class="<?= getRolBadgeClass($usuario_fila['rol']) ?>"><?= htmlspecialchars(ucfirst($usuario_fila['rol'])) ?></span></td>
                                    <td>
                                        <?php if ($usuario_fila['activo']): ?>
                                            <span class="badge rounded-pill bg-success">Activo</span>
                                        <?php else: ?>
                                            <span class="badge rounded-pill bg-danger">Inactivo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="gestionar_usuarios.php?accion=editar&id=<?= $usuario_fila['id'] ?>" class="btn btn-sm btn-outline-warning action-icon" title="Editar"><i class="bi bi-pencil-square"></i></a>
                                        <?php 
                                        $es_usuario_protegido = (($usuario_fila['id'] == 1 && strtolower($usuario_fila['usuario']) === 'admin') || ($id_usuario_actual_sesion !== null && $usuario_fila['id'] == $id_usuario_actual_sesion));
                                        if ($rol_usuario_actual_sesion === 'admin' && !$es_usuario_protegido): ?>
                                            <form method="POST" action="gestionar_usuarios.php" style="display: inline;" onsubmit="return confirm('ADVERTENCIA:\n¿Está REALMENTE seguro de eliminar al usuario \'<?= htmlspecialchars(addslashes($usuario_fila['nombre_completo'])) ?>\' (<?= htmlspecialchars(addslashes($usuario_fila['usuario'])) ?>)?\n\nEsta acción NO SE PUEDE DESHACER.');">
                                                <input type="hidden" name="id_usuario_a_eliminar" value="<?= $usuario_fila['id'] ?>">
                                                <button type="submit" name="eliminar_usuario_submit" class="btn btn-sm btn-outline-danger action-icon" title="Eliminar Usuario"><i class="bi bi-person-x-fill"></i></button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if (!$es_usuario_protegido): ?>
                                            <?php if ($usuario_fila['activo']): ?>
                                                <a href="gestionar_usuarios.php?accion=desactivar&id=<?= $usuario_fila['id'] ?>" class="btn btn-sm btn-outline-secondary action-icon" title="Desactivar" onclick="return confirm('¿Está seguro de desactivar a este usuario?');"><i class="bi bi-person-fill-slash"></i></a>
                                            <?php else: ?>
                                                <a href="gestionar_usuarios.php?accion=activar&id=<?= $usuario_fila['id'] ?>" class="btn btn-sm btn-outline-success action-icon" title="Activar" onclick="return confirm('¿Está seguro de activar a este usuario?');"><i class="bi bi-person-fill-check"></i></a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8" class="text-center p-4">No hay usuarios registrados o hubo un error al cargarlos.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalCrearUsuario" tabindex="-1" aria-labelledby="modalCrearUsuarioLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" action="gestionar_usuarios.php" id="formCrearUsuarioModal">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalCrearUsuarioLabel"><i class="bi bi-person-plus-fill"></i> Crear Nuevo Usuario</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row gx-3">
                        <div class="col-md-6 mb-3">
                            <label for="nuevo_usuario_login_modal" class="form-label">Usuario (Cédula) <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="nuevo_usuario_login_modal" name="nuevo_usuario_login" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="nuevo_nombre_completo_modal" class="form-label">Nombre Completo <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="nuevo_nombre_completo_modal" name="nuevo_nombre_completo" required>
                        </div>
                    </div>
                    <div class="row gx-3">
                        <div class="col-md-6 mb-3">
                            <label for="nueva_clave_modal" class="form-label">Contraseña <span class="text-danger">*</span></label>
                            <input type="password" class="form-control form-control-sm" id="nueva_clave_modal" name="nueva_clave" required autocomplete="new-password">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="confirmar_nueva_clave_modal" class="form-label">Confirmar Contraseña <span class="text-danger">*</span></label>
                            <input type="password" class="form-control form-control-sm" id="confirmar_nueva_clave_modal" name="confirmar_nueva_clave" required autocomplete="new-password">
                        </div>
                    </div>
                    <div class="row gx-3">
                        <div class="col-md-6 mb-3">
                            <label for="nuevo_cargo_modal" class="form-label">Cargo <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" id="nuevo_cargo_modal" name="nuevo_cargo" required>
                                <option value="">Seleccione un cargo...</option>
                                <?php foreach($cargos_form as $cargo_nombre_opt): ?>
                                    <option value="<?= htmlspecialchars($cargo_nombre_opt) ?>"><?= htmlspecialchars($cargo_nombre_opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="nuevo_rol_modal" class="form-label">Rol <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" id="nuevo_rol_modal" name="nuevo_rol" required>
                                <option value="">Seleccione un rol...</option>
                                <?php foreach($roles_form as $rol_opt): ?>
                                    <option value="<?= htmlspecialchars($rol_opt) ?>"><?= htmlspecialchars(ucfirst($rol_opt)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row gx-3">
                        <div class="col-md-6 mb-3">
                            <label for="nueva_empresa_modal" class="form-label">Empresa</label>
                            <select class="form-select form-select-sm" id="nueva_empresa_modal" name="nueva_empresa">
                                <option value="">Seleccione una empresa...</option>
                                 <?php foreach($empresas_form as $empresa_opt): ?>
                                    <option value="<?= htmlspecialchars($empresa_opt) ?>"><?= htmlspecialchars($empresa_opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="nueva_regional_modal" class="form-label">Regional</label>
                            <select class="form-select form-select-sm" id="nueva_regional_modal" name="nueva_regional">
                                <option value="">Seleccione una regional...</option>
                                <?php foreach($regionales_form as $regional_opt): ?>
                                    <option value="<?= htmlspecialchars($regional_opt) ?>"><?= htmlspecialchars($regional_opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-check form-switch mb-3"> 
                        <input class="form-check-input" type="checkbox" role="switch" id="nuevo_activo_modal" name="nuevo_activo" value="1" checked>
                        <label class="form-check-label" for="nuevo_activo_modal">Usuario Activo</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" name="crear_usuario_submit" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle"></i> Crear Usuario</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($usuario_para_editar): ?>
<div class="modal fade" id="modalEditarUsuario" tabindex="-1" aria-labelledby="modalEditarUsuarioLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" action="gestionar_usuarios.php" id="formEditarUsuario">
                <input type="hidden" name="id_usuario_editar" value="<?= htmlspecialchars($usuario_para_editar['id']) ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalEditarUsuarioLabel">Editar Usuario: <?= htmlspecialchars($usuario_para_editar['nombre_completo']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                     <div class="row gx-3">
                        <div class="col-md-6 mb-3">
                            <label for="edit_usuario_login" class="form-label">Usuario (Cédula) <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="edit_usuario_login" name="edit_usuario_login" value="<?= htmlspecialchars($usuario_para_editar['usuario']) ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_nombre_completo" class="form-label">Nombre Completo <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="edit_nombre_completo" name="edit_nombre_completo" value="<?= htmlspecialchars($usuario_para_editar['nombre_completo']) ?>" required>
                        </div>
                    </div>
                    <div class="row gx-3">
                        <div class="col-md-6 mb-3">
                            <label for="edit_cargo" class="form-label">Cargo <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" id="edit_cargo" name="edit_cargo" required>
                                <option value="">Seleccione un cargo...</option>
                                <?php foreach($cargos_form as $cargo_opt_edit): ?>
                                    <option value="<?= htmlspecialchars($cargo_opt_edit) ?>" <?= (($usuario_para_editar['nombre_cargo'] ?? '') == $cargo_opt_edit) ? 'selected' : '' ?>><?= htmlspecialchars($cargo_opt_edit) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_rol" class="form-label">Rol <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" id="edit_rol" name="edit_rol" required>
                                 <option value="">Seleccione un rol...</option>
                                <?php foreach($roles_form as $rol_opt_edit): ?>
                                    <option value="<?= htmlspecialchars($rol_opt_edit) ?>" <?= (($usuario_para_editar['rol'] ?? '') == $rol_opt_edit) ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($rol_opt_edit)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row gx-3">
                        <div class="col-md-6 mb-3">
                            <label for="edit_empresa" class="form-label">Empresa</label>
                            <select class="form-select form-select-sm" id="edit_empresa" name="edit_empresa">
                                <option value="">Seleccione una empresa...</option>
                                <?php foreach($empresas_form as $empresa_opt_edit): ?>
                                    <option value="<?= htmlspecialchars($empresa_opt_edit) ?>" <?= (($usuario_para_editar['empresa'] ?? '') == $empresa_opt_edit) ? 'selected' : '' ?>><?= htmlspecialchars($empresa_opt_edit) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_regional" class="form-label">Regional</label>
                            <select class="form-select form-select-sm" id="edit_regional" name="edit_regional">
                                 <option value="">Seleccione una regional...</option>
                                <?php foreach($regionales_form as $regional_opt_edit): ?>
                                    <option value="<?= htmlspecialchars($regional_opt_edit) ?>" <?= (($usuario_para_editar['regional'] ?? '') == $regional_opt_edit) ? 'selected' : '' ?>><?= htmlspecialchars($regional_opt_edit) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="edit_activo" name="edit_activo" value="1" <?= ($usuario_para_editar['activo'] ?? 0) == 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="edit_activo">Usuario Activo</label>
                    </div>
                    <hr>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" role="switch" id="edit_cambiar_clave_check" name="edit_cambiar_clave_check">
                        <label class="form-check-label" for="edit_cambiar_clave_check">Cambiar Contraseña</label>
                    </div>
                    <div id="edit_password_fields_container" style="display: none;">
                        <p class="text-muted small">Ingrese la nueva contraseña solo si desea cambiarla.</p>
                        <div class="row gx-3">
                            <div class="col-md-6 mb-3">
                                <label for="edit_clave" class="form-label">Nueva Contraseña</label>
                                <input type="password" class="form-control form-control-sm" id="edit_clave" name="edit_clave" autocomplete="new-password">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="edit_confirmar_clave" class="form-label">Confirmar Nueva Contraseña</label>
                                <input type="password" class="form-control form-control-sm" id="edit_confirmar_clave" name="edit_confirmar_clave" autocomplete="new-password">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" name="editar_usuario_submit" class="btn btn-primary btn-sm"><i class="bi bi-save"></i> Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (isset($conexion)) { $conexion->close(); } ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const currentUrl = new URL(window.location);
    if (currentUrl.searchParams.has('error_creacion')) {
        currentUrl.searchParams.delete('error_creacion');
        window.history.replaceState({}, document.title, currentUrl.toString());
    }

    <?php if ($abrir_modal_creacion_js): ?>
    const modalCrearUsuarioEl = document.getElementById('modalCrearUsuario');
    if (modalCrearUsuarioEl) {
        const modalCrear = new bootstrap.Modal(modalCrearUsuarioEl);
        modalCrear.show();
    }
    <?php endif; ?>

    <?php if ($usuario_para_editar): ?>
    const modalEditarUsuarioEl = document.getElementById('modalEditarUsuario');
    if (modalEditarUsuarioEl) {
        const modalEditar = new bootstrap.Modal(modalEditarUsuarioEl);
        modalEditar.show(); 

        const cambiarClaveCheck = document.getElementById('edit_cambiar_clave_check');
        const passwordFieldsContainer = document.getElementById('edit_password_fields_container');
        const editClaveInput = document.getElementById('edit_clave');
        const editConfirmarClaveInput = document.getElementById('edit_confirmar_clave');

        if (cambiarClaveCheck && passwordFieldsContainer && editClaveInput && editConfirmarClaveInput) {
            const togglePasswordFields = () => {
                if (cambiarClaveCheck.checked) {
                    passwordFieldsContainer.style.display = 'block';
                    editClaveInput.required = true;
                    editConfirmarClaveInput.required = true;
                } else {
                    passwordFieldsContainer.style.display = 'none';
                    editClaveInput.required = false;
                    editConfirmarClaveInput.required = false;
                    editClaveInput.value = ''; 
                    editConfirmarClaveInput.value = '';
                }
            };
            cambiarClaveCheck.addEventListener('change', togglePasswordFields);
            togglePasswordFields(); 
        }
    }
    <?php endif; ?>

    const formCrearModal = document.getElementById('formCrearUsuarioModal');
    if(formCrearModal) {
        formCrearModal.addEventListener('submit', function(event) {
            const nuevaClaveModal = document.getElementById('nueva_clave_modal').value;
            const confirmarNuevaClaveModal = document.getElementById('confirmar_nueva_clave_modal').value;
            if (nuevaClaveModal !== confirmarNuevaClaveModal) {
                alert('Creación: Las contraseñas no coinciden.');
                event.preventDefault(); 
            }
        });
    }

    const formEditar = document.getElementById('formEditarUsuario');
    if(formEditar) {
        formEditar.addEventListener('submit', function(event) {
            const cambiarClaveCheck = document.getElementById('edit_cambiar_clave_check');
            if (cambiarClaveCheck && cambiarClaveCheck.checked) { 
                const editClave = document.getElementById('edit_clave').value;
                const editConfirmarClave = document.getElementById('edit_confirmar_clave').value;
                if (editClave === "") { 
                    alert('Edición: La nueva contraseña no puede estar vacía si ha elegido cambiarla.');
                    event.preventDefault(); return;
                }
                if (editClave !== editConfirmarClave) {
                    alert('Edición: Las nuevas contraseñas no coinciden.');
                    event.preventDefault(); 
                }
            }
        });
    }
});
</script>
</body>
</html>