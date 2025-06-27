#!/bin/bash
set -e
#echo "host all all 0.0.0.0/0 md5" >> /var/lib/postgresql/data/pg_hba.conf
cat ./original_hba.conf >> /var/lib/postgresql/data/pg_hba.conf
psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c 'SELECT pg_reload_conf();'
