#!/usr/bin/env bash
# Update the live site to the latest code. Run on the server from the project folder: bash deploy.sh
# Stops at the first error; the site shows a maintenance page only while the database is being updated.
set -euo pipefail
cd "$(dirname "$0")"

echo "→ Getting the latest code"
git pull --ff-only

echo "→ Installing PHP packages (no development tools)"
composer install --no-dev --optimize-autoloader --no-interaction --no-progress

echo "→ Building the website files"
npm ci --no-audit --no-fund
npm run build
rm -f public/hot

echo "→ Backing up the database before changing it"
php artisan platform:backup

echo "→ Updating the database"
php artisan down --retry=30 --refresh=15
trap 'php artisan up' EXIT
php artisan migrate --force

echo "→ Caching settings, routes and views"
php artisan optimize:clear
php artisan optimize

php artisan up
trap - EXIT

echo "→ Checking the live settings"
php artisan app:production-check
echo "✓ Deployed"
