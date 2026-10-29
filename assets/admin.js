/* As the Crow Flies — Admin JS */
/* global jQuery, atcfAdmin */

( function ( $ ) {
	'use strict';

	// -------------------------------------------------------------------------
	// Screen Options — column and filter visibility
	// -------------------------------------------------------------------------

	// Maps Screen Options column key → selector for the corresponding data cells.
	// Headers use class="column-{key}" (WP's screen.js handles those automatically).
	var atcfColCellMap = {
		label:          '.atcf-col-label',
		address:        '.atcf-col-address',
		locality:       '.atcf-col-locality',
		region:         '.atcf-col-region',
		postal_code:    '.atcf-col-postal',
		country:        '.atcf-col-country',
		coords:         '.atcf-col-coords',
		geocode_status: '.atcf-col-geocode',
		actions:        null,   // no dedicated cell class; header-only toggling is enough
	};

	function atcfSetColumnVisible( col, visible ) {
		var sel = atcfColCellMap[ col ];
		if ( sel ) {
			$( '.atcf-locations-table' ).find( sel ).toggleClass( 'hidden', ! visible );
		}
		$( '[data-atcf-filter-col="' + col + '"]' ).toggle( visible );
	}

	// Apply saved hidden-column state on page load (headers handled by WP JS;
	// we handle data cells and filter inputs here).
	$( function () {
		( atcfAdmin.hiddenColumns || [] ).forEach( function ( col ) {
			atcfSetColumnVisible( col, false );
		} );
	} );

	// Sync when the user toggles a column checkbox in Screen Options.
	$( document ).on( 'click', '.hide-column-tog', function () {
		atcfSetColumnVisible( $( this ).val(), this.checked );
	} );

	// -------------------------------------------------------------------------
	// Clear all locations for a term
	// -------------------------------------------------------------------------

	// -------------------------------------------------------------------------
	// Global page: Clear all coords / Delete all locations
	// -------------------------------------------------------------------------

	function atcfGlAction( action, confirmMsg, ajaxAction, $btn ) {
		if ( ! window.confirm( confirmMsg ) ) { return; }
		var taxonomy = $btn.data( 'taxonomy' ) || '';
		var origText = $btn.text();
		var $status  = $btn.closest( 'div' ).find( '.atcf-gl-action-status' );
		$btn.prop( 'disabled', true ).text( atcfAdmin.i18n.saving || 'Working…' );
		$.post( atcfAdmin.ajaxUrl, { action: ajaxAction, taxonomy: taxonomy, nonce: $btn.data( 'nonce' ) } )
			.done( function ( resp ) {
				if ( resp.success ) {
					window.location.reload();
				} else {
					$status.text( ( resp.data && resp.data.message ) || atcfAdmin.i18n.error );
					$btn.prop( 'disabled', false ).text( origText );
				}
			} )
			.fail( function () {
				$status.text( atcfAdmin.i18n.error );
				$btn.prop( 'disabled', false ).text( origText );
			} );
	}

	$( document ).on( 'click', '.atcf-gl-clear-all-coords', function ( e ) {
		e.preventDefault();
		atcfGlAction( 'clear', atcfAdmin.confirmGlClearCoords || 'Clear all coordinates?', 'atcf_gl_clear_all_coords', $( this ) );
	} );

	$( document ).on( 'click', '.atcf-gl-delete-all', function ( e ) {
		e.preventDefault();
		atcfGlAction( 'delete', atcfAdmin.confirmGlDeleteAll || 'Delete all locations? This cannot be undone.', 'atcf_gl_delete_all', $( this ) );
	} );

	$( document ).on( 'click', '.atcf-delete-all-locations', function ( e ) {
		e.preventDefault();
		if ( ! window.confirm( atcfAdmin.confirmDeleteAll || 'Delete all locations for this term? This cannot be undone.' ) ) {
			return;
		}
		var $btn     = $( this );
		var origText = $btn.text();
		$btn.prop( 'disabled', true ).text( atcfAdmin.i18n.deleting || 'Deleting…' );
		$.post( atcfAdmin.ajaxUrl, {
			action:   'atcf_clear_term_locations',
			nonce:    atcfAdmin.nonce,
			term_id:  $btn.data( 'term-id' ),
			taxonomy: $btn.data( 'taxonomy' ),
		} )
			.done( function ( response ) {
				if ( response.success ) {
					window.location.reload();
				} else {
					alert( ( response.data && response.data.message ) || atcfAdmin.i18n.error );
					$btn.prop( 'disabled', false ).text( origText );
				}
			} )
			.fail( function () {
				alert( atcfAdmin.i18n.error );
				$btn.prop( 'disabled', false ).text( origText );
			} );
	} );

	$( document ).on( 'click', '.atcf-clear-locations', function ( e ) {
		e.preventDefault();

		if ( ! window.confirm( atcfAdmin.confirmClearCoords || 'Clear all coordinates for this term?' ) ) {
			return;
		}

		var $btn     = $( this );
		var termId   = $btn.data( 'term-id' );
		var taxonomy = $btn.data( 'taxonomy' );
		var origText = $btn.text();

		$btn.prop( 'disabled', true ).text( atcfAdmin.i18n.clearingCoords || 'Clearing…' );

		$.post( atcfAdmin.ajaxUrl, {
			action:   'atcf_clear_term_coords',
			nonce:    atcfAdmin.nonce,
			term_id:  termId,
			taxonomy: taxonomy,
		} )
			.done( function ( response ) {
				if ( response.success ) {
					window.location.reload();
				} else {
					alert( ( response.data && response.data.message ) || atcfAdmin.i18n.error );
					$btn.prop( 'disabled', false ).text( origText );
				}
			} )
			.fail( function () {
				alert( atcfAdmin.i18n.error );
				$btn.prop( 'disabled', false ).text( origText );
			} );
	} );

	// -------------------------------------------------------------------------
	// Term-scoped CSV upload (AJAX form)
	// -------------------------------------------------------------------------

	$( document ).on( 'submit', '.atcf-upload-form', function ( e ) {
		e.preventDefault();

		var $form   = $( this );
		var $result = $form.find( '.atcf-upload-result' );
		var $submit = $form.find( '[type="submit"]' );
		var formData = new FormData( $form[0] );

		formData.append( 'action',   'atcf_upload_term_csv' );
		formData.append( 'term_id',  $form.data( 'term-id' ) );
		formData.append( 'taxonomy', $form.data( 'taxonomy' ) );

		$submit.prop( 'disabled', true ).text( atcfAdmin.i18n.saving );
		$result.hide().removeClass( 'error' );

		$.ajax( {
			url:         atcfAdmin.ajaxUrl,
			type:        'POST',
			data:        formData,
			processData: false,
			contentType: false,
		} )
			.done( function ( response ) {
				$result.show();
				if ( response.success ) {
					var d    = response.data;
					var text = 'Imported: ' + ( d.inserted || 0 ) + ' added, '
						+ ( d.updated || 0 ) + ' updated, '
						+ ( d.skipped || 0 ) + ' skipped.';

					if ( d.non_us_without_coords > 0 ) {
						text += ' ' + d.non_us_without_coords
							+ ' non-US address'
							+ ( d.non_us_without_coords === 1 ? ' has' : 'es have' )
							+ ' no coordinates. Export them, add coordinates via'
							+ ' Geocodio or OpenCage, and re-import with lat/lng filled in.';
					}

					$result.text( text );
					// Reload page to refresh location count.
					setTimeout( function () {
						window.location.reload();
					}, 1500 );
				} else {
					$result.addClass( 'error' ).text( response.data.message || atcfAdmin.i18n.error );
				}
			} )
			.fail( function () {
				$result.show().addClass( 'error' ).text( atcfAdmin.i18n.error );
			} )
			.always( function () {
				$submit.prop( 'disabled', false ).text( atcfAdmin.i18n.importBtn || 'Import' );
			} );
	} );

	// -------------------------------------------------------------------------
	// Term list: inject the Locations filter into the tablenav via JS
	// (restrict_manage_{taxonomy} does not fire in WP_Terms_List_Table)
	// -------------------------------------------------------------------------

	if ( atcfAdmin.locationFilter ) {
		var lf = atcfAdmin.locationFilter;

		var $sel = $( '<select id="atcf_has_locations" name="atcf_has_locations"></select>' );
		$sel.css( 'max-width', '130px' );
		$sel.append( $( '<option>' ).val( '' ).text( lf.all ) );
		$sel.append( $( '<option>' ).val( 'yes' ).text( lf.yes ).prop( 'selected', lf.current === 'yes' ) );
		$sel.append( $( '<option>' ).val( 'no' ).text( lf.no ).prop( 'selected', lf.current === 'no' ) );

		if ( lf.countries && lf.countries.length ) {
			var $countryGrp = $( '<optgroup>' ).attr( 'label', lf.byCountry );
			lf.countries.forEach( function ( code ) {
				var val = 'country:' + code;
				$countryGrp.append(
					$( '<option>' ).val( val ).text( code ).prop( 'selected', lf.current === val )
				);
			} );
			$sel.append( $countryGrp );
		}

		// Inject into the search form — same insertion point as HT's filters.
		// Apply CSS positioning to align with the bulk actions row, then fire
		// atcf:termFilterInserted so HT (or any other plugin) can absorb the
		// container into its own shared row if it is active.
		var $searchForm = $( '#col-right .search-form' ).first();
		if ( ! $searchForm.length ) {
			$searchForm = $( '.wrap .search-form' ).first();
		}
		if ( $searchForm.length ) {
			var $container = $( '<div class="atcf-term-filters"></div>' );
			$searchForm.prepend( $container );
			$container.append( $sel );

			// Show or hide the filter to match the Locations column visibility.
			// The same filter is also output by PHP via restrict_manage_{taxonomy};
			// both need to stay in sync with Screen Options.
			function syncAtcfFilterVisibility() {
				var $col = $( '.wp-list-table thead th.column-atcf_locations' );
				var $phpFilter = $( 'select[name="atcf_has_locations"]' ).not( $sel );
				var isHidden = $col.length && ( $col.hasClass( 'hidden' ) || $col.css( 'display' ) === 'none' );
				if ( isHidden ) {
					$sel.hide();
					$container.hide();
					$phpFilter.closest( 'label, span, div' ).addBack().hide();
				} else {
					$sel.show();
					$container.show();
					$phpFilter.closest( 'label, span, div' ).addBack().show();
				}
			}

			// Initial sync.
			syncAtcfFilterVisibility();

			// Re-sync whenever Screen Options toggles a column (WP adds/removes
			// the 'hidden' class on <th> elements without a page reload).
			var $tableHead = $( '.wp-list-table thead' );
			if ( $tableHead.length && window.MutationObserver ) {
				var atcfObserver = new MutationObserver( function ( mutations ) {
					var needsSync = false;
					mutations.forEach( function ( m ) {
						if ( m.type === 'attributes' && m.attributeName === 'class' ) {
							needsSync = true;
						}
					} );
					if ( needsSync ) {
						syncAtcfFilterVisibility();
					}
				} );
				$tableHead.find( 'th' ).each( function () {
					atcfObserver.observe( this, { attributes: true } );
				} );
			}

			// Notify other plugins first — if HT absorbs the container
			// it will be removed from the search form, so we should not
			// then also try to position it.
			$( document ).trigger( 'atcf:termFilterInserted', [ $container ] );

			// If the container is still in the DOM (HT did not absorb it),
			// apply the same positioning HT uses to align with bulk actions.
			setTimeout( function () {
				if ( ! $.contains( document, $container[0] ) ) {
					return;
				}
				var $bulkActions = $( '.tablenav.top .bulkactions' ).first();
				if ( $bulkActions.length && $searchForm.length ) {
					var leftOffset = $bulkActions.offset().left - $searchForm.offset().left;
					$container.css( {
						display:     'inline-block',
						float:       'left',
						position:    'relative',
						left:        leftOffset + 'px',
						margin:      '0 10px 0 0',
						paddingTop:  '11px',
						lineHeight:  '28px',
					} );
				}
			}, 100 );
		}
	}

	// -------------------------------------------------------------------------
	// Locations table — select-all checkbox behaviour
	// -------------------------------------------------------------------------

	$( document ).on( 'change', '#cb-select-all-1', function () {
		$( '#atcf-locations-form input[name="location_ids[]"]' ).prop( 'checked', $( this ).prop( 'checked' ) );
	} );

	// Sync select-all state when individual checkboxes change.
	$( document ).on( 'change', '#atcf-locations-form input[name="location_ids[]"]', function () {
		var $boxes = $( '#atcf-locations-form input[name="location_ids[]"]' );
		$( '#cb-select-all-1' ).prop( 'checked', $boxes.length > 0 && $boxes.length === $boxes.filter( ':checked' ).length );
	} );

	// Global Locations page — select-all checkbox.
	$( document ).on( 'change', '#cb-gl-select-all', function () {
		$( 'input[name="location_ids[]"]' ).prop( 'checked', $( this ).prop( 'checked' ) );
	} );
	$( document ).on( 'change', 'input[name="location_ids[]"]', function () {
		var $boxes = $( 'input[name="location_ids[]"]' );
		$( '#cb-gl-select-all' ).prop( 'checked', $boxes.length > 0 && $boxes.length === $boxes.filter( ':checked' ).length );
	} );

	// -------------------------------------------------------------------------
	// Locations filter dropdown — navigate on change, no submit button needed
	// -------------------------------------------------------------------------

	$( document ).on( 'change', '#atcf_has_locations', function () {
		var url = new URL( window.location.href );
		var val = $( this ).val();
		if ( val ) {
			url.searchParams.set( 'atcf_has_locations', val );
		} else {
			url.searchParams.delete( 'atcf_has_locations' );
		}
		url.searchParams.delete( 'paged' );
		window.location.href = url.toString();
	} );

	// -------------------------------------------------------------------------
	// Per-row geocode
	// -------------------------------------------------------------------------

	$( document ).on( 'click', '.atcf-geocode-link', function ( e ) {
		e.preventDefault();
		var $link  = $( this );
		var id     = $link.data( 'id' );
		var nonce  = $link.data( 'nonce' );
		var $row   = $link.closest( 'tr' );
		var $cell  = $row.find( '.atcf-col-geocode' );

		if ( $link.data( 'processing' ) ) { return; }
		$link.data( 'processing', true );
		$cell.text( atcfAdmin.geocodeLabels.geocoding || 'Geocoding…' );

		$.post( atcfAdmin.ajaxUrl, { action: 'atcf_geocode_location', location_id: id, nonce: nonce } )
			.done( function ( resp ) {
				if ( resp.success ) {
					// geocode_status is null for user-provided; map it to its label key.
					var raw    = resp.data.geocode_status;
					var label  = raw !== null
						? ( atcfAdmin.geocodeLabels[ raw ] || raw )
						: ( atcfAdmin.geocodeLabels.user_provided || 'User-provided' );
					$cell.text( label );
					if ( resp.data.lat ) {
						$row.find( '.atcf-col-coords' )
							.empty()
							.append( $( '<span class="dashicons dashicons-yes-alt"></span>' ).attr( 'title', resp.data.lat + ',' + resp.data.lng ) );
					}
				} else {
					$cell.text( '' );
					alert( ( resp.data && resp.data.message ) || atcfAdmin.i18n.error );
				}
			} )
			.fail( function () {
				$cell.text( '' );
				alert( atcfAdmin.i18n.error );
			} )
			.always( function () { $link.removeData( 'processing' ); } );
	} );

	// -------------------------------------------------------------------------
	// Clear coords action
	// -------------------------------------------------------------------------

	$( document ).on( 'click', '.atcf-clear-coords-link', function ( e ) {
		e.preventDefault();
		var $link = $( this );
		var id    = $link.data( 'id' );
		var nonce = $link.data( 'nonce' );
		var $row  = $( '#atcf-loc-' + id );

		$link.text( atcfAdmin.i18n.saving || 'Clearing…' ).prop( 'disabled', true );

		$.post( atcfAdmin.ajaxUrl, { action: 'atcf_clear_location_coords', location_id: id, nonce: nonce } )
			.done( function ( resp ) {
				if ( resp.success ) {
					$row.find( '.atcf-col-coords' ).html( '—' );
					$row.find( '.atcf-col-geocode' ).text( atcfAdmin.geocodeLabels.pending || 'Pending' );
				} else {
					alert( ( resp.data && resp.data.message ) || atcfAdmin.i18n.error );
				}
			} )
			.fail( function () { alert( atcfAdmin.i18n.error ); } )
			.always( function () { $link.text( atcfAdmin.i18n.clearCoords || 'Clear coords' ).prop( 'disabled', false ); } );
	} );

	// -------------------------------------------------------------------------
	// Per-term geocode button
	// -------------------------------------------------------------------------

	$( document ).on( 'click', '.atcf-geocode-term-btn', function () {
		var $btn      = $( this );
		var $status   = $btn.siblings( '.atcf-geocode-term-status' );
		var term_id   = $btn.data( 'term-id' );
		var taxonomy  = $btn.data( 'taxonomy' );
		var nonce     = $btn.data( 'nonce' );
		$btn.prop( 'disabled', true );
		$status.text( atcfAdmin.i18n.saving || 'Geocoding…' );

		$.post( atcfAdmin.ajaxUrl, { action: 'atcf_geocode_term', term_id: term_id, taxonomy: taxonomy, nonce: nonce } )
			.done( function ( resp ) {
				if ( resp.success ) {
					var n = resp.data.processed;
					$status.text( n > 0 ? n + ' location(s) processed.' : 'No geocodable locations found.' );
					if ( n > 0 ) { window.location.reload(); }
				} else {
					$status.text( ( resp.data && resp.data.message ) || atcfAdmin.i18n.error );
				}
			} )
			.fail( function () { $status.text( atcfAdmin.i18n.error ); } )
			.always( function () { $btn.prop( 'disabled', false ); } );
	} );

	// -------------------------------------------------------------------------
	// Inline edit
	// -------------------------------------------------------------------------

	$( document ).on( 'click', '.atcf-quick-edit-link', function ( e ) {
		e.preventDefault();
		var $link = $( this );
		var id    = $link.data( 'id' );
		var $row  = $( '#atcf-inline-' + id );
		var $data = $( '#atcf-loc-' + id );

		// Close any other open inline rows, restoring their data rows.
		$( '.atcf-inline-edit-row' ).not( $row ).each( function () {
			var otherId = $( this ).attr( 'id' ).replace( 'atcf-inline-', '' );
			$( this ).hide();
			$( '#atcf-loc-' + otherId ).show();
		} );

		if ( $row.is( ':visible' ) ) {
			// Toggle off: restore the data row.
			$row.hide();
			$data.show();
			return;
		}

		// Populate fields from data attributes.
		$row.find( '.atcf-ie-label' ).val( $link.data( 'label' ) );
		$row.find( '.atcf-ie-address' ).val( $link.data( 'address' ) );
		$row.find( '.atcf-ie-address2' ).val( $link.data( 'address2' ) );
		$row.find( '.atcf-ie-locality' ).val( $link.data( 'locality' ) );
		$row.find( '.atcf-ie-region' ).val( $link.data( 'region' ) );
		$row.find( '.atcf-ie-postal' ).val( $link.data( 'postal' ) );
		$row.find( '.atcf-ie-country' ).val( $link.data( 'country' ) );
		$row.find( '.atcf-ie-lat' ).val( $link.data( 'lat' ) );
		$row.find( '.atcf-ie-lng' ).val( $link.data( 'lng' ) );
		$row.find( '.atcf-ie-status' ).text( '' );

		// Hide the data row and show the edit row in its place.
		$data.hide();
		$row.show();
	} );

	$( document ).on( 'click', '.atcf-ie-cancel', function () {
		var id = $( this ).data( 'id' );
		$( '#atcf-inline-' + id ).hide();
		$( '#atcf-loc-' + id ).show();
	} );

	$( document ).on( 'click', '.atcf-ie-save', function () {
		var $btn  = $( this );
		var id    = $btn.data( 'id' );
		var $row  = $( '#atcf-inline-' + id );
		var $data = $( '#atcf-loc-' + id );
		var $status = $row.find( '.atcf-ie-status' );

		$btn.prop( 'disabled', true );
		$status.text( atcfAdmin.i18n.saving || 'Saving…' );

		$.post( atcfAdmin.ajaxUrl, {
			action:       'atcf_save_location_inline',
			nonce:        $btn.data( 'nonce' ),
			location_id:  id,
			term_id:      $btn.data( 'term-id' ),
			taxonomy:     $btn.data( 'taxonomy' ),
			label:        $row.find( '.atcf-ie-label' ).val(),
			address_line: $row.find( '.atcf-ie-address' ).val(),
			address_line2:$row.find( '.atcf-ie-address2' ).val(),
			locality:     $row.find( '.atcf-ie-locality' ).val(),
			region:       $row.find( '.atcf-ie-region' ).val(),
			postal_code:  $row.find( '.atcf-ie-postal' ).val(),
			country:      $row.find( '.atcf-ie-country' ).val(),
			lat:          $row.find( '.atcf-ie-lat' ).val(),
			lng:          $row.find( '.atcf-ie-lng' ).val()
		} )
		.done( function ( resp ) {
			if ( resp.success ) {
				var d = resp.data;
				// Update data row cells.
				$data.find( '.atcf-col-label' ).text( d.label );
				var $addrCell = $data.find( '.atcf-col-address' ).empty();
				$addrCell.append( document.createTextNode( d.address_line ) );
				if ( d.address_line2 ) {
					$addrCell.append( $( '<br>' ) ).append( $( '<small>' ).text( d.address_line2 ) );
				}
				$data.find( '.atcf-col-locality' ).text( d.locality );
				$data.find( '.atcf-col-region' ).text( d.region );
				$data.find( '.atcf-col-postal' ).text( d.postal_code );
				$data.find( '.atcf-col-country' ).text( d.country );
				if ( d.lat ) {
					$data.find( '.atcf-col-coords' )
						.empty()
						.append( $( '<span class="dashicons dashicons-yes-alt"></span>' ).attr( 'title', d.lat + ',' + d.lng ) );
				} else {
					$data.find( '.atcf-col-coords' ).text( '—' );
				}
				$data.find( '.atcf-col-geocode' ).text(
					d.geocode_status !== null
						? ( atcfAdmin.geocodeLabels[ d.geocode_status ] || d.geocode_status )
						: ( atcfAdmin.geocodeLabels.user_provided || 'User-provided' )
				);
				// Update Edit link data attributes for next open.
				$data.find( '.atcf-quick-edit-link' )
					.data( 'label', d.label ).data( 'address', d.address_line )
					.data( 'address2', d.address_line2 ).data( 'locality', d.locality )
					.data( 'region', d.region ).data( 'postal', d.postal_code )
					.data( 'country', d.country ).data( 'lat', d.lat || '' ).data( 'lng', d.lng || '' );
				$row.hide();
				$data.show();
			} else {
				$status.text( ( resp.data && resp.data.message ) || atcfAdmin.i18n.error );
			}
		} )
		.fail( function () { $status.text( atcfAdmin.i18n.error ); } )
		.always( function () { $btn.prop( 'disabled', false ); } );
	} );

	// Escape key closes any open inline edit row and restores the data row.
	$( document ).on( 'keydown', function ( e ) {
		if ( e.key !== 'Escape' ) { return; }
		$( '.atcf-inline-edit-row:visible' ).each( function () {
			var id = $( this ).attr( 'id' ).replace( 'atcf-inline-', '' );
			$( this ).hide();
			$( '#atcf-loc-' + id ).show();
		} );
	} );

	// -------------------------------------------------------------------------
	// Import conflict detection — coordinates proximity sub-row
	// -------------------------------------------------------------------------

	function atcfInitProximityRow( $fieldset ) {
		var $row    = $fieldset.find( '.atcf-coords-proximity-row' );
		var $radius = $row.find( '.atcf-coords-radius-input' );
		var $unit   = $row.find( '.atcf-coords-subunit' );
		$radius.val( atcfAdmin.locationMatchRadius || 0 );
		$unit.text( atcfAdmin.distanceSubUnit || '' );
		// Match the 'change' handler's visibility so a persisted checked state
		// (the "Coordinates" checkbox is pre-populated from the saved preference)
		// doesn't render with the row incorrectly hidden on page load.
		$row.toggle( $fieldset.find( '.atcf-conflict-coords' ).prop( 'checked' ) );
	}

	// Disable the checked checkbox when it is the only one of the two providing coverage.
	function atcfSyncConflictLock( $container ) {
		var $addr   = $container.find( '.atcf-conflict-address' );
		var $coords = $container.find( '.atcf-conflict-coords' );
		var a = $addr.prop( 'checked' );
		var c = $coords.prop( 'checked' );
		$addr.prop( 'disabled', a && !c );
		$coords.prop( 'disabled', c && !a );
	}

	$( function () {
		$( '.atcf-conflict-detection' ).each( function () {
			atcfInitProximityRow( $( this ) );
			atcfSyncConflictLock( $( this ) );
		} );
	} );

	$( document ).on( 'change', '.atcf-conflict-address', function () {
		atcfSyncConflictLock( $( this ).closest( '.atcf-conflict-detection' ) );
	} );

	$( document ).on( 'change', '.atcf-conflict-coords', function () {
		var $container = $( this ).closest( '.atcf-conflict-detection' );
		var $row = $container.find( '.atcf-coords-proximity-row' );
		$row.toggle( this.checked );
		if ( ! this.checked ) {
			$row.find( '.atcf-coords-mode[value="exact"]' ).prop( 'checked', true );
			$row.find( '.atcf-coords-radius-input' ).prop( 'disabled', true );
		}
		atcfSyncConflictLock( $container );
	} );

	$( document ).on( 'change', '.atcf-coords-mode', function () {
		var $input = $( this ).closest( '.atcf-coords-proximity-row' ).find( '.atcf-coords-radius-input' );
		$input.prop( 'disabled', $( this ).val() !== 'within' );
	} );

} )( jQuery );
