=== As the Crow Flies – Proximity filtering for WordPress - attach locations to taxonomy terms, filter by distance, zip code, or browser geolocation ===
Tags: proximity, location, distance, radius, zip, map, search, filter, taxonomy, terms, geolocation, postal code
Contributors: eugenevv
Author: Eugene Weinstein
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

No map API needed. Works worldwide; supports US zip codes and international postal codes. Distance to nearest of multiple locations per term.

== Description ==

As the Crow Flies lets you attach physical locations (addresses and/or coordinates) to taxonomy terms and filter WordPress content by distance from any location.

**Key features:**

* Attach one or more locations to any taxonomy term
* Three Gutenberg blocks: Location Finder, Term Locations, Nearest Location
* Three shortcodes: [atcf_locations], [atcf_terms], [atcf_nearby]
* Filter posts by distance using a postal code or browser geolocation
* Automatic geocoding of US addresses via the Census Geocoder with OpenStreetMap/Nominatim fallback
* US Zip Code centroids bundled — no external geocoding API needed for basic use
* International support — upload GeoNames postal code data for any country
* Browser geolocation support — no postal code entry needed
* IP-based approximate location as a fallback — choose from FreeIPAPI, ipapi.co, or ipwho.is (all free, no key required)
* CSV import/export for bulk location management
* WP_Query integration with nested `atcf_query` parameter
* REST API for headless and JavaScript use: `/atcf/v1/locations`, `/atcf/v1/nearest-postal-code`
* Privacy control to disable location data in REST API responses — shortcodes and server-side distance filtering are unaffected either way
* Optional integration with HyperTaxonomy for term group support within shortcodes and blocks
* Provides distance filtering within HyperTaxonomy's PowerSearch and Search Widget
* Preserves locations when HyperTaxonomy merges terms — distinct locations from merged-away terms move to the surviving term

**Use cases:**

* Store locators: find stores that carry specific inventory within 25 miles
* Product reviews: show an always-up-to-date list of locations
* Event finders: show events happening near the visitor
* Directory sites: filter directory entries by proximity
* Any taxonomy-driven site where location matters

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/as-the-crow-flies/`
2. Activate the plugin through the **Plugins** screen
3. Go to **Settings → As the Crow Flies → General** to enable taxonomies, set default distance units, and configure location matching
4. Edit taxonomy terms to add location data, or use the CSV import

== Shortcodes ==

Three shortcodes for displaying and filtering locations by proximity:

* `[atcf_locations]` — Location Finder: locations within a radius of the visitor (or an interactive postal code / geolocation form with `form="yes"`)
* `[atcf_terms]` — Term Finder: taxonomy terms with at least one location within a radius, sorted by nearest
* `[atcf_nearby]` — Nearby Terms: terms whose locations are within a radius of a reference term, postal code, or the visitor's location

**Example:**
```
[atcf_locations postal_code="10001" country="US" radius="25" unit="miles"]
```

For all attributes, output formats, item templates, and more examples, see [Shortcodes & Blocks](https://hypertaxonomy.org/as-the-crow-flies/usage.html#shortcodes).

== Blocks ==

Three Gutenberg blocks, available in the block inserter under **As the Crow Flies**: **Location Finder** (interactive postal code / geolocation search form, equivalent to `[atcf_locations form="yes"]`), **Term Locations** (static list of a term's locations, no shortcode equivalent), and **Nearest Location** (single nearest location, resolved client-side via browser geolocation with IP fallback).

For all inspector panel settings, see [Shortcodes & Blocks](https://hypertaxonomy.org/as-the-crow-flies/usage.html#blocks).

== HyperTaxonomy Integration ==

When [HyperTaxonomy](https://hypertaxonomy.org) is active, ATCF gains: automatic Term Group expansion in shortcodes and blocks (a group ID expands to its member terms at render time), the `near:` proximity operator in PowerSearch and the Search Widget, and location preservation when HyperTaxonomy merges terms. None of this requires configuration beyond enabling the relevant taxonomies in ATCF settings.

```
[atcf_locations group="7" radius="10"]
```

For full details, see [HyperTaxonomy Integration](https://hypertaxonomy.org/as-the-crow-flies/hypertaxonomy-integration.html).

== Output Formatting ==

All three shortcodes and the Location Finder and Nearest Location blocks accept a `format` attribute (shortcodes) or **Output style** setting (blocks) that controls the wrapper element:

| Value | Shortcode | Block label | Output |
|-------|-----------|-------------|--------|
| `ul` | `format="ul"` | Unordered list | `<ul>` with `<li>` items (default) |
| `ol` | `format="ol"` | Ordered list | `<ol>` with `<li>` items |
| `div` | `format="div"` | Div | `<div>` wrapper with `<div>` items |
| `table` | `format="table"` | Table | `<table>` with a header row (Label, Address, Distance, Directions) |
| `json` | `format="json"` | — | `<script type="application/json">` data blob; shortcode only |

The `table` format renders a fixed four-column layout. For full control over the per-row markup, use an `item_template` with any list format.

== Item Templates ==

All three shortcodes (`item_template` attribute) and all three blocks (**Item template** setting) support custom per-row output using `{placeholder}` tokens for location fields (`{label}`, `{address_line}`, `{city}`, `{distance}`, `{directions}`, etc.) and, for term-based shortcodes, `{term_name}` / `{term_url}`.

**Example:**
```
{label} — {address_line}, {city}, {region} {postal_code}
```

For the complete token reference, see [Shortcodes & Blocks](https://hypertaxonomy.org/as-the-crow-flies/usage.html#item-templates).

== Directions ==

**Settings → As the Crow Flies → Advanced → Directions** sets the site-wide default directions provider:

| Setting value | Provider |
|---------------|----------|
| `google` (default) | Google Maps |
| `apple` | Apple Maps |
| `waze` | Waze |
| `osm` | OpenStreetMap |

This controls:

* The `{directions_url}`, `{directions}`, and `{directions_link_start}` template tokens, which resolve to the configured provider's URL
* The default "Directions" link rendered when no `item_template` is set
* The `directions_url` field returned by the REST API

Per-provider tokens (`{directions_url_google}`, `{directions_url_apple}`, etc.) are always available regardless of this setting.

**Per-shortcode override** — pass a `directions_provider` attribute to any shortcode:

```
[atcf_locations postal_code="10001" directions_provider="apple"]
[atcf_nearby term="42" directions_provider="waze"]
```

**Per-block override** — each block's inspector panel includes a **Directions provider** dropdown. Choose **Site default** to inherit from Settings.

== CSS Classes ==

All three shortcodes accept a `class` attribute; all three blocks use WordPress's built-in **Additional CSS class(es)** field. Default classes (`.atcf-location`, `.atcf-term`, `.atcf-distance`, `.atcf-directions`, etc.) are applied to shortcode and block output for styling.

```
[atcf_locations postal_code="10001" class="store-finder compact"]
```

For the complete class reference, see [Shortcodes & Blocks](https://hypertaxonomy.org/as-the-crow-flies/usage.html#css-classes).

== Frequently Asked Questions ==

= Does this require a Google Maps API key? =

No. US Zip Code centroids are bundled with the plugin. For other countries, upload a free GeoNames postal code file in the Postal Codes settings tab. No external API key is required for distance calculation.

= How accurate is the distance calculation? =

Great-circle distance is calculated using the spherical law of cosines, which treats the Earth as a perfect sphere. This is accurate to well within 1% for any practical radius — suitable for filtering within a radius, not for precise navigation.

When explicit coordinates are not provided, distance is measured from the postal code centroid (the geographic center of the postal code area), which introduces additional approximation depending on the size of the postal code area.

= Does it work with non-US postal codes? =

Yes. Upload GeoNames postal code files (free download from geonames.org) for any country in **Settings → Postal Codes**. Locations with explicit lat/lng coordinates work worldwide without any centroid data.

= Can location data appear automatically in post templates without a shortcode? =

Yes — this is a natural fit for a dedicated custom taxonomy.

Create a custom taxonomy for your location entities (e.g. "Branches", "Stores", "Offices"), enable it in **Settings → As the Crow Flies → General**, and attach addresses to its terms. When a post is assigned a term from that taxonomy, a theme template can read the post's terms and render their locations directly — no shortcode required. This suits sites where location data is a structural property of the content rather than an editorial choice, for example a staff directory where every "Staff Member" post shows the office addresses associated with that person's "Office" terms.

The PHP and REST code recipe for retrieving a term's locations from a theme template is documented in the [Developer Reference → PHP API](https://hypertaxonomy.org/as-the-crow-flies/developer-reference.html#php-api).

= What external services does this plugin use? =

* **US Census Geocoder** (geocoding.geo.census.gov) — primary geocoder for US addresses; batch API first, then OpenStreetMap/Nominatim fallback. Free, no API key required. Called server-side only.
* **OpenStreetMap Nominatim** (nominatim.openstreetmap.org) — secondary geocoder fallback when Census returns no match. Free, no API key required. Called server-side only.
* **IP geolocation provider** — used to approximate visitor location from IP address when browser geolocation is unavailable. Sends the visitor's IP address to the selected provider's servers — only when a page actually needs the visitor's location (a "near me" shortcode, block or search), never on every page view. Provider is selectable in Settings → Advanced: **FreeIPAPI** (freeipapi.com, default), **ipapi.co**, or **ipwho.is**. All three are free and require no API key. Can be disabled entirely in Settings → Advanced. Behind Cloudflare with the "Add visitor location headers" managed transform on, the visitor's location is read from Cloudflare's request headers instead and no provider is contacted. An optional **Fallback location** (Settings → Advanced) is used by "near me" features — this plugin's shortcodes and blocks and HyperTaxonomy `near:me` searches — when no visitor location can be determined; visitors are told when results are based on it.

== Screenshots ==

1. Per-term locations management page with add/edit/delete
2. Term list with Locations column showing location counts
3. Location Finder Gutenberg block configuration in post editor
4. Location Finder block in action on the frontend
5. Distance filtering in action on the frontend, from a postal code or browser geolocation
6. Plugin settings — General tab
7. Settings → Postal Codes — uploading a GeoNames file to enable international postal code support

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
