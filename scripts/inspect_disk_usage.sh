#!/usr/bin/env bash

# inspect disk usage for the Moodle DB container
# sudo du -sh /var/lib/docker/volumes/moodle_pg_data/_data
sudo du -h --max-depth=1 /var/lib/docker/volumes/moodle_pg_data/_data | sort -h

