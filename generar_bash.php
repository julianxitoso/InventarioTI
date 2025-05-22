<?php
// Archivo: generar_hash.php (ELIMINAR DESPUÉS DE USAR)

$nueva_contrasena_admin = "apolo&2025"; // <<< CAMBIA ESTO POR TU NUEVA CONTRASEÑA

if (empty($nueva_contrasena_admin)) {
    die("Por favor, define una contraseña en la variable \$nueva_contrasena_admin dentro de este script.");
}

$hash_para_db = password_hash($nueva_contrasena_admin, PASSWORD_DEFAULT);

if ($hash_para_db === false) {
    die("Error al generar el hash de la contraseña. Verifica tu configuración de PHP.");
}

echo "Copia este hash para tu base de datos:<br><br>";
echo htmlspecialchars($hash_para_db);

// Verifica que el hash funciona (opcional pero recomendado)
if (password_verify($nueva_contrasena_admin, $hash_para_db)) {
    echo "<br><br>¡Verificación del hash exitosa! La contraseña y el hash coinciden.";
} else {
    echo "<br><br>¡Error en la verificación del hash! Algo salió mal.";
}
?>