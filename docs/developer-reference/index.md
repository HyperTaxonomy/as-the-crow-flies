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

As the Crow Flies contacts these services only for the features described. Nothing is sent on ordinary page views.

- **US Census Geocoder** (geocoding.geo.census.gov) — converts US addresses to coordinates. The plugin sends location addresses (street, city, state, ZIP code) from the site's server, in the hourly geocoding run or when an administrator clicks Geocode. No visitor data is sent. [Terms of Service](https://www.census.gov/data/developers/terms-of-service.html), [Privacy Policy](https://www.census.gov/about/policies/privacy/privacy-policy.html)
- **US Census Bureau downloads** (www2.census.gov) — when an administrator clicks the button to download current ZIP code data on the Postal Codes settings tab, the plugin downloads the public ZCTA Gazetteer file. Nothing else is sent. Same terms and privacy policy as above.
- **OpenStreetMap Nominatim** (nominatim.openstreetmap.org) — used in two ways. From the site's server, it geocodes addresses the Census Geocoder can't match, sending the location's address. From the visitor's browser, when the visitor clicks the "use my location" button on a location form or distance field, it looks up the city or postal code for the visitor's coordinates to show in the form; the browser sends those coordinates. [Usage Policy](https://operations.osmfoundation.org/policies/nominatim/), [Privacy Policy](https://osmfoundation.org/wiki/Privacy_Policy)
- **IP geolocation provider** — approximates the visitor's location when a "near me" shortcode, block or search needs it and the browser doesn't provide one. The site's server sends the visitor's IP address to the provider selected in Settings&nbsp;→ As the Crow Flies&nbsp;→ Advanced. IP geolocation is off by default; it is used only after an administrator turns it on there. The provider is not contacted on other page views, when IP geolocation is turned off, or when the site's CDN supplies the visitor's location in request headers (Cloudflare's "Add visitor location headers" managed transform). Results are cached per IP address for the Cache TTL setting (one day by default).
  - **FreeIPAPI** (freeipapi.com, default): [Terms](https://freeipapi.com/terms), [Privacy Policy](https://freeipapi.com/privacy)
  - **ipapi.co**: [Terms of Service](https://ipapi.co/terms/), [Privacy Policy](https://ipapi.co/privacy/)
  - **ipwho.is** (operated by ipwhois.io): [Terms of Service](https://ipwhois.io/terms), [Privacy Policy](https://ipwhois.io/privacy)

An optional **Fallback location** (Settings&nbsp;→ As the Crow Flies&nbsp;→ Advanced) is used by "near me" features — this plugin's shortcodes and blocks, and HyperTaxonomy `near:me` searches — when no visitor location can be determined; visitors are told when results are based on it. It involves no external service.

Directions links (Google Maps, Apple Maps, Waze, OpenStreetMap) are ordinary links: nothing is sent to those services unless a visitor follows one.

