<?php
// 1. Configuración de cabeceras JSON
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// 2. Desactivar errores visuales HTML
error_reporting(0);
ini_set('display_errors', 0);

$response = ["success" => false, "message" => "Error desconocido"];

try {
    // 3. Conexión
    $rutaConexion = __DIR__ . '/conexion.php';
    if (!file_exists($rutaConexion)) {
        throw new Exception("No se encuentra el archivo conexion.php");
    }
    require_once $rutaConexion;

    if (!isset($pdo) || !$pdo) {
        throw new Exception("Error de conexión a la base de datos.");
    }

    $method = $_SERVER['REQUEST_METHOD'];
    
    // --- OBTENER PARÁMETROS (GET o POST JSON) ---
    $action = $_GET['action'] ?? '';

    // =======================================================================
    //                              PETICIONES GET
    // =======================================================================
    if ($method === 'GET') {
        
        // A. LISTAR USUARIOS (Gestión de Roles - Pestaña 1)
        if ($action === 'listar_usuarios' || $action === '') {
            $sql = "SELECT 
                        u.id_usuario, 
                        u.nombres, 
                        u.apellidos, 
                        u.email, 
                        u.escuela_proc, 
                        u.tipo_usuario,
                        (SELECT GROUP_CONCAT(DISTINCT c.nombre_categoria SEPARATOR ', ')
                         FROM equipos e
                         JOIN categorias c ON e.id_categoria = c.id_categoria
                         WHERE e.id_coach = u.id_usuario AND e.activo = 1) as categorias_equipos,
                        (SELECT GROUP_CONCAT(DISTINCT c.nombre_categoria SEPARATOR ', ')
                         FROM jueces_eventos je
                         JOIN categorias c ON je.id_categoria = c.id_categoria
                         WHERE je.id_juez = u.id_usuario) as categorias_juez
                    FROM usuarios u 
                    WHERE (u.tipo_usuario = 'COACH' OR u.tipo_usuario = 'COACH_JUEZ' OR u.tipo_usuario = 'JUEZ') 
                      AND u.activo = 1 
                    ORDER BY u.nombres ASC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(["success" => true, "data" => $data]);
            exit;
        }

        // B. LISTAR EVENTOS Y CATEGORÍAS (Para selects de Pestaña 2)
        elseif ($action === 'obtener_catalogos') {
            // Eventos
            $stmtE = $pdo->prepare("CALL Sp_AdminListarEventos()");
            $stmtE->execute();
            $eventos = $stmtE->fetchAll(PDO::FETCH_ASSOC);
            $stmtE->closeCursor();

            // Categorías
            $stmtC = $pdo->query("SELECT * FROM categorias");
            $categorias = $stmtC->fetchAll(PDO::FETCH_ASSOC);
            $stmtC->closeCursor();

            echo json_encode(["success" => true, "eventos" => $eventos, "categorias" => $categorias]);
            exit;
        }

        // C. JUECES DISPONIBLES (Para un evento específico - Pestaña 2 Izq)
        elseif ($action === 'jueces_disponibles') {
            // Trae todos los jueces que estén activos.
            // El frontend filtrará visualmente si ya están asignados, o el SP podría hacerlo.
            // Usamos ListarJuecesDisponibles() existente.
            $stmt = $pdo->prepare("CALL ListarJuecesDisponibles()");
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(["success" => true, "data" => $data]);
            exit;
        }

        // D. JUECES ASIGNADOS (Para un evento específico - Pestaña 2 Der)
        elseif ($action === 'jueces_asignados') {
            $idEvento = $_GET['id_evento'] ?? 0;
            // Usamos Sp_ListarJuecesDeEvento(id) existente
            $stmt = $pdo->prepare("CALL Sp_ListarJuecesDeEvento(:id)");
            $stmt->bindParam(':id', $idEvento);
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(["success" => true, "data" => $data]);
            exit;
        }
    }

    // =======================================================================
    //                              PETICIONES POST
    // =======================================================================
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $postAction = $input['accion'] ?? '';

        // 1. ACTUALIZAR ROL GLOBAL (Pestaña 1)
        if ($postAction === 'actualizar_rol' || $postAction === 'promover') {
            $idUsuario = $input['id'];
            $nuevoRol = isset($input['rol']) ? $input['rol'] : 'COACH_JUEZ';
            
            // Validación manual rápida
            if (!in_array($nuevoRol, ['COACH', 'JUEZ', 'COACH_JUEZ'])) {
                throw new Exception("Rol inválido");
            }

            $sql = "UPDATE usuarios SET tipo_usuario = :rol WHERE id_usuario = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':rol', $nuevoRol);
            $stmt->bindParam(':id', $idUsuario);
            
            if ($stmt->execute()) {
                $response = ["success" => true, "message" => "Rol actualizado a " . $nuevoRol];
            } else {
                throw new Exception("Error al actualizar rol en BD.");
            }
        }

        // 2. ASIGNAR JUEZ A EVENTO/CATEGORÍA (Pestaña 2)
        elseif ($postAction === 'asignar_juez_evento') {
            $idEvento = $input['id_evento'];
            $idJuez = $input['id_juez'];
            $idCategoria = $input['id_categoria'];

            // SP: AsignarJuezEvento(evento, juez, categoria, OUT res)
            $stmt = $pdo->prepare("CALL AsignarJuezEvento(:ev, :ju, :cat, @res)");
            $stmt->bindParam(':ev', $idEvento);
            $stmt->bindParam(':ju', $idJuez);
            $stmt->bindParam(':cat', $idCategoria);
            $stmt->execute();
            $stmt->closeCursor();

            $output = $pdo->query("SELECT @res as mensaje")->fetch(PDO::FETCH_ASSOC);
            $msg = $output['mensaje'];

            // El SP devuelve "ÉXITO: ..." o "ERROR: ..." o "ADVERTENCIA: ..."
            if (strpos($msg, 'ÉXITO') !== false) {
                $response = ["success" => true, "message" => $msg];
            } else {
                $response = ["success" => false, "message" => $msg];
            }
        }

        // 3. REMOVER JUEZ DE EVENTO (Pestaña 2)
        elseif ($postAction === 'quitar_juez_evento') {
            $idEvento = $input['id_evento'];
            $idJuez = $input['id_juez'];
            $idCategoria = $input['id_categoria'];

            // SP: QuitarJuezEvento(evento, juez, categoria) (No devuelve OUT, es DELETE directo)
            $stmt = $pdo->prepare("CALL QuitarJuezEvento(:ev, :ju, :cat)");
            $stmt->bindParam(':ev', $idEvento);
            $stmt->bindParam(':ju', $idJuez);
            $stmt->bindParam(':cat', $idCategoria);
            
            if ($stmt->execute()) {
                $response = ["success" => true, "message" => "Juez removido de la categoría."];
            } else {
                throw new Exception("No se pudo remover al juez.");
            }
        }
        else {
            throw new Exception("Acción no válida: " . $postAction);
        }
    }

} catch (Exception $e) {
    $response["success"] = false;
    $response["message"] = $e->getMessage();
}

echo json_encode($response);
?>