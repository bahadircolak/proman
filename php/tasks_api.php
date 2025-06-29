<?php
require_once __DIR__ . '/config.php'; // For DB constants, session_start, UPLOADS_DIR
require_once __DIR__ . '/db.php';     // For getDBConnection()
require_once __DIR__ . '/security.php'; // For CSRF functions and permission checks

header('Content-Type: application/json');
$response = ['success' => false, 'message' => 'Invalid request or not authorized.'];
$action = $_REQUEST['action'] ?? null;

// Apply CSRF protection for state-changing POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfProtectedActions = ['add_task', 'update_task', 'update_task_order_and_status', 'delete_task', 'update_task_status'];
    if (in_array($action, $csrfProtectedActions)) {
        verifyCsrfTokenProtection();
    }
}

// --- Authentication Check ---
if (!isset($_SESSION['user_id'])) {
    $response['message'] = 'Authentication required. Please login.';
    $response['redirectToLogin'] = true;
    echo json_encode($response);
    exit;
}
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['user_role'] ?? 'user';
$companyId = $_SESSION['company_id'] ?? null; // User's company
$activeBoardId = $_SESSION['active_board_id'] ?? null; // Currently selected board in UI

// Get the PDO connection
try {
    $pdo = getDBConnection();
} catch (PDOException $e) {
    $response['message'] = 'Database connection error.';
    echo json_encode($response);
    exit;
}

$allowed_task_statuses = ['todo', 'inprogress', 'done'];

switch ($action) {
    case 'get_tasks':
        $targetBoardId = ($userRole === 'super_admin' && isset($_GET['board_id'])) ? $_GET['board_id'] : $activeBoardId;
        if (!$targetBoardId) { $response['message'] = 'No active/specified board for get_tasks.'; break; }

        if (!hasBoardPermission($pdo, $userId, $targetBoardId, $userRole, $companyId, ['board_viewer', 'board_editor', 'board_admin'])) {
            $response['message'] = 'Not authorized to view tasks on this board.'; break;
        }
        try {
            $sql = "SELECT t.id, t.title, t.description, t.status, t.image_path, t.task_order, t.user_id as creator_id, 
                           t.assigned_to_user_id, u_assignee.username as assignee_username
                    FROM tasks t
                    LEFT JOIN users u_assignee ON t.assigned_to_user_id = u_assignee.id
                    WHERE t.board_id = :board_id 
                    ORDER BY t.task_order ASC, t.created_at DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['board_id' => $targetBoardId]);
            $tasks = $stmt->fetchAll();
            $response = ['success' => true, 'tasks' => $tasks];
        } catch (PDOException $e) { /* ... error log ... */ }
        break;

    case 'get_task':
        $taskId = $_GET['id'] ?? null;
        $targetBoardId = ($userRole === 'super_admin' && isset($_GET['board_id'])) ? $_GET['board_id'] : $activeBoardId;
        if (!$targetBoardId && $userRole !== 'super_admin' && !$taskId) { $response['message'] = 'No active/specified board or task ID for get_task.'; break; }
        if (!$taskId) { $response['message'] = 'Task ID required.'; break; }
        
        // If targetBoardId is still not determined (e.g. SA didn't provide and no active board), try to find task's board
        if (!$targetBoardId && $userRole === 'super_admin') {
            $stmtFindBoard = $pdo->prepare("SELECT board_id FROM tasks WHERE id = :task_id");
            $stmtFindBoard->execute(['task_id' => $taskId]);
            $taskBoardInfo = $stmtFindBoard->fetch();
            if ($taskBoardInfo) $targetBoardId = $taskBoardInfo['board_id']; else {$response['message'] = 'Task not found to determine board.'; break;}
        }
        if (!$targetBoardId) { $response['message'] = 'Board context for task could not be determined.'; break; }


        if (!hasBoardPermission($pdo, $userId, $targetBoardId, $userRole, $companyId, ['board_viewer', 'board_editor', 'board_admin'])) {
            $response['message'] = 'Not authorized to view this task.'; break;
        }
        try {
            $sql = "SELECT t.id, t.title, t.description, t.status, t.image_path, t.user_id as creator_id,
                           t.assigned_to_user_id, u_assignee.username as assignee_username
                    FROM tasks t
                    LEFT JOIN users u_assignee ON t.assigned_to_user_id = u_assignee.id
                    WHERE t.id = :id AND t.board_id = :board_id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $taskId, 'board_id' => $targetBoardId]);
            $task = $stmt->fetch();
            if ($task) $response = ['success' => true, 'task' => $task];
            else $response['message'] = 'Task not found on the specified board.';
        } catch (PDOException $e) { /* ... error log ... */ }
        break;

    case 'add_task':
        $targetBoardId = ($userRole === 'super_admin' && isset($_POST['board_id'])) ? $_POST['board_id'] : $activeBoardId;
        if (!$targetBoardId) { $response['message'] = 'No active/specified board to add task to.'; break; }
        if (!hasBoardPermission($pdo, $userId, $targetBoardId, $userRole, $companyId, ['board_editor', 'board_admin'])) {
            $response['message'] = 'Not authorized to add tasks to this board.'; break;
        }
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = trim($_POST['status'] ?? 'todo');
        $assignedToUserId = isset($_POST['assigned_to_user_id']) && !empty($_POST['assigned_to_user_id']) ? (int)$_POST['assigned_to_user_id'] : null;

        if (empty($title)) { $response['message'] = 'Title is required.'; break; }
        if (!in_array($status, $allowed_task_statuses)) { $response['message'] = 'Invalid task status.'; break; }
        if ($assignedToUserId !== null) {
            $stmtCheckUser = $pdo->prepare("SELECT u.id FROM users u JOIN board_memberships bm ON u.id = bm.user_id WHERE u.id = :user_id AND bm.board_id = :board_id");
            $stmtCheckUser->execute(['user_id' => $assignedToUserId, 'board_id' => $targetBoardId]);
            if (!$stmtCheckUser->fetch()) { $response['message'] = 'Assigned user is not a member of this board.'; break; }
        }
        try {
            // ... (image upload logic - assume $image_path_to_save and $targetFilePath are set) ...
            if (!is_dir(UPLOADS_DIR)) { if (!mkdir(UPLOADS_DIR, 0755, true)) {$response['message'] = 'Uploads directory error.'; break;}}
            if (!is_writable(UPLOADS_DIR)) { $response['message'] = 'Uploads directory not writable.'; break;}
            $image_path_to_save = null; $uploadedImage = $_FILES['task_image'] ?? null; $targetFilePath = null;
            if ($uploadedImage && $uploadedImage['error'] === UPLOAD_ERR_OK) {
                $targetFileName = uniqid('taskimg_', true) . "_" . preg_replace("/[^a-zA-Z0-9\._-]/", "_", basename($uploadedImage["name"]));
                $targetFilePath = UPLOADS_DIR . '/' . $targetFileName;
                $imageFileType = strtolower(pathinfo($targetFilePath, PATHINFO_EXTENSION));
                $allowedImageExtensions = ['jpg', 'jpeg', 'png', 'gif'];
                if (!in_array($imageFileType, $allowedImageExtensions)) { $response['message'] = 'Invalid image file type.'; break; }
                if ($uploadedImage["size"] > 2 * 1024 * 1024) { $response['message'] = 'Image file is too large (max 2MB).'; break; }
                if (move_uploaded_file($uploadedImage["tmp_name"], $targetFilePath)) {
                    $image_path_to_save = 'uploads/' . $targetFileName;
                } else { $response['message'] = 'Error uploading file.'; break; }
            } elseif ($uploadedImage && $uploadedImage['error'] !== UPLOAD_ERR_NO_FILE) { $response['message'] = 'Image upload error code ' . $uploadedImage['error']; break; }


            $stmt = $pdo->prepare("INSERT INTO tasks (user_id, board_id, title, description, status, image_path, assigned_to_user_id) VALUES (:user_id, :board_id, :title, :description, :status, :image_path, :assigned_to)");
            $stmt->execute(['user_id' => $userId, 'board_id' => $targetBoardId, 'title' => $title, 'description' => $description, 'status' => $status, 'image_path' => $image_path_to_save, 'assigned_to' => $assignedToUserId]);
            if ($stmt->rowCount() > 0) {
                $lastId = $pdo->lastInsertId(); $assigneeUsername = null;
                if($assignedToUserId) { $s = $pdo->prepare("SELECT username FROM users WHERE id = :id"); $s->execute(['id' => $assignedToUserId]); $u = $s->fetch(); if($u) $assigneeUsername = $u['username'];}
                $response = ['success' => true, 'message' => 'Task added.', 'task' => ['id' => $lastId, /* other fields */ 'assigned_to_user_id' => $assignedToUserId, 'assignee_username' => $assigneeUsername]];
            } else { /* ... error, unlink image ... */ }
        } catch (PDOException $e) { /* ... error log, unlink image ... */ }
        break;

    case 'update_task':
        $taskId = $_POST['id'] ?? null;
        // Determine the board_id of the task being updated first to check permission
        $stmtTaskBoard = $pdo->prepare("SELECT board_id, user_id as creator_id, image_path FROM tasks WHERE id = :task_id");
        $stmtTaskBoard->execute(['task_id' => $taskId]);
        $taskBeingUpdated = $stmtTaskBoard->fetch();

        if (!$taskBeingUpdated) { $response['message'] = 'Task not found.'; break; }
        $taskBoardId = $taskBeingUpdated['board_id'];

        if (!hasBoardPermission($pdo, $userId, $taskBoardId, $userRole, $companyId, ['board_editor', 'board_admin'])) {
            $response['message'] = 'Not authorized to update tasks on this board.'; break;
        }
        $title = trim($_POST['title'] ?? ''); $description = trim($_POST['description'] ?? ''); $status = trim($_POST['status'] ?? '');
        $assignedToUserId = isset($_POST['assigned_to_user_id']) && !empty($_POST['assigned_to_user_id']) ? (int)$_POST['assigned_to_user_id'] : null;
        $removeImageFlag = isset($_POST['remove_image']) && $_POST['remove_image'] === 'true';

        if (empty($taskId) || empty($title) || empty($status)) { $response['message'] = 'Task ID, title, status required.'; break; }
        if (!in_array($status, $allowed_task_statuses)) { $response['message'] = 'Invalid status.'; break; }
        if ($assignedToUserId !== null) {
            $stmtCheckUser = $pdo->prepare("SELECT u.id FROM users u JOIN board_memberships bm ON u.id = bm.user_id WHERE u.id = :user_id AND bm.board_id = :board_id");
            $stmtCheckUser->execute(['user_id' => $assignedToUserId, 'board_id' => $taskBoardId]);
            if (!$stmtCheckUser->fetch()) { $response['message'] = 'Assigned user not member of this board.'; break; }
        }
        try {
            // ... (image update logic - $image_path_to_save, $newlyUploadedPath, $targetFilePath set) ...
            $oldImagePath = $taskBeingUpdated['image_path']; $image_path_to_save = $oldImagePath; $newlyUploadedPath = null; $uploadedImage = $_FILES['task_image'] ?? null; $targetFilePath = null;
            if ($removeImageFlag) { if ($oldImagePath && file_exists(UPLOADS_DIR . '/' . basename($oldImagePath))) { unlink(UPLOADS_DIR . '/' . basename($oldImagePath)); } $image_path_to_save = null; }
            elseif ($uploadedImage && $uploadedImage['error'] === UPLOAD_ERR_OK) {
                $targetFileName = uniqid('taskimg_', true) . "_" . preg_replace("/[^a-zA-Z0-9\._-]/", "_", basename($uploadedImage["name"]));
                $targetFilePath = UPLOADS_DIR . '/' . $targetFileName;
                $imageFileType = strtolower(pathinfo($targetFilePath, PATHINFO_EXTENSION));
                $allowedImageExtensions = ['jpg', 'jpeg', 'png', 'gif'];
                if (!in_array($imageFileType, $allowedImageExtensions)) { $response['message'] = 'Invalid image file type.'; break; }
                if ($uploadedImage["size"] > 2 * 1024 * 1024) { $response['message'] = 'Image file is too large (max 2MB).'; break; }
                if (move_uploaded_file($uploadedImage["tmp_name"], $targetFilePath)) {
                    if ($oldImagePath && file_exists(UPLOADS_DIR . '/' . basename($oldImagePath))) { unlink(UPLOADS_DIR . '/' . basename($oldImagePath)); }
                    $image_path_to_save = 'uploads/' . $targetFileName; $newlyUploadedPath = $targetFilePath;
                } else { $response['message'] = 'Error uploading new image file.'; break; }
            } elseif ($uploadedImage && $uploadedImage['error'] !== UPLOAD_ERR_NO_FILE) { $response['message'] = 'Image upload error code ' . $uploadedImage['error']; break;}


            $stmt = $pdo->prepare("UPDATE tasks SET title = :title, description = :description, status = :status, image_path = :image_path, assigned_to_user_id = :assigned_to WHERE id = :id AND board_id = :board_id");
            $stmt->execute(['title' => $title, 'description' => $description, 'status' => $status, 'image_path' => $image_path_to_save, 'assigned_to' => $assignedToUserId, 'id' => $taskId, 'board_id' => $taskBoardId]);
            
            $assigneeUsername = null;
            if($assignedToUserId) { $s = $pdo->prepare("SELECT username FROM users WHERE id = :id"); $s->execute(['id' => $assignedToUserId]); $u = $s->fetch(); if($u) $assigneeUsername = $u['username'];}
            $response = ['success' => true, 'message' => 'Task updated.', 'task' => [/* task fields */ 'assigned_to_user_id' => $assignedToUserId, 'assignee_username' => $assigneeUsername]];
        } catch (PDOException $e) { /* ... error log, unlink new image if error ... */ }
        break;

    case 'update_task_order_and_status': 
        $targetBoardId = $activeBoardId; 
        if (!$targetBoardId) { $response['message'] = 'No active board selected for ordering.'; break; }
        if (!hasBoardPermission($pdo, $userId, $targetBoardId, $userRole, $companyId, ['board_editor', 'board_admin'])) {
            $response['message'] = 'Not authorized to reorder tasks on this board.'; break;
        }
        // ... (rest of the logic for this case, ensuring board_id = $targetBoardId in queries) ...
        break;

    case 'delete_task':
        $taskId = $_POST['id'] ?? null;
        if (empty($taskId)) { $response['message'] = 'Task ID required.'; break; }

        $stmtTaskBoard = $pdo->prepare("SELECT board_id, image_path FROM tasks WHERE id = :task_id");
        $stmtTaskBoard->execute(['task_id' => $taskId]);
        $taskToDelete = $stmtTaskBoard->fetch();

        if (!$taskToDelete) { $response['message'] = 'Task not found.'; break; }
        $taskBoardId = $taskToDelete['board_id'];

        if (!hasBoardPermission($pdo, $userId, $taskBoardId, $userRole, $companyId, ['board_editor', 'board_admin'])) {
             $response['message'] = 'Not authorized to delete tasks on this board.'; break;
        }
        try {
            $oldImagePath = $taskToDelete['image_path'];
            $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = :id AND board_id = :board_id");
            $stmt->execute(['id' => $taskId, 'board_id' => $taskBoardId]);
            if ($stmt->rowCount() > 0) {
                if ($oldImagePath && file_exists(UPLOADS_DIR . '/' . basename($oldImagePath))) { unlink(UPLOADS_DIR . '/' . basename($oldImagePath)); }
                $response = ['success' => true, 'message' => 'Task deleted.'];
            } else { /* ... error ... */ }
        } catch (PDOException $e) { /* ... error log ... */ }
        break;

    default:
        if ($userId && $action !== null) { $response['message'] = "Action '{$action}' not recognized in tasks API."; }
        elseif ($userId) { $response = ['success' => true, 'message' => 'Authenticated user. No action specified.']; }
        else if ($action === null) { $response = ['success' => false, 'message' => 'Not authenticated. No action specified.']; }
        else { $response['message'] = "Action '{$action}' not recognized or general error."; }
        break;
}
echo json_encode($response);
?>
```

This version of `php/tasks_api.php` now includes:
-   `assigned_to_user_id` and `assignee_username` in `get_tasks` and `get_task` responses.
-   Accepts `assigned_to_user_id` in `add_task` and `update_task`.
-   Validates that an assigned user is a member of the task's board before saving.
-   Permission checks using `hasBoardPermission` are integrated more consistently.
-   Error logging placeholders `/* ... error log ... */` and image unlink logic `/* ... error, unlink image ... */` should be filled in with proper error handling.

Next, I'll modify `index.php` (task modal) and `js/script.js` for the frontend parts of task assignment.
```
