<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

header('Content-Type: application/json');
$response = ['success' => false, 'message' => 'Invalid request.'];
$action = $_REQUEST['action'] ?? null; // Using $_REQUEST to handle GET for 'details', POST for others

// --- Authentication Check ---
if (!isset($_SESSION['user_id'])) {
    $response['message'] = 'Authentication required. Please login.';
    $response['redirectToLogin'] = true;
    echo json_encode($response);
    exit;
}
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['user_role'] ?? 'user'; // Assuming user_role is set in session upon login

// Apply CSRF protection for state-changing POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfProtectedActions = ['create_company', 'update_company_details'];
    if (in_array($action, $csrfProtectedActions)) {
        verifyCsrfTokenProtection();
    }
}

try {
    $pdo = getDBConnection();
} catch (PDOException $e) {
    $response['message'] = 'Database connection error.';
    error_log("Company API DB Error: " . $e->getMessage());
    echo json_encode($response);
    exit;
}

switch ($action) {
    case 'create_company':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $response['message'] = 'Invalid request method for company creation.';
            break;
        }
        $companyName = trim($_POST['company_name'] ?? '');
        if (empty($companyName)) {
            $response['message'] = 'Company name is required.';
            break;
        }

        $stmtCheckUserCompany = $pdo->prepare("SELECT company_id FROM users WHERE id = :user_id");
        $stmtCheckUserCompany->execute(['user_id' => $userId]);
        $userCompanyInfo = $stmtCheckUserCompany->fetch();

        // Allow super_admin to create companies even if they are "associated" with one (their role transcends this)
        // Or if they are not associated with any.
        // Normal users can only create if not already in a company.
        if ($userRole !== 'super_admin' && $userCompanyInfo && $userCompanyInfo['company_id'] !== null) {
            $response['message'] = 'You already belong to or own a company. Only Super Admins can create multiple.';
            break;
        }

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO companies (name, owner_user_id) VALUES (:name, :owner_user_id)");
            // If a super_admin creates a company, they are the owner, but they don't become company_admin of it.
            // A regular user creating a company becomes its owner AND company_admin.
            $stmt->execute(['name' => $companyName, 'owner_user_id' => $userId]);
            $companyId = $pdo->lastInsertId();

            if ($companyId) {
                if ($userRole !== 'super_admin') {
                    $stmtUser = $pdo->prepare("UPDATE users SET company_id = :company_id, role = 'company_admin' WHERE id = :user_id");
                    $stmtUser->execute(['company_id' => $companyId, 'user_id' => $userId]);

                    if ($stmtUser->rowCount() > 0) {
                        $_SESSION['company_id'] = $companyId; 
                        $_SESSION['user_role'] = 'company_admin'; 
                    } else {
                        $pdo->rollBack();
                        $response['message'] = 'Failed to assign user to the new company.';
                        echo json_encode($response); exit;
                    }
                }
                $pdo->commit();
                $response = ['success' => true, 'message' => 'Company created successfully!', 'company' => ['id' => $companyId, 'name' => $companyName, 'owner_user_id' => $userId]];
            } else {
                $pdo->rollBack();
                $response['message'] = 'Failed to create company.';
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $response['message'] = 'Database error during company creation.';
            error_log("Create Company DB Error: " . $e->getMessage());
        }
        break;

    case 'get_my_company_details': 
        $companyIdForDetails = $_SESSION['company_id'] ?? null;
        
        // Super admin can request details for any company by providing company_id
        if ($userRole === 'super_admin' && isset($_GET['company_id'])) {
            $companyIdForDetails = $_GET['company_id'];
        }

        if (!$companyIdForDetails && $userRole !== 'super_admin') { // Non-SA must have a company context
            $stmtUser = $pdo->prepare("SELECT company_id FROM users WHERE id = :user_id");
            $stmtUser->execute(['user_id' => $userId]);
            $userCompany = $stmtUser->fetch();
            if ($userCompany && $userCompany['company_id']) {
                 $companyIdForDetails = $userCompany['company_id'];
                 $_SESSION['company_id'] = $companyIdForDetails; 
            } else {
                 $response['message'] = 'You are not currently associated with any company.';
                 $response['no_company'] = true;
                 echo json_encode($response); exit;
            }
        } elseif (!$companyIdForDetails && $userRole === 'super_admin') {
            // Super admin needs to specify which company if not using their own (if any)
            $response['message'] = 'Super Admin: Please specify a company_id to get details, or no company context is set.';
            $response['no_company_context_sa'] = true;
            echo json_encode($response); exit;
        }
        
        try {
            $stmt = $pdo->prepare("SELECT c.id, c.name, c.owner_user_id, u_owner.username as owner_username, c.created_at 
                                   FROM companies c 
                                   LEFT JOIN users u_owner ON c.owner_user_id = u_owner.id
                                   WHERE c.id = :company_id");
            $stmt->execute(['company_id' => $companyIdForDetails]);
            $company = $stmt->fetch();

            if ($company) {
                 // Authorization: User must belong to this company OR be a super_admin
                if ($userRole === 'super_admin' || ($company['id'] == ($_SESSION['company_id'] ?? null))) {
                     $response = ['success' => true, 'company' => $company];
                } else {
                     $response['message'] = 'Not authorized to view these company details (mismatch).';
                }
            } else {
                $response['message'] = 'Company details not found for ID: ' . $companyIdForDetails;
                if ($companyIdForDetails == ($_SESSION['company_id'] ?? null)) {
                    // If the company in session was not found, clear it from session
                    // This might happen if company was deleted externally
                    unset($_SESSION['company_id']);
                }
            }
        } catch (PDOException $e) {
            $response['message'] = 'Database error fetching company details.';
            error_log("Get Company Details DB Error: " . $e->getMessage());
        }
        break;

    case 'update_company_details':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $response['message'] = 'Invalid request method for updating company.'; break;
        }
        
        // If super_admin, they must provide company_id_to_update.
        // If company_admin, it defaults to their session company_id.
        $companyIdToUpdate = null;
        if ($userRole === 'super_admin') {
            $companyIdToUpdate = $_POST['company_id_to_update'] ?? null;
            if (!$companyIdToUpdate) {
                $response['message'] = 'Super Admin: company_id_to_update is required.'; break;
            }
        } else {
            $companyIdToUpdate = $_SESSION['company_id'] ?? null;
        }
        
        $newCompanyName = trim($_POST['company_name'] ?? '');

        if (empty($companyIdToUpdate)) {
            $response['message'] = 'Company ID to update is missing or you are not part of a company.'; break;
        }
        if (empty($newCompanyName)) {
            $response['message'] = 'Company name cannot be empty.'; break;
        }

        try {
            $stmtCheckCompany = $pdo->prepare("SELECT owner_user_id FROM companies WHERE id = :company_id");
            $stmtCheckCompany->execute(['company_id' => $companyIdToUpdate]);
            $companyData = $stmtCheckCompany->fetch();

            if (!$companyData) {
                 $response['message'] = 'Company not found.'; break;
            }

            $isOwner = ($companyData['owner_user_id'] == $userId);
            // company_admin can only edit their own company
            $isCompanyAdminOfThisCompany = ($userRole === 'company_admin' && ($_SESSION['company_id'] ?? null) == $companyIdToUpdate);

            if (!$isOwner && !$isCompanyAdminOfThisCompany && $userRole !== 'super_admin') {
                 $response['message'] = 'Not authorized to update this company\'s details.'; break;
            }

            $stmt = $pdo->prepare("UPDATE companies SET name = :name WHERE id = :id");
            $stmt->execute(['name' => $newCompanyName, 'id' => $companyIdToUpdate]);

            if ($stmt->rowCount() > 0) {
                $response = ['success' => true, 'message' => 'Company details updated successfully.', 'company' => ['id' => $companyIdToUpdate, 'name' => $newCompanyName]];
            } else {
                // Check if the name was simply not changed
                $stmtCheckName = $pdo->prepare("SELECT name FROM companies WHERE id = :id AND name = :name");
                $stmtCheckName->execute(['id' => $companyIdToUpdate, 'name' => $newCompanyName]);
                if ($stmtCheckName->fetch()) {
                    $response = ['success' => true, 'message' => 'No changes detected in company name.'];
                } else {
                    $response['message'] = 'Failed to update company or company not found.';
                }
            }
        } catch (PDOException $e) {
            $response['message'] = 'Database error updating company details.';
            error_log("Update Company Details DB Error: " . $e->getMessage());
        }
        break;
    
    case 'list_all_companies': // For Super Admin
        if ($userRole !== 'super_admin') {
            $response['message'] = 'Unauthorized: Only super admins can list all companies.';
            break;
        }
        try {
            $sql = "SELECT c.id, c.name, c.owner_user_id, u.username as owner_username, c.created_at 
                    FROM companies c 
                    LEFT JOIN users u ON c.owner_user_id = u.id 
                    ORDER BY c.name ASC";
            $stmt = $pdo->query($sql);
            $companies = $stmt->fetchAll();
            $response = ['success' => true, 'companies' => $companies];
        } catch (PDOException $e) {
            $response['message'] = 'Database error listing all companies.';
            error_log("List All Companies DB Error (SA): " . $e->getMessage());
        }
        break;

    case 'invite_user_to_company':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { $response['message'] = 'Invalid request method.'; break; }
        if ($userRole !== 'company_admin' && $userRole !== 'super_admin') { // SA might invite to any company
            $response['message'] = 'Unauthorized: Only company admins or super admins can invite users.'; break;
        }

        $invitedEmail = trim($_POST['email'] ?? '');
        $targetCompanyId = ($userRole === 'super_admin' && isset($_POST['company_id'])) ? $_POST['company_id'] : $companyId;

        if (empty($invitedEmail) || !filter_var($invitedEmail, FILTER_VALIDATE_EMAIL)) {
            $response['message'] = 'Valid email address is required for invitation.'; break;
        }
        if (empty($targetCompanyId)) {
            $response['message'] = 'Target company ID is missing.'; break;
        }

        try {
            // Check if user is already in ANY company or specifically this one
            $stmtCheckExistingUser = $pdo->prepare("SELECT id, company_id FROM users WHERE email = :email");
            $stmtCheckExistingUser->execute(['email' => $invitedEmail]);
            $existingUser = $stmtCheckExistingUser->fetch();

            if ($existingUser && $existingUser['company_id'] == $targetCompanyId) {
                $response['message'] = 'This user is already a member of this company.'; break;
            }
            // If user exists and is in another company, current simple model doesn't allow joining another.
            // This can be changed later for multi-company membership.
            if ($existingUser && $existingUser['company_id'] !== null && $existingUser['company_id'] != $targetCompanyId) {
                 $response['message'] = 'This user is already a member of another company.'; break;
            }

            // Check for existing pending invitation for this email to this company
            $stmtCheckPending = $pdo->prepare("SELECT id FROM invitations WHERE email = :email AND company_id = :company_id AND status = 'pending'");
            $stmtCheckPending->execute(['email' => $invitedEmail, 'company_id' => $targetCompanyId]);
            if ($stmtCheckPending->fetch()) {
                $response['message'] = 'An invitation for this email to this company is already pending.'; break;
            }

            $invitationToken = bin2hex(random_bytes(32));
            // Expiry: e.g., 7 days from now
            $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));


            $stmtInvite = $pdo->prepare("INSERT INTO invitations (company_id, email, invitation_token, invited_by_user_id, expires_at) VALUES (:company_id, :email, :token, :invited_by, :expires_at)");
            $stmtInvite->execute([
                'company_id' => $targetCompanyId,
                'email' => $invitedEmail,
                'token' => $invitationToken,
                'invited_by' => $userId,
                'expires_at' => $expiresAt
            ]);

            if ($stmtInvite->rowCount() > 0) {
                // In a real app, you would send an email with the invitation link/token here.
                // For this exercise, we'll return the token for testing.
                $response = ['success' => true, 'message' => 'Invitation sent successfully.', 'invitation_token' => $invitationToken];
            } else {
                $response['message'] = 'Failed to create invitation.';
            }
        } catch (PDOException $e) {
            $response['message'] = 'Database error sending invitation.';
            error_log("Invite User DB Error: " . $e->getMessage());
        }
        break;

    case 'accept_company_invitation':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { $response['message'] = 'Invalid request method.'; break; }
        
        $token = trim($_POST['invitation_token'] ?? '');
        if (empty($token)) {
            $response['message'] = 'Invitation token is required.'; break;
        }

        try {
            $pdo->beginTransaction();
            $stmtInvite = $pdo->prepare("SELECT id, company_id, email, status, expires_at FROM invitations WHERE invitation_token = :token AND status = 'pending'");
            $stmtInvite->execute(['token' => $token]);
            $invitation = $stmtInvite->fetch();

            if (!$invitation) {
                $response['message'] = 'Invalid or expired invitation token.'; $pdo->rollBack(); break;
            }
            if ($invitation['expires_at'] !== null && strtotime($invitation['expires_at']) < time()) {
                // Update status to expired
                $stmtExpire = $pdo->prepare("UPDATE invitations SET status = 'expired' WHERE id = :id");
                $stmtExpire->execute(['id' => $invitation['id']]);
                $pdo->commit(); // Commit expiry update
                $response['message'] = 'Invitation has expired.'; break;
            }

            // User accepting must be logged in and their email must match the invitation email.
            // (Or if not logged in, they register with this email then accept) - this flow is more complex for frontend.
            // For now, assume user is logged in.
            $stmtUserCheck = $pdo->prepare("SELECT email, company_id FROM users WHERE id = :user_id");
            $stmtUserCheck->execute(['user_id' => $userId]);
            $currentUser = $stmtUserCheck->fetch();

            if (!$currentUser || $currentUser['email'] !== $invitation['email']) {
                $response['message'] = 'Invitation is not for your email address.'; $pdo->rollBack(); break;
            }
            if ($currentUser['company_id'] !== null) {
                $response['message'] = 'You are already part of a company.'; $pdo->rollBack(); break;
            }

            // Update user's company_id and set role to 'user' (can be changed by admin later)
            $stmtUpdateUser = $pdo->prepare("UPDATE users SET company_id = :company_id, role = 'user' WHERE id = :user_id");
            $stmtUpdateUser->execute(['company_id' => $invitation['company_id'], 'user_id' => $userId]);

            if ($stmtUpdateUser->rowCount() > 0) {
                // Update invitation status to 'accepted'
                $stmtUpdateInvite = $pdo->prepare("UPDATE invitations SET status = 'accepted' WHERE id = :id");
                $stmtUpdateInvite->execute(['id' => $invitation['id']]);
                
                $pdo->commit();
                $_SESSION['company_id'] = $invitation['company_id']; // Update session
                $_SESSION['user_role'] = 'user'; // Update session
                $response = ['success' => true, 'message' => 'Invitation accepted! You are now part of the company.'];
            } else {
                $pdo->rollBack();
                $response['message'] = 'Failed to update your user profile with the new company.';
            }

        } catch (PDOException $e) {
            $pdo->rollBack();
            $response['message'] = 'Database error accepting invitation.';
            error_log("Accept Invitation DB Error: " . $e->getMessage());
        }
        break;
    
    case 'get_pending_invitation_by_token': // GET request
        $token = trim($_GET['token'] ?? '');
        if (empty($token)) { $response['message'] = 'Token required.'; break; }
        try {
            $stmt = $pdo->prepare("SELECT i.email as invited_email, i.expires_at, c.name as company_name 
                                   FROM invitations i
                                   JOIN companies c ON i.company_id = c.id
                                   WHERE i.invitation_token = :token AND i.status = 'pending'");
            $stmt->execute(['token' => $token]);
            $invitationDetails = $stmt->fetch();
            if ($invitationDetails) {
                if ($invitationDetails['expires_at'] !== null && strtotime($invitationDetails['expires_at']) < time()) {
                     $response = ['success' => false, 'message' => 'This invitation has expired.'];
                } else {
                    $response = ['success' => true, 'invitation' => $invitationDetails];
                }
            } else {
                $response = ['success' => false, 'message' => 'Invitation not found or already processed.'];
            }
        } catch (PDOException $e) {
            $response['message'] = 'Database error fetching invitation details.';
            error_log("Get Invitation by Token DB Error: " . $e->getMessage());
        }
        break;

    case 'list_company_invitations': // GET, for company_admin / super_admin
        if ($userRole !== 'company_admin' && $userRole !== 'super_admin') {
            $response['message'] = 'Unauthorized.'; break;
        }
        $targetCompanyId = ($userRole === 'super_admin' && isset($_GET['company_id'])) ? $_GET['company_id'] : $companyId;
        if (!$targetCompanyId) { $response['message'] = 'Company context missing.'; break; }

        try {
            $stmt = $pdo->prepare("SELECT id, email, status, invited_by_user_id, expires_at, created_at FROM invitations WHERE company_id = :company_id ORDER BY created_at DESC");
            $stmt->execute(['company_id' => $targetCompanyId]);
            $invitations = $stmt->fetchAll();
            $response = ['success' => true, 'invitations' => $invitations];
        } catch (PDOException $e) {
            $response['message'] = 'Database error listing invitations.';
            error_log("List Company Invitations DB Error: " . $e->getMessage());
        }
        break;

    case 'list_company_users': // GET, for company_admin / super_admin
        if ($userRole !== 'company_admin' && $userRole !== 'super_admin') {
            $response['message'] = 'Unauthorized.'; break;
        }
        $targetCompanyId = ($userRole === 'super_admin' && isset($_GET['company_id'])) ? $_GET['company_id'] : $companyId;
        if (!$targetCompanyId) { $response['message'] = 'Company context missing.'; break; }

        try {
            // Select users of the specified company. Exclude sensitive info like password_hash.
            $stmt = $pdo->prepare("SELECT id, username, email, role FROM users WHERE company_id = :company_id ORDER BY username ASC");
            $stmt->execute(['company_id' => $targetCompanyId]);
            $users = $stmt->fetchAll();
            $response = ['success' => true, 'users' => $users];
        } catch (PDOException $e) {
            $response['message'] = 'Database error listing company users.';
            error_log("List Company Users DB Error: " . $e->getMessage());
        }
        break;

    case 'update_company_user_role': // POST
        if ($userRole !== 'company_admin' && $userRole !== 'super_admin') {
            $response['message'] = 'Unauthorized.'; break;
        }
        $userIdToUpdate = $_POST['user_id_to_update'] ?? null;
        $newRole = $_POST['new_role'] ?? null;
        $allowedCompanyRoles = ['user', 'company_admin']; // Roles a company_admin can set

        if (empty($userIdToUpdate) || empty($newRole) || !in_array($newRole, $allowedCompanyRoles)) {
            $response['message'] = 'User ID and a valid new role (user, company_admin) are required.'; break;
        }

        // Determine the company context for the update
        $adminCompanyId = ($userRole === 'super_admin') ? ($_POST['company_id_context'] ?? null) : $companyId;
        if (!$adminCompanyId && $userRole === 'super_admin') {
             $response['message'] = 'Super Admin: Company context ID is required to update user role.'; break;
        }
         if (!$adminCompanyId && $userRole !== 'super_admin') { // Should not happen if session is fine for company_admin
             $response['message'] = 'Company context not found for admin.'; break;
        }


        try {
            // Verify the user being updated belongs to the admin's company (or specified company for SA)
            $stmtCheckUser = $pdo->prepare("SELECT company_id, role FROM users WHERE id = :user_id_to_update");
            $stmtCheckUser->execute(['user_id_to_update' => $userIdToUpdate]);
            $userToUpdateData = $stmtCheckUser->fetch();

            if (!$userToUpdateData) { $response['message'] = 'User to update not found.'; break; }
            if ($userToUpdateData['company_id'] != $adminCompanyId) {
                $response['message'] = 'User does not belong to your company (or specified company for SA).'; break;
            }
            if ($userToUpdateData['role'] === 'super_admin' && $userRole !== 'super_admin') {
                 $response['message'] = 'Company admins cannot change the role of a super admin.'; break;
            }
            if ($userIdToUpdate == $userId && $newRole !== 'company_admin' && $userRole === 'company_admin') {
                 // Prevent company admin from demoting themselves if they are the only one, or if they are the owner.
                 // Check if current user is the company owner
                 $stmtOwnerCheck = $pdo->prepare("SELECT owner_user_id FROM companies WHERE id = :company_id");
                 $stmtOwnerCheck->execute(['company_id' => $adminCompanyId]);
                 $companyOwner = $stmtOwnerCheck->fetch();
                 if ($companyOwner && $companyOwner['owner_user_id'] == $userId) {
                     $response['message'] = 'Company owner cannot demote themselves from company admin.'; break;
                 }
                 // More complex logic: check if they are the *only* company_admin (not implemented here for brevity)
            }


            $stmt = $pdo->prepare("UPDATE users SET role = :new_role WHERE id = :user_id_to_update AND company_id = :company_id");
            $stmt->execute(['new_role' => $newRole, 'user_id_to_update' => $userIdToUpdate, 'company_id' => $adminCompanyId]);

            if ($stmt->rowCount() > 0) {
                $response = ['success' => true, 'message' => 'User role updated successfully.'];
            } else {
                $response['message'] = 'Failed to update user role or role was already set to this value.';
            }
        } catch (PDOException $e) {
            $response['message'] = 'Database error updating user role.';
            error_log("Update Company User Role DB Error: " . $e->getMessage());
        }
        break;

    case 'remove_user_from_company': // POST
        if ($userRole !== 'company_admin' && $userRole !== 'super_admin') {
            $response['message'] = 'Unauthorized.'; break;
        }
        $userIdToRemove = $_POST['user_id_to_remove'] ?? null;
        if (empty($userIdToRemove)) { $response['message'] = 'User ID to remove is required.'; break; }

        $adminCompanyId = ($userRole === 'super_admin') ? ($_POST['company_id_context'] ?? null) : $companyId;
         if (!$adminCompanyId && $userRole === 'super_admin') {
             $response['message'] = 'Super Admin: Company context ID is required to remove user.'; break;
        }
         if (!$adminCompanyId && $userRole !== 'super_admin') {
             $response['message'] = 'Company context not found for admin.'; break;
        }


        try {
            // Check if the user to remove is the company owner
            $stmtOwner = $pdo->prepare("SELECT owner_user_id FROM companies WHERE id = :company_id");
            $stmtOwner->execute(['company_id' => $adminCompanyId]);
            $companyData = $stmtOwner->fetch();

            if ($companyData && $companyData['owner_user_id'] == $userIdToRemove) {
                $response['message'] = 'Cannot remove the company owner. Transfer ownership first.'; break;
            }
            if ($userIdToRemove == $userId && $userRole === 'company_admin') { // company admin trying to remove self
                 $response['message'] = 'Company admins cannot remove themselves from the company.'; break;
            }


            // Set company_id to NULL and role to 'user' for the removed user.
            // Also, remove from all boards within that company.
            $pdo->beginTransaction();
            $stmtRemoveUser = $pdo->prepare("UPDATE users SET company_id = NULL, role = 'user' WHERE id = :user_id_to_remove AND company_id = :company_id");
            $stmtRemoveUser->execute(['user_id_to_remove' => $userIdToRemove, 'company_id' => $adminCompanyId]);

            if ($stmtRemoveUser->rowCount() > 0) {
                // Remove from all board_memberships within this company
                $stmtRemoveMemberships = $pdo->prepare("DELETE bm FROM board_memberships bm JOIN boards b ON bm.board_id = b.id WHERE bm.user_id = :user_id_to_remove AND b.company_id = :company_id");
                $stmtRemoveMemberships->execute(['user_id_to_remove' => $userIdToRemove, 'company_id' => $adminCompanyId]);
                
                $pdo->commit();
                $response = ['success' => true, 'message' => 'User removed from company and its boards successfully.'];
            } else {
                $pdo->rollBack();
                $response['message'] = 'Failed to remove user (not found in this company or already removed).';
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $response['message'] = 'Database error removing user from company.';
            error_log("Remove User From Company DB Error: " . $e->getMessage());
        }
        break;

    default:
        $response['message'] = "Action '{$action}' not recognized in company API.";
        break;
}

echo json_encode($response);
?>
```
This should ensure `php/company_api.php` is correctly set up. Now I will update `README.md`.

```
