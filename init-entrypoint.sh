#!/bin/sh
set -e

echo "Installing Ansible collections..."
ansible-galaxy collection install ansible.eda community.crypto community.general

echo "Starting newapp rulebooks..."
# Start SSL order processing rulebook
ansible-rulebook --rulebook ssl-order.yml --inventory /inventory/inventory.yml --verbose &

# Start SSL expiry checking rulebook  
ansible-rulebook --rulebook ssl-expiry.yml --inventory /inventory/inventory.yml --verbose &

# Wait for all background processes
wait