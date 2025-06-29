$(document).ready(function() {
    const superAdminApiUrl = 'php/superadmin_api.php';
    const $superAdminDashboardContainer = $('#superAdminDashboardContainer');
    const $saCompanyListTableBody = $('#saCompanyListTableBody');
    const $saUserListTableBody = $('#saUserListTableBody');
    const $superAdminMessages = $('#superAdminMessages');
    let saCachedCompanies = []; // Cache for company list

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
            
            fetchAndCacheCompaniesSA(function() { // Fetch companies first
                loadAllCompaniesSA(); // Then load the table which might use parts of it
                loadAllUsersSA(); // Load users, modal will use cached companies
            });
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

    function fetchAndCacheCompaniesSA(callback) {
        $.ajax({
            url: superAdminApiUrl,
            method: 'GET',
            data: { action: 'sa_list_all_companies' },
            dataType: 'json',
            success: function(response) {
                if (response.success && response.companies) {
                    saCachedCompanies = response.companies; // Cache the company list
                } else {
                    saCachedCompanies = []; // Clear cache on error
                    console.error("SA: Failed to fetch or cache companies:", response.message);
                    showSAMessage("Error: Could not load company list for editing users.", false);
                }
                if (typeof callback === 'function') callback();
            },
            error: function(xhr) {
                saCachedCompanies = [];
                handleGlobalApiError(xhr, "SA: Error fetching company list for caching.");
                if (typeof callback === 'function') callback();
            }
        });
    }

    function loadAllCompaniesSA() {
        // This function primarily populates the table.
        // It can use saCachedCompanies if already populated by fetchAndCacheCompaniesSA,
        // or rely on its own AJAX call if this is called independently (though current flow is via initializeSuperAdminDashboard).
        // For simplicity, let's assume saCachedCompanies is the source if available.

        $saCompanyListTableBody.html('<tr><td colspan="7">Loading companies...</td></tr>');

        if (saCachedCompanies.length > 0) {
            populateCompanyTable(saCachedCompanies);
        } else {
            // Fallback or if called before cache is ready - though fetchAndCacheCompaniesSA should handle this.
            // This AJAX call here essentially becomes a refresh mechanism if called directly.
            $.ajax({
                url: superAdminApiUrl,
                method: 'GET',
                data: { action: 'sa_list_all_companies' },
                dataType: 'json',
                success: function(response) {
                    if (response.success && response.companies) {
                        saCachedCompanies = response.companies; // Update cache
                        populateCompanyTable(response.companies);
                    } else {
                        $saCompanyListTableBody.empty().append(`<tr><td colspan="7" class="text-danger">Error: ${response.message || 'Could not load companies.'}</td></tr>`);
                    }
                },
                error: function(xhr) {
                    handleGlobalApiError(xhr, "Error fetching all companies for table.");
                    $saCompanyListTableBody.html('<tr><td colspan="7" class="text-danger">Server error loading companies.</td></tr>');
                }
            });
        }
    }

    function populateCompanyTable(companies) {
        $saCompanyListTableBody.empty();
        if (companies.length === 0) {
            $saCompanyListTableBody.append('<tr><td colspan="7">No companies found.</td></tr>');
        } else {
            companies.forEach(company => {
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
                                        <button class="btn btn-sm btn-outline-danger sa-delete-company-btn" data-company-id="${company.id}" data-company-name="${escapeHtml(company.name)}">Delete</button>
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
                                        <button class="btn btn-sm btn-outline-danger sa-delete-user-btn" data-user-id="${user.id}" data-username="${escapeHtml(user.username)}">Delete</button>
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
        // $('#saEditUserCompany').val(companyId); // Old input field, remove/replace

        const $companySelect = $('#saEditUserCompanySelect');
        $companySelect.empty(); // Clear previous options
        $companySelect.append($('<option>', { value: '', text: '-- No Company --' }));

        if (saCachedCompanies && saCachedCompanies.length > 0) {
            saCachedCompanies.forEach(function(company) {
                $companySelect.append($('<option>', {
                    value: company.id,
                    text: escapeHtml(company.name) + ` (ID: ${company.id})`
                }));
            });
        }
        $companySelect.val(companyId || ''); // Set current company or '' for 'No Company'

        $('#saEditUserCurrentCompany').text(`${escapeHtml(companyName) || 'N/A'} (ID: ${companyId || 'N/A'})`);
        $('#saEditUserMessage').hide();
        $('#saEditUserModal').modal('show');
    });

    // Handle SA Save Changes to User button
    $('#saSaveChangesToUserButton').on('click', function() {
        const userIdToUpdate = $('#saEditUserId').val();
        const newRole = $('#saEditUserRole').val();
        let newCompanyId = $('#saEditUserCompanySelect').val();
        newCompanyId = newCompanyId === "" ? null : parseInt(newCompanyId, 10); // Convert "" to null for backend

        const token = typeof getCsrfToken === 'function' ? getCsrfToken() : null;

        if (!userIdToUpdate || !newRole) {
            // Use the modal's message area instead of alert
            showUserMessage("User ID and Role are required.", false, '#saEditUserMessage');
            return;
        }
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

    // Helper function to show messages in the SA Edit User modal
    function showUserMessage(message, isSuccess, targetSelector = '#saEditUserMessage') {
        const $messageArea = $(targetSelector);
        $messageArea.text(message)
            .removeClass('alert-success alert-danger')
            .addClass(isSuccess ? 'alert-success' : 'alert-danger') // Ensure alert-danger for false
            .show();
    }

    // Helper function to show messages in the SA Edit Company modal
    function showCompanyEditMessage(message, isSuccess) {
        const $saEditCompanyMessage = $('#saEditCompanyMessage');
        $saEditCompanyMessage.text(message)
            .removeClass('alert-success alert-danger')
            .addClass(isSuccess ? 'alert-success alert-danger' : 'alert-danger')
            .show();
    }

    // Handle SA Edit Company button click
    $(document).on('click', '.sa-edit-company-btn', function() {
        const companyId = $(this).data('company-id');
        const companyName = $(this).data('company-name'); // Already escaped from loadAllCompaniesSA

        $('#saEditCompanyIdToUpdate').val(companyId);
        $('#saEditCompanyName').val(companyName); // Set the raw name for editing
        $('#saEditCompanyMessage').hide();
        $('#saEditCompanyModalLabel').text(`Edit Company: ${escapeHtml(companyName)} (ID: ${companyId})`);
        $('#saEditCompanyModal').modal('show');
    });

    // Handle SA Save Changes to Company button
    $('#saSaveChangesToCompanyButton').on('click', function() {
        const companyIdToUpdate = $('#saEditCompanyIdToUpdate').val();
        const newCompanyName = $('#saEditCompanyName').val().trim();
        const token = typeof getCsrfToken === 'function' ? getCsrfToken() : null;

        if (!newCompanyName) {
            showCompanyEditMessage('Company name cannot be empty.', false);
            return;
        }
        if (!companyIdToUpdate) {
            showCompanyEditMessage('Company ID is missing. Cannot update.', false);
            return;
        }
        if (!token) {
            showCompanyEditMessage('CSRF token missing. Please refresh and try again.', false);
            return;
        }

        $.ajax({
            url: 'php/company_api.php', // Using company_api.php as it handles SA updates
            method: 'POST',
            data: {
                action: 'update_company_details',
                company_id_to_update: companyIdToUpdate,
                company_name: newCompanyName,
                csrf_token: token
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showCompanyEditMessage(response.message || 'Company updated successfully!', true);
                    setTimeout(function() {
                        $('#saEditCompanyModal').modal('hide');
                    }, 1500); // Hide modal after a short delay
                    loadAllCompaniesSA(); // Refresh the list of companies
                } else {
                    showCompanyEditMessage(response.message || 'Failed to update company.', false);
                }
            },
            error: function(xhr) {
                // Use handleGlobalApiError for consistency if it's suitable, or specific modal message
                const errorMsg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : "Server error updating company.";
                showCompanyEditMessage(errorMsg, false);
                // console.error("Error updating company (SA):", xhr);
            }
        });
    });

    // Handle SA Delete Company button click (to open confirmation modal)
    $(document).on('click', '.sa-delete-company-btn', function() {
        const companyId = $(this).data('company-id');
        const companyName = $(this).data('company-name'); // Already escaped from loadAllCompaniesSA

        $('#saCompanyIdToDelete').val(companyId);
        $('#saDeleteCompanyNameConfirm').text(companyName); // Display escaped name
        $('#saDeleteCompanyIdConfirm').text(companyId);
        $('#saDeleteCompanyConfirmMessage').hide().removeClass('alert alert-danger alert-success');
        $('#saDeleteCompanyConfirmModal').modal('show');
    });

    // Handle SA Confirm Delete Company button click (actual deletion)
    $('#saConfirmDeleteCompanyButton').on('click', function() {
        const companyIdToDelete = $('#saCompanyIdToDelete').val();
        const token = typeof getCsrfToken === 'function' ? getCsrfToken() : null;
        const $deleteConfirmMessage = $('#saDeleteCompanyConfirmMessage');

        if (!companyIdToDelete) {
            $deleteConfirmMessage.text('Company ID is missing. Cannot delete.').addClass('alert alert-danger').show();
            return;
        }
        if (!token) {
            $deleteConfirmMessage.text('CSRF token missing. Please refresh and try again.').addClass('alert alert-danger').show();
            return;
        }

        $.ajax({
            url: superAdminApiUrl, // php/superadmin_api.php
            method: 'POST',
            data: {
                action: 'sa_delete_company',
                company_id: companyIdToDelete,
                csrf_token: token
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showSAMessage(response.message || 'Company deleted successfully!', true);
                    $('#saDeleteCompanyConfirmModal').modal('hide');
                    loadAllCompaniesSA(); // Refresh the list of companies
                } else {
                    // Display error message inside the confirmation modal
                    $deleteConfirmMessage.text(response.message || 'Failed to delete company.').removeClass('alert-success').addClass('alert alert-danger').show();
                }
            },
            error: function(xhr) {
                const errorMsg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : "Server error deleting company.";
                $deleteConfirmMessage.text(errorMsg).removeClass('alert-success').addClass('alert alert-danger').show();
            }
        });
    });

    // Handle SA Delete User button click (to open confirmation modal)
    $(document).on('click', '.sa-delete-user-btn', function() {
        const userId = $(this).data('user-id');
        const username = $(this).data('username'); // Already escaped from loadAllUsersSA

        $('#saUserIdToDeleteConfirm').val(userId);
        $('#saDeleteUserNameConfirm').text(username); // Display escaped name
        $('#saDeleteUserIdConfirmSpan').text(userId);
        $('#saDeleteUserConfirmMessage').hide().removeClass('alert alert-danger alert-success');
        $('#saDeleteUserConfirmModal').modal('show');
    });

    // Handle SA Confirm Delete User button click (actual deletion)
    $('#saConfirmDeleteUserButton').on('click', function() {
        const userIdToDelete = $('#saUserIdToDeleteConfirm').val();
        const token = typeof getCsrfToken === 'function' ? getCsrfToken() : null;
        const $deleteConfirmMessage = $('#saDeleteUserConfirmMessage');

        if (!userIdToDelete) {
            $deleteConfirmMessage.text('User ID is missing. Cannot delete.').addClass('alert alert-danger').show();
            return;
        }
        // Prevent current super admin from deleting themselves via this button.
        // Backend also has a check, but good to have a client-side one too.
        const currentUser = typeof getCurrentUserData === 'function' ? getCurrentUserData() : null;
        if (currentUser && currentUser.id == userIdToDelete) {
             $deleteConfirmMessage.text('You cannot delete your own account using this button.').addClass('alert alert-danger').show();
            return;
        }

        if (!token) {
            $deleteConfirmMessage.text('CSRF token missing. Please refresh and try again.').addClass('alert alert-danger').show();
            return;
        }
        $deleteConfirmMessage.hide(); // Hide message before new attempt

        $.ajax({
            url: superAdminApiUrl, // php/superadmin_api.php
            method: 'POST',
            data: {
                action: 'sa_delete_user',
                user_id_to_delete: userIdToDelete,
                csrf_token: token
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showSAMessage(response.message || 'User deleted successfully!', true);
                    $('#saDeleteUserConfirmModal').modal('hide');
                    loadAllUsersSA(); // Refresh the list of users
                } else {
                    // Display error message inside the confirmation modal
                    $deleteConfirmMessage.text(response.message || 'Failed to delete user.').removeClass('alert-success').addClass('alert alert-danger').show();
                }
            },
            error: function(xhr) {
                const errorMsg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : "Server error deleting user.";
                $deleteConfirmMessage.text(errorMsg).removeClass('alert-success').addClass('alert alert-danger').show();
            }
        });
    });


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
