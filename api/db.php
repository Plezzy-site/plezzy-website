<?php
/**
 * PLEZZY Database Connection & Data Access Layer
 * Supports Hostinger MySQL / MariaDB (PDO) with automatic JSON file fallback.
 */

require_once __DIR__ . '/config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

class PlezzyDB {
    private static $pdo = null;
    private static $isMySql = false;
    private static $connectionAttempted = false;

    /**
     * Get active PDO instance or null if MySQL unavailable
     */
    public static function getConnection() {
        if (self::$connectionAttempted) {
            return self::$pdo;
        }

        self::$connectionAttempted = true;

        // Skip connection attempt if DB_NAME is default empty and no pass
        if (empty(DB_NAME) || (DB_HOST === 'localhost' && DB_NAME === 'plezzy_db' && DB_USER === 'root' && empty(DB_PASS))) {
            // Attempt local silent probe
        }

        try {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 3, // fast timeout for seamless fallback
            ];
            self::$pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            self::$isMySql = true;

            // Auto-initialize schema if tables do not exist yet
            self::ensureSchemaInitialized();
        } catch (PDOException $e) {
            self::$pdo = null;
            self::$isMySql = false;
            error_log('[Plezzy DB] MySQL connection not active (' . $e->getMessage() . '). Using JSON fallback store.');
        }

        return self::$pdo;
    }

    /**
     * Is MySQL connection currently active?
     */
    public static function isMySql() {
        self::getConnection();
        return self::$isMySql;
    }

    /**
     * Auto initialize MySQL tables if products table is missing
     */
    public static function ensureSchemaInitialized() {
        if (!self::$pdo) return;
        try {
            $stmt = self::$pdo->query("SHOW TABLES LIKE 'products'");
            if ($stmt->rowCount() === 0) {
                $sqlFile = __DIR__ . '/../database.sql';
                if (file_exists($sqlFile)) {
                    $sql = file_get_contents($sqlFile);
                    self::$pdo->exec($sql);
                    error_log('[Plezzy DB] Successfully auto-initialized MySQL schema & product catalog from database.sql');
                }
            }
        } catch (Exception $e) {
            error_log('[Plezzy DB] Auto-schema init warning: ' . $e->getMessage());
        }
    }

    /**
     * JSON Fallback Storage Helpers
     */
    public static function getDataDir() {
        $dir = __DIR__ . '/../data';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    public static function readJson($filePath, $default = []) {
        if (!file_exists($filePath)) return $default;
        $raw = @file_get_contents($filePath);
        if ($raw === false) return $default;
        $data = json_decode($raw, true);
        return is_array($data) ? $data : $default;
    }

    public static function writeJson($filePath, $data) {
        $temp = $filePath . '.tmp';
        @file_put_contents($temp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
        if (file_exists($temp)) {
            @rename($temp, $filePath);
            return true;
        }
        return false;
    }

    /**
     * Clean JSON API response output
     */
    public static function json($data, $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit();
    }

    /**
     * Parse incoming JSON request body
     */
    public static function getJsonInput() {
        $raw = file_get_contents('php://input');
        if (empty($raw)) return $_POST;
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
