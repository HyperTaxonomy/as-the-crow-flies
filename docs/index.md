---
title: Home
nav_order: 1
---

# As the Crow Flies

*Location management and distance filtering*
{: .fs-6 .fw-300 }

Attach physical locations (addresses and/or coordinates) to taxonomy terms and let visitors filter WordPress content by distance from any location — no external map API required. Distance is computed from bundled US Zip Code centroids, uploadable GeoNames postal data for any country, or explicit lat/lng coordinates, and works worldwide. Each term can hold multiple locations; results report the distance to the nearest one, with direction links included.

Part of the [HyperTaxonomy Suite](https://hypertaxonomy.org) of interoperable WordPress taxonomy plugins. Works on its own, with [more available when HyperTaxonomy is active](hypertaxonomy-integration.html).

## Documentation map

- **[Locations & Settings](locations-settings.html)** — for site owners: adding locations, geocoding, CSV import and export, every setting, and uninstalling.
- **[Shortcodes & Blocks](usage.html)** — the user-facing reference: the three shortcodes and three blocks with full attribute and inspector tables, item-template tokens, output formats, directions providers, and CSS classes.
- **[Developer Reference](developer-reference.html)** — for theme and plugin developers: the PHP API, the `window.atcfGeo` JavaScript API, actions, the REST API, and `WP_Query` `atcf_query` integration.
- **[HyperTaxonomy Integration](hypertaxonomy-integration.html)** — what becomes available when HyperTaxonomy is active alongside this plugin: term group expansion, PowerSearch / Search Widget distance filtering, and term-merge location preservation.
- **[Architecture](architecture/)** — for contributors: file structure and loading, function and hook references, and the database schema.

## Requirements

| Item | Version |
|------|---------|
| WordPress | 6.6+ |
| PHP | 7.4+ |

## Getting started

1. Install from the [WordPress.org plugin directory](https://wordpress.org/plugins/as-the-crow-flies/), or download the zip from [GitHub Releases](https://github.com/HyperTaxonomy/as-the-crow-flies/releases).
2. Upload via **Plugins → Add New → Upload Plugin**.
3. Activate, then go to **Settings → As the Crow Flies → General** to enable taxonomies, set default distance units, and configure location matching.
4. Edit taxonomy terms to add location data, or use the CSV import. See [Locations & Settings](locations-settings.html).
5. Add `[atcf_locations form="yes"]` to a page: visitors enter a postal code or use their location, and your locations are listed by distance. See [Shortcodes & Blocks](usage.html) for this and the other ways to show locations.

## License

GPLv2 or later — https://www.gnu.org/licenses/gpl-2.0.html
