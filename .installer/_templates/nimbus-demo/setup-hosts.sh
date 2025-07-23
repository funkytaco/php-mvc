#!/bin/bash
# Setup /etc/hosts entries for {{APP_NAME}} Nimbus app
# This script requires sudo access to modify /etc/hosts

APP_NAME="{{APP_NAME}}"
MARKER="# ${APP_NAME} Nimbus app containers"
END_MARKER="# End ${APP_NAME}"

# Color codes for output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

echo -e "${YELLOW}Setting up hosts entries for ${APP_NAME}...${NC}"

# Check if running on macOS
if [[ "$OSTYPE" != "darwin"* ]]; then
    echo -e "${RED}This script is designed for macOS only.${NC}"
    exit 1
fi

# Function to remove existing entries
remove_entries() {
    sudo sed -i '' "/${MARKER}/,/${END_MARKER}/d" /etc/hosts 2>/dev/null
}

# Function to add entries
add_entries() {
    cat << EOF | sudo tee -a /etc/hosts >/dev/null
${MARKER}
127.0.0.1    ${APP_NAME}-app.test
127.0.0.1    ${APP_NAME}-keycloak.test
127.0.0.1    ${APP_NAME}-db.test
127.0.0.1    ${APP_NAME}-eda.test
${END_MARKER}
EOF
}

# Remove any existing entries first
remove_entries

# Add new entries
add_entries

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ Successfully added hosts entries for ${APP_NAME}${NC}"
    echo
    echo "You can now access your app using these URLs:"
    echo "  • http://${APP_NAME}-app.test:{{APP_PORT}}"
    if [ -f "app.nimbus.json" ] && grep -q '"keycloak": true' app.nimbus.json 2>/dev/null; then
        echo "  • http://${APP_NAME}-keycloak.test:8080"
    fi
    echo
    echo "To remove these entries later, run:"
    echo "  sudo ./setup-hosts.sh remove"
else
    echo -e "${RED}✗ Failed to add hosts entries. Make sure you have sudo access.${NC}"
    exit 1
fi

# Handle remove argument
if [ "$1" == "remove" ]; then
    remove_entries
    echo -e "${GREEN}✓ Removed hosts entries for ${APP_NAME}${NC}"
fi