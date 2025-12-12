<?php
// 1. DESACTIVAR ERRORES VISUALES: 
// Esto es crucial para APIs JSON. Evita que warnings de PHP (como "include failed" o "undefined index")
// impriman HTML (el <br/> <b> que ves en tu error) y rompan el JSON.
error_reporting(0);
ini_set('display_errors', 0);

session_start();
header('Content-Type: application/json');

$response = [
    "success" => false, 
    "message" => "Error desconocido",
    "redirect" => ""
];

try {
    // 2. VALIDAR ARCHIVO DE CONEXIÓN:
    // Verificamos que el archivo exista antes de intentar incluirlo para poder manejar el error limpiamente.
    $rutaConexion = __DIR__ . '/conexion.php';
    
    if (!file_exists($rutaConexion)) {
        throw new Exception("Error interno: No se encuentra el archivo de conexión en " . $rutaConexion);
    }
    
    require_once $rutaConexion;

    // Verificar si la variable $pdo se creó correctamente en conexion.php
    if (!isset($pdo)) {
        throw new Exception("Error interno: Fallo la conexión a la base de datos.");
    }

    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        // Validar si el JSON llegó bien formado
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Datos inválidos recibidos (JSON mal formado).");
        }
        
        $email = $input['email'] ?? '';
        $password = $input['password'] ?? '';
        $role = $input['role'] ?? '';

        if (empty($email) || empty($password) || empty($role)) {
            // No usamos exit aquí para asegurar que el json_encode final se ejecute
            throw new Exception("Por favor complete todos los campos.");
        }

        // Llamada al Procedimiento Almacenado
        $stmt = $pdo->prepare("CALL sp_ObtenerDatosLogin(?, ?)");
        $stmt->execute([$email, $role]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        if ($user) {
            if ($user['activo'] == 0) {
                $response["message"] = "Esta cuenta ha sido desactivada. Contacte al administrador.";
            } 
            else if ($password === $user['password_hash']) {
                $_SESSION['user_id'] = $user['id_usuario'];
                $_SESSION['user_name'] = $user['nombres'] . ' ' . $user['apellidos'];
                $_SESSION['user_role'] = $user['tipo_usuario'];
                
                $response["success"] = true;
                $response["message"] = "Login correcto";
                
                // Lógica de Redirección
                if ($user['tipo_usuario'] == 'ADMIN') {
                    $response["redirect"] = "adminPanel.html";
                } elseif ($role == 'JUEZ') { 
                    $response["redirect"] = "juezPanel.html";
                } elseif ($role == 'COACH') { 
                    $response["redirect"] = "coachPanel.html";
                } else {
                     $response["redirect"] = "login.html";
                }
            } else {
                $response["message"] = "Contraseña incorrecta.";
            }
        } else {
            $response["message"] = "Usuario no encontrado o no tiene permisos de " . $role . ".";
        }

    } else {
        $response["message"] = "Método no permitido.";
    }

} catch (PDOException $e) {
    // Capturamos errores de SQL
    $response["message"] = "Error de Base de Datos: " . $e->getMessage();
} catch (Exception $e) {
    // Capturamos errores generales (archivos faltantes, lógica, etc.)
    $response["message"] = $e->getMessage();
}

// 3. SALIDA ÚNICA:
// Aseguramos que solo haya un echo y sea JSON puro.
echo json_encode($response);
?>