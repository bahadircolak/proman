<?php
require_once __DIR__ . '/config.php'; // For DB constants, session_start, BASE_URL
require_once __DIR__ . '/db.php';     // For getDBConnection()
require_once __DIR__ . '/security.php'; // For CSRF functions

header('Content-Type: application/json');
$response = ['success' => false, 'message' => 'Invalid request.'];
$action = $_POST['action'] ?? $_GET['action'] ?? null;

// Apply CSRF protection for state-changing POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['register', 'login', 'logout'])) {
    verifyCsrfTokenProtection();
}


// Get the PDO connection - moved after potential CSRF exit
try {
    $pdo = getDBConnection();
} catch (PDOException $e) {
    $response['message'] = 'Database connection error.';
    echo json_encode($response);
    exit;
}


switch ($action) {
    case 'register':
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($email) || empty($password)) {
            $response['message'] = 'Username, email, and password are required.';
            break;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $response['message'] = 'Invalid email format.';
            break;
        }
        if (strlen($password) < 6) { // Basic password length validation
            $response['message'] = 'Password must be at least 6 characters long.';
            break;
        }

        try {
            // Check if username or email already exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = :username OR email = :email");
            $stmt->execute(['username' => $username, 'email' => $email]);
            if ($stmt->fetch()) {
                $response['message'] = 'Username or email already taken.';
                break;
            }

            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash) VALUES (:username, :email, :password_hash)");
            $stmt->execute([
                'username' => $username,
                'email' => $email,
                'password_hash' => $password_hash
            ]);

            if ($stmt->rowCount() > 0) {
                $response = ['success' => true, 'message' => 'Registration successful. Please login.'];
            } else {
                $response['message'] = 'Registration failed. Please try again.';
            }
        } catch (PDOException $e) {
            $response['message'] = 'Database error during registration.';
            error_log("Registration DB Error: " . $e->getMessage());
        }
        break;

    case 'login':
        $usernameOrEmail = trim($_POST['username'] ?? ''); // Can be username or email
        $password = $_POST['password'] ?? '';

        if (empty($usernameOrEmail) || empty($password)) {
            $response['message'] = 'Username/Email and password are required.';
            break;
        }

        try {
            $stmt = $pdo->prepare("SELECT id, username, password_hash, role, company_id FROM users WHERE username = :usernameOrEmail OR email = :usernameOrEmail");
            $stmt->execute(['usernameOrEmail' => $usernameOrEmail]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['user_role'] = $user['role']; // Store role
                $_SESSION['company_id'] = $user['company_id']; // Store company_id (can be null)
                
                unset($_SESSION['csrf_token']); 
                $csrfToken = generateCsrfToken(); 
                $response = [
                    'success' => true, 
                    'message' => 'Login successful.',
                    'user' => [
                        'id' => $user['id'], 
                        'username' => $user['username'],
                        'role' => $user['role'],
                        'company_id' => $user['company_id']
                    ],
                    'csrf_token' => $csrfToken 
                ];
            } else {
                $response['message'] = 'Invalid username/email or password.';
            }
        } catch (PDOException $e) {
            $response['message'] = 'Database error during login.';
            error_log("Login DB Error: " . $e->getMessage());
        }
        break;

    case 'logout':
        // CSRF protection is already applied if POST at the top
        $_SESSION = array(); // Unset all session variables
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
        }
        session_destroy();
        // A new session (and CSRF token) will be generated on next request if needed
        $response = ['success' => true, 'message' => 'Logout successful.'];
        break;

    case 'check_auth': // Helper to check if user is logged in - typically GET
        $csrfToken = generateCsrfToken(); // Ensure a token is available for the frontend
        if (isset($_SESSION['user_id'])) {
            $response = [
                'success' => true, 
                'loggedIn' => true, 
                'user' => [
                    'id' => $_SESSION['user_id'], 
                    'username' => $_SESSION['username'],
                    'role' => $_SESSION['user_role'] ?? 'user', // Get role from session
                    'company_id' => $_SESSION['company_id'] ?? null // Get company_id from session
                ],
                'csrf_token' => $csrfToken
            ];
        } else {
            $response = ['success' => true, 'loggedIn' => false, 'csrf_token' => $csrfToken];
        }
        break;
    
    case 'get_csrf_token': 
        $response = ['success' => true, 'csrf_token' => generateCsrfToken()];
        break;

    default:
        $response['message'] = "Action '{$action}' not recognized in auth API.";
        break;
}

echo json_encode($response);
?>
