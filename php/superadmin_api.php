<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php'; // For CSRF and potentially permission helpers

header('Content-Type: application/json');
$response = ['success' => false, 'message' => 'Unauthorized or invalid request.'];
$action = $_REQUEST['action'] ?? null;

// --- Super Admin Authentication Check ---
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'super_admin') {
    $response['message'] = 'Access denied: Super administrator privileges required.';
    $response['redirectToLogin'] = true; // Could also be a specific "access_denied" page
    http_response_code(403); // Forbidden
    echo json_encode($response);
    exit;
}

$userId = $_SESSION['user_id']; // Super Admin's ID

// Apply CSRF protection for state-changing POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Define actions that modify data and need CSRF protection
    $csrfProtectedActions = [
        'sa_update_company_details', 
        'sa_update_user_details', // e.g. change role, suspend
        'sa_delete_company',      // If implemented
        'sa_delete_user'          // If implemented
    ];
    if (in_array($action, $csrfProtectedActions)) {
        verifyCsrfTokenProtection();
    }
}

try {
    $pdo = getDBConnection();
} catch (PDOException $e) {
    $response['message'] = 'Database connection error.';
    http_response_code(500);
    error_log("SuperAdmin API DB Error: " . $e->getMessage());
    echo json_encode($response);
    exit;
}

switch ($action) {
    case 'sa_list_all_companies': // GET request
        try {
            $sql = "SELECT c.id, c.name, c.owner_user_id, u.username as owner_username, c.created_at, 
                           (SELECT COUNT(*) FROM users WHERE company_id = c.id) as user_count,
                           (SELECT COUNT(*) FROM boards WHERE company_id = c.id) as board_count
                    FROM companies c 
                    LEFT JOIN users u ON c.owner_user_id = u.id 
                    ORDER BY c.name ASC";
            $stmt = $pdo->query($sql);
            $companies = $stmt->fetchAll();
            $response = ['success' => true, 'companies' => $companies];
        } catch (PDOException $e) {
            $response['message'] = 'Database error listing all companies.';
            error_log("SA List All Companies DB Error: " . $e->getMessage());
        }
        break;

    case 'sa_list_all_users': // GET request
        try {
            // Exclude password_hash. Join with companies to get company name.
            $sql = "SELECT u.id, u.username, u.email, u.role, u.company_id, c.name as company_name, u.created_at 
                    FROM users u
                    LEFT JOIN companies c ON u.company_id = c.id
                    ORDER BY u.username ASC";
            $stmt = $pdo->query($sql);
            $users = $stmt->fetchAll();
            $response = ['success' => true, 'users' => $users];
        } catch (PDOException $e) {
            $response['message'] = 'Database error listing all users.';
            error_log("SA List All Users DB Error: " . $e->getMessage());
        }
        break;

    case 'sa_update_user_role': // POST request
        $userIdToUpdate = $_POST['user_id_to_update'] ?? null;
        $newRole = $_POST['new_role'] ?? null;
        // Super admin can set any of these roles.
        $allowedRoles = ['user', 'company_admin', 'super_admin']; 

        if (empty($userIdToUpdate) || empty($newRole) || !in_array($newRole, $allowedRoles)) {
            $response['message'] = 'User ID and a valid new role are required.'; break;
        }
        // Super admin cannot demote the last super admin (or themselves if they are the only one)
        if ($newRole !== 'super_admin') {
            $stmtCheckLastSA = $pdo->prepare("SELECT COUNT(*) as sa_count FROM users WHERE role = 'super_admin'");
            $stmtCheckLastSA->execute();
            $saCount = $stmtCheckLastSA->fetchColumn();

            $stmtCurrentUserRole = $pdo->prepare("SELECT role FROM users WHERE id = :uid");
            $stmtCurrentUserRole->execute(['uid' => $userIdToUpdate]);
            $userBeingUpdatedCurrentRole = $stmtCurrentUserRole->fetchColumn();

            if ($saCount <= 1 && $userBeingUpdatedCurrentRole === 'super_admin') {
                $response['message'] = 'Cannot remove the last super admin role.'; break;
            }
        }
        // Prevent SA from changing their own role if they are the only SA (covered above for demotion)
        // If they are changing another SA's role, it's fine.

        try {
            $stmt = $pdo->prepare("UPDATE users SET role = :new_role WHERE id = :user_id_to_update");
            $stmt->execute(['new_role' => $newRole, 'user_id_to_update' => $userIdToUpdate]);
            if ($stmt->rowCount() > 0) {
                $response = ['success' => true, 'message' => 'User role updated successfully.'];
            } else {
                $response['message'] = 'Failed to update user role (user not found or role unchanged).';
            }
        } catch (PDOException $e) {
            $response['message'] = 'Database error updating user role.';
            error_log("SA Update User Role DB Error: " . $e->getMessage());
        }
        break;
    
    case 'sa_update_user_company': // POST
        $userIdToUpdate = $_POST['user_id_to_update'] ?? null;
        // company_id can be null to remove user from company
        $newCompanyId = (isset($_POST['company_id']) && $_POST['company_id'] !== '' && $_POST['company_id'] !== '0') ? (int)$_POST['company_id'] : null;

        if (empty($userIdToUpdate)) { $response['message'] = 'User ID is required.'; break; }
        if ($newCompanyId !== null) { // Validate new company ID if provided
            $stmtCheckCompany = $pdo->prepare("SELECT id FROM companies WHERE id = :company_id");
            $stmtCheckCompany->execute(['company_id' => $newCompanyId]);
            if (!$stmtCheckCompany->fetch()) { $response['message'] = 'Specified company does not exist.'; break; }
        }
        try {
            // If removing from company, also remove from all board memberships of that company and set role to 'user'
            // If assigning to a new company, their role might need to be 'user' by default in the new company.
            $pdo->beginTransaction();

            $stmtUser = $pdo->prepare("SELECT company_id, role FROM users WHERE id = :user_id");
            $stmtUser->execute(['user_id' => $userIdToUpdate]);
            $currentUserData = $stmtUser->fetch();
            if(!$currentUserData){ $response['message'] = 'User not found.'; $pdo->rollBack(); break; }

            $currentCompanyId = $currentUserData['company_id'];
            $currentRole = $currentUserData['role'];
            $roleToSet = $currentRole; // Keep current role unless logic dictates otherwise

            if ($currentCompanyId !== null && $newCompanyId != $currentCompanyId) { // User is being moved from a company or removed
                 // Remove from all board_memberships of the old company
                $stmtRemoveMemberships = $pdo->prepare("DELETE bm FROM board_memberships bm JOIN boards b ON bm.board_id = b.id WHERE bm.user_id = :user_id AND b.company_id = :old_company_id");
                $stmtRemoveMemberships->execute(['user_id' => $userIdToUpdate, 'old_company_id' => $currentCompanyId]);
                
                // If removed from a company (newCompanyId is null) or moved to a new one,
                // and they were company_admin, demote to 'user' unless they are super_admin
                if ($currentRole === 'company_admin' && $currentUserData['role'] !== 'super_admin') {
                    $roleToSet = 'user';
                }
            }
            
            // If user is SA, their company_id change doesn't affect their SA role.
            // If they are assigned to a new company, they become a regular 'user' in that company
            // unless explicitly made company_admin of new company by another action.
            if ($newCompanyId !== null && $currentCompanyId != $newCompanyId && $roleToSet !== 'super_admin') {
                 $roleToSet = 'user'; // Default role in new company
            }


            $stmt = $pdo->prepare("UPDATE users SET company_id = :company_id, role = :role WHERE id = :user_id_to_update");
            $stmt->execute(['company_id' => $newCompanyId, 'role' => $roleToSet, 'user_id_to_update' => $userIdToUpdate]);

            if ($stmt->rowCount() > 0) {
                $pdo->commit();
                $response = ['success' => true, 'message' => 'User company association updated.'];
            } else {
                $pdo->rollBack();
                $response['message'] = 'No changes made to user company association.';
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $response['message'] = 'Database error updating user company.';
            error_log("SA Update User Company DB Error: " . $e->getMessage());
        }
        break;
        
    // TODO: sa_update_company_details (name, owner_user_id)
    // TODO: sa_delete_company (careful with cascading effects)
    // TODO: sa_delete_user (careful with ownerships)
    // TODO: Basic reporting endpoints (counts, etc.)

    default:
        $response['message'] = "Action '{$action}' not recognized in SuperAdmin API.";
        http_response_code(404);
        break;
}

echo json_encode($response);
?>
```
This creates `php/superadmin_api.php` with:
*   Strict check for `$_SESSION['user_role'] === 'super_admin'`.
*   CSRF protection for POST actions.
*   `sa_list_all_companies`: Fetches all companies with owner username, user count, and board count.
*   `sa_list_all_users`: Fetches all users with their company name and role.
*   `sa_update_user_role`: Allows SA to change any user's role (user, company_admin, super_admin), with a safeguard against removing the last SA.
*   `sa_update_user_company`: Allows SA to assign a user to a company, change their company, or remove them from a company (sets company_id to NULL and role to 'user', removes from old company's boards).

The next sub-step is to create the basic frontend structure for the Super Admin Dashboard in `index.php` and a new `js/superadmin.js`.
```
