<?php
session_start();
require_once 'backend/db.php'; // Para la conexión $conexion

$error_login = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (empty(trim($_POST["usuario"])) || empty(trim($_POST["clave"]))) {
        $error_login = "Por favor, ingrese usuario y contraseña.";
    } else {
        $usuario_ingresado = trim($_POST["usuario"]);
        $clave_ingresada = trim($_POST["clave"]);

        if (isset($conn) && !isset($conexion)) { $conexion = $conn; }
        if (!isset($conexion) || $conexion->connect_error) {
             $error_login = "Error de conexión a la base de datos. Intente más tarde.";
        } else {
            $sql = "SELECT id, usuario, clave, nombre_completo, rol, activo FROM usuarios WHERE usuario = ?";
            if ($stmt = $conexion->prepare($sql)) {
                $stmt->bind_param("s", $usuario_ingresado);
                if ($stmt->execute()) {
                    $stmt->store_result();
                    if ($stmt->num_rows == 1) {
                        $stmt->bind_result($id_db, $usuario_db, $clave_hash_db, $nombre_completo_db, $rol_db, $activo_db);
                        if ($stmt->fetch()) {
                            if ($activo_db == 1) {
                                // VERIFICAR LA CONTRASEÑA
                                if (password_verify($clave_ingresada, $clave_hash_db)) {
                                    // Contraseña correcta, iniciar sesión
                                    $_SESSION["loggedin"] = true;
                                    $_SESSION["usuario_id"] = $id_db;
                                    $_SESSION["usuario_login"] = $usuario_db; // El nombre de usuario para login
                                    $_SESSION["nombre_usuario_completo"] = $nombre_completo_db;
                                    $_SESSION["rol_usuario"] = $rol_db;
                                    
                                    // Guardar el nombre de usuario de la sesión original que usabas
                                    // si tus otros scripts dependen de $_SESSION['usuario']
                                    $_SESSION['usuario'] = $nombre_completo_db; // o $usuario_db si prefieres el login name

                                    header("location: menu.php"); // O dashboard.php
                                    exit;
                                } else {
                                    $error_login = "La contraseña ingresada no es válida.";
                                }
                            } else {
                                $error_login = "Esta cuenta de usuario ha sido desactivada.";
                            }
                        }
                    } else {
                        $error_login = "No se encontró una cuenta con ese nombre de usuario.";
                    }
                } else {
                    $error_login = "Oops! Algo salió mal. Por favor, inténtelo de nuevo más tarde.";
                    error_log("Error de ejecución en login.php: " . $stmt->error);
                }
                $stmt->close();
            } else {
                 $error_login = "Oops! Error al preparar la consulta. Por favor, inténtelo de nuevo más tarde.";
                 error_log("Error de preparación en login.php: " . $conexion->error);
            }
            $conexion->close();
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
            background-color: #f0f2f5; /* Un fondo suave */
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .login-container {
            background-color: #fff;
            padding: 2.5rem;
            border-radius: 10px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1); /* Sombra más pronunciada */
            width: 100%;
            max-width: 420px; /* Ancho óptimo para el formulario */
        }
        .login-header {
            text-align: center;
            margin-bottom: 1.5rem;
        }
        .login-header img {
            max-width: 200px; /* Ajusta según tu logo */
            margin-bottom: 1rem;
        }
        .login-header h2 {
            color: #333;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        .form-floating label {
            color: #6c757d; /* Color más suave para labels flotantes */
        }
        .form-control:focus {
            border-color: #191970; /* Color principal de tu marca */
            box-shadow: 0 0 0 0.25rem rgba(25, 25, 112, 0.25); /* Sombra al enfocar */
        }
        .btn-login {
            background-color: #191970; /* Color principal */
            border: none;
            padding: 0.75rem;
            font-size: 1.05rem;
            font-weight: 500;
        }
        .btn-login:hover {
            background-color: #10104d; /* Un poco más oscuro al pasar el mouse */
        }
        .alert-danger {
            font-size: 0.9rem; /* Tamaño de fuente más pequeño para alertas */
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <img src="imagenes/logo3.png" alt="Logo Empresa"> <h2>Inventario de Activos</h2>
            <p class="text-muted">ARPESOD ASOCIADOS SAS</p>
        </div>

        <?php if(!empty($error_login)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($error_login); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <form action="<?= htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
            <div class="form-floating mb-3">
                <input type="text" class="form-control" id="usuario" name="usuario" placeholder="Nombre de Usuario" required autofocus>
                <label for="usuario"><i class="bi bi-person-fill"></i> Nombre de Usuario</label>
            </div>
            <div class="form-floating mb-3">
                <input type="password" class="form-control" id="clave" name="clave" placeholder="Contraseña" required>
                <label for="clave"><i class="bi bi-lock-fill"></i> Contraseña</label>
            </div>
            <div class="d-grid">
                <button class="btn btn-primary btn-login" type="submit">Ingresar</button>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>