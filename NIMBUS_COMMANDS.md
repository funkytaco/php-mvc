# Nimbus Commands Reference

## App Creation & Setup

### `composer nimbus:create [<app>] [<template>]`
**Purpose:** Scaffold a new containerized app from a template.

**Flow:**
1. Prompts for app name (if not provided)
2. Shows available templates and aliases
3. Prompts for template selection (shows default)
4. Optionally accepts `--no-db` flag to create without database container
5. Checks vault for existing credentials for this app
6. Copies template → `.installer/apps/<app>/`
7. Substitutes `{{PLACEHOLDER}}` tokens in `app.config.php` and `app.nimbus.json`
8. Shows next steps interactively

**Example:**
```bash
composer nimbus:create my-app lkui
composer nimbus:create another-app --no-db
```

---

### `composer nimbus:create-with-eda [<app>] [<template>]`
**Purpose:** Create an app with Event-Driven Ansible (EDA) container enabled by default.

**Equivalent to:** `nimbus:create <app> <template>` then `nimbus:add-eda <app>`

**Example:**
```bash
composer nimbus:create-with-eda my-eda-app
```

---

### `composer nimbus:create-eda-keycloak [<app>]`
**Purpose:** Create an app with both EDA and Keycloak SSO enabled.

**Features enabled:**
- Event-Driven Ansible (EDA) on port 5000
- Keycloak identity provider

**Example:**
```bash
composer nimbus:create-eda-keycloak secure-app
```

---

## App Installation & Deployment

### `composer nimbus:install [<app>]`
**Purpose:** Finalize app setup and generate container orchestration config.

**Flow:**
1. Installs app assets from `.installer/apps/<app>/` → `app/`
2. Generates `<app>-compose.yml` (podman-compose config)
3. Validates Postgres/MySQL schemas if present
4. Makes the app ready to run with `nimbus:up`

**Note:** You must run this after `nimbus:create` before starting containers.

**Example:**
```bash
composer nimbus:install my-app
```

---

### `composer nimbus:up [<app>]`
**Purpose:** Start containers for an app (interactive if no app specified).

**Prerequisites:** Requires `nimbus:install` first and a valid `<app>-compose.yml`

**Features:**
- Displays container status (running/stopped/health)
- Interactive menu if multiple apps available
- Checks podman-compose availability

**Example:**
```bash
composer nimbus:up my-app              # Start specific app
composer nimbus:up                     # Interactive: choose which app
```

---

### `composer nimbus:down [<app>]`
**Purpose:** Stop running containers for an app.

**Flow:**
1. Lists running apps with container statuses
2. Interactive menu if no app specified
3. Gracefully stops all containers in the app stack

**Example:**
```bash
composer nimbus:down my-app
composer nimbus:down                   # Interactive: choose which app to stop
```

---

### `composer nimbus:status`
**Purpose:** Show status of all created and running apps.

**Displays:**
- List of all created apps with templates
- Running status (up/down)
- Container health indicators
- Image build status

**Example:**
```bash
composer nimbus:status
```

---

## App Management

### `composer nimbus:list`
**Purpose:** List all created apps with their templates and status.

**Shows:**
- App name
- Template used
- Installation status (created/installed)

**Example:**
```bash
composer nimbus:list
```

---

### `composer nimbus:delete [<app>]`
**Purpose:** Delete an app and its generated files.

**Deletes:**
- `.installer/apps/<app>/` (app instance)
- `<app>-compose.yml` (container config)
- Associated data volumes (if confirmed)

**Note:** Cannot delete apps that are currently running.

**Example:**
```bash
composer nimbus:delete old-app
```

---

## Feature Management

### `composer nimbus:add-eda [<app>]`
**Purpose:** Add Event-Driven Ansible (EDA) container to an existing app.

**Changes:**
1. Enables EDA in `app.nimbus.json`
2. Creates `rulebooks/` directory with demo files
3. Regenerates `<app>-compose.yml` with EDA service
4. Validates YAML syntax

**Example:**
```bash
composer nimbus:add-eda my-app
```

---

### `composer nimbus:remove-eda [<app>]`
**Purpose:** Remove EDA container from an existing app.

**Changes:**
1. Disables EDA in `app.nimbus.json`
2. Removes EDA container from compose config
3. Regenerates `<app>-compose.yml`

**Example:**
```bash
composer nimbus:remove-eda my-app
```

---

### `composer nimbus:add-keycloak [<app>]`
**Purpose:** Add Keycloak identity provider to an existing app.

**Changes:**
1. Enables Keycloak in `app.nimbus.json`
2. Generates Keycloak configuration
3. Regenerates compose config with Keycloak service

**Example:**
```bash
composer nimbus:add-keycloak my-app
```

---

### `composer nimbus:remove-keycloak [<app>]`
**Purpose:** Remove Keycloak from an existing app.

**Changes:**
1. Disables Keycloak in `app.nimbus.json`
2. Removes Keycloak container from compose config

**Example:**
```bash
composer nimbus:remove-keycloak my-app
```

---

### `composer nimbus:add-eda-keycloak [<app>]`
**Purpose:** Add both EDA and Keycloak to an existing app.

**Equivalent to:** `nimbus:add-eda <app>` then `nimbus:add-keycloak <app>`

**Example:**
```bash
composer nimbus:add-eda-keycloak my-app
```

---

## Development Workflow

### `composer nimbus:dev [<app>]`
**Purpose:** Set up dev mode with hot-reload and VS Code web editor.

**Creates:**
- `<app>-compose.dev.yml` overlay file
- Bind-mounts `.installer/apps/<app>/` as served code (not shared `app/`)
- Spins up code-server (VS Code in browser) sidecar

**Benefits:**
- Edits are live (no rebuild needed)
- Apps stay isolated (multiple dev apps don't interfere)
- Changes survive `nimbus:install`
- Code-server accessible via browser

**Launch dev app:**
```bash
bin/nimbus dev my-app
# Or manually:
podman-compose -f my-app-compose.yml -f my-app-compose.dev.yml up --build -d
```

**Example:**
```bash
composer nimbus:dev my-app
# code-server at: http://localhost:<port>  password: <generated>
```

---

### `composer nimbus:commit [<app>]`
**Purpose:** Copy dev-mode edits back to the shared template source.

**Flow:**
1. Reads from `.installer/apps/<app>/` (your dev edits)
2. Copies app-agnostic assets back to `.installer/_templates/<template>/`
3. **Excludes** `app.config.php` (per-app secrets stay private)
4. Makes changes available to future `nimbus:create` runs

**Safety:**
- All-or-nothing: if any file fails to commit, **nothing** is written
- Prevents per-app secrets from leaking into templates
- Prints which files were committed vs. skipped

**Example:**
```bash
composer nimbus:commit my-app
# Output:
# Committed X asset path(s):
#   ✓ routes/CustomRoutes.php
#   ✓ Controllers/PageController.php
#   ⊘ app.config.php (skipped: per-app resolved values)
```

---

## Template Management

### `composer nimbus:template-scaffold [<name>]`
**Purpose:** Generate a new vanilla template with MVC scaffolding.

**Creates:**
- `.installer/_templates/<name>/app.config.php`
- `.installer/_templates/<name>/app.nimbus.json`
- `.installer/_templates/<name>/Controllers/`
- `.installer/_templates/<name>/Views/`
- `.installer/_templates/<name>/Models/` (optional)
- `.installer/_templates/<name>/routes/CustomRoutes.php`
- `.installer/_templates/<name>/database/schema.sql` (optional)

**Naming:** Lowercase, hyphens only (e.g., `my-custom-app`)

**Example:**
```bash
composer nimbus:template-scaffold my-custom-template
```

---

### `composer nimbus:template-check [<name>]`
**Purpose:** Verify template's MVC scaffolding and mustache rendering (read-only).

**Checks:**
- MVC structure is present (Controllers, Views, Models, routes)
- Mustache views parse correctly
- Referenced partials exist
- Controllers provide expected variables to views
- Template scaffolding matches vanilla template structure

**Output:** Reports findings (errors/warnings), suggests fixes, **does NOT fix anything**

**Scope:** MVC verification only (linting is separate: `nimbus:lint-check`)

**Example:**
```bash
composer nimbus:template-check lkui
# Errors: 3
# Warnings: 1
# [ERROR] Views/dashboard.mustache: partial 'header' not found
```

---

### `composer nimbus:lint-check [<name>]`
**Purpose:** Lint templates for syntax, placeholders, and asset validity (read-only).

**Checks:**
- `app.nimbus.json` valid JSON
- `app.config.php` valid PHP
- Placeholder tokens (`{{APP_NAME}}`, etc.) only in allowed files
- Asset source paths exist
- No stray placeholders in Controllers/Models/Views/routes
- YAML syntax if generator templates present

**Output:** Reports findings, fixes NOTHING

**Scope:** Distinct from `nimbus:template-check` (which verifies MVC rendering)

**Example:**
```bash
composer nimbus:lint-check lkui
# Or check all:
composer nimbus:lint-check
```

---

## Template Aliasing

### `composer nimbus:alias-template`
**Purpose:** Create a short alias for a template name.

**Flow:**
1. Shows available templates
2. Prompts for alias name
3. Maps alias → template name in `.installer/apps.json`

**Benefit:** Use shorter names with `nimbus:create`

**Example:**
```bash
composer nimbus:alias-template
# Alias 'lk' → 'lkui' in config
# Then: composer nimbus:create my-app lk
```

---

### `composer nimbus:alias-remove`
**Purpose:** Delete a template alias.

**Example:**
```bash
composer nimbus:alias-remove lk
```

---

### `composer nimbus:alias-list`
**Purpose:** Show all configured template aliases.

**Example:**
```bash
composer nimbus:alias-list
# Output:
# lk → lkui
# mvc → bootstrap-mvc
```

---

## Vault (Credentials & Passwords)

### `composer nimbus:vault-init`
**Purpose:** Initialize vault for encrypted credential storage.

**Flow:**
1. Prompts for vault password
2. Creates ansible-vault config in a container
3. Stores in `.installer/vault/`
4. Subsequent app creation auto-backs up passwords

**Prerequisites:** Podman installed (vault runs in container)

**Example:**
```bash
composer nimbus:vault-init
# Enter password: ••••••••
```

---

### `composer nimbus:vault-backup`
**Purpose:** Manually back up an app's credentials to vault.

**Backs up:**
- Database passwords
- Keycloak admin passwords
- API keys (if present)

**Example:**
```bash
composer nimbus:vault-backup my-app
```

---

### `composer nimbus:vault-restore`
**Purpose:** Restore backed-up credentials for an app.

**Flow:**
1. Checks if credentials exist in vault for this app
2. Restores to `<app>-compose.yml` / `app.nimbus.json`
3. Regenerates compose config with restored secrets

**Use case:** Re-creating an app that had persistent DB volume; restores matching passwords so data isn't orphaned

**Example:**
```bash
composer nimbus:vault-restore my-app
```

---

### `composer nimbus:vault-list`
**Purpose:** List all apps with backed-up credentials in vault.

**Shows:**
- App name
- Backup date
- Which secret types are stored (database, keycloak, etc.)

**Example:**
```bash
composer nimbus:vault-list
```

---

### `composer nimbus:vault-view [<app>]`
**Purpose:** Decrypt and display vault credentials for an app (read-only).

**Output:**
- Database password
- Keycloak passwords
- Any other stored secrets (masked unless requested)

**Example:**
```bash
composer nimbus:vault-view my-app
```

---

## Architecture Summary

```
Template (source of truth)
  .installer/_templates/<template>/
        │
        ├─ app.config.php          ← app.nimbus.json + placeholders resolved
        ├─ app.nimbus.json         ← features, containers, database, assets map
        ├─ Controllers/
        ├─ Views/
        ├─ Models/
        ├─ routes/CustomRoutes.php
        └─ database/schema.sql

nimbus:create <app> <template>
        │
        ▼ (copy + resolve {{PLACEHOLDER}})

App Instance
  .installer/apps/<app>/           ← dev edits live here
        │
        │  (nimbus:dev: bind-mount, code-server)
        │  (nimbus:commit: copy back to template)
        │
        └─ nimbus:install
                │
                ▼ (copy → app/, generate compose)

Runtime
  app/                             ← shared by all running baked apps
  <app>-compose.yml               ← orchestration (podman/docker)
  data/                           ← persistent volumes
```

---

## Common Workflows

### Create & Deploy an App
```bash
composer nimbus:create my-app lkui
composer nimbus:install my-app
composer nimbus:up my-app
```

### Create with EDA + Keycloak
```bash
composer nimbus:create-eda-keycloak secure-app
composer nimbus:install secure-app
composer nimbus:up secure-app
```

### Add Feature to Existing App
```bash
composer nimbus:add-eda my-app
composer nimbus:install my-app     # Regenerate compose
composer nimbus:up my-app          # Restart with new service
```

### Development Workflow
```bash
composer nimbus:dev my-app                # Set up dev overlay
bin/nimbus dev my-app                     # Start with code-server
# Edit .installer/apps/my-app/ in browser or locally
composer nimbus:commit my-app             # Share edits → template
composer nimbus:create another-app lkui   # New app includes your edits
```

### Backup & Restore Credentials
```bash
composer nimbus:vault-init                # One-time setup
composer nimbus:vault-backup my-app       # After any feature change
# Later:
composer nimbus:vault-restore my-app      # Restore old passwords, no data loss
```

---

## Key Concepts

**Two-Layer App Model:**
- **Templates** (`.installer/_templates/`) are the source of truth
- **App instances** (`.installer/apps/<app>/`) are generated copies
- **Runtime** (`app/`, `<app>-compose.yml`) are also generated; editing directly is temporary

**Dev Mode Isolation:**
- Each dev app serves from its own instance dir (`.installer/apps/<app>/`)
- Multiple dev apps don't interfere with each other
- Edits survive reinstalls via `nimbus:commit`

**Placeholder Substitution:**
- `{{APP_NAME}}`, `{{DB_PASSWORD}}`, `{{APP_PORT}}` are replaced at create time
- Only `app.config.php` and `app.nimbus.json` may contain placeholders
- Controllers/Models/Views/routes read values at runtime via `$config`

**Checks are Read-Only:**
- `nimbus:template-check` verifies MVC scaffolding (reports, doesn't fix)
- `nimbus:lint-check` verifies syntax & placeholders (reports, doesn't fix)
- Humans fix the actual issues in template source files
