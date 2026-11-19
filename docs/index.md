---
title: Home
nav_order: 1
---

# As the Crow Flies

*Location management and distance filtering*
{: .fs-6 .fw-300 }

Attach physical locations (addresses and/or coordinates) to taxonomy terms and let visitors filter WordPress content by distance from any location — no external map API required. Distance is computed from bundled US Zip Code centroids, uploadable GeoNames postal data for any country, or explicit lat/lng coordinates, and works worldwide. Each term can hold multiple locations; results report the distance to the nearest one, with direction links included.

Part of the [HyperTaxonomy Suite](https://hypertaxonomy.org) of interoperable WordPress taxonomy plugins. Works on its own, with [more available when HyperTaxonomy is active](user-guide/working-with-hypertaxonomy.html).

## Requirements

| Item | Version |
|------|---------|
| WordPress | 6.6+ |
| PHP | 7.4+ |
| MySQL | 5.7+ (MariaDB 10.3+) |

## Getting started

1. Install from the [WordPress.org plugin directory](https://wordpress.org/plugins/as-the-crow-flies/), or download the zip from [GitHub Releases](https://github.com/HyperTaxonomy/as-the-crow-flies/releases) and upload via **Plugins&nbsp;→ Add New&nbsp;→ Upload Plugin**, then activate it.
2. Go to **Settings&nbsp;→ As the Crow Flies&nbsp;→ General** to enable taxonomies, set default distance units, and configure location matching.
3. Edit taxonomy terms to add location data, or use the CSV import. See [Locations & Settings](user-guide/locations-settings.html).
4. Add `[atcf_locations form="yes"]` to a page: visitors enter a postal code or use their location, and your locations are listed by distance. See [Shortcodes & Blocks](user-guide/shortcodes-blocks.html) for this and the other ways to show locations.

## Documentation map

- **[User Guide](user-guide/)** — for site owners: adding locations, geocoding, CSV import and export, every setting, the shortcodes and blocks, and what HyperTaxonomy adds.
- **[Developer Reference](developer-reference/)** — for theme and plugin developers: the PHP API, `WP_Query` `atcf_query` integration, the `window.atcfGeo` JavaScript API, the REST API, actions and filters, and the external services used.
- **[Architecture](architecture/)** — for contributors: file structure and loading, function and hook references, and data storage.

## License

GPLv2 or later — https://www.gnu.org/licenses/gpl-2.0.html
