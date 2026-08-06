=== ImageSnippets Gallery ===
Contributors: gnosyslabs
Tags: gallery, block, media, provenance, rdf
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A responsive, server-rendered gallery block for images from ImageSnippets, with embedded provenance metadata (JSON-LD) for SEO and discoverability.

== Description ==

ImageSnippets Gallery adds a Block Editor block that displays images curated on [ImageSnippets](https://imagesnippets.com). Tag images as being "in" a gallery on ImageSnippets and they appear automatically — changes propagate without editing your post.

Unlike a purely client-side gallery, this plugin renders on the server and caches results, so:

* The gallery's images and their provenance/license metadata are present in the page HTML — visible to search engines, social cards, and crawlers (emitted as schema.org JSON-LD).
* Pages load faster and the ImageSnippets endpoint is queried far less often (results are cached in a transient).

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
* Galleries now clear the page cache when their contents change, so updates reach visitors instead of sitting behind stored HTML. Works with the common caching plugins by detection, and exposes an `isg_gallery_changed` action for anything else.
* Added a "Refresh from ImageSnippets" button to the block settings, which pulls the gallery again and reports how many images it found.
* Added Tools &rarr; ImageSnippets: every gallery, when it last updated, how many images, which pages show it, and any error. Includes "Refresh all galleries".
* Added Site Health reporting for gallery freshness, cron status, and page-cache detection.
* Cache lifetime is now a per-block setting (Advanced &rarr; "Cache for (minutes)"). Set it to 0 for always-live.
* Editor previews are now always live, so what you see while editing matches what you just changed on ImageSnippets.
* Fixed: on sites where WP-Cron does not run, a stale gallery could stay stale indefinitely. The refresh now happens during the page view instead.
* Fixed: the editor-only data-quality notice could appear to other REST consumers.

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
Galleries now update on their own and clear your page cache when they change. Adds a refresh button and a Tools screen showing when each gallery last updated.

= 0.2.1 =
Sharper gallery thumbnails.

= 0.1.0 =
Initial release.
