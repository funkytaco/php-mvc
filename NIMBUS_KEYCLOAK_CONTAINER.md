# Nimbus Keycloak Container Architecture

## Overview

The Nimbus framework provides automatic Keycloak SSO integration for each app through a sophisticated container orchestration system. Each app gets its own isolated Keycloak realm with proper client configuration, user management, and authentication flows.

## Container Architecture

### 1. Keycloak Server Container (`myapp-keycloak`)
- **Image**: `quay.io/keycloak/keycloak:latest`
- **Purpose**: Main Keycloak authentication server
- **Port**: 8080 (mapped to host)
- **Database**: Connected to dedicated PostgreSQL instance

### 2. Keycloak Database Container (`myapp-keycloak-db`)
- **Image**: `postgres:14`
- **Purpose**: Dedicated PostgreSQL database for Keycloak data
- **Database**: `keycloak_db`
- **User**: `keycloak` with auto-generated password

### 3. Keycloak Setup Container (`myapp-keycloak-setup`)
- **Image**: `alpine:latest`
- **Purpose**: One-time realm configuration via REST API
- **Dependencies**: curl, jq
- **Execution**: Runs once after Keycloak is healthy, then exits

## Automatic Configuration Process

### Phase 1: Container Startup
1. **Database Container** starts first with health checks
2. **Keycloak Server** starts once database is healthy
3. **Setup Container** waits for Keycloak to be ready

### Phase 2: Realm Configuration
The setup container executes `keycloak-init.sh` which:

1. **Waits for Keycloak** using `/admin` endpoint health check
2. **Authenticates** with Keycloak admin credentials
3. **Creates Realm** with app-specific name (`{app-name}-realm`)
4. **Configures Client** with proper redirect URIs and secrets
5. **Creates Test User** for immediate testing
6. **Sets up Roles** for user management

### Environment Variables

The setup container receives these environment variables:

```bash
KEYCLOAK_URL=http://myapp-keycloak:8080
KEYCLOAK_ADMIN_USER=admin
KEYCLOAK_ADMIN_PASSWORD={auto-generated}
KEYCLOAK_REALM={app-name}-realm
KEYCLOAK_CLIENT_ID={app-name}-client
KEYCLOAK_CLIENT_SECRET={auto-generated}
APP_NAME={app-name}
APP_PORT={app-port}
```

## Configuration Details

### Realm Settings
- **Name**: `{app-name}-realm`
- **Registration**: Enabled
- **Email Login**: Enabled
- **Password Reset**: Enabled
- **Brute Force Protection**: Enabled
- **Session Management**: Configured for web apps

### Client Settings
- **Client ID**: `{app-name}-client`
- **Client Secret**: Auto-generated 32-character secret
- **Protocol**: OpenID Connect
- **Access Type**: Confidential
- **Standard Flow**: Enabled
- **Direct Access**: Enabled
- **Service Accounts**: Enabled

### Redirect URIs
- `http://localhost:{app-port}/*`
- `http://{app-name}-app:8080/*`

### Default Test User
- **Username**: `testuser`
- **Email**: `testuser@{app-name}.local`
- **Password**: `testpass123`
- **Status**: Enabled, email verified

## Integration with Nimbus Apps

### App Configuration
Each app's `app.nimbus.json` contains Keycloak configuration:

```json
{
  "features": {
    "keycloak": true
  },
  "keycloak": {
    "realm": "{app-name}-realm",
    "client_id": "{app-name}-client", 
    "client_secret": "{auto-generated}",
    "auth_url": "http://{app-name}-keycloak:8080",
    "redirect_uri": "http://localhost:{app-port}/auth/callback"
  }
}
```

### Container Dependencies
- App container depends on Keycloak being healthy
- Keycloak depends on its database being healthy
- Setup container runs after Keycloak is ready

## Compose File Generation

The `AppManager.php` generates the following service definitions:

```yaml
services:
  myapp-keycloak-db:
    image: postgres:14
    environment:
      POSTGRES_DB: keycloak_db
      POSTGRES_USER: keycloak
      POSTGRES_PASSWORD: {auto-generated}
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U keycloak -d keycloak_db"]

  myapp-keycloak:
    image: quay.io/keycloak/keycloak:latest
    command: ["start-dev"]
    environment:
      KC_DB: postgres
      KC_DB_URL: jdbc:postgresql://myapp-keycloak-db:5432/keycloak_db
      KC_DB_USERNAME: keycloak
      KC_DB_PASSWORD: {auto-generated}
      KEYCLOAK_ADMIN: admin
      KEYCLOAK_ADMIN_PASSWORD: {auto-generated}
    depends_on:
      myapp-keycloak-db:
        condition: service_healthy

  myapp-keycloak-setup:
    image: alpine:latest
    command: ["sh", "-c", "apk add --no-cache curl jq && sh /keycloak-init.sh"]
    environment:
      KEYCLOAK_URL: http://myapp-keycloak:8080
      KEYCLOAK_ADMIN_USER: admin
      KEYCLOAK_ADMIN_PASSWORD: {auto-generated}
      KEYCLOAK_REALM: myapp-realm
      KEYCLOAK_CLIENT_ID: myapp-client
      KEYCLOAK_CLIENT_SECRET: {auto-generated}
      APP_NAME: myapp
      APP_PORT: {app-port}
    volumes:
      - ./.installer/apps/myapp/keycloak-init.sh:/keycloak-init.sh:Z
    depends_on:
      myapp-keycloak:
        condition: service_healthy
    restart: no
```

## Security Features

### Password Generation
- **Admin Password**: 32-character random string
- **Database Password**: 32-character random string  
- **Client Secret**: 32-character random string with special characters

### Network Isolation
- Each app runs in its own Docker network
- Keycloak is only accessible within the app network and host port 8080
- Database is only accessible within the app network

### Brute Force Protection
- Account lockout after failed attempts
- Progressive delays between attempts
- Configurable thresholds and timeouts

## Troubleshooting

### Common Issues

1. **"Keycloak is already enabled"**
   - Use force flag: `composer nimbus:add-keycloak myapp force`

2. **Password Authentication Failed**
   - Remove database volume: `rm -rf data/myapp-keycloak`
   - Restart app: `composer nimbus:down myapp && composer nimbus:up myapp`

3. **Setup Container Fails**
   - Check Keycloak logs: `podman logs myapp-keycloak`
   - Verify network connectivity between containers

### Accessing Keycloak Admin

1. **URL**: `http://localhost:8080`
2. **Username**: `admin`
3. **Password**: Retrieved from container environment
   ```bash
   podman inspect myapp-keycloak --format '{{range .Config.Env}}{{println .}}{{end}}' | grep KEYCLOAK_ADMIN_PASSWORD | cut -d'=' -f2
   ```

### Testing Authentication

1. Access the realm: `http://localhost:8080/realms/{app-name}-realm`
2. Test login with default user:
   - Username: `testuser`
   - Password: `testpass123`

## Implementation Files

### Key Files Modified
- `src/Nimbus/App/AppManager.php` - Container orchestration logic
- `.installer/_templates/nimbus-demo/keycloak-init.sh` - Realm setup script
- `ApplicationTasks.php` - CLI commands for Keycloak management

### Template Processing
- Environment variables are preserved in `keycloak-init.sh`
- Template placeholders are replaced in configuration files only
- Runtime environment variables take precedence

## Benefits

1. **Isolation**: Each app gets its own authentication realm
2. **Automation**: Zero-configuration setup for new apps
3. **Security**: Industry-standard authentication with proper secrets
4. **Scalability**: Each app can have different authentication requirements
5. **Development**: Immediate testing with pre-configured users

## Future Enhancements

1. **LDAP Integration**: Support for external user directories
2. **Social Login**: Integration with OAuth providers
3. **Custom Themes**: App-specific login page branding
4. **Role Mapping**: Automatic role assignment from app configuration
5. **SSL/TLS**: Production-ready HTTPS configuration

---

*This architecture ensures that every Nimbus app has robust, isolated authentication with minimal configuration overhead.*