#!/bin/bash

# Force initialization script for PostgreSQL
# This script runs when we need to update passwords on existing data

set -e

echo "🔄 Force init triggered - checking if password update needed..."

# Check if we're restoring from vault (indicated by FORCE_INIT=true)
if [ "$FORCE_INIT" = "true" ]; then
    echo "🔐 Vault restore detected - updating database password..."
    
    # Wait for PostgreSQL to be ready
    until pg_isready -U postgres; do
        echo "⏳ Waiting for PostgreSQL to start..."
        sleep 2
    done
    
    # Update the user password to match the environment variable
    echo "🔑 Updating password for user: $POSTGRES_USER"
    psql -U postgres -d postgres -c "ALTER USER $POSTGRES_USER WITH PASSWORD '$POSTGRES_PASSWORD';"
    
    echo "✅ Password updated successfully!"
else
    echo "ℹ️  No force init required - using standard initialization"
fi

# Always run the schema script to ensure tables exist
if [ -f /docker-entrypoint-initdb.d/schema.sql ]; then
    echo "📊 Running schema initialization..."
    psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -f /docker-entrypoint-initdb.d/schema.sql
    echo "✅ Schema initialization complete!"
fi