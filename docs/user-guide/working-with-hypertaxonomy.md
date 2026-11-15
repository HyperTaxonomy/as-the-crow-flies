---
title: Working with HyperTaxonomy
parent: User Guide
nav_order: 3
---

# Working with HyperTaxonomy

When [HyperTaxonomy](https://hypertaxonomy.org/hypertaxonomy/) is active alongside As the Crow Flies, additional features become available. None require configuration beyond enabling the relevant taxonomies in As the Crow Flies' settings.

## Term group expansion

HyperTaxonomy's [term groups](https://hypertaxonomy.org/hypertaxonomy/user-guide/term-groups.html) let you collect taxonomy terms into named sets (e.g. "Downtown Branches", "Partner Stores"). As the Crow Flies can expand a group into its member terms automatically, so you don't have to maintain a static list of term IDs in every shortcode and block.

### In shortcodes

Add `group="<group_id>"` (or a comma-separated list) to `[atcf_locations]` or `[atcf_nearby]`. The group's members are resolved at render time and merged with any `term` IDs already specified:

```
[atcf_locations group="7" radius="10"]
[atcf_nearby group="7,12" nearby_taxonomy="stores" radius="20"]
```

The `taxonomy` attribute is used to determine which of each group's members to include. When `taxonomy` is omitted, members in every taxonomy enabled in As the Crow Flies are included.

### In blocks

The **Location Finder** and **Nearest Location** block inspector panels show a **Term Groups** checklist when HyperTaxonomy is active. Check one or more groups; their members are expanded server-side at render time and merged with any specific terms selected above the checklist.

Group members and directly-selected terms are deduplicated automatically.

The underlying expansion is also available programmatically via `atcf_expand_htax_groups()` — see the [PHP API](../developer-reference/php-api.html).

---

## Settings

The **Term Groups** taxonomy (slug: `group`) is automatically excluded from the **Enabled Taxonomies** list in **Settings → As the Crow Flies → General** when HyperTaxonomy is active, since attaching locations to group terms directly is not meaningful.

---

## PowerSearch and Search Widget

The [`near:` operator](https://hypertaxonomy.org/hypertaxonomy/user-guide/powersearch.html#query-syntax) in HyperTaxonomy's PowerSearch, and the Search Widget's [distance filter](https://hypertaxonomy.org/hypertaxonomy/user-guide/search-widget.html#ht_distance_field--location-based-distance-filter), both use As the Crow Flies' distance engine. They find every matching location within the radius. No additional configuration is required — enabling a taxonomy in As the Crow Flies' settings is sufficient.

[![A search form with a distance field set to within 250 miles of a ZIP code, and the matching posts below](../assets/images/screenshots/distance-field.png){: .screenshot-small}](../assets/images/screenshots/distance-field.png)

---

## Matching locations across sites

With [TermConnect](https://hypertaxonomy.org/termconnect/) active as well as HyperTaxonomy, terms on different sites can match by shared location, even when their slugs and names differ. HyperTaxonomy adds each term's normalized address to the identifiers TermConnect compares across sites.

---

## Term merges

When HyperTaxonomy [merges several terms](https://hypertaxonomy.org/hypertaxonomy/user-guide/merge-convert.html#merge-terms) into one, the merged-away source terms are deleted. As the Crow Flies automatically moves their locations to the surviving target term before they are lost. Locations that the target already has (per your **Location Matching** settings) are not duplicated; only distinct locations are carried over. No configuration is required.
