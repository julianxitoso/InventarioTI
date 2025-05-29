<?php
session_start();

// ini_set('display_errors', 1); // Descomentar para depuración si no ves errores
// error_reporting(E_ALL);      // Descomentar para depuración

// Si el usuario ya está logueado, redirigirlo a menu.php
if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
    header("location: menu.php");
    exit;
}

require_once 'backend/db.php'; // $conexion debería definirse aquí

$error_login = "";

// Asegurarse de que la conexión se establece una sola vez si db.php la define
if (isset($conn) && !isset($conexion)) {
    $conexion = $conn; // $conn es el nombre común en tu db.php
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['login_submit'])) {
    error_log("[LOGIN_ATTEMPT] Intento de login POST recibido.");

    if (empty(trim($_POST["usuario"])) || empty(trim($_POST["clave"]))) {
        $error_login = "Por favor, ingrese usuario y contraseña.";
        error_log("[LOGIN_FAIL] Campos vacíos.");
    } else {
        $usuario_ingresado_form = trim($_POST["usuario"]); 
        $clave_ingresada_form = trim($_POST["clave"]);
        error_log("[LOGIN_ATTEMPT] Usuario: " . $usuario_ingresado_form);

        if (!isset($conexion) || (method_exists($conexion, 'connect_error') && $conexion->connect_error && $conexion->connect_error)) {
            $error_login = "Error de conexión a la base de datos. Intente más tarde.";
            error_log("[LOGIN_FAIL] Error de conexión a BD: " . ($conexion->connect_error ?? 'Desconocido o no disponible'));
        } else {
            $conexion->set_charset("utf8mb4");

            // Consulta AJUSTADA para obtener nombre_cargo desde la tabla cargos
            // y asumiendo que empresa y regional están en la tabla usuarios.
            $sql = "SELECT u.id, u.usuario, u.clave, u.nombre_completo, u.rol, u.activo, 
                           c.nombre_cargo, u.empresa, u.regional 
                    FROM usuarios u
                    LEFT JOIN cargos c ON u.id_cargo = c.id_cargo 
                    WHERE u.usuario = ?"; 

            error_log("[LOGIN_DEBUG] SQL: " . $sql);

            if ($stmt = $conexion->prepare($sql)) {
                error_log("[LOGIN_DEBUG] Statement preparado exitosamente.");
                $stmt->bind_param("s", $usuario_ingresado_form);

                if ($stmt->execute()) {
                    error_log("[LOGIN_DEBUG] Statement ejecutado exitosamente.");
                    $stmt->store_result();
                    error_log("[LOGIN_DEBUG] Número de filas encontradas: " . $stmt->num_rows);

                    if ($stmt->num_rows == 1) {
                        // Ajustar bind_result para el nuevo campo nombre_cargo
                        $stmt->bind_result($id_db, $usuario_db_col, $clave_hash_db_col, $nombre_completo_db, $rol_db, $activo_db, $nombre_cargo_db, $empresa_db, $regional_db);

                        if ($stmt->fetch()) {
                            error_log("[LOGIN_DEBUG] Fetch exitoso. Activo: " . $activo_db);
                            if ($activo_db == 1 || $activo_db === TRUE) { // Asegurar que el usuario esté activo
                                if (password_verify($clave_ingresada_form, $clave_hash_db_col)) {
                                    error_log("[LOGIN_SUCCESS] Contraseña verificada para usuario: " . $usuario_ingresado_form);
                                    session_regenerate_id(true); 
                                    $_SESSION["loggedin"] = true;
                                    $_SESSION["usuario_id"] = $id_db;
                                    $_SESSION["usuario_login"] = $usuario_db_col; 
                                    $_SESSION["nombre_usuario_completo"] = $nombre_completo_db;
                                    $_SESSION["rol_usuario"] = $rol_db;
                                    $_SESSION["cargo_usuario"] = $nombre_cargo_db; // Usar el nombre del cargo obtenido del JOIN
                                    $_SESSION["empresa_usuario"] = $empresa_db;
                                    $_SESSION["regional_usuario"] = $regional_db;
                                    
                                    // Esta variable de sesión parece redundante si ya tienes nombre_usuario_completo
                                    // $_SESSION['usuario'] = $nombre_completo_db; 

                                    header("location: menu.php");
                                    exit;
                                } else {
                                    $error_login = "La contraseña ingresada no es válida.";
                                    error_log("[LOGIN_FAIL] Contraseña incorrecta para usuario: " . $usuario_ingresado_form);
                                }
                            } else {
                                $error_login = "Esta cuenta de usuario ha sido desactivada.";
                                error_log("[LOGIN_FAIL] Cuenta desactivada para usuario: " . $usuario_ingresado_form);
                            }
                        } else {
                             $error_login = "Error al obtener los datos del usuario.";
                             error_log("[LOGIN_FAIL] Error en stmt->fetch() para usuario: " . $usuario_ingresado_form);
                        }
                    } else {
                        $error_login = "No se encontró una cuenta con esa cédula/usuario.";
                        error_log("[LOGIN_FAIL] No se encontró cuenta para usuario: " . $usuario_ingresado_form);
                    }
                } else {
                    $error_login = "Oops! Algo salió mal al consultar. Por favor, inténtelo de nuevo más tarde.";
                    error_log("[LOGIN_FAIL] Error de ejecución de statement: " . $stmt->error);
                }
                $stmt->close();
            } else {
                $error_login = "Oops! Error al preparar la consulta. Por favor, inténtelo de nuevo más tarde.";
                error_log("[LOGIN_FAIL] Error de preparación de statement: " . $conexion->error);
            }
            // No cierres la conexión $conexion aquí si es global y la usan otros scripts incluidos en `menu.php`
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Inventario de Activos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background-color: #f0f2f5; /* Un gris claro suave */
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .login-container {
            background-color: #fff;
            padding: 2.5rem; /* Más padding */
            border-radius: 10px; /* Bordes más redondeados */
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1); /* Sombra más pronunciada */
            width: 100%;
            max-width: 420px; /* Un poco más ancho */
        }
        .login-header {
            text-align: center;
            margin-bottom: 1.5rem;
        }
        .login-header img {
            max-width: 180px; /* Ajusta según tu logo */
            margin-bottom: 1rem;
        }
        .login-header h2 {
            color: #333;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        .form-floating label {
            color: #6c757d;
        }
        .form-control:focus { /* Estilo de foco consistente con otras páginas */
            border-color: #007bff; 
            box-shadow: 0 0 0 0.25rem rgba(0, 123, 255, 0.25);
        }
        .btn-login {
            background-color: #007bff; /* Azul primario de Bootstrap */
            border: none;
            padding: 0.75rem;
            font-size: 1.05rem;
            font-weight: 500;
        }
        .btn-login:hover {
            background-color: #0056b3; /* Azul más oscuro en hover */
        }
        .alert-danger {
            font-size: 0.9rem;
        }
        .extra-links { text-align: center; margin-top: 1.5rem; }
        .extra-links a { font-size: 0.9em; }

        /* Estilos para el botón de registro (si lo mantienes) */
        .btn-outline-principal {
            color: #007bff; /* Color del texto y del ícono */
            border-color: #007bff; /* Color del borde */
        }
        .btn-outline-principal:hover {
            background-color: #007bff; /* Fondo al pasar el mouse */
            color: #ffffff; /* Texto blanco al pasar el mouse */
        }
        .btn-outline-principal i { color: inherit; /* Hereda color del botón */}
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <img src="imagenes/logo.png" alt="Logo Empresa"> <h2>Inventario de Activos</h2>
            <p class="text-muted">ARPESOD ASOCIADOS SAS</p>
        </div>

        <?php if (!empty($error_login)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error_login); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <form action="<?= htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" novalidate>
            <div class="form-floating mb-3">
                <input type="text" class="form-control" id="usuario" name="usuario" placeholder="Cédula de Usuario" required autofocus>
                <label for="usuario"><i class="bi bi-person-fill"></i> Cédula de Usuario</label>
            </div>
            <div class="form-floating mb-3">
                <input type="password" class="form-control" id="clave" name="clave" placeholder="Contraseña" required>
                <label for="clave"><i class="bi bi-lock-fill"></i> Contraseña</label>
            </div>
            <div class="d-grid">
                <button class="btn btn-primary btn-login" type="submit" name="login_submit">Ingresar</button>
            </div>
        </form>

        <?php if(isset($mostrar_enlace_registro) && $mostrar_enlace_registro): // Ocultar si no se usa ?>
        <div class="extra-links">
            <p class="mb-1">¿Eres nuevo y necesitas registrar activos?</p>
            <a href="registro.php" class="btn btn-outline-principal btn-sm">
                <i class="bi bi-person-plus"></i> Regístrate aquí como Registrador
            </a>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>