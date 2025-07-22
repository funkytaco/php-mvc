# Nimbus App Manager Roadmap

## Overview

This roadmap outlines enhancements to evolve Nimbus into a comprehensive container-based development environment with advanced CLI tooling, while maintaining its unique strengths in Event-Driven Ansible (EDA) and Keycloak SSO integration.

## Current State

### Existing Nimbus Features
- **App Management**: Template-based creation, multi-app support
- **Container Orchestration**: Podman-compose integration
- **Advanced Features**: EDA integration, Keycloak SSO
- **CLI Commands**: create, install, up/down, status, delete, add-eda, add-keycloak

### Enhancement Opportunities
Modern development environments benefit from:
- Direct framework CLI access (drush, artisan, console, etc.)
- Container command execution shortcuts
- Built-in development tools
- Database management utilities
- SSH key forwarding
- Real-time log viewing

## Enhancement Roadmap

### Phase 1: Core Container Utilities (Priority: High)

#### 1.1 Execute Command (`nimbus:exec`)
**Command**: `composer nimbus:exec <app> [--container=<name>] -- <command>`

**Features**:
- Execute arbitrary commands in app containers
- Default to main app container
- Support for selecting specific containers (app, db, eda, keycloak)

**Implementation**:
```php
public static function nimbusExec(Event $event) {
    // Parse app name and command
    // Use podman exec to run command
    // Stream output to console
}
```

#### 1.2 Log Viewing (`nimbus:logs`)
**Command**: `composer nimbus:logs <app> [--follow] [--tail=<lines>] [--container=<name>]`

**Features**:
- View container logs
- Real-time log following with --follow
- Tail specific number of lines
- Filter by container

#### 1.3 SSH Access (`nimbus:ssh`)
**Command**: `composer nimbus:ssh <app> [--container=<name>]`

**Features**:
- Interactive shell access to containers
- Default to app container
- Support for all service containers

### Phase 2: Framework-Specific Integrations (Priority: Medium)

#### 2.1 Framework Detection
- Auto-detect framework based on files (composer.json, artisan, etc.)
- Store framework type in app.nimbus.json

#### 2.2 Framework CLI Shortcuts
Based on detected framework, provide shortcuts:

**Laravel**:
- `composer nimbus:artisan <app> -- <command>`
- Maps to: `nimbus:exec <app> -- php artisan <command>`

**Symfony**:
- `composer nimbus:console <app> -- <command>`
- Maps to: `nimbus:exec <app> -- php bin/console <command>`

**WordPress**:
- `composer nimbus:wp <app> -- <command>`
- Maps to: `nimbus:exec <app> -- wp <command>`

**Drupal/Backdrop**:
- `composer nimbus:drush <app> -- <command>`
- Maps to: `nimbus:exec <app> -- drush <command>`

**Magento 2**:
- `composer nimbus:magento <app> -- <command>`
- Maps to: `nimbus:exec <app> -- bin/magento <command>`

**Craft CMS**:
- `composer nimbus:craft <app> -- <command>`
- Maps to: `nimbus:exec <app> -- ./craft <command>`

**CakePHP**:
- `composer nimbus:cake <app> -- <command>`
- Maps to: `nimbus:exec <app> -- bin/cake <command>`

#### 2.3 Node.js Tools
- `composer nimbus:npm <app> -- <command>`
- `composer nimbus:yarn <app> -- <command>`
- `composer nimbus:node <app> -- <script>`

### Phase 3: Database Management (Priority: Medium)

#### 3.1 Database Import/Export
**Commands**:
- `composer nimbus:db-import <app> <file> [--drop-tables]`
- `composer nimbus:db-export <app> [--gzip] [--no-data]`

**Features**:
- Support SQL and compressed files
- Option to drop existing tables
- Export with various options

#### 3.2 Database Snapshot
**Commands**:
- `composer nimbus:snapshot <app> [--name=<name>]`
- `composer nimbus:snapshot-restore <app> <snapshot>`
- `composer nimbus:snapshot-list <app>`

### Phase 4: Developer Experience (Priority: Low-Medium)

#### 4.1 SSH Key Management
**Command**: `composer nimbus:auth ssh <app>`

**Features**:
- Forward host SSH keys to containers
- Support for GitHub/GitLab authentication

#### 4.2 Environment Management
**Commands**:
- `composer nimbus:env <app> [--show]`
- `composer nimbus:env-set <app> <key> <value>`

#### 4.3 Service Management
**Commands**:
- `composer nimbus:restart <app> [--container=<name>]`
- `composer nimbus:rebuild <app> [--no-cache]`

#### 4.4 Composer Integration
**Command**: `composer nimbus:composer <app> -- <command>`

**Features**:
- Run composer commands without local installation
- Automatic cache mounting for performance

### Phase 5: Advanced Features (Priority: Low)

#### 5.1 Multi-Environment Support
- Development, staging, production configs
- Environment-specific compose files

#### 5.2 Plugin System
- Allow custom commands via plugins
- Hook system for lifecycle events

#### 5.3 Performance Monitoring
- Resource usage tracking
- Performance profiling integration

#### 5.4 Pre/Post Hooks
- `pre-start`: Run commands before container start
- `post-start`: Run commands after container start
- `pre-stop`: Run commands before container stop

## Implementation Guidelines

### Code Structure
1. Extend `ApplicationTasks.php` with new commands
2. Add utility methods to `AppManager.php`
3. Maintain backward compatibility

### Command Naming Convention
- Use `nimbus:` prefix for all commands
- Use descriptive verb-noun format
- Support both long and short options

### Error Handling
- Validate app existence before operations
- Provide clear error messages
- Handle podman/podman-compose errors gracefully

### Documentation
- Update README with new commands
- Add examples for each command
- Create comprehensive user guide

## Timeline

- **Q1 2024**: Phase 1 (Core Utilities)
- **Q2 2024**: Phase 2 (Framework Integrations)
- **Q3 2024**: Phase 3 (Database Management)
- **Q4 2024**: Phase 4-5 (Developer Experience & Advanced Features)

## Success Metrics

1. **Feature Completeness**: Comprehensive CLI tooling for all supported frameworks
2. **Developer Adoption**: Positive feedback from development teams
3. **Performance**: Commands execute within 2 seconds
4. **Reliability**: 99% success rate for standard operations

## Unique Nimbus Advantages to Maintain

1. **EDA Integration**: Continue as a core differentiator
2. **Keycloak SSO**: Maintain out-of-the-box SSO support
3. **Podman Native**: Leverage podman-specific features
4. **Template System**: Expand with framework-specific templates

## Future Template Ideas

1. **nimbus-laravel**: Pre-configured Laravel with Vite, Tailwind
2. **nimbus-symfony**: Symfony with API Platform
3. **nimbus-wordpress**: WordPress with popular plugins
4. **nimbus-drupal**: Drupal with common modules
5. **nimbus-magento**: Magento 2 with sample data
6. **nimbus-nextjs**: Next.js with TypeScript
7. **nimbus-django**: Django with REST framework

## Container Image Optimizations

1. **Multi-stage builds**: Reduce image sizes
2. **Shared base images**: Common PHP/Node versions
3. **Layer caching**: Optimize build times
4. **Security scanning**: Automated vulnerability checks

## Conclusion

By implementing these enhancements, Nimbus will provide a comprehensive, framework-agnostic development environment that simplifies containerized development while offering unique features for modern application architectures with EDA and SSO capabilities.