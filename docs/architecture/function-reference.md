---
title: Function Reference
parent: Architecture
nav_order: 2
---

# Function Reference
{: .no_toc }

Functions and methods of As the Crow Flies by file, with each one's docblock summary, chosen by one rule used across the suite's plugins. Listed: the public API (documented in the [Developer Reference](../developer-reference/)) and anything called from another file, which is what contributors reuse. Not listed: hook callbacks, which the [Hook Reference](hook-reference.html) names next to their hooks; screen wiring (rendering, enqueueing, menu and column registration, constructors); overrides of a base class's method; and anything used only inside its own file or class, which is best read in the code. This page is generated from the code.

## Table of contents
{: .no_toc .text-delta }

1. TOC
{:toc}

---

## Includes

### Activation (`includes/class-activator.php`)

Plugin activation: table creation and initial data import.


**`ATCF_Activator`**

- `ATCF_Activator::reimport_us_centroids()` — Re-import US centroids from the bundled CSV file, replacing existing rows.
- `ATCF_Activator::get_centroid_count( string $country )` — Return the count of rows in the centroids table for a given country.
- `ATCF_Activator::import_geonames_file( string $country, string $tmp_path )` — Import centroids from a GeoNames TSV file for a given country. Replaces all existing rows for that country.
- `ATCF_Activator::download_and_import_census_zcta()` — Download the Census ZCTA Gazetteer zip from census.gov, extract the TSV, and import into the centroids table.
- `ATCF_Activator::import_zcta_file( string $tmp_path )` — Import US centroid data from an uploaded ZCTA CSV file. Expected columns (with or without header): postal_code, lat, lng

### Term edit panel and column (`includes/class-admin.php`)

Admin integration: term edit panel and term list column.


**`ATCF_Admin`**

- `ATCF_Admin::flush_location_caches( string $taxonomy )` — Flush the per-taxonomy location caches on atcf_location_changed.

### CSV import and export (`includes/class-csv.php`)

CSV import and export for location data.


**`ATCF_CSV`**

- `ATCF_CSV::safe_cell( $value )` — Stop a spreadsheet from treating an exported value as a formula.
- `ATCF_CSV::import_term_scoped( string $tmp_path, int $term_id, string $taxonomy, string $mode, array $conflict )` — Import a term-scoped CSV file.
- `ATCF_CSV::import_global( string $tmp_path, string $mode, array $conflict )` — Import a global CSV file (taxonomy + term_slug columns).

### Distance and postal codes (`includes/class-distance.php`)

Distance calculation, centroid lookup, coordinate validation, and public API.

- `atcf_get_term_ids_within_radius( string $postal_code, float $radius, string $unit = 'miles', string $taxonomy = '', string $country = 'US' )` — Return term IDs within radius of a postal code.
- `atcf_get_term_ids_with_locations( string $taxonomy )` — Return all term IDs in a taxonomy that have at least one ATCF location.
- `atcf_find_terms_by_location( array $schema_locations )` — Find local terms whose stored locations match any location in the given list.
- `atcf_get_visitor_location( bool $include_request = true )` — Return the visitor's location for "near me" features — the one place the location chain is defined, used by ATCF's shortcodes, blocks and REST endpoint and by HyperTaxonomy's near:me searches.


**`ATCF_Distance`**

- `ATCF_Distance::get_locations_within_radius( string $postal_code, float $radius, string $unit = 'miles', array $args = array() )` — Return full location rows within radius of a reference point.
- `ATCF_Distance::resolve_provider( string $pref = '' )` — Resolve a directions provider slug to a valid value.
- `ATCF_Distance::get_directions_url( $location, string $provider = 'google' )` — Return a directions URL for a location.
- `ATCF_Distance::the_distance( $location, string $unit = 'miles', string $less_than_one = '', string $one = '', string $more_than_one = '' )` — Return a human-readable distance string.
- `ATCF_Distance::get_nearest_postal_code( float $lat, float $lng, string $country = 'US', float $max_km = 0.0 )` — Find the nearest postal code to a coordinate pair.
- `ATCF_Distance::normalize_postal_code( string $postal_code, string $country = 'US' )` — Normalize a postal code for a given country.
- `ATCF_Distance::is_valid_coordinate( float $lat, float $lng )` — Check that lat/lng values are within valid ranges.
- `ATCF_Distance::get_centroid( string $postal_code, string $country )` — Look up a centroid by postal code and country.
- `ATCF_Distance::great_circle_distance( float $lat1, float $lng1, float $lat2, float $lng2 )` — Spherical law of cosines — great-circle distance in km between two coordinates.

### Geocoding (`includes/class-geocoder.php`)

US Census Geocoder batch integration and cron scheduling.


**`ATCF_Geocoder`**

- `ATCF_Geocoder::run_for_ids( array $ids )` — Geocode a specific set of location IDs immediately. Used by per-row, bulk, and per-term geocode actions. Only US locations are sent to the Census API; others are marked no_match.
- `ATCF_Geocoder::retry_and_run()` — Re-queue API failures and run geocoding immediately.

### HyperTaxonomy integration (`includes/class-ht-integration.php`)

HyperTaxonomy integration — term merge lifecycle.

- `atcf_expand_htax_groups( array $group_ids, string $taxonomy = '' )` — Expand HyperTaxonomy group IDs to member term IDs.


**`ATCF_HT_Integration`**

- `ATCF_HT_Integration::merge_group_terms_into_string( string $group_attr, string $term_attr, string $taxonomy )` — Merge a shortcode `group` attribute's expanded members into a `term` string.
- `ATCF_HT_Integration::merge_group_term_ids( array $term_ids, array $group_ids, string $taxonomy )` — Merge expanded HyperTaxonomy group members into a list of term IDs.
- `ATCF_HT_Integration::terms_menu_slug()` — Return the shared "Terms" top-level menu slug if one is already registered by a suite plugin (HyperTaxonomy's htax-terms or TermConnect's tc-terms), else null.

### Locations page (all terms) (`includes/class-locations-global.php`)

Global Locations admin page — shown under Terms menu.


**`ATCF_Locations_Global`**

- `ATCF_Locations_Global::get_parent_slug()` — Return the parent menu slug (htax-terms if HT is active, atcf-terms otherwise). Safe to call after admin_menu has fired.

### Locations page (one term) (`includes/class-locations-page.php`)

Per-term locations management page.


**`ATCF_Locations_Page`**

- `ATCF_Locations_Page::us_states()` — US state codes and names.
- `ATCF_Locations_Page::sortable_th( string $label, string $column, string $current, string $order, bool $hidden = false )` — Table header cell that sorts the table by its column.
- `ATCF_Locations_Page::kses_th()` — HTML allowed in sortable header cells.
- `ATCF_Locations_Page::geocode_status_label( ?string $status )` — Label for a location's geocoding status.

### Location storage (`includes/class-locations.php`)

CRUD operations for term locations.


**`ATCF_Locations`**

- `ATCF_Locations::get_for_term( int $term_id, string $taxonomy, array $args = array() )` — Get all locations for a single term.
- `ATCF_Locations::get_by_term_id( int $term_id )` — Get locations for a single term by ID only, without requiring the taxonomy.
- `ATCF_Locations::flush_term_cache( int $term_id )` — Invalidate the cached locations for a single term.
- `ATCF_Locations::flush_all_location_caches()` — Invalidate all per-term location caches. Use for operations that affect many terms without a known term ID set.
- `ATCF_Locations::get( int $id )` — Get a single location by ID.
- `ATCF_Locations::count_for_term( int $term_id, string $taxonomy )` — Count locations for a term.
- `ATCF_Locations::get_all( array $args = array() )` — Get paginated locations across all terms/taxonomies.
- `ATCF_Locations::insert( array $data )` — Insert a new location. Returns the new ID or WP_Error.
- `ATCF_Locations::update( int $id, array $data )` — Update an existing location. Returns true or WP_Error.
- `ATCF_Locations::bulk_clear_coords( array $ids )` — Clear coordinates and re-queue geocoding for a batch of location IDs. Runs a single UPDATE instead of per-row queries.
- `ATCF_Locations::delete( int $id )` — Delete a single location by ID.
- `ATCF_Locations::delete_for_term( int $term_id, string $taxonomy )` — Delete all locations for a term.
- `ATCF_Locations::normalize_address_line1( string $line )` — DIY normalization for address_line (line 1). Used when the Census-returned form is not yet available.
- `ATCF_Locations::set_normalized_address( int $id, string $normalized_street )` — Update a location's normalized address_line from the Census-returned street form.
- `ATCF_Locations::set_geocode_result( int $id, string $status, ?float $lat = null, ?float $lng = null )` — Mark a location's geocode_status and optionally set coordinates.
- `ATCF_Locations::get_pending_geocode_ids( int $limit = 9999 )` — Return IDs of pending US locations (no lat/lng, geocode_status = 'pending').
- `ATCF_Locations::count_non_us_without_coords()` — Count non-US locations that have no coordinates. Used to generate the post-import admin notice.
- `ATCF_Locations::locations_match( object $a, object $b )` — Compare two location rows and determine whether they represent the same place.
- `ATCF_Locations::delete_orphans()` — Delete location rows whose term_id no longer corresponds to a WordPress term.

### REST API (`includes/class-rest-api.php`)

REST API endpoints.


**`ATCF_REST_API`**

- `ATCF_REST_API::get_nearest_postal_code( WP_REST_Request $request )` — GET /atcf/v1/nearest-postal-code

### Shortcodes (`includes/class-shortcodes.php`)

Frontend shortcodes.


**`ATCF_Shortcodes`**

- `ATCF_Shortcodes::apply_location_template( string $tpl, array $loc, string $unit, string $provider = '' )` — Substitute {placeholder} tokens in a location item template.
