all: clean test

test:
	vendor/bin/phpunit

coverage:
	vendor/bin/phpunit --coverage-html=artifacts/coverage

view-coverage:
	open artifacts/coverage/index.html

clean:
	rm -rf artifacts/*

static: static-phpstan static-codestyle-check static-composer-normalize-check

static-phpstan:
	composer install
	composer bin phpstan update
	vendor/bin/phpstan analyze $(PHPSTAN_PARAMS)

static-phpstan-update-baseline:
	composer install
	composer bin phpstan update
	$(MAKE) static-phpstan PHPSTAN_PARAMS="--generate-baseline"

static-codestyle-fix:
	composer install
	composer bin php-cs-fixer update
	vendor/bin/php-cs-fixer fix --diff $(CS_PARAMS)

static-codestyle-check:
	$(MAKE) static-codestyle-fix CS_PARAMS="--dry-run"

static-composer-normalize-fix:
	composer install
	composer bin composer-normalize update
	composer bin composer-normalize normalize --diff $(COMPOSER_NORMALIZE_PARAMS) ../../composer.json

static-composer-normalize-check:
	$(MAKE) static-composer-normalize-fix COMPOSER_NORMALIZE_PARAMS="--dry-run"
