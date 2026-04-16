# erikr/auth — test targets.
#
#   make test       Run the PHPUnit Unit suite (72 tests, no DB required).
#   make test-unit  Alias for `make test` — kept for symmetry with consumer apps.
#
# Tests run without a live DB: AUTH_DB_PREFIX='' is defined in tests/bootstrap.php,
# and every test that touches DB behaviour does so via mocks.

PHPUNIT ?= ./vendor/bin/phpunit

.PHONY: test test-unit

test:
	$(PHPUNIT)

test-unit:
	$(PHPUNIT) --testsuite Unit
