=== ImageSnippets Gallery ===
Contributors: gnosyslabs
Tags: gallery, block, media, provenance, rdf
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A responsive, server-rendered gallery block for images from ImageSnippets, with embedded provenance metadata (JSON-LD) for SEO and discoverability.

== Description ==

ImageSnippets Gallery adds a Block Editor block that displays images curated on [ImageSnippets](https://imagesnippets.com). Tag images as being "in" a gallery on ImageSnippets and they appear automatically — changes propagate without editing your post.

Unlike a purely client-side gallery, this plugin renders on the server and caches results, so:

* The gallery's images and their provenance and licensing are present in the page HTML — visible to search engines, social cards, and crawlers, with no JavaScript required to see them.
* Pages load faster and the ImageSnippets endpoint is queried far less often (results are cached in a transient).

= What ends up in the page =

Each image on ImageSnippets is described by its own named graph, and this plugin passes that graph through rather than reducing it to a handful of fields.

The result is a single JSON-LD block serving two readers at once. Search engines find ordinary schema.org — an ImageGallery of ImageObjects with names, descriptions, creators, licences and the entities each image is about, every one carrying a readable name rather than a bare identifier. Semantic-web tools additionally find each image's full graph, still attributed to the graph that asserted it, including the statements schema.org has no vocabulary for: what an image depicts, what is in its background, where its scene is set.

How much to embed is a per-block setting (Structured data &rarr; Metadata detail):

* **schema.org only** — smallest; just what search engines read.
* **Provenance** — the default. Adds the full provenance graph, with page furniture and camera fields stripped.
* **Full graph** — everything, verbatim.

For a forty-image gallery the default profile is roughly five kilobytes once compressed.

Options:

* Filter by gallery name and (optionally) by ImageSnippets user.
* Show captions and/or the gallery title.
* Grid, masonry, or justified layouts; small/medium/large thumbnails.
* Sort by title or date, ascending or descending; limit the number of images.
* Override the SPARQL endpoint (advanced).

This is an independent fork of "IS Gallery" by Henry Sautter, rebuilt for server-side rendering and structured-data output. With thanks to the original author.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/image-snippets-gallery`, or install through the Plugins screen.
2. Activate it.
3. Add the "ImageSnippets Gallery" block to a post or page and enter a gallery name in the block settings.

== Frequently Asked Questions ==

= Where do the images come from? =

From ImageSnippets. Any image tagged as being in the named gallery entity is shown.

= How long are results cached? =

Ten minutes by default, adjustable per block under Advanced &rarr; "Cache for (minutes)". Set it to 0 for always-live, which queries ImageSnippets on every page view. Developers can change the default with the `isg_cache_ttl` filter.

= My galleries do not update when I add images on ImageSnippets. =

Open Tools &rarr; ImageSnippets. It shows when each gallery last updated, how many images it holds, and any error. Use "Refresh all galleries" to pull immediately, or the "Refresh from ImageSnippets" button in the block's settings for a single gallery.

If that screen says a page cache is active but has not been cleared by this plugin, your host or caching plugin is serving stored HTML to visitors. Exclude the gallery pages from that cache, or ask your host how to clear it when content changes.

= Does this work with caching plugins? =

Yes. When a gallery's contents change, the plugin clears the affected pages from WP Rocket, W3 Total Cache, WP Super Cache, Cache Enabler, LiteSpeed Cache, Cachify, and SG Optimizer, and signals WordPress so other caches following core conventions clear themselves. For anything else, hook the `isg_gallery_changed` action.

== Changelog ==

= 0.3.0 =

Galleries now update on their own, and the metadata embedded in the page is far richer.

Freshness:

* Galleries clear the page cache when their contents change, so updates reach visitors instead of sitting behind stored HTML. Works with the common caching plugins by detection, and exposes an `isg_gallery_changed` action for anything else.
* Added a "Refresh from ImageSnippets" button to the block settings, which pulls the gallery again and reports how many images it found.
* Added Tools &rarr; ImageSnippets: every gallery, when it last updated, how many images, which pages show it, and any error. Includes "Refresh all galleries".
* Added Site Health reporting for gallery freshness, cron status, and page-cache detection.
* Cache lifetime is now a per-block setting (Advanced &rarr; "Cache for (minutes)"). Set it to 0 for always-live.
* Editor previews are now always live, so what you see while editing matches what you just changed on ImageSnippets.

Structured data:

* Each image's whole named graph is now retrieved and passed through, rather than a fixed list of fields reassembled into schema.org by hand. Statements schema.org cannot express — what an image depicts, what is in its background, where its scene is set — survive into the page instead of being discarded.
* Entities are emitted with the names already recorded alongside them, so nothing reaches a search engine as an unreadable identifier.
* Named graphs keep their attribution, so a consumer can tell who asserted what.
* New per-block setting (Structured data &rarr; Metadata detail) chooses how much to embed: schema.org only, provenance, or the full graph.

Fixes:

* **Page-cache clearing never worked on Cache Enabler.** The plugin called functions that do not exist in it, so every attempt silently did nothing and galleries stayed stale for visitors on any site using it.
* **"Refresh all galleries" refreshed the wrong thing.** It rebuilt each gallery's query from default settings, so any block using a custom limit or sort order was left untouched while the screen reported success.
* **The refresh button could report success without changing the public page.** It skipped clearing the page cache whenever the newly fetched data matched what was already stored, even though the stored HTML might not have.
* On sites where WP-Cron does not run, a stale gallery could stay stale indefinitely. The refresh now happens during the page view instead.
* The editor-only data-quality notice could appear to other REST consumers.

= 0.2.1 =
* Fix blurry thumbnails: serve real Flickr renditions with proper srcset/sizes derived from the full-resolution source, instead of upscaling the 128px ImageSnippets thumbnail. The thumbnail is kept only as an onerror fallback for link-rotted source URLs.

= 0.2.0 =
* Query the richer ImageSnippets graph: extended descriptions, depicted entities (schema:about), scene location, and full-resolution contentUrl.
* JSON-LD now emits an ImageGallery of ImageObjects, each linked to its canonical entity (about) and location for richer discoverability.
* Alt/caption fallback chain (alt → description → title), with an optional filename fallback and an editor-only warning when images lack titles/alt text.
* Configurable crop ratio (square/4:3/3:2/16:9) to eliminate layout shift; default sort changed to newest-first by date.

= 0.1.0 =
* Initial release of the fork: server-side rendering, transient caching, JSON-LD output, grid/masonry/justified layouts, configurable endpoint.

== Upgrade Notice ==

= 0.3.0 =
Important fix: clearing the page cache never worked on some hosts, leaving galleries stale for visitors while looking correct to logged-in editors. Also embeds much richer image metadata for search engines.

= 0.2.1 =
Sharper gallery thumbnails.

= 0.1.0 =
Initial release.
