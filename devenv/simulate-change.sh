#!/usr/bin/env bash
#
# Make the site's stored copy of a gallery disagree with ImageSnippets, so the
# next refresh has something real to correct.
#
# The honest test of the refresh button is "add an image on ImageSnippets, watch
# the site pick it up", but that needs a collection you can write to. This is
# the same test from the other end: drop an image from what the site has stored,
# so a refresh must notice the difference, fetch the missing one, and purge the
# page cache. The purge path exercised is identical.
#
# Usage: ./devenv/simulate-change.sh [page-slug]      (default: gallery)

set -euo pipefail
cd "$(dirname "$0")"

docker compose run --rm -T \
    -v "$(pwd)/fixture.php:/fixture.php:ro" \
    cli wp --path=/var/www/html eval-file /fixture.php drop "${1:-gallery}"

cat <<'EOF'

The site now believes this gallery has one fewer image than it does.
Reload the public page (private window) to see the smaller gallery,
then click Refresh from ImageSnippets and reload again.
EOF
