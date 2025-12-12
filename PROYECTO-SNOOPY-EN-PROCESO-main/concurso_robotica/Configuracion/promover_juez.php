<?php
// Configuración de Cabeceras
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// Desactivar errores visuales para no ensuciar el JSON
error_reporting(0);
ini_set('display_errors', 0);

session_start();
require_once 'conexion.php';

$response = ["success" => false, "message" => "Acción no válida"];

try {
    // 1. SEGURIDAD: Solo ADMIN puede acceder aquí
    if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'ADMIN') {
        throw new Exception("Acceso denegado. Permisos insuficientes.");
    }

    $method = $_SERVER['REQUEST_METHOD'];

    // =======================================================================
    //                              PETICIONES GET
    // =======================================================================
    if ($method === 'GET') {
        $action = $_GET['action'] ?? '';

        // A. LISTAR USUARIOS (Tabla de Roles)
        if ($action === 'listar_usuarios') {
            $stmt = $pdo->prepare("CALL Sp_Admin_ListarUsuariosCandidatos()");
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            echo json_encode(["success" => true, "data" => $data]);
            exit;
        }

        // B. OBTENER CATALOGOS (Para el Select de Eventos y Categorías)
        // El HTML pide 'obtener_catalogos' y espera {eventos: [], categorias: []}
        if ($action === 'obtener_catalogos') {
            // 1. Eventos
            $stmt = $pdo->prepare("CALL Sp_AdminListarEventosActivos()");
            $stmt->execute();
            $eventos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            // 2. Categorías
            $stmt = $pdo->prepare("CALL Sp_AdminListarCategorias()");
            $stmt->execute();
            $categorias = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            echo json_encode([
                "success" => true, 
                "eventos" => $eventos, 
                "categorias" => $categorias
            ]);
            exit;
        }

        // C. JUECES DISPONIBLES (Columna Izquierda Drag & Drop)
        if ($action === 'jueces_disponibles') {
            $stmt = $pdo->prepare("CALL ListarJuecesDisponibles()");
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            echo json_encode(["success" => true, "data" => $data]);
            exit;
        }

        // D. JUECES ASIGNADOS (Columna Derecha Drag & Drop)
        if ($action === 'jueces_asignados') {
            $idEvento = $_GET['id_evento'] ?? 0;
            $stmt = $pdo->prepare("CALL Sp_ListarJuecesDeEvento(:ide)");
            $stmt->bindParam(':ide', $idEvento);
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            echo json_encode(["success" => true, "data" => $data]);
            exit;
        }
    }

    // =======================================================================
    //                              PETICIONES POST
    // =======================================================================
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $accion = $input['accion'] ?? ''; // Ojo: Tu JS usa 'accion' en español para los POST

        // E. ACTUALIZAR ROL (Modal Editar Rol)
        if ($accion === 'actualizar_rol') {
            $idUsuario = $input['id'];
            $nuevoRol = $input['rol']; // COACH, JUEZ, COACH_JUEZ

            // Actualización directa segura
            $sql = "UPDATE usuarios SET tipo_usuario = :rol WHERE id_usuario = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':rol', $nuevoRol);
            $stmt->bindParam(':id', $idUsuario);
            
            if ($stmt->execute()) {
                echo json_encode(["success" => true, "message" => "Rol actualizado correctamente."]);
            } else {
                throw new Exception("No se pudo actualizar el rol.");
            }
            exit;
        }

        // F. ASIGNAR JUEZ A EVENTO (Drag & Drop)
        if ($accion === 'asignar_juez_evento') {
            $idEvento = $input['id_evento'];
            $idJuez = $input['id_juez'];
            $idCategoria = $input['id_categoria'];

            $stmt = $pdo->prepare("CALL AsignarJuezEvento(:ide, :idj, :idc, @res)");
            $stmt->bindParam(':ide', $idEvento);
            $stmt->bindParam(':idj', $idJuez);
            $stmt->bindParam(':idc', $idCategoria);
            $stmt->execute();
            $stmt->closeCursor();

            $output = $pdo->query("SELECT @res as mensaje")->fetch(PDO::FETCH_ASSOC);
            $mensaje = $output['mensaje'];

            if (strpos($mensaje, 'ÉXITO') !== false) {
                echo json_encode(["success" => true, "message" => "Asignación exitosa."]);
            } else {
                echo json_encode(["success" => false, "message" => $mensaje]);
            }
            exit;
        }

        // G. QUITAR JUEZ DE EVENTO
        if ($accion === 'quitar_juez_evento') {
            $idEvento = $input['id_evento'];
            $idJuez = $input['id_juez'];
            $idCategoria = $input['id_categoria'];

            $stmt = $pdo->prepare("CALL QuitarJuezEvento(:ide, :idj, :idc)");
            $stmt->bindParam(':ide', $idEvento);
            $stmt->bindParam(':idj', $idJuez);
            $stmt->bindParam(':idc', $idCategoria);
            
            if ($stmt->execute()) {
                echo json_encode(["success" => true, "message" => "Juez removido correctamente."]);
            } else {
                throw new Exception("Error al remover juez.");
            }
            exit;
        }
    }

} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>