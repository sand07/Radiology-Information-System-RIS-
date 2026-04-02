<?php

/**
 * Konfigurasi Database untuk RIS
 * Database: sikbackup2
 * Driver: PDO MySQL
 */

// Konfigurasi Database
define('DB_HOST', '');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', '');
define('DB_PORT', '3306');

// Konfigurasi Orthanc PACS
define('ORTHANC_URL', '');
define('ORTHANC_USERNAME', '');
define('ORTHANC_PASSWORD', '');

// Konfigurasi Enkripsi
define('ENCRYPT_KEY_USER', '');
define('ENCRYPT_KEY_PASS', '');

// Helper untuk koneksi database
class Database
{
    private $pdo;
    private $lastError;
    private $connected = false;

    public function __construct()
    {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5,
            ];

            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            $this->connected = true;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->connected = false;

            // Log error instead of dying
            error_log('Database Connection Error: ' . $e->getMessage());

            // Untuk development, show error message
            if (php_sapi_name() !== 'cli') {
                // hanya log, tidak die
            }
        }
    }

    /**
     * Check apakah connected
     */
    public function isConnected()
    {
        return $this->connected;
    }

    /**
     * Eksekusi query dengan prepared statement
     * @param string $query
     * @param array $params
     * @return mixed
     */
    public function query($query, $params = [])
    {
        if (!$this->connected || $this->pdo === null) {
            $this->lastError = 'Database tidak terhubung: ' . $this->lastError;
            throw new Exception('Database tidak terhubung: ' . $this->lastError);
        }

        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            throw $e;
        }
    }

    /**
     * Fetch satu baris
     */
    public function fetch($query, $params = [])
    {
        $stmt = $this->query($query, $params);
        return $stmt->fetch();
    }

    /**
     * Fetch semua baris
     */
    public function fetchAll($query, $params = [])
    {
        $stmt = $this->query($query, $params);
        return $stmt->fetchAll();
    }

    /**
     * Fetch satu nilai
     */
    public function fetchColumn($query, $params = [], $column = 0)
    {
        $stmt = $this->query($query, $params);
        return $stmt->fetchColumn($column);
    }

    /**
     * Insert data
     */
    public function insert($table, $data)
    {
        $columns = array_keys($data);
        $values = array_values($data);
        $placeholders = array_fill(0, count($columns), '?');

        $query = "INSERT INTO $table (" . implode(',', $columns) . ") VALUES (" . implode(',', $placeholders) . ")";

        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($values);
            return $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    /**
     * Update data
     */
    public function update($table, $data, $where = [])
    {
        $set = [];
        $values = [];

        foreach ($data as $column => $value) {
            $set[] = "$column = ?";
            $values[] = $value;
        }

        $whereClause = '';
        foreach ($where as $column => $value) {
            $whereClause .= " AND $column = ?";
            $values[] = $value;
        }

        $query = "UPDATE $table SET " . implode(', ', $set) . " WHERE 1=1 $whereClause";

        try {
            $stmt = $this->pdo->prepare($query);
            return $stmt->execute($values);
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    /**
     * Delete data
     */
    public function delete($table, $where = [])
    {
        $conditions = [];
        $values = [];

        foreach ($where as $column => $value) {
            $conditions[] = "$column = ?";
            $values[] = $value;
        }

        $query = "DELETE FROM $table WHERE " . implode(' AND ', $conditions);

        try {
            $stmt = $this->pdo->prepare($query);
            return $stmt->execute($values);
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    /**
     * Get last error
     */
    public function getLastError()
    {
        return $this->lastError;
    }

    /**
     * Close connection
     */
    public function close()
    {
        $this->pdo = null;
    }
}
