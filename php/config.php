<?php
// Database Configuration

define('DB_HOST', 'localhost');    // Your database host (e.g., '127.0.0.1' or 'localhost')
define('DB_NAME', 'kanban_db');    // Your database name
define('DB_USER', 'kanban_user');  // Your database username
define('DB_PASS', 'your_secure_password'); // Your database password
define('DB_CHARSET', 'utf8mb4');

// Base URL of the application (useful for redirects, links)
// Detect scheme (http or https)
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
// Detect host
$host = $_SERVER['HTTP_HOST'];
// Get the directory of the current script, relative to the document root
$script_dir = str_replace(basename($_SERVER['SCRIPT_NAME']), '', $_SERVER['SCRIPT_NAME']);
// Remove trailing slash from script_dir if it's not the root
$script_dir = ($script_dir === '/') ? '' : rtrim($script_dir, '/');

define('BASE_URL', $scheme . $host . $script_dir);

// Directory for uploaded images
define('UPLOADS_DIR', __DIR__ . '/../uploads'); // Assumes 'uploads' directory is at the project root
define('UPLOADS_URL', BASE_URL . '/uploads'); // URL to access uploads

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
?>
