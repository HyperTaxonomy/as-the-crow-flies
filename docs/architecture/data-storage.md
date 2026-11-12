---
title: Data Storage
parent: Architecture
nav_order: 4
---

# Data Storage

As the Crow Flies keeps locations in two custom tables, and its settings and caches in options and transients. It uses no term, post or user meta. For what uninstalling removes, see [Locations & Settings](../user-guide/locations-settings.html#uninstalling).

## Tables

### `{prefix}atcf_locations`

One row per location. A term can have any number of locations.

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | Primary key. |
| `term_id` | `BIGINT UNSIGNED` | The term the location belongs to. |
| `taxonomy` | `VARCHAR(32)` | The term's taxonomy. |
| `country` | `CHAR(2)` | ISO 3166-1 alpha-2 code. Defaults to `US`. |
| `label` | `VARCHAR(200)` | Optional name, such as a branch name. |
| `address_line` | `VARCHAR(255)` | Street address. |
| `address_line2` | `VARCHAR(255)` | Suite, apartment or floor. |
| `address_line_normalized` | `VARCHAR(255)` | `address_line` normalized for comparing addresses. |
| `address_line2_normalized` | `VARCHAR(255)` | `address_line2` normalized for comparing addresses. |
| `locality` | `VARCHAR(100)` | City or town. |
| `region` | `VARCHAR(50)` | State, province or region. |
| `postal_code` | `VARCHAR(10)` | Postal code. |
| `lat`, `lng` | `DECIMAL(10,7)` | Coordinates, or `NULL` when not known yet. |
| `geocode_status` | `VARCHAR(20)` | Where the coordinates came from: `NULL` when the user entered them, `pending` while waiting for geocoding, `matched` or `tie` from the Census geocoder, `matched_nominatim` from OpenStreetMap, or `no_match` and `failed` when geocoding didn't succeed. |

Indexes: primary key on `id`; `term_taxonomy (term_id, taxonomy)`; `country_postal (country, postal_code)`.

### `{prefix}atcf_postal_centroids`

The center point of each postal code, used to measure distance from a postal code.

| Column | Type | Notes |
|---|---|---|
| `country` | `CHAR(2)` | ISO 3166-1 alpha-2 code. |
| `postal_code` | `VARCHAR(10)` | Postal code. |
| `lat`, `lng` | `DECIMAL(10,7)` | Coordinates of the center point. |

Primary key: `(country, postal_code)`. US rows come from the bundled `data/postal-centroids.csv` or the Census ZCTA file. Other countries come from uploaded GeoNames files.

## Options

Settings, saved by Settings → As the Crow Flies and described in [Locations & Settings](../user-guide/locations-settings.html#settings):

| Option | Type | Default |
|---|---|---|
| `atcf_enabled_taxonomies` | array | none |
| `atcf_distance_units` | string | `miles` |
| `atcf_location_match_mode` | string | `either` |
| `atcf_match_require_label_address` | boolean | `false` |
| `atcf_match_require_label_coords` | boolean | `false` |
| `atcf_location_match_radius` | number | `82` |
| `atcf_find_terms_radius` | number | `50` |
| `atcf_directions_provider` | string | `google` |
| `atcf_ip_geo_enabled` | boolean | `true` |
| `atcf_ip_geo_provider` | string | `freeipapi` |
| `atcf_ip_geo_ttl` | integer | `86400` |
| `atcf_fallback_location` | array | none |
| `atcf_expose_locations_api` | boolean | `true` |
| `atcf_delete_on_uninstall` | boolean | `false` |

Kept by the plugin:

| Option | Value |
|---|---|
| `atcf_db_version` | Plugin version that last created or updated the tables. |
| `atcf_us_centroids_imported` | When the US centroids were last imported. |
| `atcf_us_centroids_enabled` | Whether US postal codes are used. |
| `atcf_country_centroids` | For each other country: its row count, import time and whether it's enabled. |
| `atcf_conflict_address`, `atcf_conflict_label`, `atcf_conflict_coords` | The last "Match existing rows by" choices for CSV import. |

## Transients

| Key | Holds | Expires |
|---|---|---|
| `atcf_locs_{term_id}` | A term's location rows. | Never (a year with a persistent object cache); cleared when its locations change. |
| `atcf_countries_{md5(taxonomy)}` | Countries that have locations in a taxonomy, for the admin country filter. | Never; cleared when locations change. |
| `atcf_ip_{md5(provider + ip)}` | An IP geolocation result. | After `atcf_ip_geo_ttl` seconds, or 5 minutes when the lookup failed. |

## Cron event

| Event | Schedule | Runs |
|---|---|---|
| `atcf_geocode_pending` | Hourly | Sends locations with `pending` status to the Census geocoder in batches. |
