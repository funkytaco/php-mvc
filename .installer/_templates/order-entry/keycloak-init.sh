#!/bin/bash
# Keycloak auto-configuration script
# This runs inside the Keycloak container on startup

KEYCLOAK_URL="${KEYCLOAK_URL:-http://localhost:8080}"
ADMIN_USER="${KEYCLOAK_ADMIN_USER:-admin}"
ADMIN_PASSWORD="${KEYCLOAK_ADMIN_PASSWORD}"
REALM_NAME="${KEYCLOAK_REALM}"
CLIENT_ID="${KEYCLOAK_CLIENT_ID}"
CLIENT_SECRET="${KEYCLOAK_CLIENT_SECRET}"
APP_NAME="${APP_NAME}"
APP_PORT="${APP_PORT}"

# Debug: Show environment variables
echo "=== Keycloak Configuration ==="
echo "KEYCLOAK_URL: ${KEYCLOAK_URL}"
echo "ADMIN_USER: ${ADMIN_USER}"
echo "REALM_NAME: ${REALM_NAME}"
echo "CLIENT_ID: ${CLIENT_ID}"
echo "APP_NAME: ${APP_NAME}"
echo "APP_PORT: ${APP_PORT}"
echo "============================="

# Wait for Keycloak to be ready
echo "Waiting for Keycloak to start..."
until curl -sf "${KEYCLOAK_URL}/admin" > /dev/null; do
    sleep 5
done

echo "Keycloak is ready. Starting auto-configuration..."

# Get admin token
get_admin_token() {
    curl -s -X POST "${KEYCLOAK_URL}/realms/master/protocol/openid-connect/token" \
        -H "Content-Type: application/x-www-form-urlencoded" \
        -d "username=${ADMIN_USER}" \
        -d "password=${ADMIN_PASSWORD}" \
        -d "grant_type=password" \
        -d "client_id=admin-cli" | jq -r '.access_token'
}

TOKEN=$(get_admin_token)

# Check if realm already exists
REALM_EXISTS=$(curl -s -o /dev/null -w "%{http_code}" \
    -H "Authorization: Bearer ${TOKEN}" \
    "${KEYCLOAK_URL}/admin/realms/${REALM_NAME}")

if [ "$REALM_EXISTS" -eq 404 ]; then
    echo "Creating realm: ${REALM_NAME}"
    
    # Create realm with pre-configured settings
    curl -s -X POST "${KEYCLOAK_URL}/admin/realms" \
        -H "Authorization: Bearer ${TOKEN}" \
        -H "Content-Type: application/json" \
        -d @- <<EOF
{
    "realm": "${REALM_NAME}",
    "enabled": true,
    "registrationAllowed": true,
    "loginWithEmailAllowed": true,
    "duplicateEmailsAllowed": false,
    "resetPasswordAllowed": true,
    "editUsernameAllowed": false,
    "bruteForceProtected": true,
    "permanentLockout": false,
    "maxFailureWaitSeconds": 900,
    "minimumQuickLoginWaitSeconds": 60,
    "waitIncrementSeconds": 60,
    "quickLoginCheckMilliSeconds": 1000,
    "maxDeltaTimeSeconds": 43200,
    "failureFactor": 30,
    "defaultSignatureAlgorithm": "RS256",
    "offlineSessionMaxLifespanEnabled": false,
    "offlineSessionMaxLifespan": 5184000,
    "clientSessionIdleTimeout": 0,
    "clientSessionMaxLifespan": 0,
    "clientOfflineSessionIdleTimeout": 0,
    "clientOfflineSessionMaxLifespan": 0,
    "accessTokenLifespan": 300,
    "accessCodeLifespan": 60,
    "accessCodeLifespanUserAction": 300,
    "accessCodeLifespanLogin": 1800,
    "sslRequired": "external",
    "registrationEmailAsUsername": false,
    "rememberMe": true,
    "verifyEmail": false,
    "loginTheme": "keycloak",
    "accountTheme": "keycloak",
    "adminTheme": "keycloak",
    "emailTheme": "keycloak"
}
EOF

    # Create client for the app
    echo "Creating client: ${CLIENT_ID}"
    curl -s -X POST "${KEYCLOAK_URL}/admin/realms/${REALM_NAME}/clients" \
        -H "Authorization: Bearer ${TOKEN}" \
        -H "Content-Type: application/json" \
        -d @- <<EOF
{
    "clientId": "${CLIENT_ID}",
    "name": "${APP_NAME} Application",
    "description": "Auto-configured client for ${APP_NAME}",
    "rootUrl": "http://localhost:${APP_PORT}",
    "adminUrl": "http://localhost:${APP_PORT}",
    "baseUrl": "/",
    "enabled": true,
    "clientAuthenticatorType": "client-secret",
    "secret": "${CLIENT_SECRET}",
    "redirectUris": [
        "http://localhost:${APP_PORT}/*",
        "http://${APP_NAME}-app:8080/*"
    ],
    "webOrigins": [
        "http://localhost:${APP_PORT}",
        "http://${APP_NAME}-app:8080"
    ],
    "attributes": {
        "post.logout.redirect.uris": "http://localhost:${APP_PORT}/*##http://${APP_NAME}-app:8080/*"
    },
    "protocol": "openid-connect",
    "publicClient": false,
    "standardFlowEnabled": true,
    "implicitFlowEnabled": false,
    "directAccessGrantsEnabled": true,
    "serviceAccountsEnabled": true,
    "authorizationServicesEnabled": false,
    "bearerOnly": false,
    "consentRequired": false,
    "fullScopeAllowed": true,
    "nodeReRegistrationTimeout": -1,
    "defaultClientScopes": [
        "web-origins",
        "profile",
        "roles",
        "email"
    ],
    "optionalClientScopes": [
        "address",
        "phone",
        "offline_access",
        "microprofile-jwt"
    ]
}
EOF

    # Create a default user for testing
    echo "Creating default test user"
    curl -s -X POST "${KEYCLOAK_URL}/admin/realms/${REALM_NAME}/users" \
        -H "Authorization: Bearer ${TOKEN}" \
        -H "Content-Type: application/json" \
        -d @- <<EOF
{
    "username": "testuser",
    "email": "testuser@${APP_NAME}.local",
    "emailVerified": true,
    "enabled": true,
    "firstName": "Test",
    "lastName": "User",
    "credentials": [{
        "type": "password",
        "value": "testpass9898",
        "temporary": false
    }]
}
EOF

    # Create admin role
    echo "Creating admin role"
    curl -s -X POST "${KEYCLOAK_URL}/admin/realms/${REALM_NAME}/roles" \
        -H "Authorization: Bearer ${TOKEN}" \
        -H "Content-Type: application/json" \
        -d '{"name": "admin", "description": "Administrator role"}'

    echo "Keycloak auto-configuration completed successfully!"
else
    echo "Realm ${REALM_NAME} already exists. Skipping configuration."
fi

# Create a marker file to indicate configuration is complete
touch /opt/keycloak/data/configured.marker