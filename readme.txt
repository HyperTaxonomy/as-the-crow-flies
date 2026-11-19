=== As the Crow Flies – Proximity Filtering ===
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
* Optional IP-based approximate location as a fallback (off by default) — choose from FreeIPAPI, ipapi.co, or ipwho.is (all free, no key required)
* CSV import/export for bulk location management
* WP_Query integration with nested `atcf_query` parameter
* REST API for headless and JavaScript use: `/atcf/v1/locations`, `/atcf/v1/visitor-location`, `/atcf/v1/nearest-postal-code`
* Privacy control to disable location data in REST API responses — shortcodes and server-side distance filtering are unaffected either way
* Optional integration with HyperTaxonomy for term group support within shortcodes and blocks
* Provides distance filtering within HyperTaxonomy's PowerSearch and Search Widget
* Keeps locations when HyperTaxonomy merges or converts terms — on the redirect stubs, or moved or copied to the remaining or new term

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
4. Edit taxonomy terms to add location data, or use the CSV import. See [Locations & Settings](https://hypertaxonomy.org/as-the-crow-flies/user-guide/locations-settings.html)

== Shortcodes ==

Three shortcodes for displaying and filtering locations by proximity:

* `[atcf_locations]` — Location Finder: locations within a radius of the visitor (or an interactive postal code / geolocation form with `form="yes"`)
* `[atcf_terms]` — Term Finder: taxonomy terms with at least one location within a radius, sorted by nearest
* `[atcf_nearby]` — Nearby Terms: terms whose locations are within a radius of a reference term, postal code, or the visitor's location

**Example:**
```
[atcf_locations postal_code="10001" country="US" radius="25" unit="miles"]
```

For all attributes, output formats, item templates, and more examples, see [Shortcodes & Blocks](https://hypertaxonomy.org/as-the-crow-flies/user-guide/shortcodes-blocks.html#shortcodes).

== Blocks ==

Three Gutenberg blocks, available in the block inserter under **As the Crow Flies**: **Location Finder** (interactive postal code / geolocation search form, equivalent to `[atcf_locations form="yes"]`), **Term Locations** (static list of a term's locations, no shortcode equivalent), and **Nearest Location** (single nearest location, resolved client-side via browser geolocation with IP fallback).

For all inspector panel settings, see [Shortcodes & Blocks](https://hypertaxonomy.org/as-the-crow-flies/user-guide/shortcodes-blocks.html#blocks).

== HyperTaxonomy Integration ==

When [HyperTaxonomy](https://hypertaxonomy.org) is active, As the Crow Flies gains: automatic Term Group expansion in shortcodes and blocks (a group ID expands to its member terms at render time), the `near:` proximity operator in PowerSearch and the Search Widget, and keeping locations when HyperTaxonomy merges or converts terms. None of this requires configuration beyond enabling the relevant taxonomies in As the Crow Flies settings.

```
[atcf_locations group="7" radius="10"]
```

For full details, see [Working with HyperTaxonomy](https://hypertaxonomy.org/as-the-crow-flies/user-guide/working-with-hypertaxonomy.html).

== Output Formatting ==

All three shortcodes accept a `format` attribute, and the Location Finder and Term Locations blocks have an **Output style** setting, which control the wrapper element:

| Value | Shortcode | Block label | Output |
|-------|-----------|-------------|--------|
| `ul` | `format="ul"` | Unordered list | `<ul>` with `<li>` items (default) |
| `ol` | `format="ol"` | Ordered list | `<ol>` with `<li>` items |
| `div` | `format="div"` | Div | `<div>` wrapper with `<div>` items |
| `table` | `format="table"`, in `[atcf_locations]` only | Table | `<table>` with a header row (Label, Address, Distance, Directions) |
| `json` | `format="json"`, in `[atcf_locations]` only | — | `<script type="application/json">` data blob |

The `table` format renders a fixed four-column layout. For full control over the per-row markup, use an `item_template` with any list format.

== Item Templates ==

All three shortcodes (`item_template` attribute) and all three blocks (**Item template** setting) support custom per-row output using `{placeholder}` tokens for location fields (`{label}`, `{address_line}`, `{city}`, `{distance}`, `{directions}`, etc.) and, for term-based shortcodes, `{term_name}` / `{term_url}`.

**Example:**
```
{label} — {address_line}, {city}, {region} {postal_code}
```

For the complete token reference, see [Shortcodes & Blocks](https://hypertaxonomy.org/as-the-crow-flies/user-guide/shortcodes-blocks.html#item-templates).

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
* The default "Directions" link in the Location Finder and Nearest Location blocks

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

For the complete class reference, see [Shortcodes & Blocks](https://hypertaxonomy.org/as-the-crow-flies/user-guide/shortcodes-blocks.html#css-classes).

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

The PHP and REST code recipe for retrieving a term's locations from a theme template is documented in the [Developer Reference → PHP API](https://hypertaxonomy.org/as-the-crow-flies/developer-reference/php-api.html).

== External services ==

As the Crow Flies contacts these services only for the features described. Nothing is sent on ordinary page views.

* **US Census Geocoder** (geocoding.geo.census.gov) — converts US addresses to coordinates. The plugin sends location addresses (street, city, state, ZIP code) from the site's server, in the hourly geocoding run or when an administrator clicks Geocode. No visitor data is sent. [Terms of Service](https://www.census.gov/data/developers/terms-of-service.html), [Privacy Policy](https://www.census.gov/about/policies/privacy/privacy-policy.html)
* **US Census Bureau downloads** (www2.census.gov) — when an administrator clicks the button to download current ZIP code data on the Postal Codes settings tab, the plugin downloads the public ZCTA Gazetteer file. Nothing else is sent. Same terms and privacy policy as above.
* **OpenStreetMap Nominatim** (nominatim.openstreetmap.org) — used in two ways. From the site's server, it geocodes addresses the Census Geocoder can't match, sending the location's address. From the visitor's browser, when the visitor clicks the "use my location" button on a location form or distance field, it looks up the city or postal code for the visitor's coordinates to show in the form; the browser sends those coordinates. [Usage Policy](https://operations.osmfoundation.org/policies/nominatim/), [Privacy Policy](https://osmfoundation.org/wiki/Privacy_Policy)
* **IP geolocation provider** — approximates the visitor's location when a "near me" shortcode, block or search needs it and the browser doesn't provide one. The site's server sends the visitor's IP address to the provider selected in Settings → As the Crow Flies → Advanced. IP geolocation is off by default; it is used only after an administrator turns it on there. The provider is not contacted on other page views, when IP geolocation is turned off, or when the site's CDN supplies the visitor's location in request headers (Cloudflare's "Add visitor location headers" managed transform). Results are cached per IP address for the Cache TTL setting (one day by default).
  * **FreeIPAPI** (freeipapi.com, default): [Terms](https://freeipapi.com/terms), [Privacy Policy](https://freeipapi.com/privacy)
  * **ipapi.co**: [Terms of Service](https://ipapi.co/terms/), [Privacy Policy](https://ipapi.co/privacy/)
  * **ipwho.is** (operated by ipwhois.io): [Terms of Service](https://ipwhois.io/terms), [Privacy Policy](https://ipwhois.io/privacy)

An optional **Fallback location** (Settings → As the Crow Flies → Advanced) is used by "near me" features — this plugin's shortcodes and blocks, and HyperTaxonomy `near:me` searches — when no visitor location can be determined; visitors are told when results are based on it. It involves no external service.

Directions links (Google Maps, Apple Maps, Waze, OpenStreetMap) are ordinary links: nothing is sent to those services unless a visitor follows one.

== Screenshots ==

1. Location Finder block in action on the frontend
2. Distance filtering in action on the frontend, from a postal code or browser geolocation
3. Per-term locations management page with add/edit/delete
4. Terms → Term Locations — every term's locations in one table, filtered by taxonomy, place, coordinates and geocoding status, with bulk CSV import
5. Term list with the Locations column showing each term's location count
6. As the Crow Flies blocks in the post editor — a Term Locations block with its settings and live preview
7. Plugin settings — General tab
8. Settings → Postal Codes — uploading a GeoNames file to enable international postal code support

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
