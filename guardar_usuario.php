<?php
session_start();
// Se necesitan ambos para verificar la sesión y conectar a la BD
require_once 'backend/auth_check.php'; 
require_once 'backend/db.php';

// Redirigir si no es una petición POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php'); 
    exit;
}

if (isset($conn) && !isset($conexion)) {
    $conexion = $conn;
}

// --- 1. CAPTURAR DATOS DEL FORMULARIO ---
$cedula = trim($_POST['cedula'] ?? '');
$nombre_completo = trim($_POST['nombre_completo'] ?? '');
$contrasena = $_POST['contrasena'] ?? '';
$confirmar_contrasena = $_POST['confirmar_contrasena'] ?? '';
$cargo = trim($_POST['cargo'] ?? '');
$empresa = $_POST['empresa_usuario'] ?? '';
$regional = $_POST['regional_usuario'] ?? '';
$rol_desde_formulario = $_POST['rol_usuario'] ?? 'registrador'; // Rol enviado desde el form

// --- 2. VALIDACIONES ---
// (Usaremos una variable de sesión genérica para los errores para que funcione en ambas páginas)
if (empty($cedula) || empty($nombre_completo) || empty($contrasena) || empty($cargo) || empty($empresa) || empty($regional)) {
    $_SESSION['error_form_usuario'] = "Todos los campos marcados con * son obligatorios.";
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'registro.php'));
    exit;
}
if ($contrasena !== $confirmar_contrasena) {
    $_SESSION['error_form_usuario'] = "Las contraseñas no coinciden.";
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'registro.php'));
    exit;
}
if (strlen($contrasena) < 6) { 
    $_SESSION['error_form_usuario'] = "La contraseña debe tener al menos 6 caracteres.";
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'registro.php'));
    exit;
}

// --- 3. LÓGICA DE ASIGNACIÓN DE ROL (La parte más importante) ---
$rol_final_asignado = 'registrador'; // Por defecto, el rol más bajo y seguro.

// Verificamos si hay un usuario logueado Y si ese usuario es 'admin'
if (isset($_SESSION['usuario_login']) && obtener_rol_usuario() === 'admin') {
    // Si es un admin, SÍ confiamos en el rol que viene del formulario.
    $rol_final_asignado = $rol_desde_formulario;
}
// Si no es un admin quien envía el formulario, el rol se queda como 'registrador',
// ignorando cualquier intento de enviar un rol diferente desde el formulario público.

// --- 4. PROCESAMIENTO EN BASE DE DATOS ---
// Verificar si el usuario ya existe
$stmt_check = $conexion->prepare("SELECT id FROM usuarios WHERE usuario = ?");
$stmt_check->bind_param('s', $cedula);
$stmt_check->execute();
$stmt_check->store_result();

if ($stmt_check->num_rows > 0) {
    $_SESSION['error_form_usuario'] = "El usuario (cédula) '" . htmlspecialchars($cedula) . "' ya existe. Intente iniciar sesión o use otra cédula.";
    $stmt_check->close();
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'registro.php'));
    exit;
}
$stmt_check->close();

// Insertar el nuevo usuario si no existe
$contrasena_hashed = password_hash($contrasena, PASSWORD_DEFAULT);
$sql_insert = "INSERT INTO usuarios (usuario, clave, nombre_completo, cargo, empresa, regional, rol, activo) VALUES (?, ?, ?, ?, ?, ?, ?, 1)";
$stmt_insert = $conexion->prepare($sql_insert);
$stmt_insert->bind_param('sssssss', $cedula, $contrasena_hashed, $nombre_completo, $cargo, $empresa, $regional, $rol_final_asignado);

// --- 5. REDIRECCIÓN INTELIGENTE ---
if ($stmt_insert->execute()) {
    // Si la creación fue exitosa, decidimos a dónde redirigir.
    if (obtener_rol_usuario() === 'admin') {
        // Si un admin lo creó, lo dejamos en la página de creación con un mensaje de éxito.
        $_SESSION['mensaje_creacion_usuario'] = "¡Usuario '" . htmlspecialchars($nombre_completo) . "' creado con el rol de '" . htmlspecialchars($rol_final_asignado) . "'!";
        header("Location: crear_usuario.php");
    } else {
        // Si fue un registro público, lo enviamos a la página de login con un mensaje de éxito.
        $_SESSION['mensaje_login'] = "¡Registro exitoso! Ya puedes iniciar sesión con tu cédula y contraseña.";
        header("Location: login.php");
    }
} else {
    // Si falló, lo devolvemos a la página anterior con un error.
    $_SESSION['error_form_usuario'] = "Error al guardar el usuario: " . $stmt_insert->error;
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'registro.php'));
}

$stmt_insert->close();
$conexion->close();
exit;
?>