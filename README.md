# ImageSnippets Gallery

A WordPress block that displays a responsive, **server-rendered** gallery of images from [ImageSnippets](https://imagesnippets.com), with embedded provenance metadata (schema.org JSON-LD) for SEO and discoverability.

It is an independent, modernized fork of [IS Gallery](https://wordpress.org/plugins/is-gallery/) by Henry Sautter (GPL-2.0). With thanks to the original author.

## Why a fork?

The original renders entirely in the browser, so search engines, social scrapers, and AI crawlers see an empty `<div>`. This fork runs the SPARQL query **on the server**, caches the result, and emits real HTML plus a JSON-LD `ImageGallery` — so the images *and their provenance* are present in the page source.

## Features

- **Server-side rendering** with transient caching (configurable via the `isg_cache_ttl` filter).
- **Rich JSON-LD**: an `ImageGallery` of `ImageObject`s, each linked to its canonical entity (`about`) and scene location (`contentLocation`) from the ImageSnippets graph.
- **Smart text fallbacks**: alt text resolves `alt → description → title`; an optional filename fallback; an editor-only warning when images lack titles/alt text.
- **Layouts**: grid, masonry, justified; small/medium/large thumbnails.
- **Configurable crop ratio** (square, 4:3, 3:2, 16:9) to eliminate layout shift.
- Sort by date or title, ascending or descending; limit the image count.
- Optional filter by ImageSnippets user; overridable SPARQL endpoint.

## Install (for testing)

Download the latest `image-snippets-gallery-*.zip` from the [Releases](../../releases) page, then in WordPress go to **Plugins → Add New → Upload Plugin**, choose the zip, and activate. Add the **ImageSnippets Gallery** block and enter a gallery name.

## Build from source

Requires Node 18+.

```bash
npm install
npm run build      # outputs to build/
npm run start      # watch mode for development
```

For a quick local WordPress with the plugin loaded (no Docker/PHP needed):

```bash
npx @wp-now/wp-now@latest start
```

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
