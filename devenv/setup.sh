#!/usr/bin/env bash
#
# Bring up the managed-host simulation and put it in a known state.
# Safe to re-run; it skips anything already done.
#
# Usage:  ./devenv/setup.sh [gallery-name]      (default: hs_gallery02)

set -euo pipefail
cd "$(dirname "$0")"

GALLERY="${1:-hs_gallery02}"
PORT="${ISG_PORT:-8080}"
SITE="http://localhost:${PORT}"

wp() { docker compose run --rm -T cli wp --path=/var/www/html "$@"; }

echo "==> starting containers"
docker compose up -d db redis wordpress cron

echo "==> waiting for WordPress to answer"
for _ in $(seq 1 60); do
    if curl -sf -o /dev/null "${SITE}/wp-admin/install.php"; then break; fi
    if curl -sf -o /dev/null "${SITE}/"; then break; fi
    sleep 2
done

if ! wp core is-installed 2>/dev/null; then
    echo "==> installing WordPress"
    wp core install \
        --url="$SITE" \
        --title="ImageSnippets Gallery — host simulation" \
        --admin_user=admin \
        --admin_password=admin \
        --admin_email=dev@example.invalid \
        --skip-email
fi

echo "==> pretty permalinks (Cache Enabler needs them)"
wp rewrite structure '/%postname%/' --hard >/dev/null
wp rewrite flush --hard >/dev/null

echo "==> our plugin"
wp plugin activate image-snippets-gallery >/dev/null

echo "==> page cache: Cache Enabler (the plugin Nexcess forked)"
wp plugin is-installed cache-enabler >/dev/null 2>&1 || wp plugin install cache-enabler >/dev/null
wp plugin activate cache-enabler >/dev/null

echo "==> object cache: Redis Object Cache (stands in for Object Cache Pro)"
wp plugin is-installed redis-cache >/dev/null 2>&1 || wp plugin install redis-cache >/dev/null
wp plugin activate redis-cache >/dev/null
wp redis enable >/dev/null 2>&1 || true

if ! wp post list --post_type=page --name=gallery --format=count | grep -q '^1$'; then
    echo "==> creating the /gallery/ test page"
    wp post create \
        --post_type=page \
        --post_title='Gallery' \
        --post_name='gallery' \
        --post_status=publish \
        --post_content="<!-- wp:imagesnippets/gallery {\"gallery\":\"${GALLERY}\",\"displayTitle\":true,\"displayCaption\":true,\"limit\":12} /-->" \
        >/dev/null
fi

echo "==> clearing any cached HTML so the first look is honest"
wp cache-enabler clear >/dev/null 2>&1 || true

cat <<EOF

Ready.

  site        ${SITE}
  admin       ${SITE}/wp-admin/   (admin / admin)
  test page   ${SITE}/gallery/    gallery = ${GALLERY}

Checks:
  ./devenv/visitor.sh              what an anonymous visitor sees
  ./devenv/wp cron event list      scheduled syncs
  ./devenv/wp redis status         object cache
  docker compose -f devenv/docker-compose.yml logs -f cron

Read devenv/README.md before drawing conclusions — being logged in changes
what you see, and that is the exact trap Margaret fell into.
EOF
