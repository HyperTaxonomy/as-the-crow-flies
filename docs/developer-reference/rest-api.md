---
title: REST API
parent: Developer Reference
nav_order: 4
---

# REST API

Three read-only endpoints under `atcf/v1`. None of them needs authentication.

## `GET /wp-json/atcf/v1/visitor-location`

The visitor's location from the server side of the chain — CDN headers, IP geolocation, fallback location (`atcf_get_visitor_location( false )`). Response: `{ "location": { "lat", "lng", "country", "postal_code", "source" } | null }`, sent with `Cache-Control: private, no-store`. Used by `atcfGeo.getServerLocation()`.

## `GET /wp-json/atcf/v1/locations`

| Parameter | Type | Description |
|-----------|------|-------------|
| `postal_code` | string | Reference postal code. |
| `lat` / `lng` | float | Reference coordinates (alternative to postal code). |
| `country` | string | ISO country code; limits results to this country. Postal codes default to `US`. Coordinates aren't limited unless this is set. |
| `radius` | float | Search radius. |
| `unit` | string | `miles` or `km`. |
| `term` | integer | Single term ID. |
| `term_ids[]` | integer[] | Multiple term IDs (e.g. `term_ids[]=1&term_ids[]=2`). Supersedes `term` when provided. |
| `taxonomy` | string | Taxonomy slug. |
| `limit` | integer | Maximum results (default 20, up to 100). |
| `orderby` | string | `distance`, `label`, or `term_name`. |
| `directions_provider` | string | `google` (default), `apple`, `osm`, `waze`, or `geo`. Provider used to build the `directions_url` in each result row; see [Directions](../user-guide/shortcodes-blocks.html#directions). |

## `GET /wp-json/atcf/v1/nearest-postal-code`

| Parameter | Type | Description |
|-----------|------|-------------|
| `lat` | float | Latitude. |
| `lng` | float | Longitude. |
| `country` | string | ISO country code. |
| `max_km` | float | Optional. Return 404 when the nearest centroid is farther than this (km). `0`/omitted = no limit. |

Returns `{ "postal_code", "country", "distance_km" }` with the nearest centroid in the country (use `distance_km` to judge proximity), or a 404 when no centroids exist for that country or the nearest exceeds `max_km`.

## Privacy

Settings → Advanced → "Return location data via REST API" controls `/atcf/v1/locations` only. When unchecked, the endpoint always returns an empty `locations` array (`{"locations":[],"count":0,...}`) — callers cannot retrieve your location database via REST, though the endpoint itself stays accessible. The [Location Finder and Nearest Location blocks](../user-guide/shortcodes-blocks.html#blocks) (which read this endpoint) will show "no locations found" in that state; the Term Locations block is unaffected, since it renders server-side and never calls the REST endpoint. Shortcodes and server-side distance filtering are unaffected either way — they call `ATCF_Distance::get_locations_within_radius()` directly, a separate code path from this REST endpoint, so this setting never suppresses them. `/atcf/v1/nearest-postal-code` is a different endpoint entirely and is unaffected by this setting — it never returns location coordinates or addresses to begin with (only a postal code and a distance).
