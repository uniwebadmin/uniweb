#!/usr/bin/env bash
# Local DEV database bootstrap for the Cursor Cloud environment.
# Starts MariaDB, creates the dev database + user, and loads the reconstructed
# dev schema (dev_local/schema.sql). Idempotent — safe to re-run.
#
# NOTE: The real production DB schema lives in gitignored migrations/*.sql that
# are NOT in this repository. dev_local/schema.sql is a reconstructed minimal
# schema created only so the app can boot and be exercised locally.
set -u

DB_NAME="${DB_NAME:-uniweb}"
DB_USER="${DB_USER:-uniweb}"
DB_PASS="${DB_PASS:-uniweb_dev}"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

echo "==> Starting MariaDB"
sudo mkdir -p /var/run/mysqld
sudo chown -R mysql:mysql /var/run/mysqld 2>/dev/null || true
sudo service mariadb start || sudo mysqld_safe --skip-grant-tables=0 &
sleep 4
sudo mysqladmin ping 2>/dev/null || { sleep 4; }

echo "==> Creating database + user"
sudo mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

echo "==> Loading base dev schema (tables not owned by migrations/)"
mysql -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" < "${ROOT_DIR}/dev_local/schema.sql"

echo "==> Ensuring local config.php exists"
if [ -f "${ROOT_DIR}/config.dev.php" ] && [ ! -f "${ROOT_DIR}/config.php" ]; then
    cp "${ROOT_DIR}/config.dev.php" "${ROOT_DIR}/config.php"
    echo "    copied config.dev.php -> config.php"
fi

echo "==> Applying real migrations/*.sql (schema of record)"
if [ -d "${ROOT_DIR}/migrations" ]; then
    php -r 'require getcwd()."/config.php"; $a = applyPendingMigrations(getcwd()."/migrations"); $f = $a["applied_files"] ?? []; fwrite(STDERR, "    applied: ".(count($f)?implode(", ",$f):"none (already up to date)")."\n");' \
        2>&1 || echo "    (migration step reported an issue — check output above)"
else
    echo "    migrations/ not present — running on base dev schema only"
fi

echo "==> Done. Start the app with: php -S 0.0.0.0:8000"
