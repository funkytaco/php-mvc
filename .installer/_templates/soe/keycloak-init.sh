#!/bin/bash
# Keycloak auto-configuration for the Host Build Order & Tracking Gateway.
#
# Runs once in a throwaway sidecar (<app>-keycloak-setup) after Keycloak is
# healthy. Creates the realm, the confidential client, the FOUR persona realm
# roles this platform gates its surfaces on, and one demo user per persona so
# the app is usable the moment `nimbus:add-keycloak` finishes.
#
# NOTE: this file intentionally contains no Nimbus placeholder tokens. Nimbus
# skips it during substitution and it reads everything from the environment at
# runtime, so every value below is a shell variable.

KEYCLOAK_URL="${KEYCLOAK_URL:-http://localhost:8080}"
ADMIN_USER="${KEYCLOAK_ADMIN_USER:-admin}"
ADMIN_PASSWORD="${KEYCLOAK_ADMIN_PASSWORD}"
REALM_NAME="${KEYCLOAK_REALM}"
CLIENT_ID="${KEYCLOAK_CLIENT_ID}"
CLIENT_SECRET="${KEYCLOAK_CLIENT_SECRET}"
APP_NAME="${APP_NAME}"
APP_PORT="${APP_PORT}"

# Shared password for the four demo persona users. These exist so the four
# surfaces are demonstrable immediately; they are not production accounts.
DEMO_PASSWORD="${SOE_DEMO_PASSWORD:-hostbuild9898}"

echo "=== Host Build Gateway · Keycloak configuration ==="
echo "KEYCLOAK_URL: ${KEYCLOAK_URL}"
echo "REALM_NAME:   ${REALM_NAME}"
echo "CLIENT_ID:    ${CLIENT_ID}"
echo "APP_NAME:     ${APP_NAME}"
echo "APP_PORT:     ${APP_PORT}"
echo "==================================================="

echo "Waiting for Keycloak to start..."
until curl -sf "${KEYCLOAK_URL}/admin" > /dev/null; do
    sleep 5
done
echo "Keycloak is ready. Starting auto-configuration..."

get_admin_token() {
    curl -s -X POST "${KEYCLOAK_URL}/realms/master/protocol/openid-connect/token" \
        -H "Content-Type: application/x-www-form-urlencoded" \
        -d "username=${ADMIN_USER}" \
        -d "password=${ADMIN_PASSWORD}" \
        -d "grant_type=password" \
        -d "client_id=admin-cli" | jq -r '.access_token'
}

TOKEN=$(get_admin_token)

REALM_EXISTS=$(curl -s -o /dev/null -w "%{http_code}" \
    -H "Authorization: Bearer ${TOKEN}" \
    "${KEYCLOAK_URL}/admin/realms/${REALM_NAME}")

if [ "$REALM_EXISTS" -ne 404 ]; then
    echo "Realm ${REALM_NAME} already exists. Skipping configuration."
    # Best-effort completion marker. This script runs in a throwaway alpine
# sidecar, not inside the Keycloak container, so the data directory usually
# does not exist here — never let that fail the run.
[ -d /opt/keycloak/data ] && touch /opt/keycloak/data/configured.marker
exit 0
    exit 0
fi

echo "Creating realm: ${REALM_NAME}"
curl -s -X POST "${KEYCLOAK_URL}/admin/realms" \
    -H "Authorization: Bearer ${TOKEN}" \
    -H "Content-Type: application/json" \
    -d @- <<EOF
{
    "realm": "${REALM_NAME}",
    "enabled": true,
    "registrationAllowed": false,
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
    "accessTokenLifespan": 300,
    "accessCodeLifespan": 60,
    "accessCodeLifespanUserAction": 300,
    "accessCodeLifespanLogin": 1800,
    "sslRequired": "external",
    "rememberMe": true,
    "verifyEmail": false,
    "loginTheme": "keycloak",
    "accountTheme": "keycloak",
    "adminTheme": "keycloak",
    "emailTheme": "keycloak"
}
EOF

echo "Creating client: ${CLIENT_ID}"
curl -s -X POST "${KEYCLOAK_URL}/admin/realms/${REALM_NAME}/clients" \
    -H "Authorization: Bearer ${TOKEN}" \
    -H "Content-Type: application/json" \
    -d @- <<EOF
{
    "clientId": "${CLIENT_ID}",
    "name": "${APP_NAME} Host Build Gateway",
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
    "defaultClientScopes": ["web-origins", "profile", "roles", "email"],
    "optionalClientScopes": ["address", "phone", "offline_access", "microprofile-jwt"]
}
EOF

# --------------------------------------------------------------------------
# Persona realm roles. App\Domain\Persona gates each surface on exactly these
# four names — changing a name here silently locks a surface, so keep them in
# step with Persona::APP_OWNER / REQUESTER / CUSTOMER / TEAM_MEMBER.
# --------------------------------------------------------------------------

create_role() {
    local name="$1" description="$2"
    echo "  role: ${name}"
    curl -s -X POST "${KEYCLOAK_URL}/admin/realms/${REALM_NAME}/roles" \
        -H "Authorization: Bearer ${TOKEN}" \
        -H "Content-Type: application/json" \
        -d "{\"name\": \"${name}\", \"description\": \"${description}\"}" > /dev/null
}

echo "Creating persona realm roles"
create_role "app-owner"   "Composes catalog items in the Catalog Builder"
create_role "requester"   "Submits orders in the Order Gateway"
create_role "customer"    "Reads order status in the Order Tracker"
create_role "team-member" "Works a team queue in Team SOPs"
create_role "admin"       "Operator — may open every surface"

# Creates a user and grants them one realm role.
create_user_with_role() {
    local username="$1" first="$2" last="$3" role="$4"

    echo "  user: ${username} (${role})"
    curl -s -X POST "${KEYCLOAK_URL}/admin/realms/${REALM_NAME}/users" \
        -H "Authorization: Bearer ${TOKEN}" \
        -H "Content-Type: application/json" \
        -d @- > /dev/null <<EOF
{
    "username": "${username}",
    "email": "${username}@${APP_NAME}.local",
    "emailVerified": true,
    "enabled": true,
    "firstName": "${first}",
    "lastName": "${last}",
    "credentials": [{"type": "password", "value": "${DEMO_PASSWORD}", "temporary": false}]
}
EOF

    local user_id
    user_id=$(curl -s -H "Authorization: Bearer ${TOKEN}" \
        "${KEYCLOAK_URL}/admin/realms/${REALM_NAME}/users?username=${username}&exact=true" \
        | jq -r '.[0].id')

    local role_json
    role_json=$(curl -s -H "Authorization: Bearer ${TOKEN}" \
        "${KEYCLOAK_URL}/admin/realms/${REALM_NAME}/roles/${role}")

    if [ -n "${user_id}" ] && [ "${user_id}" != "null" ]; then
        curl -s -X POST \
            "${KEYCLOAK_URL}/admin/realms/${REALM_NAME}/users/${user_id}/role-mappings/realm" \
            -H "Authorization: Bearer ${TOKEN}" \
            -H "Content-Type: application/json" \
            -d "[${role_json}]" > /dev/null
    else
        echo "  !! could not resolve user id for ${username} — assign ${role} by hand"
    fi
}

echo "Creating one demo user per persona (password: ${DEMO_PASSWORD})"
create_user_with_role "owner"     "Ada"   "Owner"     "app-owner"
create_user_with_role "requester" "Ravi"  "Requester" "requester"
create_user_with_role "customer"  "Cass"  "Customer"  "customer"
create_user_with_role "teamlead"  "Toni"  "Team"      "team-member"

echo ""
echo "Keycloak auto-configuration complete."
echo "  Sign in at http://localhost:${APP_PORT}/ with any of:"
echo "    owner / requester / customer / teamlead"
echo "  Password for all four: ${DEMO_PASSWORD}"
echo ""

# Best-effort completion marker. This script runs in a throwaway alpine
# sidecar, not inside the Keycloak container, so the data directory usually
# does not exist here — never let that fail the run.
[ -d /opt/keycloak/data ] && touch /opt/keycloak/data/configured.marker
exit 0
