# Nimbus Framework

A containerized PHP MVC application generator with Event-Driven Automation (EDA) support. Nimbus replaces manual development workflows with automated app creation, container orchestration, and optional Ansible EDA integration.

## 🚀 Quick Start (bin/nimbus — recommended)

Host needs only **git + podman + podman-compose**. PHP, Composer and Ansible run
inside the `nimbus-tools` container (RHEL UBI9), built automatically on first use.

```bash
# One-shot: create + install + start
bin/nimbus init my-app

# Live-edit dev mode: bind-mounts app/ + src/ into the container and starts
# code-server (VS Code in your browser). Edit locally OR in the browser —
# same files, changes are live, no rebuild.
bin/nimbus dev my-app

# Everyday lifecycle (honest exit codes)
bin/nimbus up my-app [--dev]     # start
bin/nimbus down my-app           # stop
bin/nimbus status                # all apps
bin/nimbus logs my-app           # follow logs
bin/nimbus rebuild my-app        # force image rebuild

# Features on/off per app
bin/nimbus create my-app --no-db          # app without postgres
bin/nimbus add-eda my-app / remove-eda my-app
bin/nimbus add-keycloak my-app / remove-keycloak my-app

# Ansible, inside the tools container
bin/nimbus playbook path/to/playbook.yml

bin/nimbus help                  # everything else
```

`composer nimbus:*` (below) still works if you have PHP+Composer installed locally —
it is the same engine. Note the legacy `composer nimbus:up` path does not
propagate container failures as exit codes; `bin/nimbus` does.

## 🚀 Quick Start (composer, legacy path)

```bash
# Create a new app with EDA
composer nimbus:create-with-eda my-app

# Install and start the app
composer nimbus:install my-app
composer nimbus:up my-app

# Your app is running at http://localhost:8XXX (auto-assigned port)
```

Build Status - Active Development

## Installation

```bash
# Install dependencies
composer install

# Install legacy MVC template (optional)
composer install-mvc

# Install LKUI template (for SSL certificate management)
composer install-lkui
```

## Nimbus Commands

Every `composer nimbus:*` script below also works as `bin/nimbus <command>` (drop
the `nimbus:` prefix) — see the Quick Start above. The examples here use the
composer form since it's the lowest-common-denominator (no tools image needed).

### App Lifecycle Management
```bash
# Create basic app
composer nimbus:create my-app

# Create an app with no database container
composer nimbus:create my-app -- --no-db

# Create app with EDA enabled
composer nimbus:create-with-eda my-app

# Create app with both EDA and Keycloak enabled
composer nimbus:create-eda-keycloak my-app

# Add/remove EDA on an existing app
composer nimbus:add-eda my-app
composer nimbus:remove-eda my-app

# Add/remove Keycloak on an existing app
composer nimbus:add-keycloak my-app
composer nimbus:remove-keycloak my-app

# Install app (copy files and generate containers)
composer nimbus:install my-app

# List all apps with status
composer nimbus:list

# Delete an app
composer nimbus:delete my-app
```

### Container Management
```bash
# Start apps (interactive mode)
composer nimbus:up

# Start specific app
composer nimbus:up my-app

# Stop apps (interactive mode)
composer nimbus:down

# Stop specific app with cleanup options
composer nimbus:down my-app

# Check status
composer nimbus:status
```

### Dev Mode & Live Editing
```bash
# Generate <app>-compose.dev.yml: bind-mounts app/ + src/ into the running
# container (edits are live, no rebuild) and adds a code-server sidecar.
composer nimbus:dev my-app

# Apply it (bin/nimbus does this in one step via `bin/nimbus dev my-app`):
podman-compose -f my-app-compose.yml -f my-app-compose.dev.yml up --build -d
```

### Vault, Templates & Aliases
```bash
composer nimbus:vault-init | vault-backup <app> | vault-restore <app> | vault-list | vault-view <app>
composer nimbus:template-scaffold <name> | template-check [name]
composer nimbus:alias-template | alias-remove | alias-list
```

## Nimbus Architecture

### What You Get
Each Nimbus app generates a containerized stack. Every feature below is
independently on/off per app via `--no-db`, `add-eda`/`remove-eda`, and
`add-keycloak`/`remove-keycloak`:

**Standard App (2 containers):**
- **app-name-app**: PHP 8.3 + Apache application server
- **app-name-postgres**: PostgreSQL 14 database with health checks (omit with `--no-db`)

**EDA-Enabled App (+1 container):**
- **app-name-eda**: Ansible EDA server with webhook listener on a per-app port

**Keycloak-Enabled App (+2 containers):**
- **app-name-keycloak**: Keycloak SSO server
- **app-name-keycloak-db**: PostgreSQL 14 database for Keycloak

**Dev Mode (`bin/nimbus dev` / `nimbus:dev`, +1 container):**
- **app-name-code-server**: VS Code in the browser, editing the same host
  files bind-mounted into `app-name-app` — so laptop edits and browser edits
  are the same tree and go live without a rebuild

### Features
- ✅ **Zero Configuration**: Apps work out-of-the-box
- ✅ **Automatic Port Assignment**: No port conflicts between apps
- ✅ **Health Monitoring**: Container status and health checks
- ✅ **Live Development**: `bin/nimbus dev` bind-mounts code for immediate changes, no rebuild
- ✅ **Database Integration**: Schema loading with sample data, or opt out with `--no-db`
- ✅ **EDA Automation**: Event-driven Ansible playbooks, addable/removable per app
- ✅ **Keycloak SSO**: Addable/removable per app
- ✅ **Template System**: Extensible app templates
- ✅ **No local PHP/Composer/Ansible required**: `bin/nimbus` runs the engine and
  ansible-playbook inside a RHEL UBI9 tools container; host only needs podman

### App Structure
```
.installer/apps/my-app/
├── app.nimbus.json       # App configuration
├── Controllers/          # MVC Controllers  
├── Models/              # Data models
├── Views/               # Mustache templates (.mustache)
├── routes/              # Custom API routes
├── database/            # Schema and migrations
├── rulebooks/           # EDA rulebooks (if enabled)
├── playbooks/           # Ansible playbooks (if enabled)
└── logs/                # Application logs
```

## Legacy Usage
To run a development server for legacy templates:
```bash
composer serve
```

## Legacy Development (Pre-Nimbus) ##
### 1) Create your own View ###
- add a template view in **app/Views/** by default, this is a [Mustache]() template. (It is possible to change the rendering engine).
    - add a controller in src/Controllers/ which uses the view.
    - add a route in **app/CustomRoutes.php** that uses the controller.

    For further templating information, [mustache.php] has a good primer on how to pass in your data. If you don't like Mustache, then [No Framework Templating], explains how to replace the "Renderer".

### 2) Create your own Controller ###
- add a controller in **app/Controllers/** [(Example Controller)](https://gist.github.com/funkytaco/87fd34b5ef863ebbc120)
    - For the controller to be used, it must be used by a route in  **app/Routes.php**
    - Reference a view to load in the controller function, if applicable.
    - `$this->data` is how your model data will be accessed by the controller, and shared with the view.


### 3) Create your own Model ###
 - You can put your model in **app/Traits/** or **app/Models** for models which will not be re-used.
    - The **$conn PDO** connection is be passed into the controller.
 - The PDOWrapper class `uses` the namespace of your Trait file, e.g.,
`use \Main\Traits\MyQueryData`. Since this class is now loaded in the class all of its functions are available to the parent class.
- e.g. `getUsers()` in our traits file is accessible as `$conn->getUsers()`.

    ####To use a MySQL/Postgres/Other Database:####
- In `src/Traits/QueryData.php`
    - add your query functions in  (I will explain how to use these functions in your view)
    - uncomment `$conn = $injector->make('\Main\PDO');` . It must stay below the `$injector->define` for PDO.
- In your controller:
    -  **use \Main\PDO;** and comment/remove **use \Main\Mock\PDO;**
- In `Config.php`:
    - `$dbtype` should be set to *mysql* or *postgres*
    - You can add other types supported by PDO, as this is just a PDO instantiation.
- Stub out your database queries:
    - create a Foo_Module.php in **src/Modules** and include it like the example Date_Module class.




***
*Additional Info*

##Tree##
public assets directory:
Any CSS/JS/media assets MUST go in public/assets

    public
    ├── assets
    └── index.php


Source directory:
This is the core of our MVC framework.

    src
    ├── Bootstrap.php
    ├── Dependencies.php
    ├── MimeTypes.php
    ├── Mock
    │   ├── PDO.php
    │   └── Traits
    │       └── QueryData.php
    ├── Modules
    │   └── Date_Module.php
    ├── Renderer
    │   ├── MustacheRenderer.php
    │   └── Renderer.php
    ├── Routes.php
    └── Static
        └── Error.php
Test directory:

    test
    ├── bootstrap.php
    └── src
        ├── Controllers
        │   └── IndexController_Test.php
        └── Mock
            └── PDO_Test.php


***

## Components

Components
  - [Bootstrap] for front-end development (in bootstrap branch)
  - [Composer] for dependency management and project setup (i.e. post installation script events)
  - [whoops] for error handling
  - [Klein.php] for routing
  - [mustache.php] for templating
  - [Auryn] for IoC dependency injection

Change out these components for others (i.e. replace [mustache.php] with [handlebars.php]) by reading through [No Framework] for PHP.

## Contributing

1. Fork it!
2. Create your feature branch: `git checkout -b my-new-feature`
3. Commit your changes: `git commit -am 'Add some feature'`
4. Push to the branch: `git push origin my-new-feature`
5. Submit a pull request :D

## History
  - v 0.7.5 stripped out Bootstrap from master branch and moved it to a bootstrap branch.
  - v 0.7.4 PHPUnit Travis-CI tests. Callout CSS. PDO Config file added. PDO structure and file name changes. Code cleanup for Routes.php
  - v 0.7.3 Updated license. PDO wrapper changes.
  - v 0.7.2 Initial commit



[Bootstrap]:http://www.getbootstrap.com/
[Composer]:https://getcomposer.org/
[whoops]:https://github.com/filp/whoops/
[Klein.php]:https://github.com/chriso/klein.php/
[mustache.php]:https://github.com/bobthecow/mustache.php
[handlebars.php]:https://github.com/XaminProject/handlebars.php/
[Auryn]:https://github.com/rdlowrey/Auryn/
[No Framework]:https://github.com/PatrickLouys/no-framework-tutorial/
[No Framework Templating]: https://github.com/PatrickLouys/no-framework-tutorial/blob/master/09-templating.md
[@PatrickLouys]:https://github.com/PatrickLuoys/
