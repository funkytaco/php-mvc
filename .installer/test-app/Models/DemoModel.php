<?php

namespace App\Models;

use PDO;

/**
 * DemoModel - Simple model for demonstration
 */
class DemoModel
{
    private PDO $db;
    
    public function __construct(PDO $db)
    {
        $this->db = $db;
    }
    
    /**
     * Get statistics
     */
    public function getStats(): array
    {
        try {
            $stmt = $this->db->query("SELECT COUNT(*) as total FROM demo_items");
            $total = $stmt->fetchColumn();
            
            return [
                'total_items' => $total,
                'last_updated' => date('Y-m-d H:i:s')
            ];
        } catch (\Exception $e) {
            return [
                'total_items' => 0,
                'last_updated' => 'Never'
            ];
        }
    }
    
    /**
     * Get all items
     */
    public function getAllItems(): array
    {
        $stmt = $this->db->query("
            SELECT * FROM demo_items 
            ORDER BY created_at DESC
        ");
        return $stmt->fetchAll();
    }
    
    /**
     * Get single item
     */
    public function getItem(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM demo_items 
            WHERE id = :id
        ");
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch();
        
        return $result ?: null;
    }
    
    /**
     * Create new item
     */
    public function createItem(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO demo_items (name, description, created_at) 
            VALUES (:name, :description, NOW())
        ");
        
        $stmt->execute([
            'name' => $data['name'],
            'description' => $data['description']
        ]);
        
        return (int) $this->db->lastInsertId();
    }
    
    /**
     * Update item
     */
    public function updateItem(int $id, array $data): bool
    {
        $fields = [];
        $params = ['id' => $id];
        
        if (isset($data['name'])) {
            $fields[] = 'name = :name';
            $params['name'] = $data['name'];
        }
        
        if (isset($data['description'])) {
            $fields[] = 'description = :description';
            $params['description'] = $data['description'];
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $fields[] = 'updated_at = NOW()';
        
        $stmt = $this->db->prepare("
            UPDATE demo_items 
            SET " . implode(', ', $fields) . "
            WHERE id = :id
        ");
        
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Delete item
     */
    public function deleteItem(int $id): bool
    {
        $stmt = $this->db->prepare("
            DELETE FROM demo_items 
            WHERE id = :id
        ");
        
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }
}