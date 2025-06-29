$(document).ready(function() {
    const notificationsApiUrl = 'php/notifications_api.php';
    const $notificationsNav = $('#notificationsNav');
    const $notificationCountBadge = $('#notificationCountBadge');
    const $notificationsDropdownMenu = $('#notificationsDropdownMenu');
    const $notificationItemsContainer = $('#notificationItemsContainer');
    let notificationPollInterval;
    const POLLING_INTERVAL_MS = 3 * 60 * 1000; // 3 minutes

    // Function to fetch notifications from the server
    window.fetchUserNotifications = function() {
        // Ensure user is logged in before fetching (currentUserData should be available from auth.js)
        const currentUser = typeof getCurrentUserData === 'function' ? getCurrentUserData() : null;
        if (!currentUser) {
            // console.log("Notifications: User not logged in. Skipping fetch.");
            $notificationsNav.hide(); // Hide if somehow visible
            return;
        }
        $notificationsNav.show(); // Ensure it's visible if user is logged in

        $.ajax({
            url: notificationsApiUrl,
            method: 'GET',
            data: { action: 'get_notifications' },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    displayNotifications(response.notifications, response.unread_count);
                } else {
                    console.error("Error fetching notifications:", response.message);
                    // Optionally show a subtle error to the user or just log it
                }
            },
            error: function(xhr) {
                console.error("AJAX error fetching notifications:", xhr.statusText);
                // Optionally handle AJAX errors, e.g. if server is unreachable
            }
        });
    }

    // Function to display notifications in the dropdown
    function displayNotifications(notifications, unreadCount) {
        // Update badge
        if (unreadCount > 0) {
            $notificationCountBadge.text(unreadCount).show();
        } else {
            $notificationCountBadge.text('0').hide();
        }

        // Populate dropdown
        $notificationItemsContainer.empty(); // Clear old notifications
        if (notifications && notifications.length > 0) {
            notifications.forEach(function(notification) {
                const isReadClass = notification.is_read ? 'is-read' : '';
                // Basic time formatting (can be improved with a library like moment.js or date-fns for "x minutes ago")
                const notificationDate = new Date(notification.created_at.replace(/-/g, '/')); // Fix for Safari date parsing
                const timeAgo = formatTimeAgo(notificationDate);

                const notificationHtml = `
                    <a class="dropdown-item notification-item ${isReadClass}" href="${notification.link_url || '#'}" data-id="${notification.id}">
                        <p class="mb-0">${escapeHtml(notification.message)}</p>
                        <small class="text-muted">${timeAgo}</small>
                    </a>`;
                $notificationItemsContainer.append(notificationHtml);
            });
        } else {
            $notificationItemsContainer.append('<a class="dropdown-item text-muted text-center" href="#">No notifications</a>');
        }
    }

    // Simple time ago formatter
    function formatTimeAgo(date) {
        const seconds = Math.floor((new Date() - date) / 1000);
        let interval = Math.floor(seconds / 31536000);
        if (interval > 1) return interval + " years ago";
        interval = Math.floor(seconds / 2592000);
        if (interval > 1) return interval + " months ago";
        interval = Math.floor(seconds / 86400);
        if (interval > 1) return interval + " days ago";
        interval = Math.floor(seconds / 3600);
        if (interval > 1) return interval + " hours ago";
        interval = Math.floor(seconds / 60);
        if (interval > 1) return interval + " minutes ago";
        if (seconds < 10) return "just now";
        return Math.floor(seconds) + " seconds ago";
    }

    // Make sure escapeHtml is available if not already global (it should be from superadmin.js or other files)
    if (typeof escapeHtml !== 'function') {
        window.escapeHtml = function(unsafe) {
            if (unsafe === null || typeof unsafe === 'undefined') return '';
            return unsafe.toString().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
        }
    }


    // Event handler for clicking a single notification item
    $notificationItemsContainer.on('click', '.notification-item', function(e) {
        const $item = $(this);
        const notificationId = $item.data('id');
        const isRead = $item.hasClass('is-read');
        const linkUrl = $item.attr('href');

        if (!isRead) {
            markNotificationAsRead(notificationId, function(success) {
                if (success) {
                    $item.addClass('is-read');
                    // Decrement badge count
                    let currentCount = parseInt($notificationCountBadge.text()) || 0;
                    if (currentCount > 0) {
                        currentCount--;
                        $notificationCountBadge.text(currentCount);
                        if (currentCount === 0) $notificationCountBadge.hide();
                    }
                }
                 // Allow navigation even if marking as read fails, or decide based on UX
                if (linkUrl && linkUrl !== '#') {
                     // Handle fragment navigation carefully if it's for SPA-like behavior
                    if (linkUrl.startsWith("#")) {
                        // Potentially trigger custom event or function for SPA navigation
                        // For now, simple window.location change might reload or jump
                        console.log("Navigating to fragment:", linkUrl);
                        // window.location.hash = linkUrl; // This might be too simplistic
                        // If it's a full URL, let it navigate
                    } else {
                        // window.location.href = linkUrl; // Avoid for now unless full URLs are intended
                    }
                }
            });
        } else {
            // Already read, just navigate if link exists
            if (linkUrl && linkUrl !== '#') {
                console.log("Navigating to fragment (already read):", linkUrl);
            }
        }
        // Prevent default if it's just a fragment for SPA-like action,
        // but allow if it's a real URL (though we are not using real URLs yet for links)
        if (linkUrl === '#') {
            e.preventDefault();
        }
    });

    function markNotificationAsRead(notificationId, callback) {
        const token = typeof getCsrfToken === 'function' ? getCsrfToken() : null;
        if (!token) {
            console.error("CSRF token missing for markNotificationAsRead.");
            if (typeof callback === 'function') callback(false);
            return;
        }
        $.ajax({
            url: notificationsApiUrl,
            method: 'POST',
            data: {
                action: 'mark_notification_read',
                notification_id: notificationId,
                csrf_token: token
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // console.log("Notification marked as read:", notificationId);
                    if (typeof callback === 'function') callback(true);
                } else {
                    console.error("Failed to mark notification as read:", response.message);
                    if (typeof callback === 'function') callback(false);
                }
            },
            error: function(xhr) {
                console.error("AJAX error marking notification as read:", xhr.statusText);
                if (typeof callback === 'function') callback(false);
            }
        });
    }

    // Event handler for "Mark all as read"
    $('#markAllNotificationsRead').on('click', function(e) {
        e.preventDefault();
        const token = typeof getCsrfToken === 'function' ? getCsrfToken() : null;
        if (!token) {
            alert("Error: Security token not available. Please refresh."); // Or use a more subtle UI message
            return;
        }

        $.ajax({
            url: notificationsApiUrl,
            method: 'POST',
            data: {
                action: 'mark_all_notifications_read',
                csrf_token: token
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // Refresh all notifications to show them as read
                    fetchUserNotifications();
                    // showGlobalMessage is better here if available as dropdown might close
                    if(typeof showGlobalMessage === 'function') showGlobalMessage('All notifications marked as read.', true);
                    else alert('All notifications marked as read.');
                } else {
                    if(typeof showGlobalMessage === 'function') showGlobalMessage(response.message || 'Failed to mark all as read.', false);
                    else alert(response.message || 'Failed to mark all as read.');
                }
            },
            error: function(xhr) {
                if(typeof showGlobalMessage === 'function') handleGlobalApiError(xhr, "Error marking all notifications as read.");
                else alert("Server error marking all notifications as read.");
            }
        });
    });

    // Function to start polling for notifications
    window.startNotificationPolling = function() {
        if (notificationPollInterval) clearInterval(notificationPollInterval); // Clear existing interval

        const currentUser = typeof getCurrentUserData === 'function' ? getCurrentUserData() : null;
        if (currentUser) { // Only poll if user is logged in
            fetchUserNotifications(); // Initial fetch
            notificationPollInterval = setInterval(fetchUserNotifications, POLLING_INTERVAL_MS);
            console.log("Notification polling started.");
        }
    };

    // Function to stop polling for notifications (e.g., on logout)
    window.stopNotificationPolling = function() {
        if (notificationPollInterval) {
            clearInterval(notificationPollInterval);
            notificationPollInterval = null;
            console.log("Notification polling stopped.");
        }
        $notificationsNav.hide(); // Hide notification UI on logout
        $notificationCountBadge.text('0').hide();
        $notificationItemsContainer.html('<a class="dropdown-item text-muted text-center" href="#">No notifications</a>');
    };

    // Initial setup: If user is already logged in (e.g. page refresh with active session), start polling.
    // This will be more robustly handled by auth.js calling startNotificationPolling.
});
