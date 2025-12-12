<?php
// Configuración JSON
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
error_reporting(0); 
ini_set('display_errors', 0);

$response = ["success" => false, "message" => "Error desconocido"];

try {
    // 1. Incluir conexión
    $rutaConexion = __DIR__ . '/conexion.php';
    if (!file_exists($rutaConexion)) {
        throw new Exception("El archivo conexion.php no existe.");
    }
    
    require_once $rutaConexion;

    // 2. Verificar si la conexión fue exitosa
    if (!$pdo) {
        throw new Exception("Error de Conexión a Base de Datos: " . ($errorConexion ?? 'No se pudo conectar'));
    }

    $method = $_SERVER['REQUEST_METHOD'];

    // --- GET: LISTAR EVENTOS ---
    if ($method === 'GET') {
        try {
            $stmt = $pdo->prepare("CALL Sp_AdminListarEventos()");
            $stmt->execute();
            $eventos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(["success" => true, "data" => $eventos]);
            exit;
        } catch (PDOException $e) {
            throw new Exception("Error al ejecutar procedimiento: " . $e->getMessage());
        }
    }

    // --- POST: CREAR O ELIMINAR ---
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) throw new Exception("Datos vacíos o JSON inválido");

        $accion = $input['accion'] ?? '';

        if ($accion === 'crear') {
            $nombre = $input['nombre'];
            $fecha  = $input['fecha'];
            $lugar  = $input['lugar'];

            $stmt = $pdo->prepare("CALL CrearEvento(:nombre, :fecha, :lugar, @resultado)");
            $stmt->bindParam(':nombre', $nombre);
            $stmt->bindParam(':fecha', $fecha);
            $stmt->bindParam(':lugar', $lugar);
            $stmt->execute();
            $stmt->closeCursor();

            $res = $pdo->query("SELECT @resultado as mensaje")->fetch(PDO::FETCH_ASSOC);
            $msg = $res['mensaje'] ?? 'Error desconocido';

            $response = ["success" => (strpos($msg, 'ÉXITO') !== false), "message" => $msg];
        } 
        elseif ($accion === 'eliminar') {
            $id = $input['id'];
            $stmt = $pdo->prepare("CALL EliminarEvento(:id, @resultado)");
            $stmt->bindParam(':id', $id);
            $stmt->execute();
            $stmt->closeCursor();

            $res = $pdo->query("SELECT @resultado as mensaje")->fetch(PDO::FETCH_ASSOC);
            $msg = $res['mensaje'] ?? 'Error desconocido';

            $response = ["success" => (strpos($msg, 'ÉXITO') !== false), "message" => $msg];
        } else {
            throw new Exception("Acción no reconocida");
        }
    }

} catch (Exception $e) {
    $response["message"] = $e->getMessage();
}

echo json_encode($response);
?>