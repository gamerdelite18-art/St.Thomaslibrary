<?php
/**
 * db.php - MySQL connection + prepared statement helpers
 * Core PHP (PDO). Every query in this project goes through these helpers,
 * so user input is always bound as a parameter (no SQL injection).
 */

// ---------- Database credentials ----------
define('DB_HOST', 'localhost');
define('DB_NAME', 'library_db');
define('DB_USER', 'root');       // XAMPP default
define('DB_PASS', '');           // XAMPP default
define('DB_CHARSET', 'utf8mb4');

/**
 * Returns the shared PDO connection (created once per request).
 */
function db()
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // throw on SQL errors
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // rows as arrays
                PDO::ATTR_EMULATE_PREPARES   => false,                  // real prepared statements
            ]);
        } catch (PDOException $e) {
            die('<div style="font-family:sans-serif;padding:40px;text-align:center">
                    <h2>Database connection failed</h2>
                    <p>' . htmlspecialchars($e->getMessage()) . '</p>
                    <p>Check <code>includes/db.php</code> and import <code>database/library.sql</code>.</p>
                 </div>');
        }
    }

    return $pdo;
}

/**
 * Prepare + execute a statement.
 * @param string $sql    SQL with ? placeholders
 * @param array  $params Values to bind
 * @return PDOStatement
 */
function db_query($sql, $params = [])
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/** Fetch all rows. */
function db_all($sql, $params = [])
{
    return db_query($sql, $params)->fetchAll();
}

/** Fetch a single row (or null). */
function db_one($sql, $params = [])
{
    $row = db_query($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** Fetch a single value (first column of first row). */
function db_val($sql, $params = [], $default = 0)
{
    $val = db_query($sql, $params)->fetchColumn();
    return $val === false ? $default : $val;
}
