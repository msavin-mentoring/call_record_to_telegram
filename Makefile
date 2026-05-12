up:
	docker compose up -d --build

up-prod:
	docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build worker

logs:
	docker compose logs -f worker

logs-prod:
	docker compose -f docker-compose.yml -f docker-compose.prod.yml logs -f worker
