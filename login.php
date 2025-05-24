<?php
session_start();
// Si el usuario ya está logueado, redirigirlo a menu.php para evitar que vea el login de nuevo
if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
    header("location: menu.php");
    exit;
}

require_once 'backend/db.php';

$error_login = "";

// Asegurarse de que la conexión se establece una sola vez si db.php la define
if (isset($conn) && !isset($conexion)) {
    $conexion = $conn;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['login_submit'])) {
    if (empty(trim($_POST["usuario"])) || empty(trim($_POST["clave"]))) {
        $error_login = "Por favor, ingrese usuario y contraseña.";
    } else {
        $usuario_ingresado_form = trim($_POST["usuario"]); // El campo del form se llama 'usuario', contiene la cédula
        $clave_ingresada_form = trim($_POST["clave"]);

        if (!isset($conexion) || (method_exists($conexion, 'connect_error') && $conexion->connect_error)) {
            $error_login = "Error de conexión a la base de datos. Intente más tarde.";
            error_log("Error de conexión BD en login.php: " . ($conexion->connect_error ?? 'Desconocido'));
        } else {
            $conexion->set_charset("utf8mb4");

            // CORRECCIÓN: Usar los nombres de columna correctos de tu tabla 'usuarios'
            // Asumimos: 'usuario' para la cédula/login, 'clave' para el hash de la contraseña,
            // y que las columnas 'cargo', 'empresa', 'regional' existen.
            $sql = "SELECT id, usuario, clave, nombre_completo, rol, activo, cargo, empresa, regional 
                    FROM usuarios 
                    WHERE usuario = ?"; // <--- Usar 'usuario' para el WHERE clause

            if ($stmt = $conexion->prepare($sql)) { // Esta sería la línea 31 o cercana
                $stmt->bind_param("s", $usuario_ingresado_form);

                if ($stmt->execute()) {
                    $stmt->store_result();
                    if ($stmt->num_rows == 1) {
                        // Ajustar bind_result para incluir cargo, empresa, regional
                        $stmt->bind_result($id_db, $usuario_db_col, $clave_hash_db_col, $nombre_completo_db, $rol_db, $activo_db, $cargo_db, $empresa_db, $regional_db);

                        if ($stmt->fetch()) {
                            if ($activo_db == 1 || $activo_db === TRUE) {
                                if (password_verify($clave_ingresada_form, $clave_hash_db_col)) {
                                    session_regenerate_id(true);
                                    $_SESSION["loggedin"] = true;
                                    $_SESSION["usuario_id"] = $id_db;
                                    $_SESSION["usuario_login"] = $usuario_db_col; // Contiene la cédula (valor de la columna 'usuario')
                                    $_SESSION["nombre_usuario_completo"] = $nombre_completo_db;
                                    $_SESSION["rol_usuario"] = $rol_db;
                                    $_SESSION["cargo_usuario"] = $cargo_db;     // <<< Nueva sesión
                                    $_SESSION["empresa_usuario"] = $empresa_db;
                                    $_SESSION["regional_usuario"] = $regional_db;

                                    $_SESSION['usuario'] = $nombre_completo_db;

                                    header("location: menu.php");
                                    exit;
                                } else {
                                    $error_login = "La contraseña ingresada no es válida.";
                                }
                            } else {
                                $error_login = "Esta cuenta de usuario ha sido desactivada.";
                            }
                        }
                    } else {
                        $error_login = "No se encontró una cuenta con esa cédula/usuario.";
                    }
                } else {
                    $error_login = "Oops! Algo salió mal al ejecutar la consulta. Por favor, inténtelo de nuevo más tarde.";
                    error_log("Error de ejecución en login.php: " . $stmt->error);
                }
                $stmt->close();
            } else {
                $error_login = "Oops! Error al preparar la consulta. Por favor, inténtelo de nuevo más tarde.";
                error_log("Error de preparación en login.php: " . $conexion->error);
            }
            // No cerrar $conexion aquí si db.php la maneja globalmente y otros scripts la esperan
            // if (isset($conn)) { /* $conexion es $conn, no cerrar */ } else { if(isset($conexion)) $conexion->close(); }
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
            background-color: #f0f2f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .login-container {
            background-color: #fff;
            padding: 2.5rem;
            border-radius: 10px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 420px;
        }

        .login-header {
            text-align: center;
            margin-bottom: 1.5rem;
        }

        .login-header img {
            max-width: 200px;
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

        .form-control:focus {
            border-color: #191970;
            box-shadow: 0 0 0 0.25rem rgba(25, 25, 112, 0.25);
        }

        .btn-login {
            background-color: #191970;
            border: none;
            padding: 0.75rem;
            font-size: 1.05rem;
            font-weight: 500;
        }

        .btn-login:hover {
            background-color: #10104d;
        }

        .alert-danger {
            font-size: 0.9rem;
        }

        .extra-links {
            text-align: center;
            margin-top: 1.5rem;
        }

        .extra-links a {
            font-size: 0.9em;
        }

        .btn-outline-principal {
            color: black;
            /* Color del texto y del ícono */
            border-color: #191970;
            /* Color del borde */
        }

        .btn-outline-principal:hover {
            background-color: #191970;
            /* Fondo al pasar el mouse */
            color: #ffffff;
            /* Texto blanco al pasar el mouse */
            border-color: #191970;
        }

        /* Ajuste para el ícono dentro del botón outline principal, si es necesario */
        .btn-outline-principal i {
            color: #191970;
            /* Mismo color que el texto */
        }

        .btn-outline-principal:hover i {
            color: #ffffff;
            /* Mismo color que el texto en hover */
        }
    </style>
</head>

<body>

    <div class="login-container">
        <div class="login-header">
            <img src="imagenes/logo3.png" alt="Logo Empresa">
            <h2>Inventario de Activos</h2>
            <p class="text-muted">ARPESOD ASOCIADOS SAS</p>
        </div>

        <?php if (!empty($error_login)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($error_login); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <form action="<?= htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
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

        <div class="extra-links">
            <p class="mb-1">¿Eres nuevo y necesitas registrar activos?</p>
            <a href="registro.php" class="btn btn-outline-principal btn-sm">
                <i class="bi bi-person-plus"></i> Regístrate aquí como Registrador
            </a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>