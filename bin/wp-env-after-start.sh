#!/usr/bin/env bash
#
# wp-env afterStart lifecycle script.
#
# wp-browser (Codeception WPLoader) connects to the test database via PDO, but the
# wp-env WordPress image ships without pdo_mysql — which surfaces as
# "Could not connect to the database: could not find driver". This installs it in
# the tests-wordpress container.
#
# IMPORTANT: more than one wp-env instance can run at once (different projects),
# so a bare `docker ps | grep tests-wordpress` is ambiguous and matches them all.
# We target THIS project's instance by its wp-env hash (the basename of
# `wp-env install-path`), e.g. <hash>-tests-wordpress-1.
#
# Always exits 0 so `wp-env start` never reports an afterStart error.

set -uo pipefail

WP_ENV_BIN="./node_modules/.bin/wp-env"
[ -x "$WP_ENV_BIN" ] || WP_ENV_BIN="npx wp-env"

# Resolve this project's wp-env instance hash.
INSTALL_PATH="$( $WP_ENV_BIN install-path 2>/dev/null || true )"
HASH="$( [ -n "$INSTALL_PATH" ] && basename "$INSTALL_PATH" || true )"

if [ -z "$HASH" ]; then
	echo "afterStart: could not resolve wp-env instance hash; skipping pdo_mysql install."
	exit 0
fi

# Find this instance's tests-wordpress container (it may take a moment to be ready).
CID=""
for _ in $(seq 1 15); do
	CID="$( docker ps --filter "name=${HASH}-tests-wordpress" --format '{{.ID}}' | head -n1 )"
	[ -n "$CID" ] && break
	sleep 2
done

if [ -z "$CID" ]; then
	echo "afterStart: tests-wordpress container for instance ${HASH} not found; skipping."
	exit 0
fi

if docker exec "$CID" php -m 2>/dev/null | grep -qi '^pdo_mysql$'; then
	echo "afterStart: pdo_mysql already installed (container ${CID})."
	exit 0
fi

echo "afterStart: installing pdo_mysql in container ${CID} (instance ${HASH}) ..."
docker exec "$CID" docker-php-ext-install pdo_mysql \
	&& docker exec "$CID" service apache2 reload >/dev/null 2>&1 || true

if docker exec "$CID" php -m 2>/dev/null | grep -qi '^pdo_mysql$'; then
	echo "afterStart: pdo_mysql installed."
else
	echo "afterStart: WARNING — pdo_mysql still missing after install attempt." >&2
fi

exit 0
