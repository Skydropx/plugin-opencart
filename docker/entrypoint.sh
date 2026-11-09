#!/bin/bash
# First boot: run the OpenCart CLI installer, then start Apache.
set -euo pipefail

cd /var/www/html

if [ ! -f /var/www/html/install.lock ]; then
    wait-for-it "${DB_HOSTNAME}:3306" -t 120
    # MySQL accepts TCP a moment before it accepts logins.
    for _ in $(seq 1 30); do
        php -r 'exit(@mysqli_connect(getenv("DB_HOSTNAME"), "root", getenv("DB_PASSWORD"), getenv("DB_DATABASE")) ? 0 : 1);' && break
        sleep 2
    done

    cp config-dist.php config.php
    cp admin/config-dist.php admin/config.php
    chown www-data:www-data config.php admin/config.php

    su -s /bin/bash www-data -c "php install/cli_install.php install \
        --username '${OC_ADMIN_USER}' --password '${OC_ADMIN_PASSWORD}' --email '${OC_ADMIN_EMAIL}' \
        --http_server '${OC_HTTP_SERVER}' --db_driver mysqli --db_hostname '${DB_HOSTNAME}' \
        --db_username root --db_password '${DB_PASSWORD}' --db_database '${DB_DATABASE}' \
        --db_port 3306 --db_prefix oc_"

    # The admin refuses to work while install/ exists.
    rm -rf install
    touch install.lock
fi

exec apache2-foreground
