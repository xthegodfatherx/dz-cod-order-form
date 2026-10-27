#!/usr/bin/env bash
# Installs the WordPress test suite (same steps as `wp scaffold plugin-tests`).
# Usage: bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]
set -euo pipefail

DB_NAME=${1:?db name}
DB_USER=${2:?db user}
DB_PASS=${3:-}
DB_HOST=${4:-localhost}
WP_VERSION=${5:-latest}

TMPDIR=${TMPDIR:-/tmp}
TMPDIR=$(echo "$TMPDIR" | sed -e "s/\/$//")
WP_TESTS_DIR=${WP_TESTS_DIR:-$TMPDIR/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR:-$TMPDIR/wordpress}

if [ "$WP_VERSION" == "latest" ]; then
	# Use the test library of the released version, not trunk (trunk can be ahead of the release).
	WP_VERSION=$(curl -sSL https://api.wordpress.org/core/version-check/1.7/ | grep -o '"version":"[^"]*' | head -1 | sed 's/"version":"//')
	WP_VERSION=${WP_VERSION%.0}
	echo "Latest WordPress: $WP_VERSION"
	WP_TESTS_TAG="tags/$WP_VERSION"
	WP_ZIP="https://wordpress.org/wordpress-$WP_VERSION.tar.gz"
else
	WP_TESTS_TAG="tags/$WP_VERSION"
	WP_ZIP="https://wordpress.org/wordpress-$WP_VERSION.tar.gz"
fi

mkdir -p "$WP_CORE_DIR" "$WP_TESTS_DIR"
if [ ! -f "$WP_CORE_DIR/wp-settings.php" ]; then
	curl -sSL "$WP_ZIP" | tar --strip-components=1 -zx -C "$WP_CORE_DIR"
fi

if [ ! -d "$WP_TESTS_DIR/includes" ]; then
	svn export --quiet --ignore-externals "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
	svn export --quiet --ignore-externals "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data/" "$WP_TESTS_DIR/data"
fi

if [ ! -f "$WP_TESTS_DIR/wp-tests-config.php" ]; then
	curl -sSL "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/wp-tests-config-sample.php" > "$WP_TESTS_DIR/wp-tests-config.php"
	WP_CORE_DIR_ESC=$(echo "$WP_CORE_DIR" | sed "s:/\+$::")
	sed -i "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR_ESC/':" "$WP_TESTS_DIR/wp-tests-config.php"
	sed -i "s:__DIR__ . '/src/':'$WP_CORE_DIR_ESC/':" "$WP_TESTS_DIR/wp-tests-config.php"
	sed -i "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR/wp-tests-config.php"
	sed -i "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR/wp-tests-config.php"
	sed -i "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR/wp-tests-config.php"
	sed -i "s|localhost|${DB_HOST}|" "$WP_TESTS_DIR/wp-tests-config.php"
fi

mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" --protocol=tcp 2>/dev/null || true
echo "WordPress test suite ready in $WP_TESTS_DIR"
