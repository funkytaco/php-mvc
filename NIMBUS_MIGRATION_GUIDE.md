# Nimbus Migration Guide

## Migrating Controllers to Nimbus Framework

### Before (Old Style)
```php
<?php
use Main\Renderer\Renderer;

class OrderController implements ControllerInterface {
    protected $renderer;
    protected $conn;
    
    public function __construct(Renderer $renderer, PDO $conn) {
        $this->renderer = $renderer;
        $this->conn = $conn;
    }
    
    public function get() {
        $html = $this->renderer->render('template', ['data' => 'value']);
        echo $html;
    }
}
```

### After (Nimbus Style)
```php
<?php
namespace App\Controllers;

use Nimbus\Controller\AbstractController;

class OrderController extends AbstractController {
    public function get(...$params) {
        $html = $this->render('template', ['data' => 'value']);
        echo $html;
    }
}
```

## Key Changes

### 1. Namespace Declaration
- Add `namespace App\Controllers;` at the top
- Controllers now live in the `App\Controllers` namespace

### 2. Extend AbstractController
- Change from `implements ControllerInterface` to `extends AbstractController`
- Import with `use Nimbus\Controller\AbstractController;`

### 3. Constructor Simplification
- Remove manual dependency injection in constructor
- Use `$this->container` to access the DI container
- Override `initialize()` method for custom setup

### 4. Built-in Helper Methods
```php
// Rendering
$this->render($template, $data)  // Returns rendered HTML

// JSON responses
$this->json($data, $status)      // Send JSON response
$this->error($message, $status)  // Send error response
$this->success($data, $message)  // Send success response

// Database access
$this->getDb()                   // Get PDO instance

// Request handling
$this->getRequestData()          // Get POST/JSON data
$this->validate($data, $fields) // Validate required fields

// Navigation
$this->redirect($url)            // Redirect to URL
```

### 5. HTTP Method Handlers
```php
public function get(...$params)     // Handle GET requests
public function post(...$params)    // Handle POST requests
public function put(...$params)     // Handle PUT requests
public function delete(...$params)  // Handle DELETE requests
public function patch(...$params)   // Handle PATCH requests
```

## Step-by-Step Migration

### Step 1: Update Namespace
```php
<?php
namespace App\Controllers;
```

### Step 2: Change Inheritance
```php
use Nimbus\Controller\AbstractController;

class MyController extends AbstractController {
```

### Step 3: Remove Constructor (if only doing DI)
```php
// Delete this if only injecting renderer/PDO
public function __construct(Renderer $renderer, PDO $conn) {
    $this->renderer = $renderer;
    $this->conn = $conn;
}
```

### Step 4: Update Database Calls
```php
// Before
$stmt = $this->conn->prepare("SELECT * FROM users");

// After
$db = $this->getDb();
$stmt = $db->prepare("SELECT * FROM users");
```

### Step 5: Update Rendering
```php
// Before
$html = $this->renderer->render('template', $data);

// After
$html = $this->render('template', $data);
```

## Custom Dependencies

If your controller needs custom dependencies:

```php
class MyController extends AbstractController {
    private MyService $myService;
    
    protected function initialize(): void {
        $this->myService = $this->container->make('App\Services\MyService');
    }
}
```

## Routes Update

Update your routes to use the new namespace:

```php
// Before
['GET', '/orders', [OrderController::class, 'showOrders']]

// After
['GET', '/orders', ['App\Controllers\OrderController', 'showOrders']]
```

## Benefits After Migration

1. ✅ Less boilerplate code
2. ✅ Consistent error handling
3. ✅ Built-in JSON responses
4. ✅ Request data handling
5. ✅ Validation helpers
6. ✅ Transaction support
7. ✅ Cleaner controllers

## Gradual Migration

You can migrate controllers one at a time. The old and new styles can coexist during the migration period.