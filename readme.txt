=== ImageSnippets Gallery ===
Contributors: gnosyslabs
Tags: gallery, block, media, provenance, rdf
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0
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

Ten minutes by default. Developers can change this with the `isg_cache_ttl` filter.

== Changelog ==

= 0.1.0 =
* Initial release of the fork: server-side rendering, transient caching, JSON-LD output, grid/masonry/justified layouts, configurable endpoint.

== Upgrade Notice ==

= 0.1.0 =
Initial release.
