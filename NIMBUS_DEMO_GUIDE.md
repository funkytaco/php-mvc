# Nimbus Demo - Quick Test Guide

## 🎯 Purpose
The `nimbus-app-php` template demonstrates the complete Nimbus framework functionality with minimal code. Users can create a working app without writing any code.

## ✅ What Is Currently Working

### 1. App Creation (All Commands Working)
```bash
# Basic app creation
composer nimbus:create test-app

# EDA-enabled app creation
composer nimbus:create-with-eda test-app  

# Add EDA to existing app
composer nimbus:add-eda test-app
```

**What Works:**
- ✅ Created from nimbus-app-php template
- ✅ Generated unique port based on app name hash
- ✅ Created secure randomly-generated database password
- ✅ Replaced all placeholders with app-specific values
- ✅ Registered in .installer/apps.json
- ✅ Auto-creates app.nimbus.json configuration
- ✅ EDA rulebooks and playbooks generated when enabled

### 2. App Installation & Management
```bash
# Install app files and generate containers
composer nimbus:install test-app

# List all apps with status
composer nimbus:list

# Start/stop apps with monitoring
composer nimbus:up test-app
composer nimbus:down test-app
```

**What Works:**
- ✅ Copied all assets to active directories (app/Controllers, app/Views, etc.)
- ✅ Generated {app-name}-compose.yml file with proper YAML format
- ✅ Created container configuration with networking and volumes
- ✅ Lists all created apps with installation and running status
- ✅ Interactive start/stop with health monitoring
- ✅ Container status display with icons and health checks

## 📁 What Gets Created

### Template Structure (`/.installer/_templates/nimbus-app-php/`)
```
nimbus-app-php/
├── app.nimbus.json          # App configuration
├── app.config.php           # PHP app config
├── Controllers/
│   └── IndexController.php   # Full CRUD controller using Fast Route, by default
├── Models/
│   └── DemoModel.php        # Database model
├── Views/
│   └── demo/
│       └── index.mustache   # HTML view
├── routes/
│   └── CustomRoutes.php     # API routes. Fast Route, by default
└── database/
    └── schema.sql           # Database schema with sample data
```

### Generated App Instance (`/.installer/test-app/`)
- All template files with placeholders replaced
- App-specific database credentials
- Unique container port assignment

### Active Files (copied during install)
- `app/Controllers/IndexController.php`
- `app/Models/DemoModel.php`
- `app/Views/demo/index.mustache`
- `app/CustomRoutes.php`
- `app/app.config.php`
- `test-app-compose.yml`

## 🚀 Demo Features

### Web Interface
- **Route**: `GET /`
- **Features**: Responsive dashboard showing app stats
- **Database**: Displays item count from demo_items table

### REST API
- `GET /api/items` - List all items
- `GET /api/items/{id}` - Get single item
- `POST /api/items` - Create item (JSON: {name, description})
- `PUT /api/items/{id}` - Update item
- `DELETE /api/items/{id}` - Delete item

### Database
- PostgreSQL with demo_items table
- 3 sample records pre-inserted
- Proper indexes and constraints

## 🎨 Technologies Demonstrated

1. **Nimbus Controller**: Extends AbstractController
2. **Database**: PDO with transaction support
3. **Views**: Mustache template engine
4. **API**: RESTful endpoints with JSON responses
5. **Validation**: Built-in request validation
6. **Containers**: PostgreSQL + PHP app setup

## 🧪 Testing the Demo

### Start the containers (Nimbus Way)
```bash
# Nimbus handles everything automatically
composer nimbus:up test-app
```

**OR manually:**
```bash
podman-compose -f test-app-compose.yml up -d
```

### Test the web interface
```bash
# Port is generated based on app name hash
curl http://localhost:$(cat .installer/apps/test-app/app.nimbus.json | grep -o '"port": "[^"]*' | cut -d'"' -f4)/
```

### Test the API
```bash
# Find your app's port first
APP_PORT=$(cat .installer/apps/test-app/app.nimbus.json | grep -o '"port": "[^"]*' | cut -d'"' -f4)

# List items
curl http://localhost:$APP_PORT/api/items

# Create item
curl -X POST http://localhost:$APP_PORT/api/items \
  -H "Content-Type: application/json" \
  -d '{"name":"Test Item","description":"Created via API"}'

# Get single item
curl http://localhost:$APP_PORT/api/items/1
```

## 🎯 Success Criteria

✅ **No Code Required**: User just runs commands
✅ **Complete Stack**: Web + API + Database working
✅ **Container Ready**: Generates proper compose file
✅ **Database Integration**: Schema loads with sample data
✅ **Unique Configuration**: Each app gets unique ports/passwords
✅ **Framework Features**: All Nimbus components demonstrated

## 🔄 Creating Additional Apps

Users can create multiple apps:
```bash
composer nimbus:create my-blog
composer nimbus:create api-service
composer nimbus:create monitoring-dashboard
```

Each gets:
- Unique port assignment
- Isolated database
- Separate container namespace
- Independent configuration

The demo proves the Nimbus framework is ready for production use with a streamlined developer experience.