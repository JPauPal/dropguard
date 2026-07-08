#!/usr/bin/env bash
#
# Drop Guard — Ubuntu / Apache / MySQL deployment script
#
# Usage (on the server as root):
#   sudo bash ubuntu-deploy.sh
#
# Before running, set GIT_REPO to your repository URL and adjust MySQL
# credentials if needed. Copy .env.example to .env on the server after deploy.

set -euo pipefail

# ---------------------------------------------------------------------------
# Configuration — edit these before running
# ---------------------------------------------------------------------------
GIT_REPO="${GIT_REPO:-https://github.com/JPauPal/dropguard.git}"
GIT_BRANCH="${GIT_BRANCH:-main}"

WEB_ROOT="/var/www/html"
APACHE_SITE="/etc/apache2/sites-enabled/000-default.conf"

DB_NAME="dropguard_db"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"          # leave empty to use sudo mysql / .my.cnf
DB_HOST="${DB_HOST:-localhost}"

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------
log() { printf '[dropguard] %s\n' "$*"; }
die() { printf '[dropguard] ERROR: %s\n' "$*" >&2; exit 1; }

require_root() {
  if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
    die "Run this script as root: sudo bash $0"
  fi
}

mysql_exec() {
  if [[ -n "$DB_PASS" ]]; then
    mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$@"
  else
    mysql -h "$DB_HOST" -u "$DB_USER" "$@"
  fi
}

# ---------------------------------------------------------------------------
# 1. Reset web root and pull latest project files
# ---------------------------------------------------------------------------
deploy_application() {
  log "Resetting ${WEB_ROOT} ..."

  if [[ -d "$WEB_ROOT" ]]; then
    find "$WEB_ROOT" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
  else
    mkdir -p "$WEB_ROOT"
  fi

  if [[ -z "$GIT_REPO" ]]; then
    die "Set GIT_REPO to your Drop Guard repository URL before running."
  fi

  log "Cloning ${GIT_REPO} (branch: ${GIT_BRANCH}) ..."
  git clone --depth 1 --branch "$GIT_BRANCH" "$GIT_REPO" "$WEB_ROOT"

  # Ensure writable runtime directories exist
  mkdir -p "${WEB_ROOT}/storage/sessions" "${WEB_ROOT}/public/uploads/students"
}

# ---------------------------------------------------------------------------
# 2. Root .htaccess — MVC front-controller routing
# ---------------------------------------------------------------------------
write_htaccess() {
  log "Writing ${WEB_ROOT}/.htaccess ..."

  cat > "${WEB_ROOT}/.htaccess" <<'HTACCESS'
Options -Indexes
RewriteEngine On

# Serve static assets and existing paths under public/
RewriteCond %{REQUEST_URI} !^/public/
RewriteCond %{DOCUMENT_ROOT}/public%{REQUEST_URI} -f [OR]
RewriteCond %{DOCUMENT_ROOT}/public%{REQUEST_URI} -d
RewriteRule ^(.*)$ public/$1 [L]

# Standard MVC: existing files/dirs pass through; everything else -> index.php
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]

RewriteRule ^ index.php [L]

# Block direct web access to application internals
RedirectMatch 403 ^/(app|sql|ml|storage|tools)(/|$)

# Block hidden files (.env, .git, etc.)
<FilesMatch "^\.">
    Require all denied
</FilesMatch>
HTACCESS
}

# ---------------------------------------------------------------------------
# 3. Apache — mod_rewrite + default vhost
# ---------------------------------------------------------------------------
configure_apache() {
  log "Enabling mod_rewrite ..."
  a2enmod rewrite

  log "Writing ${APACHE_SITE} ..."
  cat > "$APACHE_SITE" <<APACHE
<VirtualHost *:80>
    ServerAdmin webmaster@localhost
    DocumentRoot ${WEB_ROOT}

    ErrorLog \${APACHE_LOG_DIR}/error.log
    CustomLog \${APACHE_LOG_DIR}/access.log combined

    <Directory ${WEB_ROOT}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
APACHE

  log "Testing Apache configuration ..."
  apachectl configtest
  systemctl reload apache2
}

# ---------------------------------------------------------------------------
# 4. Ownership and permissions
# ---------------------------------------------------------------------------
fix_permissions() {
  log "Setting ownership to www-data:www-data ..."
  chown -R www-data:www-data "$WEB_ROOT"

  log "Setting permissions (644 files / 755 directories) ..."
  find "$WEB_ROOT" -type d -exec chmod 755 {} +
  find "$WEB_ROOT" -type f -exec chmod 644 {} +

  # Sessions and uploads must be writable by the web server
  chmod -R 775 "${WEB_ROOT}/storage" "${WEB_ROOT}/public/uploads"
  chown -R www-data:www-data "${WEB_ROOT}/storage" "${WEB_ROOT}/public/uploads"
}

# ---------------------------------------------------------------------------
# 5. MySQL — create database and import schema
# -------------------------------------------------------------------
import_database() {
  local schema="${WEB_ROOT}/sql/schema.sql"

  [[ -f "$schema" ]] || die "Schema not found: ${schema}"

  log "Creating database ${DB_NAME} ..."
  mysql_exec -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

  log "Importing schema from ${schema} ..."
  # schema.sql creates/uses "dropguard"; skip those lines and import into dropguard_db
  tail -n +6 "$schema" | mysql_exec "$DB_NAME"

  log "Database ${DB_NAME} is ready."
}

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------
main() {
  require_root

  command -v git    >/dev/null 2>&1 || die "git is not installed."
  command -v mysql  >/dev/null 2>&1 || die "mysql client is not installed."
  command -v apachectl >/dev/null 2>&1 || die "Apache is not installed."

  deploy_application
  write_htaccess
  configure_apache
  fix_permissions
  import_database

  log "Deployment complete."
  log "Next steps:"
  log "  1. Copy ${WEB_ROOT}/.env.example to ${WEB_ROOT}/.env"
  log "  2. Set DB_NAME=${DB_NAME} and your DB credentials in .env"
  log "  3. Visit http://YOUR_SERVER_IP/ to verify the application"
}

main "$@"
