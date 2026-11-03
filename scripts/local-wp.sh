#!/usr/bin/env bash
# Local WordPress on SQLite for manual testing (no MySQL needed).
#
#   scripts/local-wp.sh setup   download core + SQLite drop-in, install, link the plugin
#   scripts/local-wp.sh serve   php -S 127.0.0.1:8141
#
# Everything lives in .local-wp/ (git-ignored). Admin login: admin / admin.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
LOCAL="$ROOT/.local-wp"
WP="$LOCAL/wordpress"
PORT="${PORT:-8141}"
URL="http://127.0.0.1:$PORT"

setup() {
	mkdir -p "$LOCAL"
	if [ ! -f "$WP/wp-load.php" ]; then
		curl -sSL -o "$LOCAL/wp.tgz" https://wordpress.org/latest.tar.gz
		tar -xzf "$LOCAL/wp.tgz" -C "$LOCAL"
	fi

	local sqlite="$WP/wp-content/plugins/sqlite-database-integration"
	if [ ! -d "$sqlite" ]; then
		mkdir -p "$LOCAL/dl-sqlite"
		curl -sSL -o "$LOCAL/dl-sqlite/sqlite.zip" https://downloads.wordpress.org/plugin/sqlite-database-integration.zip
		unzip -q -o "$LOCAL/dl-sqlite/sqlite.zip" -d "$WP/wp-content/plugins/"
	fi
	# Per the plugin readme: db.copy becomes wp-content/db.php with the placeholders filled in.
	sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$sqlite#" \
		-e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
		"$sqlite/db.copy" > "$WP/wp-content/db.php"

	if [ ! -f "$WP/wp-config.php" ]; then
		sed -e "s/database_name_here/wordpress/" -e "s/username_here/root/" -e "s/password_here//" \
			"$WP/wp-config-sample.php" > "$WP/wp-config.php"
		php -r '
			$f = $argv[1];
			$c = file_get_contents($f);
			$c = str_replace("define( \x27WP_DEBUG\x27, false );", "define( \x27WP_DEBUG\x27, true );\ndefine( \x27WP_DEBUG_LOG\x27, true );\ndefine( \x27WP_DEBUG_DISPLAY\x27, false );", $c);
			file_put_contents($f, $c);
		' "$WP/wp-config.php"
	fi

	ln -sfn "$ROOT" "$WP/wp-content/plugins/spa-ai-concierge"
	mkdir -p "$WP/wp-content/mu-plugins"

	php -d display_errors=1 -r '
		define( "WP_INSTALLING", true );
		$_SERVER["HTTP_HOST"] = "127.0.0.1:'"$PORT"'";
		$_SERVER["REQUEST_URI"] = "/";
		require $argv[1] . "/wp-load.php";
		require_once ABSPATH . "wp-admin/includes/upgrade.php";
		if ( ! is_blog_installed() ) {
			wp_install( "Verbena Med Spa (local)", "admin", "admin@example.test", true, "", "admin" );
			update_option( "siteurl", "'"$URL"'" );
			update_option( "home", "'"$URL"'" );
			update_option( "permalink_structure", "/%postname%/" );
			echo "Installed\n";
		} else {
			echo "Already installed\n";
		}
	' "$WP"
}

serve() {
	cd "$WP"
	exec php -S "127.0.0.1:$PORT" -t "$WP" "$ROOT/scripts/router.php"
}

case "${1:-}" in
	setup) setup ;;
	serve) serve ;;
	*) echo "usage: $0 setup|serve" >&2; exit 1 ;;
esac
