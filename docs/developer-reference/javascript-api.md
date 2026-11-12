---
title: JavaScript API
parent: Developer Reference
nav_order: 3
---

# JavaScript API

ATCF exposes `window.atcfGeo` (script handle `atcf-frontend`). The location helpers are the browser side of the visitor-location chain; HyperTaxonomy's `near:me` uses them too.

```js
// Browser position: the current one, otherwise the last one this device gave
// (remembered 7 days in localStorage "atcf_geo"). → Promise<{lat, lng, source:'browser'}|null>
window.atcfGeo.getBrowserLocation({ timeout: 8000 });

// Server side of the chain (CDN headers → IP geolocation → fallback location), fetched
// once per page from GET atcf/v1/visitor-location. → Promise<{lat, lng, country, postal_code, source}|null>
window.atcfGeo.getServerLocation();

// The whole chain: browser, then server. → Promise<location with source|null>
// When source is 'fallback', tell the visitor (atcfFrontend.i18n.fallbackNote).
window.atcfGeo.getVisitorLocation({ timeout: 8000 });
```

The location is looked up only when a page asks for it — never on every page view, and never written into the page's HTML, so page caches can't serve one visitor's location to another.

For plugins that render a postal code input with a geolocation button:

```js
// Reverse-geocode coordinates → { city, postalCode }, both nullable.
window.atcfGeo.reverseGeocode(lat, lng, callback);

// Wire a crosshair button to a postal input with full lifecycle management.
window.atcfGeo.initGeoBtn({
    btn:       buttonElement,       // the <button> inside the postal-wrap
    input:     inputElement,        // optional: <input> to populate with label
    label:     'Current Location',  // fallback when reverse geocode returns nothing
    onSuccess: (lat, lng, displayLabel) => { /* store coords */ },
    onDenied:  () => { /* permission denied or geolocation unsupported */ },
    onReset:   () => { /* user edited the input — coords cleared */ },
});
```

`initGeoBtn` handles: browser permission request, Nominatim reverse geocode, populating the input with the detected postal code or city name, icon loading/used/re-enable states, and re-enable-on-edit. Consumers only need to implement `onSuccess` and `onReset` to store/clear their own coordinate state.

HyperTaxonomy's `[ht_distance_field]` shortcode delegates to this API when ATCF is active.
