# Local host simulation

A WordPress that behaves like Margaret's managed host rather than like a clean
dev box, so freshness and cache behaviour can be tested here instead of on a
call with her.

Three defaults from Nexcess Managed WordPress are reproduced:

| Layer | Here | On her site |
|---|---|---|
| Full-page cache | Cache Enabler 1.8 (KeyCDN) | A Nexcess fork of the same plugin |
| Object cache | Redis Object Cache + Redis | Object Cache Pro + Redis |
| Cron | `DISABLE_WP_CRON`, driven from outside every 15 min | Same arrangement, typical of managed hosts |

Requires Docker. No PHP or WP-CLI on the host.

```bash
./devenv/setup.sh              # first run pulls images and builds; a few minutes
```

Then: <http://localhost:8080/gallery/> · admin at `/wp-admin/` (`admin` / `admin`).

Teardown, including the database:

```bash
docker compose -f devenv/docker-compose.yml down -v
```

---

## The one rule

**Never judge freshness from a browser you are logged into.**

Every page cache bypasses for logged-in users. Margaret is logged in, so she saw
fresh galleries; her visitors got HTML that had been sitting on disk since the
last time anyone happened to trigger a render. That asymmetry is the whole bug.

So there are two views of every page, and only one of them matters:

```bash
./devenv/visitor.sh            # anonymous, cookieless — what a visitor gets
```

It reports whether the HTML came off disk (`HIT`) or out of PHP (`MISS`), how
many images are in it, and whether the JSON-LD is present.

Two traps this script exists to avoid, both of which will otherwise hand you a
confident wrong answer:

- A logged-in browser never sees the cache at all.
- Plain `curl` sends `Accept: */*`, and Cache Enabler refuses to cache any
  response whose request did not ask for `text/html`. Without a browser-like
  `Accept` header every single request looks like a MISS and the page cache
  appears not to exist. `visitor.sh` sends the right headers.

Cache expiry is set to **never** here, on purpose. Nothing goes stale on a timer,
so anything stale is stale because a purge did not happen.

---

## Test A — the editor refresh button

This is the path that has never been exercised in a browser.

You need a gallery you can add images to. `hs_gallery02` (Henry Sautter's, the
default) is fine for reading but you cannot add to it — for the round trip you
need an ImageSnippets collection of your own, or one Margaret gives you write
access to. Pass it to setup: `./devenv/setup.sh my_gallery`.

1. Open <http://localhost:8080/gallery/> **in a private window**. Note the images.
2. In a normal window, open `/wp-admin/`, edit the *Gallery* page, and confirm
   the block preview shows the same images.
3. In another tab, add an image to that collection on imagesnippets.com.
4. Reload the private window. **It should not change** — the visitor is being
   served stored HTML, and nothing has told the cache otherwise. This is the bug
   as Margaret experienced it, reproduced on demand.
5. Back in the editor, select the block and click **Refresh from ImageSnippets**
   in the sidebar. The preview should pick up the new image.
6. Reload the private window. **It should now show the new image.**

Step 6 is the assertion. Step 4 is the control — without it you cannot tell a
working purge from a cache that was never populated.

Scripted equivalent, for regression:

```bash
APP=$(./devenv/wp user application-password create admin t --porcelain | tail -1)
./devenv/visitor.sh >/dev/null && ./devenv/visitor.sh | grep 'page cache'   # HIT
curl -sS -u "admin:$APP" -X POST localhost:8080/wp-json/imagesnippets/v1/refresh \
  -H 'Content-Type: application/json' \
  -d '{"attributes":{"gallery":"hs_gallery02","limit":12}}'
./devenv/visitor.sh | grep 'page cache'                                     # MISS
```

The button purges whether or not the data moved. A scheduled refresh purges only
on change — a busy site should not flush pages every interval for nothing — but
someone who clicked Refresh is asking "does the public page match ImageSnippets
now?", and answering "yes" while leaving stale HTML in place is the original
failure wearing a different hat.

---

## Test B — the purge adapter

The plugin has no universal purge API to call, so it detects what is installed.
Check that detection lands:

```bash
./devenv/wp eval "print_r( isg_purge_page_cache( array( 4 ) ) );"
```

Expected: `Cache Enabler` and `Cache Enabler (by URL)`. An empty array means the
cascade found nothing and the site will go stale silently.

This test has already earned its keep. The adapter originally looked for global
functions named `cache_enabler_clear_page_cache_by_post_id()` and friends, taken
from upstream documentation. **Those functions do not exist.** Cache Enabler
publishes its purge API only as static methods on the `Cache_Enabler` class, so
every `function_exists()` check returned false and the adapter never fired — on
any site running it, Margaret's included. Fixed in `includes/cache-purge.php`,
which now resolves `Class::method` targets too.

Worth keeping in mind for the call with her: the Nexcess fork may have renamed
things again. If `isg_purge_page_cache()` returns an empty array on her site,
that is the answer, and the `isg_gallery_changed` action is the escape hatch.

---

## Test C — freshness without touching anything

The `cron` container runs `wp cron event run --due-now` every 15 minutes, which
is roughly the granularity a managed host gives you. To watch it:

```bash
docker compose -f devenv/docker-compose.yml logs -f cron
./devenv/wp cron event list                      # what is scheduled
./devenv/wp eval "print_r( isg_sync_status() );" # last sync, row count, errors
```

To iterate faster, shorten the interval — but change it back before drawing any
conclusion about how fast her site will update:

```bash
ISG_CRON_INTERVAL=60 docker compose -f devenv/docker-compose.yml up -d cron
```

Because `DISABLE_WP_CRON` is set, nothing runs on page loads. That is deliberate:
it is the condition the plugin's overdue-synchronous fallback exists for. To test
that fallback, stop cron entirely (`docker compose ... stop cron`) and confirm a
gallery still eventually updates for a visitor.

---

## Knobs

| Variable | Default | Effect |
|---|---|---|
| `ISG_PORT` | `8080` | Host port |
| `ISG_CRON_INTERVAL` | `900` | Seconds between cron runs |

```bash
./devenv/wp <any wp-cli command>
./devenv/wp cache-enabler clear          # empty the page cache by hand
./devenv/wp redis status                 # object cache health
docker compose -f devenv/docker-compose.yml logs -f wordpress
```

---

## What this does not simulate

Worth being explicit, so the call with Margaret targets the right unknowns:

- **Her fork of Cache Enabler.** This runs stock upstream. Renamed methods are
  exactly the kind of difference that would not show up here.
- **Object Cache Pro.** Redis Object Cache is the free equivalent and shares the
  `wp_cache_*` semantics, but it is not the same code.
- **Her host's actual cron arrangement.** Unconfirmed. The 15-minute interval is
  a plausible guess, not a measurement.
- **Any cache in front of PHP** — a CDN or the host's reverse proxy. Nothing the
  plugin does in PHP can invalidate those, which is why layer 3 exists.
