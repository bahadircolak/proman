<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

header('Content-Type: application/json');
$response = ['success' => false, 'message' => 'Invalid request or not authorized.'];
$action = $_REQUEST['action'] ?? null; // Use $_REQUEST to handle GET for fetching, POST for updates

// --- Authentication Check ---
if (!isset($_SESSION['user_id'])) {
    $response['message'] = 'Authentication required. Please login.';
    $response['redirectToLogin'] = true;
    http_response_code(401); // Unauthorized
    echo json_encode($response);
    exit;
}
$userId = $_SESSION['user_id'];

// Apply CSRF protection for state-changing POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfProtectedActions = ['mark_notification_read', 'mark_all_notifications_read'];
    if (in_array($action, $csrfProtectedActions)) {
        verifyCsrfTokenProtection(); // This function should handle exit on failure
    }
}

try {
    $pdo = getDBConnection();
} catch (PDOException $e) {
    error_log("Notifications API DB Connection Error: " . $e->getMessage());
    $response['message'] = 'Database connection error.';
    http_response_code(500);
    echo json_encode($response);
    exit;
}

switch ($action) {
    case 'get_notifications':
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $response['message'] = 'Invalid request method for get_notifications.';
            http_response_code(405); // Method Not Allowed
            break;
        }
        try {
            // Fetch notifications for the logged-in user, e.g., latest 20
            // Order by created_at DESC to get newest first, then by is_read ASC to show unread first within those.
            // However, usually frontend wants newest overall, and unread status is a flag.
            $stmt = $pdo->prepare(
                "SELECT id, message, link_url, is_read, created_at
                 FROM notifications
                 WHERE user_id = :user_id
                 ORDER BY created_at DESC
                 LIMIT 20" // Limiting for performance in dropdown
            );
            $stmt->execute(['user_id' => $userId]);
            $notifications = $stmt->fetchAll();

            // Get unread count separately for the badge
            $stmtUnreadCount = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = FALSE");
            $stmtUnreadCount->execute(['user_id' => $userId]);
            $unreadCount = $stmtUnreadCount->fetchColumn();

            $response = [
                'success' => true,
                'notifications' => $notifications,
                'unread_count' => (int)$unreadCount
            ];
        } catch (PDOException $e) {
            error_log("Get Notifications DB Error for user $userId: " . $e->getMessage());
            $response['message'] = 'Error fetching notifications.';
            http_response_code(500);
        }
        break;

    case 'mark_notification_read':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $response['message'] = 'Invalid request method.';
            http_response_code(405);
            break;
        }
        $notificationId = $_POST['notification_id'] ?? null;
        if (empty($notificationId)) {
            $response['message'] = 'Notification ID is required.';
            break;
        }

        try {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE id = :notification_id AND user_id = :user_id");
            $stmt->execute(['notification_id' => $notificationId, 'user_id' => $userId]);

            if ($stmt->rowCount() > 0) {
                $response = ['success' => true, 'message' => 'Notification marked as read.'];
            } else {
                // Could be notification not found, or already read, or not belonging to user
                $response['message'] = 'Could not mark notification as read or notification not found.';
            }
        } catch (PDOException $e) {
            error_log("Mark Notification Read DB Error for user $userId, notification $notificationId: " . $e->getMessage());
            $response['message'] = 'Database error marking notification as read.';
            http_response_code(500);
        }
        break;

    case 'mark_all_notifications_read':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $response['message'] = 'Invalid request method.';
            http_response_code(405);
            break;
        }
        try {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = :user_id AND is_read = FALSE");
            $stmt->execute(['user_id' => $userId]);

            // rowCount might be 0 if all were already read, which is still a "success" in terms of state.
            $response = ['success' => true, 'message' => 'All unread notifications marked as read.', 'updated_count' => $stmt->rowCount()];
        } catch (PDOException $e) {
            error_log("Mark All Notifications Read DB Error for user $userId: " . $e->getMessage());
            $response['message'] = 'Database error marking all notifications as read.';
            http_response_code(500);
        }
        break;

    default:
        $response['message'] = "Action '{$action}' not recognized in notifications API.";
        http_response_code(404); // Not Found
        break;
}

echo json_encode($response);
?>
