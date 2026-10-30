---
title: Shortcodes & Blocks
nav_order: 2
---

# Shortcodes & Blocks
{: .no_toc }

The user-facing reference for displaying and filtering locations: three shortcodes, three blocks, the shared item-template token set, output formats, directions providers, and CSS classes.

## Table of contents
{: .no_toc .text-delta }

1. TOC
{:toc}

---

**Visitor location ("near me").** Everything in ATCF and HyperTaxonomy that needs the visitor's location uses one chain, defined in `atcf_get_visitor_location()` (server) and `atcfGeo` (browser):

1. a place the visitor typed (postal code, term) — always wins, and is not part of the chain;
2. the browser's position — the current one (the browser asks permission once per site; HTTPS only), otherwise the last one it gave on this device (remembered for 7 days);
3. the site's CDN location headers (Cloudflare's "Add visitor location headers" managed transform) — no third party involved;
4. IP geolocation, when enabled in Settings → Advanced;
5. the **Fallback location** from Settings → Advanced, when set. Results based on it carry a note: "Showing results near the default location — we couldn't determine yours."

When nothing answers, the feature shows nothing (or, in HyperTaxonomy searches, isn't filtered by distance and says so).

## Shortcodes

### [atcf_locations] — Location Finder

Displays locations within a radius of the visitor.

When neither `postal_code` nor `lat`/`lng` is provided, the visitor's IP location is used automatically. Add `form="yes"` to show an interactive postal code / geolocation form instead.

**Attributes:**

| Attribute | Default | Description |
|-----------|---------|-------------|
| `postal_code` | — | Reference postal code. Results are shown immediately unless `form="yes"` is also set, in which case the form is shown with this value pre-filled. |
| `lat` / `lng` | — | Explicit coordinates instead of a postal code. Both must be provided. When set, results are shown immediately regardless of `form`. |
| `country` | `US` | ISO 3166-1 alpha-2 country code (e.g. `US`, `CA`, `GB`). Determines the postal code format used for validation and centroid lookup. |
| `radius` | `10` | Search radius in the chosen unit. |
| `unit` | site default | `miles` or `km`. |
| `taxonomy` | (all) | Limit results to one taxonomy's locations. Use the taxonomy slug. |
| `term` | — | Limit results to specific terms' locations. Accepts a single term ID or slug, or a comma-separated list of term IDs (e.g. `term="1,2,3"`). When using slugs, `taxonomy` is required. Omit to show all locations within the radius. |
| `group` | — | Comma-separated HyperTaxonomy group IDs. When HyperTaxonomy is active, all members of each group are expanded and merged with any `term` IDs. Silently ignored when HyperTaxonomy is not active. |
| `limit` | `20` | Maximum number of locations returned. |
| `orderby` | `distance` | Sort order: `distance`, `label`, or `term_name`. |
| `format` | `ul` | Output wrapper: `ul`, `ol`, `div`, `table`, or `json`. See [Output formatting](#output-formatting). |
| `item_template` | — | Custom per-row template using `{placeholder}` tokens. See [Item templates](#item-templates). |
| `form` | — | Set to `yes` to show an interactive form. The postal code input is pre-filled with `postal_code` if provided, or the visitor's IP-derived postal code otherwise. |
| `input` | `zip,geolocation` | Which inputs appear in the form when `form="yes"`: `zip`, `geolocation`, or `zip,geolocation` (both). |
| `class` | — | Additional CSS class(es) on the wrapper element. |
| `directions_provider` | site default | `google`, `apple`, `waze`, `osm`, or `geo`. See [Directions](#directions). |

**Examples:**

Locations near the visitor's IP location:

```
[atcf_locations]
```

Interactive form pre-filled with the visitor's IP postal code:

```
[atcf_locations form="yes"]
```

Interactive form pre-filled with a specific postal code:

```
[atcf_locations postal_code="10001" form="yes"]
```

Geolocation-only button, no postal code input:

```
[atcf_locations form="yes" input="geolocation" taxonomy="stores"]
```

Pre-filled with a postal code — results shown immediately:

```
[atcf_locations postal_code="10001" country="US" radius="25" unit="miles"]
```

Limit to a specific term with a custom item template:

```
[atcf_locations postal_code="10001" term="42" item_template="{label} — {distance} — {directions}"]
```

### [atcf_terms] — Term Finder

Lists taxonomy terms that have at least one location within a radius of the visitor, sorted by nearest location.

When neither `postal_code` nor `lat`/`lng` is provided, the visitor's IP location is used automatically. Add `form="yes"` to show an interactive form instead.

**Attributes:**

| Attribute | Default | Description |
|-----------|---------|-------------|
| `postal_code` | — | Reference postal code. Results are shown immediately unless `form="yes"` is also set, in which case the form is shown with this value pre-filled. |
| `lat` / `lng` | — | Explicit coordinates instead of a postal code. Both must be provided. When set, results are shown immediately regardless of `form`. |
| `country` | `US` | ISO country code for postal code lookup. |
| `radius` | `10` | Search radius. |
| `unit` | site default | `miles` or `km`. |
| `taxonomy` | (all) | Limit to one taxonomy. |
| `output` | `terms` | `terms` to list term links, or `posts` to list posts assigned to any of the matching terms. |
| `post_type` | `post` | Post type to query when `output="posts"`. |
| `limit` | `20` | Maximum number of terms (or posts) returned. |
| `orderby` | `distance` | `distance` or `label`. |
| `format` | `ul` | `ul`, `ol`, or `div`. |
| `item_template` | — | Custom template. For term output, the tokens `{name}`, `{url}`, and `{distance}` are available. Location tokens (`{label}`, `{address_line}`, `{directions}`, etc.) are populated from the nearest location for each term. See [Item templates](#item-templates). |
| `form` | — | Set to `yes` to show an interactive form. The postal code input is pre-filled with `postal_code` if provided, or the visitor's IP-derived postal code otherwise. |
| `input` | `zip,geolocation` | Which inputs appear in the form when `form="yes"`: `zip`, `geolocation`, or `zip,geolocation`. |
| `class` | — | Additional CSS class(es). |
| `directions_provider` | site default | `google`, `apple`, `waze`, `osm`, or `geo`. |

**Examples:**

Terms near the visitor's IP location:

```
[atcf_terms taxonomy="stores"]
```

Interactive form — visitor enters their location:

```
[atcf_terms taxonomy="stores" form="yes"]
```

Terms near a fixed postal code:

```
[atcf_terms postal_code="90210" country="US" radius="15" taxonomy="stores"]
```

Posts assigned to nearby terms:

```
[atcf_terms postal_code="10001" taxonomy="offices" output="posts" post_type="employee"]
```

### [atcf_nearby] — Nearby Terms

Lists terms whose locations are within a given radius of a reference point. Useful for "branches near this branch", "neighborhoods near this event venue", or "stores near the visitor's location".

The reference point is determined in one of three ways:

- **With `term`** — the reference point(s) come from the named term's locations. All locations are expanded and the search radius is applied around each one.
- **With `postal_code`** — the centroid of the given postal code is used as the reference point.
- **Without either** — the visitor's location is used as the reference point (see *Visitor location* above: CDN headers, IP geolocation, then the fallback location, with a note when it is used). Output is empty when none is available.

**Attributes:**

| Attribute | Default | Description |
|-----------|---------|-------------|
| `term` | — | The reference term(s): a term ID, slug, or comma-separated list of term IDs (e.g. `term="1,2,3"`). Reference points are collected from all listed terms' locations. Omit to use `postal_code` or IP location instead. When omitted, `nearby_taxonomy` is required. |
| `taxonomy` | — | Taxonomy of the reference term(s). Required when `term` contains slugs. |
| `group` | — | Comma-separated HyperTaxonomy group IDs. When HyperTaxonomy is active, all members of each group are expanded and used as reference terms, merged with any `term` IDs. Silently ignored when HyperTaxonomy is not active. |
| `postal_code` | — | Reference postal code. Used when `term` is omitted. Falls back to the visitor's location (see *Visitor location*) when not provided. |
| `country` | `US` | ISO country code for `postal_code` lookup. |
| `nearby_taxonomy` | same as `term`'s taxonomy | Taxonomy to search for nearby terms. Defaults to the reference term's own taxonomy. Required when `term` is omitted. |
| `radius` | `10` | Search radius around the reference point(s). |
| `unit` | site default | `miles` or `km`. |
| `limit` | `20` | Maximum number of nearby terms (or posts) returned. |
| `orderby` | `distance` | `distance` or `label`. |
| `output` | `terms` | `terms` to list term links, or `posts` to list posts assigned to nearby terms. |
| `post_type` | `post` | Post type to query when `output="posts"`. |
| `format` | `ul` | `ul`, `ol`, or `div`. |
| `item_template` | — | Custom per-row template. Location tokens (`{label}`, `{address_line}`, `{directions}`, etc.) are populated from the nearest matching location. `{term_name}` and `{term_url}` are also available. See [Item templates](#item-templates). |
| `class` | — | Additional CSS class(es). |
| `directions_provider` | site default | `google`, `apple`, `waze`, `osm`, or `geo`. |

**Examples:**

Terms in the same taxonomy near term ID 42:

```
[atcf_nearby term="42" radius="25" unit="miles"]
```

Terms in a different taxonomy near a term identified by slug:

```
[atcf_nearby term="midtown-office" taxonomy="offices" nearby_taxonomy="stores" radius="10"]
```

Posts assigned to nearby terms (reference by term):

```
[atcf_nearby term="42" output="posts" post_type="event"]
```

Stores near a postal code (no term specified):

```
[atcf_nearby postal_code="10001" nearby_taxonomy="stores" radius="15"]
```

Stores near the visitor's IP location (no term or postal code):

```
[atcf_nearby nearby_taxonomy="stores" radius="15"]
```

---

## Blocks

The plugin provides three Gutenberg blocks, available in the block inserter under **As the Crow Flies**.

### Location Finder block

Renders an interactive search form: the visitor enters a postal code (or clicks the crosshair button to use browser geolocation), picks a radius, and sees nearby locations. Results are fetched from the REST API and rendered in place — no page reload required.

**Block settings (inspector panel):**

| Setting | Default | Notes |
|---------|---------|-------|
| Taxonomy | (all) | Limit to one taxonomy's locations. Changing taxonomy clears the term selection. |
| Limit to specific terms | (all) | Appears after a taxonomy is selected. One or more terms within the chosen taxonomy. Type at least 2 characters to search. Leave empty to include all terms. |
| Term Groups | (none) | Appears when HyperTaxonomy is active and at least one term group exists. Check one or more groups; their members are merged with any specific terms above. Hidden when HyperTaxonomy is not active. |
| Radius options | `5,10,25,50` | Comma-separated integers shown in the radius dropdown, in the chosen unit. |
| Unit | site default | Miles or Kilometers. |
| Max results | `20` | Maximum locations returned per search. |
| Sort by | Distance | Distance, Label (location name), or Term name. |
| Output style | Unordered list | List (`ul`), Ordered list (`ol`), Div, or Table. |
| Item template | — | Custom per-row template using `{placeholder}` tokens. See [Item templates](#item-templates). |
| Show "Use my location" button | on | Adds a crosshair geolocation button next to the postal code input. |
| Directions provider | Site default | Per-block override. See [Directions](#directions). |
| Country | `US` | Required. Determines the postal code format for client-side validation and filters results to that country. Shown as a dropdown of countries with centroid data enabled in Settings → Postal Codes. |

Equivalent to `[atcf_locations form="yes" input="zip,geolocation"]`. The **Limit to specific terms** control and the shortcode's `term` attribute both accept multiple terms.

### Term Locations block

Renders a static list of all locations attached to one or more taxonomy terms. Updates automatically when location data changes — no visitor interaction required.

**Block settings (inspector panel):**

| Setting | Default | Notes |
|---------|---------|-------|
| Taxonomy | — | Required. Select a taxonomy; the term picker is then populated. Changing taxonomy clears the term selection. |
| Terms | — | One or more terms from the chosen taxonomy. Type at least 2 characters to search. Leave empty to show all terms' locations. |
| Sort by distance from postal code | — | When set, locations are sorted nearest-first from this postal code and a distance label is shown. Leave blank to sort by label. |
| Unit | site default | Miles or Kilometers. Used for the distance label when sorting by postal code. |
| Max results | 5 | Cap the number of locations shown. Leave blank or set to 0 to show all locations. |
| Output style | Unordered list | `ul`, `ol`, `div`, or `table`. |
| Item template | — | Custom per-row template. When set, the **Columns shown** toggles are ignored. See [Item templates](#item-templates). |
| Directions provider | Site default | Per-block override. |
| Filter by country | (all) | Optional. Limits which of the term's locations are displayed to a single country. |
| Columns shown | Address line, City, State/Region, Postal code on; Country off | Five toggles controlling which address fields are rendered. Ignored when Item template is set. |

This block has no direct shortcode equivalent — it shows all locations for one or more terms without requiring a visitor reference point or radius.

### Nearest Location block

Resolves and displays the **single nearest location** to the current visitor, using the visitor-location chain client-side: the browser's position first, then CDN headers / IP geolocation / the fallback location (with a note when the fallback is used). No postal code input is shown.

**Block settings (inspector panel):**

| Setting | Default | Notes |
|---------|---------|-------|
| Taxonomy | (all) | Limit to one taxonomy's locations. Changing taxonomy clears the term selection. |
| Limit to specific terms | (all) | Appears after a taxonomy is selected. One or more terms within the chosen taxonomy. Type at least 2 characters to search. |
| Term Groups | (none) | Appears when HyperTaxonomy is active and at least one term group exists. Check one or more groups; their members are merged with any specific terms above. Hidden when HyperTaxonomy is not active. |
| Unit | site default | Miles or Kilometers. |
| Show distance | on | Appends a formatted distance string to the rendered location. |
| Item template | — | Custom template using `{placeholder}` tokens. See [Item templates](#item-templates). |
| Directions provider | Site default | Per-block override. |
| Country | (all) | Filters results to one country. Shown as a dropdown of enabled countries. |
| Loading prompt text | — | Text shown while location is being resolved. Defaults to "Finding your nearest location…". |

The closest shortcode equivalent is `[atcf_locations limit="1"]`, which uses the server side of the chain (CDN headers, IP geolocation, fallback location) to show the single nearest location. This block differs in that it asks the browser first, which is more precise and does not require a page reload.

---

## Output formatting

All three shortcodes and the Location Finder and Nearest Location blocks accept a `format` attribute (shortcodes) or **Output style** setting (blocks) that controls the wrapper element:

| Value | Shortcode | Block label | Output |
|-------|-----------|-------------|--------|
| `ul` | `format="ul"` | Unordered list | `<ul>` with `<li>` items (default) |
| `ol` | `format="ol"` | Ordered list | `<ol>` with `<li>` items |
| `div` | `format="div"` | Div | `<div>` wrapper with `<div>` items |
| `table` | `format="table"` | Table | `<table>` with a header row (Label, Address, Distance, Directions) |
| `json` | `format="json"` | — | `<script type="application/json">` data blob; shortcode only |

The `table` format renders a fixed four-column layout. For full control over the per-row markup, use an `item_template` with any list format.

---

## Item templates

All three shortcodes (`item_template` attribute) and all three blocks (**Item template** setting) support custom per-row output using `{placeholder}` tokens.

**Available tokens:**

| Token | Description |
|-------|-------------|
| `{label}` | Location label / name |
| `{term_name}` | Taxonomy term name (in `[atcf_terms]` and `[atcf_nearby]` when location tokens are used) |
| `{term_url}` | URL of the term archive (in `[atcf_terms]` and `[atcf_nearby]`) |
| `{address_line}` | Street address, line 1 |
| `{address_line2}` | Street address, line 2 |
| `{address}` | Lines 1 and 2 combined |
| `{city}` | City / locality |
| `{region}` or `{state}` | State / region (aliases) |
| `{postal_code}` or `{zip}` | Postal code (aliases) |
| `{country}` | Country |
| `{distance}` | Formatted distance string (e.g. "About 3 miles away") |
| `{directions_url}` | Raw directions URL (configured provider) |
| `{directions_url_google}` | Google Maps URL |
| `{directions_url_apple}` | Apple Maps URL |
| `{directions_url_waze}` | Waze URL |
| `{directions_url_osm}` | OpenStreetMap URL |
| `{directions}` | Pre-built "Directions" anchor using the configured provider |
| `{directions_link_start}` | Opening `<a href="...">` tag (configured provider) |
| `{directions_link_start_google}` | Opening `<a>` for Google Maps |
| `{directions_link_start_apple}` | Opening `<a>` for Apple Maps |
| `{directions_link_start_waze}` | Opening `<a>` for Waze |
| `{directions_link_start_osm}` | Opening `<a>` for OpenStreetMap |
| `{directions_link_end}` | Closing `</a>` tag |

**Examples:**

Label and formatted address on one line:

```
{label} — {address_line}, {city}, {region} {postal_code}
```

Label as a clickable directions link:

```
{directions_link_start}{label}{directions_link_end} — {distance}
```

Full address block with inline HTML:

```
<strong>{label}</strong><br>{address_line}<br>{city}, {region} {postal_code} — {directions}
```

**Using `{directions_link_start}` / `{directions_link_end}`:** if `{directions_link_end}` is omitted, it is automatically inserted after the first `{token}` that follows each `{directions_link_start}`. If no directions URL can be generated (the location has no geocodable data), both the opening and closing tags expand to empty strings.

**Term tokens in `[atcf_terms]` and `[atcf_nearby]`:** when no location-level tokens are present in the template, only `{name}`, `{url}`, and `{distance}` are substituted. When location tokens are also present, the nearest location's data is merged in and all tokens become available.

---

## Directions

**Settings → As the Crow Flies → Advanced → Directions** sets the site-wide default directions provider:

| Setting value | Provider |
|---------------|----------|
| `google` (default) | Google Maps |
| `apple` | Apple Maps |
| `waze` | Waze |
| `osm` | OpenStreetMap |

This controls:

- The `{directions_url}`, `{directions}`, and `{directions_link_start}` template tokens, which resolve to the configured provider's URL.
- The default "Directions" link rendered when no `item_template` is set.
- The `directions_url` field returned by the REST API.

Per-provider tokens (`{directions_url_google}`, `{directions_url_apple}`, etc.) are always available regardless of this setting.

**Per-shortcode override** — pass a `directions_provider` attribute to any shortcode:

```
[atcf_locations postal_code="10001" directions_provider="apple"]
[atcf_nearby term="42" directions_provider="waze"]
```

**Per-block override** — each block's inspector panel includes a **Directions provider** dropdown. Choose **Site default** to inherit from Settings.

---

## CSS classes

All three shortcodes accept a `class` attribute. All three blocks use WordPress's built-in **Advanced → Additional CSS class(es)** field. In both cases the value is appended to the outermost wrapper element, so individual instances can be targeted with CSS without affecting others:

```
[atcf_locations postal_code="10001" class="store-finder compact"]
[atcf_terms taxonomy="neighborhood" class="sidebar-terms"]
[atcf_nearby term="42" class="related-locations"]
```

Multiple space-separated classes are accepted.

**Default CSS classes applied to shortcode and block output:**

| Class | Element |
|-------|---------|
| `.atcf-locations-list` | Wrapper for list output in `[atcf_locations]` and the Location Finder block |
| `.atcf-locations-table` | Wrapper for table output in `[atcf_locations]` |
| `.atcf-terms-list` | Wrapper for `[atcf_terms]` and `[atcf_nearby]` list output |
| `.atcf-posts-list` | Wrapper when `output="posts"` |
| `.atcf-location` | Individual location row |
| `.atcf-term` | Individual term row |
| `.atcf-distance` | Distance string span |
| `.atcf-directions` | Directions anchor |
| `.atcf-no-results` | Shown when no results are returned |
| `.atcf-location-finder` | Outer wrapper of the Location Finder block |
| `.atcf-finder-form` | Interactive form wrapper rendered by `[atcf_locations form="yes"]` |
| `.atcf-postal-wrap` | Postal code input + geolocation button container |
| `.atcf-postal-input` | Postal code `<input>` element |
| `.atcf-geo-btn` | Geolocation crosshair button |
| `.atcf-results` | Results container inside the interactive form |
