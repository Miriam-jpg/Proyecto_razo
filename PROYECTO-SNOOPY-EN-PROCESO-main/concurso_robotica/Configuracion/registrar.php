<?php
session_start();
header('Content-Type: application/json');

// Incluir la conexión a la base de datos
// Asumimos que este archivo está en la misma carpeta que conexion.php
require_once '../Configuracion/conexion.php';

$response = [
    "success" => false, 
    "message" => "Error desconocido"
];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // Obtener datos del cuerpo de la solicitud (JSON)
    $input = json_decode(file_get_contents('php://input'), true);

    // Mapear datos (validando que existan)
    $nombres = isset($input['nombres']) ? trim($input['nombres']) : '';
    $apellidos = isset($input['apellidos']) ? trim($input['apellidos']) : '';
    $escuela = isset($input['escuela']) ? trim($input['escuela']) : '';
    $email = isset($input['email']) ? trim($input['email']) : '';
    $password = isset($input['password']) ? trim($input['password']) : '';
    $tipoUsuario = isset($input['tipoUsuario']) ? trim($input['tipoUsuario']) : '';

    // Validaciones básicas de servidor
    if (empty($nombres) || empty($apellidos) || empty($escuela) || empty($email) || empty($password) || empty($tipoUsuario)) {
        echo json_encode(["success" => false, "message" => "Todos los campos son obligatorios."]);
        exit;
    }

    try {
        // NOTA DE SEGURIDAD:
        // En un entorno de producción real, deberías encriptar la contraseña usando password_hash().
        // Sin embargo, para mantener compatibilidad con tu 'login_controlador.php' actual
        // (que compara texto plano), guardaremos la contraseña tal cual.
        // Si decides cambiarlo a futuro, usa: $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $passwordHash = $password; 

        // Preparar la llamada al Procedimiento Almacenado
        // Firma SQL: RegistrarUsuario(email, pass, nombres, apellidos, tipo, escuela, OUT resultado, OUT id)
        $sql = "CALL RegistrarUsuario(:email, :pass, :nombres, :apellidos, :tipo, :escuela, @resultado, @nuevo_id)";
        
        $stmt = $pdo->prepare($sql);
        
        // Vincular parámetros de entrada
        $stmt->bindParam(':email', $email, PDO::PARAM_STR);
        $stmt->bindParam(':pass', $passwordHash, PDO::PARAM_STR);
        $stmt->bindParam(':nombres', $nombres, PDO::PARAM_STR);
        $stmt->bindParam(':apellidos', $apellidos, PDO::PARAM_STR);
        $stmt->bindParam(':tipo', $tipoUsuario, PDO::PARAM_STR);
        $stmt->bindParam(':escuela', $escuela, PDO::PARAM_STR);
        
        // Ejecutar el procedimiento
        $stmt->execute();
        $stmt->closeCursor(); // Liberar para poder ejecutar la siguiente consulta SELECT

        // Obtener los parámetros de salida (OUT)
        $resultQuery = $pdo->query("SELECT @resultado AS mensaje, @nuevo_id AS id");
        $output = $resultQuery->fetch(PDO::FETCH_ASSOC);

        $mensajeDB = $output['mensaje'];
        $idUsuario = $output['id'];

        // Analizar el mensaje que devuelve la base de datos
        // Tu SP devuelve strings que empiezan con "ÉXITO" o "ERROR"
        if (strpos($mensajeDB, 'ÉXITO') !== false) {
            $response["success"] = true;
            $response["message"] = "Registro completado con éxito. Redirigiendo...";
        } else {
            // Caso: Email duplicado u otro error SQL controlado
            $response["success"] = false;
            $response["message"] = $mensajeDB; // Ej: "ERROR: Email ya registrado"
        }

    } catch (PDOException $e) {
        $response["message"] = "Error de Base de Datos: " . $e->getMessage();
    }

} else {
    $response["message"] = "Método no permitido.";
}

echo json_encode($response);
?>