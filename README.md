# Moodle Bootstrapper

Bootstrap a local Moodle instance in a Docker environment.

## Quickstart

Bootstrap a fresh repo + some daily `make` commands.

```bash
# Fresh clone
cp .env.example .env          # edit values
make up                       # start db + moodle
make init                     # install Moodle schema
make bootstrap                # create API user/service/token
make verify                   # confirm token works

# Daily
make ps
make logs
make token                    # print token
make stop                     # stop cleanly

# Disaster recovery
make reset                    # wipe, confirm, rebuild
make up && make init && make bootstrap

# Before changes
make backup
```

## Dev Cheatsheet

Below are some tips: the dev cheatsheet.

### Lifecycle

    cd ~/repos/moodle_api_docker
    docker compose up -d            # start
    docker compose stop             # stop (data kept)
    docker compose down             # remove containers (volume kept)
    docker compose down -v          # NEVER — wipes DB

### Health

    docker compose ps               # want (healthy)
    docker compose logs -f moodle   # follow logs

### Endpoints

    Site:   http://localhost:8080
    Admin:  admin / <your-password>
    API:    http://localhost:8080/webservice/rest/server.php

### Get token

```sh
# copy token from output. If script fails twice → manual UI (below).
docker compose run --rm moodle-cli
```

### Test token (smoke test)

Run this curl command:

```sh
curl -s http://localhost:8080/webservice/rest/server.php \
  --data-urlencode "wstoken=$TOKEN" \
  --data-urlencode "wsfunction=core_webservice_get_site_info" \
  --data-urlencode "moodlewsrestformat=json"
```

### Manual token fallback (10 min)

Site admin → Plugins → Web services:
1. Enable web services (Advanced features)
2. Enable REST protocol
3. External services → create "moodle_api" → add functions → Authorized users → add api_client
4. Manage tokens → Add → api_client + moodle_api

### Inspect DB

```sh
docker compose exec db psql -U moodle -d moodle -c "\dt"
```

### Inspect access tokens

```sh
docker compose exec db psql -U moodle -d moodle -c \
"SELECT id, tokentype, token FROM mdl_external_tokens;"
```

### Disaster recovery

1. `docker compose up -d`
2. install_database.php  (creates schema)
3. enable web services + REST in UI
4. `docker compose run --rm moodle-cli`  (or manual fallback)
5. reinstall Attendance plugin via UI → Site admin → Notifications

## Caveats

**A bind-mount source that doesn't exist on the host is silently created as an
empty directory.** If a file "vanishes" inside a container while `ls` on the
host says the path is gone, the mount source is wrong — `ls -ld` inside the
container (leading `d`) confirms it.

### Missing capabilities

Adding a new function to the `$FUNCTIONS` array in the
`scripts/setup-api-user.php` may not be enough — you need to check if the
"API Client" user is missing any capabilities in: Moodle home page -> Site
administration -> Server -> Web services -> Manage tokens. There, look for the
user named "API Client". If the **Missing Capabilities** column on the right
lists anything, those capabilities must be added to the `$CAPABILITIES` array
in the PHP script.

Re-run `make bootstrap` after editing the PHP script to apply the changes.

### Missing Cron

The `moodlehq/moodle-php-apache:8.3` image this project is using is a
**runtime-only** image (PHP + Apache). Unlike some production-oriented Moodle
images that bundle a cron daemon by default, this official image does **not**
include a running cron service. Without a separate process invoking
`admin/cli/cron.php`, Moodle's scheduled and ad-hoc background tasks will
simply sit in the queue forever.

It is an expected missing piece in a basic Docker Compose deployment.

The quickest way to confirm cron is the missing piece is to trigger it once by
hand:

```sh
docker compose exec moodle php admin/cli/cron.php
```

(You can now check the Cron Logs in **Site administration → Server → Tasks →
Task logs**.)

To enable cron, you have a few options:

#### Option A — Host Cron (simplest)

Add a line to your host's crontab (run `crontab -e`):

```cron
* * * * * cd /path/to/repo/moodle-bootstrapper && docker compose exec -T moodle php admin/cli/cron.php > /dev/null 2>&1
```

This uses the host to trigger cron inside the running container. It is easy to
debug because you can run the same command manually.

#### Option B — Dedicated Cron Sidecar (more self-contained)

(Check out the `moodle-cron` service section in `docker-compose.yml`.)

Start it with `docker compose --profile cron up -d moodle-cron`. This keeps
cron isolated from the web container.

