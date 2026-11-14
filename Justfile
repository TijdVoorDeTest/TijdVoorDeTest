up *args:
    docker compose up -d {{ args }}

compose *args:
    docker compose {{ args }}

down *args:
    docker compose down --remove-orphans {{ args }}

stop:
    docker compose stop

exec *args:
    docker compose exec php {{ args }}

[no-exit-message]
shell:
    @docker compose exec php bash

[no-exit-message]
shell-run:
    @docker compose run --rm php bash

migrate: up
    docker compose run --rm php bin/console doctrine:migrations:migrate --no-interaction

fixtures:
    docker compose exec php bin/console doctrine:fixtures:load --purge-with-truncate --no-interaction --group=dev

translations:
    docker compose exec php bin/console translation:extract --force --format=yaml --sort=asc --domain=messages nl

fix-cs:
    docker compose exec php vendor/bin/php-cs-fixer fix
    docker compose exec php vendor/bin/twig-cs-fixer fix

rector *args:
    docker compose exec php vendor/bin/rector {{ args }}

phpstan *args:
    docker compose exec php vendor/bin/phpstan analyse {{ args }}

# Runs in parallel (ParaTest, one DB per worker, see reload-tests). For a single process use `just test-serial`.
test *args:
    docker compose exec php vendor/bin/paratest {{ args }}

test-serial *args:
    docker compose exec php vendor/bin/phpunit {{ args }}

# Browser-driven E2E tests (Symfony Panther). Reloads the test DB before AND after: these tests
# mutate real data (e.g. renames) in a separate process from PHPUnit, so DAMA's per-test
# rollback doesn't cover them, and `just test` afterwards would otherwise see dirty fixtures.
test-e2e *args: reload-tests
    #!/usr/bin/env bash
    set -uo pipefail
    docker compose exec php vendor/bin/phpunit --testsuite=e2e {{ args }}
    status=$?
    just reload-tests
    exit $status

fix-ts:
    docker compose exec php deno fmt assets/
    docker compose exec php deno lint --fix assets/

check-ts:
    docker compose exec php deno check assets/

test-ts *args:
    docker compose exec php deno test assets/ {{ args }}

[confirm]
clean:
    docker compose down -v --remove-orphans
    rm -rf vendor var assets/vendor public/assets public/bundles .php-cs-fixer.cache .twig-cs-fixer.cache

reload-tests *workers:
    @docker compose exec php bin/reload-test-dbs {{ workers }}

install-hooks:
    git config core.hooksPath .githooks
    chmod +x .githooks/pre-commit
    @echo "Pre-commit hook installed."

trust-cert:
    sudo security add-trusted-cer -d \
        -r trustRoot \
        -k "$HOME/Library/Keychains/login.keychain" \
        frankenphp/data/caddy/pki/authorities/local/root.crt
