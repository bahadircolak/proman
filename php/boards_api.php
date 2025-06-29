<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

header('Content-Type: application/json');
$response = ['success' => false, 'message' => 'Invalid request.'];
$action = $_REQUEST['action'] ?? null;

// --- Authentication & Company Context Check ---
if (!isset($_SESSION['user_id'])) {
    $response['message'] = 'Authentication required.';
    $response['redirectToLogin'] = true;
    echo json_encode($response);
    exit;
}
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['user_role'] ?? 'user';
$companyId = $_SESSION['company_id'] ?? null;

if (!$companyId && !in_array($action, ['list_my_boards'])) { // list_my_boards might try to re-fetch company_id if session is out of sync
    // For most board actions, a company context is required.
    // Super admins might be an exception for some listing/management later.
    // We can refine this check per action if needed.
    $stmtUserCompany = $pdo->prepare("SELECT company_id FROM users WHERE id = :user_id");
    $stmtUserCompany->execute(['user_id' => $userId]);
    $userCompanyInfo = $stmtUserCompany->fetch();
    if ($userCompanyInfo && $userCompanyInfo['company_id']) {
        $companyId = $userCompanyInfo['company_id'];
        $_SESSION['company_id'] = $companyId; // Correct session
    } else if ($userRole !== 'super_admin') { // Super admin might not need a company for some views
        $response['message'] = 'User not associated with a company. Cannot manage boards.';
        $response['no_company'] = true;
        echo json_encode($response);
        exit;
    }
}


// Apply CSRF protection for state-changing POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfProtectedActions = ['create_board', 'update_board_details', 'delete_board', 'set_active_board'];
    if (in_array($action, $csrfProtectedActions)) {
        verifyCsrfTokenProtection();
    }
}

try {
    $pdo = getDBConnection();
} catch (PDOException $e) {
    $response['message'] = 'Database connection error.';
    error_log("Boards API DB Error: " . $e->getMessage());
    echo json_encode($response);
    exit;
}

switch ($action) {
    case 'create_board':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $response['message'] = 'Invalid request method.'; break;
        }
        if (!$companyId && $userRole !== 'super_admin') { // Super admin cannot create boards for a null company
             $response['message'] = 'No company context to create a board in.'; break;
        }
        // If super_admin, they might need to specify a company_id to create a board for.
        // For now, assume company_admin or user creates for their own company.
        $targetCompanyId = ($userRole === 'super_admin' && isset($_POST['company_id'])) ? $_POST['company_id'] : $companyId;
        if (!$targetCompanyId) {
            $response['message'] = 'Target company ID not specified for board creation.'; break;
        }


        $boardName = trim($_POST['board_name'] ?? '');
        $boardDescription = trim($_POST['board_description'] ?? '');

        if (empty($boardName)) {
            $response['message'] = 'Board name is required.'; break;
        }
        // TODO: Add permission check: only company_admin or users with specific rights can create boards.
        // For now, any user in the company can create a board.

        try {
            $stmt = $pdo->prepare("INSERT INTO boards (company_id, name, description, created_by_user_id) VALUES (:company_id, :name, :description, :created_by_user_id)");
            $stmt->execute([
                'company_id' => $targetCompanyId,
                'name' => $boardName,
                'description' => $boardDescription,
                'created_by_user_id' => $userId
            ]);
            $boardId = $pdo->lastInsertId();
            if ($boardId) {
                // Add creator as board_admin to board_memberships
                $stmtMember = $pdo->prepare("INSERT INTO board_memberships (board_id, user_id, role) VALUES (:board_id, :user_id, 'board_admin')");
                $stmtMember->execute(['board_id' => $boardId, 'user_id' => $userId]);

                if ($stmtMember->rowCount() > 0) {
                    $pdo->commit();
                    $_SESSION['active_board_id'] = $boardId; // Set as active
                    $response = ['success' => true, 'message' => 'Board created successfully!', 'board' => ['id' => $boardId, 'company_id' => $targetCompanyId, 'name' => $boardName, 'description' => $boardDescription]];
                } else {
                    $pdo->rollBack();
                    $response['message'] = 'Failed to assign board admin role to creator.';
                }
            } else {
                $pdo->rollBack();
                $response['message'] = 'Failed to create board.';
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $response['message'] = 'Database error creating board.';
            error_log("Create Board DB Error: " . $e->getMessage());
        }
        break;

    case 'list_my_boards': 
        $targetCompanyIdForList = $companyId; // Default to user's session company ID

        if ($userRole === 'super_admin') {
            if (isset($_GET['company_id_filter']) && !empty($_GET['company_id_filter'])) {
                $targetCompanyIdForList = $_GET['company_id_filter'];
            } else {
                // Super admin not filtering by company: list all boards they have explicit membership on, OR all boards if a flag is set
                // For now, let's require SA to filter by company or list boards they are direct members of.
                // This query lists boards user is a member of, OR if SA and no filter, it is problematic without a company context.
                // Let's adjust: SA must provide company_id_filter to use this endpoint this way, or we list ALL boards in system (future)
                // For now, if SA and no filter, it will use their session company_id if set, or error if not.
                if (!$targetCompanyIdForList) {
                     // $response['message'] = 'Super Admin: Please filter by company or select a company context to list boards.';
                     // $response['boards'] = []; // Or list all boards they are members of across all companies
                     // For now, let's make it list boards they are members of, regardless of company context, for SA
                     try {
                        $stmt = $pdo->prepare("SELECT b.id, b.name, b.description, b.company_id 
                                               FROM boards b
                                               JOIN board_memberships bm ON b.id = bm.board_id
                                               WHERE bm.user_id = :user_id
                                               ORDER BY b.name ASC");
                        $stmt->execute(['user_id' => $userId]);
                        $boards = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        $response = ['success' => true, 'boards' => $boards];
                        if (isset($_SESSION['active_board_id'])) $response['active_board_id'] = $_SESSION['active_board_id'];
                        echo json_encode($response); exit;
                     } catch (PDOException $e) { /* ... error ...*/ }
                }
            }
        }
        
        if (!$targetCompanyIdForList) { // If still no target company ID (e.g. user not in company)
            $stmtUser = $pdo->prepare("SELECT company_id FROM users WHERE id = :user_id");
            $stmtUser->execute(['user_id' => $userId]);
            $userCompany = $stmtUser->fetch();
            if ($userCompany && $userCompany['company_id']) {
                 $targetCompanyIdForList = $userCompany['company_id'];
                 $_SESSION['company_id'] = $targetCompanyIdForList;
            } else {
                $response['message'] = 'User not associated with a company.';
                $response['no_company'] = true;
                echo json_encode($response); exit;
            }
        }

        try {
            // List boards user is a member of OR (if company_admin) all boards in their company.
            if ($userRole === 'company_admin' && $targetCompanyIdForList == $companyId) { // List all boards in their company
                 $stmt = $pdo->prepare("SELECT id, name, description FROM boards WHERE company_id = :company_id ORDER BY name ASC");
                 $stmt->execute(['company_id' => $targetCompanyIdForList]);
            } else { // List only boards they are a member of in the target company
                 $stmt = $pdo->prepare("SELECT b.id, b.name, b.description 
                                       FROM boards b
                                       JOIN board_memberships bm ON b.id = bm.board_id
                                       WHERE bm.user_id = :user_id AND b.company_id = :company_id
                                       ORDER BY b.name ASC");
                 $stmt->execute(['user_id' => $userId, 'company_id' => $targetCompanyIdForList]);
            }
            $boards = $stmt->fetchAll();
            $response = ['success' => true, 'boards' => $boards];
            if (isset($_SESSION['active_board_id'])) {
                $response['active_board_id'] = $_SESSION['active_board_id'];
            }
        } catch (PDOException $e) {
            $response['message'] = 'Database error listing boards.';
            error_log("List Boards DB Error: " . $e->getMessage());
        }
        break;

    case 'set_active_board':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { $response['message'] = 'Invalid request method.'; break; }
        $boardId = $_POST['board_id'] ?? null;
        if (empty($boardId)) { $response['message'] = 'Board ID is required.'; break; }

        try {
            // Verify user has at least viewer permission for the board
            if (hasBoardPermission($pdo, $userId, $boardId, $userRole, $companyId, ['board_viewer', 'board_editor', 'board_admin'])) {
                $_SESSION['active_board_id'] = $boardId;
                // Also update session's company_id context if user is SA and selected a board from another company
                if ($userRole === 'super_admin') {
                    $stmtBoard = $pdo->prepare("SELECT company_id FROM boards WHERE id = :board_id");
                    $stmtBoard->execute(['board_id' => $boardId]);
                    $boardInfo = $stmtBoard->fetch();
                    if ($boardInfo) $_SESSION['active_company_id_context_sa'] = $boardInfo['company_id']; else  unset($_SESSION['active_company_id_context_sa']);
                }
                $response = ['success' => true, 'message' => 'Active board set.', 'active_board_id' => $boardId];
            } else {
                $response['message'] = 'Board not found or not authorized for selection.';
            }
        } catch (PDOException $e) {
            $response['message'] = 'Database error setting active board.';
            error_log("Set Active Board DB Error: " . $e->getMessage());
        }
        break;

    case 'get_board_details': 
        $boardId = $_REQUEST['board_id'] ?? ($_SESSION['active_board_id'] ?? null);
        if (empty($boardId)) { $response['message'] = 'Board ID not provided and no active board set.'; break; }
        
        try {
            if (hasBoardPermission($pdo, $userId, $boardId, $userRole, $companyId, ['board_viewer', 'board_editor', 'board_admin'])) {
                $stmt = $pdo->prepare("SELECT id, name, description, company_id FROM boards WHERE id = :board_id");
                $stmt->execute(['board_id' => $boardId]);
                $board = $stmt->fetch();
                if ($board) {
                    $response = ['success' => true, 'board' => $board];
                } else {
                    $response['message'] = 'Board not found.'; // Should be caught by hasBoardPermission if board doesn't exist
                }
            } else {
                $response['message'] = 'Not authorized to view this board\'s details.';
            }
        } catch (PDOException $e) {
            $response['message'] = 'Database error fetching board details.';
            error_log("Get Board Details DB Error: " . $e->getMessage());
        }
        break;

    case 'update_board_details':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { $response['message'] = 'Invalid request method.'; break; }
        $boardId = $_POST['board_id'] ?? null;
        $boardName = trim($_POST['board_name'] ?? '');
        $boardDescription = trim($_POST['board_description'] ?? '');

        if (empty($boardId) || empty($boardName)) { $response['message'] = 'Board ID and name are required.'; break; }
        
        try {
            if (hasBoardPermission($pdo, $userId, $boardId, $userRole, $companyId, ['board_admin'])) { // Only board_admin (or comp_admin/SA)
                $stmt = $pdo->prepare("UPDATE boards SET name = :name, description = :description WHERE id = :id");
                $stmt->execute(['name' => $boardName, 'description' => $boardDescription, 'id' => $boardId]);
                if ($stmt->rowCount() > 0) {
                    $response = ['success' => true, 'message' => 'Board details updated.'];
                } else {
                     $stmtCheck = $pdo->prepare("SELECT id FROM boards WHERE id = :id AND name = :name AND description <=> :description");
                     $stmtCheck->execute(['id' => $boardId, 'name' => $boardName, 'description' => $boardDescription]);
                     if ($stmtCheck->fetch()) {
                        $response = ['success' => true, 'message' => 'No changes made to board details.'];
                     } else {
                        $response['message'] = 'Failed to update board or board not found.'; // Should be caught by permission check mostly
                     }
                }
            } else {
                $response['message'] = 'Not authorized to update this board.';
            }
        } catch (PDOException $e) {
            $response['message'] = 'Database error updating board.';
            error_log("Update Board DB Error: " . $e->getMessage());
        }
        break;

    // TODO: delete_board action (needs permission check: board_admin/company_admin/SA)

    case 'add_user_to_board': // POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { $response['message'] = 'Invalid request method.'; break; }
        $boardId = $_POST['board_id'] ?? null;
        $userIdToAdd = $_POST['user_id_to_add'] ?? null;
        $boardRoleToAssign = $_POST['board_role'] ?? 'board_viewer'; // Default role
        $allowedBoardRoles = ['board_admin', 'board_editor', 'board_viewer'];

        if (empty($boardId) || empty($userIdToAdd) || !in_array($boardRoleToAssign, $allowedBoardRoles)) {
            $response['message'] = 'Board ID, User ID to add, and a valid role are required.'; break;
        }
        if (!hasBoardPermission($pdo, $userId, $boardId, $userRole, $companyId, ['board_admin'])) { // Only board_admin or higher can add users
            $response['message'] = 'Not authorized to add users to this board.'; break;
        }
        try {
            // Verify user_id_to_add belongs to the same company as the board
            $stmtUserCompany = $pdo->prepare("SELECT company_id FROM users WHERE id = :user_id_to_add");
            $stmtUserCompany->execute(['user_id_to_add' => $userIdToAdd]);
            $userToAddCompany = $stmtUserCompany->fetch();

            $stmtBoardCompany = $pdo->prepare("SELECT company_id FROM boards WHERE id = :board_id");
            $stmtBoardCompany->execute(['board_id' => $boardId]);
            $boardCompany = $stmtBoardCompany->fetch();

            if (!$userToAddCompany || !$boardCompany || $userToAddCompany['company_id'] != $boardCompany['company_id']) {
                $response['message'] = 'User to add does not belong to the same company as the board, or board/user not found.'; break;
            }

            // Check if user is already a member
            $stmtCheck = $pdo->prepare("SELECT id FROM board_memberships WHERE board_id = :board_id AND user_id = :user_id_to_add");
            $stmtCheck->execute(['board_id' => $boardId, 'user_id_to_add' => $userIdToAdd]);
            if ($stmtCheck->fetch()) {
                $response['message'] = 'User is already a member of this board. You can update their role.'; break;
            }

            $stmt = $pdo->prepare("INSERT INTO board_memberships (board_id, user_id, role) VALUES (:board_id, :user_id, :role)");
            $stmt->execute(['board_id' => $boardId, 'user_id' => $userIdToAdd, 'role' => $boardRoleToAssign]);
            if ($stmt->rowCount() > 0) {
                $response = ['success' => true, 'message' => 'User added to board successfully.'];
            } else {
                $response['message'] = 'Failed to add user to board.';
            }
        } catch (PDOException $e) {
            $response['message'] = 'Database error adding user to board: ' . $e->getMessage();
            error_log("Add User to Board DB Error: " . $e->getMessage());
        }
        break;

    case 'remove_user_from_board': // POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { $response['message'] = 'Invalid request method.'; break; }
        $boardId = $_POST['board_id'] ?? null;
        $userIdToRemove = $_POST['user_id_to_remove'] ?? null;

        if (empty($boardId) || empty($userIdToRemove)) {
            $response['message'] = 'Board ID and User ID to remove are required.'; break;
        }
        if ($userId == $userIdToRemove) { // Prevent admin from removing themselves this way (owner should handle it or separate logic)
             $response['message'] = 'Board admins cannot remove themselves directly. Another admin must do this.'; break;
        }
        if (!hasBoardPermission($pdo, $userId, $boardId, $userRole, $companyId, ['board_admin'])) {
            $response['message'] = 'Not authorized to remove users from this board.'; break;
        }
        try {
            // Check if the user to remove is the board creator - prevent removal if so (or transfer ownership first - more complex)
            $stmtBoardCreator = $pdo->prepare("SELECT created_by_user_id FROM boards WHERE id = :board_id");
            $stmtBoardCreator->execute(['board_id' => $boardId]);
            $boardMeta = $stmtBoardCreator->fetch();
            if ($boardMeta && $boardMeta['created_by_user_id'] == $userIdToRemove) {
                $response['message'] = 'Cannot remove the board creator. Change board ownership first.'; break;
            }


            $stmt = $pdo->prepare("DELETE FROM board_memberships WHERE board_id = :board_id AND user_id = :user_id_to_remove");
            $stmt->execute(['board_id' => $boardId, 'user_id_to_remove' => $userIdToRemove]);
            if ($stmt->rowCount() > 0) {
                $response = ['success' => true, 'message' => 'User removed from board successfully.'];
            } else {
                $response['message'] = 'Failed to remove user (not a member or already removed).';
            }
        } catch (PDOException $e) {
            $response['message'] = 'Database error removing user from board.';
            error_log("Remove User from Board DB Error: " . $e->getMessage());
        }
        break;

    case 'update_user_board_role': // POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { $response['message'] = 'Invalid request method.'; break; }
        $boardId = $_POST['board_id'] ?? null;
        $userIdToUpdate = $_POST['user_id_to_update'] ?? null;
        $newBoardRole = $_POST['new_board_role'] ?? '';
        $allowedBoardRoles = ['board_admin', 'board_editor', 'board_viewer'];

        if (empty($boardId) || empty($userIdToUpdate) || !in_array($newBoardRole, $allowedBoardRoles)) {
            $response['message'] = 'Board ID, User ID to update, and a valid new role are required.'; break;
        }
        if ($userId == $userIdToUpdate && $newBoardRole !== 'board_admin') { // Prevent admin from demoting themselves below admin
             $stmtBoardCreator = $pdo->prepare("SELECT created_by_user_id FROM boards WHERE id = :board_id");
             $stmtBoardCreator->execute(['board_id' => $boardId]);
             $boardMeta = $stmtBoardCreator->fetch();
             // Check if current user is the board creator and trying to demote self
             if ($boardMeta && $boardMeta['created_by_user_id'] == $userId) {
                $response['message'] = 'Board creator cannot demote themselves from board admin.'; break;
             }
        }
        if (!hasBoardPermission($pdo, $userId, $boardId, $userRole, $companyId, ['board_admin'])) {
            $response['message'] = 'Not authorized to update user roles on this board.'; break;
        }
        try {
            $stmt = $pdo->prepare("UPDATE board_memberships SET role = :new_role WHERE board_id = :board_id AND user_id = :user_id_to_update");
            $stmt->execute(['new_role' => $newBoardRole, 'board_id' => $boardId, 'user_id_to_update' => $userIdToUpdate]);
            if ($stmt->rowCount() > 0) {
                $response = ['success' => true, 'message' => 'User board role updated successfully.'];
            } else {
                $response['message'] = 'Failed to update role (user not a member or role unchanged).';
            }
        } catch (PDOException $e) {
            $response['message'] = 'Database error updating user board role.';
            error_log("Update User Board Role DB Error: " . $e->getMessage());
        }
        break;

    case 'list_board_members': // GET
        $boardId = $_GET['board_id'] ?? ($_SESSION['active_board_id'] ?? null);
        if (empty($boardId)) { $response['message'] = 'Board ID is required.'; break; }

        if (!hasBoardPermission($pdo, $userId, $boardId, $userRole, $companyId, ['board_viewer', 'board_editor', 'board_admin'])) {
            $response['message'] = 'Not authorized to view members of this board.'; break;
        }
        try {
            $stmt = $pdo->prepare("SELECT u.id as user_id, u.username, u.email, bm.role as board_role 
                                   FROM users u
                                   JOIN board_memberships bm ON u.id = bm.user_id
                                   WHERE bm.board_id = :board_id
                                   ORDER BY u.username ASC");
            $stmt->execute(['board_id' => $boardId]);
            $members = $stmt->fetchAll();
            $response = ['success' => true, 'members' => $members];
        } catch (PDOException $e) {
            $response['message'] = 'Database error listing board members.';
            error_log("List Board Members DB Error: " . $e->getMessage());
        }
        break;

    default:
        $response['message'] = "Action '{$action}' not recognized in boards API.";
        break;
}

echo json_encode($response);
?>
```

**2. Modify `php/tasks_api.php` to use `$_SESSION['active_board_id']`**

All task operations must now be scoped to the active board.
```
