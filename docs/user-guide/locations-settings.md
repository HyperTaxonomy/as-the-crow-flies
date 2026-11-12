---
title: Locations & Settings
parent: User Guide
nav_order: 1
---

# Locations & Settings
{: .no_toc }

How to add locations to terms, import them in bulk, and configure the plugin. To display locations on your site, see [Shortcodes & Blocks](shortcodes-blocks.html).

## Table of contents
{: .no_toc .text-delta }

1. TOC
{:toc}

---

## Adding locations

Start by enabling a taxonomy at **Settings → As the Crow Flies → General**, under **Enabled Taxonomies**. Its term edit screens then show a **Locations** panel, its term lists can show a **Locations** column, and it appears in the block settings. The column is hidden by default, so turn it on from **Screen Options**.

### For one term

Edit the term and click **Add locations →** to open its Locations page. Each location has these fields:

| Field | Notes |
|-------|-------|
| **Label** | Optional name for the location. |
| **Address line 1** and **Address line 2** | The street address. Line 2 is optional, for a suite, apartment or floor. |
| **City**, **State**, **Zip** | Once other countries are enabled, the labels become **City / Locality**, **Region** and **Postal**. |
| **CC** | ISO country code, such as `US`, `CA` or `GB`. Shown once other countries are enabled. |
| **Latitude** and **Longitude** | Optional. When blank, US addresses are converted to coordinates automatically. If that fails, the postal code's center is used. |

A term can have any number of locations. Distance results use whichever one is nearest.

### For all terms

**Terms → Term Locations** lists locations across all taxonomies. You can filter the list by taxonomy, country, locality, coordinates and geocoding status, edit rows in place, and use the bulk actions. **Geocode all**, **Clear all coords**, **Delete all locations** and **Export CSV** are also on this page.

---

## Geocoding

US addresses without coordinates are queued, and converted automatically on an hourly run. The US Census Geocoder is tried first, then OpenStreetMap Nominatim for addresses it can't match. You can also run this straight away with **Geocode** or **Geocode all**.

Each location's **Status** shows the result:

| Status | Meaning |
|--------|---------|
| **Pending** | Queued for the next hourly run. |
| **Census** | Coordinates came from the US Census Geocoder. |
| **Census (tie)** | The Census Geocoder found more than one equally good match, and one was used. Worth checking. |
| **OpenStreetMap** | Coordinates came from Nominatim. |
| **No match** / **Failed** | Neither service could resolve the address. Edit the address or enter coordinates by hand. |
| **User-provided** | You entered the coordinates, so geocoding won't overwrite them. |

Addresses outside the US aren't geocoded automatically. They use the center of their postal code when that country's postal data is uploaded (see [Postal Codes](#postal-codes)). For better accuracy, export them, add coordinates using a service such as Geocodio or OpenCage, and import them again.

Clearing a location's coordinates queues it for geocoding again.

---

## CSV import and export

Import and export are available on each term's Locations page and on **Terms → Term Locations**. Each has a **Download CSV template** link.

| Where | Columns |
|-------|---------|
| One term's Locations page | `label`, `address_line`, `address_line2`, `locality`, `region`, `postal_code`, `country`, `lat`, `lng` |
| **Terms → Term Locations** | `taxonomy`, `term_slug`, `country`, `label`, `address_line`, `address_line2`, `locality`, `region`, `postal_code`, `lat`, `lng` |

A header row is recognized automatically, and its columns can be in any order. Without one, columns must be in the order above.

**Match existing rows by** decides when a row counts as a location that already exists on the term. You can combine these:

- **Address + postal code** (on by default)
- **Label**
- **Coordinates**, either **Exact match** or **Within** a distance you set

**On conflict** decides what happens then:

- **Update** changes the existing location.
- **Skip** leaves it alone.
- **Reject all** cancels the whole import and lists the conflicting rows.

---

## Settings

Go to **Settings → As the Crow Flies**.

### General

| Setting | Description | Default |
|---------|-------------|---------|
| **Enabled Taxonomies** | Which taxonomies can have locations. | None |
| **Default units** | Miles or kilometers, used wherever a shortcode, block or query doesn't choose. | Miles |

**Location Matching** decides when two locations count as the same place. Merging terms in HyperTaxonomy and matching terms across sites through TermConnect both use it, so these settings only matter with [HyperTaxonomy](hypertaxonomy-integration.html).

| Setting | Description | Default |
|---------|-------------|---------|
| **Match locations by** | **Address (incl. postal code)**, **Coordinates**, **Address OR Coordinates**, or **Address AND Coordinates**. | **Address OR Coordinates** |
| **Require labels to match** | Separate options for comparing addresses and comparing coordinates. | Off |
| **Location match radius** | Coordinates this close together count as the same location, in feet or meters depending on **Default units**. | `82` |
| **Find-terms search radius** | The furthest `atcf_find_terms_by_location()` looks for matching terms. | `50` |

### Postal Codes

Distances from a postal code are measured from the center of that postal code's area.

- **US Zip Code Data** comes bundled, with about 33,000 Zip codes. You can disable it, download the current data from the Census Bureau, upload a file, or restore the bundled copy.
- **Other Countries** takes a free per-country postal code file from GeoNames. Enter the country code and upload the TSV file. Each country can then be enabled, disabled or deleted.

Locations with their own coordinates work in any country, with or without this data.

### Advanced

| Setting | Description | Default |
|---------|-------------|---------|
| **Directions provider** | See [Directions](shortcodes-blocks.html#directions). | Google Maps |
| **Enable IP geolocation** | Estimate the visitor's location from their IP address when the browser can't provide it. The IP address is sent to the provider you choose. | On |
| **Provider** | FreeIPAPI, ipapi.co or ipwho.is. None needs an API key. | FreeIPAPI |
| **Cache TTL** | How long to keep each IP lookup, in seconds. | `86400` |
| **Fallback location** | Latitude and longitude for "near me" features to use when the visitor's location can't be determined. Results based on it carry a note. | Empty |
| **Return location data via REST API** | When off, `/atcf/v1/locations` returns an empty list. See the [privacy note](../developer-reference/rest-api.html#privacy). | On |
| **Delete data on uninstall** | See [Uninstalling](#uninstalling). | Off |

---

## Uninstalling

Deactivating the plugin never deletes anything. Deleting it from the **Plugins** screen always removes most settings, scheduled tasks and cached data.

Locations, postal code data, enabled taxonomies and the list of uploaded countries stay, so a reinstall works as before, unless **Delete data on uninstall** is on.
