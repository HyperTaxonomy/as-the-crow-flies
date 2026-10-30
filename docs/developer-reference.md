---
title: Developer Reference
nav_order: 3
---

# Developer Reference
{: .no_toc }

The programmatic surface of As the Crow Flies: PHP functions, the `window.atcfGeo` JavaScript API, action hooks, the REST API, and `WP_Query` proximity filtering. For visitor-facing display, see [Shortcodes & Blocks](usage.html).

## Table of contents
{: .no_toc .text-delta }

1. TOC
{:toc}

---

## PHP API

```php
// All locations for a term.
$locations = ATCF_Locations::get_for_term( int $term_id, string $taxonomy, array $args = [] );
// $args: country (string, ISO code filter), limit (int, 0 = all).

// Locations within a radius of a point.
$locations = ATCF_Distance::get_locations_within_radius(
    string $postal_code,   // or '' when lat/lng are in $args
    float  $radius,
    string $unit,          // 'miles' or 'km'
    array  $args           // taxonomy, term_id, term_ids[], country, lat, lng, limit, orderby
);

// Single centroid lookup.
$centroid = ATCF_Distance::get_centroid( string $postal_code, string $country );
// Returns ['lat' => float, 'lng' => float] or null.

// Resolve the configured directions provider.
$provider = ATCF_Distance::resolve_provider( string $override = '' );
// Returns 'google' | 'apple' | 'waze' | 'osm' | 'geo'.

// Build a directions URL for a location.
$url = ATCF_Distance::get_directions_url( object $location, string $provider );

// IP geolocation for the current visitor: CDN location headers when present
// (atcf_get_cdn_location()), otherwise the configured provider (cached per IP).
$loc = atcf_get_ip_location();
// Returns ['lat' => float, 'lng' => float, 'country' => string, 'postal_code' => string] or null.

// Visitor location from CDN headers only (Cloudflare's CF-IPLatitude / CF-IPLongitude,
// or whatever the atcf_cdn_location filter supplies). No third party is contacted, so it
// is used even when IP geolocation is disabled.
$loc = atcf_get_cdn_location();

// Visitor location for "near me" features — the single location chain:
// browser coordinates sent with the request (_atcf_lat / _atcf_lng) → CDN headers →
// IP geolocation → the Settings → Advanced "Fallback location". 'source' says which
// step answered; pass false to ignore request coordinates.
$loc = atcf_get_visitor_location( bool $include_request = true );
// Returns [..., 'source' => 'browser' | 'cdn' | 'ip' | 'fallback'] or null.
// Show a note when source is 'fallback' — the shortcodes and blocks do.

// --- Template / theme functions (stable wrappers around ATCF_Distance) ---

// Term IDs with a location within $radius of a postal code.
$term_ids = atcf_get_term_ids_within_radius( string $postal_code, float $radius, string $unit = 'miles', string $taxonomy = '', string $country = 'US' );

// Location rows within $radius (pass lat/lng in $args to use coordinates instead of a postal code).
$rows = atcf_get_locations_within_radius( string $postal_code, float $radius, string $unit = 'miles', array $args = [] );

// Is one location within $radius of a coordinate pair?
$near = atcf_is_within_radius( $location, float $lat, float $lng, float $radius, string $unit = 'miles' );

// Postal code centroid, and the nearest postal code to a coordinate pair.
$centroid = atcf_get_postal_centroid( string $postal_code, string $country = 'US' );        // ['lat','lng'] or null
$nearest  = atcf_get_nearest_postal_code( float $lat, float $lng, string $country = 'US', float $max_km = 0.0 );
// Returns ['postal_code', 'country', 'distance_km'] or null.

// Directions link for a location ('google', 'apple', 'osm', 'waze', 'geo'); '' = site setting.
$url      = atcf_get_directions_url( $location, string $provider = 'google' );
$provider = atcf_resolve_directions_provider( string $pref = '' );

// Distance of a row returned by a radius query (has distance_km / distance_miles).
$miles = atcf_get_the_distance( $location, string $unit = 'miles' );     // float or null
atcf_the_distance( $location, string $unit = 'miles', string $less_than_one = '', string $one = '', string $more_than_one = '' );
// Echoes an escaped, human-readable distance ("less than a mile away", "about a mile away", "About 12 miles away"); pass the
// three strings to override the wording ($more_than_one is a sprintf pattern).

// Expand HyperTaxonomy group IDs to member term IDs (returns [] when HT is not active).
$term_ids = atcf_expand_htax_groups( array $group_ids, string $taxonomy = '' );
// Pass '' for $taxonomy to expand across all ATCF-enabled taxonomies.

// All term IDs in a taxonomy that have at least one ATCF location. Cached (object cache,
// invalidated on atcf_location_changed; see Action Hooks below).
$term_ids = atcf_get_term_ids_with_locations( string $taxonomy );
```

### Return shapes

`get_for_term` returns an array of **row objects** (as queried with `$wpdb->get_results` in OBJECT mode) with properties: `id`, `term_id`, `taxonomy`, `label`, `address_line`, `address_line2`, `locality`, `region`, `postal_code`, `country`, `lat`, `lng`. These rows do **not** carry distance fields.

`get_locations_within_radius` returns an array of **associative arrays** with the same location keys plus the computed fields `street_address`, `distance_km`, and `distance_miles`.

---

## WP_Query integration (`atcf_query`)

Any `WP_Query` (including the main query, via `pre_get_posts`) can be constrained to posts whose assigned terms have a location within a radius of a reference point. Pass a nested `atcf_query` array:

```php
$q = new WP_Query( array(
    'post_type'  => 'event',
    'atcf_query' => array(
        'postal_code' => '10001',
        'radius'      => 25,
        'unit'        => 'miles',   // 'miles' or 'km'
        'country'     => 'US',
        'taxonomy'    => 'venue',   // optional; omit to span all ATCF-enabled taxonomies
        // 'lat' / 'lng' may be supplied instead of postal_code for coordinate mode.
    ),
    'orderby'    => 'atcf_distance', // optional: sort posts by nearest matched-term distance
) );
```

**`atcf_query` parameters** (defaults shown):

| Key | Default | Description |
|-----|---------|-------------|
| `postal_code` | `''` | Reference postal code. Resolved to a centroid for the search point. |
| `lat` / `lng` | `null` | Reference coordinates (coordinate mode). When both are valid, they take precedence over `postal_code`. |
| `radius` | `10` | Search radius (clamped to a minimum of 0.1). |
| `unit` | `atcf_distance_units` option | `miles` or `km`. |
| `country` | `US` | ISO country code used for postal-code centroid lookup. |
| `taxonomy` | `''` | Restrict matched terms to one taxonomy. When empty, all matching taxonomies are included (multiple taxonomies are combined with an `OR` `tax_query`). |

**Behavior:**

- The reference point is resolved (coordinate mode if valid `lat`/`lng` are present, otherwise the postal-code centroid), all locations within the radius are collected, and their term IDs are injected as a `tax_query` clause. Any pre-existing `tax_query` on the query is preserved and combined with `relation => 'AND'`.
- When the postal code cannot be normalized, or no locations fall within the radius, the query is forced to return nothing (`post__in => [0]`).
- Setting `orderby => 'atcf_distance'` sorts results so that posts carrying nearer terms come first. Each post is ranked by the minimum distance among its matched terms (terms outside the result set are ignored, so an unrelated term assignment does not pull a post to the front).

Registered by `ATCF_WP_Query` via `pre_get_posts`, `posts_orderby`, and `the_posts` (`includes/class-wp-query.php`).

---

## JavaScript API

ATCF exposes `window.atcfGeo` (script handle `atcf-frontend`). The location helpers are the browser side of the visitor-location chain; HyperTaxonomy's `near:me` uses them too.

```js
// Browser position: the current one, otherwise the last one this device gave
// (remembered 7 days in localStorage "atcf_geo"). → Promise<{lat, lng, source:'browser'}|null>
window.atcfGeo.getBrowserLocation({ timeout: 8000 });

// Server side of the chain (CDN headers → IP geolocation → fallback location), fetched
// once per page from GET atcf/v1/visitor-location. → Promise<{lat, lng, country, postal_code, source}|null>
window.atcfGeo.getServerLocation();

// The whole chain: browser, then server. → Promise<location with source|null>
// When source is 'fallback', tell the visitor (atcfFrontend.i18n.fallbackNote).
window.atcfGeo.getVisitorLocation({ timeout: 8000 });
```

The location is looked up only when a page asks for it — never on every page view, and never written into the page's HTML, so page caches can't serve one visitor's location to another.

For plugins that render a postal code input with a geolocation button:

```js
// Reverse-geocode coordinates → { city, postalCode }, both nullable.
window.atcfGeo.reverseGeocode(lat, lng, callback);

// Wire a crosshair button to a postal input with full lifecycle management.
window.atcfGeo.initGeoBtn({
    btn:       buttonElement,       // the <button> inside the postal-wrap
    input:     inputElement,        // optional: <input> to populate with label
    label:     'Current Location',  // fallback when reverse geocode returns nothing
    onSuccess: (lat, lng, displayLabel) => { /* store coords */ },
    onDenied:  () => { /* permission denied or geolocation unsupported */ },
    onReset:   () => { /* user edited the input — coords cleared */ },
});
```

`initGeoBtn` handles: browser permission request, Nominatim reverse geocode, populating the input with the detected postal code or city name, icon loading/used/re-enable states, and re-enable-on-edit. Consumers only need to implement `onSuccess` and `onReset` to store/clear their own coordinate state.

HyperTaxonomy's `[htax_distance_field]` shortcode delegates to this API when ATCF is active.

---

## Actions

| Action | Arguments | Fired when |
|---|---|---|
| `atcf_location_changed` | `string $taxonomy` | Any location is saved, updated, deleted, or imported for a term in this taxonomy. Passes taxonomy slug; does not pass term ID. |
| `atcf_term_location_changed` | `int $term_id` | Fired from `ATCF_Locations::flush_term_cache()` (and directly by the bulk clear/delete handlers) whenever a term's locations change. The save/update/delete/import paths fire it alongside `atcf_location_changed`. Passes the specific term ID. |

Use `atcf_term_location_changed` when you need to invalidate a per-term cache. Use `atcf_location_changed` when you need to react to any location change within a taxonomy (e.g. invalidating a taxonomy-wide result set).

`atcf_location_changed`'s `$taxonomy` argument can be an empty string — not just a real slug. Several bulk operations (clearing coordinates, deleting orphaned locations, geocoding a batch, and the global admin page's "all taxonomies" clear/delete actions) span more than one taxonomy at once and fire the hook unscoped, passing `''` to mean "affects all taxonomies." A listener that assumes `$taxonomy` is always a real slug will silently skip invalidating in that case — treat an empty value as "every taxonomy", as ATCF's own built-in listener (`ATCF_Admin::flush_location_caches()`) does.

HyperTaxonomy hooks `atcf_term_location_changed` directly (to rebuild TermConnect's normalized-address URI index for the term). Integrating plugins should hook `atcf_term_location_changed` as well — it is fired for single saves/deletes and for the global bulk clear/delete operations.

---

## Filters

| Filter | Arguments | Purpose |
|---|---|---|
| `atcf_ip_headers` | `string[] $headers` | `$_SERVER` keys IP geolocation reads the visitor's IP from (default `['REMOTE_ADDR']`). |
| `atcf_cdn_location` | `array\|null $location` | Visitor location from CDN request headers (`{ lat, lng, country, postal_code }` or null). Cloudflare's headers are read by default; map another CDN's headers here. |

---

## REST API

### `GET /wp-json/atcf/v1/visitor-location`

The visitor's location from the server side of the chain — CDN headers, IP geolocation, fallback location (`atcf_get_visitor_location( false )`). Response: `{ "location": { "lat", "lng", "country", "postal_code", "source" } | null }`, sent with `Cache-Control: private, no-store`. Used by `atcfGeo.getServerLocation()`.

### `GET /wp-json/atcf/v1/locations`

| Parameter | Type | Description |
|-----------|------|-------------|
| `postal_code` | string | Reference postal code. |
| `lat` / `lng` | float | Reference coordinates (alternative to postal code). |
| `country` | string | ISO country code (default `US`). |
| `radius` | float | Search radius. |
| `unit` | string | `miles` or `km`. |
| `term` | integer | Single term ID. |
| `term_ids[]` | integer[] | Multiple term IDs (e.g. `term_ids[]=1&term_ids[]=2`). Supersedes `term` when provided. |
| `taxonomy` | string | Taxonomy slug. |
| `limit` | integer | Maximum results (default 20). |
| `orderby` | string | `distance`, `label`, or `term_name`. |
| `directions_provider` | string | `google` (default), `apple`, `osm`, `waze`, or `geo`. Provider used to build the `directions_url` in each result row. |

### `GET /wp-json/atcf/v1/nearest-postal-code`

| Parameter | Type | Description |
|-----------|------|-------------|
| `lat` | float | Latitude. |
| `lng` | float | Longitude. |
| `country` | string | ISO country code. |
| `max_km` | float | Optional. Return 404 when the nearest centroid is farther than this (km). `0`/omitted = no limit. |

Returns `{ "postal_code", "country", "distance_km" }` with the nearest centroid in the country (use `distance_km` to judge proximity), or a 404 when no centroids exist for that country or the nearest exceeds `max_km`.

**Privacy note:** Settings → Advanced → "Return location data via REST API" controls `/atcf/v1/locations` only. When unchecked, the endpoint always returns an empty `locations` array (`{"locations":[],"count":0,...}`) — callers cannot retrieve your location database via REST, though the endpoint itself stays accessible. The Location Finder and Nearest Location blocks (which read this endpoint) will show "no locations found" in that state; the Term Locations block is unaffected, since it renders server-side and never calls the REST endpoint. Shortcodes and server-side distance filtering are unaffected either way — they call `ATCF_Distance::get_locations_within_radius()` directly, a separate code path from this REST endpoint, so this setting never suppresses them. `/atcf/v1/nearest-postal-code` is a different endpoint entirely and is unaffected by this setting — it never returns location coordinates or addresses to begin with (only a postal code and a distance).

---

## External services

- **US Census Geocoder** — primary geocoder for US addresses (free, server-side, no key); batch API first, then OpenStreetMap/Nominatim fallback.
- **OpenStreetMap Nominatim** — secondary geocoder fallback when Census returns no match (free, server-side, no key).
- **IP geolocation** — approximates visitor location from IP when geolocation is unavailable (free, server-side, can be disabled in Settings → Advanced). Provider is selectable: **FreeIPAPI** (default, no stated rate limit), **ipapi.co** (1,000 req/day free), or **ipwho.is** (60 req/min free). All three require no API key. Not contacted when the site's CDN supplies the visitor's location in request headers (Cloudflare's "Add visitor location headers" managed transform).
