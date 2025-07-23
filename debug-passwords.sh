#!/bin/bash

echo "🔍 DEBUG: Password Analysis for myapp1"
echo "======================================"

echo ""
echo "1. 📄 Vault Credentials:"
echo "-------------------------"
cd .installer/vault && podman run --rm -v "$(pwd):/vault:Z" -w /vault quay.io/ansible/ansible-runner:latest sh -c "ansible-vault decrypt --vault-password-file .vault_pass --output /tmp/decrypted.yml credentials.yml && cat /tmp/decrypted.yml" 2>/dev/null
cd - > /dev/null

echo ""
echo "2. 🐳 Container Environment (myapp1-postgres):"
echo "----------------------------------------------"
CONTAINER_PASSWORD=$(podman inspect myapp1-postgres --format '{{json .Config.Env}}' 2>/dev/null | jq -r '.[] | select(startswith("POSTGRES_PASSWORD=")) | split("=")[1]' 2>/dev/null)
echo "Container POSTGRES_PASSWORD: $CONTAINER_PASSWORD"

echo ""
echo "3. 📋 Compose File Password:"
echo "----------------------------"
COMPOSE_PASSWORD=$(grep "POSTGRES_PASSWORD:" myapp1-compose.yml | head -1 | awk '{print $2}')
echo "Compose POSTGRES_PASSWORD: $COMPOSE_PASSWORD"

echo ""
echo "4. 📁 App Config (app.nimbus.json):"
echo "-----------------------------------"
if [ -f ".installer/apps/myapp1/app.nimbus.json" ]; then
    CONFIG_PASSWORD=$(jq -r '.database.password' .installer/apps/myapp1/app.nimbus.json 2>/dev/null)
    echo "Config database.password: $CONFIG_PASSWORD"
    echo "Has vault_restore flag: $(jq -r '.vault_restore // "false"' .installer/apps/myapp1/app.nimbus.json 2>/dev/null)"
else
    echo "❌ app.nimbus.json not found"
fi

echo ""
echo "5. 💾 Data Directory Status:"
echo "----------------------------"
if [ -d "data/myapp1" ]; then
    echo "✅ data/myapp1 exists"
    echo "Contents: $(ls -la data/myapp1 | wc -l) items"
    echo "PostgreSQL version file: $(cat data/myapp1/PG_VERSION 2>/dev/null || echo 'Not found')"
else
    echo "❌ data/myapp1 does not exist"
fi

echo ""
echo "6. 🔍 Container Status:"
echo "----------------------"
if podman container exists myapp1-postgres 2>/dev/null; then
    echo "✅ myapp1-postgres container exists"
    CONTAINER_STATUS=$(podman inspect myapp1-postgres --format '{{.State.Status}}' 2>/dev/null)
    echo "Status: $CONTAINER_STATUS"
    
    if [ "$CONTAINER_STATUS" = "running" ]; then
        echo ""
        echo "7. 🧪 Password Test (inside container):"
        echo "--------------------------------------"
        echo "Testing connection with container password..."
        podman exec myapp1-postgres sh -c "PGPASSWORD='$CONTAINER_PASSWORD' psql -U myapp1_user -d myapp1_db -c 'SELECT current_user;'" 2>&1 | head -3
    fi
else
    echo "❌ myapp1-postgres container not found"
fi

echo ""
echo "8. 📊 Summary:"
echo "-------------"
echo "Vault password: $(cd .installer/vault && podman run --rm -v "$(pwd):/vault:Z" -w /vault quay.io/ansible/ansible-runner:latest sh -c "ansible-vault decrypt --vault-password-file .vault_pass --output /tmp/decrypted.yml credentials.yml && grep 'password:' /tmp/decrypted.yml | head -1 | awk '{print \$2}' | tr -d '\"'" 2>/dev/null)"
echo "Container password: $CONTAINER_PASSWORD"
echo "Compose password: $COMPOSE_PASSWORD"
echo "Config password: $CONFIG_PASSWORD"

echo ""
if [ "$CONTAINER_PASSWORD" = "$COMPOSE_PASSWORD" ]; then
    echo "✅ Container and Compose passwords MATCH"
else
    echo "❌ Container and Compose passwords MISMATCH!"
fi

echo ""
echo "9. 🔧 Force Init Script Status:"
echo "-------------------------------"
if [ -f ".installer/apps/myapp1/database/force-init.sh" ]; then
    echo "✅ force-init.sh exists"
    echo "Executable: $(test -x .installer/apps/myapp1/database/force-init.sh && echo 'Yes' || echo 'No')"
else
    echo "❌ force-init.sh not found"
fi

echo ""
echo "10. 🔄 Docker Init Scripts:"
echo "---------------------------"
echo "Mounted init scripts in container:"
podman exec myapp1-postgres ls -la /docker-entrypoint-initdb.d/ 2>/dev/null || echo "Container not running or accessible"