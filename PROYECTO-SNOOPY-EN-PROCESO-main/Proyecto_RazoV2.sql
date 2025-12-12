DROP DATABASE IF EXISTS concurso_robotica;
CREATE DATABASE concurso_robotica;
USE concurso_robotica;

-- =============================================
-- 1. TABLAS (ESTRUCTURA)
-- =============================================

CREATE TABLE usuarios (
    id_usuario INT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    nombres VARCHAR(100) NOT NULL,
    apellidos VARCHAR(100) NOT NULL,
    escuela_proc varchar (150) not null,
    tipo_usuario ENUM('ADMIN', 'JUEZ', 'COACH', 'COACH_JUEZ') NOT NULL,
    fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP,
    activo BOOLEAN DEFAULT TRUE
);

CREATE TABLE eventos (
    id_evento INT PRIMARY KEY AUTO_INCREMENT,
    nombre_evento VARCHAR(200) NOT NULL,
    fecha_evento DATE NOT NULL,
    lugar VARCHAR(200) NOT NULL,
    activo BOOLEAN DEFAULT TRUE
);

CREATE TABLE categorias (
    id_categoria INT PRIMARY KEY AUTO_INCREMENT,
    nombre_categoria VARCHAR(50) NOT NULL UNIQUE,
    edad_minima INT NOT NULL,
    edad_maxima INT NOT NULL
);

CREATE TABLE equipos (
    id_equipo INT PRIMARY KEY AUTO_INCREMENT,
    nombre_equipo VARCHAR(150) NOT NULL,
    nombre_prototipo VARCHAR(200) NOT NULL,            
    id_evento INT NOT NULL,
    id_categoria INT NOT NULL,
    id_coach INT NOT NULL,
    escuela_procedencia varchar (150) not null,
    descripcion_proyecto TEXT,
    estado_proyecto ENUM('PENDIENTE', 'EVALUADO', 'DESCALIFICADO') DEFAULT 'PENDIENTE', 
    fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP,
    activo BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (id_evento) REFERENCES eventos(id_evento),
    FOREIGN KEY (id_categoria) REFERENCES categorias(id_categoria),
    FOREIGN KEY (id_coach) REFERENCES usuarios(id_usuario)
);

CREATE TABLE integrantes (
    id_integrante INT PRIMARY KEY AUTO_INCREMENT,
    id_equipo INT NOT NULL,
    nombre_completo VARCHAR(150) NOT NULL,
    edad INT NOT NULL,
    grado INT NOT NULL DEFAULT 1,
    escuela VARCHAR(150),
    FOREIGN KEY (id_equipo) REFERENCES equipos(id_equipo) ON DELETE CASCADE
);

CREATE TABLE evaluaciones (
    id_evaluacion INT PRIMARY KEY AUTO_INCREMENT,
    id_equipo INT NOT NULL, 
    id_juez INT NOT NULL,  
    fecha_evaluacion DATETIME DEFAULT CURRENT_TIMESTAMP,
    puntuacion_total INT,  
    FOREIGN KEY (id_equipo) REFERENCES equipos(id_equipo) ON DELETE CASCADE,
    FOREIGN KEY (id_juez) REFERENCES usuarios(id_usuario),
    UNIQUE KEY unique_evaluacion_equipo (id_equipo) 
);

CREATE TABLE jueces_eventos (
    id_asignacion INT PRIMARY KEY AUTO_INCREMENT,
    id_evento INT NOT NULL,
    id_juez INT NOT NULL,
    id_categoria INT NOT NULL,
    fecha_asignacion DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_evento) REFERENCES eventos(id_evento) ON DELETE CASCADE,
    FOREIGN KEY (id_juez) REFERENCES usuarios(id_usuario),
    FOREIGN KEY (id_categoria) REFERENCES categorias(id_categoria),
    UNIQUE KEY unique_juez_evento_categoria (id_evento, id_juez, id_categoria)
);

-- Datos Semilla (Categorías Base)
INSERT INTO categorias(nombre_categoria,edad_minima,edad_maxima) VALUES
('PRIMARIA',6,12),('SECUNDARIA',13,15),('PREPARATORIA',16,18),('UNIVERSIDAD',18,25);

-- Usuario Admin por defecto
INSERT INTO usuarios(email,password_hash,nombres,apellidos,escuela_proc,tipo_usuario) VALUES
('admin@robotica.com','admin123','Admin','Sistema','SISTEMA','ADMIN');

-- =============================================
-- 2. FUNCIONES DE VALIDACIÓN
-- =============================================

DELIMITER //

CREATE FUNCTION VerificarEquipoRepetido(p_nombre_equipo VARCHAR(150), p_id_evento INT, p_id_categoria INT) 
RETURNS BOOLEAN DETERMINISTIC READS SQL DATA
BEGIN
    DECLARE existe BOOLEAN DEFAULT FALSE;
    -- Valida nombre repetido dentro del MISMO evento y la MISMA categoría
    SELECT COUNT(*) > 0 INTO existe FROM equipos 
    WHERE nombre_equipo = p_nombre_equipo 
      AND id_evento = p_id_evento 
      AND id_categoria = p_id_categoria 
      AND activo = TRUE;
    RETURN existe;
END//

CREATE FUNCTION VerificarEdadCategoria(p_edad INT, p_id_categoria INT)
RETURNS BOOLEAN DETERMINISTIC READS SQL DATA
BEGIN
    DECLARE emin INT; DECLARE emax INT;
    SELECT edad_minima, edad_maxima INTO emin, emax FROM categorias WHERE id_categoria = p_id_categoria;
    RETURN p_edad BETWEEN emin AND emax;
END//

CREATE FUNCTION VerificarGradoCategoria(p_grado INT, p_id_categoria INT)
RETURNS BOOLEAN DETERMINISTIC READS SQL DATA
BEGIN
    DECLARE cat_nombre VARCHAR(50);
    SELECT nombre_categoria INTO cat_nombre FROM categorias WHERE id_categoria = p_id_categoria;
    
    IF cat_nombre = 'PRIMARIA' THEN RETURN p_grado BETWEEN 1 AND 6;
    ELSEIF cat_nombre = 'SECUNDARIA' THEN RETURN p_grado BETWEEN 1 AND 3;
    ELSEIF cat_nombre = 'PREPARATORIA' THEN RETURN p_grado BETWEEN 1 AND 6;
    ELSEIF cat_nombre = 'UNIVERSIDAD' THEN RETURN p_grado BETWEEN 1 AND 14;
    ELSE RETURN TRUE;
    END IF;
END //

DELIMITER ;

-- =============================================
-- 3. PROCEDIMIENTOS ALMACENADOS (LÓGICA DEL SISTEMA)
-- =============================================

DELIMITER //

-- --- LOGIN Y REGISTRO ---

CREATE PROCEDURE sp_ObtenerDatosLogin(IN p_email VARCHAR(100), IN p_tipo VARCHAR(20))
BEGIN
    SELECT id_usuario, password_hash, nombres, apellidos, tipo_usuario, email, activo 
    FROM usuarios WHERE email = p_email AND (tipo_usuario = p_tipo OR tipo_usuario = 'COACH_JUEZ' OR p_tipo = 'ADMIN');
END //

CREATE PROCEDURE RegistrarUsuario(
    IN p_email VARCHAR(100), IN p_password_hash VARCHAR(255),
    IN p_nombres VARCHAR(100), IN p_apellidos VARCHAR(100),
    IN p_tipo_usuario ENUM('ADMIN','JUEZ','COACH'), IN p_escuela_proc VARCHAR(150),
    OUT p_resultado VARCHAR(255), OUT p_usuario_id INT
)
BEGIN
    IF EXISTS (SELECT 1 FROM usuarios WHERE email = p_email) THEN
        SET p_resultado = 'ERROR: Email ya registrado'; SET p_usuario_id = 0;
    ELSE
        INSERT INTO usuarios(email, password_hash, nombres, apellidos, escuela_proc, tipo_usuario)
        VALUES(p_email, p_password_hash, p_nombres, p_apellidos, p_escuela_proc, p_tipo_usuario);
        SET p_usuario_id = LAST_INSERT_ID();
        SET p_resultado = 'ÉXITO: Usuario registrado';
    END IF;
END //

CREATE PROCEDURE ObtenerUsuarioPorEmail(IN p_email VARCHAR(100))
BEGIN
    SELECT id_usuario, email, nombres, apellidos, tipo_usuario, activo FROM usuarios WHERE email = p_email;
END //

-- --- GESTIÓN DE EQUIPOS (COACH) ---

CREATE PROCEDURE RegistrarEquipo(
    IN p_nombre VARCHAR(150), IN p_prototipo VARCHAR(200),
    IN p_id_evento INT, IN p_id_categoria INT, IN p_id_coach INT,
    OUT p_resultado VARCHAR(255), OUT p_equipo_id INT
)
BEGIN
    DECLARE v_escuela VARCHAR(150);
    SELECT escuela_proc INTO v_escuela FROM usuarios WHERE id_usuario = p_id_coach;

    IF VerificarEquipoRepetido(p_nombre, p_id_evento, p_id_categoria) THEN
        SET p_resultado = 'ERROR: Nombre de equipo duplicado en este evento'; SET p_equipo_id = 0;
    ELSE
        INSERT INTO equipos(nombre_equipo, nombre_prototipo, id_evento, id_categoria, id_coach, escuela_procedencia)
        VALUES(p_nombre, p_prototipo, p_id_evento, p_id_categoria, p_id_coach, v_escuela);
        SET p_equipo_id = LAST_INSERT_ID();
        SET p_resultado = 'ÉXITO: Equipo creado';
    END IF;
END //

CREATE PROCEDURE AgregarIntegrante(
    IN p_id_equipo INT, IN p_nombre VARCHAR(150), IN p_edad INT, IN p_grado INT,
    OUT p_resultado VARCHAR(255)
)
BEGIN
    DECLARE v_total INT; DECLARE v_cat INT; DECLARE v_escuela VARCHAR(150);
    SELECT COUNT(*) INTO v_total FROM integrantes WHERE id_equipo = p_id_equipo;
    
    IF v_total >= 3 THEN SET p_resultado = 'ERROR: El equipo ya está lleno (3)';
    ELSE
        SELECT id_categoria, escuela_procedencia INTO v_cat, v_escuela FROM equipos WHERE id_equipo = p_id_equipo;
        
        IF NOT VerificarEdadCategoria(p_edad, v_cat) THEN SET p_resultado = 'ERROR: Edad no permitida para la categoría';
        ELSEIF NOT VerificarGradoCategoria(p_grado, v_cat) THEN SET p_resultado = 'ERROR: Grado escolar no válido';
        ELSE
            INSERT INTO integrantes(id_equipo, nombre_completo, edad, grado, escuela)
            VALUES(p_id_equipo, p_nombre, p_edad, p_grado, v_escuela);
            SET p_resultado = 'ÉXITO: Integrante agregado';
        END IF;
    END IF;
END //

CREATE PROCEDURE ListarDetalleEquiposPorCoach(IN p_id_coach INT)
BEGIN
    SELECT e.id_equipo, e.nombre_equipo, e.nombre_prototipo, ev.nombre_evento, c.nombre_categoria, e.estado_proyecto,
           (SELECT COUNT(*) FROM integrantes i WHERE i.id_equipo = e.id_equipo) as total_integrantes,
           (SELECT GROUP_CONCAT(nombre_completo SEPARATOR ', ') FROM integrantes i WHERE i.id_equipo = e.id_equipo) as nombres_integrantes
    FROM equipos e
    JOIN eventos ev ON e.id_evento = ev.id_evento
    JOIN categorias c ON e.id_categoria = c.id_categoria
    WHERE e.id_coach = p_id_coach AND e.activo = TRUE
    ORDER BY e.id_equipo DESC;
END //

CREATE PROCEDURE ListarIntegrantesPorEquipo(IN p_id_equipo INT)
BEGIN
    SELECT nombre_completo, edad, grado FROM integrantes WHERE id_equipo = p_id_equipo;
END //

-- --- GESTIÓN DE EVENTOS Y JUECES (ADMIN) ---

-- *** SP MODIFICADO: VALIDACIÓN DE FECHA, LUGAR Y NOMBRE ***
CREATE PROCEDURE CrearEvento(IN p_nombre VARCHAR(200), IN p_fecha DATE, IN p_lugar VARCHAR(200), OUT p_resultado VARCHAR(255))
BEGIN
    -- 1. Validar que la fecha no sea anterior a la actual
    IF p_fecha < CURDATE() THEN
        SET p_resultado = 'ERROR: La fecha del evento no puede ser anterior a la actual';
        
    -- 2. Validar Nombre Duplicado (Solo activos)
    ELSEIF EXISTS (SELECT 1 FROM eventos WHERE nombre_evento = p_nombre AND activo = TRUE) THEN
        SET p_resultado = 'ERROR: Ya existe un evento activo con ese nombre';
        
    -- 3. Validar Mismo Lugar y Fecha (Solo activos)
    ELSEIF EXISTS (SELECT 1 FROM eventos WHERE fecha_evento = p_fecha AND lugar = p_lugar AND activo = TRUE) THEN
        SET p_resultado = 'ERROR: Ya existe un evento programado en ese lugar para esa fecha';
        
    ELSE
        INSERT INTO eventos (nombre_evento, fecha_evento, lugar, activo) VALUES (p_nombre, p_fecha, p_lugar, TRUE);
        SET p_resultado = 'ÉXITO: Evento creado';
    END IF;
END //

CREATE PROCEDURE EliminarEvento(IN p_id_evento INT, OUT p_resultado VARCHAR(255))
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; SET p_resultado = 'ERROR: Fallo al eliminar'; END;
    START TRANSACTION;
        -- Borrado en cascada manual para asegurar limpieza
        DELETE FROM evaluaciones WHERE id_equipo IN (SELECT id_equipo FROM equipos WHERE id_evento = p_id_evento);
        DELETE FROM integrantes WHERE id_equipo IN (SELECT id_equipo FROM equipos WHERE id_evento = p_id_evento);
        DELETE FROM jueces_eventos WHERE id_evento = p_id_evento;
        DELETE FROM equipos WHERE id_evento = p_id_evento;
        DELETE FROM eventos WHERE id_evento = p_id_evento;
    COMMIT;
    SET p_resultado = 'ÉXITO: Evento eliminado';
END //

CREATE PROCEDURE Sp_AdminListarEventos()
BEGIN
    SELECT id_evento, nombre_evento, fecha_evento, lugar FROM eventos ORDER BY fecha_evento DESC;
END //

CREATE PROCEDURE ListarJuecesDisponibles()
BEGIN
    SELECT id_usuario, CONCAT(nombres, ' ', apellidos) as nombre_completo 
    FROM usuarios WHERE (tipo_usuario = 'JUEZ' OR tipo_usuario = 'COACH_JUEZ') AND activo = TRUE;
END //

CREATE PROCEDURE PromoverCoachAJuez(IN p_id_usuario INT, OUT p_resultado VARCHAR(255))
BEGIN
    UPDATE usuarios SET tipo_usuario = 'COACH_JUEZ' WHERE id_usuario = p_id_usuario AND tipo_usuario = 'COACH';
    IF ROW_COUNT() > 0 THEN SET p_resultado = 'ÉXITO: Coach promovido';
    ELSE SET p_resultado = 'ERROR: No se pudo promover o ya es juez'; END IF;
END //

CREATE PROCEDURE AsignarJuezEvento(IN p_id_evento INT, IN p_id_juez INT, IN p_id_categoria INT, OUT p_resultado VARCHAR(255))
BEGIN
    DECLARE v_escuela_juez VARCHAR(150); DECLARE v_count INT;
    SELECT escuela_proc INTO v_escuela_juez FROM usuarios WHERE id_usuario = p_id_juez;
    
    -- Validaciones de Negocio (Reglas Estrictas)
    IF (SELECT COUNT(*) FROM jueces_eventos WHERE id_evento = p_id_evento AND id_categoria = p_id_categoria) >= 3 THEN
        SET p_resultado = 'ERROR: Límite de 3 jueces alcanzado';
    ELSEIF EXISTS (SELECT 1 FROM jueces_eventos WHERE id_evento=p_id_evento AND id_juez=p_id_juez AND id_categoria=p_id_categoria) THEN
        SET p_resultado = 'ADVERTENCIA: Juez ya asignado';
    ELSEIF EXISTS (SELECT 1 FROM equipos WHERE id_coach = p_id_juez AND id_evento = p_id_evento AND id_categoria = p_id_categoria) THEN
        SET p_resultado = 'ERROR: Conflicto de interés (Tiene equipo propio)';
    ELSEIF EXISTS (SELECT 1 FROM equipos WHERE escuela_procedencia = v_escuela_juez AND id_evento = p_id_evento AND id_categoria = p_id_categoria) THEN
        SET p_resultado = 'ERROR: Conflicto de interés (Misma escuela)';
    ELSE
        INSERT INTO jueces_eventos(id_evento, id_juez, id_categoria) VALUES(p_id_evento, p_id_juez, p_id_categoria);
        SET p_resultado = 'ÉXITO: Juez asignado';
    END IF;
END //

CREATE PROCEDURE Sp_ListarJuecesDeEvento(IN p_id_evento INT)
BEGIN
    SELECT je.id_evento, u.id_usuario, u.nombres, u.apellidos, u.escuela_proc, c.id_categoria, c.nombre_categoria 
    FROM jueces_eventos je 
    JOIN usuarios u ON je.id_juez = u.id_usuario 
    JOIN categorias c ON je.id_categoria = c.id_categoria 
    WHERE je.id_evento = p_id_evento ORDER BY c.nombre_categoria;
END //

CREATE PROCEDURE QuitarJuezEvento(IN p_id_evento INT, IN p_id_juez INT, IN p_id_categoria INT)
BEGIN
    DELETE FROM jueces_eventos WHERE id_evento = p_id_evento AND id_juez = p_id_juez AND id_categoria = p_id_categoria;
END //

-- --- EVALUACIÓN (JUEZ) ---

CREATE PROCEDURE Sp_Juez_ObtenerCategoriasAsignadas(IN p_id_juez INT)
BEGIN
    SELECT DISTINCT c.nombre_categoria FROM jueces_eventos je JOIN categorias c ON je.id_categoria = c.id_categoria WHERE je.id_juez = p_id_juez;
END //

CREATE PROCEDURE Sp_Juez_ListarProyectos(IN p_id_juez INT, IN p_nombre_categoria VARCHAR(50))
BEGIN
    DECLARE v_escuela_juez VARCHAR(150);
    SELECT escuela_proc INTO v_escuela_juez FROM usuarios WHERE id_usuario = p_id_juez;

    SELECT e.id_equipo, e.nombre_equipo, e.nombre_prototipo, e.estado_proyecto
    FROM equipos e
    JOIN categorias c ON e.id_categoria = c.id_categoria
    JOIN jueces_eventos je ON e.id_evento = je.id_evento AND e.id_categoria = je.id_categoria
    WHERE je.id_juez = p_id_juez AND c.nombre_categoria = p_nombre_categoria AND e.activo = TRUE
      -- Filtros estrictos de visualización
      AND (SELECT COUNT(*) FROM integrantes i WHERE i.id_equipo = e.id_equipo) = 3
      AND e.escuela_procedencia <> v_escuela_juez
      AND e.id_coach <> p_id_juez
    ORDER BY FIELD(e.estado_proyecto, 'PENDIENTE', 'EVALUADO'), e.nombre_equipo;
END //

-- --- REPORTES Y UTILIDADES ---

CREATE PROCEDURE Sp_ListarNombresEventos()
BEGIN SELECT nombre_evento FROM eventos WHERE activo = TRUE ORDER BY fecha_evento DESC; END //

CREATE PROCEDURE Sp_ListarNombresCategorias()
BEGIN SELECT nombre_categoria FROM categorias ORDER BY id_categoria; END //

CREATE PROCEDURE ObtenerIdEventoPorNombre(IN p_nombre VARCHAR(200))
BEGIN SELECT id_evento FROM eventos WHERE nombre_evento = p_nombre LIMIT 1; END //

CREATE PROCEDURE ObtenerIdCategoriaPorNombre(IN p_nombre VARCHAR(50))
BEGIN SELECT id_categoria FROM categorias WHERE nombre_categoria = p_nombre LIMIT 1; END //

-- Reportes Dinámicos (Sin Vistas)
CREATE PROCEDURE Sp_ReporteTop3(IN p_nombre_evento VARCHAR(200))
BEGIN
    SELECT RANK() OVER (PARTITION BY c.id_categoria ORDER BY eva.puntuacion_total DESC) as posicion,
           ev.nombre_evento, c.nombre_categoria, e.nombre_equipo, e.nombre_prototipo as nombre_proyecto,
           eva.puntuacion_total, CONCAT(u.nombres, ' ', u.apellidos) as nombre_coach
    FROM evaluaciones eva
    JOIN equipos e ON eva.id_equipo = e.id_equipo
    JOIN categorias c ON e.id_categoria = c.id_categoria
    JOIN eventos ev ON e.id_evento = ev.id_evento
    JOIN usuarios u ON e.id_coach = u.id_usuario
    WHERE (p_nombre_evento IS NULL OR p_nombre_evento = 'Todos los eventos' OR ev.nombre_evento = p_nombre_evento)
    ORDER BY c.nombre_categoria, posicion LIMIT 20;
END //

CREATE PROCEDURE Sp_ReporteEquipos(IN p_nombre_evento VARCHAR(200), IN p_nombre_categoria VARCHAR(50))
BEGIN
    SELECT c.nombre_categoria, ev.nombre_evento, e.nombre_equipo, e.nombre_prototipo AS nombre_proyecto,
           e.estado_proyecto, CONCAT(u.nombres, ' ', u.apellidos) AS nombre_coach,
           (SELECT COUNT(*) FROM integrantes i WHERE i.id_equipo = e.id_equipo) AS total_integrantes
    FROM equipos e
    JOIN categorias c ON e.id_categoria = c.id_categoria
    JOIN eventos ev ON e.id_evento = ev.id_evento
    JOIN usuarios u ON e.id_coach = u.id_usuario
    WHERE e.activo = TRUE
      AND (p_nombre_evento IS NULL OR p_nombre_evento = 'Todos los eventos' OR ev.nombre_evento = p_nombre_evento)
      AND (p_nombre_categoria IS NULL OR p_nombre_categoria = 'Todas las categorías' OR c.nombre_categoria = p_nombre_categoria)
    ORDER BY c.nombre_categoria, e.nombre_equipo;
END //

CREATE PROCEDURE Sp_ReporteEstadisticas()
BEGIN
    SELECT ev.nombre_evento, ev.lugar, ev.fecha_evento,
           COUNT(e.id_equipo) as total_equipos,
           SUM(CASE WHEN e.estado_proyecto = 'EVALUADO' THEN 1 ELSE 0 END) as equipos_evaluados,
           SUM(CASE WHEN e.estado_proyecto = 'PENDIENTE' THEN 1 ELSE 0 END) as equipos_pendientes,
           (SELECT COUNT(*) FROM integrantes i JOIN equipos eq ON i.id_equipo = eq.id_equipo WHERE eq.id_evento = ev.id_evento) as total_participantes
    FROM eventos ev
    LEFT JOIN equipos e ON ev.id_evento = e.id_evento AND e.activo = TRUE
    WHERE ev.activo = TRUE
    GROUP BY ev.id_evento;
END //

CREATE PROCEDURE Sp_ObtenerResumenGeneral()
BEGIN
    SELECT 
        (SELECT COUNT(*) FROM equipos WHERE activo=1) as total_equipos,
        (SELECT COUNT(*) FROM integrantes) as total_participantes,
        (SELECT COUNT(*) FROM equipos WHERE estado_proyecto='EVALUADO') as evaluados,
        (SELECT COUNT(*) FROM eventos WHERE activo=1) as eventos_activos;
END //


-- --------------------------------------------------------------------------------
-- NUEVOS PROCEDIMIENTOS DE GESTIÓN DE INTEGRANTES
-- --------------------------------------------------------------------------------
DELIMITER //
-- Listar integrantes (Actualizado para devolver ID)
DROP PROCEDURE IF EXISTS ListarIntegrantesPorEquipo;
CREATE PROCEDURE ListarIntegrantesPorEquipo(IN p_id_equipo INT)
BEGIN
    SELECT id_integrante, nombre_completo, edad, grado, escuela 
    FROM integrantes 
    WHERE id_equipo = p_id_equipo;
END //
DELIMITER ;

-- Eliminar integrante con seguridad (Verifica dueño del equipo)
DELIMITER //
DROP PROCEDURE IF EXISTS EliminarIntegrante;
CREATE PROCEDURE EliminarIntegrante(
    IN p_id_integrante INT, 
    IN p_id_coach INT, 
    OUT p_resultado VARCHAR(255)
)
BEGIN
    DECLARE v_id_equipo INT;
    DECLARE v_owner_coach INT;
    
    -- Obtener el equipo al que pertenece el integrante
    SELECT id_equipo INTO v_id_equipo FROM integrantes WHERE id_integrante = p_id_integrante;
    
    IF v_id_equipo IS NULL THEN
        SET p_resultado = 'ERROR: Integrante no encontrado';
    ELSE
        -- Verificar quién es el coach dueño del equipo
        SELECT id_coach INTO v_owner_coach FROM equipos WHERE id_equipo = v_id_equipo;
        
        IF v_owner_coach = p_id_coach THEN
            DELETE FROM integrantes WHERE id_integrante = p_id_integrante;
            SET p_resultado = 'ÉXITO: Integrante eliminado';
        ELSE
            SET p_resultado = 'ERROR: No tienes permiso para eliminar este integrante';
        END IF;
    END IF;
END //
DELIMITER ;

DELIMITER //
-- Editar integrante (Opcional, por si se requiere editar en lugar de borrar/agregar)
DROP PROCEDURE IF EXISTS EditarIntegrante;
CREATE PROCEDURE EditarIntegrante(
    IN p_id_integrante INT,
    IN p_id_coach INT,
    IN p_nombre VARCHAR(150),
    IN p_edad INT,
    IN p_grado INT,
    OUT p_resultado VARCHAR(255)
)
BEGIN
    DECLARE v_id_equipo INT;
    DECLARE v_owner_coach INT;
    DECLARE v_cat INT;
    
    SELECT id_equipo INTO v_id_equipo FROM integrantes WHERE id_integrante = p_id_integrante;
    
    IF v_id_equipo IS NULL THEN
        SET p_resultado = 'ERROR: Integrante no encontrado';
    ELSE
        SELECT id_coach, id_categoria INTO v_owner_coach, v_cat FROM equipos WHERE id_equipo = v_id_equipo;
        
        IF v_owner_coach = p_id_coach THEN
            -- Revalidamos reglas de negocio
            IF NOT VerificarEdadCategoria(p_edad, v_cat) THEN 
                SET p_resultado = 'ERROR: Edad no permitida para la categoría';
            ELSEIF NOT VerificarGradoCategoria(p_grado, v_cat) THEN 
                SET p_resultado = 'ERROR: Grado escolar no válido';
            ELSE
                UPDATE integrantes 
                SET nombre_completo = p_nombre, edad = p_edad, grado = p_grado
                WHERE id_integrante = p_id_integrante;
                SET p_resultado = 'ÉXITO: Integrante actualizado';
            END IF;
        ELSE
            SET p_resultado = 'ERROR: No tienes permiso para editar este integrante';
        END IF;
    END IF;
END //
-- --------------------------------------------------------------------------------

DELIMITER ;


-- PROCEDIMIENTO PARA LA EVALUACIÓN 
DELIMITER //

CREATE PROCEDURE RegistrarEvaluacion(
    IN p_id_equipo INT,
    IN p_id_juez INT,
    IN p_total INT,
    OUT p_resultado VARCHAR(255)
)
BEGIN
    DECLARE v_existe INT;

    -- Verificar si ya existe una evaluación para este equipo (Dado que id_equipo es UNIQUE en la tabla evaluaciones)
    SELECT COUNT(*) INTO v_existe FROM evaluaciones WHERE id_equipo = p_id_equipo;

    IF v_existe > 0 THEN
        -- Si ya existe, actualizamos (Opcional: podrías bloquearlo si no quieres permitir re-evaluación)
        UPDATE evaluaciones 
        SET puntuacion_total = p_total, 
            id_juez = p_id_juez, 
            fecha_evaluacion = NOW()
        WHERE id_equipo = p_id_equipo;
        
        SET p_resultado = 'ÉXITO: Evaluación actualizada correctamente.';
    ELSE
        -- Insertar nueva evaluación
        INSERT INTO evaluaciones(id_equipo, id_juez, puntuacion_total)
        VALUES(p_id_equipo, p_id_juez, p_total);
        
        -- Actualizar estado del equipo a EVALUADO
        UPDATE equipos SET estado_proyecto = 'EVALUADO' WHERE id_equipo = p_id_equipo;
        
        SET p_resultado = 'ÉXITO: Evaluación registrada correctamente.';
    END IF;
END //

DELIMITER ;


USE concurso_robotica;

-- 1. Agregar columna para guardar el detalle de los checkboxes (JSON)
ALTER TABLE evaluaciones ADD COLUMN detalles_evaluacion JSON DEFAULT NULL;

-- 2. Actualizar el procedimiento almacenado para recibir los detalles
DROP PROCEDURE IF EXISTS RegistrarEvaluacion;

DELIMITER //
CREATE PROCEDURE RegistrarEvaluacion(
    IN p_id_equipo INT,
    IN p_id_juez INT,
    IN p_total INT,
    IN p_detalles JSON,  -- Nuevo parámetro
    OUT p_resultado VARCHAR(255)
)
BEGIN
    DECLARE v_existe INT;

    SELECT COUNT(*) INTO v_existe FROM evaluaciones WHERE id_equipo = p_id_equipo;

    IF v_existe > 0 THEN
        UPDATE evaluaciones 
        SET puntuacion_total = p_total, 
            id_juez = p_id_juez, 
            detalles_evaluacion = p_detalles, -- Guardamos el JSON
            fecha_evaluacion = NOW()
        WHERE id_equipo = p_id_equipo;
        
        SET p_resultado = 'ÉXITO: Evaluación actualizada correctamente.';
    ELSE
        INSERT INTO evaluaciones(id_equipo, id_juez, puntuacion_total, detalles_evaluacion)
        VALUES(p_id_equipo, p_id_juez, p_total, p_detalles);
        
        UPDATE equipos SET estado_proyecto = 'EVALUADO' WHERE id_equipo = p_id_equipo;
        
        SET p_resultado = 'ÉXITO: Evaluación registrada correctamente.';
    END IF;
END //
DELIMITER ;

DELIMITER //

DROP PROCEDURE IF EXISTS Sp_Admin_ListarUsuariosCandidatos;

CREATE PROCEDURE Sp_Admin_ListarUsuariosCandidatos()
BEGIN
    -- Aumentamos el límite de caracteres para evitar que la lista de categorías se corte
    SET SESSION group_concat_max_len = 10000;

    SELECT 
        u.id_usuario, 
        u.nombres, 
        u.apellidos, 
        u.email, 
        u.escuela_proc, 
        u.tipo_usuario,
        -- Subconsulta para categorías donde es Coach (equipos activos)
        (SELECT GROUP_CONCAT(DISTINCT c.nombre_categoria SEPARATOR ', ')
         FROM equipos e
         JOIN categorias c ON e.id_categoria = c.id_categoria
         WHERE e.id_coach = u.id_usuario AND e.activo = 1) as categorias_equipos,
        -- Subconsulta para categorías donde es Juez
        (SELECT GROUP_CONCAT(DISTINCT c.nombre_categoria SEPARATOR ', ')
         FROM jueces_eventos je
         JOIN categorias c ON je.id_categoria = c.id_categoria
         WHERE je.id_juez = u.id_usuario) as categorias_juez
    FROM usuarios u 
    WHERE (u.tipo_usuario = 'COACH' OR u.tipo_usuario = 'COACH_JUEZ' OR u.tipo_usuario = 'JUEZ') 
      AND u.activo = 1 
    ORDER BY u.nombres ASC;
END //

DELIMITER ;

DELIMITER //

-- 1. Procedimiento para listar eventos activos (Para el Dropdown)
DROP PROCEDURE IF EXISTS Sp_AdminListarEventosActivos //
CREATE PROCEDURE Sp_AdminListarEventosActivos()
BEGIN
    SELECT id_evento, nombre_evento 
    FROM eventos 
    WHERE activo = 1 
    ORDER BY fecha_evento DESC;
END //

-- 2. Procedimiento para listar categorías (ID y Nombre)
DROP PROCEDURE IF EXISTS Sp_AdminListarCategorias //
CREATE PROCEDURE Sp_AdminListarCategorias()
BEGIN
    SELECT id_categoria, nombre_categoria 
    FROM categorias 
    ORDER BY id_categoria ASC;
END //

DELIMITER ;