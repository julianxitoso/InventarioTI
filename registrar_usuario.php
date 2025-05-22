<?php
session_start(); // Necesario para mensajes flash
// require_once 'backend/auth_check.php'; // Se quita la restricción de admin para esta página

require_once 'backend/db.php'; 
if (isset($conn) && !isset($conexion)) { $conexion = $conn; }
// No se necesita $nombre_usuario_sesion porque es una página pública

$regionales_usuarios = ['Popayan', 'Bordo', 'Santander', 'Valle', 'Pasto', 'Tuquerres', 'Huila', 'Nacional', 'N/A'];
$empresas_usuarios = ['Arpesod', 'Finansueños', 'N/A'];
$rol_fijo_para_creacion = 'registrador'; // Rol asignado automáticamente

$mensaje = $_SESSION['mensaje_creacion_usuario'] ?? null;
$error = $_SESSION['error_creacion_usuario'] ?? null;
unset($_SESSION['mensaje_creacion_usuario']);
unset($_SESSION['error_creacion_usuario']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Auto-Registro de Usuario (Rol Registrador)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background: #f0f2f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding-top: 20px; padding-bottom: 20px;}
        .container-main { max-width: 700px; width:100%; }
        .card.form-card { box-shadow: 0 4px 12px rgba(0,0,0,0.1); border: none; }
        .logo-container { text-align: center; margin-bottom: 1rem; }
        .logo-container img { width: 180px; height: 70px; object-fit: contain; }
        .form-label { font-weight: 500; color: #495057; }
        /* Considerar añadir estilos para CAPTCHA si se implementa */
    </style>
</head>
<body>

<div class="container-main">
    <div class="logo-container">
        <a href="login.php"><img src="imagenes/logo3.png" alt="Logo"></a>
    </div>
    <div class="card form-card p-4 p-md-5">
        <h3 class="page-title text-center mb-4">Auto-Registro de Usuario</h3>
        <p class="text-center text-muted mb-3">Crea tu cuenta para registrar activos. Serás asignado con el rol de "Registrador".</p>

        <?php if ($mensaje): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($mensaje) ?>
                <a href="login.php" class="alert-link ms-2">Ir a Iniciar Sesión</a>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert"><?= htmlspecialchars($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>
        <?php endif; ?>

        <form action="guardar_usuario.php" method="post" id="formRegistrarUsuario">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="cedula" class="form-label">Cédula (Será tu usuario de login) <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="cedula" name="cedula" required pattern="[0-9]+" title="Solo números para la cédula">
                </div>
                <div class="col-md-6 mb-3">
                    <label for="nombre_completo" class="form-label">Nombre Completo <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="nombre_completo" name="nombre_completo" required>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="contrasena" class="form-label">Contraseña <span class="text-danger">*</span></label>
                    <input type="password" class="form-control" id="contrasena" name="contrasena" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="confirmar_contrasena" class="form-label">Confirmar Contraseña <span class="text-danger">*</span></label>
                    <input type="password" class="form-control" id="confirmar_contrasena" name="confirmar_contrasena" required>
                </div>
            </div>
             <div class="mb-3">
                <label for="cargo" class="form-label">Tu Cargo en la Empresa <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="cargo" name="cargo" required>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="empresa_usuario" class="form-label">Empresa a la que Pertenece <span class="text-danger">*</span></label>
                    <select class="form-select" id="empresa_usuario" name="empresa_usuario" required>
                        <option value="">Seleccione...</option>
                        <?php foreach ($empresas_usuarios as $e): ?>
                            <option value="<?= htmlspecialchars($e) ?>"><?= htmlspecialchars($e) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="regional_usuario" class="form-label">Tu Regional de Ubicación <span class="text-danger">*</span></label>
                    <select class="form-select" id="regional_usuario" name="regional_usuario" required>
                        <option value="">Seleccione...</option>
                        <?php foreach ($regionales_usuarios as $r): ?>
                            <option value="<?= htmlspecialchars($r) ?>"><?= htmlspecialchars($r) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>


            <input type="hidden" name="rol_usuario" value="<?= htmlspecialchars($rol_fijo_para_creacion) ?>">
            



            <div class="d-grid gap-2 mt-4">
                <button type="submit" class="btn btn-success btn-lg"><i class="bi bi-person-check-fill"></i> Registrarme</button>
            </div>
            <div class="text-center mt-3">
                <a href="login.php">¿Ya tienes cuenta? Inicia Sesión</a>
            </div>
        </form>
    </div>
</div>

<script>
    const password = document.getElementById("contrasena");
    const confirm_password = document.getElementById("confirmar_contrasena");
    function validatePassword(){
      if(password && confirm_password && password.value != confirm_password.value) { confirm_password.setCustomValidity("Las contraseñas no coinciden."); } 
      else if (confirm_password) { confirm_password.setCustomValidity(''); }
    }
    if (password) password.onchange = validatePassword;
    if (confirm_password) confirm_password.onkeyup = validatePassword;
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>