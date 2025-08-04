# Nimbus Framework Quick Start Guide

## Overview
Nimbus is a complete PHP MVC framework with built-in container orchestration, EDA (Event-Driven Ansible) support, and automated app management. It replaces manual processes with powerful CLI commands.

## Quick Commands

### App Lifecycle
```bash
# Create basic app
composer nimbus:create my-app

# Create app with EDA enabled
composer nimbus:create-with-eda my-app

# Add EDA to existing app
composer nimbus:add-eda my-app

# Install app (copy files to containers)
composer nimbus:install my-app

# List all apps
composer nimbus:list

# Start apps with status monitoring
composer nimbus:up              # Interactive mode
composer nimbus:up my-app       # Start specific app

# Stop apps with cleanup options
composer nimbus:down            # Interactive mode
composer nimbus:down my-app     # Stop specific app
```

### Status Monitoring
The `nimbus:up` command shows comprehensive status:
- ✅ **Image built status** - Shows if container images exist
- 🟢 **Running status** - Shows running/stopped with container counts  
- ✅ **Health status** - Displays overall health (healthy/unhealthy/partial)
- 📊 **Individual containers** - Shows each container's state and health

### App Shutdown
The `nimbus:down` command provides flexible stopping options:
- 🛑 **Graceful shutdown** - Stops containers with configurable timeout
- 🗑️ **Volume cleanup** - Option to remove persistent data
- 📦 **Container removal** - Option to completely remove containers
- 💿 **Image cleanup** - Option to remove built images
- 🔄 **Bulk operations** - Stop all running apps at once

## Key Improvements

### 1. Bootstrap Replacement
- **Before**: `Bootstrap.php` with hardcoded configuration
- **After**: `Nimbus\Core\Application` with dynamic config loading

### 2. Controller Enhancement
```php
// Before (manual dependency injection)
class OrderController implements ControllerInterface {
    public function get() {
        $injector = new Injector();
        $db = $injector->make('PDO');
        $renderer = $injector->make('Renderer');
    }
}

// After (automatic injection)
class OrderController extends \Nimbus\Controller\AbstractController {
    public function listOrders() {
        $orders = $this->db->query("SELECT * FROM orders")->fetchAll();
        return $this->render('orders/list', ['orders' => $orders]);
    }
}
```

### 3. Automated App Management

**Before** (Manual Process):
1. Copy files to `.installer/app-name/`
2. Edit `composer.json` to add install command
3. Manually create container configs
4. Update ApplicationTasks.php

**After** (Fully Automated):
```bash
# One command creates everything
composer nimbus:create-with-eda monitoring

# Automatically generates:
# - App directory structure
# - Container configurations  
# - EDA rulebooks and playbooks
# - Database schema
# - Compose files with YAML validation
```

## App Configuration (app.nimbus.json)

```json
{
    "name": "my-app",
    "version": "1.0.0",
    "type": "nimbus-app-php",
    "description": "A Nimbus framework application",
    "features": {
        "database": true,
        "eda": true,
        "certbot": false
    },
    "containers": {
        "app": {
            "port": "8080"
        },
        "db": {
            "engine": "postgres",
            "version": "14"
        },
        "eda": {
            "image": "quay.io/ansible/eda-server:latest",
            "rulebooks_dir": "rulebooks"
        }
    },
    "database": {
        "name": "my-app_db",
        "user": "my-app_user", 
        "password": "auto-generated"
    }
}
```

## Current Directory Structure

```
php-mvc-lkui/
├── src/
│   ├── Nimbus/              # ✅ Framework core (IMPLEMENTED)
│   │   ├── Core/
│   │   │   └── Application.php   # Replaces Bootstrap.php
│   │   ├── Controller/
│   │   │   └── AbstractController.php
│   │   └── App/
│   │       └── AppManager.php    # Handles app lifecycle
├── app/                     # Active app files (generated)
├── public/
│   └── index.php           # ✅ Updated to use Nimbus
├── .installer/
│   ├── _templates/          # ✅ App templates
│   │   └── nimbus-app-php/     # Default template with EDA
│   ├── apps.json           # ✅ App registry
│   ├── app-alpha/          # ✅ Example apps
│   ├── app-beta/           
│   └── test-app/
├── data/                    # Container data volumes
└── *-compose.yml           # ✅ Generated compose files
```

## Container Architecture

Each app generates a complete container stack:

### Standard App (2 containers):
- **app-name-app**: PHP/Apache application server
- **app-name-postgres**: PostgreSQL database with health checks

### EDA-Enabled App (3 containers):
- **app-name-app**: PHP/Apache application server  
- **app-name-postgres**: PostgreSQL database
- **app-name-eda**: Ansible EDA server with webhook listener

## Real-World Examples

### Create a Simple API
```bash
composer nimbus:create customer-api
composer nimbus:install customer-api
composer nimbus:up customer-api
# → Running at http://localhost:8XXX (auto-assigned port)
```

### Create Event-Driven App  
```bash
composer nimbus:create-with-eda order-processor
composer nimbus:install order-processor
composer nimbus:up order-processor
# → App + Database + EDA automation running
# → Webhook listener on port 5000
```

### Add EDA to Existing App
```bash
composer nimbus:add-eda customer-api
composer nimbus:install customer-api
composer nimbus:up customer-api
# → Now includes EDA container with rulebooks
```

## Status Monitoring Example

```bash
$ composer nimbus:up
Available apps to start:
  [1] app-alpha (✓ built, ▶️ running (3/3), ✅ healthy)
      └─ app-alpha-app: running 🟢 ➖
      └─ app-alpha-postgres: running 🟢 ✅
      └─ app-alpha-eda: running 🟢 ➖
  [2] customer-api (✗ not built, ⏹️ stopped, ⏸️ stopped)
  [3] order-processor (✓ built, ⚠️ partial (2/3), 🔄 partial)
```

## Implementation Status

✅ **Core Framework**: Complete & Working
- ✅ Nimbus\Core\Application (fully replaces Bootstrap.php)
- ✅ AbstractController with dependency injection
- ✅ Dynamic configuration loading from app.nimbus.json
- ✅ PSR-7 compatible with named_vars support

✅ **App Management**: Complete & Working  
- ✅ Automated app creation and installation
- ✅ Template system with placeholder replacement
- ✅ Container generation with working YAML output
- ✅ App registry system (apps.json)
- ✅ Asset copying and file management

✅ **EDA Integration**: Complete & Working
- ✅ EDA-enabled app creation via nimbus:create-with-eda
- ✅ Add EDA to existing apps via nimbus:add-eda
- ✅ Rulebook and playbook templating  
- ✅ Ansible EDA container orchestration
- ✅ Webhook listener configuration

✅ **Container Orchestration**: Complete & Working
- ✅ Multi-container app stacks (App + DB + EDA)
- ✅ Health monitoring and status reporting
- ✅ Automatic port assignment (hash-based)
- ✅ Podman-compose integration
- ✅ Volume mounting for live development

✅ **CLI Commands**: Complete & Working
- ✅ All composer nimbus:* commands functional
- ✅ Interactive and direct command modes
- ✅ Comprehensive status monitoring with icons
- ✅ Graceful shutdown with cleanup options

🎯 **Current Status**: Production Ready
- All documented features are implemented and working
- Apps can be created, installed, and run successfully
- Container orchestration is fully functional
- EDA integration works with real Ansible automation