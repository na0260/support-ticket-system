DC  := docker compose
APP := app

export HOST_UID := $(shell id -u)
export HOST_GID := $(shell id -g)

.DEFAULT_GOAL := help
.PHONY: help up down build restart ps logs bash artisan composer test migrate fresh perms

help: ## List available targets
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

up: ## Start all containers in the background
	$(DC) up -d

down: ## Stop and remove containers
	$(DC) down

build: ## (Re)build images and start containers
	$(DC) up -d --build

restart: ## Restart all containers
	$(DC) restart

ps: ## Show container status
	$(DC) ps

logs: ## Tail logs (make logs s=app)
	$(DC) logs -f $(s)

bash: ## Open a shell inside the app container
	$(DC) exec $(APP) sh

artisan: ## Run artisan (make artisan c="route:list")
	$(DC) exec $(APP) php artisan $(c)

composer: ## Run composer (make composer c="require foo/bar")
	$(DC) exec $(APP) composer $(c)

test: ## Run the test suite
	$(DC) exec $(APP) php artisan test

migrate: ## Run database migrations
	$(DC) exec $(APP) php artisan migrate

fresh: ## Drop all tables and re-run migrations with seeders
	$(DC) exec $(APP) php artisan migrate:fresh --seed

perms: ## Fix storage/cache permissions
	$(DC) exec -u root $(APP) sh -c "chown -R www-data:www-data storage bootstrap/cache && chmod -R 775 storage bootstrap/cache"
