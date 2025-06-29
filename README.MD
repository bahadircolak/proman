# Kanban Pro - Task Management Board (Multi-Tenant)

A standalone Kanban board application built with PHP, MySQL, jQuery, and Bootstrap. It supports multiple companies and boards, user authentication, task management with image uploads, and drag-and-drop functionality.

## Features

-   **Multi-Company & Multi-Board**: Supports distinct companies, each with multiple Kanban boards.
-   **User Authentication & Roles**: Secure registration, login, logout. Roles include 'user', 'company_admin', 'super_admin'.
-   **Task Management**: CRUD operations for tasks within a specific board.
    -   Tasks include title, description, status, an optional image, and ordering.
-   **Drag & Drop**: Move tasks between columns (updates status) and reorder within columns.
-   **Image Uploads**: Attach images to tasks, with server-side validation and storage.
-   **Company Management**: Users can create companies (becoming company_admin). Company admins can update basic company details.
-   **Super Admin Capabilities**:
    -   Basic backend endpoint to list all companies.
    -   (Further UI and management features planned for later phases).
-   **Security**: CSRF protection, password hashing, prepared statements (anti-SQLi), XSS prevention (client-side), secure uploads directory.

## Project Structure

-   `index.php`: Main HTML file, entry point.
-   `css/style.css`: Custom CSS styles.
-   `js/auth.js`: Frontend JS for authentication.
-   `js/boards.js`: Frontend JS for company and board management UI.
-   `js/script.js`: Frontend JS for Kanban board (tasks) functionality.
-   `php/config.php`: Database credentials, base URL, uploads config, session start.
-   `php/db.php`: PDO database connection utility.
-   `php/security.php`: CSRF token functions.
-   `php/auth_api.php`: Backend API for authentication.
-   `php/company_api.php`: Backend API for company operations.
-   `php/boards_api.php`: Backend API for board operations.
-   `php/tasks_api.php`: Backend API for task operations.
-   `uploads/`: Directory for task images, protected by `.htaccess`.
-   `AGENTS.md`: Instructions for software agents.
-   `README.md`: This file.

## Setup and Usage

### 1. Prerequisites
-   Web server with PHP (7.2+ recommended: Apache, Nginx).
-   MySQL database server.
-   PHP extensions: `pdo_mysql`, `session`, `fileinfo` (recommended for uploads).

### 2. Database Setup
-   Create a MySQL database (e.g., `kanban_pro_db`).
-   Create a MySQL user (e.g., `kanban_pro_user`) with a secure password.
-   Grant this user necessary permissions on the database (SELECT, INSERT, UPDATE, DELETE).
-   Execute the SQL schema:
    ```sql
    CREATE TABLE `companies` (
      `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      `name` VARCHAR(255) NOT NULL,
      `owner_user_id` INT UNSIGNED NULL,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX `idx_company_owner` (`owner_user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    CREATE TABLE `users` (
      `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      `company_id` INT UNSIGNED NULL DEFAULT NULL,
      `username` VARCHAR(50) NOT NULL UNIQUE,
      `email` VARCHAR(100) NOT NULL UNIQUE,
      `password_hash` VARCHAR(255) NOT NULL,
      `role` VARCHAR(50) NOT NULL DEFAULT 'user' COMMENT 'e.g., user, company_admin, super_admin',
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX `idx_user_company` (`company_id`),
      CONSTRAINT `fk_user_company` FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    ALTER TABLE `companies`
      ADD CONSTRAINT `fk_company_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL;

    CREATE TABLE `boards` (
      `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      `company_id` INT UNSIGNED NOT NULL,
      `name` VARCHAR(255) NOT NULL,
      `description` TEXT NULL,
      `created_by_user_id` INT UNSIGNED NOT NULL,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX `idx_board_company` (`company_id`),
      INDEX `idx_board_created_by` (`created_by_user_id`),
      CONSTRAINT `fk_board_company` FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE,
      CONSTRAINT `fk_board_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    CREATE TABLE `tasks` (
      `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      `user_id` INT UNSIGNED NOT NULL COMMENT 'Creator or primary responsible user',
      `board_id` INT UNSIGNED NOT NULL,
      `title` VARCHAR(255) NOT NULL,
      `description` TEXT,
      `status` VARCHAR(50) NOT NULL DEFAULT 'todo',
      `image_path` VARCHAR(255) NULL,
      `task_order` INT DEFAULT 0,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX `idx_task_board` (`board_id`),
      CONSTRAINT `fk_task_board` FOREIGN KEY (`board_id`) REFERENCES `boards`(`id`) ON DELETE CASCADE,
      CONSTRAINT `fk_task_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE -- Added FK for user_id in tasks
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ```

### 3. Application Configuration
-   Clone/download files to your web server.
-   Edit `php/config.php` with your database credentials (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`).
-   Ensure `uploads/` directory (at project root) is writable by the web server. The `.htaccess` file inside it should be present.

### 4. Designating a Super Admin (Manual Step)
-   After registering a user who will be the super admin, manually update their role in the database:
    ```sql
    UPDATE users SET role = 'super_admin' WHERE username = 'your_chosen_superadmin_username';
    ```
   (Replace `your_chosen_superadmin_username` accordingly). A super admin does not need to be tied to a specific company.

### 5. Running the Application
-   Navigate to `index.php` in your browser.
-   Register, then log in. If not part of a company, you'll be prompted to create one.

## How to Use
-   **Authentication**: Register, login. Logout via navbar.
-   **Company**: If not in one, create one. Company admins can update the company name.
-   **Boards**: Select from dropdown, or create new boards. Tasks are managed per board.
-   **Tasks**: Add, edit (including image), delete, drag/drop to change status or order.

## Development Notes
-   **APIs**: `auth_api.php`, `company_api.php`, `boards_api.php`, `tasks_api.php`. All return JSON.
-   **Security**: CSRF, password hashing, PDO prepared statements, XSS (client HTML escape), upload validation, `.htaccess` for uploads.
-   **Dependencies (CDN)**: Bootstrap, jQuery, jQuery UI.
-   **Image URLs**: `APP_BASE_URL` in `index.php` (from `php/config.php`) helps form correct image URLs.

## Future (Planned) Phases
-   User Roles & Permissions within companies/boards (invitations, task assignment).
-   Company Admin Dashboard (user management, board management).
-   Super Admin Dashboard (global company/user management, reporting).
-   Advanced features (notifications, search, etc.).
```

With `php/company_api.php` updated (or re-created successfully) and `README.md` now reflecting the super admin setup, the basic backend support and documentation for "Super Admin - Basic Company Viewing" are in place.

The frontend aspect for a super admin to *use* this `list_all_companies` endpoint (e.g., a dedicated page/view) is part of a later phase (Phase 4: Super Admin Management & Reporting Screen). This current step was primarily about establishing the role and a minimal backend capability.

```
