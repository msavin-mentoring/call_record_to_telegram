SHELL := /bin/sh

COMPOSE_ENV_FILES := --env-file .env
ifneq ("$(wildcard .env.local)","")
COMPOSE_ENV_FILES += --env-file .env.local
endif

COMPOSE := docker compose $(COMPOSE_ENV_FILES)
TAG_SCRIPT := ./scripts/tag-release.sh

.PHONY: up up-prod logs logs-prod tag tag-dry-run

up:
	$(COMPOSE) up -d --build

up-prod:
	$(COMPOSE) -f docker-compose.yml -f docker-compose.prod.yml up -d --build worker

logs:
	$(COMPOSE) logs -f worker

logs-prod:
	$(COMPOSE) -f docker-compose.yml -f docker-compose.prod.yml logs -f worker

tag:
	$(TAG_SCRIPT) $(or $(BUMP),patch)

tag-dry-run:
	DRY_RUN=1 $(TAG_SCRIPT) $(or $(BUMP),patch)
