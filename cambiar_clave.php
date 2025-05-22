<?php
// session_start(); // auth_check.php ya lo incluye y gestiona
require_once 'backend/auth_check.php';
// Solo usuarios logueados pueden acceder a cambiar su propia clave
// Puedes restringir a roles específicos si es necesario, pero usualmente cualquier usuario logueado puede cambiar SU clave.
// Para este caso, asumimos que el admin está logueado y quiere cambiar SU clave.
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

require_once 'backend/db.php';
if (isset($conn) && !isset($conexion)) { $conexion = $conn; }
if (!isset($conexion) || !$conexion) { die("Error de conexión a la base de datos."); }
$conexion->set_charset("utf8mb4");

$mensaje_cambio = "";
$error_cambio = "";

// Obtener datos del usuario de la sesión para la navbar y para la consulta
$nombre_usuario_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Usuario';
$cedula_usuario_sesion = $_SESSION['usuario_login'] ?? null; // Cédula del usuario logueado

if (!$cedula_usuario_sesion) {
    // Esto no debería pasar si el usuario está logueado y la sesión se configuró correctamente
    $error_cambio = "Error: No se pudo identificar al usuario actual. Intente iniciar sesión de nuevo.";
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && $cedula_usuario_sesion) {
    $contrasena_actual = $_POST['contrasena_actual'] ?? '';
    $nueva_contrasena = $_POST['nueva_contrasena'] ?? '';
    $confirmar_nueva_contrasena = $_POST['confirmar_nueva_contrasena'] ?? '';

    if (empty($contrasena_actual) || empty($nueva_contrasena) || empty($confirmar_nueva_contrasena)) {
        $error_cambio = "Todos los campos son obligatorios.";
    } elseif ($nueva_contrasena !== $confirmar_nueva_contrasena) {
        $error_cambio = "La nueva contraseña y su confirmación no coinciden.";
    } elseif (strlen($nueva_contrasena) < 6) { // Validación simple de longitud
        $error_cambio = "La nueva contraseña debe tener al menos 6 caracteres.";
    } else {
        // 1. Obtener el hash de la contraseña actual del usuario
        $sql_select = "SELECT password_hash FROM usuarios WHERE cedula = ?";
        if ($stmt_select = $conexion->prepare($sql_select)) {
            $stmt_select->bind_param("s", $cedula_usuario_sesion);
            $stmt_select->execute();
            $stmt_select->store_result();

            if ($stmt_select->num_rows == 1) {
                $stmt_select->bind_result($hash_actual_db);
                $stmt_select->fetch();

                // 2. Verificar la contraseña actual
                if (password_verify($contrasena_actual, $hash_actual_db)) {
                    // 3. Contraseña actual correcta, generar hash para la nueva
                    $nuevo_password_hash = password_hash($nueva_contrasena, PASSWORD_DEFAULT);

                    if ($nuevo_password_hash === false) {
                        $error_cambio = "Error crítico al procesar la nueva contraseña.";
                        error_log("Error en password_hash() para el usuario: " . $cedula_usuario_sesion);
                    } else {
                        // 4. Actualizar la contraseña en la base de datos
                        $sql_update = "UPDATE usuarios SET password_hash = ? WHERE cedula = ?";
                        if ($stmt_update = $conexion->prepare($sql_update)) {
                            $stmt_update->bind_param("ss", $nuevo_password_hash, $cedula_usuario_sesion);
                            if ($stmt_update->execute()) {
                                $mensaje_cambio = "¡Contraseña actualizada exitosamente!";
                                // Opcional: Forzar cierre de otras sesiones o actualizar la sesión actual si es necesario.
                                // Por simplicidad, solo mostramos mensaje. El usuario usará la nueva clave la próxima vez.
                            } else {
                                $error_cambio = "Error al actualizar la contraseña en la base de datos.";
                                error_log("Error en UPDATE de contraseña para ".$cedula_usuario_sesion.": " . $stmt_update->error);
                            }
                            $stmt_update->close();
                        } else {
                            $error_cambio = "Error al preparar la actualización de la contraseña.";
                            error_log("Error preparando UPDATE de contraseña: " . $conexion->error);
                        }
                    }
                } else {
                    $error_cambio = "La contraseña actual ingresada es incorrecta.";
                }
            } else {
                // Esto sería muy raro si el usuario está logueado
                $error_cambio = "No se encontró el usuario actual en la base de datos.";
            }
            $stmt_select->close();
        } else {
            $error_cambio = "Error al consultar la información del usuario.";
            error_log("Error preparando SELECT de contraseña: " . $conexion->error);
        }
    }
}
$conexion->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cambiar Contraseña</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .container-main { margin-top: 20px; margin-bottom: 40px; max-width: 600px; }
        h3.page-title { color: #333; font-weight: 600; margin-bottom: 25px; }
        .logo-container { text-align: center; margin-bottom: 5px; padding-top:10px; }
        .logo-container img { width: 180px; height: 70px; object-fit: contain; }
        .navbar-custom { background-color: #191970; }
        .navbar-custom .nav-link { color: white !important; font-weight: 500; padding: 0.5rem 1rem;}
        .navbar-custom .nav-link:hover, .navbar-custom .nav-link.active { background-color: #8b0000; color: white; }
        .card.form-card { box-shadow: 0 4px 12px rgba(0,0,0,0.08); border: none; }
        .form-label { font-weight: 500; color: #495057; }
    </style>
</head>
<body>

<div class="logo-container">
    <a href="menu.php"><img src="imagenes/logo3.png" alt="Logo"></a>
</div>

<nav class="navbar navbar-expand-lg navbar-custom">
    <div class="container-fluid">
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"><span class="navbar-toggler-icon" style="background-image: url(&quot;data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba(255,255,255,0.8)' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e&quot;);"></span></button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item"><a class="nav-link" href="menu.php">Inicio</a></li>
                <li class="nav-item"><a class="nav-link active" aria-current="page" href="cambiar_clave.php">Cambiar Contraseña</a></li>
            </ul>
            <form class="d-flex ms-auto" action="logout.php" method="post">
                <button class="btn btn-outline-light" type="submit">Cerrar sesión (<?= htmlspecialchars($nombre_usuario_sesion) ?>)</button>
            </form>
        </div>
    </div>
</nav>

<div class="container-main container mt-4">
    <div class="card form-card p-4 p-md-5">
        <h3 class="page-title text-center mb-4">Cambiar Mi Contraseña</h3>

        <?php if ($mensaje_cambio): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert"><?= htmlspecialchars($mensaje_cambio) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>
        <?php endif; ?>
        <?php if ($error_cambio): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert"><?= htmlspecialchars($error_cambio) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>
        <?php endif; ?>

        <?php if ($cedula_usuario_sesion): // Solo mostrar formulario si se identificó al usuario ?>
        <form action="<?= htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" id="formCambiarClave">
            <div class="mb-3">
                <label for="contrasena_actual" class="form-label">Contraseña Actual <span class="text-danger">*</span></label>
                <input type="password" class="form-control" id="contrasena_actual" name="contrasena_actual" required>
            </div>
            <hr>
            <div class="mb-3">
                <label for="nueva_contrasena" class="form-label">Nueva Contraseña <span class="text-danger">*</span></label>
                <input type="password" class="form-control" id="nueva_contrasena" name="nueva_contrasena" required minlength="6">
                <div id="passwordHelpBlock" class="form-text">
                    Tu nueva contraseña debe tener al menos 6 caracteres.
                </div>
            </div>
            <div class="mb-4">
                <label for="confirmar_nueva_contrasena" class="form-label">Confirmar Nueva Contraseña <span class="text-danger">*</span></label>
                <input type="password" class="form-control" id="confirmar_nueva_contrasena" name="confirmar_nueva_contrasena" required>
            </div>

            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-key-fill"></i> Cambiar Contraseña</button>
            </div>
        </form>
        <?php else: ?>
            <div class="alert alert-warning">No se puede cambiar la contraseña porque no se ha identificado al usuario actual. Por favor, inicie sesión.</div>
        <?php endif; ?>
    </div>
</div>

<script>
    const nuevaPassword = document.getElementById("nueva_contrasena");
    const confirmarNuevaPassword = document.getElementById("confirmar_nueva_contrasena");

    function validateNewPassword(){
      if(nuevaPassword && confirmarNuevaPassword && nuevaPassword.value !== confirmarNuevaPassword.value) {
        confirmarNuevaPassword.setCustomValidity("Las nuevas contraseñas no coinciden.");
      } else if (confirmarNuevaPassword) {
        confirmarNuevaPassword.setCustomValidity('');
      }
    }
    if (nuevaPassword) nuevaPassword.onchange = validateNewPassword;
    if (confirmarNuevaPassword) confirmarNuevaPassword.onkeyup = validateNewPassword;
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>