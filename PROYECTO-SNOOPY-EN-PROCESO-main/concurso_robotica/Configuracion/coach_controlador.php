<?php
// Configuración de Cabeceras
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// Desactivar errores visuales para no romper el JSON
error_reporting(0);
ini_set('display_errors', 0);

session_start();
require_once 'conexion.php';

$response = ["success" => false, "message" => "Acción no válida"];

try {
    // 1. VALIDACIÓN DE SESIÓN
    // Asegurarse de que el usuario esté logueado y sea COACH (o COACH_JUEZ)
    if (!isset($_SESSION['user_id'])) {
        throw new Exception("Sesión expirada o no iniciada.");
    }

    $idCoach = $_SESSION['user_id'];
    $rol = $_SESSION['user_role'] ?? '';

    if ($rol !== 'COACH' && $rol !== 'COACH_JUEZ') {
        throw new Exception("No tienes permisos de Coach.");
    }

    // 2. OBTENER MÉTODO Y DATOS
    $method = $_SERVER['REQUEST_METHOD'];
    
    // --- PETICIONES GET (Lectura) ---
    if ($method === 'GET') {
        $action = $_GET['action'] ?? '';

        if ($action === 'listar_equipos') {
            // SP: ListarDetalleEquiposPorCoach(IN p_id_coach INT)
            $stmt = $pdo->prepare("CALL ListarDetalleEquiposPorCoach(:id)");
            $stmt->bindParam(':id', $idCoach, PDO::PARAM_INT);
            $stmt->execute();
            $equipos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $response = ["success" => true, "data" => $equipos];
        } 
        elseif ($action === 'listar_integrantes') {
            $idEquipo = $_GET['id_equipo'] ?? 0;
            
            // Verificación de seguridad básica (que el equipo pertenezca al coach)
            // Esto se hace idealmente en el SP, pero por seguridad extra:
            $check = $pdo->prepare("SELECT id_coach FROM equipos WHERE id_equipo = :ide");
            $check->execute([':ide' => $idEquipo]);
            $owner = $check->fetchColumn();

            if ($owner != $idCoach) {
                throw new Exception("No tienes permiso para ver este equipo.");
            }

            // AHORA USAMOS EL SP ACTUALIZADO que ya devuelve id_integrante
            $stmt = $pdo->prepare("CALL ListarIntegrantesPorEquipo(:id)");
            $stmt->bindParam(':id', $idEquipo, PDO::PARAM_INT);
            $stmt->execute();
            $integrantes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $response = ["success" => true, "data" => $integrantes];
        }
    }

    // --- PETICIONES POST (Escritura) ---
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';

        if ($action === 'registrar_equipo') {
            $nombre = $input['nombre'];
            $prototipo = $input['prototipo'];
            $idEvento = $input['id_evento'];
            $idCategoria = $input['id_categoria'];

            // SP: RegistrarEquipo(nombre, prototipo, evento, categoria, coach, OUT res, OUT id)
            $stmt = $pdo->prepare("CALL RegistrarEquipo(:nom, :proto, :ev, :cat, :coach, @res, @nid)");
            $stmt->bindParam(':nom', $nombre);
            $stmt->bindParam(':proto', $prototipo);
            $stmt->bindParam(':ev', $idEvento);
            $stmt->bindParam(':cat', $idCategoria);
            $stmt->bindParam(':coach', $idCoach);
            $stmt->execute();
            $stmt->closeCursor();

            $output = $pdo->query("SELECT @res as mensaje, @nid as id")->fetch(PDO::FETCH_ASSOC);
            
            if (strpos($output['mensaje'], 'ÉXITO') !== false) {
                $response = ["success" => true, "message" => "Equipo registrado correctamente."];
            } else {
                throw new Exception($output['mensaje']);
            }
        } 
        elseif ($action === 'agregar_integrante') {
            $idEquipo = $input['id_equipo'];
            $nombre = $input['nombre'];
            $edad = $input['edad'];
            $grado = $input['grado'];

            // Validar propiedad del equipo antes de insertar
            $check = $pdo->prepare("SELECT id_coach FROM equipos WHERE id_equipo = ?");
            $check->execute([$idEquipo]);
            if ($check->fetchColumn() != $idCoach) {
                throw new Exception("Acción no autorizada.");
            }

            // SP: AgregarIntegrante(equipo, nombre, edad, grado, OUT res)
            $stmt = $pdo->prepare("CALL AgregarIntegrante(:ide, :nom, :edad, :grado, @res)");
            $stmt->bindParam(':ide', $idEquipo);
            $stmt->bindParam(':nom', $nombre);
            $stmt->bindParam(':edad', $edad);
            $stmt->bindParam(':grado', $grado);
            $stmt->execute();
            $stmt->closeCursor();

            $output = $pdo->query("SELECT @res as mensaje")->fetch(PDO::FETCH_ASSOC);

            if (strpos($output['mensaje'], 'ÉXITO') !== false) {
                $response = ["success" => true, "message" => "Integrante agregado."];
            } else {
                throw new Exception($output['mensaje']);
            }
        }
        elseif ($action === 'eliminar_integrante') {
            $idIntegrante = $input['id_integrante'];

            // USANDO EL NUEVO STORED PROCEDURE
            // EliminarIntegrante(IN p_id_integrante INT, IN p_id_coach INT, OUT p_resultado VARCHAR(255))
            $stmt = $pdo->prepare("CALL EliminarIntegrante(:idi, :idc, @res)");
            $stmt->bindParam(':idi', $idIntegrante);
            $stmt->bindParam(':idc', $idCoach);
            $stmt->execute();
            $stmt->closeCursor();

            $output = $pdo->query("SELECT @res as mensaje")->fetch(PDO::FETCH_ASSOC);
            
            if (strpos($output['mensaje'], 'ÉXITO') !== false) {
                $response = ["success" => true, "message" => "Integrante eliminado correctamente."];
            } else {
                throw new Exception($output['mensaje']);
            }
        }
    }

} catch (Exception $e) {
    $response["success"] = false;
    $response["message"] = $e->getMessage();
}

echo json_encode($response);
?>