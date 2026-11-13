---
title: Function Reference
parent: Architecture
nav_order: 2
---

# Function Reference
{: .no_toc }

As the Crow Flies's functions and public and protected methods, by file, with each one's docblock summary. Private methods aren't listed. This page is generated from the code. For what other plugins and themes should call, see the [Developer Reference](../developer-reference/); for hook registrations, see the [Hook Reference](hook-reference.html).

## Table of contents
{: .no_toc .text-delta }

1. TOC
{:toc}

---

## Plugin root

### Main plugin file (`as-the-crow-flies.php`)

Main plugin file.

- `atcf_plugin_action_links( array $links )` — Add a Settings link to the plugin's row on the Plugins screen.
- `atcf_init()` — Register the plugin's components.

### Uninstall (`uninstall.php`)

Uninstall script for As the Crow Flies.

- `atcf_uninstall_cleanup()` — Remove the plugin's settings, caches and cron event, and its tables when Delete data on uninstall is on.

## Includes

### Activation (`includes/class-activator.php`)

Plugin activation: table creation and initial data import.


**`ATCF_Activator`**

- `ATCF_Activator::activate()` — Run on plugin activation.
- `ATCF_Activator::create_tables()` — Create plugin database tables.
- `ATCF_Activator::import_us_centroids()` — Import bundled US Zip code centroid data on first activation.
- `ATCF_Activator::reimport_us_centroids()` — Re-import US centroids from the bundled CSV file, replacing existing rows.
- `ATCF_Activator::deactivate()` — Clean up on deactivation (cron only; tables and data preserved).
- `ATCF_Activator::get_centroid_count( string $country )` — Return the count of rows in the centroids table for a given country.
- `ATCF_Activator::import_geonames_file( string $country, string $tmp_path )` — Import centroids from a GeoNames TSV file for a given country. Replaces all existing rows for that country.
- `ATCF_Activator::download_and_import_census_zcta()` — Download the Census ZCTA Gazetteer zip from census.gov, extract the TSV, and import into the centroids table.
- `ATCF_Activator::import_zcta_from_tsv( string $tmp_path )` — Import US centroid data from a Census Gazetteer tab-delimited file.
- `ATCF_Activator::import_zcta_file( string $tmp_path )` — Import US centroid data from an uploaded ZCTA CSV file. Expected columns (with or without header): postal_code, lat, lng

### Term edit panel and column (`includes/class-admin.php`)

Admin integration: term edit panel and term list column.


**`ATCF_Admin`**

- `ATCF_Admin::register()` — Hook the admin assets, term edit panel, term list columns and AJAX handlers.
- `ATCF_Admin::enqueue_assets( string $hook )` — Load the admin scripts and styles on the screens that need them.
- `ATCF_Admin::hook_term_edit( string $taxonomy )` — Hook the term edit section for a taxonomy when it is registered.
- `ATCF_Admin::render_term_edit_panel( WP_Term $term )` — Render the ATCF section on the Edit Term screen.
- `ATCF_Admin::hook_term_list( string $taxonomy )` — Hook the term list column, sortable registration, and filter for a taxonomy.
- `ATCF_Admin::add_locations_column( array $columns )` — Add the Locations column to term lists for enabled taxonomies.
- `ATCF_Admin::make_locations_column_sortable( array $sortable )` — Register the Locations column as sortable.
- `ATCF_Admin::sort_terms_by_locations( array $clauses, array $taxonomies, array $args )` — Inject an ORDER BY subquery when the Locations column is sorted.
- `ATCF_Admin::render_locations_column( string $output, string $column, int $term_id )` — Render the Locations column cell for a term.
- `ATCF_Admin::render_locations_filter( string $taxonomy )` — Render the locations filter dropdown above the term list.
- `ATCF_Admin::apply_locations_filter( WP_Term_Query $query )` — Filter terms by location presence when the dropdown filter is applied.
- `ATCF_Admin::flush_location_caches( string $taxonomy )` — Flush the per-taxonomy location caches on atcf_location_changed.
- `ATCF_Admin::ajax_clear_term_locations()` — AJAX: clear all locations for a term.
- `ATCF_Admin::ajax_upload_term_csv()` — AJAX: upload a term-scoped CSV.
- `ATCF_Admin::is_taxonomy_enabled( string $taxonomy )` — Check whether ATCF is enabled for a taxonomy.
- `ATCF_Admin::ajax_geocode_location()` — AJAX: geocode one location.
- `ATCF_Admin::ajax_clear_location_coords()` — AJAX: clear one location's coordinates.
- `ATCF_Admin::ajax_gl_clear_all_coords()` — AJAX: clear the coordinates of all locations, or one taxonomy's, from the Term Locations screen.
- `ATCF_Admin::ajax_gl_delete_all()` — AJAX: delete all locations, or one taxonomy's, from the Term Locations screen.
- `ATCF_Admin::ajax_clear_term_coords()` — AJAX: clear the coordinates of a term's locations.
- `ATCF_Admin::ajax_geocode_term()` — AJAX: geocode a term's locations.
- `ATCF_Admin::ajax_save_location_inline()` — AJAX: save a location edited inline.

### Blocks (`includes/class-blocks.php`)

Gutenberg block registration.


**`ATCF_Blocks`**

- `ATCF_Blocks::register()` — Hook the block category, block registration and editor data.
- `ATCF_Blocks::enqueue_editor_data()` — Inject enabled-country list into the editor so the Location Finder block can render a dropdown instead of a free-text ISO code field.
- `ATCF_Blocks::add_block_category( array $categories )` — Add "As the Crow Flies" category to the block inserter.
- `ATCF_Blocks::register_blocks()` — Register all three dynamic blocks.
- `ATCF_Blocks::render_location_finder( array $attributes )` — Render atcf/location-finder — interactive postal code finder.
- `ATCF_Blocks::render_term_locations( array $attributes )` — Render atcf/term-locations — static location list for a specific term.
- `ATCF_Blocks::render_nearest_location( array $attributes )` — Render atcf/nearest-location — compact callout for the nearest location.

### CSV import and export (`includes/class-csv.php`)

CSV import and export for location data.


**`ATCF_CSV`**

- `ATCF_CSV::safe_cell( $value )` — Stop a spreadsheet from treating an exported value as a formula.
- `ATCF_CSV::import_term_scoped( string $tmp_path, int $term_id, string $taxonomy, string $mode, array $conflict )` — Import a term-scoped CSV file.
- `ATCF_CSV::import_global( string $tmp_path, string $mode, array $conflict )` — Import a global CSV file (taxonomy + term_slug columns).

### Distance and postal codes (`includes/class-distance.php`)

Distance calculation, centroid lookup, coordinate validation, and public API.

- `atcf_get_term_ids_within_radius( string $postal_code, float $radius, string $unit = 'miles', string $taxonomy = '', string $country = 'US' )` — Return term IDs within radius of a postal code.
- `atcf_get_locations_within_radius( string $postal_code, float $radius, string $unit = 'miles', array $args = array() )` — Return full location rows within radius of a reference point.
- `atcf_is_within_radius( $location, float $lat, float $lng, float $radius, string $unit = 'miles' )` — Check whether a single location is within radius of a coordinate pair.
- `atcf_get_directions_url( $location, string $provider = 'google' )` — Return a directions URL for a location.
- `atcf_resolve_directions_provider( string $pref = '' )` — Resolve a directions provider slug to a valid value.
- `atcf_get_the_distance( $location, string $unit = 'miles' )` — Return numeric distance from a location row.
- `atcf_the_distance( $location, string $unit = 'miles', string $less_than_one = '', string $one = '', string $more_than_one = '' )` — Echo a human-readable distance string.
- `atcf_get_nearest_postal_code( float $lat, float $lng, string $country = 'US', float $max_km = 0.0 )` — Find the nearest postal code to a coordinate pair.
- `atcf_get_postal_centroid( string $postal_code, string $country = 'US' )` — Return the centroid coordinates for a postal code.
- `atcf_get_term_ids_with_locations( string $taxonomy )` — Return all term IDs in a taxonomy that have at least one ATCF location.
- `atcf_find_terms_by_location( array $schema_locations )` — Find local terms whose stored locations match any location in the given list.
- `atcf_get_cdn_location()` — Return the visitor's location as reported by the site's CDN in request headers.
- `atcf_get_visitor_location( bool $include_request = true )` — Return the visitor's location for "near me" features — the one place the location chain is defined, used by ATCF's shortcodes, blocks and REST endpoint and by HyperTaxonomy's near:me searches.
- `atcf_get_ip_location()` — Return the visitor's approximate location from their IP address.
- `atcf_get_postal_code_format( string $country )` — Return a regex pattern that validates postal codes for a given country.


**`ATCF_Distance`**

- `ATCF_Distance::get_term_ids_within_radius( string $postal_code, float $radius, string $unit = 'miles', string $taxonomy = '', string $country = 'US' )` — Return term IDs that have at least one location within radius of a postal code.
- `ATCF_Distance::get_locations_within_radius( string $postal_code, float $radius, string $unit = 'miles', array $args = array() )` — Return full location rows within radius of a reference point.
- `ATCF_Distance::bounding_box( float $lat, float $lng, float $radius_km )` — Build a coordinate box that contains every point within a radius, for pre-filtering.
- `ATCF_Distance::is_within_radius( $location, float $ref_lat, float $ref_lng, float $radius, string $unit = 'miles' )` — Check whether a single location row is within radius of a coordinate pair.
- `ATCF_Distance::resolve_provider( string $pref = '' )` — Resolve a directions provider slug to a valid value.
- `ATCF_Distance::get_directions_url( $location, string $provider = 'google' )` — Return a directions URL for a location.
- `ATCF_Distance::get_the_distance( $location, string $unit = 'miles' )` — Return numeric distance from a location row (must have distance_km or distance_miles set).
- `ATCF_Distance::the_distance( $location, string $unit = 'miles', string $less_than_one = '', string $one = '', string $more_than_one = '' )` — Return a human-readable distance string.
- `ATCF_Distance::get_nearest_postal_code( float $lat, float $lng, string $country = 'US', float $max_km = 0.0 )` — Find the nearest postal code to a coordinate pair.
- `ATCF_Distance::normalize_postal_code( string $postal_code, string $country = 'US' )` — Normalize a postal code for a given country.
- `ATCF_Distance::is_valid_coordinate( float $lat, float $lng )` — Check that lat/lng values are within valid ranges.
- `ATCF_Distance::get_centroid( string $postal_code, string $country )` — Look up a centroid by postal code and country.
- `ATCF_Distance::great_circle_distance( float $lat1, float $lng1, float $lat2, float $lng2 )` — Spherical law of cosines — great-circle distance in km between two coordinates.

### Geocoding (`includes/class-geocoder.php`)

US Census Geocoder batch integration and cron scheduling.


**`ATCF_Geocoder`**

- `ATCF_Geocoder::register_cron()` — Register cron hook.
- `ATCF_Geocoder::run_batch()` — Run a geocoding batch for all pending US locations. Called by WP Cron and by the admin "Geocode now" button.
- `ATCF_Geocoder::run_for_ids( array $ids )` — Geocode a specific set of location IDs immediately. Used by per-row, bulk, and per-term geocode actions. Only US locations are sent to the Census API; others are marked no_match.
- `ATCF_Geocoder::retry_and_run()` — Re-queue API failures and run geocoding immediately.

### HyperTaxonomy integration (`includes/class-ht-integration.php`)

HyperTaxonomy integration — term merge lifecycle.

- `atcf_expand_htax_groups( array $group_ids, string $taxonomy = '' )` — Expand HyperTaxonomy group IDs to member term IDs.


**`ATCF_HT_Integration`**

- `ATCF_HT_Integration::register()` — Hook the HyperTaxonomy merge actions that keep locations on merged terms.
- `ATCF_HT_Integration::snapshot_before_merge( array $source_ids, int $target_id, string $taxonomy, bool $create_redirect )` — Snapshot ATCF location rows for each source term before the merge transaction.
- `ATCF_HT_Integration::sync_after_merge( array $source_ids, int $target_id, string $taxonomy, array $result )` — Copy snapshotted ATCF locations to the merge target after HT commits.
- `ATCF_HT_Integration::merge_group_terms_into_string( string $group_attr, string $term_attr, string $taxonomy )` — Merge a shortcode `group` attribute's expanded members into a `term` string.
- `ATCF_HT_Integration::merge_group_term_ids( array $term_ids, array $group_ids, string $taxonomy )` — Merge expanded HyperTaxonomy group members into a list of term IDs.
- `ATCF_HT_Integration::terms_menu_slug()` — Return the shared "Terms" top-level menu slug if one is already registered by a suite plugin (HyperTaxonomy's htax-terms or TermConnect's tc-terms), else null.
- `ATCF_HT_Integration::position_term_column( array $columns, string $key, string $label )` — Insert a term-table column after the columns added by TermConnect or HyperTaxonomy.

### Locations page (all terms) (`includes/class-locations-global.php`)

Global Locations admin page — shown under Terms menu.


**`ATCF_Locations_Global`**

- `ATCF_Locations_Global::register()` — Hook the Term Locations screen and its export, import, template, geocode and delete handlers.
- `ATCF_Locations_Global::add_menu()` — Add the Term Locations screen, under the shared Terms menu when it exists.
- `ATCF_Locations_Global::render()` — Render the Term Locations screen.
- `ATCF_Locations_Global::handle_export()` — Download all locations, or one taxonomy's, as CSV.
- `ATCF_Locations_Global::handle_import()` — Import locations from an uploaded CSV file.
- `ATCF_Locations_Global::handle_template()` — Download a blank CSV template.
- `ATCF_Locations_Global::handle_geocode()` — Geocode pending locations now.
- `ATCF_Locations_Global::handle_delete()` — Delete one location.
- `ATCF_Locations_Global::handle_bulk_delete()` — Apply a bulk action to the selected locations.
- `ATCF_Locations_Global::get_parent_slug()` — Return the parent menu slug (htax-terms if HT is active, atcf-terms otherwise). Safe to call after admin_menu has fired.

### Locations page (one term) (`includes/class-locations-page.php`)

Per-term locations management page.


**`ATCF_Locations_Page`**

- `ATCF_Locations_Page::register()` — Hook the per-term locations screen and its form handlers.
- `ATCF_Locations_Page::register_page()` — Add the per-term locations screen, which has no menu entry.
- `ATCF_Locations_Page::set_menu_state()` — Keep the Terms top-level menu expanded and the Locations submenu item highlighted while viewing an individual term's locations page.
- `ATCF_Locations_Page::render()` — Render a term's locations screen.
- `ATCF_Locations_Page::handle_bulk()` — Apply a bulk action to a term's selected locations.
- `ATCF_Locations_Page::handle_save()` — Save a location added or edited on a term's locations screen.
- `ATCF_Locations_Page::handle_delete()` — Delete a location from a term's locations screen.
- `ATCF_Locations_Page::handle_template_download()` — Download a CSV template for a term.
- `ATCF_Locations_Page::handle_export()` — Download a term's locations as CSV.
- `ATCF_Locations_Page::us_states()` — US state codes and names.
- `ATCF_Locations_Page::screen_columns()` — Column definitions shared by the per-term and global All Locations pages. Used to register Screen Options and to determine which columns can be hidden.
- `ATCF_Locations_Page::register_screen_options()` — Register Screen Options (column checkboxes) and the Help tab for a locations screen. Call from the load-{hookname} action.
- `ATCF_Locations_Page::sortable_th( string $label, string $column, string $current, string $order, bool $hidden = false )` — Table header cell that sorts the table by its column.
- `ATCF_Locations_Page::kses_th()` — HTML allowed in sortable header cells.
- `ATCF_Locations_Page::geocode_status_label( ?string $status )` — Label for a location's geocoding status.
- `ATCF_Locations_Page::render_inline_edit_fieldset( object $loc, string $save_term_id, string $save_taxonomy, bool $us_only, bool $multiple_countries, array $countries )` — Render the inline-edit fieldset for one location row. Used by both the per-term page and the global All Locations page.
- `ATCF_Locations_Page::render_geocode_info()` — Render the "About coordinates" informational block. Used by both the per-term page and the global All Locations page.

### Location storage (`includes/class-locations.php`)

CRUD operations for term locations.


**`ATCF_Locations`**

- `ATCF_Locations::get_for_term( int $term_id, string $taxonomy, array $args = array() )` — Get all locations for a single term.
- `ATCF_Locations::get_by_term_id( int $term_id )` — Get locations for a single term by ID only, without requiring the taxonomy.
- `ATCF_Locations::get_normalized_addresses( int $term_id )` — Normalized address identities for a term's locations.
- `ATCF_Locations::flush_term_cache( int $term_id )` — Invalidate the cached locations for a single term.
- `ATCF_Locations::flush_all_location_caches()` — Invalidate all per-term location caches. Use for operations that affect many terms without a known term ID set.
- `ATCF_Locations::get_for_terms( array $term_ids, string $taxonomy, array $args = array() )` — Get locations for a set of terms in one query.
- `ATCF_Locations::get_aggregated( int $term_id, string $taxonomy, array $exclude_ids = array(), array $additional_ids = array() )` — Get locations for a term and its full descendant/inclusion scope.
- `ATCF_Locations::get( int $id )` — Get a single location by ID.
- `ATCF_Locations::count_for_term( int $term_id, string $taxonomy )` — Count locations for a term.
- `ATCF_Locations::get_all( array $args = array() )` — Get paginated locations across all terms/taxonomies.
- `ATCF_Locations::insert( array $data )` — Insert a new location. Returns the new ID or WP_Error.
- `ATCF_Locations::update( int $id, array $data )` — Update an existing location. Returns true or WP_Error.
- `ATCF_Locations::bulk_clear_coords( array $ids )` — Clear coordinates and re-queue geocoding for a batch of location IDs. Runs a single UPDATE instead of per-row queries.
- `ATCF_Locations::delete( int $id )` — Delete a single location by ID.
- `ATCF_Locations::delete_for_term( int $term_id, string $taxonomy )` — Delete all locations for a term.
- `ATCF_Locations::split_secondary_address( string $address_line )` — Detect and split a secondary address component out of address_line.
- `ATCF_Locations::normalize_address_line1( string $line )` — DIY normalization for address_line (line 1). Used when the Census-returned form is not yet available.
- `ATCF_Locations::normalize_address_line2( string $line )` — DIY normalization for address_line2 — expand secondary address abbreviations.
- `ATCF_Locations::set_normalized_address( int $id, string $normalized_street )` — Update a location's normalized address_line from the Census-returned street form.
- `ATCF_Locations::set_geocode_result( int $id, string $status, ?float $lat = null, ?float $lng = null )` — Mark a location's geocode_status and optionally set coordinates.
- `ATCF_Locations::queue_for_geocoding( int $id )` — Queue a location for Census geocoding.
- `ATCF_Locations::get_pending_geocode_ids( int $limit = 9999 )` — Return IDs of pending US locations (no lat/lng, geocode_status = 'pending').
- `ATCF_Locations::count_non_us_without_coords()` — Count non-US locations that have no coordinates. Used to generate the post-import admin notice.
- `ATCF_Locations::locations_match( object $a, object $b )` — Compare two location rows and determine whether they represent the same place.
- `ATCF_Locations::delete_orphans()` — Delete location rows whose term_id no longer corresponds to a WordPress term.

### REST API (`includes/class-rest-api.php`)

REST API endpoints.


**`ATCF_REST_API`**

- `ATCF_REST_API::register()` — Hook the REST route registration.
- `ATCF_REST_API::get_visitor_location()` — GET /atcf/v1/visitor-location — the visitor's location from the server side of the chain (CDN headers, IP geolocation, fallback location; see atcf_get_visitor_location()).
- `ATCF_REST_API::register_routes()` — Register the atcf/v1 routes.
- `ATCF_REST_API::get_locations( WP_REST_Request $request )` — GET /atcf/v1/locations
- `ATCF_REST_API::get_nearest_postal_code( WP_REST_Request $request )` — GET /atcf/v1/nearest-postal-code
- `ATCF_REST_API::validate_lat( $value )` — Whether a value is a valid latitude, or null.
- `ATCF_REST_API::validate_lng( $value )` — Whether a value is a valid longitude, or null.
- `ATCF_REST_API::format_term_locations( int $term_id )` — Return locations for a term formatted as Schema.org Place objects, ready for the htax_locations REST field on HyperTaxonomy term responses.

### Settings page (`includes/class-settings-page.php`)

Plugin settings page — three tabs: Locations, Postal Codes, Advanced.


**`ATCF_Settings_Page`**

- `ATCF_Settings_Page::register()` — Hook the settings page and its form handlers.
- `ATCF_Settings_Page::add_menu()` — Add Settings → As the Crow Flies.
- `ATCF_Settings_Page::sanitize_fallback_location( $value )` — Sanitize the fallback location: "lat, lng" text → { lat, lng }, or empty.
- `ATCF_Settings_Page::register_settings()` — Register the options saved by each settings tab.
- `ATCF_Settings_Page::render()` — Render the settings page and its tabs.
- `ATCF_Settings_Page::handle_download_census()` — Download and import the Census ZCTA file.
- `ATCF_Settings_Page::handle_reimport_us()` — Re-import the bundled US postal code centroids.
- `ATCF_Settings_Page::handle_upload_zcta()` — Import an uploaded ZCTA file.
- `ATCF_Settings_Page::handle_upload_geonames()` — Import an uploaded GeoNames postal code file for a country.
- `ATCF_Settings_Page::handle_toggle_us()` — Turn the US postal code centroids on or off.
- `ATCF_Settings_Page::handle_toggle_country()` — Turn a country's postal code centroids on or off.
- `ATCF_Settings_Page::handle_delete_country()` — Delete a country's postal code centroids.

### Shortcodes (`includes/class-shortcodes.php`)

Frontend shortcodes.


**`ATCF_Shortcodes`**

- `ATCF_Shortcodes::register()` — Register the three shortcodes and their assets.
- `ATCF_Shortcodes::enqueue_frontend_assets()` — Register the front-end scripts and styles the shortcodes use.
- `ATCF_Shortcodes::shortcode_locations( array $atts )` — Render location rows near a point.
- `ATCF_Shortcodes::shortcode_terms( array $atts )` — Render terms with locations near a point.
- `ATCF_Shortcodes::shortcode_nearby( array $atts )` — Render terms with locations near a reference term's locations.
- `ATCF_Shortcodes::apply_location_template( string $tpl, array $loc, string $unit, string $provider = '' )` — Substitute {placeholder} tokens in a location item template.

### WP_Query integration (`includes/class-wp-query.php`)

WP_Query integration — nested atcf_query parameter support.


**`ATCF_WP_Query`**

- `ATCF_WP_Query::register()` — Hook the atcf_query support into WP_Query.
- `ATCF_WP_Query::handle_atcf_query( WP_Query $query )` — Detect atcf_query, resolve term IDs, inject tax_query clause.
- `ATCF_WP_Query::handle_orderby( string $orderby, WP_Query $query )` — Apply distance-based ORDER BY when orderby = 'atcf_distance'.
- `ATCF_WP_Query::cleanup( array $posts, WP_Query $query )` — Clean up stored distance data after query completes.
