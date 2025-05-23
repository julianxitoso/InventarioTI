    <?php
    session_start();
    require_once 'backend/auth_check.php';

    // Hacemos que esta página sea accesible solo para administradores
    restringir_acceso_pagina(['admin']);

    // Obtenemos el rol del usuario actual (sabemos que es 'admin' por la línea anterior)
    $rol_usuario_actual = obtener_rol_usuario();

    require_once 'backend/db.php';
    if (isset($conn) && !isset($conexion)) {
        $conexion = $conn;
    }

    // --- Datos para los formularios ---
    $regionales_usuarios = ['Popayan', 'Bordo', 'Santander', 'Valle', 'Pasto', 'Tuquerres', 'Huila', 'Nacional', 'N/A'];
    $empresas_usuarios = ['Arpesod', 'Finansueños', 'N/A'];

    // <<< ¡NUEVO! Definimos los roles que un admin puede asignar
    $roles_disponibles = ['registrador', 'auditor', 'tecnico', 'admin'];

    // Mensajes flash para mostrar el resultado de la operación
    $mensaje = $_SESSION['mensaje_creacion_usuario'] ?? null;
    $error = $_SESSION['error_creacion_usuario'] ?? null;
    unset($_SESSION['mensaje_creacion_usuario'], $_SESSION['error_creacion_usuario']);
    ?>
    <!DOCTYPE html>
    <html lang="es">

    <head>
        <meta charset="UTF-8">
        <title>Auto-Registro de Usuario (Rol Registrador)</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
        <style>
            body {
                background-color: #ffffff !important;
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                padding-top: 80px;
                /* Espacio para la barra superior fija */
                display: flex;
                /* Para centrar el container-main verticalmente si el contenido es poco */
                flex-direction: column;
                /* Alinear hijos verticalmente */
                align-items: center;
                /* Centrar horizontalmente */
                min-height: 100vh;
                /* Asegurar que el body ocupe al menos toda la altura */
            }

            .top-bar-public {
                /* Estilo ligeramente diferente para páginas públicas */
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                z-index: 1030;
                display: flex;
                justify-content: center;
                /* Centrar logo si es el único elemento principal */
                align-items: center;
                padding: 0.5rem 1.5rem;
                background-color: #f8f9fa;
                border-bottom: 1px solid #dee2e6;
                box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            }

            .logo-container-top img {
                width: auto;
                height: 75px;
                object-fit: contain;
            }

            .container-main {
                max-width: 700px;
                width: 100%;
                margin-top: 20px;
                /* Margen superior para separar del top-bar si no está fixed el top-bar */
                margin-bottom: 40px;
            }

            .card.form-card {
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
                border: 1px solid #e0e0e0;
                /* Borde sutil para la tarjeta */
            }

            .form-label {
                font-weight: 500;
                color: #495057;
            }

            .page-header-title {
                color: #191970;
            }
        </style>
    </head>

    <body>

        <div class="top-bar-public">
            <div class="logo-container-top">
                <a href="login.php" title="Ir a Inicio de Sesión">
                    <img src="imagenes/logo.png" alt="Logo ARPESOD ASOCIADOS SAS">
                </a>
            </div>
        </div>

        <div class="container-main">
            <div class="card form-card p-4 p-md-5">
            <h3 class="page-title text-center mb-3 page-header-title">Crear Nuevo Usuario</h3>
            <p class="text-center text-muted mb-4">Complete el formulario para registrar un nuevo usuario en el sistema.</p>
                <?php if ($mensaje): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($mensaje) ?>
                        <a href="login.php" class="alert-link ms-2 fw-bold">Ir a Iniciar Sesión</a>
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
                            <input type="password" class="form-control" id="contrasena" name="contrasena" required minlength="6">
                            <div class="form-text">Debe tener al menos 6 caracteres.</div>
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

                    <div class="mb-3">
                        <label for="rol_usuario" class="form-label">Rol del Nuevo Usuario <span class="text-danger">*</span></label>

                        <?php // Solo el admin puede ver y cambiar el rol 
                        ?>
                        <?php if ($rol_usuario_actual === 'admin'): ?>
                            <select class="form-select" id="rol_usuario" name="rol_usuario" required>
                                <option value="">Seleccione un rol...</option>
                                <?php foreach ($roles_disponibles as $rol): ?>
                                    <option value="<?= htmlspecialchars($rol) ?>">
                                        <?= htmlspecialchars(ucfirst($rol)) // ucfirst() pone la primera letra en mayúscula 
                                        ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <?php // Para cualquier otro caso, el rol es fijo y no se puede cambiar 
                            ?>
                            <input type="text" class="form-control" value="Registrador" readonly>
                            <input type="hidden" name="rol_usuario" value="registrador">
                        <?php endif; ?>
                    </div>
                    {/* Considera añadir un CAPTCHA aquí para seguridad:
                    <div class="mb-3">
                        <label class="form-label">Verificación de Seguridad <span class="text-danger">*</span></label>
                        [CÓDIGO DE TU CAPTCHA AQUÍ]
                    </div>
                    */}

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

            function validatePassword() {
                if (password && confirm_password && password.value != confirm_password.value) {
                    confirm_password.setCustomValidity("Las contraseñas no coinciden.");
                } else if (confirm_password) {
                    confirm_password.setCustomValidity('');
                }
            }
            if (password) password.onchange = validatePassword;
            if (confirm_password) confirm_password.onkeyup = validatePassword;
        </script>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    </body>

    </html>