=== ImageSnippets Gallery ===
Contributors: gnosyslabs
Tags: gallery, block, media, provenance, rdf
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A responsive, server-rendered gallery block for images from ImageSnippets, with embedded provenance metadata (JSON-LD) for SEO and discoverability.

== Description ==

ImageSnippets Gallery adds a Block Editor block that displays images curated on [ImageSnippets](https://imagesnippets.com). Tag images as being "in" a gallery on ImageSnippets and they appear automatically — changes propagate without editing your post.

Unlike a purely client-side gallery, this plugin renders on the server from a copy of each gallery kept in WordPress, so:

* The gallery's images and their provenance and licensing are present in the page HTML — visible to search engines, social cards, and crawlers, with no JavaScript required to see them.
* Pages never wait on the ImageSnippets endpoint. Each gallery is fetched on a schedule and stored on your site; a page view reads the stored copy.
* Your site's own search finds the images. Searching for a title, a description, or anything an image is tagged as depicting lands the visitor on the gallery page that shows it.
* Nothing is added to your admin screens. The stored copy is invisible: no new menu, nothing in Posts or Media, nothing in your sitemap.

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
* Grid or masonry layouts; small/medium/large thumbnails; optional gallery title (heading level of your choice).
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

= How quickly do changes on ImageSnippets reach my site? =

Each gallery is re-fetched every ten minutes by default, adjustable per block under Advanced &rarr; "Check ImageSnippets every (minutes)". Set it to 0 to re-fetch on every page view. When a fetch finds changes, the affected pages are cleared from any page cache. Developers can change the default with the `isg_cache_ttl` filter.

The Tools &rarr; ImageSnippets screen shows when each gallery was last fetched; the "Refresh" buttons there and in the block settings fetch immediately. There is also a WP-CLI command: `wp isg sync`.

= Does this create posts on my site? =

Yes, hidden ones. Each mirrored image is stored as a post of a private type so WordPress can query and search it, but the type has no admin screen, no public URL, and does not appear in menus, sitemaps, feeds, or the REST API. Removing a gallery from your pages removes its stored images; deleting the plugin removes everything.

= My galleries do not update when I add images on ImageSnippets. =

Open Tools &rarr; ImageSnippets. It shows when each gallery last updated, how many images it holds, and any error. Use "Refresh all galleries" to pull immediately, or the "Refresh from ImageSnippets" button in the block's settings for a single gallery.

If that screen says a page cache is active but has not been cleared by this plugin, your host or caching plugin is serving stored HTML to visitors. Exclude the gallery pages from that cache, or ask your host how to clear it when content changes.

= Does this work with caching plugins? =

Yes. When a gallery's contents change, the plugin clears the affected pages from WP Rocket, W3 Total Cache, WP Super Cache, Cache Enabler, LiteSpeed Cache, Cachify, and SG Optimizer, and signals WordPress so other caches following core conventions clear themselves. For anything else, hook the `isg_gallery_changed` action.

== Changelog ==

= Unreleased =

* The block now uses the editor's native Color, Typography, Dimensions and Border &amp; Shadow panels (background, text and link colour; font size and line height; padding, margin and block spacing; border and shadow). Values follow the active theme's palette and spacing scale, and site owners can set defaults for the block in Styles. "Block spacing" sets the gap between images.
* A "Galleries" link on the Plugins screen leads to Tools &rarr; ImageSnippets.
* Fixed: with a crop ratio and captions on the Grid layout, images stretched past their ratio and covered the row of captions below them.
* The gallery title is now a real heading; choose its level (H1–H6, default H2) from the block toolbar.
* Crop ratio is disabled, and shows "Original", while the Masonry layout is selected, since masonry keeps each image's own proportions.
* Removed the "Justified" layout option; it had no styling of its own and rendered exactly like Grid. Saved blocks that used it fall back to Grid.
* New "Images" panel in the Styles tab: border, corner radius and shadow for the thumbnails themselves (the native Border &amp; Shadow panel styles the gallery as a whole). Radius defaults to the previous fixed 4px.
* New "Title &amp; captions" panel in the Styles tab: a switch to style them separately, with their own text colour and font size (theme presets or custom); anything left unset still follows the gallery's Color and Typography.
* The rights line under a gallery now appears whenever every image shown carries the same rights statement, rather than only when a User ID filter is set (and never when statements differ, so no image is misattributed).

= 0.4.0 =

Galleries are now mirrored into WordPress rather than fetched and cached per query.

* Each image's named graph is stored, whole and untrimmed, as a hidden post labelled with the galleries it belongs to. Pages render from the stored copy and never wait on the endpoint.
* WordPress site search finds mirrored images by title, description, and the entities they depict, and links each hit to the page that shows the gallery.
* A gallery that has never been fetched is fetched the first time its page is viewed, so existing pages upgrade with no action.
* Fetches diff against the stored copy: unchanged images are left alone, images that left a gallery are unlabelled, and images that belong to no gallery are deleted. A fetch that fails, or that returns nothing for a gallery that had images, changes nothing.
* Added `wp isg sync`, `wp isg status`, `wp isg prune`, `wp isg reindex`, and `wp isg reset` for WP-CLI.
* Tools &rarr; ImageSnippets gains a per-gallery Refresh button, the number of images stored, and "Clear stored copies".
* Site Health now flags galleries that are on published pages but have never been fetched, and galleries well past their interval.
* The mirror is removed completely on uninstall.
* Renamed the block setting "Cache for (minutes)" to "Check ImageSnippets every (minutes)"; existing values are kept.

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

= 0.4.0 =
Galleries are now stored in WordPress: pages render without waiting on ImageSnippets, and your site search finds the images. Existing pages need no changes.

= 0.3.0 =
Important fix: clearing the page cache never worked on some hosts, leaving galleries stale for visitors while looking correct to logged-in editors. Also embeds much richer image metadata for search engines.

= 0.2.1 =
Sharper gallery thumbnails.

= 0.1.0 =
Initial release.
