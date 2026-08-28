=== ImageSnippets Gallery ===
Contributors: gnosyslabs
Tags: gallery, block, media, provenance, rdf
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.7.0
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

Whether to embed the graphs is a per-block toggle (Advanced &rarr; "Include full JSON-LD for images"). It is on by default. Turned off, only the schema.org description of each image is embedded — smallest page, just what search engines read.

For a forty-image gallery the default is roughly five kilobytes once compressed.

Options:

* Filter by gallery name and (optionally) by ImageSnippets user.
* Show captions and/or the gallery title (a real heading, level of your choice).
* Grid or masonry layouts, 1–8 columns, optional uniform crop ratio.
* Sort newest or oldest first, or by title; limit the number of images.
* Style the whole gallery with the editor's native Color, Typography, Dimensions and Border panels; style the thumbnails' border, radius and shadow, and the title and captions, on their own.
* Site-wide defaults for the SPARQL endpoint and refetch rate under Tools &rarr; ImageSnippets, overridable per block (advanced).

This is an independent fork of "IS Gallery" by Henry Sautter, rebuilt for server-side rendering and structured-data output. With thanks to the original author.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/image-snippets-gallery`, or install through the Plugins screen.
2. Activate it.
3. Add the "ImageSnippets Gallery" block to a post or page and pick a gallery in the block settings. Galleries outside the main Imagesnippets datasets are listed as owner/gallery, e.g. ejw_galleries/railroad_project.

== Frequently Asked Questions ==

= Where do the images come from? =

From ImageSnippets. Any image tagged as being in the named gallery entity is shown.

= How quickly do changes on ImageSnippets reach my site? =

Each gallery is re-fetched every ten minutes by default. Change the default under Tools &rarr; ImageSnippets &rarr; Defaults, or give one gallery its own rate under the block's Advanced &rarr; "Custom refetch rate for this gallery". Set it to 0 to re-fetch on every page view. When a fetch finds changes, the affected pages are cleared from any page cache. Developers can change the default with the `isgal_cache_ttl` filter.

The Tools &rarr; ImageSnippets screen shows when each gallery was last fetched; the "Refresh" buttons there and in the block settings fetch immediately. There is also a WP-CLI command: `wp isgal sync`.

= Does this create posts on my site? =

Yes, hidden ones. Each mirrored image is stored as a post of a private type so WordPress can query and search it, but the type has no admin screen, no public URL, and does not appear in menus, sitemaps, feeds, or the REST API. Removing a gallery from your pages removes its stored images; deleting the plugin removes everything.

= My galleries do not update when I add images on ImageSnippets. =

Open Tools &rarr; ImageSnippets. It shows when each gallery last updated, how many images it holds, and any error. Use "Refresh all galleries" to pull immediately, or the "Refresh from ImageSnippets" button in the block's settings for a single gallery.

If that screen says a page cache is active but has not been cleared by this plugin, your host or caching plugin is serving stored HTML to visitors. Exclude the gallery pages from that cache, or ask your host how to clear it when content changes.

= Does this work with caching plugins? =

Yes. When a gallery's contents change, the plugin clears the affected pages from WP Rocket, W3 Total Cache, WP Super Cache, Cache Enabler, LiteSpeed Cache, Cachify, and SG Optimizer, and signals WordPress so other caches following core conventions clear themselves. For anything else, hook the `isgal_gallery_changed` action.

== Changelog ==

= 0.7.0 =

* Timeline layout: images grouped under year headings down a rule, oldest or newest first, each with its date.
* Dates now come from the best source an image has rather than always the catalogue date. By default the camera's capture time (EXIF DateTimeOriginal) wins, then file-creation time, then the IPTC/Photoshop date, then a year in the rights statement; each block can reorder these under Advanced → "Date comes from". The order applies to sorting, captions, the lightbox, the timeline and the date on site-search results. Captions and the lightbox say which field a date came from.
* Lightbox. Set "Clicking an image" to open a lightbox on the page instead of the ImageSnippets page: keyboard arrows, swipe, Esc, and a provenance panel with the creator, date, rights, tags and a link to the source. Everything it shows is in the page already, so opening an image makes no request. A link to an image (the ones site search produces) opens it in the lightbox straight away. Built on WordPress's own Interactivity API; no library bundled. Requires WordPress 6.5.
* "Open in a new tab" is now a choice rather than always on.
* Captions can now carry more than the title: choose any of title, creator, date, rights and tags, in the order you want, per block. The lines come from each image's ImageSnippets record — the photographer, when it was made, who holds the rights, what it shows — so the provenance is on the page, not only in the metadata.
* Captions can sit below the image, over it, or over it only on hover; overlaid captions have their own background colour in the Styles tab.
* Hover effects: zoom, fade or lift. Off by default; all three respect the visitor's reduced-motion setting.
* New layout: Justified rows. Every image keeps its own proportions and each row comes out the same height, edge to edge, so mixed portrait and landscape galleries look intended. Rows are sized on the server from the image dimensions ImageSnippets records, so nothing shifts as the pictures load and no script is needed.
* Galleries can now be arranged by hand. Tools → ImageSnippets lists an Arrange button per gallery: drag the images into order (or use the earlier/later buttons), save, and set the block's Sort by to Manual. The order is kept on this site by image, so refreshing from ImageSnippets keeps it; images added on ImageSnippets later appear after the arranged ones, newest first, until they are placed.
* Internal: everything the plugin stores and every hook and class it exposes now uses the `isgal` prefix instead of `isg` (a wordpress.org requirement). Existing settings and arranged orders carry over; mirrored galleries are rebuilt from ImageSnippets on the next visit. If you had hooked `isg_gallery_changed` or `isg_cache_ttl`, rename them. WP-CLI is now `wp isgal`.
* Deactivating the plugin now cancels its scheduled background refreshes.

= 0.6.0 =

* Search results for images now carry a date, in whatever style and position the theme puts it. Block themes read the date through a binding that refuses posts which are not publicly viewable, and mirrored images deliberately are not, so every other result on the page had a date and these did not.
* Themes that show an author on search results now show the photographer, taken from the image's provenance rather than from a WordPress user account.
* Fixed: images in site search results rendered at their natural size and overlapped the result title. The thumbnail a mirrored image supplies now carries everything WordPress would have supplied for a real featured image — the theme's requested size and layout, the standard image classes, real width and height, and a srcset where the source offers one — so themes lay these out exactly as they lay out any other post's featured image.
* Search results now show the image's description instead of the list of keywords and entity labels the search index is built from. Those keywords still match: searching one finds the image as before, it is just no longer printed as the result text.
* Site search results for images now link to the image that matched, not just to the gallery page it sits on. A search matching several images in one gallery used to return several results that all led to the same page, leaving the visitor to find the image among hundreds; each result now scrolls to its own image and marks it briefly.
* The Structured data dropdown (schema.org only / Provenance / Full graph) is now a single toggle, "Include full JSON-LD for images", on by default. Off embeds only the schema.org description. The Full graph profile — which added only the ImageSnippets page's Open Graph and Twitter tags — is no longer offered in the editor; the `isgal_jsonld_payload` filter still receives the full graph. Blocks already saved with a profile keep working.
* The Gallery setting is now a picker: it lists every gallery on ImageSnippets with its image count, searchable as you type. Galleries outside the main Imagesnippets datasets appear as owner/gallery. A name that is not on the list can still be typed. The list is fetched when the block settings open and cached for 15 minutes.

= 0.5.2 =

* Fixed: an image whose ImageSnippets date is only a year (for example "2009") was mirrored but not shown, because that date was read as a time of day later today. The gallery now shows every image the refresh counts; the next sync repairs images already affected.

= 0.5.1 =

* Galleries outside the main Imagesnippets datasets can now be shown: enter the gallery as owner/gallery (for example ejw_galleries/railroad_project). A bare name still means the Imagesnippets datasets, so existing blocks are unchanged.

= 0.5.0 =

A block-settings overhaul: the gallery now feels like a core block.

* The block now uses the editor's native Color, Typography, Dimensions and Border &amp; Shadow panels (background, text and link colour; font size and line height; padding, margin and block spacing; border and shadow). Values follow the active theme's palette and spacing scale, and site owners can set defaults for the block in Styles. "Block spacing" sets the gap between images.
* A "Galleries" link on the Plugins screen leads to Tools &rarr; ImageSnippets.
* Fixed: with a crop ratio and captions on the Grid layout, images stretched past their ratio and covered the row of captions below them.
* The gallery title is now a real heading; choose its level (H1–H6, default H2) from the block toolbar.
* Crop ratio is disabled, and shows "Original", while the Masonry layout is selected, since masonry keeps each image's own proportions.
* Removed the "Justified" layout option; it had no styling of its own and rendered exactly like Grid. Saved blocks that used it fall back to Grid.
* New "Images" panel in the Styles tab: border, corner radius and shadow for the thumbnails themselves (the native Border &amp; Shadow panel styles the gallery as a whole). Radius defaults to the previous fixed 4px.
* New "Title &amp; captions" panel in the Styles tab: a switch to style them separately, with their own text colour and font size (theme presets or custom); anything left unset still follows the gallery's Color and Typography.
* The rights line under a gallery now appears whenever every image shown carries the same rights statement, rather than only when a User ID filter is set (and never when statements differ, so no image is misattributed).
* "Thumbnail size" is replaced by "Columns" (1–8, default 3), the way the core Gallery block works; phones show at most two. Existing blocks render three columns.
* "Order by" and "Order" are merged into one "Sort by" control (Newest first, Oldest first, Title A→Z, Title Z→A). Nothing changes in saved blocks.
* Site defaults: Tools &rarr; ImageSnippets now has a Default SPARQL endpoint and a Default refetch rate that every gallery block follows unless it sets its own under Advanced, plus a "Reset all galleries to defaults" button that removes per-block overrides.
* Block settings are regrouped: Source (gallery, sort, maximum, refresh), Layout (layout, columns, crop ratio), Title &amp; captions, and Advanced (which now also holds the structured-data detail).
* Clicking an image opens its ImageSnippets page in a new tab, so visitors keep the gallery open.
* The block's Advanced section shows the site default endpoint as a placeholder, and the refetch rate is a switch ("Custom refetch rate for this gallery") that reveals the slider only when overriding. "Use filename when title is missing" moved to Advanced.

= 0.4.0 =

Galleries are now mirrored into WordPress rather than fetched and cached per query.

* Each image's named graph is stored, whole and untrimmed, as a hidden post labelled with the galleries it belongs to. Pages render from the stored copy and never wait on the endpoint.
* WordPress site search finds mirrored images by title, description, and the entities they depict, and links each hit to the page that shows the gallery.
* A gallery that has never been fetched is fetched the first time its page is viewed, so existing pages upgrade with no action.
* Fetches diff against the stored copy: unchanged images are left alone, images that left a gallery are unlabelled, and images that belong to no gallery are deleted. A fetch that fails, or that returns nothing for a gallery that had images, changes nothing.
* Added `wp isgal sync`, `wp isgal status`, `wp isgal prune`, `wp isgal reindex`, and `wp isgal reset` for WP-CLI.
* Tools &rarr; ImageSnippets gains a per-gallery Refresh button, the number of images stored, and "Clear stored copies".
* Site Health now flags galleries that are on published pages but have never been fetched, and galleries well past their interval.
* The mirror is removed completely on uninstall.
* Renamed the block setting "Cache for (minutes)" to "Check ImageSnippets every (minutes)"; existing values are kept.

= 0.3.0 =

Galleries now update on their own, and the metadata embedded in the page is far richer.

Freshness:

* Galleries clear the page cache when their contents change, so updates reach visitors instead of sitting behind stored HTML. Works with the common caching plugins by detection, and exposes an `isgal_gallery_changed` action for anything else.
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

= 0.6.0 =
Images now render properly in site search results — correctly sized, with a description, a date, and a link straight to the image that matched. The gallery name is also chosen from a searchable list, with image counts. Existing pages need no changes.

= 0.5.2 =
Fixes images with year-only dates being counted but not shown. Refresh each gallery once after updating.

= 0.5.1 =
Galleries owned by other ImageSnippets accounts can now be shown by naming them owner/gallery. Existing pages need no changes.

= 0.5.0 =
Settings overhaul: Columns replaces thumbnail size (existing galleries render three columns), the title is a real heading, thumbnails and captions can be styled on their own, and site-wide defaults live under Tools &rarr; ImageSnippets. Existing pages need no changes.

= 0.4.0 =
Galleries are now stored in WordPress: pages render without waiting on ImageSnippets, and your site search finds the images. Existing pages need no changes.

= 0.3.0 =
Important fix: clearing the page cache never worked on some hosts, leaving galleries stale for visitors while looking correct to logged-in editors. Also embeds much richer image metadata for search engines.

= 0.2.1 =
Sharper gallery thumbnails.

= 0.1.0 =
Initial release.
