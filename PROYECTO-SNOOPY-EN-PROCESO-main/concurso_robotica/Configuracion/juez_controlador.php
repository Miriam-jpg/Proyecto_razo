<?php
// Configuración de Cabeceras
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// Desactivar errores visuales
error_reporting(0);
ini_set('display_errors', 0);

session_start();
require_once 'conexion.php';

$response = ["success" => false, "message" => "Acción no válida"];

try {
    // 1. VALIDACIÓN DE SESIÓN
    if (!isset($_SESSION['user_id'])) {
        throw new Exception("Sesión expirada. Por favor inicie sesión nuevamente.");
    }

    $idJuez = $_SESSION['user_id'];
    $rol = $_SESSION['user_role'] ?? '';

    // Validar que sea Juez o Coach-Juez
    if ($rol !== 'JUEZ' && $rol !== 'COACH_JUEZ' && $rol !== 'ADMIN') {
        throw new Exception("No tienes permisos de Juez.");
    }

    $method = $_SERVER['REQUEST_METHOD'];

    // --- PETICIONES GET (Lectura) ---
    if ($method === 'GET') {
        $action = $_GET['action'] ?? '';

        if ($action === 'listar_proyectos') {
            $categoria = $_GET['categoria'] ?? 'TODOS';
            
            // Si la categoría es TODOS, podríamos iterar o ajustar el SP. 
            // Por simplicidad, asumiremos que el frontend manda una categoría específica 
            // o ajustamos la lógica para traer todo si el SP lo permite.
            // Nota: Tu SP `Sp_Juez_ListarProyectos` requiere nombre_categoria.
            
            // Para obtener todo, primero obtenemos las categorías asignadas al juez
            if ($categoria === 'TODOS') {
                // Obtener todas las asignaciones de este juez
                $stmt = $pdo->prepare("CALL Sp_Juez_ObtenerCategoriasAsignadas(:idj)");
                $stmt->bindParam(':idj', $idJuez);
                $stmt->execute();
                $cats = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $stmt->closeCursor();

                $todosLosProyectos = [];
                
                // Iterar por cada categoría asignada para buscar proyectos
                foreach($cats as $catNombre) {
                    $stmt = $pdo->prepare("CALL Sp_Juez_ListarProyectos(:idj, :nomCat)");
                    $stmt->bindParam(':idj', $idJuez);
                    $stmt->bindParam(':nomCat', $catNombre);
                    $stmt->execute();
                    $proyectos = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $stmt->closeCursor();
                    
                    // Añadir nombre de categoría a cada proyecto para mostrarlo en tabla
                    foreach($proyectos as &$p) { $p['nombre_categoria'] = $catNombre; }
                    
                    $todosLosProyectos = array_merge($todosLosProyectos, $proyectos);
                }
                
                $response = ["success" => true, "data" => $todosLosProyectos];

            } else {
                // Filtrado específico
                $stmt = $pdo->prepare("CALL Sp_Juez_ListarProyectos(:idj, :nomCat)");
                $stmt->bindParam(':idj', $idJuez);
                $stmt->bindParam(':nomCat', $categoria);
                $stmt->execute();
                $proyectos = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach($proyectos as &$p) { $p['nombre_categoria'] = $categoria; }

                $response = ["success" => true, "data" => $proyectos];
            }
        }
        elseif ($action === 'obtener_categorias') {
            $stmt = $pdo->prepare("CALL Sp_Juez_ObtenerCategoriasAsignadas(:idj)");
            $stmt->bindParam(':idj', $idJuez);
            $stmt->execute();
            $cats = $stmt->fetchAll(PDO::FETCH_ASSOC); // Devuelve array de rows
            
            $response = ["success" => true, "data" => $cats];
        }
    }

    // --- PETICIONES POST (Escritura) ---
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';
        
        // --- GUARDAR EVALUACIÓN ---
        if ($action === 'guardar_evaluacion') {
            $idEquipo = $input['id_equipo'] ?? 0;
            $total = $input['total'] ?? 0;

            if ($idEquipo <= 0) throw new Exception("ID de equipo no válido.");

            // Llamada al Procedimiento Almacenado
            $stmt = $pdo->prepare("CALL RegistrarEvaluacion(:ide, :idj, :tot, @res)");
            $stmt->bindParam(':ide', $idEquipo);
            $stmt->bindParam(':idj', $idJuez);
            $stmt->bindParam(':tot', $total);
            $stmt->execute();
            $stmt->closeCursor();

            // Obtener resultado
            $output = $pdo->query("SELECT @res as mensaje")->fetch(PDO::FETCH_ASSOC);
            $mensaje = $output['mensaje'] ?? 'Error desconocido';

            if (strpos($mensaje, 'ÉXITO') !== false) {
                $response = ["success" => true, "message" => $mensaje];
            } else {
                throw new Exception($mensaje);
            }
        }
        else {
            throw new Exception("Acción no reconocida: " . $action);
        }
    }

} catch (Exception $e) {
    $response["success"] = false;
    $response["message"] = $e->getMessage();
}

echo json_encode($response);
?>