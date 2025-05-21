-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 20-05-2025 a las 00:44:23
-- Versión del servidor: 10.4.32-MariaDB
-- Versión de PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `inventario`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `activos_tecnologicos`
--

CREATE TABLE `activos_tecnologicos` (
  `id` int(11) NOT NULL,
  `cedula` varchar(20) DEFAULT NULL,
  `nombre` varchar(100) DEFAULT NULL,
  `cargo` varchar(100) DEFAULT NULL,
  `tipo_activo` varchar(50) DEFAULT NULL,
  `marca` varchar(100) DEFAULT NULL,
  `serie` varchar(100) DEFAULT NULL,
  `procesador` varchar(100) DEFAULT NULL,
  `ram` varchar(50) DEFAULT NULL,
  `disco_duro` varchar(100) DEFAULT NULL,
  `tipo_equipo` varchar(50) DEFAULT NULL,
  `red` varchar(50) DEFAULT NULL,
  `sistema_operativo` varchar(100) DEFAULT NULL,
  `offimatica` varchar(100) DEFAULT NULL,
  `antivirus` varchar(100) DEFAULT NULL,
  `estado` varchar(50) DEFAULT NULL,
  `valor_aproximado` decimal(10,2) DEFAULT NULL,
  `regional` varchar(50) DEFAULT NULL,
  `detalles` text DEFAULT NULL,
  `fecha_registro` timestamp NOT NULL DEFAULT current_timestamp(),
  `empresa` varchar(255) NULL,
  `Codigo_Inv` varchar(50) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `activos_tecnologicos`
--

INSERT INTO `activos_tecnologicos` (`id`, `cedula`, `nombre`, `cargo`, `tipo_activo`, `marca`, `serie`, `procesador`, `ram`, `disco_duro`, `tipo_equipo`, `red`, `sistema_operativo`, `offimatica`, `antivirus`, `estado`, `valor_aproximado`, `regional`, `detalles`, `fecha_registro`, `empresa`, `Codigo_Inv`) VALUES
(18, '25284515', 'Mary Murillo', 'Aux Contable', 'Computador', 'Asus', '123456', 'Core i5', '12 Gb', '512 Gb', 'Portátil', 'Ambas', 'Windows 10', 'Office 365', 'ESET NOD32 Antivirus', 'Regular', 2500000.00, 'Nacional', 'Bateria regular\r\nSe le instalo antivirus Eset Nod 32', '2025-05-13 21:30:24', '', ''),
(19, '25284515', 'Mary Murillo', 'Aux Contable', 'Monitor', 'LG', '7896', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Dado de Baja', 600000.00, 'Nacional', 'Se le hizo mantenimineto a la pantalla', '2025-05-13 21:30:40', '', ''),
(20, '25284515', 'Mary Murillo', 'Aux Contable', 'Combo Teclado y Mouse', 'Generico', '48522', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Bueno', 100000.00, 'Nacional', 'Bueno', '2025-05-13 21:30:59', '', ''),
(22, '25284515', 'Mary Murillo', 'Aux Contable', 'Diadema', 'Genius', '963314', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Bueno', 60000.00, 'Nacional', 'Buen estado', '2025-05-13 21:32:35', '', '');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `historial_activos`
--

CREATE TABLE `historial_activos` (
  `id_historial` int(11) NOT NULL,
  `id_activo` int(11) NOT NULL,
  `fecha_evento` datetime DEFAULT current_timestamp(),
  `tipo_evento` varchar(50) NOT NULL COMMENT 'Ej: CREACIÓN, ACTUALIZACIÓN, TRASLADO, BAJA, MANTENIMIENTO',
  `descripcion_evento` text NOT NULL COMMENT 'Detalles del evento. Puede incluir JSON con cambios específicos.',
  `usuario_responsable` varchar(255) DEFAULT NULL COMMENT 'Usuario del sistema que realizó la acción',
  `datos_anteriores` text DEFAULT NULL COMMENT 'JSON con los valores de los campos antes del cambio',
  `datos_nuevos` text DEFAULT NULL COMMENT 'JSON con los valores de los campos después del cambio (o los datos del traslado/asignación)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `historial_activos`
--

INSERT INTO `historial_activos` (`id_historial`, `id_activo`, `fecha_evento`, `tipo_evento`, `descripcion_evento`, `usuario_responsable`, `datos_anteriores`, `datos_nuevos`) VALUES
(1, 19, '2025-05-15 14:41:30', 'ACTUALIZACIÓN', 'Actualización de campos: Detalles: de \'Bueno\' a \'Se le hizo mantenimineto a la pantalla\'.', 'admin', '{\"detalles\":\"Bueno\"}', '{\"detalles\":\"Se le hizo mantenimineto a la pantalla\"}'),
(2, 18, '2025-05-15 14:58:18', 'ACTUALIZACIÓN', 'Actualización de campos: Detalles: de \'Bateria regulatr\' a \'Bateria regular\r\nSe le instalo antivirus Eset Nod 32\'; Antivirus: de \'\' a \'ESET NOD32 Antivirus\'.', 'admin', '{\"detalles\":\"Bateria regulatr\",\"antivirus\":\"\"}', '{\"detalles\":\"Bateria regular\\r\\nSe le instalo antivirus Eset Nod 32\",\"antivirus\":\"ESET NOD32 Antivirus\"}'),
(3, 19, '2025-05-15 15:30:53', 'BAJA', 'Activo dado de baja. Motivo: Fin de vida útil. Observaciones: El equipo ya tenia mas de 11 años en uso y  no es posible actualizaciones', 'admin', '{\"id\":19,\"cedula\":\"25284515\",\"nombre\":\"Mary Murillo\",\"cargo\":\"Aux Contable\",\"tipo_activo\":\"Monitor\",\"marca\":\"LG\",\"serie\":\"7896\",\"procesador\":null,\"ram\":null,\"disco_duro\":null,\"tipo_equipo\":null,\"red\":null,\"sistema_operativo\":null,\"offimatica\":null,\"antivirus\":null,\"estado\":\"Bueno\",\"valor_aproximado\":\"600000.00\",\"regional\":\"Nacional\",\"detalles\":\"Se le hizo mantenimineto a la pantalla\",\"fecha_registro\":\"2025-05-13 16:30:40\",\"Empresa\":\"\",\"Codigo_Inv\":\"\"}', '{\"estado_anterior\":\"Bueno\",\"motivo_baja\":\"Fin de vida útil\",\"observaciones_baja\":\"El equipo ya tenia mas de 11 años en uso y  no es posible actualizaciones\",\"fecha_efectiva_baja\":\"2025-05-15 22:30:53\"}');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuarios`
--

CREATE TABLE `usuarios` (
  `id` int(11) NOT NULL,
  `usuario` varchar(50) NOT NULL,
  `clave` varchar(255) NOT NULL,
  `nombre_completo` varchar(100) NOT NULL,
  `rol` enum('admin','tecnico','auditor') NOT NULL DEFAULT 'tecnico',
  `activo` tinyint(1) DEFAULT 1 COMMENT '1 para activo, 0 para inactivo',
  `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `usuarios`
--

INSERT INTO `usuarios` (`id`, `usuario`, `clave`, `nombre_completo`, `rol`, `activo`, `fecha_creacion`) VALUES
(1, 'admin', '$2y$10$sXsmH0LMTEK.sq5kpphwV.O2wCQYOoiPknlpkzInmhF8hFrLI4dX6', 'Administrador Principal', 'admin', 1, '2025-05-19 16:25:22'),
(2, 'tecnico', '$2y$10$kzAI8nD9/5VI8u1ksn772O3U6vl1/TGWPz24JH4Qg.TFNKVjBRVgi', 'Pasante Sena', 'tecnico', 1, '2025-05-19 20:31:13'),
(3, 'auditoria', '$2y$10$zWWgm12ajVOZbm6/0kAYl.OW7/txrHQop1JnIXiehAsGincJANvSG', 'Auditor Arpesod', 'auditor', 1, '2025-05-19 20:31:37'),
(4, 'auditoria1', '$2y$10$KVETs553WyJ/CGwOKBaxpOB8X01IFoWQVjMMUwPt4568Pbs24j41C', 'Auditor', 'auditor', 1, '2025-05-19 22:23:09');

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `activos_tecnologicos`
--
ALTER TABLE `activos_tecnologicos`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `historial_activos`
--
ALTER TABLE `historial_activos`
  ADD PRIMARY KEY (`id_historial`),
  ADD KEY `id_activo` (`id_activo`);

--
-- Indices de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `usuario` (`usuario`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `activos_tecnologicos`
--
ALTER TABLE `activos_tecnologicos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT de la tabla `historial_activos`
--
ALTER TABLE `historial_activos`
  MODIFY `id_historial` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `historial_activos`
--
ALTER TABLE `historial_activos`
  ADD CONSTRAINT `historial_activos_ibfk_1` FOREIGN KEY (`id_activo`) REFERENCES `activos_tecnologicos` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
