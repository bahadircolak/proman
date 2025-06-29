## Agent Instructions for Kanban Board Project

This project is a Kanban board application using PHP (standalone), MySQL, jQuery, and Bootstrap. It features user authentication and image uploads for tasks.

### Project Structure:
-   `index.php`: Main HTML file, entry point. Handles display of auth forms or Kanban board.
-   `css/style.css`: Custom CSS styles.
-   `js/auth.js`: JavaScript for user authentication (login, registration, logout, UI updates based on auth state).
-   `js/script.js`: JavaScript/jQuery logic for Kanban board interactions (CRUD, drag-and-drop, image handling).
-   `php/config.php`: Database credentials, base URL, upload directory configuration, session start.
-   `php/db.php`: PDO database connection utility.
-   `php/security.php`: CSRF token generation and validation functions.
-   `php/auth_api.php`: Backend API for user registration, login, logout, auth status check.
-   `php/tasks_api.php`: Backend API for task management (CRUD, image uploads, status/order updates).
-   `uploads/`: Directory for storing uploaded task images. Contains an `.htaccess` file for security.
-   `AGENTS.md`: This file.
-   `README.md`: Project overview, setup, and usage instructions.

### Development Guidelines:

1.  **Database Interaction (`php/db.php`, `php/*_api.php`):**
    *   Uses MySQL via PDO. All database interactions are through prepared statements to prevent SQL injection.
    *   Schema includes `users` and `tasks` tables (see `README.md` or initial plan for schema details).
    *   `tasks` are associated with `users` via `user_id`. All task operations are scoped to the logged-in user.

2.  **User Authentication:**
    *   Handled by `php/auth_api.php` (backend) and `js/auth.js` (frontend).
    *   Passwords are hashed using `password_hash()` and verified with `password_verify()`.
    *   PHP sessions manage login state. `$_SESSION['user_id']` and `$_SESSION['username']` are set.
    *   `php/tasks_api.php` checks for `$_SESSION['user_id']` to protect endpoints.

3.  **Task Management (`php/tasks_api.php`, `js/script.js`):**
    *   Supports CRUD operations for tasks.
    *   Drag-and-drop uses jQuery UI Sortable for reordering tasks within and between columns. The backend action `update_task_order_and_status` handles batch updates.
    *   Task statuses are validated against a predefined list (`todo`, `inprogress`, `done`).

4.  **Image Uploads:**
    *   Handled by `php/tasks_api.php` (backend) and `js/script.js` (frontend using FormData).
    *   **Security:**
        *   Files are uploaded to the `uploads/` directory. This directory has an `.htaccess` file to prevent script execution and directory listing.
        *   File types are validated by extension (server-side). Consider adding MIME type validation using `finfo` for greater security if the `fileinfo` PHP extension is available.
        *   File size is limited (currently 2MB).
        *   Filenames are regenerated using `uniqid()` and `preg_replace` for sanitization to prevent conflicts and malicious names.
        *   Associated image files are deleted from the server when a task is deleted or its image is replaced/removed.

5.  **Security - General:**
    *   **CSRF Protection:** Implemented for all state-changing POST requests.
        *   `php/security.php` contains `generateCsrfToken()` and `validateCsrfToken()`.
        *   `verifyCsrfTokenProtection()` is called in API scripts (`auth_api.php`, `tasks_api.php`).
        *   `js/auth.js` and `js/script.js` fetch and send the CSRF token (`csrf_token`) with relevant AJAX POST requests.
    *   **Input Validation:**
        *   User inputs (usernames, emails, passwords, task titles, descriptions, statuses) are validated on the backend.
        *   `trim()` used for string inputs. `filter_var()` for emails. Length checks for passwords. Statuses checked against an allow-list.
    *   **XSS Protection:**
        *   `escapeHtml()` function in `js/script.js` is used before displaying user-generated content (titles, descriptions) in task cards.
        *   PDO prepared statements inherently protect against SQL injection for database queries.

6.  **Frontend Logic (`js/*.js`):**
    *   Uses jQuery for DOM manipulation and event handling.
    *   AJAX calls handle communication with backend APIs.
    *   Error handling for AJAX calls is implemented, including specific handling for auth failures (e.g., `redirectToLogin`) and CSRF errors.

7.  **Styling (`css/style.css`):**
    *   Uses Bootstrap for general layout and components.
    *   Custom styles enhance the visual appeal and functionality.

### Setup Notes for Developers:
*   Ensure MySQL server is running.
*   Create the database and user as specified in `php/config.php` (or update `config.php` with your details). The SQL schema is in `README.md`.
*   Ensure the `uploads/` directory exists at the project root and is writable by the web server. The `.htaccess` file inside it should be preserved.
*   The `BASE_URL` in `php/config.php` is auto-detected but verify its correctness for your environment, especially if using complex proxy setups. It's used by `js/script.js` for constructing image URLs if `APP_BASE_URL` is defined in `index.php`.

Remember to maintain these security practices and conventions when adding new features.
Consider adding more robust file type validation (MIME types via `finfo`) if possible.
Consider rate limiting for authentication attempts.
Ensure PHP error logging is directed to a file and not displayed to users in production.
Keep dependencies (jQuery, Bootstrap) updated if not using CDNs, or monitor CDN versions for security.
```
Now, update `README.md`.
```
