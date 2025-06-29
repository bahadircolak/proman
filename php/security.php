<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

/**
 * Generates a CSRF token and stores it in the session.
 * If a token already exists in the session, it returns the existing one.
 *
 * @return string The CSRF token.
 */
function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validates a given CSRF token against the one stored in the session.
 * Uses hash_equals for timing attack safe string comparison.
 *
 * @param string $tokenFromRequest The CSRF token received from the request.
 * @return bool True if the token is valid, false otherwise.
 */
function validateCsrfToken($tokenFromRequest) {
    if (empty($tokenFromRequest)) {
        return false;
    }
    if (isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $tokenFromRequest)) {
        // Optional: For one-time use tokens, unset it here.
        // unset($_SESSION['csrf_token']);
        return true;
    }
    return false;
}

/**
 * To be called at the beginning of scripts handling POST/state-changing requests.
 * Dies if CSRF token is invalid.
 */
function verifyCsrfTokenProtection() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { // Or any other methods that change state
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (!validateCsrfToken($token)) {
            header('Content-Type: application/json'); // Ensure JSON response for API calls
            http_response_code(403); // Forbidden
            echo json_encode(['success' => false, 'message' => 'CSRF token validation failed. Request rejected.']);
            error_log('CSRF token validation failed.');
            exit;
        }
    }
}


/**
 * Gets the role of a user on a specific board.
 * Checks board_memberships, then company_admin status, then super_admin.
 *
 * @param PDO $pdo PDO database connection.
 * @param int $userId The ID of the user.
 * @param int $boardId The ID of the board.
 * @param string $userSystemRole The system-level role of the user (from users.role).
 * @param int|null $userCompanyId The company_id of the user (from users.company_id).
 * @return string|null The user's effective role on the board or null if no access.
 *                     Possible return values: 'super_admin_override', 'company_admin_as_board_admin', 
 *                                           'board_admin', 'board_editor', 'board_viewer', or null.
 */
function getUserBoardRole(PDO $pdo, int $userId, int $boardId, string $userSystemRole, $userCompanyId) {
    if ($userSystemRole === 'super_admin') {
        return 'super_admin_override'; 
    }

    $stmtBoardCompany = $pdo->prepare("SELECT company_id FROM boards WHERE id = :board_id");
    $stmtBoardCompany->execute(['board_id' => $boardId]);
    $boardCompany = $stmtBoardCompany->fetch();

    if (!$boardCompany) {
        return null; // Board doesn't exist
    }

    if ($userSystemRole === 'company_admin' && $userCompanyId !== null && $userCompanyId == $boardCompany['company_id']) {
        return 'company_admin_as_board_admin'; 
    }
    
    $stmt = $pdo->prepare("SELECT role FROM board_memberships WHERE user_id = :user_id AND board_id = :board_id");
    $stmt->execute(['user_id' => $userId, 'board_id' => $boardId]);
    $membership = $stmt->fetch();

    if ($membership) {
        return $membership['role']; 
    }
    
    // If user is part of the company that owns the board, but no explicit board role,
    // they might get a default 'viewer' or no access depending on policy.
    // For now, explicit membership or company_admin is required.
    // Exception: if a board has a "public" or "company_wide_access" setting (not implemented yet).
    return null; 
}

/**
 * Checks if a user has a minimum required permission level on a board.
 *
 * @param PDO $pdo
 * @param int $userId
 * @param int $boardId
 * @param string $userSystemRole From $_SESSION['user_role']
 * @param int|null $userCompanyId From $_SESSION['company_id']
 * @param array $minimumRequiredBoardRoles An array of board roles that grant permission (e.g., ['board_admin', 'board_editor']).
 *                                         Order can matter if treated hierarchically, or just check for presence.
 *                                         Example: To edit, pass ['board_editor', 'board_admin']. To view: ['board_viewer', 'board_editor', 'board_admin']
 * @return bool True if user has permission, false otherwise.
 */
function hasBoardPermission(PDO $pdo, int $userId, int $boardId, string $userSystemRole, $userCompanyId, array $minimumRequiredBoardRoles) {
    $effectiveRole = getUserBoardRole($pdo, $userId, $boardId, $userSystemRole, $userCompanyId);

    if ($effectiveRole === 'super_admin_override') return true;
    if ($effectiveRole === 'company_admin_as_board_admin') {
        // Company admins are effectively board_admins for all their boards.
        // So, if 'board_admin' is in $minimumRequiredBoardRoles, or any role typically under board_admin, this should pass.
        // For simplicity, let's say company_admin can do anything a board_admin can.
        // If 'board_admin' is an allowed role, or if the list implies hierarchy where board_admin is top.
        // A common pattern: if 'board_admin' is allowed, they can do it. If only 'board_editor' is allowed, company_admin can also do it.
        return true; // Simplification: company_admin has full rights on their company's boards.
    }

    if ($effectiveRole && in_array($effectiveRole, $minimumRequiredBoardRoles)) {
        return true;
    }
    
    return false;
}
?>
