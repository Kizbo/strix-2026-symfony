test:
	ddev exec bin/console doctrine:database:create --if-not-exists --env=test
	ddev exec bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env=test
	ddev exec bin/phpunit

stan:
	ddev exec vendor/bin/phpstan analyse --no-progress

cs:
	ddev exec vendor/bin/php-cs-fixer fix

qa: cs stan test

.PHONY: test stan cs qa
