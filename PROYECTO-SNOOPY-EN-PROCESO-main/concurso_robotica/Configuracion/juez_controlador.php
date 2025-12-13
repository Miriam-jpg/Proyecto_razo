<?php
// Configuración de Cabeceras
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// Desactivar errores visuales para no romper el JSON de respuesta
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

    // Validar permisos (JUEZ, COACH_JUEZ o ADMIN)
    if ($rol !== 'JUEZ' && $rol !== 'COACH_JUEZ' && $rol !== 'ADMIN') {
        throw new Exception("No tienes permisos de Juez.");
    }

    $method = $_SERVER['REQUEST_METHOD'];

    // =======================================================================
    //                              PETICIONES GET
    // =======================================================================
    if ($method === 'GET') {
        $action = $_GET['action'] ?? '';

        // A. LISTAR PROYECTOS ASIGNADOS
        if ($action === 'listar_proyectos') {
            $categoria = $_GET['categoria'] ?? 'TODOS';
            
            if ($categoria === 'TODOS') {
                // 1. Obtener todas las categorías asignadas a este juez
                $stmt = $pdo->prepare("CALL Sp_Juez_ObtenerCategoriasAsignadas(:idj)");
                $stmt->bindParam(':idj', $idJuez);
                $stmt->execute();
                $cats = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $stmt->closeCursor();

                $todosLosProyectos = [];
                
                // 2. Iterar por cada categoría para buscar proyectos
                foreach($cats as $catNombre) {
                    $stmt = $pdo->prepare("CALL Sp_Juez_ListarProyectos(:idj, :nomCat)");
                    $stmt->bindParam(':idj', $idJuez);
                    $stmt->bindParam(':nomCat', $catNombre);
                    $stmt->execute();
                    $proyectos = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $stmt->closeCursor();
                    
                    // Añadir nombre de categoría a cada proyecto para la vista
                    foreach($proyectos as &$p) { $p['nombre_categoria'] = $catNombre; }
                    
                    $todosLosProyectos = array_merge($todosLosProyectos, $proyectos);
                }
                
                $response = ["success" => true, "data" => $todosLosProyectos];

            } else {
                // Filtrado por una categoría específica
                $stmt = $pdo->prepare("CALL Sp_Juez_ListarProyectos(:idj, :nomCat)");
                $stmt->bindParam(':idj', $idJuez);
                $stmt->bindParam(':nomCat', $categoria);
                $stmt->execute();
                $proyectos = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach($proyectos as &$p) { $p['nombre_categoria'] = $categoria; }

                $response = ["success" => true, "data" => $proyectos];
            }
        }
        
        // B. OBTENER CATEGORÍAS (Para el filtro del panel)
        elseif ($action === 'obtener_categorias') {
            $stmt = $pdo->prepare("CALL Sp_Juez_ObtenerCategoriasAsignadas(:idj)");
            $stmt->bindParam(':idj', $idJuez);
            $stmt->execute();
            $cats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $response = ["success" => true, "data" => $cats];
        }

        // C. OBTENER DETALLE EVALUACIÓN (Para Modo Lectura)
        elseif ($action === 'obtener_evaluacion') {
            $idEquipo = $_GET['id_equipo'] ?? 0;
            
            if ($idEquipo > 0) {
                // Llamamos al procedimiento que lee la evaluación existente
                $stmt = $pdo->prepare("CALL Sp_ObtenerDetalleEvaluacion(:ide)");
                $stmt->bindParam(':ide', $idEquipo);
                $stmt->execute();
                $detalle = $stmt->fetch(PDO::FETCH_ASSOC);
                $stmt->closeCursor();

                if ($detalle) {
                    // Equipo ya evaluado: devolvemos los datos
                    $response = ["success" => true, "data" => $detalle];
                } else {
                    // Equipo no evaluado (retorna null data, el front lo interpreta como modo edición)
                    $response = ["success" => true, "data" => null];
                }
            } else {
                throw new Exception("ID de equipo inválido");
            }
        }
    }

    // =======================================================================
    //                              PETICIONES POST
    // =======================================================================
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';
        
        // D. GUARDAR EVALUACIÓN
        if ($action === 'guardar_evaluacion') {
            $idEquipo = $input['id_equipo'] ?? 0;
            $total = $input['total'] ?? 0;
            // Convertimos el objeto de detalles (checkboxes) a JSON String para la BD
            $detalles = isset($input['detalles']) ? json_encode($input['detalles']) : null;

            if ($idEquipo <= 0) throw new Exception("ID de equipo no válido.");

            // Llamada al Procedimiento Almacenado
            // Nota: Este SP ahora debe tener la lógica de bloqueo si ya existe
            $stmt = $pdo->prepare("CALL RegistrarEvaluacion(:ide, :idj, :tot, :det, @res)");
            $stmt->bindParam(':ide', $idEquipo);
            $stmt->bindParam(':idj', $idJuez);
            $stmt->bindParam(':tot', $total);
            $stmt->bindParam(':det', $detalles);
            $stmt->execute();
            $stmt->closeCursor();

            // Obtener mensaje de respuesta de la BD
            $output = $pdo->query("SELECT @res as mensaje")->fetch(PDO::FETCH_ASSOC);
            $mensaje = $output['mensaje'] ?? 'Error desconocido';

            // Verificamos si la BD respondió con ÉXITO
            if (strpos($mensaje, 'ÉXITO') !== false) {
                $response = ["success" => true, "message" => $mensaje];
            } else {
                // Si la BD devuelve "ERROR: Este equipo ya fue evaluado...", cae aquí
                throw new Exception($mensaje);
            }
        }
        else {
            throw new Exception("Acción no reconocida: " . $action);
        }
    }

} catch (Exception $e) {
    // Enviar el error al frontend para mostrarlo en el Toast
    $response["success"] = false;
    $response["message"] = $e->getMessage();
}

echo json_encode($response);
?>
