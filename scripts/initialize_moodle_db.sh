#!/usr/bin/env bash

# WARNING:
# Execute only once! Do not try again.
#

# Running this script rebuilds the Moodle DB.
#
# install_database.php is a built-in command-line script in Moodle. Its purpose
# is very simple: when a config.php file already exists, it initializes an empty
# database and creates the tables, default data, and admin account needed for
# Moodle to run.
#
docker compose exec moodle php admin/cli/install_database.php \
  --agree-license \
  --fullname="Local Moodle Site in Docker" \
  --shortname="moodle" \
  --adminpass="$MOODLE_ADMIN_USER_PASSWORD" \
  --adminemail="admin@example.com"

