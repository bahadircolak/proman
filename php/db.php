<?php
require_once __DIR__ . '/config.php';

/**
 * Establishes a PDO database connection.
 *
 * @return PDO|null Returns a PDO connection object on success, or null on failure.
 */
function getDBConnection() {
    static $pdo = null; // Static variable to hold the connection

    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Throw exceptions on error
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Fetch results as associative arrays
            PDO::ATTR_EMULATE_PREPARES   => false,                  // Use native prepared statements
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // In a real application, log this error and show a generic message to the user.
            // For development, you might want to see the error.
            error_log("Database Connection Error: " . $e->getMessage());
            // For this exercise, we'll throw it to make it visible during development if DB setup is wrong.
            // In production, you'd handle this more gracefully.
            throw new PDOException($e->getMessage(), (int)$e->getCode());
            // return null; // Or return null / die with a message for production
        }
    }
    return $pdo;
}

/**
 * Helper function to get the ID of the last inserted row.
 * Particularly useful after an INSERT operation.
 *
 * @param PDO $pdo The PDO connection object.
 * @return string|false The ID of the last inserted row on success, false on failure.
 */
function getLastInsertId(PDO $pdo) {
    return $pdo->lastInsertId();
}

// You can test the connection by uncommenting the following lines:
/*
try {
    $conn = getDBConnection();
    if ($conn) {
        echo "Database connection successful!";
    } else {
        echo "Database connection failed.";
    }
} catch (PDOException $e) {
    echo "Database connection error: " . $e->getMessage();
}
*/
?>
