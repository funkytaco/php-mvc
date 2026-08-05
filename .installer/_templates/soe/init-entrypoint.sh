#!/bin/sh
set -e

echo "Installing Ansible collections..."
ansible-galaxy collection install ansible.eda community.crypto community.general

echo "Starting {{APP_NAME}} rulebooks..."

# SOP task automation (webhook on :5000). A team binds a rulebook to an SOP
# step in the Team SOPs surface and runs it against one order; the app POSTs
# a "sop.run" event here, and the playbook reports back to the app when done.
if [ -f /rulebooks/sop-rules.yml ]; then
    ansible-rulebook --rulebook /rulebooks/sop-rules.yml --inventory /inventory/inventory.yml --verbose &
fi

# Keycloak auto-configuration (webhook on :5001). Two rulebooks cannot share a
# port, which is why these run as separate background processes.
if [ -f /rulebooks/keycloak-config.yml ]; then
    ansible-rulebook --rulebook /rulebooks/keycloak-config.yml --inventory /inventory/inventory.yml --verbose &
fi

# Wait for all background processes
wait
