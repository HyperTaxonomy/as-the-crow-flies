---
title: Actions & Filters
parent: Developer Reference
nav_order: 3
---

# Actions & Filters

The hooks As the Crow Flies fires for other code. For where it registers its own callbacks, see the [Hook Reference](../architecture/hook-reference.html).

## Actions

| Action | Arguments | Fired when |
|---|---|---|
| `atcf_location_changed` | `string $taxonomy` | Any location is saved, updated, deleted, or imported for a term in this taxonomy. Passes taxonomy slug; does not pass term ID. |
| `atcf_term_location_changed` | `int $term_id` | Fired from `ATCF_Locations::flush_term_cache()` (and directly by the bulk clear/delete handlers) whenever a term's locations change. The save/update/delete/import paths fire it alongside `atcf_location_changed`. Passes the specific term ID. |

Use `atcf_term_location_changed` when you need to invalidate a per-term cache. Use `atcf_location_changed` when you need to react to any location change within a taxonomy (e.g. invalidating a taxonomy-wide result set).

`atcf_location_changed`'s `$taxonomy` argument can be an empty string — not just a real slug. Several bulk operations (clearing coordinates, deleting orphaned locations, geocoding a batch, and the global admin page's "all taxonomies" clear/delete actions) span more than one taxonomy at once and fire the hook unscoped, passing `''` to mean "affects all taxonomies." A listener that assumes `$taxonomy` is always a real slug will silently skip invalidating in that case — treat an empty value as "every taxonomy", as ATCF's own built-in listener (`ATCF_Admin::flush_location_caches()`) does.

HyperTaxonomy hooks `atcf_term_location_changed` directly (to rebuild TermConnect's normalized-address URI index for the term). Integrating plugins should hook `atcf_term_location_changed` as well — it is fired for single saves/deletes and for the global bulk clear/delete operations.

## Filters

| Filter | Arguments | Purpose |
|---|---|---|
| `atcf_ip_headers` | `string[] $headers` | `$_SERVER` keys IP geolocation reads the visitor's IP from (default `['REMOTE_ADDR']`). |
| `atcf_cdn_location` | `array\|null $location` | Visitor location from CDN request headers (`{ lat, lng, country, postal_code }` or null). Cloudflare's headers are read by default; map another CDN's headers here. |
| `atcf_taxonomy_enabled` | `bool $enabled`, `string $taxonomy` | Whether ATCF's location features are enabled for a taxonomy. |
| `atcf_term_ids_within_radius` | `int[] $term_ids`, `string $postal_code`, `float $radius`, `string $unit`, `string $taxonomy`, `string $country` | Term IDs returned by `atcf_get_term_ids_within_radius()`. |
| `atcf_term_locations_for_rest` | `array $locations`, `int $term_id` | A term's locations before they are formatted for REST output. HyperTaxonomy hooks this to add locations from its `htax_term_locations` filter. |
