#!/usr/bin/env bash
# Restore a dump produced by `php artisan db:backup`.
# Always restore into a throwaway database first. Untested backups are not backups.
set -euo pipefail
DUMP="${1:?path to .sql dump}"
php artisan db:restore "$DUMP" --force
echo "Restored $DUMP. Check /health."
