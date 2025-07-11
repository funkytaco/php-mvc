#!/bin/bash
set -e
cat ./original_hba.conf >> /var/lib/postgresql/data/pg_hba.conf
psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c 'SELECT pg_reload_conf();'
