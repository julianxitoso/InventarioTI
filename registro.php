<?php
// --- registro.php (PÁGINA PÚBLICA) ---
session_start();

require_once 'backend/db.php';
if (isset($conn) && !isset($conexion)) {
    $conexion = $conn;
}

$regionales_usuarios = ['Popayan', 'Bordo', 'Santander', 'Valle', 'Pasto', 'Tuquerres', 'Huila', 'Nacional', 'N/A'];
$empresas_usuarios = ['Arpesod', 'Finansueños', 'N/A'];
$rol_fijo_para_registro = 'registrador';

// Mensajes flash
$mensaje_exito_registro = $_SESSION['mensaje_registro'] ?? ($_SESSION['mensaje_login'] ?? null); // Para el mensaje de éxito que viene de guardar_usuario.php
$error_general_registro = $_SESSION['error_form_usuario'] ?? null;   // Esta es la variable que usa guardar_usuario.php para errores

$mostrar_modal_cedula_existente = false;
$mensaje_para_modal = '';

// Lógica para detectar el error de cédula existente
if (
    $error_general_registro &&
    (strpos(strtolower($error_general_registro), 'cédula') !== false || strpos(strtolower($error_general_registro), 'usuario') !== false) &&
    (strpos(strtolower($error_general_registro), 'ya existe') !== false || strpos(strtolower($error_general_registro), 'ya está registrada') !== false)
) {

    $mostrar_modal_cedula_existente = true;
    $mensaje_para_modal = $error_general_registro;
    $error_general_registro = null; // Limpiamos el error general para que no se muestre también como alerta
}

// Limpiar las variables de sesión usadas
unset($_SESSION['mensaje_registro'], $_SESSION['mensaje_login'], $_SESSION['error_form_usuario']);
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
            padding: 0.5rem 1rem;
            background-color: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            height: 85px;
        }

        .logo-container-top img {
            max-height: 65px;
            width: auto;
            max-width: 100%;
            object-fit: contain;
        }

        .container-main {
            max-width: 700px;
            width: 100%;
            margin-top: 20px;
            margin-bottom: 40px;
        }

        .card.form-card {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            border: 1px solid #e0e0e0;
        }

        .page-header-title {
            color: black;
        }

        .form-label {
            font-weight: 500;
        }

        /* <<< --- CSS PARA EL MODAL (SI QUIERES PERSONALIZARLO MÁS) --- >>> */
        #modalCedulaExistente .modal-header {
            background-color: #191970;
            /* Tu color corporativo */
            color: #ffffff;
        }

        #modalCedulaExistente .modal-header .btn-close {
            filter: invert(1) grayscale(100%) brightness(200%);
            /* Botón de cierre blanco */
        }

        #modalCedulaExistente .modal-title i {
            margin-right: 8px;
        }

        .btn-principal {
            /* Si ya existe, no necesitas añadirla de nuevo */
            background-color: #191970;
            border-color: #191970;
            color: #ffffff;
            /* Color del texto, blanco para buen contraste */
        }

        .btn-principal:hover {
            background-color: #111150;
            /* Un tono ligeramente más oscuro para el efecto hover */
            border-color: #111150;
            color: #ffffff;
        }
    </style>
</head>

<body>

    <div class="container-main">
        <div class="card form-card p-4 p-md-5">
            <h3 class="text-center mb-3 page-header-title">Registro de Nueva Cuenta</h3>
            <p class="text-center text-muted mb-4">Crea tu cuenta para poder registrar activos en el sistema.</p>

            <?php if ($mensaje_exito_registro): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($mensaje_exito_registro) ?> <a href="login.php" class="alert-link fw-bold ms-2">Iniciar Sesión</a>.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if ($error_general_registro): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($error_general_registro) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
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
                    <button type="submit" class="btn btn-principal btn-lg">Registrarme</button>
                </div>
                <div class="text-center mt-3">
                    <a href="login.php">¿Ya tienes cuenta? Inicia Sesión</a>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="modalCedulaExistente" tabindex="-1" aria-labelledby="modalCedulaExistenteLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalCedulaExistenteLabel"><i class="bi bi-exclamation-triangle-fill"></i> Cédula Ya Registrada</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p id="mensajeModalCedulaExistente"></p>
                    <p>Por favor, intente <a href="login.php" class="alert-link">iniciar sesión</a> o utilice una cédula diferente.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Entendido</button>
                </div>
            </div>
        </div>
    </div>
    <script>
        // Script de validación de contraseñas (sin cambios)
        const password = document.getElementById("contrasena");
        const confirm_password = document.getElementById("confirmar_contrasena");

        function validatePassword() {
            if (password && confirm_password && password.value != confirm_password.value) {
                confirm_password.setCustomValidity("Las contraseñas no coinciden.");
            } else if (confirm_password) {
                confirm_password.setCustomValidity('');
            }
        }
        if (password) password.onchange = validatePassword;
        if (confirm_password) confirm_password.onkeyup = validatePassword;

        // <<< --- JAVASCRIPT PARA MOSTRAR EL MODAL --- >>>
        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($mostrar_modal_cedula_existente && !empty($mensaje_para_modal)): ?>
                const modalCedulaElement = document.getElementById('modalCedulaExistente');
                if (modalCedulaElement) {
                    const modalCedula = new bootstrap.Modal(modalCedulaElement);
                    const mensajeModalP = document.getElementById('mensajeModalCedulaExistente');
                    if (mensajeModalP) {
                        // json_encode asegura que el string de PHP se pase correctamente a JavaScript
                        // evitando problemas con comillas o caracteres especiales.
                        mensajeModalP.textContent = <?= json_encode($mensaje_para_modal) ?>;
                    }
                    modalCedula.show();
                }
            <?php endif; ?>
        });
        // <<< --- FIN JAVASCRIPT PARA MOSTRAR EL MODAL --- >>>
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>