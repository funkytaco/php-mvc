<?php

namespace Nimbus\Database;

use PDO;
use PDOException;

/**
 * Connection provides an abstraction layer over PDO
 */
class Connection
{
    private PDO $pdo;
    private array $config;
    private bool $inTransaction = false;
    
    public function __construct(array $config)
    {
        $this->config = $this->normalizeConfig($config);
        $this->connect();
    }
    
    /**
     * Normalize database configuration
     */
    private function normalizeConfig(array $config): array
    {
        return array_merge([
            'driver' => 'pgsql',
            'host' => 'localhost',
            'port' => 5432,
            'database' => null,
            'username' => null,
            'password' => null,
            'charset' => 'utf8',
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        ], $config);
    }
    
    /**
     * Connect to the database
     */
    private function connect(): void
    {
        try {
            $dsn = $this->buildDsn();
            $this->pdo = new PDO(
                $dsn,
                $this->config['username'],
                $this->config['password'],
                $this->config['options']
            );
        } catch (PDOException $e) {
            throw new DatabaseException("Connection failed: " . $e->getMessage(), 0, $e);
        }
    }
    
    /**
     * Build DSN string
     */
    private function buildDsn(): string
    {
        $driver = $this->config['driver'];
        
        switch ($driver) {
            case 'mysql':
                return sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                    $this->config['host'],
                    $this->config['port'],
                    $this->config['database'],
                    $this->config['charset']
                );
                
            case 'pgsql':
                return sprintf(
                    'pgsql:host=%s;port=%d;dbname=%s',
                    $this->config['host'],
                    $this->config['port'],
                    $this->config['database']
                );
                
            case 'sqlite':
                return 'sqlite:' . $this->config['database'];
                
            default:
                throw new DatabaseException("Unsupported driver: $driver");
        }
    }
    
    /**
     * Execute a query with optional parameters
     */
    public function query(string $sql, array $params = []): \PDOStatement
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            throw new DatabaseException("Query failed: " . $e->getMessage(), 0, $e);
        }
    }
    
    /**
     * Execute a query and return all results
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }
    
    /**
     * Execute a query and return first result
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $result = $this->query($sql, $params)->fetch();
        return $result === false ? null : $result;
    }
    
    /**
     * Execute a query and return a single column value
     */
    public function fetchColumn(string $sql, array $params = [], int $column = 0)
    {
        return $this->query($sql, $params)->fetchColumn($column);
    }
    
    /**
     * Execute an INSERT, UPDATE, or DELETE query
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }
    
    /**
     * Get the last insert ID
     */
    public function lastInsertId(?string $name = null): string
    {
        return $this->pdo->lastInsertId($name);
    }
    
    /**
     * Begin a transaction
     */
    public function beginTransaction(): bool
    {
        if (!$this->inTransaction) {
            $this->inTransaction = $this->pdo->beginTransaction();
        }
        return $this->inTransaction;
    }
    
    /**
     * Commit a transaction
     */
    public function commit(): bool
    {
        if ($this->inTransaction) {
            $result = $this->pdo->commit();
            $this->inTransaction = false;
            return $result;
        }
        return false;
    }
    
    /**
     * Rollback a transaction
     */
    public function rollback(): bool
    {
        if ($this->inTransaction) {
            $result = $this->pdo->rollBack();
            $this->inTransaction = false;
            return $result;
        }
        return false;
    }
    
    /**
     * Execute a callback within a transaction
     */
    public function transaction(callable $callback)
    {
        $this->beginTransaction();
        
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }
    
    /**
     * Quote a string for use in a query
     */
    public function quote(string $string, int $type = PDO::PARAM_STR): string
    {
        return $this->pdo->quote($string, $type);
    }
    
    /**
     * Get the underlying PDO instance
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }
    
    /**
     * Get a query builder instance
     */
    public function queryBuilder(): QueryBuilder
    {
        return new QueryBuilder($this);
    }
    
    /**
     * Check if connected
     */
    public function isConnected(): bool
    {
        try {
            $this->pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS);
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
    
    /**
     * Reconnect to the database
     */
    public function reconnect(): void
    {
        $this->pdo = null;
        $this->connect();
    }
}