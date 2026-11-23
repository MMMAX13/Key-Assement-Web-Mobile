<?php
// config/db.php - single PDO connection used by the API and the web portal

const DB_HOST    = '127.0.0.1';
const DB_NAME    = 'inventory_system';
const DB_USER    = 'root';
const DB_PASS    = '';          // XAMPP default is empty
const DB_CHARSET = 'utf8mb4';

function get_pdo(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,   // real prepared statements
        ]);
    }

    return $pdo;
}