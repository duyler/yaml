.PHONY: tests
tests:
	docker-compose run --rm php vendor/bin/phpunit

.PHONY: infection
infection:
	docker-compose run --rm php vendor/bin/infection

.PHONY: psalm
psalm:
	docker-compose run --rm php vendor/bin/psalm

.PHONY: cs-fix
cs-fix:
	docker-compose run --rm php vendor/bin/php-cs-fixer fix

.PHONY: rector
rector:
	docker-compose run --rm php vendor/bin/rector

.PHONY: shell
shell:
	docker-compose run --rm php /bin/bash

.PHONY: coverage
coverage:
	docker-compose run --rm php vendor/bin/phpunit --coverage-html=coverage --coverage-text

.PHONY: update
update:
	docker-compose run --rm php composer update
