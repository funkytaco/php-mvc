#!/bin/sh
set -e

echo "Installing Ansible collections..."
ansible-galaxy collection install ansible.eda community.crypto community.general

echo "Certbot install..."
microdnf install -y python3-pip gcc python3-devel libffi-devel openssl-devel && pip3 install certbot

echo "Starting rulebook..."
ansible-rulebook --rulebook ssl-order.yml --inventory /inventory/inventory.yml --verbose