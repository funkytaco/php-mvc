#!/bin/bash

# Enhanced force initialization script for PostgreSQL
# Handles vault credential restoration with existing data directories

set -e

echo "🔄 Database initialization starting..."
echo "📊 Environment: DB=$POSTGRES_DB, USER=$POSTGRES_USER, FORCE_INIT=${FORCE_INIT:-false}"

# Function to wait for PostgreSQL with timeout
wait_for_postgres() {
    local max_attempts=30
    local attempt=1
    
    echo "⏳ Waiting for PostgreSQL to be ready..."
    while [ $attempt -le $max_attempts ]; do
        if pg_isready -U postgres -h localhost >/dev/null 2>&1; then
            echo "✅ PostgreSQL is ready!"
            return 0
        fi
        
        echo "   Attempt $attempt/$max_attempts..."
        sleep 2
        attempt=$((attempt + 1))
    done
    
    echo "❌ PostgreSQL failed to start within timeout"
    return 1
}

# Function to safely execute SQL with error handling
execute_sql() {
    local sql="$1"
    local description="$2"
    
    echo "🔧 $description"
    if psql -U postgres -d postgres -c "$sql" >/dev/null 2>&1; then
        echo "   ✅ Success"
        return 0
    else
        echo "   ⚠️  Warning: $description failed, but continuing..."
        return 1
    fi
}

# Check if force init is needed (vault restore with existing data)
if [ "$FORCE_INIT" = "true" ]; then
    echo "🔐 VAULT RESTORE: Updating database credentials for existing data"
    
    # Wait for PostgreSQL to be ready
    if ! wait_for_postgres; then
        echo "❌ Cannot proceed without PostgreSQL"
        exit 1
    fi
    
    # Update user password and ensure proper permissions
    echo "🔑 Updating credentials for user: $POSTGRES_USER"
    
    execute_sql "
        DO \$\$
        BEGIN
            -- Update existing user or create if doesn't exist
            IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '$POSTGRES_USER') THEN
                ALTER USER $POSTGRES_USER WITH PASSWORD '$POSTGRES_PASSWORD';
                RAISE NOTICE 'Password updated for existing user: $POSTGRES_USER';
            ELSE
                CREATE USER $POSTGRES_USER WITH PASSWORD '$POSTGRES_PASSWORD';
                RAISE NOTICE 'Created new user: $POSTGRES_USER';
            END IF;
            
            -- Ensure user has proper permissions
            ALTER USER $POSTGRES_USER WITH CREATEDB;
        END
        \$\$;
    " "User password and permissions update"
    
    # Ensure database exists and user has access
    execute_sql "
        SELECT 'CREATE DATABASE $POSTGRES_DB OWNER $POSTGRES_USER'
        WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '$POSTGRES_DB')\gexec
    " "Database creation (if needed)"
    
    execute_sql "
        GRANT ALL PRIVILEGES ON DATABASE $POSTGRES_DB TO $POSTGRES_USER;
    " "Database permissions grant"
    
    echo "✅ Credential update completed successfully!"
    
else
    echo "ℹ️  Standard initialization (no force init required)"
    
    # Still wait for PostgreSQL for standard operations
    if ! wait_for_postgres; then
        echo "❌ PostgreSQL not ready for standard initialization"
        exit 1
    fi
fi

# Always ensure schema is current (using app user credentials)
if [ -f /docker-entrypoint-initdb.d/schema.sql ]; then
    echo "📊 Applying schema updates..."
    
    # Use the app user credentials for schema operations
    if PGPASSWORD="$POSTGRES_PASSWORD" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -f /docker-entrypoint-initdb.d/schema.sql >/dev/null 2>&1; then
        echo "✅ Schema applied successfully!"
    else
        echo "⚠️  Schema application had issues, but continuing..."
    fi
else
    echo "ℹ️  No schema file found, skipping schema application"
fi

echo "🎉 Database initialization complete!"
echo "🔒 Final status: USER=$POSTGRES_USER, DB=$POSTGRES_DB, STRATEGY=${FORCE_INIT:+VAULT_RESTORE}"