---
title: File Structure & Loading
parent: Architecture
nav_order: 1
---

# File Structure & Loading

## File Structure

```
as-the-crow-flies/
├── .distignore                                 # Files left out of the WordPress.org deploy
├── .gitignore                                  # Files left out of the repository
├── as-the-crow-flies.php                       # Main plugin file: header, constants and bootstrap
├── LICENSE                                     # GPLv2
├── package-lock.json                           # npm lockfile
├── package.json                                # npm dependencies for the block build
├── README.md                                   # Overview and documentation links
├── readme.txt                                  # WordPress.org readme
├── uninstall.php                               # Uninstall handler
├── webpack.config.js                           # Webpack config for the blocks
├── .wordpress-org/                             # WordPress.org listing assets: icons, banners, screenshots (not in the zip)
├── assets/                                     
│   ├── admin.css                               # As the Crow Flies — Admin styles
│   ├── admin.js                                # As the Crow Flies — Admin JS
│   ├── editor.css                              # As the Crow Flies — Block editor styles
│   ├── frontend.css                            # As the Crow Flies — Frontend styles
│   └── frontend.js                             # As the Crow Flies — Frontend JS
├── blocks/                                     
│   ├── location-finder/                        
│   │   ├── block.json                          # Block metadata: attributes, scripts and styles
│   │   ├── index.js                            # Block editor script (source, compiled into build/)
│   │   └── build/                              # Compiled block assets (committed; the plugin works without npm)
│   ├── nearest-location/                       
│   │   ├── block.json                          # Block metadata: attributes, scripts and styles
│   │   ├── index.js                            # Block editor script (source, compiled into build/)
│   │   └── build/                              # Compiled block assets (committed; the plugin works without npm)
│   └── term-locations/                         
│       ├── block.json                          # Block metadata: attributes, scripts and styles
│       ├── index.js                            # Block editor script (source, compiled into build/)
│       └── build/                              # Compiled block assets (committed; the plugin works without npm)
├── data/                                       
│   └── postal-centroids.csv                    # Bundled US postal code centroids, imported on activation
├── docs/                                       # This documentation (Just-the-Docs)
├── includes/                                   
│   ├── class-activator.php                     # Plugin activation: table creation and initial data import.
│   ├── class-admin.php                         # Admin integration: term edit panel and term list column.
│   ├── class-blocks.php                        # Gutenberg block registration.
│   ├── class-csv.php                           # CSV import and export for location data.
│   ├── class-distance.php                      # Distance calculation, centroid lookup, coordinate validation, and public API.
│   ├── class-geocoder.php                      # US Census Geocoder batch integration and cron scheduling.
│   ├── class-ht-integration.php                # HyperTaxonomy integration — term merge lifecycle.
│   ├── class-locations-global.php              # Global Locations admin page — shown under Terms menu.
│   ├── class-locations-page.php                # Per-term locations management page.
│   ├── class-locations.php                     # CRUD operations for term locations.
│   ├── class-rest-api.php                      # REST API endpoints.
│   ├── class-settings-page.php                 # Plugin settings page — three tabs: Locations, Postal Codes, Advanced.
│   ├── class-shortcodes.php                    # Frontend shortcodes.
│   └── class-wp-query.php                      # WP_Query integration — nested atcf_query parameter support.
├── languages/                                  # Translation template and translations; .mo and .json are build output
└── utils/                                      # Packaging, translation and local deploy scripts (not in the zip)
```

## Loading Sequence

### 1. Main file (`as-the-crow-flies.php`)

1. Define `ATCF_VERSION`, `ATCF_PLUGIN_FILE`, `ATCF_PLUGIN_DIR` and `ATCF_PLUGIN_URL`.
2. Load translations on `init`.
3. Require every class in `includes/`.
4. Register the activation and deactivation hooks, and the plugin's Settings link.
5. Call `atcf_init()` on `plugins_loaded`.

### 2. `atcf_init()`

1. Register `ATCF_WP_Query`, `ATCF_REST_API`, `ATCF_Shortcodes`, `ATCF_Blocks` and `ATCF_HT_Integration`.
2. Hook `atcf_location_changed`, so cached location data is cleared whenever locations change.
3. In admin requests, register `ATCF_Admin`, `ATCF_Locations_Page`, `ATCF_Settings_Page` and `ATCF_Locations_Global`.
4. Register the geocoding cron callback (`ATCF_Geocoder::register_cron()`).
5. Hook `deleted_term` and `delete_term_taxonomy`, so a term's locations are removed when the term is.

### Activation and deactivation

- **Activation** (`ATCF_Activator::activate()`) creates or updates both tables with `dbDelta()`, imports the bundled US postal code centroids, schedules `atcf_geocode_pending`, and deletes locations whose terms no longer exist.
- **Deactivation** (`ATCF_Activator::deactivate()`) unschedules `atcf_geocode_pending`.
