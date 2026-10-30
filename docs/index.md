---
title: Home
nav_order: 1
---

# As the Crow Flies

Attach physical locations (addresses and/or coordinates) to taxonomy terms and let visitors filter WordPress content by distance from any location — no external map API required. Distance is computed from bundled US Zip Code centroids, uploadable GeoNames postal data for any country, or explicit lat/lng coordinates, and works worldwide. Each term can hold multiple locations; results report the distance to the nearest one, with direction links included.

## Part of the HyperTaxonomy suite

One of five interoperable WordPress plugins for richer, connected taxonomies. Each works on its own; together they share a common term model. Learn more at [hypertaxonomy.org](https://hypertaxonomy.org).

- **[HyperTaxonomy](https://hypertaxonomy.org/hypertaxonomy/)** — the core: term groups, inclusions, synonyms, redirects, icons, relevance-scored search, and enhanced archives.
- **[TermConnect](https://hypertaxonomy.org/termconnect/)** — link terms to knowledge-base identifiers (Wikidata, GeoNames, LCSH…) and peer sites; delegated identity, shared vocabularies, and cross-site related content.
- **[Social Hashtags & Mentions](https://hypertaxonomy.org/social-hashtags-mentions/)** — inline #hashtags and @mentions as taxonomy terms, linked to the social networks you enable (built on TermConnect).
- **As the Crow Flies** (this plugin) — attach physical locations to terms and filter content by distance/proximity.
- **[WP-SKOS](https://hypertaxonomy.org/wp-skos/)** — expose your taxonomies as SKOS linked data via a Skosmos-compatible REST API.

## Documentation map

- **[Shortcodes & Blocks](usage.html)** — the user-facing reference: the three shortcodes and three blocks with full attribute and inspector tables, item-template tokens, output formats, directions providers, and CSS classes.
- **[Developer Reference](developer-reference.html)** — for theme and plugin developers: the PHP API, the `window.atcfGeo` JavaScript API, actions, the REST API, and `WP_Query` `atcf_query` integration.
- **[HyperTaxonomy Integration](hypertaxonomy-integration.html)** — what becomes available when HyperTaxonomy is active alongside this plugin: term group expansion, PowerSearch / Search Widget distance filtering, and term-merge location preservation.

## Display approaches

There are two ways to render location data, both built on the same data model and distance-query engine — the difference is only in how and where addresses are rendered.

- **Shortcode / block** — insert `[atcf_locations]` or the Term Locations block anywhere in a post to pull location data from any term, regardless of that post's own taxonomy assignments. Useful when the displayed locations and the filtering/search terms don't need to match, or when authors control placement through the editor.
- **Custom taxonomy + theme template** — create a taxonomy specifically for your location entities (e.g. "Branches", "Offices"), enable it in settings, and attach addresses to its terms. Theme templates then retrieve locations automatically from the post's assigned terms via `get_the_terms()` + `ATCF_Locations::get_for_term( $term->term_id, $taxonomy )`, with no shortcode required. Suits sites where location data is a structural property of the content rather than an editorial choice.

## Requirements

| Item | Version |
|------|---------|
| WordPress | 6.6+ |
| PHP | 7.4+ |

## Installation

1. Install from the [WordPress.org plugin directory](https://wordpress.org/plugins/as-the-crow-flies/), or download the zip from [GitHub Releases](https://github.com/HyperTaxonomy/as-the-crow-flies/releases).
2. Upload via **Plugins → Add New → Upload Plugin**.
3. Activate, then go to **Settings → As the Crow Flies → General** to enable taxonomies, set default distance units, and configure location matching.
4. Edit taxonomy terms to add location data, or use the CSV import.

## License

GPLv2 or later — https://www.gnu.org/licenses/gpl-2.0.html
