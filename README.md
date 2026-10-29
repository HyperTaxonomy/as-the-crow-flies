# As the Crow Flies

A WordPress plugin that attaches physical locations (addresses or coordinates) to taxonomy terms and lets visitors filter content by proximity or distance between locations — no external map API required.

## Features

- Attach one or more locations to any taxonomy term
- Three Gutenberg blocks: Location Finder, Term Locations, Nearest Location
- Three shortcodes: `[atcf_locations]`, `[atcf_terms]`, `[atcf_nearby]`
- Filter posts by distance using a postal code or browser geolocation
- Automatic geocoding of US addresses via the Census Geocoder with OpenStreetMap/Nominatim fallback
- US Zip Code centroids bundled — no external geocoding API needed for basic use
- International support — upload GeoNames postal code files for any country
- Browser geolocation support — no postal code entry needed
- IP-based approximate location as a fallback (FreeIPAPI, ipapi.co, or ipwho.is — no key required)
- CSV import/export for bulk location management
- `WP_Query` integration via nested `atcf_query` parameter
- REST API for headless and JavaScript use: `/atcf/v1/locations`, `/atcf/v1/nearest-postal-code`
- Privacy control to suppress location data in API responses while keeping server-side distance filtering fully functional
- Optional integration with [HyperTaxonomy](https://hypertaxonomy.org) for term group support within shortcodes and blocks
- Provides distance filtering within HyperTaxonomy's PowerSearch and Search Widget
- Preserves locations when HyperTaxonomy merges terms — distinct locations from merged-away terms move to the surviving term

## Requirements

- WordPress 5.8+
- PHP 7.4+

## Installation

1. Install from the [WordPress.org plugin directory](https://wordpress.org/plugins/as-the-crow-flies/), or download the zip from [GitHub Releases](https://github.com/HyperTaxonomy/as-the-crow-flies/releases)
2. Upload via **Plugins → Add New → Upload Plugin**
3. Activate, then go to **Settings → As the Crow Flies → General** to enable taxonomies, set default distance units, and configure location matching

## Building block assets

Block editor JS requires a build step:

```bash
npm install
npm run build        # production build
npm run start        # watch mode for development
```

Compiled assets (`blocks/*/build/`) are committed to the repository and included in release zips, so end users don't need to run a build.

## Packaging a release zip

```bash
bash utils/package.sh          # zip using existing build output
bash utils/package.sh --build  # rebuild blocks first, then zip
```

Output: `dist/as-the-crow-flies.zip`

## Documentation

Full documentation is published at [hypertaxonomy.org/as-the-crow-flies](https://hypertaxonomy.org/as-the-crow-flies) (source in [`docs/`](docs/)):

- **[Shortcodes & Blocks](docs/usage.md)** — the three shortcodes and three blocks with full attribute and inspector tables, item-template tokens, output formats, directions providers, and CSS classes.
- **[Developer Reference](docs/developer-reference.md)** — the PHP API, the `window.atcfGeo` JavaScript API, actions, the REST API, and `WP_Query` `atcf_query` integration.
- **[HyperTaxonomy Integration](docs/hypertaxonomy-integration.md)** — term group expansion, PowerSearch / Search Widget distance filtering, and term-merge location preservation.

The shortcode and block reference is also published in [readme.txt](readme.txt) and on the WordPress.org plugin page.

## Display approaches

**Shortcode / block** — insert `[atcf_locations]` or the Term Locations block anywhere in a post to pull location data from any term, regardless of that post's own taxonomy assignments. Useful when the displayed locations and the filtering/search terms don't need to match, or when authors control placement through the editor.

**Custom taxonomy + theme template** — create a taxonomy specifically for your location entities (e.g. "Branches", "Offices"), enable it in settings, and attach addresses to its terms. Theme templates then retrieve locations automatically from the post's assigned terms via `get_the_terms()` + `ATCF_Locations::get_for_term( $term->term_id, $taxonomy )`, with no shortcode required. Suits sites where location data is a structural property of the content rather than an editorial choice.

Both approaches use the same underlying data model and distance-query engine — the difference is only in how and where addresses are rendered.

## PHP API

Static methods on `ATCF_Locations` and `ATCF_Distance` for retrieving a term's locations, querying by radius, resolving centroids and directions URLs, IP geolocation, and expanding HyperTaxonomy groups.

```php
$locations = ATCF_Locations::get_for_term( $term_id, $taxonomy );
$nearby    = ATCF_Distance::get_locations_within_radius( $postal_code, $radius, $unit, $args );
```

For all methods, return shapes, and parameters, see [Developer Reference → PHP API](https://hypertaxonomy.org/as-the-crow-flies/developer-reference.html#php-api).

## REST API

`GET /wp-json/atcf/v1/locations` — locations within a radius, with taxonomy/term filtering, sorting, and a privacy option to suppress coordinates/addresses. `GET /wp-json/atcf/v1/nearest-postal-code` — resolve the nearest centroid to a coordinate.

For all parameters, see [Developer Reference → REST API](https://hypertaxonomy.org/as-the-crow-flies/developer-reference.html#rest-api).

## JavaScript API

`window.atcfGeo` provides `reverseGeocode()` and `initGeoBtn()` for plugins that render their own postal code input with a geolocation button — HyperTaxonomy's `[htax_distance_field]` uses this API.

For the full API and lifecycle handling, see [Developer Reference → JavaScript API](https://hypertaxonomy.org/as-the-crow-flies/developer-reference.html#javascript-api).

## Actions

| Action | Arguments | Fired when |
|---|---|---|
| `atcf_location_changed` | `string $taxonomy` | Any location is saved, updated, deleted, or imported for a term in this taxonomy. Passes taxonomy slug; does not pass term ID. |
| `atcf_term_location_changed` | `int $term_id` | Same as above, fired alongside `atcf_location_changed` from within `ATCF_Locations::flush_term_cache()`. Passes the specific term ID. |

Use `atcf_term_location_changed` when you need to invalidate a per-term cache. Use `atcf_location_changed` when you need to react to any location change within a taxonomy (e.g. invalidating a taxonomy-wide result set).

HyperTaxonomy hooks `atcf_term_location_changed` directly (to rebuild TermConnect's normalized-address URI index for the term). Integrating plugins should hook `atcf_term_location_changed` as well — it is fired for single saves/deletes and for the global bulk clear/delete operations.

## External services

- **US Census Geocoder** — primary geocoder for US addresses (free, server-side, no key); batch API first, then OpenStreetMap/Nominatim fallback
- **OpenStreetMap Nominatim** — secondary geocoder fallback when Census returns no match (free, server-side, no key)
- **IP geolocation** — approximates visitor location from IP when geolocation is unavailable (free, server-side, can be disabled in Settings → Advanced). Provider is selectable: **FreeIPAPI** (default, no stated rate limit), **ipapi.co** (1,000 req/day free), or **ipwho.is** (60 req/min free). All three require no API key.

## Translations

As the Crow Flies is translation-ready and ships with a complete translation template: `languages/as-the-crow-flies.pot`.

To contribute a translation: copy the template to `languages/as-the-crow-flies-<locale>.po`, translate it, and open a pull request — the build compiles it (and, for block-editor strings, generates the required JSON) automatically. See `utils/make-pot.sh` and `utils/make-json.py`.

## License

GPLv2 or later — see [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html)
