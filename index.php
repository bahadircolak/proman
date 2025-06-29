<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kanban Pro</title>
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
    <link rel="stylesheet" href="css/style.css">
    <script>
        <?php
            if (file_exists(__DIR__ . '/php/config.php')) {
                ob_start(); require_once __DIR__ . '/php/config.php'; ob_end_clean();
                echo "const APP_BASE_URL = \"" . rtrim(BASE_URL, '/') . "\";\n";
            } else {
                echo "const APP_BASE_URL = '';\n";
                error_log("Warning: php/config.php not found for APP_BASE_URL.");
            }
        ?>
    </script>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-2">
        <a class="navbar-brand" href="#">Kanban Pro</a>
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation"><span class="navbar-toggler-icon"></span></button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav mr-auto">
                <li class="nav-item" id="companyInfoNav" style="display: none;"><span class="navbar-text mr-3">Company: <strong id="companyNameNav"></strong></span></li>
                <li class="nav-item dropdown" id="boardSelectorNav" style="display: none;">
                    <a class="nav-link dropdown-toggle" href="#" id="boardDropdownMenuLink" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        Board: <strong id="currentBoardNameNav">No Board Selected</strong> 
                        <button id="boardSettingsButton" class="btn btn-sm btn-outline-secondary ml-2 py-0" style="display:none;" data-toggle="modal" data-target="#boardSettingsModal">Settings</button>
                    </a>
                    <div class="dropdown-menu" id="boardListDropdown" aria-labelledby="boardDropdownMenuLink">
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="#" data-toggle="modal" data-target="#createBoardModal">Create New Board...</a>
                    </div>
                </li>
                 <li class="nav-item" id="superAdminNavButtonContainer" style="display:none;">
                    <button class="btn btn-sm btn-warning" id="superAdminDashboardBtn">Super Admin</button>
                </li>
            </ul>
            <ul class="navbar-nav ml-auto">
                <li class="nav-item" id="userInfo" style="display: none;"><span class="navbar-text mr-3">User: <strong id="usernameDisplay"></strong> (<em id="userRoleDisplay"></em>)</span><button class="btn btn-outline-light btn-sm py-0" id="logoutButton">Logout</button></li>
                <li class="nav-item" id="loginNav" style="display: none;"><a class="nav-link" href="#" id="showLogin">Login</a></li>
                <li class="nav-item" id="registerNav" style="display: none;"><a class="nav-link" href="#" id="showRegister">Register</a></li>
            </ul>
        </div>
    </nav>

    <div class="container-fluid mt-3">
        <div id="globalMessages" class="mb-3"></div>
        <div id="authContainer" class="row justify-content-center" style="display: none;">
            <div class="col-md-6 col-lg-4">
                <div id="authMessage" class="mb-3"></div>
                <div id="loginFormContainer" style="display: none;"><h3 class="text-center mb-3">Login</h3><form id="loginForm"><div class="form-group"><label for="loginUsername">Username or Email</label><input type="text" class="form-control" id="loginUsername" required></div><div class="form-group"><label for="loginPassword">Password</label><input type="password" class="form-control" id="loginPassword" required></div><button type="submit" class="btn btn-primary btn-block">Login</button><p class="text-center mt-3">Don't have an account? <a href="#" id="switchToRegister">Register here</a></p></form></div>
                <div id="registerFormContainer" style="display: none;"><h3 class="text-center mb-3">Register</h3><form id="registerForm"><div class="form-group"><label for="registerUsername">Username</label><input type="text" class="form-control" id="registerUsername" required></div><div class="form-group"><label for="registerEmail">Email</label><input type="email" class="form-control" id="registerEmail" required></div><div class="form-group"><label for="registerPassword">Password</label><input type="password" class="form-control" id="registerPassword" required></div><div class="form-group"><label for="registerConfirmPassword">Confirm Password</label><input type="password" class="form-control" id="registerConfirmPassword" required></div><button type="submit" class="btn btn-success btn-block">Register</button><p class="text-center mt-3">Already have an account? <a href="#" id="switchToLogin">Login here</a></p></form></div>
            </div>
        </div>

        <div id="companyManagementContainer" class="row justify-content-center mt-4" style="display: none;">
            <div class="col-md-10 col-lg-8">
                <div id="companyMessage" class="mb-3"></div>
                <div id="noCompanyView" style="display:none;" class="text-center card p-4"><h3 class="mb-3">Welcome!</h3><p>You are not yet part of a company. Create one to start using Kanban Pro.</p><button class="btn btn-lg btn-success" data-toggle="modal" data-target="#createCompanyModal">Create Your Company</button></div>
                <div id="companyAdminView" style="display:none;" class="card p-4">
                    <h4>Company Administration: <strong id="currentCompanyNameAdmin"></strong></h4>
                    <form id="updateCompanyForm" class="mt-3 mb-3 border-bottom pb-3"><h5>Company Settings</h5><div class="form-group"><label for="updateCompanyName">Company Name</label><input type="text" class="form-control" id="updateCompanyName" required></div><button type="submit" class="btn btn-primary btn-sm">Save Company Name</button></form>
                    <ul class="nav nav-tabs mt-3" id="companyAdminTabs" role="tablist">
                        <li class="nav-item" role="presentation"><a class="nav-link active" id="adminManageUsers-tab" data-toggle="tab" href="#adminManageUsers" role="tab" aria-controls="adminManageUsers" aria-selected="true">Manage Users</a></li>
                        <li class="nav-item" role="presentation"><a class="nav-link" id="adminManageBoards-tab" data-toggle="tab" href="#adminManageBoards" role="tab" aria-controls="adminManageBoards" aria-selected="false">Manage Boards</a></li>
                        <li class="nav-item" role="presentation"><a class="nav-link" id="adminManageInvites-tab" data-toggle="tab" href="#adminManageInvites" role="tab" aria-controls="adminManageInvites" aria-selected="false">Invitations</a></li>
                    </ul>
                    <div class="tab-content mt-3" id="companyAdminTabContent">
                        <div class="tab-pane fade show active" id="adminManageUsers" role="tabpanel" aria-labelledby="adminManageUsers-tab"><h5>Users in Your Company</h5><div id="adminCompanyUserMessage" class="mb-2"></div><div class="table-responsive"><table class="table table-striped table-sm"><thead><tr><th>ID</th><th>Username</th><th>Email</th><th>Role</th><th>Actions</th></tr></thead><tbody id="companyUsersListAdminView"></tbody></table></div></div>
                        <div class="tab-pane fade" id="adminManageBoards" role="tabpanel" aria-labelledby="adminManageBoards-tab"><h5>Boards in Your Company</h5><div id="adminCompanyBoardMessage" class="mb-2"></div><div class="table-responsive"><table class="table table-striped table-sm"><thead><tr><th>ID</th><th>Name</th><th>Description</th><th>Actions</th></tr></thead><tbody id="companyBoardsListAdminView"></tbody></table></div></div>
                        <div class="tab-pane fade" id="adminManageInvites" role="tabpanel" aria-labelledby="adminManageInvites-tab"><h5>Invite User to Company</h5><form id="inviteUserToCompanyForm" class="form-inline mt-2 mb-3"><div class="form-group mr-2 flex-grow-1"><label for="inviteUserEmail" class="sr-only">Email address</label><input type="email" class="form-control w-100" id="inviteUserEmail" placeholder="Enter email to invite" required></div><button type="submit" class="btn btn-info btn-sm">Send Invitation</button></form><div id="inviteUserMessage" class="mb-3"></div><h5>Company Invitations</h5><div id="companyInvitationsList" class="list-group"><p class="text-muted">Loading invitations...</p></div></div>
                    </div>
                </div>
            </div>
        </div>

        <div id="acceptInvitationContainer" class="row justify-content-center mt-4" style="display: none;"><div class="col-md-6 col-lg-4 text-center card p-4"><h3 id="acceptInviteHeader">Accept Invitation</h3><p id="acceptInviteInfo"></p><button class="btn btn-success" id="confirmAcceptInvitationButton">Join Company</button><div id="acceptInvitationMessage" class="mt-3"></div></div></div>
        <div id="kanbanAppContainer" style="display: none;" class="mt-3"><div id="noBoardSelectedView" class="text-center mt-5 p-4 card bg-light" style="display: none;"><h3>No board selected.</h3><p>Please select a board from the dropdown in the navigation bar, or <a href="#" data-toggle="modal" data-target="#createBoardModal">create a new board</a>.</p></div><div class="row kanban-board-columns-container"></div></div>
    
        <!-- Super Admin Dashboard Container (hidden by default) -->
        <div id="superAdminDashboardContainer" class="container-fluid mt-4" style="display: none;">
            <h2 class="text-center mb-4">Super Admin Dashboard</h2>
            <div id="superAdminMessages" class="mb-3"></div>
            <ul class="nav nav-tabs" id="superAdminTabs" role="tablist">
                <li class="nav-item" role="presentation"><a class="nav-link active" id="saManageCompanies-tab" data-toggle="tab" href="#saManageCompanies" role="tab" aria-controls="saManageCompanies" aria-selected="true">Manage Companies</a></li>
                <li class="nav-item" role="presentation"><a class="nav-link" id="saManageUsers-tab" data-toggle="tab" href="#saManageUsers" role="tab" aria-controls="saManageUsers" aria-selected="false">Manage Users</a></li>
            </ul>
            <div class="tab-content mt-3" id="superAdminTabContent">
                <div class="tab-pane fade show active" id="saManageCompanies" role="tabpanel" aria-labelledby="saManageCompanies-tab">
                    <h5>All Companies</h5><div class="table-responsive"><table class="table table-striped table-sm"><thead><tr><th>ID</th><th>Name</th><th>Owner</th><th>User #</th><th>Board #</th><th>Created</th><th>Actions</th></tr></thead><tbody id="saCompanyListTableBody"></tbody></table></div>
                </div>
                <div class="tab-pane fade" id="saManageUsers" role="tabpanel" aria-labelledby="saManageUsers-tab">
                    <h5>All Users</h5><div class="table-responsive"><table class="table table-striped table-sm"><thead><tr><th>ID</th><th>Username</th><th>Email</th><th>Role</th><th>Company</th><th>Created</th><th>Actions</th></tr></thead><tbody id="saUserListTableBody"></tbody></table></div>
                </div>
            </div>
        </div>
    </div> <!-- End of main container-fluid -->

    <!-- Modals -->
    <div class="modal fade" id="createCompanyModal" tabindex="-1" aria-labelledby="createCompanyModalLabel" aria-hidden="true"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="createCompanyModalLabel">Create New Company</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div><div class="modal-body"><form id="createCompanyForm"><div class="form-group"><label for="newCompanyName">Company Name</label><input type="text" class="form-control" id="newCompanyName" required></div></form></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="saveNewCompanyButton">Create</button></div></div></div></div>
    <div class="modal fade" id="changeUserCompanyRoleModal" tabindex="-1" aria-labelledby="changeUserCompanyRoleModalLabel" aria-hidden="true"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="changeUserCompanyRoleModalLabel">Change User Role</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div><div class="modal-body"><p>User: <strong id="changeRoleUserName"></strong> (<span id="changeRoleUserEmail"></span>)</p><input type="hidden" id="changeRoleUserId"><div class="form-group"><label for="selectNewCompanyRole">New Company Role</label><select id="selectNewCompanyRole" class="form-control"><option value="user">User</option><option value="company_admin">Company Admin</option></select></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="saveUserCompanyRoleButton">Save Role</button></div></div></div></div>
    <div class="modal fade" id="createBoardModal" tabindex="-1" aria-labelledby="createBoardModalLabel" aria-hidden="true"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="createBoardModalLabel">Create New Board</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div><div class="modal-body"><form id="createBoardForm"><div class="form-group"><label for="newBoardName">Board Name</label><input type="text" class="form-control" id="newBoardName" required></div><div class="form-group"><label for="newBoardDescription">Description</label><textarea class="form-control" id="newBoardDescription" rows="3"></textarea></div></form></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="saveNewBoardButton">Create</button></div></div></div></div>
    <div class="modal fade" id="boardSettingsModal" tabindex="-1" aria-labelledby="boardSettingsModalLabel" aria-hidden="true"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="boardSettingsModalLabel">Board Settings: <span id="settingsBoardName"></span></h5><button type="button" class="close" data-dismiss="modal">&times;</button></div><div class="modal-body"><ul class="nav nav-tabs" id="boardSettingsTabs" role="tablist"><li class="nav-item"><a class="nav-link active" data-toggle="tab" href="#generalBoardSettings">General</a></li><li class="nav-item"><a class="nav-link" data-toggle="tab" href="#manageBoardMembers">Members</a></li></ul><div class="tab-content mt-3"><div class="tab-pane fade show active" id="generalBoardSettings"><form id="updateBoardDetailsForm"><input type="hidden" id="settingsBoardId"><div class="form-group"><label for="settingsBoardNameInput">Board Name</label><input type="text" class="form-control" id="settingsBoardNameInput" required></div><div class="form-group"><label for="settingsBoardDescriptionInput">Description</label><textarea class="form-control" id="settingsBoardDescriptionInput" rows="3"></textarea></div><button type="submit" class="btn btn-primary btn-sm">Save Details</button><div id="updateBoardDetailsMessage" class="mt-2"></div></form></div><div class="tab-pane fade" id="manageBoardMembers"><h5>Add Member</h5><form id="addBoardMemberForm" class="form-row align-items-end mb-3"><div class="form-group col-md-5"><label for="selectCompanyUserForBoard">User</label><select id="selectCompanyUserForBoard" class="form-control"></select></div><div class="form-group col-md-4"><label for="selectBoardRoleForUser">Role</label><select id="selectBoardRoleForUser" class="form-control"><option value="board_viewer">Viewer</option><option value="board_editor">Editor</option><option value="board_admin">Admin</option></select></div><div class="form-group col-md-3"><button type="submit" class="btn btn-success btn-block btn-sm">Add</button></div></form><div id="addBoardMemberMessage" class="mb-3"></div><h5>Current Members</h5><ul id="currentBoardMembersList" class="list-group"></ul></div></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button></div></div></div></div>
    <div class="modal fade" id="taskModal" tabindex="-1" aria-labelledby="taskModalLabel" aria-hidden="true"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="taskModalLabel">Add/Edit Task</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div><div class="modal-body"><form id="taskForm"><input type="hidden" id="taskId"><div class="form-group"><label for="taskTitle">Title</label><input type="text" class="form-control" id="taskTitle" required></div><div class="form-group"><label for="taskDescription">Description</label><textarea class="form-control" id="taskDescription" rows="3"></textarea></div><div class="form-group"><label for="taskStatus">Status</label><select class="form-control" id="taskStatus"></select></div><div class="form-group" id="assignUserGroup" style="display:none;"><label for="taskAssignee">Assign to</label><select class="form-control" id="taskAssignee"><option value="">-- Unassigned --</option></select></div><div class="form-group" id="imageUploadGroup" style="display:none;"><label for="taskImage">Image</label><input type="file" class="form-control-file" id="taskImage" accept="image/jpeg,image/png,image/gif"><img id="taskImagePreview" src="#" alt="Preview" class="mt-2" style="max-width:100px;max-height:100px;display:none;"/><button type="button" class="btn btn-sm btn-warning mt-1" id="removeTaskImage" style="display:none;">Remove</button></div></form></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button><button type="button" class="btn btn-primary" id="saveTask">Save</button></div></div></div></div>
    <div class="modal fade" id="saEditUserModal" tabindex="-1" aria-labelledby="saEditUserModalLabel" aria-hidden="true"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="saEditUserModalLabel">Edit User: <span id="saEditUserName"></span></h5><button type="button" class="close" data-dismiss="modal">&times;</button></div><div class="modal-body"><form id="saEditUserForm"><input type="hidden" id="saEditUserId"><div class="form-group"><label for="saEditUserRole">Role</label><select id="saEditUserRole" class="form-control"><option value="user">User</option><option value="company_admin">Company Admin</option><option value="super_admin">Super Admin</option></select></div><div class="form-group"><label for="saEditUserCompany">Company ID (empty to remove)</label><input type="number" id="saEditUserCompany" class="form-control" placeholder="Company ID"><small>Current: <span id="saEditUserCurrentCompany"></span></small></div></form><div id="saEditUserMessage" class="mt-2"></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="saSaveChangesToUserButton">Save Changes</button></div></div></div></div>
    
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script src="js/auth.js"></script>
    <script src="js/boards.js"></script>
    <script src="js/script.js"></script> 
    <script src="js/superadmin.js"></script>
</body>
</html>

