COMPOSE_ENV_FILES := --env-file .env
ifneq ("$(wildcard .env.local)","")
COMPOSE_ENV_FILES += --env-file .env.local
endif

COMPOSE := docker compose $(COMPOSE_ENV_FILES)

up:
	$(COMPOSE) up -d --build

up-prod:
	$(COMPOSE) -f docker-compose.yml -f docker-compose.prod.yml up -d --build worker

logs:
	$(COMPOSE) logs -f worker

logs-prod:
	$(COMPOSE) -f docker-compose.yml -f docker-compose.prod.yml logs -f worker
