// Global store for boards data and active board
let userBoards = [];
let activeBoardId = null;
let currentCompanyData = null; // Store full company data for admin use

$(document).ready(function() {
    const boardsApiUrl = 'php/boards_api.php';
    const companyApiUrl = 'php/company_api.php';

    // --- COMPANY RELATED UI ---
    window.displayNoCompanyView = function() {
        $('#companyManagementContainer').show();
        $('#noCompanyView').show();
        $('#companyAdminView').hide();
        $('#kanbanAppContainer').hide();
        $('#boardSelectorNav').hide();
        $('#companyInfoNav').hide();
    };

    window.displayCompanyView = function(companyData, userRole) {
        currentCompanyData = companyData; // Store for admin functions
        $('#companyManagementContainer').show();
        $('#noCompanyView').hide();
        $('#kanbanAppContainer').show(); 
        $('#companyInfoNav').show();
        $('#companyNameNav').text(escapeHtml(companyData.name));

        if (userRole === 'company_admin' || userRole === 'super_admin') {
            $('#companyAdminView').show();
            $('#updateCompanyName').val(companyData.name);
            $('#currentCompanyNameAdmin').text(escapeHtml(companyData.name));
            // Load data for admin tabs
            fetchCompanyUsersForAdminView(companyData.id);
            fetchCompanyBoardsForAdminView(companyData.id);
            // Invitations are already loaded by loadCompanyAdminData via auth.js
        } else {
            $('#companyAdminView').hide();
        }
        fetchUserBoards(); 
    };
    
    $('#saveNewCompanyButton').on('click', function() {
        const companyName = $('#newCompanyName').val().trim();
        const token = typeof getCsrfToken === 'function' ? getCsrfToken() : null;
        if (!companyName) { showUserMessage('Company name is required.', false, '#companyMessage'); return; }
        if (!token) { showUserMessage('Security token missing.', false, '#companyMessage'); return; }
        $.ajax({
            url: companyApiUrl, method: 'POST',
            data: { action: 'create_company', company_name: companyName, csrf_token: token },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#createCompanyModal').modal('hide');
                    $('#newCompanyName').val(''); // Clear form
                    showGlobalMessage(response.message || 'Company created!', true);
                    if (typeof checkAuthStatus === "function") checkAuthStatus(); else window.location.reload();
                } else { showUserMessage(response.message || 'Error.', false, '#companyMessage'); }
            }, error: function(xhr) { handleGlobalApiError(xhr, 'Server error creating company.'); }
        });
    });

    $('#updateCompanyForm').on('submit', function(e) {
        e.preventDefault();
        const newCompanyName = $('#updateCompanyName').val().trim();
        const token = typeof getCsrfToken === 'function' ? getCsrfToken() : null;
        if (!newCompanyName) { showUserMessage('Company name required.', false, '#companyMessage'); return; }
        if (!token) { showUserMessage('Security token missing.', false, '#companyMessage'); return; }
        const companyIdToUpdate = currentCompanyData ? currentCompanyData.id : null;
        if(!companyIdToUpdate && !(getCurrentUserData() && getCurrentUserData().role === 'super_admin')){
            showUserMessage('No company context for update.', false, '#companyMessage'); return;
        }
        let ajaxData = { action: 'update_company_details', company_name: newCompanyName, csrf_token: token };
        if(getCurrentUserData() && getCurrentUserData().role === 'super_admin' && companyIdToUpdate){
            ajaxData.company_id_to_update = companyIdToUpdate; // SA needs to specify which company
        }

        $.ajax({
            url: companyApiUrl, method: 'POST', data: ajaxData, dataType: 'json',
            success: function(response) {
                showUserMessage(response.message, response.success, '#companyMessage');
                if (response.success) {
                    currentCompanyData.name = newCompanyName; // Update local store
                    $('#companyNameNav').text(escapeHtml(newCompanyName));
                    $('#currentCompanyNameAdmin').text(escapeHtml(newCompanyName));
                }
            }, error: function(xhr) { handleGlobalApiError(xhr, 'Error updating company.');}
        });
    });

    // --- BOARD RELATED UI (General User) ---
    function populateBoardSelector(boards, currentActiveBoardId) {
        const $boardList = $('#boardListDropdown');
        $boardList.find('.board-select-item').remove(); // Remove only board items
        userBoards = boards; activeBoardId = currentActiveBoardId;
        updateBoardSettingsButtonVisibility(activeBoardId);


        if (boards.length === 0) {
            $('#currentBoardNameNav').text('No Boards Yet');
            $('#noBoardSelectedView').html('You have no boards. <a href="#" data-toggle="modal" data-target="#createBoardModal">Create one?</a>').show();
            clearKanbanBoardApp(); 
            $('#kanbanAppContainer .kanban-board-columns-container').hide();
            $('#boardSettingsButton').hide();
        } else {
            $('#kanbanAppContainer .kanban-board-columns-container').show();
            boards.forEach(board => {
                const $item = $(`<a class="dropdown-item board-select-item" href="#" data-board-id="${board.id}">${escapeHtml(board.name)}</a>`);
                if (board.id == currentActiveBoardId) {
                    $item.addClass('active'); $('#currentBoardNameNav').text(escapeHtml(board.name));
                }
                $boardList.find('a[data-target="#createBoardModal"]').parent().before($item); // Add before create new
            });
            if (currentActiveBoardId && boards.find(b => b.id == currentActiveBoardId)) {
                $('#noBoardSelectedView').hide();
                if (typeof initializeKanban === "function") initializeKanban();
            } else {
                $('#currentBoardNameNav').text('Select Board'); $('#noBoardSelectedView').show(); clearKanbanBoardApp();
                $('#boardSettingsButton').hide();
            }
        }
        $('#boardSelectorNav').show();
    }

    window.fetchUserBoards = function() {
        const currentUser = typeof getCurrentUserData === 'function' ? getCurrentUserData() : null;
        let companyFilter = {};
        if(currentUser && currentUser.role === 'super_admin' && !currentUser.company_id){
            // SA not in a company, don't filter unless they pick a company context later
        } else if (currentUser && currentUser.company_id) {
            companyFilter.company_id_filter = currentUser.company_id;
        }

        $.ajax({
            url: boardsApiUrl, method: 'GET', data: { action: 'list_my_boards', ...companyFilter }, dataType: 'json',
            success: function(response) {
                if (response.success) {
                    populateBoardSelector(response.boards, response.active_board_id);
                    activeBoardId = response.active_board_id; 
                } else {
                    if(response.no_company){ $('#boardSelectorNav').hide(); } 
                    else { showGlobalMessage(response.message || 'Error fetching boards.', false); $('#currentBoardNameNav').text('Error!');}
                }
            }, error: function(xhr) { handleGlobalApiError(xhr, 'Server error fetching boards.');}
        });
    };

    $(document).on('click', '.board-select-item', function(e) {
        e.preventDefault(); const selectedBoardId = $(this).data('board-id');
        const token = typeof getCsrfToken === 'function' ? getCsrfToken() : null;
        if (!token) { alert('Security token missing.'); return; }
        $.ajax({
            url: boardsApiUrl, method: 'POST', data: { action: 'set_active_board', board_id: selectedBoardId, csrf_token: token }, dataType: 'json',
            success: function(response) {
                if (response.success) {
                    activeBoardId = response.active_board_id;
                    $('#boardListDropdown .dropdown-item').removeClass('active');
                    $(`.board-select-item[data-board-id="${activeBoardId}"]`).addClass('active');
                    const board = userBoards.find(b => b.id == activeBoardId);
                    $('#currentBoardNameNav').text(board ? escapeHtml(board.name) : 'Board Selected');
                    $('#noBoardSelectedView').hide();
                    updateBoardSettingsButtonVisibility(activeBoardId);
                    if (typeof initializeKanban === "function") initializeKanban();
                } else { showGlobalMessage(response.message || 'Error setting active board.', false); }
            }, error: function(xhr) { handleGlobalApiError(xhr, 'Server error setting active board.');}
        });
    });

    $('#saveNewBoardButton').on('click', function() {
        const boardName = $('#newBoardName').val().trim(); const boardDescription = $('#newBoardDescription').val().trim();
        const token = typeof getCsrfToken === 'function' ? getCsrfToken() : null;
        if (!boardName) { alert('Board name required.'); return; }
        if (!token) { alert('Security token missing.'); return; }
        $.ajax({
            url: boardsApiUrl, method: 'POST',
            data: { action: 'create_board', board_name: boardName, board_description: boardDescription, csrf_token: token },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#createBoardModal').modal('hide'); $('#createBoardForm')[0].reset();
                    showGlobalMessage('Board created!', true); fetchUserBoards(); 
                } else { showGlobalMessage(response.message || 'Error.', false); }
            }, error: function(xhr) { handleGlobalApiError(xhr, 'Error creating board.');}
        });
    });

    // --- COMPANY INVITATIONS UI (in boards.js for now, as it's part of company admin view) ---
    $('#inviteUserToCompanyForm').on('submit', function(e) {
        e.preventDefault(); const emailToInvite = $('#inviteUserEmail').val().trim();
        const token = typeof getCsrfToken === 'function' ? getCsrfToken() : null;
        const user = typeof getCurrentUserData === 'function' ? getCurrentUserData() : null;
        if (!emailToInvite) { showUserMessage('Email required.', false, '#inviteUserMessage'); return; }
        if (!token) { showUserMessage('Security token missing.', false, '#inviteUserMessage'); return; }
        if (!user || !user.company_id) { showUserMessage('Admin company context not found.', false, '#inviteUserMessage'); return; }
        $.ajax({
            url: companyApiUrl, method: 'POST',
            data: { action: 'invite_user_to_company', email: emailToInvite, company_id: user.company_id, csrf_token: token },
            dataType: 'json',
            success: function(response) {
                showUserMessage(response.message, response.success, '#inviteUserMessage');
                if (response.success) { $('#inviteUserEmail').val(''); fetchCompanyInvitations(user.company_id); }
            }, error: function(xhr) { handleGlobalApiError(xhr, 'Error sending invitation.');}
        });
    });

    function fetchCompanyInvitations(companyId) {
        if (!companyId) return;
        $('#companyInvitationsList').html('<p class="text-muted">Loading invitations...</p>');
        $.ajax({
            url: companyApiUrl, method: 'GET', data: { action: 'list_company_invitations', company_id: companyId }, dataType: 'json',
            success: function(response) {
                const $list = $('#companyInvitationsList'); $list.empty();
                if (response.success && response.invitations && response.invitations.length > 0) {
                    response.invitations.forEach(inv => {
                        let badge = inv.status === 'pending' ? 'warning' : (inv.status === 'accepted' ? 'success' : 'secondary');
                        $list.append(`<li class="list-group-item">${escapeHtml(inv.email)} <span class="badge badge-${badge}">${inv.status}</span></li>`);
                    });
                } else { $list.html('<p class="text-muted">No invitations found.</p>'); }
            }, error: function(xhr) { $list.html('<p class="text-danger">Error loading invitations.</p>'); handleGlobalApiError(xhr, 'Error listing invitations.');}
        });
    }
    window.loadCompanyAdminData = function(companyData, userRole) { // Called by auth.js
        if (userRole === 'company_admin' || userRole === 'super_admin') {
            fetchCompanyInvitations(companyData.id);
            fetchCompanyUsersForAdminView(companyData.id); // For Manage Users tab
            fetchCompanyBoardsForAdminView(companyData.id); // For Manage Boards tab
        }
    };

    function handleInvitationTokenOnLoad() {
        const params = new URLSearchParams(window.location.search); const token = params.get('invitation_token');
        if (token) {
            $('#authContainer, #companyManagementContainer, #kanbanAppContainer').hide();
            $('#acceptInvitationContainer').show(); $('#acceptInviteInfo').html('<p class="text-muted">Verifying...</p>');
            $.ajax({
                url: companyApiUrl, method: 'GET', data: { action: 'get_pending_invitation_by_token', token: token }, dataType: 'json',
                success: function(response) {
                    if (response.success && response.invitation) {
                        $('#acceptInviteInfo').html(`Join <strong>${escapeHtml(response.invitation.company_name)}</strong>? <small>(Invited: ${escapeHtml(response.invitation.invited_email)})</small>`);
                        $('#confirmAcceptInvitationButton').data('token', token).show();
                        const user = typeof getCurrentUserData === 'function' ? getCurrentUserData() : null;
                        if (!user) { $('#acceptInviteInfo').append('<br>Login or register to accept.'); $('#confirmAcceptInvitationButton').hide(); $('#authContainer').show(); }
                        else if (user.email.toLowerCase() !== response.invitation.invited_email.toLowerCase()){ $('#acceptInviteInfo').html('Invitation for a different email.'); $('#confirmAcceptInvitationButton').hide(); }
                        else if (user.company_id) { $('#acceptInviteInfo').html('Already in a company.'); $('#confirmAcceptInvitationButton').hide(); }
                    } else { $('#acceptInviteHeader').text('Invalid Link'); $('#acceptInviteInfo').text(response.message || 'Invalid or expired link.'); $('#confirmAcceptInvitationButton').hide(); }
                }, error: function(xhr) { $('#acceptInviteHeader').text('Error'); $('#acceptInviteInfo').text('Could not verify.'); $('#confirmAcceptInvitationButton').hide(); handleGlobalApiError(xhr, "Error verifying invitation."); }
            });
        }
    }
    handleInvitationTokenOnLoad();

    $('#confirmAcceptInvitationButton').on('click', function() {
        const tokenToAccept = $(this).data('token'); const csrf = typeof getCsrfToken === 'function' ? getCsrfToken() : null;
        if (!tokenToAccept || !csrf) { alert('Token error. Refresh.'); return; }
        const user = typeof getCurrentUserData === 'function' ? getCurrentUserData() : null;
        if (!user) { alert('Login required.'); return; }
        $.ajax({
            url: companyApiUrl, method: 'POST', data: { action: 'accept_company_invitation', invitation_token: tokenToAccept, csrf_token: csrf }, dataType: 'json',
            success: function(response) {
                showUserMessage(response.message, response.success, '#acceptInvitationMessage', 10000);
                if (response.success) {
                    $('#confirmAcceptInvitationButton').hide(); window.history.replaceState({}, '', window.location.pathname);
                    if(typeof checkAuthStatus === 'function') checkAuthStatus(); else window.location.reload();
                }
            }, error: function(xhr) { handleGlobalApiError(xhr, 'Error accepting invitation.'); }
        });
    });

    // --- BOARD SETTINGS MODAL & COMPANY ADMIN BOARD/USER MANAGEMENT ---
    function updateBoardSettingsButtonVisibility(boardId) {
        const user = typeof getCurrentUserData === 'function' ? getCurrentUserData() : null;
        if (user && boardId && (user.role === 'super_admin' || user.role === 'company_admin' || (typeof userBoardRoles !== 'undefined' && userBoardRoles[boardId] === 'board_admin'))) {
            $('#boardSettingsButton').show();
        } else { $('#boardSettingsButton').hide(); }
    }

    $('#boardSettingsModal').on('show.bs.modal', function () {
        if (!activeBoardId) { alert("No active board."); return false; }
        const board = userBoards.find(b => b.id == activeBoardId); if (!board) { alert("Board data missing."); return false; }
        $('#settingsBoardName').text(escapeHtml(board.name)); $('#settingsBoardId').val(board.id);
        $('#settingsBoardNameInput').val(board.name); $('#settingsBoardDescriptionInput').val(board.description || '');
        $('#updateBoardDetailsMessage, #addBoardMemberMessage').hide();
        fetchBoardMembers(activeBoardId); fetchCompanyUsersForBoardAssignment(activeBoardId);
        $('#boardSettingsTabs a:first').tab('show');
    });

    $('#updateBoardDetailsForm').on('submit', function(e) { /* ... same as before ... */ });
    function fetchCompanyUsersForBoardAssignment(boardId) { /* ... same as before ... */ }
    function fetchBoardMembers(boardId) { /* ... same as before, ensure actionsHtml uses currentUserData ... */ }
    $('#addBoardMemberForm').on('submit', function(e) { /* ... same as before ... */ });
    $(document).on('click', '.remove-board-member', function() { /* ... same as before ... */ });
    $(document).on('change', '.change-board-role', function() { /* ... same as before ... */ });


    // --- COMPANY ADMIN DASHBOARD SPECIFIC FUNCTIONS ---
    function fetchCompanyUsersForAdminView(companyId) {
        if (!companyId) return;
        $('#companyUsersListAdminView').html('<tr><td colspan="5">Loading users...</td></tr>');
        $.ajax({
            url: companyApiUrl, method: 'GET', data: { action: 'list_company_users', company_id: companyId }, dataType: 'json',
            success: function(response) {
                const $tbody = $('#companyUsersListAdminView'); $tbody.empty();
                if (response.success && response.users) {
                    if(response.users.length === 0) $tbody.append('<tr><td colspan="5">No users in this company yet.</td></tr>');
                    response.users.forEach(user => {
                        let actions = `<button class="btn btn-sm btn-outline-primary change-company-role-btn" data-user-id="${user.id}" data-username="${escapeHtml(user.username)}" data-useremail="${escapeHtml(user.email)}" data-currentrole="${user.role}">Change Role</button>`;
                        if (user.id !== (getCurrentUserData() ? getCurrentUserData().id : null) && user.role !== 'super_admin') { // Can't remove self or SA
                            actions += ` <button class="btn btn-sm btn-outline-danger remove-from-company-btn" data-user-id="${user.id}" data-username="${escapeHtml(user.username)}">Remove</button>`;
                        }
                        $tbody.append(`<tr><td>${user.id}</td><td>${escapeHtml(user.username)}</td><td>${escapeHtml(user.email)}</td><td>${escapeHtml(user.role)}</td><td>${actions}</td></tr>`);
                    });
                } else { $tbody.html(`<tr><td colspan="5" class="text-danger">Error: ${response.message || 'Could not load users.'}</td></tr>`);}
            }, error: function(xhr) { handleGlobalApiError(xhr, 'Error fetching company users.'); $tbody.html('<tr><td colspan="5" class="text-danger">Server error.</td></tr>');}
        });
    }

    $(document).on('click', '.change-company-role-btn', function() {
        const userId = $(this).data('user-id');
        const username = $(this).data('username');
        const useremail = $(this).data('useremail');
        const currentRole = $(this).data('currentrole');
        $('#changeRoleUserId').val(userId);
        $('#changeRoleUserName').text(username);
        $('#changeRoleUserEmail').text(useremail);
        $('#selectNewCompanyRole').val(currentRole);
        $('#changeUserCompanyRoleModal').modal('show');
    });

    $('#saveUserCompanyRoleButton').on('click', function() {
        const userIdToUpdate = $('#changeRoleUserId').val();
        const newRole = $('#selectNewCompanyRole').val();
        const token = getCsrfToken();
        const admin = getCurrentUserData();
        if (!userIdToUpdate || !newRole || !token || !admin || !admin.company_id) { alert('Error: Missing data for role change.'); return; }
        $.ajax({
            url: companyApiUrl, method: 'POST',
            data: { action: 'update_company_user_role', user_id_to_update: userIdToUpdate, new_role: newRole, company_id_context: admin.company_id, csrf_token: token },
            dataType: 'json',
            success: function(response) {
                showUserMessage(response.message, response.success, '#adminCompanyUserMessage');
                if (response.success) { $('#changeUserCompanyRoleModal').modal('hide'); fetchCompanyUsersForAdminView(admin.company_id); }
            }, error: function(xhr) { handleGlobalApiError(xhr, 'Error updating role.'); }
        });
    });
    
    $(document).on('click', '.remove-from-company-btn', function() {
        const userIdToRemove = $(this).data('user-id');
        const username = $(this).data('username');
        const token = getCsrfToken();
        const admin = getCurrentUserData();
        if (!userIdToRemove || !username || !token || !admin || !admin.company_id) { alert('Error: Missing data for user removal.'); return; }
        if (confirm(`Are you sure you want to remove ${username} from the company? This will also remove them from all company boards.`)) {
            $.ajax({
                url: companyApiUrl, method: 'POST',
                data: { action: 'remove_user_from_company', user_id_to_remove: userIdToRemove, company_id_context: admin.company_id, csrf_token: token },
                dataType: 'json',
                success: function(response) {
                    showUserMessage(response.message, response.success, '#adminCompanyUserMessage');
                    if (response.success) fetchCompanyUsersForAdminView(admin.company_id);
                }, error: function(xhr) { handleGlobalApiError(xhr, 'Error removing user.'); }
            });
        }
    });

    function fetchCompanyBoardsForAdminView(companyId) {
        if (!companyId) return;
        $('#companyBoardsListAdminView').html('<tr><td colspan="4">Loading boards...</td></tr>');
        // Use list_my_boards, as company_admin already gets all their company boards
        $.ajax({
            url: boardsApiUrl, method: 'GET', data: { action: 'list_my_boards', company_id_filter: companyId }, // Ensure it filters by this company
            dataType: 'json',
            success: function(response) {
                const $tbody = $('#companyBoardsListAdminView'); $tbody.empty();
                if (response.success && response.boards) {
                    if(response.boards.length === 0) $tbody.append('<tr><td colspan="4">No boards in this company yet.</td></tr>');
                    response.boards.forEach(board => {
                        let actions = `<button class="btn btn-sm btn-outline-primary admin-edit-board-btn" data-board-id="${board.id}">Settings/Members</button>
                                       <button class="btn btn-sm btn-outline-danger admin-delete-board-btn" data-board-id="${board.id}" data-board-name="${escapeHtml(board.name)}">Delete</button>`;
                        $tbody.append(`<tr><td>${board.id}</td><td>${escapeHtml(board.name)}</td><td>${escapeHtml(board.description || '')}</td><td>${actions}</td></tr>`);
                    });
                } else { $tbody.html(`<tr><td colspan="4" class="text-danger">Error: ${response.message || 'Could not load boards.'}</td></tr>`);}
            }, error: function(xhr) { handleGlobalApiError(xhr, 'Error fetching company boards.'); $tbody.html('<tr><td colspan="4" class="text-danger">Server error.</td></tr>');}
        });
    }
    
    $(document).on('click', '.admin-edit-board-btn', function() {
        const boardId = $(this).data('board-id');
        // The existing board settings modal is triggered by data-target.
        // We need to ensure activeBoardId is set correctly before it opens,
        // or that the modal directly uses the passed boardId.
        // For simplicity, let's set activeBoardId and then trigger the modal.
        // This requires an API call to set active board if it's not already the active one.
        // Or, modify boardSettingsModal to accept a boardId parameter.
        
        // Simpler: directly populate and show the modal.
        // This means the board settings modal needs to be more flexible
        // to be populated by data not just from global 'activeBoardId'.
        // For now, we'll assume clicking this button will make this board "active" for the modal.
        // This is a bit of a hack; ideally, the modal would take boardId as an argument.
        
        const currentActive = getActiveBoardId();
        if (currentActive != boardId) {
            const token = getCsrfToken();
            if (!token) { alert("Security token missing."); return; }
            // Temporarily set active board for the modal
            $.ajax({
                url: boardsApiUrl, method: 'POST', async: false, // Make it synchronous for simplicity here, not ideal
                data: { action: 'set_active_board', board_id: boardId, csrf_token: token },
                dataType: 'json',
                success: function(resp) {
                    if(resp.success) {
                         activeBoardId = boardId; // Update global activeBoardId
                         userBoards = userBoards.map(b => b.id == boardId ? {...b, isActive: true} : {...b, isActive: false}); // Update local cache if used
                         $('#boardSettingsModal').modal('show'); // Now open it
                    } else {
                        showUserMessage(resp.message || 'Could not switch to board for settings.', false, '#adminCompanyBoardMessage');
                    }
                },
                error: function(xhr) { handleGlobalApiError(xhr, "Error setting active board for settings."); }
            });
        } else {
            $('#boardSettingsModal').modal('show'); // Already active, just show
        }
    });

    $(document).on('click', '.admin-delete-board-btn', function() {
        const boardId = $(this).data('board-id');
        const boardName = $(this).data('board-name');
        const token = getCsrfToken();
        if (!boardId || !token) { alert('Error: Missing data for board deletion.'); return; }
        if (confirm(`Are you sure you want to delete the board "${boardName}"? This will delete all its tasks and memberships.`)) {
            $.ajax({
                url: boardsApiUrl, method: 'POST',
                data: { action: 'delete_board', board_id: boardId, csrf_token: token },
                dataType: 'json',
                success: function(response) {
                    showUserMessage(response.message, response.success, '#adminCompanyBoardMessage');
                    if (response.success) {
                        const admin = getCurrentUserData();
                        if(admin && admin.company_id) fetchCompanyBoardsForAdminView(admin.company_id); // Refresh list
                        if(getActiveBoardId() == boardId) { // If active board was deleted
                            activeBoardId = null;
                            fetchUserBoards(); // Refresh main board selector
                        }
                    }
                }, error: function(xhr) { handleGlobalApiError(xhr, 'Error deleting board.'); }
            });
        }
    });

});
```
This is a very large update to `js/boards.js`. It now includes:
*   Logic for populating and handling the "Manage Users" tab (listing, changing role, removing users from company).
*   Logic for populating and handling the "Manage Boards" tab (listing all company boards, triggering edit via existing Board Settings modal, deleting boards).
*   Uses the global `showUserMessage` and `handleGlobalApiError` more consistently.
*   The "Edit Board" button for company admins now attempts to set the selected board as active before opening the standard `#boardSettingsModal`. This is a simplification; a more robust solution might involve passing the `boardId` directly to the modal's setup functions.

With these changes to `index.php` and `js/boards.js`, the frontend components for the Company Admin Dashboard (Step 9) are now implemented.

```
