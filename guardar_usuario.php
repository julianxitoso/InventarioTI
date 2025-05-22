<?php
session_start();
// require_once 'backend/auth_check.php'; // Se quitó para auto-registro público

require_once 'backend/db.php';

if (isset($conn) && !isset($conexion)) { $conexion = $conn; }
if (!isset($conexion) || !$conexion) {
    $_SESSION['error_creacion_usuario'] = "Error crítico: No se pudo establecer la conexión a la base de datos.";
    header('Location: registrar_usuario.php'); 
    exit;
}
$conexion->set_charset("utf8mb4");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $cedula_ingresada_form = trim($_POST['cedula'] ?? ''); 
    $nombre_completo = trim($_POST['nombre_completo'] ?? '');
    $contrasena = $_POST['contrasena'] ?? '';
    $confirmar_contrasena = $_POST['confirmar_contrasena'] ?? '';
    $cargo = trim($_POST['cargo'] ?? '');
    $empresa_usuario = trim($_POST['empresa_usuario'] ?? '');
    $regional_usuario = trim($_POST['regional_usuario'] ?? '');
    
    $rol_usuario = 'registrador'; 

    // Validaciones (sin cambios)
    if (empty($cedula_ingresada_form) || empty($nombre_completo) || empty($contrasena) || empty($cargo) || empty($empresa_usuario) || empty($regional_usuario)) {
        $_SESSION['error_creacion_usuario'] = "Todos los campos marcados con * son obligatorios.";
        header('Location: registrar_usuario.php');
        exit;
    }
    if (!ctype_digit($cedula_ingresada_form)) {
        $_SESSION['error_creacion_usuario'] = "La cédula solo debe contener números.";
        header('Location: registrar_usuario.php');
        exit;
    }
    if ($contrasena !== $confirmar_contrasena) {
        $_SESSION['error_creacion_usuario'] = "Las contraseñas no coinciden.";
        header('Location: registrar_usuario.php');
        exit;
    }
     if (strlen($contrasena) < 6) { 
        $_SESSION['error_creacion_usuario'] = "La contraseña debe tener al menos 6 caracteres.";
        header('Location: registrar_usuario.php');
        exit;
    }

    // Verificar si el usuario (cédula) ya existe en la columna 'usuario'
    $stmt_check = $conexion->prepare("SELECT id FROM usuarios WHERE usuario = ?");
    if(!$stmt_check){
        error_log("Error preparando consulta de verificación de usuario: " . $conexion->error);
        $_SESSION['error_creacion_usuario'] = "Error del sistema al verificar el usuario. Intente más tarde.";
        header('Location: registrar_usuario.php');
        exit;
    }
    $stmt_check->bind_param("s", $cedula_ingresada_form);
    $stmt_check->execute();
    $stmt_check->store_result();
    if ($stmt_check->num_rows > 0) {
        $_SESSION['error_creacion_usuario'] = "La cédula ingresada ya está registrada como usuario. Intenta iniciar sesión.";
        $stmt_check->close();
        header('Location: registrar_usuario.php');
        exit;
    }
    $stmt_check->close();

    // La variable $password_hash_para_guardar contiene la contraseña hasheada
    $password_hash_para_guardar = password_hash($contrasena, PASSWORD_DEFAULT); 
    if ($password_hash_para_guardar === false) {
        error_log("Error al generar el hash de la contraseña.");
        $_SESSION['error_creacion_usuario'] = "Error crítico de seguridad al procesar la contraseña. Contacte al administrador.";
        header('Location: registrar_usuario.php');
        exit;
    }

    // ----- AQUÍ LA CORRECCIÓN PRINCIPAL -----
    // Usar 'clave' como nombre de columna en la consulta INSERT
    $sql = "INSERT INTO usuarios (usuario, clave, nombre_completo, cargo, empresa, regional, rol, activo) 
            VALUES (?, ?, ?, ?, ?, ?, ?, TRUE)"; 
    // ----- FIN CORRECCIÓN PRINCIPAL -----
    
    $stmt_insert = $conexion->prepare($sql);
    if(!$stmt_insert){
        error_log("Error preparando consulta de inserción de usuario: " . $conexion->error . " SQL: " . $sql);
        $_SESSION['error_creacion_usuario'] = "Error del sistema al crear el usuario. Intente más tarde.";
        header('Location: registrar_usuario.php');
        exit;
    }

    // El orden de las variables en bind_param coincide con los '?' en el SQL
    // La variable $password_hash_para_guardar se insertará en la columna 'clave'
    $stmt_insert->bind_param("sssssss", 
        $cedula_ingresada_form,         // para la columna 'usuario'
        $password_hash_para_guardar,    // para la columna 'clave'
        $nombre_completo, 
        $cargo, 
        $empresa_usuario, 
        $regional_usuario, 
        $rol_usuario
    );

    if ($stmt_insert->execute()) {
        $_SESSION['mensaje_creacion_usuario'] = "¡Registro exitoso! Tu cuenta como 'Registrador' ha sido creada para la cédula ".htmlspecialchars($cedula_ingresada_form).". Ahora puedes iniciar sesión.";
    } else {
        error_log("Error al ejecutar inserción de usuario: " . $stmt_insert->error);
        $_SESSION['error_creacion_usuario'] = "Error al guardar el usuario: " . $stmt_insert->error;
    }
    $stmt_insert->close();
    $conexion->close();

    header('Location: registrar_usuario.php'); 
    exit;

} else {
    $_SESSION['error_creacion_usuario'] = "Acceso no permitido por método incorrecto.";
    header('Location: registrar_usuario.php');
    exit;
}
?>