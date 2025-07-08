#!/bin/sh
set -e

echo "Installing Ansible collections..."
ansible-galaxy collection install ansible.eda community.crypto community.general

echo "Certbot install..."
microdnf install -y python3-pip gcc python3-devel libffi-devel openssl-devel && pip3 install certbot

echo "Starting rulebooks..."
# Start SSL order rulebook in background
ansible-rulebook --rulebook ssl-order.yml --inventory /inventory/inventory.yml --verbose &

# Start SSL expiry rulebook in background
ansible-rulebook --rulebook ssl-expiry.yml --inventory /inventory/inventory.yml --verbose &

# Wait for all background processes
wait