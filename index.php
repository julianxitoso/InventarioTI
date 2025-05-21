<?php
// session_start(); // auth_check.php ya lo hace
require_once 'backend/auth_check.php';
restringir_acceso_pagina(['admin', 'tecnico']); // Solo admin y técnico pueden acceder a registrar

// Para el saludo en la navbar si lo necesitas
$nombre_usuario_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Usuario';
?>

<!DOCTYPE html>
<html lang="es">
<head>
 <meta charset="UTF-8">
 <title>Registro de Activos</title>
 <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
 <script>
    let usuarioFijado = false;
    let datosUsuarioFijados = {
        cedula: '',
        nombre: '',
        cargo: '',
        empresa:''
    };

    function mostrarCamposComputador() {
        var tipoActivo = document.getElementById("tipo-activo").value;
        var camposComputador = document.getElementById("campos-computador");
        // Referencias a los divs específicos dentro de campos-computador
        var soField = document.getElementById("so-field");
        var offimaticaField = document.getElementById("offimatica-field");
        var antivirusField = document.getElementById("antivirus-field"); 
        var procesadorField = document.getElementById("procesador-field");
        var ramField = document.getElementById("ram-field");
        var discoField = document.getElementById("disco-field");
        var tipoEquipoField = document.getElementById("tipo-equipo-field");
        var redField = document.getElementById("red-field");

        if (tipoActivo === "Computador") {
            camposComputador.style.display = "block";
            if(soField) soField.style.display = "block";
            if(offimaticaField) offimaticaField.style.display = "block";
            if(antivirusField) antivirusField.style.display = "block"; 
            if(procesadorField) procesadorField.style.display = "block";
            if(ramField) ramField.style.display = "block";
            if(discoField) discoField.style.display = "block";
            if(tipoEquipoField) tipoEquipoField.style.display = "block";
            if(redField) redField.style.display = "block";
        } else {
            camposComputador.style.display = "none";
            if(soField) soField.style.display = "none";
            if(offimaticaField) offimaticaField.style.display = "none";
            if(antivirusField) antivirusField.style.display = "none"; 
            if(procesadorField) procesadorField.style.display = "none";
            if(ramField) ramField.style.display = "none";
            if(discoField) discoField.style.display = "none";
            if(tipoEquipoField) tipoEquipoField.style.display = "none";
            if(redField) redField.style.display = "none";
        }
    }

    function actualizarNombreUsuarioDisplay() {
        const cedulaVal = document.getElementById('cedula').value;
        const nombreVal = document.getElementById('nombre').value;
        const cargoVal = document.getElementById('cargo').value;
        const empresaVal = document.getElementById('empresa').value;
        var usuarioActualEl = document.getElementById("usuario-actual");

        if (usuarioFijado) {
            usuarioActualEl.innerHTML = `Registrando activos para: <strong>${datosUsuarioFijados.nombre}</strong> (Cédula: ${datosUsuarioFijados.cedula}, Cargo: ${datosUsuarioFijados.cargo}, Empresa: ${datosUsuarioFijados})`;
        } else if (cedulaVal && nombreVal && cargoVal && empresaVal) {
            usuarioActualEl.innerHTML = `Preparando para registrar activos a: <strong>${nombreVal}</strong> (Cédula: ${cedulaVal}, Cargo: ${cargoVal}, Empresa: ${empresaVal})`;
        } else {
            usuarioActualEl.innerHTML = "<em>Por favor, ingrese datos completos del usuario.</em>";
        }
    }

    function guardarActivoActual() {
        var form = document.getElementById('activo-form');
        var cedulaInput = form.elements['cedula'];
        var nombreInput = form.elements['nombre'];
        var cargoInput = form.elements['cargo'];
        var empresaInput = form.elements['empresa'];

        if (!usuarioFijado) {
            if (!cedulaInput.value || !nombreInput.value || !cargoInput.value || !empresaInput.value) {
                alert("Por favor, complete los datos del usuario (Cédula, Nombre, Cargo y empresa) primero.");
                cedulaInput.focus();
                return;
            }
        }

        var tipoActivoSelect = form.elements['tipo'];
        var marcaInput = form.elements['marca'];
        var serieInput = form.elements['serie'];
        var estadoSelect = form.elements['estado'];
        var valorInput = form.elements['valor'];
        var regionalInput = form.elements['regional'];

        if (!tipoActivoSelect.value || !marcaInput.value || !serieInput.value || !estadoSelect.value || !valorInput.value || !regionalInput.value ) {
            alert("Por favor, complete los campos obligatorios del activo (Tipo, Marca, Serie, Estado, Valor, Regional) para poder guardarlo.");
            if (!tipoActivoSelect.value) tipoActivoSelect.focus();
            else if (!marcaInput.value) marcaInput.focus();
            else if (!serieInput.value) serieInput.focus();
            else if (!estadoSelect.value) estadoSelect.focus();
            else if (!valorInput.value) valorInput.focus();
            else if (!regionalInput.value) regionalInput.focus();
            return;
        }
        if (isNaN(parseFloat(valorInput.value)) || parseFloat(valorInput.value) < 0) {
            alert("El campo 'Valor Aprox.' debe ser un número válido y no negativo.");
            valorInput.focus();
            return;
        }

        if (tipoActivoSelect.value === "Computador") {
            // Opcional: Validar campos específicos de computador aquí si son obligatorios
        }

        if (!usuarioFijado) {
            datosUsuarioFijados.cedula = cedulaInput.value;
            datosUsuarioFijados.nombre = nombreInput.value;
            datosUsuarioFijados.cargo = cargoInput.value;            
            datosUsuarioFijados.empresa = empresaInput.value;
            cedulaInput.disabled = true;
            nombreInput.disabled = true;
            cargoInput.disabled = true;
            empresaInput.disabled = true;
            usuarioFijado = true;
            actualizarNombreUsuarioDisplay(); 
            document.getElementById("btnGuardarActivo").textContent = "Guardar este Activo y Añadir Otro";
        }

        var formData = new FormData(form);
        formData.set('cedula', datosUsuarioFijados.cedula);
        formData.set('nombre', datosUsuarioFijados.nombre);
        formData.set('cargo', datosUsuarioFijados.cargo);        
        formData.set('empresa', datosUsuarioFijados.empresa);
        
        
        console.log(formData);
        

        fetch('guardar_activo.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(data => {
            alert(data); 
            if (data.toLowerCase().includes("registrado correctamente")) {
                var table = document.getElementById('tabla-activos-guardados').getElementsByTagName('tbody')[0];
                var row = table.insertRow();
                var tipoActivoTexto = tipoActivoSelect.options[tipoActivoSelect.selectedIndex].text;
                var estadoTexto = estadoSelect.options[estadoSelect.selectedIndex].text; 
                row.insertCell(0).textContent = table.rows.length; 
                row.insertCell(1).textContent = tipoActivoTexto;
                row.insertCell(2).textContent = marcaInput.value;
                row.insertCell(3).textContent = serieInput.value;
                row.insertCell(4).textContent = estadoTexto; 

                tipoActivoSelect.value = "";
                marcaInput.value = "";
                serieInput.value = "";
                estadoSelect.value = ""; 
                form.elements['procesador'].value = "";
                form.elements['ram'].value = "";
                form.elements['disco'].value = "";
                form.elements['tipo_equipo'].value = "";
                form.elements['red'].value = "";
                form.elements['so'].value = ""; 
                form.elements['offimatica'].value = ""; 
                form.elements['antivirus'].value = ""; 
                form.elements['valor'].value = ""; 
                form.elements['detalles'].value = "";
                mostrarCamposComputador(); 
                tipoActivoSelect.focus();
            }
        })
        .catch(error => {
            console.error('Error al guardar el activo:', error);
            alert('Error al guardar el activo. Revise la consola del navegador.');
        });
    }

    function finalizarYNuevoUsuario() {
        var form = document.getElementById('activo-form');
        form.reset(); 
        form.elements['cedula'].disabled = false;
        form.elements['nombre'].disabled = false;
        form.elements['cargo'].disabled = false;
        form.elements['empresa'].disabled = false;
        usuarioFijado = false;
        datosUsuarioFijados = { cedula: '', nombre: '', cargo: '', empresa: '' };
        actualizarNombreUsuarioDisplay();
        document.getElementById("btnGuardarActivo").textContent = "Guardar Activo";
        var tbody = document.getElementById('tabla-activos-guardados').getElementsByTagName('tbody')[0];
        tbody.innerHTML = ""; 
        mostrarCamposComputador(); 
        form.elements['cedula'].focus();
    }

    document.addEventListener('DOMContentLoaded', function() {
        actualizarNombreUsuarioDisplay();
        document.getElementById('cedula').addEventListener('input', actualizarNombreUsuarioDisplay);
        document.getElementById('nombre').addEventListener('input', actualizarNombreUsuarioDisplay);
        document.getElementById('cargo').addEventListener('input', actualizarNombreUsuarioDisplay);
        document.getElementById('empresa').addEventListener('input', actualizarNombreUsuarioDisplay);
        mostrarCamposComputador(); 
    });
 </script>
 <style>
    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f6f8; }
    #activo-form label { font-weight: 500; color: #37517e; margin-bottom: 0.3rem; display: block; }
    #activo-form input, #activo-form select, #activo-form textarea {
        width: 100%; padding: 0.5rem 0.75rem; border: 1px solid #ced4da;
        border-radius: 0.375rem; background: #fff; box-shadow: inset 0 1px 2px rgba(0,0,0,0.075);
        margin-bottom: 1rem; font-size: 0.95rem; transition: border-color .15s ease-in-out,box-shadow .15s ease-in-out;
    }
    #activo-form input:focus, #activo-form select:focus, #activo-form textarea:focus {
        outline: none; border-color: #86b7fe; box-shadow: 0 0 0 0.25rem rgba(13,110,253,0.25);
    }
    #activo-form input[disabled], #activo-form select[disabled] { background-color: #e9ecef; opacity: 1; }
    .btn-form-action { padding: 0.5rem 1rem; border: none; border-radius: 0.375rem; color: white; font-size: 0.95rem; cursor: pointer; transition: background-color 0.2s ease-in-out; margin-right: 8px; }
    .btn-guardar-activo { background-color: #198754; } 
    .btn-guardar-activo:hover { background-color: #157347; }
    .btn-finalizar { background-color: #6c757d; } 
    .btn-finalizar:hover { background-color: #5c636a; }
    .logo-container { text-align: center; margin-bottom: 5px; padding-top:10px;}
    .logo-container img { width: 180px; height: auto; }
    .navbar-custom { background-color: #191970; }
    .navbar-custom .nav-link { color: white !important; font-weight: 500; padding: 0.5rem 1rem;}
    .navbar-custom .nav-link:hover { background-color: #8b0000; color: white; }
    .card { border: none; }
    #usuario-actual em { color: #6c757d; } 
 </style>
</head>
<body> 
 <div class="logo-container">
  <a href="menu.php"><img src="imagenes/logo3.png" alt="Logo" style="height: 70px; width: auto;"></a>
 </div>
 <nav class="navbar navbar-expand-lg navbar-custom">
    <div class="container-fluid">
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon" style="background-image: url(\"data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba(255,255,255,0.8)' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e\");"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item"><a class="nav-link" href="menu.php">Inicio</a></li>
                <li class="nav-item"><a class="nav-link" href="editar.php">Editar Activos</a></li>
                <li class="nav-item"><a class="nav-link" href="buscar.php">Buscar Activos</a></li>
                <li class="nav-item"><a class="nav-link" href="informes.php">Informes</a></li>
                <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>                
            </ul>
            <form class="d-flex ms-auto" action="logout.php" method="post">
                <button class="btn btn-outline-light" type="submit">Cerrar sesión</button>
            </form>
        </div>
    </div>
 </nav>
 <div class="container py-4"> 
    <div class="card p-4 shadow-sm"> 
        <h3 class="mb-3 text-center">Registro de Activos Tecnológicos</h3>
        <h5 id="usuario-actual" class="text-center mb-4"></h5>
        <form id="activo-form" onsubmit="return false;">
            <h6>Datos del Usuario</h6>
            <div class="row">
                <div class="col-md-4 mb-3"><label for="cedula">Cédula</label><input type="text" class="form-control" id="cedula" name="cedula" required></div>
                <div class="col-md-4 mb-3"><label for="nombre">Nombre</label><input type="text" class="form-control" id="nombre" name="nombre" required></div>
                <div class="col-md-4 mb-3"><label for="cargo">Cargo</label><input type="text" class="form-control" id="cargo" name="cargo" required></div>
                <div class="col-md-4 mb-3">
                    <label for="empresa">Empresa</label>
                    <select class="form-control" name="empresa" id="empresa" required>
                        <option value="">Seleccione...</option>
                        <option value="Finansueños">Finansueños</option>
                        <option value="Arpesod">Arpesod</option>
                    </select>
                </div>
            </div>
            <hr> 
            <h6 class="mt-3">Datos Generales del Activo</h6>
            <div class="row mt-2">
                <div class="col-md-4 mb-3">
                    <label for="tipo-activo">Tipo de Activo</label>
                    <select class="form-select" name="tipo" id="tipo-activo" required onchange="mostrarCamposComputador()">
                        <option value="">Seleccione...</option>
                        <option value="Computador">Computador</option>
                        <option value="Monitor">Monitor</option>
                        <option value="Impresora">Impresora</option>
                        <option value="Escáner">Escáner</option>
                        <option value="DVR">DVR</option>
                        <option value="Contadora Billetes">Contadora Billetes</option>
                        <option value="Contadora Monedas">Contadora Monedas</option>
                        <option value="Celular">Celular</option>
                        <option value="Impresora Térmica">Impresora Térmica</option>
                        <option value="Combo Teclado y Mouse">Combo Teclado y Mouse</option>
                        <option value="Diadema">Diadema</option>
                        <option value="Adaptador Multipuertos / Red">Adaptador Multipuertos / Red</option>
                        <option value="Router">Router</option>
                    </select>
                </div>
                <div class="col-md-4 mb-3"><label for="marca">Marca</label><input type="text" class="form-control" id="marca" name="marca" required></div>
                <div class="col-md-4 mb-3"><label for="serie">Serie</label><input type="text" class="form-control" id="serie" name="serie" required></div>
            </div>
            
            <div class="row mt-0"> 
                 <div class="col-md-4 mb-3">
                    <label for="estado">Estado</label>
                    <select class="form-select" name="estado" id="estado" required>
                        <option value="">Seleccione...</option>
                        <option value="Bueno">Bueno</option>
                        <option value="Regular">Regular</option>
                        <option value="Malo">Malo</option>
                    </select>
                </div>
                <div class="col-md-4 mb-3"><label for="valor">Valor Aprox.</label><input type="number" class="form-control" id="valor" name="valor" required step="0.01" min="0"></div>
                <div class="col-md-4 mb-3">
                    <label for="regional">Regional</label>
                    <select class="form-select" name="regional" id="regional" required>
                        <option value="">Seleccione...</option>
                        <option value="Popayan">Popayán</option>
                        <option value="Bordo">Bordó</option>
                        <option value="Santander">Santander</option>
                        <option value="Valle">Valle</option>
                        <option value="Pasto">Pasto</option>
                        <option value="Tuquerres">Túquerres</option>
                        <option value="Huila">Huila</option>
                        <option value="Nacional">Nacional</option>
                    </select>
                </div>
            </div>

            <div id="campos-computador" style="display: none;">
                 <hr>
                 <h6 class="mt-3">Detalles Específicos de Computador</h6>
                <div class="row mt-2">
                    <div class="col-md-3 mb-3" id="procesador-field"><label for="procesador">Procesador</label><input type="text" class="form-control" id="procesador" name="procesador"></div>
                    <div class="col-md-2 mb-3" id="ram-field"><label for="ram">Memoria RAM</label><input type="text" class="form-control" id="ram" name="ram"></div>
                    <div class="col-md-3 mb-3" id="disco-field"><label for="disco">Disco Duro</label><input type="text" class="form-control" id="disco" name="disco"></div>
                    <div class="col-md-2 mb-3" id="tipo-equipo-field">
                        <label for="tipo_equipo">Tipo Equipo</label>
                        <select class="form-select" name="tipo_equipo" id="tipo_equipo">
                            <option value="">Seleccione...</option>
                            <option value="Portátil">Portátil</option>
                            <option value="Mesa">Mesa</option>
                            <option value="Todo en 1">Todo en 1</option>
                        </select>
                    </div>
                    <div class="col-md-2 mb-3" id="red-field">
                        <label for="red">Red</label>
                        <select class="form-select" name="red" id="red">
                            <option value="">Seleccione</option>
                            <option value="Cableada">Cableada</option>
                            <option value="Inalámbrica">Inalámbrica</option>
                            <option value="Ambas">Ambas</option>
                        </select>
                    </div>
                </div>
                <div class="row mt-0"> 
                    <div class="col-md-4 mb-3" id="so-field">
                        <label for="so">Sistema Operativo</label>
                        <select class="form-select" name="so" id="so">
                            <option value="">Seleccione...</option>
                            <option value="Windows 10">Windows 10</option>
                            <option value="Windows 11">Windows 11</option>
                            <option value="Linux">Linux</option>
                            <option value="MacOS">MacOS</option>
                            <option value="Otro SO">Otro (Especificar en detalles)</option>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3" id="offimatica-field">
                        <label for="offimatica">Offimática</label>
                        <select class="form-select" name="offimatica" id="offimatica">
                            <option value="">Seleccione...</option>
                            <option value="Office 365">Office 365</option>
                            <option value="Office Home And Business">Office Home & Business</option>
                            <option value="Office 2021">Office 2021</option>
                            <option value="Office 2019">Office 2019</option>
                            <option value="Office 2016">Office 2016</option>
                            <option value="LibreOffice">LibreOffice</option>
                            <option value="Google Workspace">Google Workspace</option>
                            <option value="Otro Office">Otro</option>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3" id="antivirus-field">
                        <label for="antivirus">Antivirus</label>
                        <select class="form-select" name="antivirus" id="antivirus">
                            <option value="">Seleccione...</option>
                            <option value="Microsoft Defender">Microsoft Defender</option>
                            <option value="Bitdefender">Bitdefender</option>
                            <option value="ESET NOD32 Antivirus">ESET NOD32 Antivirus</option>
                            <option value="McAfee Total Protection">McAfee Total Protection</option>
                            <option value="Kaspersky">Kaspersky</option>
                            <option value="N/A Antivirus">Sin Antivirus</option>
                        </select>
                    </div>
                </div>
            </div>
           
            <div class="mb-3 mt-2"> 
                <label for="detalles">Detalles Adicionales del Activo</label>
                <textarea class="form-control" name="detalles" id="detalles" rows="2" placeholder="Si seleccionó 'Otro' en algún campo, especifique aquí. También otros detalles relevantes."></textarea>
            </div>
            <div class="mt-4 text-center"> 
                <button type="button" class="btn-form-action btn-guardar-activo" id="btnGuardarActivo" onclick="guardarActivoActual()">Guardar Activo</button>
                <button type="button" class="btn-form-action btn-finalizar" onclick="finalizarYNuevoUsuario()">Finalizar y Registrar Nuevo Usuario</button>
            </div>
        </form>
        <hr class="my-4"> 
        <h5 class="mt-3 text-center">Activos Guardados para este Usuario</h5>
        <div class="table-responsive mt-3">
            <table class="table table-sm table-striped table-hover" id="tabla-activos-guardados">
                <thead class="table-light"><tr><th>#</th><th>Tipo</th><th>Marca</th><th>Serie</th><th>Estado</th></tr></thead>
                <tbody>
                </tbody>
            </table>
        </div>
    </div>
 </div>
 <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
 </body>
 </html>