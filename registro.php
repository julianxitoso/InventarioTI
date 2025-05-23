<?php
// --- registro.php (PÁGINA PÚBLICA) ---
session_start(); 

// NO se usa auth_check.php para restringir, es PÚBLICO.
require_once 'backend/db.php'; 
if (isset($conn) && !isset($conexion)) { $conexion = $conn; }

$regionales_usuarios = ['Popayan', 'Bordo', 'Santander', 'Valle', 'Pasto', 'Tuquerres', 'Huila', 'Nacional', 'N/A'];
$empresas_usuarios = ['Arpesod', 'Finansueños', 'N/A'];
// El rol para el público siempre será 'registrador'
$rol_fijo_para_registro = 'registrador';

// Mensajes flash para el resultado
$mensaje = $_SESSION['mensaje_registro'] ?? null;
$error = $_SESSION['error_registro'] ?? null;
unset($_SESSION['mensaje_registro'], $_SESSION['error_registro']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Registro de Usuario</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
         body { 
        background-color: #ffffff !important; 
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        /* Aumentamos el padding para dar espacio a la barra superior */
        padding-top: 100px; 
        display: flex;
        flex-direction: column;
        align-items: center;
        min-height: 100vh;
    }
    .top-bar-public {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        z-index: 1030;
        display: flex;
        justify-content: center;
        align-items: center;
        padding: 0.5rem 1rem; /* Padding reducido un poco */
        background-color: #f8f9fa; 
        border-bottom: 1px solid #dee2e6;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        /* Se define una altura fija para la barra */
        height: 85px; 
    }
    /* --- LA CORRECCIÓN PRINCIPAL ESTÁ AQUÍ --- */
    .logo-container-top img {
        /* Usamos max-height para asegurar que el logo nunca sea demasiado alto */
        max-height: 65px; 
        /* Width auto para que mantenga la proporción */
        width: auto;  
        /* Opcional: para que la imagen no se estire si es muy ancha */
        max-width: 100%; 
        object-fit: contain;
    }
    
    .container-main { 
        max-width: 700px; 
        width:100%; 
        margin-top: 20px;
        margin-bottom: 40px;
    }
    .card.form-card { 
        box-shadow: 0 4px 12px rgba(0,0,0,0.1); 
        border: 1px solid #e0e0e0;
    }
    .page-header-title { color: #191970; }
    .form-label { font-weight: 500; }
    </style>
</head>
<body>
<div class="container-main"> 
    <div class="card form-card p-4 p-md-5">
        <h3 class="text-center mb-3 page-header-title">Registro de Nueva Cuenta</h3>
        <p class="text-center text-muted mb-4">Crea tu cuenta para poder registrar activos en el sistema.</p>

        <?php if ($mensaje): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($mensaje) ?> <a href="login.php" class="alert-link fw-bold">Iniciar Sesión</a>.
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form action="guardar_usuario.php" method="post" id="formRegistrarUsuario">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="cedula" class="form-label">Cédula (Será tu usuario) <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="cedula" name="cedula" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="nombre_completo" class="form-label">Nombre Completo <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="nombre_completo" name="nombre_completo" required>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="contrasena" class="form-label">Contraseña <span class="text-danger">*</span></label>
                    <input type="password" class="form-control" id="contrasena" name="contrasena" required minlength="6">
                </div>
                <div class="col-md-6 mb-3">
                    <label for="confirmar_contrasena" class="form-label">Confirmar Contraseña <span class="text-danger">*</span></label>
                    <input type="password" class="form-control" id="confirmar_contrasena" name="confirmar_contrasena" required>
                </div>
            </div>
            <div class="mb-3">
                <label for="cargo" class="form-label">Tu Cargo <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="cargo" name="cargo" required>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="empresa_usuario" class="form-label">Empresa <span class="text-danger">*</span></label>
                    <select class="form-select" id="empresa_usuario" name="empresa_usuario" required>
                        <option value="">Seleccione...</option>
                        <?php foreach ($empresas_usuarios as $e): ?>
                            <option value="<?= htmlspecialchars($e) ?>"><?= htmlspecialchars($e) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="regional_usuario" class="form-label">Regional <span class="text-danger">*</span></label>
                    <select class="form-select" id="regional_usuario" name="regional_usuario" required>
                        <option value="">Seleccione...</option>
                        <?php foreach ($regionales_usuarios as $r): ?>
                            <option value="<?= htmlspecialchars($r) ?>"><?= htmlspecialchars($r) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <input type="hidden" name="rol_usuario" value="<?= htmlspecialchars($rol_fijo_para_registro) ?>">
            
            <div class="d-grid gap-2 mt-4">
                <button type="submit" class="btn btn-success btn-lg">Registrarme</button>
            </div>
            <div class="text-center mt-3">
                <a href="login.php">¿Ya tienes cuenta? Inicia Sesión</a>
            </div>
        </form>
    </div>
</div>

<script>
    // Tu script de validación de contraseñas es el mismo y está perfecto
    const password = document.getElementById("contrasena");
    const confirm_password = document.getElementById("confirmar_contrasena");
    function validatePassword(){
      if(password.value != confirm_password.value) { confirm_password.setCustomValidity("Las contraseñas no coinciden."); } 
      else { confirm_password.setCustomValidity(''); }
    }
    password.onchange = validatePassword;
    confirm_password.onkeyup = validatePassword;
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>