# Nimbus Framework Quick Start Guide

## Overview
Nimbus transforms your PHP MVC framework into a modular, container-ready platform with built-in EDA support.

## Key Changes

### 1. Namespace Migration
- **Before**: `Main\*` namespace with components in `src/`
- **After**: `Nimbus\*` namespace with modular components

### 2. Controller Enhancement
```php
// Before
class OrderController implements ControllerInterface {
    public function get() {
        // Manual dependency injection
    }
}

// After
class OrderController extends \Nimbus\Controller\AbstractController {
    public function listOrders() {
        $orders = $this->db->query("SELECT * FROM orders")->fetchAll();
        return $this->render('orders/list', ['orders' => $orders]);
    }
}
```

### 3. Simplified App Creation

**Before** (Manual Process):
1. Copy files to `.installer/app-name/`
2. Edit `composer.json` to add install command
3. Modify `ApplicationTasks.php`
4. Create container configs manually

**After** (Automated):
```bash
# Create new EDA-enabled app
composer nimbus:create my-app --template=eda-enabled

# Install the app
composer nimbus:install my-app

# Generate containers
composer nimbus:containers my-app
```

## App Configuration (app.nimbus.json)

```json
{
    "name": "my-app",
    "type": "themed-app",
    "features": {
        "database": true,
        "eda": true,
        "certbot": false
    },
    "containers": {
        "app": {
            "ports": ["8080:8080"]
        },
        "db": {
            "engine": "postgres",
            "version": "14"
        },
        "eda": {
            "rulebooks": ["monitoring", "webhooks"]
        }
    }
}
```

## Directory Structure

```
my-project/
├── src/
│   └── Nimbus/           # Framework core
├── app/                  # Active app files
├── .installer/
│   ├── _templates/       # App templates
│   ├── apps.json        # App registry
│   └── my-app/          # Your app
│       ├── app.nimbus.json
│       ├── Controllers/
│       ├── Views/
│       ├── Models/
│       ├── database/
│       └── containers/
└── eda/
    └── my-app/
        ├── rulebooks/
        └── playbooks/
```

## Migration Path

1. **Phase 1**: Install Nimbus core without breaking existing code
2. **Phase 2**: Migrate controllers to extend AbstractController
3. **Phase 3**: Switch to Nimbus app management
4. **Phase 4**: Enable advanced features (EDA, monitoring)

## Benefits

✅ **Less Code**: No more manual copying in ApplicationTasks.php
✅ **Consistency**: All apps follow the same structure
✅ **Flexibility**: Swap template engines, databases easily
✅ **Scalability**: Each app runs in isolated containers
✅ **Power**: Built-in EDA for event-driven automation

## Example: Creating a Monitoring Dashboard

```bash
# Create app with EDA support
composer nimbus:create monitoring --template=eda-enabled

# The system automatically:
# - Creates directory structure
# - Generates container configs
# - Sets up EDA rulebooks
# - Configures database
# - Registers in apps.json

# Install and run
composer nimbus:install monitoring
podman-compose -f monitoring-compose.yml up -d
```

## Next Implementation Steps

1. Create `src/Nimbus/` directory structure
2. Move Bootstrap.php logic to Nimbus\Core\Application  
3. Create AbstractController base class
4. Implement AppManager for automated installations
5. Test with existing lkui app