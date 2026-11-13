---
title: WP_Query Integration
parent: Developer Reference
nav_order: 1
---

# WP_Query Integration

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
