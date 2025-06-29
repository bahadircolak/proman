$(document).ready(function() {
    const superAdminApiUrl = 'php/superadmin_api.php';
    const $superAdminDashboardContainer = $('#superAdminDashboardContainer');
    const $saCompanyListTableBody = $('#saCompanyListTableBody');
    const $saUserListTableBody = $('#saUserListTableBody');
    const $superAdminMessages = $('#superAdminMessages');

    // Function to display messages specifically for super admin dashboard
    function showSAMessage(message, isSuccess) {
        $superAdminMessages.text(message)
            .removeClass('alert-success alert-danger')
            .addClass(isSuccess ? 'alert-success' : 'alert alert-danger')
            .show().delay(5000).fadeOut();
    }

    // Check if current user is super_admin and show dashboard
    // This is also handled by auth.js, but this script should only run its logic if SA.
    window.initializeSuperAdminDashboard = function() {
        const currentUser = typeof getCurrentUserData === 'function' ? getCurrentUserData() : null;
        if (currentUser && currentUser.role === 'super_admin') {
            console.log("Initializing Super Admin Dashboard...");
            $superAdminDashboardContainer.show();
            // Hide other main containers if not already hidden by auth.js
            $('#kanbanAppContainer').hide();
            $('#companyManagementContainer').hide();
            $('#acceptInvitationContainer').hide();
            
            loadAllCompaniesSA();
            loadAllUsersSA();
            // Ensure first tab is active
            $('#superAdminTabs a:first').tab('show');
        } else {
            $superAdminDashboardContainer.hide();
        }
    };
    
    // Event listener for the Super Admin Dashboard button in navbar
    // This button is only visible to super_admins (controlled by auth.js)
    $('#superAdminDashboardBtn').on('click', function(e) {
        e.preventDefault();
        initializeSuperAdminDashboard(); // This will show the SA dash and hide others
    });


    function loadAllCompaniesSA() {
        $saCompanyListTableBody.html('<tr><td colspan="7">Loading companies...</td></tr>');
        $.ajax({
            url: superAdminApiUrl,
            method: 'GET',
            data: { action: 'sa_list_all_companies' }, // No CSRF for GET
            dataType: 'json',
            success: function(response) {
                $saCompanyListTableBody.empty();
                if (response.success && response.companies) {
                    if (response.companies.length === 0) {
                        $saCompanyListTableBody.append('<tr><td colspan="7">No companies found.</td></tr>');
                    } else {
                        response.companies.forEach(company => {
                            $saCompanyListTableBody.append(`
                                <tr>
                                    <td>${company.id}</td>
                                    <td>${escapeHtml(company.name)}</td>
                                    <td>${escapeHtml(company.owner_username || 'N/A')} (ID: ${company.owner_user_id || 'N/A'})</td>
                                    <td>${company.user_count}</td>
                                    <td>${company.board_count}</td>
                                    <td>${new Date(company.created_at).toLocaleDateString()}</td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary sa-edit-company-btn" data-company-id="${company.id}" data-company-name="${escapeHtml(company.name)}">Edit</button>
                                        <!-- <button class="btn btn-sm btn-outline-danger sa-delete-company-btn" data-company-id="${company.id}">Delete</button> -->
                                    </td>
                                </tr>
                            `);
                        });
                    }
                } else {
                    $saCompanyListTableBody.append(`<tr><td colspan="7" class="text-danger">Error: ${response.message || 'Could not load companies.'}</td></tr>`);
                }
            },
            error: function(xhr) {
                handleGlobalApiError(xhr, "Error fetching all companies.");
                $saCompanyListTableBody.html('<tr><td colspan="7" class="text-danger">Server error loading companies.</td></tr>');
            }
        });
    }

    function loadAllUsersSA() {
        $saUserListTableBody.html('<tr><td colspan="7">Loading users...</td></tr>');
        $.ajax({
            url: superAdminApiUrl,
            method: 'GET',
            data: { action: 'sa_list_all_users' }, // No CSRF for GET
            dataType: 'json',
            success: function(response) {
                $saUserListTableBody.empty();
                if (response.success && response.users) {
                     if (response.users.length === 0) {
                        $saUserListTableBody.append('<tr><td colspan="7">No users found.</td></tr>');
                    } else {
                        response.users.forEach(user => {
                            $saUserListTableBody.append(`
                                <tr>
                                    <td>${user.id}</td>
                                    <td>${escapeHtml(user.username)}</td>
                                    <td>${escapeHtml(user.email)}</td>
                                    <td>${escapeHtml(user.role)}</td>
                                    <td>${escapeHtml(user.company_name || 'N/A')} (ID: ${user.company_id || 'N/A'})</td>
                                    <td>${new Date(user.created_at).toLocaleDateString()}</td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary sa-edit-user-btn" 
                                                data-user-id="${user.id}" 
                                                data-username="${escapeHtml(user.username)}"
                                                data-role="${escapeHtml(user.role)}"
                                                data-company-id="${user.company_id || ''}"
                                                data-company-name="${escapeHtml(user.company_name || 'N/A')}">Edit</button>
                                        <!-- <button class="btn btn-sm btn-outline-danger sa-delete-user-btn" data-user-id="${user.id}">Delete</button> -->
                                    </td>
                                </tr>
                            `);
                        });
                    }
                } else {
                    $saUserListTableBody.append(`<tr><td colspan="7" class="text-danger">Error: ${response.message || 'Could not load users.'}</td></tr>`);
                }
            },
            error: function(xhr) {
                handleGlobalApiError(xhr, "Error fetching all users.");
                $saUserListTableBody.html('<tr><td colspan="7" class="text-danger">Server error loading users.</td></tr>');
            }
        });
    }

    // Handle SA Edit User button click
    $(document).on('click', '.sa-edit-user-btn', function() {
        const userId = $(this).data('user-id');
        const username = $(this).data('username');
        const role = $(this).data('role');
        const companyId = $(this).data('company-id');
        const companyName = $(this).data('company-name');

        $('#saEditUserId').val(userId);
        $('#saEditUserName').text(username);
        $('#saEditUserRole').val(role);
        $('#saEditUserCompany').val(companyId);
        $('#saEditUserCurrentCompany').text(`${companyName} (ID: ${companyId || 'N/A'})`);
        $('#saEditUserMessage').hide();
        $('#saEditUserModal').modal('show');
    });

    // Handle SA Save Changes to User button
    $('#saSaveChangesToUserButton').on('click', function() {
        const userIdToUpdate = $('#saEditUserId').val();
        const newRole = $('#saEditUserRole').val();
        const newCompanyId = $('#saEditUserCompany').val().trim() === '' ? null : parseInt($('#saEditUserCompany').val().trim(), 10);
        const token = typeof getCsrfToken === 'function' ? getCsrfToken() : null;

        if (!userIdToUpdate || !newRole) { alert("User ID and Role are required."); return; }
        if (!token) { alert("CSRF token missing."); return; }

        // Call role update first, then company update if needed, or a combined endpoint
        // For simplicity, let's assume two separate calls if both changed.
        // Or better, one API endpoint that handles both.
        // php/superadmin_api.php has sa_update_user_role and sa_update_user_company

        // Update Role
        $.ajax({
            url: superAdminApiUrl, method: 'POST',
            data: { action: 'sa_update_user_role', user_id_to_update: userIdToUpdate, new_role: newRole, csrf_token: token },
            dataType: 'json',
            success: function(roleResponse) {
                if (roleResponse.success) {
                    // Now update company if it's different or being set/unset
                    const originalCompanyId = $('#saEditUserCompany').data('original-company-id'); // Need to store this when modal opens
                     // The above is a bit tricky. Let's get current company from the button that opened modal
                    const btnData = $(`.sa-edit-user-btn[data-user-id='${userIdToUpdate}']`);
                    const currentDbCompanyId = btnData.data('company-id') || null;


                    if (newCompanyId !== currentDbCompanyId) { // Only call if company actually changed
                        $.ajax({
                            url: superAdminApiUrl, method: 'POST',
                            data: { action: 'sa_update_user_company', user_id_to_update: userIdToUpdate, company_id: newCompanyId, csrf_token: token },
                            dataType: 'json',
                            success: function(companyResponse) {
                                showUserMessage(roleResponse.message + " " + companyResponse.message, companyResponse.success, '#saEditUserMessage');
                                if (companyResponse.success) {
                                    $('#saEditUserModal').modal('hide');
                                    loadAllUsersSA(); // Refresh user list
                                }
                            },
                            error: function(xhr) { handleGlobalApiError(xhr, "Error updating user company."); }
                        });
                    } else { // Role updated, company not changed
                        showUserMessage(roleResponse.message, roleResponse.success, '#saEditUserMessage');
                        if (roleResponse.success) {
                            $('#saEditUserModal').modal('hide');
                            loadAllUsersSA();
                        }
                    }
                } else {
                    showUserMessage(roleResponse.message || "Failed to update role.", false, '#saEditUserMessage');
                }
            },
            error: function(xhr) { handleGlobalApiError(xhr, "Error updating user role."); }
        });
    });
    
    // TODO: SA Edit Company functionality (modal, form submission to company_api or superadmin_api)
    // $(document).on('click', '.sa-edit-company-btn', function() { ... });


    // Initial load if SA dashboard is visible (e.g. direct navigation or after login as SA)
    // This is now primarily triggered by auth.js calling initializeSuperAdminDashboard
});

// Make sure escapeHtml is available if not already global
if (typeof escapeHtml !== 'function') {
    function escapeHtml(unsafe) {
        if (unsafe === null || typeof unsafe === 'undefined') return '';
        return unsafe.toString().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }
}
```
This `js/superadmin.js` includes:
*   `initializeSuperAdminDashboard()`: Called by `auth.js` if user is SA. Shows the SA dashboard, hides other content.
*   Loads all companies and all users into respective tables.
*   Handles opening an "Edit User" modal for SA to change a user's role and company assignment.
*   Makes AJAX calls to `sa_update_user_role` and `sa_update_user_company` in `superadmin_api.php`.

Lastly, need to ensure `js/auth.js` calls `initializeSuperAdminDashboard()`.
```
