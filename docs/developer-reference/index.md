---
title: Developer Reference
nav_order: 3
has_children: true
has_toc: false
---

# As the Crow Flies Developer Reference

For theme developers and plugin authors who work with As the Crow Flies in code. For adding locations and showing them on the site, see the [User Guide](../user-guide/).

- **[WP_Query Integration](wp-query.html)** — limiting any `WP_Query` to posts near a point with `atcf_query`, and ordering by distance.
- **[PHP API](php-api.html)** — reading and changing locations, radius searches, postal-code centroids, directions links, the visitor-location chain, and the theme functions.
- **[Actions & Filters](actions-filters.html)** — `atcf_location_changed`, `atcf_term_location_changed`, and the filters for visitor location, enabled taxonomies and REST output.
- **[REST API](rest-api.html)** — the `visitor-location`, `locations` and `nearest-postal-code` endpoints, and what the REST privacy setting covers.
- **[JavaScript API](javascript-api.html)** — `window.atcfGeo`: the browser side of the visitor-location chain and the geolocation button helper.

## External services

- **US Census Geocoder** — primary geocoder for US addresses (free, server-side, no key); batch API first, then OpenStreetMap/Nominatim fallback.
- **OpenStreetMap Nominatim** — secondary geocoder fallback when Census returns no match (free, server-side, no key).
- **IP geolocation** — approximates visitor location from IP when geolocation is unavailable (free, server-side, can be disabled in Settings → Advanced). Provider is selectable: **FreeIPAPI** (default, no stated rate limit), **ipapi.co** (1,000 req/day free), or **ipwho.is** (60 req/min free). All three require no API key. Not contacted when the site's CDN supplies the visitor's location in request headers (Cloudflare's "Add visitor location headers" managed transform).
