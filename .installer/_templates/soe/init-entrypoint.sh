#!/bin/sh
set -e

echo "Installing Ansible collections..."
ansible-galaxy collection install ansible.eda community.crypto community.general

echo "Starting {{APP_NAME}} rulebooks..."
# Start demo rulebook (webhook on :5000)
ansible-rulebook --rulebook /rulebooks/demo-rules.yml --inventory /inventory/inventory.yml --verbose &

# Start Keycloak auto-configuration rulebook (webhook on :5001) when present
if [ -f /rulebooks/keycloak-config.yml ]; then
    ansible-rulebook --rulebook /rulebooks/keycloak-config.yml --inventory /inventory/inventory.yml --verbose &
fi

# Wait for all background processes
wait