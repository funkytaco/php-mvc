#!/bin/sh
set -e

echo "Installing Ansible collections..."
ansible-galaxy collection install ansible.eda community.crypto community.general

echo "Starting {{APP_NAME}} rulebooks..."
# Start demo rulebook
ansible-rulebook --rulebook demo-rules.yml --inventory /inventory/inventory.yml --verbose &

# Wait for all background processes
wait