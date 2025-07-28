#!/bin/bash
APPNAME="snoopy"

echo "🔍 DEBUG: Password Analysis for myapp1"
echo "======================================"

echo ""
echo "1. 📄 Vault Credentials:"
echo "-------------------------"
cd .installer/vault && podman run --rm -v "$(pwd):/vault:Z" -w /vault quay.io/ansible/ansible-runner:latest sh -c "ansible-vault decrypt --vault-password-file .vault_pass --output /tmp/decrypted.yml credentials.yml && cat /tmp/decrypted.yml" 2>/dev/null
cd - > /dev/null

echo ""
echo "2. 🐳 Container Environment:"
echo "----------------------------"
if podman container exists $APPNAME-postgres 2>/dev/null; then
    CONTAINER_PASSWORD=$(podman inspect $APPNAME-postgres --format '{{json .Config.Env}}' 2>/dev/null | jq -r '.[] | select(startswith("POSTGRES_PASSWORD=")) | split("=")[1]' 2>/dev/null)
    echo "Container POSTGRES_PASSWORD: $CONTAINER_PASSWORD"
else
    echo "❌ $APPNAME-postgres container not found"
fi

if podman container exists $APPNAME-keycloak 2>/dev/null; then
    CONTAINER_KC_PASSWORD=$(podman inspect $APPNAME-keycloak --format '{{json .Config.Env}}' 2>/dev/null | jq -r '.[] | select(startswith("KEYCLOAK_ADMIN_PASSWORD=")) | split("=")[1]' 2>/dev/null)
    echo "Container KEYCLOAK_ADMIN_PASSWORD: $CONTAINER_KC_PASSWORD"
else
    echo "❌ $APPNAME-keycloak container not found"
fi

echo ""
echo "3. 📋 Compose File Password:"
echo "----------------------------"
if [ -f "$APPNAME-compose.yml" ]; then
    COMPOSE_PASSWORD=$(grep "POSTGRES_PASSWORD:" $APPNAME-compose.yml | head -1 | awk '{print $2}')
    echo "Compose POSTGRES_PASSWORD: $COMPOSE_PASSWORD"
    
    COMPOSE_KC_PASSWORD=$(grep "KEYCLOAK_ADMIN_PASSWORD:" $APPNAME-compose.yml | head -1 | awk '{print $2}')
    echo "Compose KEYCLOAK_ADMIN_PASSWORD: $COMPOSE_KC_PASSWORD"
else
    echo "❌ Compose file not found"
fi

echo ""
echo "4. 📁 App Config (app.nimbus.json):"
echo "-----------------------------------"
if [ -f ".installer/apps/$APPNAME/app.nimbus.json" ]; then
    CONFIG_PASSWORD=$(jq -r '.database.password' .installer/apps/$APPNAME/app.nimbus.json 2>/dev/null)
    echo "Config database.password: $CONFIG_PASSWORD"
    
    CONFIG_KC_PASSWORD=$(jq -r '.containers.keycloak.admin_password' .installer/apps/$APPNAME/app.nimbus.json 2>/dev/null)
    echo "Config keycloak.admin_password: $CONFIG_KC_PASSWORD"
    
    echo "Password strategy: $(jq -r '.password_strategy // "none"' .installer/apps/$APPNAME/app.nimbus.json 2>/dev/null)"
else
    echo "❌ app.nimbus.json not found"
fi

echo ""
echo "5. 💾 Data Directory Status:"
echo "----------------------------"
if [ -d "data/$APPNAME" ]; then
    echo "✅ data/$APPNAME exists"
    echo "Contents: $(ls -la data/$APPNAME | wc -l) items"
    echo "PostgreSQL version file: $(cat data/$APPNAME/PG_VERSION 2>/dev/null || echo 'Not found')"
else
    echo "❌ data/$APPNAME does not exist"
fi

echo ""
echo "6. 🔍 Container Status:"
echo "----------------------"
if podman container exists $APPNAME-postgres 2>/dev/null; then
    echo "✅ $APPNAME-postgres container exists"
    CONTAINER_STATUS=$(podman inspect $APPNAME-postgres --format '{{.State.Status}}' 2>/dev/null)
    echo "Status: $CONTAINER_STATUS"
else
    echo "❌ $APPNAME-postgres container not found"
fi

if podman container exists $APPNAME-keycloak 2>/dev/null; then
    echo "✅ $APPNAME-keycloak container exists"
    KC_CONTAINER_STATUS=$(podman inspect $APPNAME-keycloak --format '{{.State.Status}}' 2>/dev/null)
    echo "Keycloak Status: $KC_CONTAINER_STATUS"
else
    echo "❌ $APPNAME-keycloak container not found"
fi

echo ""
echo "7. 📊 Password Comparison:"
echo "-------------------------"
VAULT_DB_PASSWORD=$(cd .installer/vault && podman run --rm -v "$(pwd):/vault:Z" -w /vault quay.io/ansible/ansible-runner:latest sh -c "ansible-vault decrypt --vault-password-file .vault_pass --output /tmp/decrypted.yml credentials.yml && grep -A 10 '$APPNAME:' /tmp/decrypted.yml | grep 'password:' | head -1 | awk '{print \$2}' | tr -d '\"'" 2>/dev/null)
VAULT_KC_PASSWORD=$(cd .installer/vault && podman run --rm -v "$(pwd):/vault:Z" -w /vault quay.io/ansible/ansible-runner:latest sh -c "ansible-vault decrypt --vault-password-file .vault_pass --output /tmp/decrypted.yml credentials.yml && grep -A 10 'keycloak:' /tmp/decrypted.yml | grep 'admin_password:' | awk '{print \$2}' | tr -d '\"'" 2>/dev/null)

echo "DATABASE PASSWORDS:"
echo "  Vault:     $VAULT_DB_PASSWORD"
echo "  Container: ${CONTAINER_PASSWORD:-'N/A'}"
echo "  Compose:   ${COMPOSE_PASSWORD:-'N/A'}"
echo "  Config:    ${CONFIG_PASSWORD:-'N/A'}"

echo ""
echo "KEYCLOAK ADMIN PASSWORDS:"
echo "  Vault:     $VAULT_KC_PASSWORD"
echo "  Container: ${CONTAINER_KC_PASSWORD:-'N/A'}"
echo "  Compose:   ${COMPOSE_KC_PASSWORD:-'N/A'}"
echo "  Config:    ${CONFIG_KC_PASSWORD:-'N/A'}"

echo ""
echo "MATCH STATUS:"
if [ "$CONTAINER_KC_PASSWORD" = "$CONFIG_KC_PASSWORD" ]; then
    echo "✅ Keycloak Container and Config passwords MATCH"
else
    echo "❌ Keycloak Container and Config passwords MISMATCH!"
    echo "   Container: ${CONTAINER_KC_PASSWORD:-'N/A'}"
    echo "   Config:    ${CONFIG_KC_PASSWORD:-'N/A'}"
fi

echo ""
echo "8. 🔧 Force Init Script Status:"
echo "-------------------------------"
if [ -f ".installer/apps/$APPNAME/database/force-init.sh" ]; then
    echo "✅ force-init.sh exists"
    echo "Executable: $(test -x .installer/apps/$APPNAME/database/force-init.sh && echo 'Yes' || echo 'No')"
else
    echo "❌ force-init.sh not found"
fi

echo ""
echo "9. 🔄 Docker Init Scripts:"
echo "---------------------------"
if podman container exists $APPNAME-postgres 2>/dev/null; then
    echo "Mounted init scripts in container:"
    podman exec $APPNAME-postgres ls -la /docker-entrypoint-initdb.d/ 2>/dev/null || echo "Container not accessible"
else
    echo "Container not running"
fi

echo ""
echo "10. 🌐 Web Interface Source:"
echo "----------------------------"
echo "The web interface password comes from the app config at:"
echo ".installer/apps/$APPNAME/app.config.php"
if [ -f ".installer/apps/$APPNAME/app.config.php" ]; then
    echo "Keycloak password in web config:"
    grep -A 5 -B 5 "admin.*password\|password.*admin" .installer/apps/$APPNAME/app.config.php | head -10
else
    echo "❌ app.config.php not found"
fi
