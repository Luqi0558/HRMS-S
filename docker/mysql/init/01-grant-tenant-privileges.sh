#!/usr/bin/env sh
set -eu

# Stancl tenancy creates a separate database for each company. The image's
# MYSQL_USER grant applies only to MYSQL_DATABASE, so extend it with the DDL
# privileges Laravel needs for tenant database creation and migrations.
mysql --protocol=socket -uroot -p"${MYSQL_ROOT_PASSWORD}" <<-EOSQL
    GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES,
          CREATE TEMPORARY TABLES, LOCK TABLES
    ON *.* TO '${MYSQL_USER}'@'%';
    FLUSH PRIVILEGES;
EOSQL

