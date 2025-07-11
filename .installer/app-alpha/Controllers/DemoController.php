<?php

namespace App\Controllers;

use Nimbus\Controller\AbstractController;
use App\Models\DemoModel;

/**
 * DemoController - A simple demonstration controller
 */
class DemoController extends AbstractController
{
    private DemoModel $demoModel;
    
    protected function initialize(): void
    {
        $this->demoModel = new DemoModel($this->getDb());
    }
    
    /**
     * Home page
     */
    public function index()
    {
        $data = [
            'title' => 'APP-ALPHA Demo',
            'message' => 'Welcome to your Nimbus application!',
            'features' => [
                'MVC Architecture',
                'Database Integration',
                'Container Ready',
                'RESTful API Support'
            ],
            'stats' => $this->demoModel->getStats()
        ];
        
        $html = $this->render('demo/index', $data);
        echo $html;
    }
    
    /**
     * API endpoint - Get all items
     */
    public function apiList()
    {
        try {
            $items = $this->demoModel->getAllItems();
            $this->json([
                'success' => true,
                'data' => $items,
                'count' => count($items)
            ]);
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }
    
    /**
     * API endpoint - Get single item
     */
    public function apiGet($id)
    {
        try {
            $item = $this->demoModel->getItem($id);
            if (!$item) {
                $this->error('Item not found', 404);
                return;
            }
            $this->json(['success' => true, 'data' => $item]);
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }
    
    /**
     * API endpoint - Create item
     */
    public function apiCreate()
    {
        $data = $this->getRequestData();
        
        if (!$this->validate($data, ['name', 'description'])) {
            $this->error('Name and description are required');
            return;
        }
        
        try {
            $id = $this->demoModel->createItem($data);
            $this->json([
                'success' => true,
                'message' => 'Item created successfully',
                'id' => $id
            ], 201);
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }
    
    /**
     * API endpoint - Update item
     */
    public function apiUpdate($id)
    {
        $data = $this->getRequestData();
        
        try {
            $updated = $this->demoModel->updateItem($id, $data);
            if (!$updated) {
                $this->error('Item not found', 404);
                return;
            }
            $this->success(null, 'Item updated successfully');
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }
    
    /**
     * API endpoint - Delete item
     */
    public function apiDelete($id)
    {
        try {
            $deleted = $this->demoModel->deleteItem($id);
            if (!$deleted) {
                $this->error('Item not found', 404);
                return;
            }
            $this->success(null, 'Item deleted successfully');
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }
}