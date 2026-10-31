# Moodle Bootstrap — The Makefile
#
# Run from repo root (where docker-compose.yml lives).
# Bare `make` prints help.

.DEFAULT_GOAL := help
SHELL         := /bin/bash
.SHELLFLAGS   := -eu -o pipefail -c

COMPOSE       := docker compose
MOODLE_DIR    := moodle
MOODLEDATA_DIR := moodledata
BACKUP_DIR    := backups
DB_SERVICE    := db
MOODLE_SERVICE := moodle
CLI_SERVICE   := moodle-cli

# ---------- help ----------

.PHONY: help
help: ## Show this help
	@echo "Usage: make <target>"
	@echo ""
	@grep -E '^[a-zA-Z0-9_-]+:.*## ' $(MAKEFILE_LIST) \
	  | sort \
	  | awk 'BEGIN {FS = ":.*## "}; {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}'

# ---------- guards (each target declares what it needs) ----------

.PHONY: check-root
check-root:
	@test -f docker-compose.yml \
	  || (echo "ERROR: run from repo root (no docker-compose.yml here)."; exit 1)

.PHONY: check-env
check-env: check-root
	@test -f .env \
	  || (echo "ERROR: missing .env. Run: cp .env.example .env, then edit it."; exit 1)

.PHONY: check-moodle-code
check-moodle-code: check-root
	@test -f $(MOODLE_DIR)/public/version.php \
	  || (echo "ERROR: $(MOODLE_DIR)/public/version.php missing. See README (submodule or fetch script)."; exit 1)

.PHONY: check-compose
check-compose: check-env
	@$(COMPOSE) config >/dev/null && echo "compose file: OK"

.PHONY: check-healthy
check-healthy: check-root
	@n=$$($(COMPOSE) ps -q $(DB_SERVICE)); \
	if [ -z "$$n" ]; then \
	  echo "ERROR: no '$(DB_SERVICE)' container. Project-name mismatch?"; \
	  echo "  Try: docker inspect moodle --format '{{index .Config.Labels \"com.docker.compose.project\"}}'"; \
	  exit 1; \
	fi
	@timeout 60 bash -c 'until [ "$$($(COMPOSE) ps $(DB_SERVICE) --format "{{.Health}}")" = "healthy" ]; do sleep 2; done' \
	  || (echo "ERROR: $(DB_SERVICE) not healthy within 60s. Run: make logs-db"; exit 1)

.PHONY: check-installed
check-installed: check-healthy
	@n=$$($(COMPOSE) exec -T $(DB_SERVICE) psql -U moodle -d moodle -tAc \
	  "SELECT count(*) FROM information_schema.tables WHERE table_name LIKE 'mdl_%'"); \
	if [ "$${n:-0}" -lt 1 ]; then \
	  echo "ERROR: database is empty. Run: make init"; exit 1; \
	fi

.PHONY: check-empty
check-empty: check-healthy
	@n=$$($(COMPOSE) exec -T $(DB_SERVICE) psql -U moodle -d moodle -tAc \
	  "SELECT count(*) FROM information_schema.tables WHERE table_name LIKE 'mdl_%'"); \
	if [ "$${n:-0}" -gt 0 ]; then \
	  echo "ERROR: DB already has tables. Run 'make reset' first (this wipes data)."; exit 1; \
	fi

# ---------- lifecycle ----------

.PHONY: up
up: check-env check-moodle-code ## Start db + moodle containers
	$(COMPOSE) up -d $(DB_SERVICE) $(MOODLE_SERVICE)
	@echo "Moodle: http://localhost:8080"

.PHONY: stop
stop: ## Stop containers (data preserved)
	$(COMPOSE) stop

.PHONY: down
down: ## Remove containers + network (volumes preserved)
	$(COMPOSE) down

.PHONY: ps
ps: ## Show container status
	$(COMPOSE) ps

.PHONY: logs
logs: ## Follow all logs (Ctrl-C to exit)
	@echo ""
	@echo "-----------------------------------"
	@echo "    Entering the Docker logs..."
	@echo "    Use Ctrl-C to exit."
	@echo "-----------------------------------"
	@echo ""
	$(COMPOSE) logs -f

.PHONY: logs-db
logs-db: ## Follow db logs
	@echo ""
	@echo "--------------------------------------------"
	@echo "    Entering the Docker database logs..."
	@echo "    Use Ctrl-C to exit."
	@echo "--------------------------------------------"
	@echo ""
	$(COMPOSE) logs -f $(DB_SERVICE)

.PHONY: shell-db
shell-db: ## psql shell into db
	$(COMPOSE) exec $(DB_SERVICE) psql -U moodle -d moodle

.PHONY: shell-moodle
shell-moodle: ## bash shell into moodle
	$(COMPOSE) exec $(MOODLE_SERVICE) bash

# ---------- provisioning ----------

.PHONY: init
init: check-env check-healthy ## Initialise Moodle DB (runs install_database.php)
	bash scripts/initialize_moodle_db.sh

.PHONY: bootstrap
bootstrap: check-env check-installed ## Create API user/role/service/token (idempotent)
	$(COMPOSE) run --rm $(CLI_SERVICE)

.PHONY: token
token: check-installed ## Print the current API token
	@$(COMPOSE) exec -T $(DB_SERVICE) psql -U moodle -d moodle -tAc \
	  "SELECT t.token FROM mdl_external_tokens t \
	   JOIN mdl_user u ON u.id = t.userid \
	   WHERE u.username = 'api_client' LIMIT 1"

.PHONY: verify
verify: check-installed ## Smoke-test the token against core_webservice_get_site_info
	@tok=$$($(MAKE) -s token); \
	test -n "$$tok" || (echo "No token. Run: make bootstrap"; exit 1); \
	curl -s http://localhost:8080/webservice/rest/server.php \
	  --data-urlencode "wstoken=$$tok" \
	  --data-urlencode "wsfunction=core_webservice_get_site_info" \
	  --data-urlencode "moodlewsrestformat=json"

.PHONY: clean-bootstrap
clean-bootstrap: ## Remove leftover one-shot moodle-cli containers (rarely needed)
	-$(COMPOSE) rm -sf $(CLI_SERVICE) 2>/dev/null

# ---------- data ----------

.PHONY: backup
backup: check-env check-healthy ## Dump DB + config into ./backups/
	@mkdir -p $(BACKUP_DIR)
	@ts=$$(date +%Y%m%d-%H%M%S); \
	$(COMPOSE) exec -T $(DB_SERVICE) pg_dump -U moodle moodle > $(BACKUP_DIR)/db-$$ts.sql; \
	tar czf $(BACKUP_DIR)/config-$$ts.tgz $(MOODLE_DIR)/config.php; \
	echo "Wrote $(BACKUP_DIR)/db-$$ts.sql and $(BACKUP_DIR)/config-$$ts.tgz"

.PHONY: restore
restore: check-env check-empty ## Restore DB from FILE=backups/db-*.sql (requires empty DB)
	@test -n "$(FILE)" || (echo "Usage: make restore FILE=backups/db-YYYYMMDD-HHMMSS.sql"; exit 1)
	@test -f "$(FILE)" || (echo "No such file: $(FILE)"; exit 1)
	$(COMPOSE) exec -T $(DB_SERVICE) psql -U moodle -d moodle < $(FILE)
	@echo "Restored from $(FILE)"

# ---------- destructive ----------

.PHONY: reset
reset: check-env ## DESTRUCTIVE: wipe DB volume and ./moodledata, then rebuild from scratch
	@echo ""
	@echo "This will DELETE:"
	@echo "  - the named DB volume (all courses, users, config)"
	@echo "  - contents of ./$(MOODLEDATA_DIR) (uploads, caches, sessions)"
	@echo ""
	@read -p "Type exactly 'yes' to continue: " ok && [ "$$ok" = "yes" ] || (echo "Aborted."; exit 1)
	-$(COMPOSE) rm -sf $(CLI_SERVICE) 2>/dev/null
	$(COMPOSE) down -v
	mkdir -p $(MOODLEDATA_DIR) && rm -rf $(MOODLEDATA_DIR)/*
	@echo "Wiped. Next: make up && make init && make bootstrap"
