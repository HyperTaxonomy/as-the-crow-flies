/* As the Crow Flies — Frontend JS */
/* global jQuery, atcfFrontend */

( function ( $ ) {
	'use strict';

	var api = atcfFrontend.restUrl;
	var i18n = atcfFrontend.i18n;

	// -------------------------------------------------------------------------
	// Country-specific postal code normalization
	// Keyed by ISO 3166-1 alpha-2 country code.
	// pattern: RegExp to validate the raw input.
	// maxLength: max characters to accept in the input field.
	// normalize: function that converts raw input to the stored normalized form.
	// -------------------------------------------------------------------------

	var countryPostal = {
		'US': {
			pattern:   /^\d{5}(-\d{4})?$/,
			maxLength: 10,
			normalize: function ( v ) {
				return v.replace( /-\d{4}$/, '' ).replace( /\s+/g, '' );
			},
		},
		'CA': {
			pattern:   /^[A-Za-z]\d[A-Za-z][ ]?\d[A-Za-z]\d$/,
			maxLength: 7,
			normalize: function ( v ) {
				v = v.toUpperCase().replace( /\s+/g, '' );
				if ( v.length === 6 ) {
					v = v.slice( 0, 3 ) + ' ' + v.slice( 3 );
				}
				return v;
			},
		},
		'GB': {
			pattern:   /^[A-Za-z]{1,2}\d[A-Za-z\d]?[ ]?\d[A-Za-z]{2}$/,
			maxLength: 8,
			normalize: function ( v ) {
				return v.toUpperCase();
			},
		},
		'AU': {
			pattern:   /^\d{4}$/,
			maxLength: 4,
			normalize: function ( v ) { return v.replace( /\s+/g, '' ); },
		},
		'DE': {
			pattern:   /^\d{5}$/,
			maxLength: 5,
			normalize: function ( v ) { return v.replace( /\s+/g, '' ); },
		},
		'FR': {
			pattern:   /^\d{5}$/,
			maxLength: 5,
			normalize: function ( v ) { return v.replace( /\s+/g, '' ); },
		},
		'NL': {
			pattern:   /^\d{4}[ ]?[A-Za-z]{2}$/,
			maxLength: 7,
			normalize: function ( v ) {
				v = v.toUpperCase().replace( /\s+/g, '' );
				if ( v.length === 6 ) {
					v = v.slice( 0, 4 ) + ' ' + v.slice( 4 );
				}
				return v;
			},
		},
	};

	/**
	 * Normalize a postal code for a given country.
	 * Falls back to trimmed value if no rule exists for the country.
	 *
	 * @param {string} value   Raw postal code.
	 * @param {string} country ISO 3166-1 alpha-2 code.
	 * @return {string} Normalized postal code.
	 */
	function normalizePostalCode( value, country ) {
		var rule = countryPostal[ ( country || 'US' ).toUpperCase() ];
		if ( rule ) {
			return rule.normalize( value.trim() );
		}
		return value.trim();
	}

	/**
	 * Validate a postal code for a given country.
	 * Returns true if no rule exists (unknown countries pass through).
	 *
	 * @param {string} value   Normalized postal code.
	 * @param {string} country ISO 3166-1 alpha-2 code.
	 * @return {boolean}
	 */
	function isValidPostalCode( value, country ) {
		var rule = countryPostal[ ( country || 'US' ).toUpperCase() ];
		if ( ! rule ) {
			return value.length > 0;
		}
		return rule.pattern.test( value );
	}

	/**
	 * Apply maxLength constraint and country pattern to a postal code input.
	 *
	 * @param {jQuery} $input  The postal code input element.
	 * @param {string} country ISO 3166-1 alpha-2 code.
	 */
	function applyPostalInputRules( $input, country ) {
		var rule = countryPostal[ ( country || 'US' ).toUpperCase() ];
		if ( rule ) {
			$input.attr( 'maxlength', rule.maxLength );
		}
	}

	// -------------------------------------------------------------------------
	// Utilities
	// -------------------------------------------------------------------------

	function formatDistance( km, unit ) {
		var dist = unit === 'km' ? km : km / 1.60934;
		if ( dist < 1 ) {
			return unit === 'km' ? i18n.lessThanOneKm : i18n.lessThanOne;
		}
		if ( dist < 1.5 ) {
			return unit === 'km' ? i18n.aboutOneKm : i18n.aboutOne;
		}
		var tmpl = unit === 'km' ? i18n.aboutKm : i18n.aboutMiles;
		return tmpl.replace( '%s', Math.round( dist ) );
	}

	function formatCityLine( loc ) {
		var cityState = [ loc.locality, loc.region ].filter( Boolean ).join( ', ' );
		return cityState + ( loc.postal_code ? ( cityState ? ' ' : '' ) + loc.postal_code : '' );
	}

	function applyLocationTemplate( tpl, loc, unit ) {
		// Auto-close: inject {directions_link_end} after the first placeholder following
		// each {directions_link_start*} when {directions_link_end} is absent.
		if ( tpl.indexOf( '{directions_link_start' ) !== -1 && tpl.indexOf( '{directions_link_end}' ) === -1 ) {
			tpl = tpl.replace( /(\{directions_link_start[^}]*\})([^{]*\{[^}]+\})/g, '$1$2{directions_link_end}' );
		}

		var distStr    = loc.distance_km !== undefined ? formatDistance( loc.distance_km, unit ) : '';
		var dirUrl     = loc.directions_url || '';
		var addrParts  = [ loc.address_line, loc.address_line2 ].filter( Boolean );
		var directions = dirUrl
			? '<a href="' + esc( dirUrl ) + '" class="atcf-directions" target="_blank" rel="noopener">Directions</a>'
			: '';
		var linkAttrs = ' class="atcf-directions" target="_blank" rel="noopener">';
		function linkOpen( url ) {
			return url ? '<a href="' + esc( url ) + '"' + linkAttrs : '';
		}
		return tpl
			.replace( /\{term_name\}/g,                    esc( loc.term_name || '' ) )
			.replace( /\{label\}/g,                        esc( loc.label || '' ) )
			.replace( /\{address_line\}/g,                 esc( loc.address_line || '' ) )
			.replace( /\{address_line2\}/g,                esc( loc.address_line2 || '' ) )
			.replace( /\{address\}/g,                      esc( addrParts.join( ', ' ) ) )
			.replace( /\{city\}/g,                         esc( loc.locality || '' ) )
			.replace( /\{region\}/g,                       esc( loc.region || '' ) )
			.replace( /\{state\}/g,                        esc( loc.region || '' ) )
			.replace( /\{postal_code\}/g,                  esc( loc.postal_code || '' ) )
			.replace( /\{zip\}/g,                          esc( loc.postal_code || '' ) )
			.replace( /\{country\}/g,                      esc( loc.country || '' ) )
			.replace( /\{distance\}/g,                     esc( distStr ) )
			.replace( /\{directions_url\}/g,               esc( dirUrl ) )
			.replace( /\{directions\}/g,                   directions )
			.replace( /\{directions_link_start_google\}/g, linkOpen( loc.directions_url_google || dirUrl ) )
			.replace( /\{directions_link_start_apple\}/g,  linkOpen( loc.directions_url_apple  || '' ) )
			.replace( /\{directions_link_start_waze\}/g,   linkOpen( loc.directions_url_waze   || '' ) )
			.replace( /\{directions_link_start_osm\}/g,    linkOpen( loc.directions_url_osm    || '' ) )
			.replace( /\{directions_link_start\}/g,        linkOpen( dirUrl ) )
			.replace( /\{directions_link_end\}/g,          dirUrl ? '</a>' : '' );
	}

	function buildLocationHTML( loc, unit, format, tpl ) {
		var isDiv   = 'div' === format;
		var isTable = 'table' === format;
		var itemTag = isTable ? 'tr' : ( isDiv ? 'div' : 'li' );
		var html    = '<' + itemTag + ' class="atcf-location">';

		if ( tpl ) {
			html += applyLocationTemplate( tpl, loc, unit );
		} else if ( isTable ) {
			var cityLine = formatCityLine( loc );
			var address  = [ loc.address_line, loc.address_line2, cityLine ].filter( Boolean ).join( ', ' );
			html += '<td>' + esc( loc.label || '' ) + '</td>';
			html += '<td>' + esc( address ) + '</td>';
			html += '<td>' + ( loc.distance_km !== undefined ? esc( formatDistance( loc.distance_km, unit ) ) : '' ) + '</td>';
			html += '<td>';
			if ( loc.directions_url ) {
				html += '<a href="' + esc( loc.directions_url ) + '" target="_blank" rel="noopener">Directions</a>';
			}
			html += '</td>';
		} else {
			if ( loc.label ) {
				html += '<strong class="atcf-location-label">' + esc( loc.label ) + '</strong>';
			}
			var addressParts = [ loc.address_line, loc.address_line2 ].filter( Boolean );
			var cityLine     = formatCityLine( loc );
			if ( addressParts.length || cityLine ) {
				html += '<address>';
				if ( addressParts.length ) {
					html += esc( addressParts.join( ', ' ) ) + '<br/>';
				}
				if ( cityLine ) {
					html += esc( cityLine );
				}
				html += '</address>';
			}
			if ( loc.distance_km !== undefined ) {
				html += '<span class="atcf-distance">' + esc( formatDistance( loc.distance_km, unit ) ) + '</span>';
			}
			if ( loc.directions_url ) {
				html += ' <a href="' + esc( loc.directions_url ) + '" class="atcf-directions" target="_blank" rel="noopener">Directions</a>';
			}
		}

		html += '</' + itemTag + '>';
		return html;
	}

	// Minimal HTML escape.
	function esc( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	// -------------------------------------------------------------------------
	// REST query
	// -------------------------------------------------------------------------

	function queryLocations( params, callback ) {
		$.ajax( {
			url:      api + 'locations',
			type:     'GET',
			data:     params,
			headers:  { 'X-WP-Nonce': atcfFrontend.nonce },
			dataType: 'json',
		} )
			.done( function ( res ) {
				callback( null, res );
			} )
			.fail( function ( xhr ) {
				callback( xhr.responseJSON || { message: i18n.error } );
			} );
	}

	// -------------------------------------------------------------------------
	// Public geo-input API — used by ATCF and by HT's [htax_distance_field]
	// -------------------------------------------------------------------------

	// Visitor location chain, browser side. The server side (CDN headers, IP geolocation,
	// fallback location) lives in atcf_get_visitor_location(); see getServerLocation().
	var GEO_STORAGE_KEY = 'atcf_geo';
	var GEO_MAX_AGE_MS  = 7 * 24 * 60 * 60 * 1000;
	var serverLocationPromise = null;

	function rememberedLocation() {
		try {
			var saved = JSON.parse( window.localStorage.getItem( GEO_STORAGE_KEY ) || 'null' );
			if ( saved && Date.now() - saved.t < GEO_MAX_AGE_MS ) {
				return { lat: saved.lat, lng: saved.lng, source: 'browser' };
			}
		} catch ( e ) {
			// Storage unavailable (private mode, blocked) — nothing remembered.
		}
		return null;
	}

	function rememberLocation( loc ) {
		try {
			window.localStorage.setItem( GEO_STORAGE_KEY, JSON.stringify( { lat: loc.lat, lng: loc.lng, t: Date.now() } ) );
		} catch ( e ) {
			// Storage unavailable — the position is still used for this request.
		}
	}

	window.atcfGeo = {

		/**
		 * The browser's position: the current one (asks the visitor's permission once per
		 * site; HTTPS only), otherwise the last one the browser gave on this device
		 * (remembered for 7 days). Resolves to { lat, lng, source: 'browser' } or null.
		 */
		getBrowserLocation: function ( options ) {
			var timeout = ( options && options.timeout ) || 8000;
			return new Promise( function ( resolve ) {
				if ( ! navigator.geolocation || ! window.isSecureContext ) {
					resolve( rememberedLocation() );
					return;
				}
				navigator.geolocation.getCurrentPosition(
					function ( position ) {
						var loc = { lat: position.coords.latitude, lng: position.coords.longitude, source: 'browser' };
						rememberLocation( loc );
						resolve( loc );
					},
					function () { resolve( rememberedLocation() ); },
					{ timeout: timeout, maximumAge: 10 * 60 * 1000 }
				);
			} );
		},

		/**
		 * The server side of the chain (GET atcf/v1/visitor-location): CDN headers, IP
		 * geolocation, then the site's fallback location. Fetched once per page, only when
		 * asked for. Resolves to { lat, lng, country, postal_code, source } or null.
		 */
		getServerLocation: function () {
			if ( ! serverLocationPromise ) {
				serverLocationPromise = new Promise( function ( resolve ) {
					$.getJSON( atcfFrontend.restUrl + 'visitor-location' )
						.done( function ( data ) { resolve( ( data && data.location ) || null ); } )
						.fail( function () { resolve( null ); } );
				} );
			}
			return serverLocationPromise;
		},

		/**
		 * The whole chain: browser position, then the server side. Resolves to a location
		 * with its `source` ('browser', 'cdn', 'ip', 'fallback') or null. Callers that use a
		 * 'fallback' location should say so to the visitor (i18n.fallbackNote).
		 */
		getVisitorLocation: function ( options ) {
			return window.atcfGeo.getBrowserLocation( options ).then( function ( loc ) {
				return loc || window.atcfGeo.getServerLocation();
			} );
		},

		/**
		 * Reverse-geocode coordinates via Nominatim.
		 *
		 * @param {number}   lat
		 * @param {number}   lng
		 * @param {Function} callback  Called with { city, postalCode }, both nullable.
		 */
		reverseGeocode: function ( lat, lng, callback ) {
			fetch(
				'https://nominatim.openstreetmap.org/reverse?lat=' + lat + '&lon=' + lng + '&format=json',
				{ headers: { 'Accept-Language': navigator.language || 'en' } }
			)
				.then( function ( r ) { return r.json(); } )
				.then( function ( d ) {
					var a = ( d && d.address ) ? d.address : {};
					callback( {
						city:       a.city || a.town || a.village || a.municipality || null,
						postalCode: a.postcode || null,
					} );
				} )
				.catch( function () { callback( { city: null, postalCode: null } ); } );
		},

		/**
		 * Wire up a crosshair geo button and its associated postal input.
		 *
		 * config:
		 *   btn      {Element}  The crosshair <button> element.
		 *   input    {Element}  Optional: the <input> to populate with the city label.
		 *   label    {string}   Fallback label when reverse geocode fails.
		 *   onSuccess {Function(lat, lng, displayLabel)}
		 *   onDenied  {Function}  Called when permission is denied or unsupported.
		 *   onReset   {Function}  Called when the user edits the input after geo fills it.
		 */
		initGeoBtn: function ( config ) {
			var $btn   = $( config.btn );
			var $input = config.input ? $( config.input ) : $();
			var label  = config.label || 'Current Location';

			function stopLoading() {
				$btn.removeClass( 'atcf-geo-loading' );
				$input.prop( 'disabled', false );
			}

			$btn.on( 'click', function () {
				if ( $btn.hasClass( 'atcf-geo-loading' ) ) { return; }

				if ( ! navigator.geolocation ) {
					if ( config.onDenied ) { config.onDenied(); }
					return;
				}

				$btn.addClass( 'atcf-geo-loading' ).removeClass( 'atcf-geo-used' );
				$input.prop( 'disabled', true );

				var completed  = false;
				var timeoutId  = setTimeout( function () {
					if ( ! completed ) {
						completed = true;
						stopLoading();
					}
				}, 5000 );

				navigator.geolocation.getCurrentPosition(
					function ( position ) {
						if ( completed ) { return; }
						var lat = position.coords.latitude;
						var lng = position.coords.longitude;

						window.atcfGeo.reverseGeocode( lat, lng, function ( result ) {
							clearTimeout( timeoutId );
							completed = true;
							var displayLabel = result.postalCode || result.city || label;
							$input.val( displayLabel ).data( 'atcf-geo-value', displayLabel );
							stopLoading();
							$btn.addClass( 'atcf-geo-used' );
							if ( config.onSuccess ) { config.onSuccess( lat, lng, displayLabel ); }
						} );
					},
					function () {
						clearTimeout( timeoutId );
						completed = true;
						stopLoading();
						if ( config.onDenied ) { config.onDenied(); }
					},
					{ timeout: 10000 }
				);
			} );

			// Clear geo data when the user edits the value that geo populated.
			if ( $input.length ) {
				$input.on( 'input.atcfGeo', function () {
					if ( ! $btn.hasClass( 'atcf-geo-used' ) ) { return; }
					var geoValue = $input.data( 'atcf-geo-value' ) || '';
					if ( $input.val() !== geoValue ) {
						$input.removeData( 'atcf-geo-value' );
						$btn.removeClass( 'atcf-geo-used' );
						if ( config.onReset ) { config.onReset(); }
					}
				} );
			}
		}
	};

	// -------------------------------------------------------------------------
	// Shortcode / block finder form
	// -------------------------------------------------------------------------

	$( document ).on( 'click', '.atcf-finder-form .atcf-search, .atcf-location-finder .atcf-search', function () {
		var $wrapper = $( this ).closest( '.atcf-finder-form, .atcf-location-finder' );
		runSearch( $wrapper );
	} );

	$( document ).on( 'keydown', '.atcf-postal-input', function ( e ) {
		if ( 13 === e.which ) {
			var $wrapper = $( this ).closest( '.atcf-finder-form, .atcf-location-finder' );
			runSearch( $wrapper );
		}
	} );

	// Wire each ATCF crosshair button via the shared atcfGeo API at DOM ready.

	// Geo-only "Find nearby" button (no postal input): auto-search on click.
	$( document ).on( 'click', '.atcf-use-location', function () {
		var $btn     = $( this );
		var $wrapper = $btn.closest( '.atcf-finder-form, .atcf-location-finder' );

		$btn.prop( 'disabled', true );

		if ( ! navigator.geolocation ) {
			$btn.prop( 'disabled', false );
			return;
		}

		var $results = $wrapper.find( '.atcf-results' );
		$results.html( '<p class="atcf-loading">' + esc( i18n.locating ) + '</p>' );

		var _unit     = $wrapper.data( 'unit' ) || 'miles';
		var _format   = $wrapper.data( 'format' ) || 'ul';
		var _tpl      = $wrapper.data( 'item-template' ) || '';
		var _provider = $wrapper.data( 'directions-provider' ) || atcfFrontend.directionsProvider || 'google';

		window.atcfGeo.getVisitorLocation( { timeout: 10000 } ).then( function ( loc ) {
			$btn.prop( 'disabled', false );
			if ( ! loc ) {
				$results.html( '<p class="atcf-error">' + esc( i18n.denied ) + '</p>' );
				return;
			}
			renderResults( $results, {
				lat:                 loc.lat,
				lng:                 loc.lng,
				radius:              $wrapper.find( '.atcf-radius-select' ).val() || $wrapper.data( 'radius' ) || 10,
				unit:                _unit,
				taxonomy:            $wrapper.data( 'taxonomy' ) || '',
				limit:               $wrapper.data( 'limit' ) || 20,
				directions_provider: _provider,
			}, _unit, _format, _tpl, 'fallback' === loc.source );
		} );
	} );

	function runSearch( $wrapper ) {
		var $input   = $wrapper.find( '.atcf-postal-input' );
		var $results = $wrapper.find( '.atcf-results' );
		var unit     = $wrapper.data( 'unit' ) || 'miles';
		var radius   = $wrapper.find( '.atcf-radius-select' ).val() || $wrapper.data( 'radius' ) || 10;
		var country  = ( $wrapper.data( 'country' ) || 'US' ).toUpperCase();
		var format   = $wrapper.data( 'format' ) || 'ul';
		var tpl      = $wrapper.data( 'item-template' ) || '';
		var provider = $wrapper.data( 'directions-provider' ) || atcfFrontend.directionsProvider || 'google';

		var termIds = $wrapper.data( 'term-ids' );

		// If the crosshair icon was used, stored lat/lng take priority over the
		// display label in the input (which is a city name, not a postal code).
		var storedLat = $wrapper.data( 'geo-lat' );
		var storedLng = $wrapper.data( 'geo-lng' );
		if ( storedLat !== undefined && storedLng !== undefined ) {
			var coordParams = {
				lat:                 storedLat,
				lng:                 storedLng,
				radius:              radius,
				unit:                unit,
				taxonomy:            $wrapper.data( 'taxonomy' ) || '',
				limit:               $wrapper.data( 'limit' ) || 20,
				orderby:             $wrapper.data( 'orderby' ) || 'distance',
				directions_provider: provider,
			};
			if ( termIds && termIds.length ) {
				coordParams.term_ids = termIds;
			}
			renderResults( $results, coordParams, unit, format, tpl );
			return;
		}

		var raw = $.trim( $input.val() );
		if ( ! raw ) {
			return;
		}

		var postalCode = normalizePostalCode( raw, country );

		if ( ! isValidPostalCode( postalCode, country ) ) {
			$results.html( '<p class="atcf-error">' + esc( i18n.invalidPostalCode || 'Please enter a valid postal code.' ) + '</p>' );
			return;
		}

		var postalParams = {
			postal_code:         postalCode,
			radius:              radius,
			unit:                unit,
			country:             country,
			taxonomy:            $wrapper.data( 'taxonomy' ) || '',
			limit:               $wrapper.data( 'limit' ) || 20,
			orderby:             $wrapper.data( 'orderby' ) || 'distance',
			directions_provider: provider,
		};
		if ( termIds && termIds.length ) {
			postalParams.term_ids = termIds;
		}
		renderResults( $results, postalParams, unit, format, tpl );
	}

	function renderResults( $results, params, unit, format, tpl, usedFallback ) {
		format = format || 'ul';
		tpl    = tpl    || '';
		// Results near the site's fallback location (the visitor's couldn't be determined) say so.
		var note = usedFallback ? '<p class="atcf-location-note" role="status">' + esc( i18n.fallbackNote ) + '</p>' : '';

		$results.html( '<p class="atcf-loading">' + esc( i18n.loading ) + '</p>' );

		queryLocations( params, function ( err, res ) {
			if ( err ) {
				$results.html( '<p class="atcf-error">' + esc( err.message || i18n.error ) + '</p>' );
				return;
			}

			if ( ! res.locations || ! res.locations.length ) {
				$results.html( note + '<p class="atcf-no-results">' + esc( i18n.noResults ) + '</p>' );
				return;
			}

			var html;
			if ( 'table' === format ) {
				html  = '<table class="atcf-locations-table">';
				html += '<thead><tr><th>' + esc( i18n.colLabel ) + '</th><th>' + esc( i18n.colAddress ) + '</th><th>' + esc( i18n.colDistance ) + '</th><th></th></tr></thead>';
				html += '<tbody>';
				$.each( res.locations, function ( i, loc ) {
					html += buildLocationHTML( loc, unit, format, tpl );
				} );
				html += '</tbody></table>';
			} else {
				var tag = 'ol' === format ? 'ol' : ( 'div' === format ? 'div' : 'ul' );
				html = '<' + tag + ' class="atcf-locations-list">';
				$.each( res.locations, function ( i, loc ) {
					html += buildLocationHTML( loc, unit, format, tpl );
				} );
				html += '</' + tag + '>';
			}
			$results.html( note + html );
		} );
	}

	// -------------------------------------------------------------------------
	// Nearest location block
	// -------------------------------------------------------------------------

	function initNearestLocation( $block ) {
		var $prompt  = $block.find( '.atcf-nearest-prompt' );
		var $result  = $block.find( '.atcf-nearest-result' );
		var taxonomy = $block.data( 'taxonomy' ) || '';
		var termIds  = $block.data( 'term-ids' );
		var country  = $block.data( 'country' ) || 'US';
		var unit     = $block.data( 'unit' ) || 'miles';
		var showDist = '1' === String( $block.data( 'show-distance' ) );
		var tpl      = $block.data( 'item-template' ) || '';
		var provider = $block.data( 'directions-provider' ) || atcfFrontend.directionsProvider || 'google';

		function resolveNearest( lat, lng ) {
			var params = {
				lat:                 lat,
				lng:                 lng,
				taxonomy:            taxonomy,
				country:             country,
				unit:                unit,
				limit:               1,
				directions_provider: provider,
			};
			if ( termIds && termIds.length ) {
				params.term_ids = termIds;
			}
			queryLocations( params, function ( err, res ) {
				$prompt.hide();
				if ( err || ! res.locations || ! res.locations.length ) {
					$result.html( '<p class="atcf-no-results">' + esc( i18n.noResults ) + '</p>' );
					return;
				}
				var loc  = res.locations[0];
				var html = '<div class="atcf-location">';
				if ( tpl ) {
					html += applyLocationTemplate( tpl, loc, unit );
				} else {
					if ( loc.label ) {
						html += '<strong class="atcf-location-label">' + esc( loc.label ) + '</strong>';
					}
					if ( loc.term_name ) {
						html += '<span class="atcf-term-name">' + esc( loc.term_name ) + '</span>';
					}
					var addressParts = [ loc.address_line, loc.address_line2 ].filter( Boolean );
					var cityLine     = formatCityLine( loc );
					if ( addressParts.length || cityLine ) {
						html += '<address>';
						if ( addressParts.length ) {
							html += esc( addressParts.join( ', ' ) ) + '<br/>';
						}
						if ( cityLine ) {
							html += esc( cityLine );
						}
						html += '</address>';
					}
					if ( showDist && loc.distance_km !== undefined ) {
						html += '<span class="atcf-distance">' + esc( formatDistance( loc.distance_km, unit ) ) + '</span>';
					}
					if ( loc.directions_url ) {
						html += ' <a href="' + esc( loc.directions_url ) + '" class="atcf-directions" target="_blank" rel="noopener">Directions</a>';
					}
				}
				html += '</div>';
				$result.html( html );
			} );
		}

		// The visitor's location: browser first, then CDN / IP / fallback location.
		window.atcfGeo.getVisitorLocation().then( function ( loc ) {
			if ( ! loc ) {
				$prompt.hide();
				return;
			}
			if ( 'fallback' === loc.source ) {
				$block.prepend( '<p class="atcf-location-note" role="status">' + esc( i18n.fallbackNote ) + '</p>' );
			}
			resolveNearest( loc.lat, loc.lng );
		} );
	}

	// -------------------------------------------------------------------------
	// Init
	// -------------------------------------------------------------------------

	$( function () {
		// Apply country-specific postal input rules (maxlength, etc.).
		$( '.atcf-finder-form, .atcf-location-finder' ).each( function () {
			var $wrapper = $( this );
			var country  = ( $wrapper.data( 'country' ) || 'US' ).toUpperCase();
			applyPostalInputRules( $wrapper.find( '.atcf-postal-input' ), country );

			// Wire the crosshair geo button via the shared API.
			var $btn = $wrapper.find( '.atcf-geo-btn' );
			if ( $btn.length ) {
				var $input = $wrapper.find( '.atcf-postal-input' );
				window.atcfGeo.initGeoBtn( {
					btn:   $btn[ 0 ],
					input: $input[ 0 ] || null,
					label: i18n.currentLocation || 'Current Location',
					onSuccess: function ( lat, lng ) {
						$wrapper.data( 'geo-lat', lat ).data( 'geo-lng', lng );
					},
					onReset: function () {
						$wrapper.removeData( 'geo-lat' ).removeData( 'geo-lng' );
					}
				} );
			}
		} );

		// Pre-fill empty postal code inputs with the visitor's postal code (CDN / IP side of the
		// chain). Done here rather than in the page's HTML, which page caches would share.
		var $emptyPostal = $( '.atcf-postal-input' ).filter( function () { return ! $( this ).val(); } );
		if ( $emptyPostal.length ) {
			window.atcfGeo.getServerLocation().then( function ( loc ) {
				if ( loc && loc.postal_code && 'fallback' !== loc.source ) {
					$emptyPostal.each( function () {
						if ( ! $( this ).val() ) {
							$( this ).val( loc.postal_code );
						}
					} );
				}
			} );
		}

		// Initialize nearest-location blocks.
		$( '.atcf-nearest-location' ).each( function () {
			initNearestLocation( $( this ) );
		} );
	} );

} )( jQuery );
