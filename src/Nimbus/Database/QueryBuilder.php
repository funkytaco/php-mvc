<?php

namespace Nimbus\Database;

/**
 * QueryBuilder provides a fluent interface for building SQL queries
 */
class QueryBuilder
{
    private Connection $connection;
    private array $query = [
        'type' => 'SELECT',
        'table' => null,
        'columns' => ['*'],
        'joins' => [],
        'where' => [],
        'orderBy' => [],
        'groupBy' => [],
        'having' => [],
        'limit' => null,
        'offset' => null,
        'values' => [],
    ];
    private array $bindings = [];
    
    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }
    
    /**
     * Set the table to query
     */
    public function table(string $table): self
    {
        $this->query['table'] = $table;
        return $this;
    }
    
    /**
     * Set the table alias
     */
    public function from(string $table, string $alias = null): self
    {
        $this->query['table'] = $alias ? "$table AS $alias" : $table;
        return $this;
    }
    
    /**
     * Set columns to select
     */
    public function select(...$columns): self
    {
        $this->query['type'] = 'SELECT';
        $this->query['columns'] = empty($columns) ? ['*'] : $columns;
        return $this;
    }
    
    /**
     * Add a where condition
     */
    public function where(string $column, $value = null, string $operator = '='): self
    {
        if ($value === null) {
            // Raw where clause
            $this->query['where'][] = ['raw' => $column];
        } else {
            $placeholder = $this->addBinding($value);
            $this->query['where'][] = [
                'column' => $column,
                'operator' => $operator,
                'placeholder' => $placeholder,
                'connector' => 'AND'
            ];
        }
        return $this;
    }
    
    /**
     * Add an OR where condition
     */
    public function orWhere(string $column, $value = null, string $operator = '='): self
    {
        if (empty($this->query['where'])) {
            return $this->where($column, $value, $operator);
        }
        
        if ($value === null) {
            $this->query['where'][] = ['raw' => $column, 'connector' => 'OR'];
        } else {
            $placeholder = $this->addBinding($value);
            $this->query['where'][] = [
                'column' => $column,
                'operator' => $operator,
                'placeholder' => $placeholder,
                'connector' => 'OR'
            ];
        }
        return $this;
    }
    
    /**
     * Add a where IN condition
     */
    public function whereIn(string $column, array $values): self
    {
        $placeholders = [];
        foreach ($values as $value) {
            $placeholders[] = $this->addBinding($value);
        }
        
        $this->query['where'][] = [
            'column' => $column,
            'operator' => 'IN',
            'placeholders' => $placeholders,
            'connector' => 'AND'
        ];
        return $this;
    }
    
    /**
     * Add a JOIN clause
     */
    public function join(string $table, string $first, string $operator, string $second): self
    {
        $this->query['joins'][] = [
            'type' => 'INNER',
            'table' => $table,
            'first' => $first,
            'operator' => $operator,
            'second' => $second
        ];
        return $this;
    }
    
    /**
     * Add a LEFT JOIN clause
     */
    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        $this->query['joins'][] = [
            'type' => 'LEFT',
            'table' => $table,
            'first' => $first,
            'operator' => $operator,
            'second' => $second
        ];
        return $this;
    }
    
    /**
     * Add ORDER BY clause
     */
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->query['orderBy'][] = "$column $direction";
        return $this;
    }
    
    /**
     * Add GROUP BY clause
     */
    public function groupBy(...$columns): self
    {
        $this->query['groupBy'] = array_merge($this->query['groupBy'], $columns);
        return $this;
    }
    
    /**
     * Set LIMIT clause
     */
    public function limit(int $limit): self
    {
        $this->query['limit'] = $limit;
        return $this;
    }
    
    /**
     * Set OFFSET clause
     */
    public function offset(int $offset): self
    {
        $this->query['offset'] = $offset;
        return $this;
    }
    
    /**
     * Execute the query and get all results
     */
    public function get(): array
    {
        $sql = $this->toSql();
        return $this->connection->fetchAll($sql, $this->bindings);
    }
    
    /**
     * Execute the query and get first result
     */
    public function first(): ?array
    {
        $this->limit(1);
        $sql = $this->toSql();
        return $this->connection->fetchOne($sql, $this->bindings);
    }
    
    /**
     * Count records
     */
    public function count(): int
    {
        $this->query['columns'] = ['COUNT(*) as count'];
        $sql = $this->toSql();
        return (int) $this->connection->fetchColumn($sql, $this->bindings);
    }
    
    /**
     * Insert data
     */
    public function insert(array $data): int
    {
        $this->query['type'] = 'INSERT';
        $this->query['values'] = $data;
        
        $columns = array_keys($data);
        $placeholders = [];
        
        foreach ($data as $value) {
            $placeholders[] = $this->addBinding($value);
        }
        
        $sql = sprintf(
            "INSERT INTO %s (%s) VALUES (%s)",
            $this->query['table'],
            implode(', ', $columns),
            implode(', ', $placeholders)
        );
        
        return $this->connection->execute($sql, $this->bindings);
    }
    
    /**
     * Update data
     */
    public function update(array $data): int
    {
        $this->query['type'] = 'UPDATE';
        $this->query['values'] = $data;
        
        $sets = [];
        foreach ($data as $column => $value) {
            $placeholder = $this->addBinding($value);
            $sets[] = "$column = $placeholder";
        }
        
        $sql = sprintf(
            "UPDATE %s SET %s%s",
            $this->query['table'],
            implode(', ', $sets),
            $this->buildWhereClause()
        );
        
        return $this->connection->execute($sql, $this->bindings);
    }
    
    /**
     * Delete records
     */
    public function delete(): int
    {
        $this->query['type'] = 'DELETE';
        
        $sql = sprintf(
            "DELETE FROM %s%s",
            $this->query['table'],
            $this->buildWhereClause()
        );
        
        return $this->connection->execute($sql, $this->bindings);
    }
    
    /**
     * Build the complete SQL query
     */
    public function toSql(): string
    {
        switch ($this->query['type']) {
            case 'SELECT':
                return $this->buildSelectQuery();
            default:
                throw new DatabaseException("Unsupported query type: {$this->query['type']}");
        }
    }
    
    /**
     * Build SELECT query
     */
    private function buildSelectQuery(): string
    {
        $sql = sprintf(
            "SELECT %s FROM %s",
            implode(', ', $this->query['columns']),
            $this->query['table']
        );
        
        // Add JOINs
        foreach ($this->query['joins'] as $join) {
            $sql .= sprintf(
                " %s JOIN %s ON %s %s %s",
                $join['type'],
                $join['table'],
                $join['first'],
                $join['operator'],
                $join['second']
            );
        }
        
        // Add WHERE clause
        $sql .= $this->buildWhereClause();
        
        // Add GROUP BY
        if (!empty($this->query['groupBy'])) {
            $sql .= ' GROUP BY ' . implode(', ', $this->query['groupBy']);
        }
        
        // Add ORDER BY
        if (!empty($this->query['orderBy'])) {
            $sql .= ' ORDER BY ' . implode(', ', $this->query['orderBy']);
        }
        
        // Add LIMIT
        if ($this->query['limit'] !== null) {
            $sql .= ' LIMIT ' . $this->query['limit'];
        }
        
        // Add OFFSET
        if ($this->query['offset'] !== null) {
            $sql .= ' OFFSET ' . $this->query['offset'];
        }
        
        return $sql;
    }
    
    /**
     * Build WHERE clause
     */
    private function buildWhereClause(): string
    {
        if (empty($this->query['where'])) {
            return '';
        }
        
        $conditions = [];
        foreach ($this->query['where'] as $index => $where) {
            if (isset($where['raw'])) {
                $condition = $where['raw'];
            } elseif (isset($where['placeholders'])) {
                // WHERE IN clause
                $condition = "{$where['column']} {$where['operator']} (" . 
                           implode(', ', $where['placeholders']) . ")";
            } else {
                $condition = "{$where['column']} {$where['operator']} {$where['placeholder']}";
            }
            
            if ($index > 0 && isset($where['connector'])) {
                $conditions[] = $where['connector'] . ' ' . $condition;
            } else {
                $conditions[] = $condition;
            }
        }
        
        return ' WHERE ' . implode(' ', $conditions);
    }
    
    /**
     * Add a binding and return placeholder
     */
    private function addBinding($value): string
    {
        $key = ':param' . count($this->bindings);
        $this->bindings[$key] = $value;
        return $key;
    }
    
    /**
     * Get current bindings
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }
}