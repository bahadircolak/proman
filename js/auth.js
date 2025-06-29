// Global store for CSRF token
let csrfToken = null;
// Global store for user data after login
let currentUserData = null;

$(document).ready(function() {
    const authApiUrl = 'php/auth_api.php';
    const $authContainer = $('#authContainer');
    const $loginFormContainer = $('#loginFormContainer');
    const $registerFormContainer = $('#registerFormContainer');
    const $kanbanAppContainer = $('#kanbanAppContainer');
    const $companyManagementContainer = $('#companyManagementContainer');
    const $userInfo = $('#userInfo');
    const $usernameDisplay = $('#usernameDisplay');
    const $userRoleDisplay = $('#userRoleDisplay');
    const $logoutButton = $('#logoutButton');
    const $loginNav = $('#loginNav');
    const $registerNav = $('#registerNav');
    const $authMessage = $('#authMessage');
    const $globalMessages = $('#globalMessages'); // For messages not specific to auth form

    // Show messages in the global message area
    window.showGlobalMessage = function(message, isSuccess) {
        $globalMessages.text(message)
            .removeClass('alert-success alert-danger alert-info')
            .addClass(isSuccess ? 'alert alert-success' : 'alert alert-danger')
            .show().delay(5000).fadeOut();
    };
    
    // Show messages in the auth form message area
    window.showAuthFormMessage = function(message, isSuccess) {
        $authMessage.text(message)
            .removeClass('alert-success alert-danger')
            .addClass(isSuccess ? 'alert alert-success' : 'alert alert-danger')
            .show().delay(5000).fadeOut(); // Keep auth form messages visible a bit longer
    };

    function clearAuthMessage() { // Clears only auth form specific messages
        $authMessage.text('').removeClass('alert-success alert-danger').hide();
    }

    window.updateUIBasedOnAuthState = function(loggedIn, userData = null) {
        clearAuthMessage(); // Clear auth form messages
        // Don't clear global messages here, they might be from other actions
        currentUserData = loggedIn ? userData : null;

        if (loggedIn && userData) {
            $authContainer.hide();
            $userInfo.show();
            $usernameDisplay.text(userData.username);
            $userRoleDisplay.text(userData.role);
            $loginNav.hide();
            $registerNav.hide();
            $('#imageUploadGroup').show(); // In task modal

            if (userData.company_id) {
                $companyManagementContainer.show(); 
                $kanbanAppContainer.show();    
                if (typeof displayCompanyView === "function") { // From boards.js
                     $.ajax({
                        url: 'php/company_api.php', 
                        method: 'GET',
                        data: { action: 'get_my_company_details' }, 
                        dataType: 'json',
                        success: function(companyResponse) {
                            if (companyResponse.success) {
                                displayCompanyView(companyResponse.company, userData.role); // Call from boards.js
                                if(typeof loadCompanyAdminData === "function" && (userData.role === 'company_admin' || userData.role === 'super_admin')){ // From boards.js
                                    loadCompanyAdminData(companyResponse.company, userData.role);
                                }
                            } else {
                                console.error("Error fetching company details:", companyResponse.message);
                                $('#companyInfoNav').show();
                                $('#companyNameNav').text('Error!').addClass('text-danger');
                                if(companyResponse.no_company && userData.role !== 'super_admin'){
                                     if (typeof displayNoCompanyView === "function") displayNoCompanyView(); // From boards.js
                                } else if (typeof fetchUserBoards === "function") { // From boards.js
                                     fetchUserBoards(); 
                                }
                            }
                        },
                        error: function(xhr) {
                            handleApiError(xhr, "Error fetching company details post-login.");
                             $('#companyInfoNav').show();
                             $('#companyNameNav').text('Error!').addClass('text-danger');
                        }
                    });
                } else { console.warn("displayCompanyView function not found in boards.js");}
            } else if (userData.role !== 'super_admin') { 
                $kanbanAppContainer.hide();
                if (typeof displayNoCompanyView === "function") { // From boards.js
                    displayNoCompanyView();
                } else { console.warn("displayNoCompanyView function not found in boards.js");}
            } else { // Super Admin 
                 $companyManagementContainer.hide(); 
                 // Kanban app container will be hidden by initializeSuperAdminDashboard if it's shown
                 // $('#kanbanAppContainer').show(); // Don't show by default, SA dash takes precedence
                 $('#companyInfoNav').show();
                 $('#companyNameNav').text('Super Admin'); // This might be overwritten by SA dash
                 // $('#boardSelectorNav').show(); // SA dash might have its own navigation or use this.
                 $('#superAdminNavButtonContainer').show(); // Show the SA Dashboard button

                 if (typeof initializeSuperAdminDashboard === "function") {
                    // initializeSuperAdminDashboard(); // Don't auto-load, let SA click button
                 } else {
                    console.warn("initializeSuperAdminDashboard function not found in superadmin.js");
                 }
                 // SA might still want to use regular board view, so boards can be fetched.
                 // if (typeof fetchUserBoards === "function") fetchUserBoards(); // This is called by displayCompanyView or SA specific logic
            }
            // Start notification polling if function exists
            if (typeof window.startNotificationPolling === 'function') {
                window.startNotificationPolling();
            }
        } else { // Not logged in
            $authContainer.show();
            showLoginForm(); 
            // Stop notification polling if function exists
            if (typeof window.stopNotificationPolling === 'function') {
                window.stopNotificationPolling();
            }
            $kanbanAppContainer.hide();
            $companyManagementContainer.hide();
            $userInfo.hide();
            $('#companyInfoNav').hide();
            $('#boardSelectorNav').hide();
            $loginNav.show();
            $registerNav.show();
            $('#imageUploadGroup').hide(); 
            if(typeof clearKanbanBoardApp === "function") clearKanbanBoardApp(); // From script.js
        }
    }

    function showLoginForm() {
        clearAuthMessage();
        $loginFormContainer.show();
        $registerFormContainer.hide();
        $loginNav.addClass('active');
        $registerNav.removeClass('active');
    }

    function showRegisterForm() {
        clearAuthMessage();
        $loginFormContainer.hide();
        $registerFormContainer.show();
        $registerNav.addClass('active');
        $loginNav.removeClass('active');
    }

    $('#switchToRegister, #showRegister').on('click', function(e) { e.preventDefault(); showRegisterForm(); });
    $('#switchToLogin, #showLogin').on('click', function(e) { e.preventDefault(); showLoginForm(); });

    window.checkAuthStatus = function() { 
        $.ajax({
            url: authApiUrl, method: 'GET', data: { action: 'check_auth' }, dataType: 'json',
            success: function(response) {
                csrfToken = response.csrf_token; 
                updateUIBasedOnAuthState(response.success && response.loggedIn, response.user);
            },
            error: function(xhr) { // xhr for check_auth
                showGlobalMessage('Error checking auth status. Some features may not work. Please refresh.', false);
                updateUIBasedOnAuthState(false); 
                // Try to get CSRF token even on error for login/register forms
                $.ajax({ url: authApiUrl, method: 'GET', data: {action: 'get_csrf_token'}, dataType: 'json', 
                    success: function(r) { if(r.success) csrfToken = r.csrf_token; },
                    error: function() { console.error("Failed to fetch CSRF token after auth check error.");}
                });
            }
        });
    };
    checkAuthStatus(); // Initial check

    $('#loginForm').on('submit', function(e) {
        e.preventDefault(); clearAuthMessage();
        const username = $('#loginUsername').val(); const password = $('#loginPassword').val();
        if (!csrfToken) { showAuthFormMessage('Security token not available. Please refresh.', false); return; }
        $.ajax({
            url: authApiUrl, method: 'POST', data: { action: 'login', username: username, password: password, csrf_token: csrfToken }, dataType: 'json',
            success: function(response) {
                if (response.success) {
                    csrfToken = response.csrf_token; 
                    showGlobalMessage('Login successful!', true); // Use global message for login success
                    updateUIBasedOnAuthState(true, response.user);
                } else {
                    showAuthFormMessage(response.message || 'Login failed.', false);
                    $.ajax({ url: authApiUrl, method: 'GET', data: {action: 'get_csrf_token'}, dataType: 'json', success: function(r) { if(r.success) csrfToken = r.csrf_token; } });
                }
            },
            error: function(xhr) { // xhr for login
                handleGlobalApiError(xhr, 'Server error during login.');
                $.ajax({ url: authApiUrl, method: 'GET', data: {action: 'get_csrf_token'}, dataType: 'json', success: function(r) { if(r.success) csrfToken = r.csrf_token; } });
            }
        });
    });

    $('#registerForm').on('submit', function(e) {
        e.preventDefault(); clearAuthMessage();
        const username = $('#registerUsername').val(); const email = $('#registerEmail').val();
        const password = $('#registerPassword').val(); const confirmPassword = $('#registerConfirmPassword').val();
        if (password !== confirmPassword) { showAuthFormMessage('Passwords do not match.', false); return; }
        if (password.length < 6) { showAuthFormMessage('Password must be at least 6 characters.', false); return; }
        if (!csrfToken) { showAuthFormMessage('Security token not available. Please refresh.', false); return; }
        $.ajax({
            url: authApiUrl, method: 'POST', data: { action: 'register', username: username, email: email, password: password, csrf_token: csrfToken }, dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showAuthFormMessage(response.message || 'Registration successful! Please login.', true);
                    showLoginForm(); $('#loginForm')[0].reset(); $('#registerForm')[0].reset(); 
                    $.ajax({ url: authApiUrl, method: 'GET', data: {action: 'get_csrf_token'}, dataType: 'json', success: function(r) { if(r.success) csrfToken = r.csrf_token; } });
                } else {
                    showAuthFormMessage(response.message || 'Registration failed.', false);
                    $.ajax({ url: authApiUrl, method: 'GET', data: {action: 'get_csrf_token'}, dataType: 'json', success: function(r) { if(r.success) csrfToken = r.csrf_token; } });
                }
            },
            error: function(xhr) { // xhr for register
                handleGlobalApiError(xhr, 'Server error during registration.');
                $.ajax({ url: authApiUrl, method: 'GET', data: {action: 'get_csrf_token'}, dataType: 'json', success: function(r) { if(r.success) csrfToken = r.csrf_token; } });
            }
        });
    });

    $logoutButton.on('click', function() {
        clearAuthMessage(); $globalMessages.hide(); // Hide global messages on logout
        if (!csrfToken) { console.warn("CSRF token not available for logout."); }
        $.ajax({
            url: authApiUrl, method: 'POST', data: { action: 'logout', csrf_token: csrfToken }, dataType: 'json',
            error: function(xhr) { handleGlobalApiError(xhr, 'Server error during logout. Forcing logout locally.'); },
            complete: function() { 
                csrfToken = null; 
                updateUIBasedOnAuthState(false); 
                $.ajax({ url: authApiUrl, method: 'GET', data: {action: 'get_csrf_token'}, dataType: 'json', success: function(r) { if(r.success) csrfToken = r.csrf_token; } });
            }
        });
    });
});

function getCsrfToken() { return csrfToken; }
function getCurrentUserData() { return currentUserData; }

// More generic global API error handler
function handleGlobalApiError(xhr, defaultMessage) {
    let message = defaultMessage;
    if (xhr.responseJSON && xhr.responseJSON.message) {
        message = xhr.responseJSON.message;
    } else if (xhr.statusText && xhr.status !== 0) { 
        message += " (" + xhr.status + " " + xhr.statusText + ")";
    } else if (xhr.status === 0) {
        message = "Network error. Please check your connection.";
    }
    
    console.error("API Error:", xhr);
    showGlobalMessage(message, false); // Use global message display

    if (xhr.status === 401 || (xhr.responseJSON && xhr.responseJSON.redirectToLogin)) {
        updateUIBasedOnAuthState(false); 
    } else if (xhr.status === 403 && message.toLowerCase().includes('csrf')) {
        // CSRF error, prompt refresh or try to get new token
    }
}
```
This updated `js/auth.js` has more robust UI update logic and calls `loadCompanyAdminData` from `boards.js` when a company admin logs in. It also uses a global message display area.

The frontend UI for *company-level* invitations is now mostly established in `index.php` and `js/boards.js` (with support from `js/auth.js`). The next part is the UI for *board-level* member management.

I will proceed to add UI elements to `index.php` for managing board members (within a board's context, e.g., a "Board Settings" modal) and then the JS logic in `js/boards.js` to power it.

```
