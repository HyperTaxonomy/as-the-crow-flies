---
title: PHP API
parent: Developer Reference
nav_order: 1
---

# PHP API
{: .no_toc }

Functions and class methods for reading and changing locations, radius searches, postal-code centroids, directions links and the visitor's location. The `atcf_*` functions are stable wrappers meant for themes and other plugins; the `ATCF_Locations` and `ATCF_Distance` methods are what they wrap.

## Table of contents
{: .no_toc .text-delta }

1. TOC
{:toc}

---

## Locations

```php
// All locations for a term.
$locations = ATCF_Locations::get_for_term( int $term_id, string $taxonomy, array $args = [] );
// $args: country (string, ISO code filter), limit (int, 0 = all).

// Locations for several terms in one taxonomy.
$locations = ATCF_Locations::get_for_terms( array $term_ids, string $taxonomy, array $args = [] );

// Create, change or delete a location. insert() and update() return a WP_Error on failure.
$id = ATCF_Locations::insert( array $data );
// $data: term_id, taxonomy, label, address_line, address_line2, locality, region, postal_code, country, lat, lng.
$result = ATCF_Locations::update( int $id, array $data );
$deleted = ATCF_Locations::delete( int $id );

// All term IDs in a taxonomy that have at least one ATCF location. Cached (object cache,
// invalidated on atcf_location_changed; see Actions & Filters).
$term_ids = atcf_get_term_ids_with_locations( string $taxonomy );

// Local terms with a location matching any of these Schema.org Place objects, as HyperTaxonomy's
// htax_locations REST field provides them. Uses the Location Matching settings.
$term_ids = atcf_find_terms_by_location( array $schema_locations );
```

## Radius searches

```php
// Locations within a radius of a point.
$locations = ATCF_Distance::get_locations_within_radius(
    string $postal_code,   // or '' when lat/lng are in $args
    float  $radius,
    string $unit,          // 'miles' or 'km'
    array  $args           // taxonomy, term_id, term_ids[], country, lat, lng, limit, orderby
);
// Omit limit (or pass 0) for every location in the radius. orderby: 'distance', 'label', 'term_name'.

// Location rows within $radius (pass lat/lng in $args to use coordinates instead of a postal code).
$rows = atcf_get_locations_within_radius( string $postal_code, float $radius, string $unit = 'miles', array $args = [] );

// Term IDs with a location within $radius of a postal code.
$term_ids = atcf_get_term_ids_within_radius( string $postal_code, float $radius, string $unit = 'miles', string $taxonomy = '', string $country = 'US' );

// Is one location within $radius of a coordinate pair?
$near = atcf_is_within_radius( $location, float $lat, float $lng, float $radius, string $unit = 'miles' );

// Distance of a row returned by a radius query (has distance_km / distance_miles).
$miles = atcf_get_the_distance( $location, string $unit = 'miles' );     // float or null
atcf_the_distance( $location, string $unit = 'miles', string $less_than_one = '', string $one = '', string $more_than_one = '' );
// Echoes an escaped, human-readable distance ("less than a mile away", "about a mile away", "About 12 miles away"); pass the
// three strings to override the wording ($more_than_one is a sprintf pattern).
```

## Postal codes

```php
// Single centroid lookup.
$centroid = ATCF_Distance::get_centroid( string $postal_code, string $country );
// Returns ['lat' => float, 'lng' => float] or null.

// Postal code centroid, and the nearest postal code to a coordinate pair.
$centroid = atcf_get_postal_centroid( string $postal_code, string $country = 'US' );        // ['lat','lng'] or null
$nearest  = atcf_get_nearest_postal_code( float $lat, float $lng, string $country = 'US', float $max_km = 0.0 );
// Returns ['postal_code', 'country', 'distance_km'] or null.
```

## Directions

```php
// Resolve the configured directions provider.
$provider = ATCF_Distance::resolve_provider( string $pref = '' );
// Returns 'google' | 'apple' | 'waze' | 'osm' | 'geo'.

// Build a directions URL for a location.
$url = ATCF_Distance::get_directions_url( object $location, string $provider );

// Directions link for a location ('google', 'apple', 'osm', 'waze', 'geo'); '' = site setting.
$url      = atcf_get_directions_url( $location, string $provider = 'google' );
$provider = atcf_resolve_directions_provider( string $pref = '' );
```

## Visitor location

```php
// Visitor location for "near me" features — the single location chain:
// browser coordinates sent with the request (_atcf_lat / _atcf_lng) → CDN headers →
// IP geolocation → the Settings → Advanced "Fallback location". 'source' says which
// step answered; pass false to ignore request coordinates.
$loc = atcf_get_visitor_location( bool $include_request = true );
// Returns [..., 'source' => 'browser' | 'cdn' | 'ip' | 'fallback'] or null.
// Show a note when source is 'fallback' — the shortcodes and blocks do.

// IP geolocation for the current visitor: CDN location headers when present
// (atcf_get_cdn_location()), otherwise the configured provider (cached per IP).
$loc = atcf_get_ip_location();
// Returns ['lat' => float, 'lng' => float, 'country' => string, 'postal_code' => string] or null.

// Visitor location from CDN headers only (Cloudflare's CF-IPLatitude / CF-IPLongitude,
// or whatever the atcf_cdn_location filter supplies). No third party is contacted, so it
// is used even when IP geolocation is disabled.
$loc = atcf_get_cdn_location();
```

## HyperTaxonomy term groups

```php
// Expand HyperTaxonomy group IDs to member term IDs (returns [] when HT is not active).
$term_ids = atcf_expand_htax_groups( array $group_ids, string $taxonomy = '' );
// Pass '' for $taxonomy to expand across all ATCF-enabled taxonomies.
```

## Return shapes

Lookups skip locations whose term can't be resolved with `get_term()`.

`get_for_term` returns an array of **row objects** (as queried with `$wpdb->get_results` in OBJECT mode) with properties: `id`, `term_id`, `taxonomy`, `label`, `address_line`, `address_line2`, `locality`, `region`, `postal_code`, `country`, `lat`, `lng`. These rows do **not** carry distance fields.

`get_locations_within_radius` returns an array of **associative arrays** with the same location keys plus the computed fields `street_address`, `distance_km`, and `distance_miles`.
