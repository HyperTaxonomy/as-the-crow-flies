import { registerBlockType } from '@wordpress/blocks';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	InspectorControls,
	useBlockProps,
} from '@wordpress/block-editor';
import {
	BaseControl,
	CheckboxControl,
	FormTokenField,
	PanelBody,
	SearchControl,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useState, Fragment } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { decodeEntities } from '@wordpress/html-entities';
import metadata from './block.json';

registerBlockType( metadata.name, {
	edit( { attributes, setAttributes } ) {
		const {
			taxonomy,
			termIds,
			groupIds,
			country,
			distances,
			unit,
			limit,
			orderby,
			format,
			itemTemplate,
			showGeolocation,
			directionsProvider,
		} = attributes;

		const htaxGroups = ( window.atcfEditorData && window.atcfEditorData.htaxGroups ) || [];
		const htaxActive = !! ( window.atcfEditorData && window.atcfEditorData.htaxActive );

		const providerOptions = [
			{ label: __( 'Site default', 'as-the-crow-flies' ),  value: '' },
			{ label: __( 'Google Maps', 'as-the-crow-flies' ),    value: 'google' },
			{ label: __( 'Apple Maps', 'as-the-crow-flies' ),     value: 'apple' },
			{ label: __( 'Waze', 'as-the-crow-flies' ),           value: 'waze' },
			{ label: __( 'OpenStreetMap', 'as-the-crow-flies' ),  value: 'osm' },
		];

		const blockProps = useBlockProps( {
			className: 'atcf-location-finder-editor',
		} );

		const [ termSearch, setTermSearch ] = useState( '' );
		const [ groupSearch, setGroupSearch ] = useState( '' );

		const enabledCountries = ( window.atcfEditorData && window.atcfEditorData.countries ) || [];
		const countryOptions = enabledCountries.map( ( c ) => ( { label: c.label, value: c.code } ) );

		const taxonomies = useSelect( ( select ) => {
			const allTaxonomies = select( 'core' ).getTaxonomies( { per_page: -1 } );
			return allTaxonomies
				? allTaxonomies
					.filter( ( t ) => t.visibility?.publicly_queryable )
					.map( ( t ) => ( { label: t.name + ' (' + t.slug + ')', value: t.slug } ) )
				: [];
		}, [] );

		const terms = useSelect( ( select ) => {
			if ( ! taxonomy ) {
				return [];
			}
			return select( 'core' ).getEntityRecords( 'taxonomy', taxonomy, { per_page: -1 } ) || [];
		}, [ taxonomy ] );

		const taxonomyOptions = [
			{ label: __( '— All taxonomies —', 'as-the-crow-flies' ), value: '' },
			...taxonomies,
		];

		// Build a decoded-name → id map so FormTokenField shows readable names.
		// Disambiguate duplicate decoded names (realistic in a hierarchical taxonomy,
		// e.g. the same name under two different parents) so the name shown always
		// resolves back to a single, correct term ID.
		const termNameCounts = {};
		terms.forEach( ( t ) => {
			const n = decodeEntities( t.name );
			termNameCounts[ n ] = ( termNameCounts[ n ] || 0 ) + 1;
		} );
		const termLabel = ( t ) => {
			const n = decodeEntities( t.name );
			return termNameCounts[ n ] > 1
				/* translators: 1: term name, 2: term ID (shown only when two terms share a name) */
				? sprintf( __( '%1$s (ID: %2$d)', 'as-the-crow-flies' ), n, t.id )
				: n;
		};

		const termNameMap = {};
		terms.forEach( ( t ) => { termNameMap[ termLabel( t ) ] = t.id; } );

		const allTermSuggestions = Object.keys( termNameMap );
		const termSuggestions = termSearch.length >= 2 ? allTermSuggestions : [];

		const selectedTermNames = ( termIds || [] )
			.map( ( id ) => {
				const t = terms.find( ( tt ) => tt.id === id );
				return t ? termLabel( t ) : null;
			} )
			.filter( ( n ) => null !== n );

		// Build group tree for hierarchical checklist.
		const groupMap = {};
		const groupChildren = {};
		htaxGroups.forEach( ( g ) => { groupMap[ g.id ] = g; groupChildren[ g.id ] = []; } );
		htaxGroups.forEach( ( g ) => {
			if ( g.parent && groupChildren[ g.parent ] !== undefined ) {
				groupChildren[ g.parent ].push( g.id );
			}
		} );
		const rootGroupIds = htaxGroups
			.filter( ( g ) => ! g.parent || groupChildren[ g.parent ] === undefined )
			.map( ( g ) => g.id );
		const lowerGroupSearch = groupSearch.toLowerCase();
		const groupOrDescendantMatches = ( id ) => {
			const g = groupMap[ id ];
			if ( ! g ) return false;
			if ( decodeEntities( g.name ).toLowerCase().includes( lowerGroupSearch ) ) return true;
			return ( groupChildren[ id ] || [] ).some( ( cid ) => groupOrDescendantMatches( cid ) );
		};
		const renderGroupItem = ( id, depth, index = 0 ) => {
			if ( lowerGroupSearch && ! groupOrDescendantMatches( id ) ) return null;
			const g = groupMap[ id ];
			const marginTop = ( depth === 0 && index === 0 ) ? undefined : '8px';
			return (
				<Fragment key={ id }>
					<div style={ { paddingLeft: depth * 18 + 'px', marginTop } }>
						<CheckboxControl
							label={ decodeEntities( g.name ) }
							checked={ ( groupIds || [] ).includes( id ) }
							onChange={ ( checked ) => {
								const next = checked
									? [ ...( groupIds || [] ), id ]
									: ( groupIds || [] ).filter( ( i ) => i !== id );
								setAttributes( { groupIds: next } );
							} }
						/>
					</div>
					{ ( groupChildren[ id ] || [] ).map( ( cid, i ) => renderGroupItem( cid, depth + 1, i ) ) }
				</Fragment>
			);
		};

		return (
			<>
				<InspectorControls>
					<PanelBody title={ __( 'Location Finder Settings', 'as-the-crow-flies' ) }>
						<SelectControl
							label={ __( 'Taxonomy', 'as-the-crow-flies' ) }
							value={ taxonomy }
							options={ taxonomyOptions }
							onChange={ ( val ) => setAttributes( { taxonomy: val, termIds: [] } ) }
						/>
						{ taxonomy && (
							<FormTokenField
								label={ __( 'Limit to specific terms', 'as-the-crow-flies' ) }
								value={ selectedTermNames }
								suggestions={ termSuggestions }
								onInputChange={ setTermSearch }
								onChange={ ( names ) => {
									const ids = names
										.map( ( name ) => termNameMap[ name ] ?? null )
										.filter( ( id ) => null !== id );
									setAttributes( { termIds: ids } );
								} }
								help={ __( 'Type at least 2 characters to search. Leave empty to include all terms.', 'as-the-crow-flies' ) }
							/>
						) }
						{ htaxActive && htaxGroups.length > 0 && (
							<div style={ { marginTop: '16px' } }><BaseControl
								label={ __( 'Term Groups', 'as-the-crow-flies' ) }
								id="atcf-lf-groups"
								help={ __( 'Group members are merged with any specific terms above.', 'as-the-crow-flies' ) }
							>
								<SearchControl
									value={ groupSearch }
									onChange={ setGroupSearch }
									placeholder={ __( 'Filter groups…', 'as-the-crow-flies' ) }
									size="compact"
								/>
								<div style={ { maxHeight: '160px', overflowY: 'auto', border: '1px solid #dcdcde', padding: '4px 8px', marginTop: '4px' } }>
									{ rootGroupIds.map( ( id, index ) => renderGroupItem( id, 0, index ) ) }
								</div>
							</BaseControl></div>
						) }
						<div style={ { marginTop: '16px' } }><TextControl
							label={ __( 'Radius options (comma-separated)', 'as-the-crow-flies' ) }
							value={ distances }
							onChange={ ( val ) => setAttributes( { distances: val } ) }
							help={ __( 'Values shown in the radius dropdown, e.g. 5,10,25,50', 'as-the-crow-flies' ) }
						/></div>
						<SelectControl
							label={ __( 'Unit', 'as-the-crow-flies' ) }
							value={ unit }
							options={ [
								{ label: __( 'Miles', 'as-the-crow-flies' ), value: 'miles' },
								{ label: __( 'Kilometers', 'as-the-crow-flies' ), value: 'km' },
							] }
							onChange={ ( val ) => setAttributes( { unit: val } ) }
						/>
						<TextControl
							label={ __( 'Max results', 'as-the-crow-flies' ) }
							type="number"
							value={ limit }
							min={ 1 }
							max={ 100 }
							onChange={ ( val ) => setAttributes( { limit: Math.max( 1, parseInt( val, 10 ) || 20 ) } ) }
						/>
						<SelectControl
							label={ __( 'Sort by', 'as-the-crow-flies' ) }
							value={ orderby }
							options={ [
								{ label: __( 'Distance', 'as-the-crow-flies' ),   value: 'distance' },
								{ label: __( 'Label', 'as-the-crow-flies' ),      value: 'label' },
								{ label: __( 'Term name', 'as-the-crow-flies' ),  value: 'term_name' },
							] }
							onChange={ ( val ) => setAttributes( { orderby: val } ) }
						/>
						<SelectControl
							label={ __( 'Output style', 'as-the-crow-flies' ) }
							value={ format }
							options={ [
								{ label: __( 'Unordered list', 'as-the-crow-flies' ), value: 'ul' },
								{ label: __( 'Ordered list', 'as-the-crow-flies' ),   value: 'ol' },
								{ label: __( 'Div', 'as-the-crow-flies' ),            value: 'div' },
								{ label: __( 'Table', 'as-the-crow-flies' ),          value: 'table' },
							] }
							onChange={ ( val ) => setAttributes( { format: val } ) }
						/>
						<TextControl
							label={ __( 'Item template', 'as-the-crow-flies' ) }
							value={ itemTemplate }
							onChange={ ( val ) => setAttributes( { itemTemplate: val } ) }
							help={ __( 'Optional. Use {label}, {address_line}, {city}, {region}, {postal_code}, {distance}, {directions}, etc.', 'as-the-crow-flies' ) }
						/>
						<ToggleControl
							label={ __( 'Show "Use my location" button', 'as-the-crow-flies' ) }
							checked={ showGeolocation }
							onChange={ ( val ) => setAttributes( { showGeolocation: val } ) }
						/>
						<SelectControl
							label={ __( 'Directions provider', 'as-the-crow-flies' ) }
							value={ directionsProvider }
							options={ providerOptions }
							onChange={ ( val ) => setAttributes( { directionsProvider: val } ) }
							help={ __( 'Provider for {directions} and {directions_link_start} in item templates. "Site default" uses the value from ATCF Settings.', 'as-the-crow-flies' ) }
						/>
						{ enabledCountries.length > 0 ? (
							<SelectControl
								label={ __( 'Country', 'as-the-crow-flies' ) }
								value={ country }
								options={ countryOptions }
								onChange={ ( val ) => setAttributes( { country: val } ) }
								help={ __( 'Determines postal code format and filters results to this country.', 'as-the-crow-flies' ) }
							/>
						) : (
							<TextControl
								label={ __( 'Country (ISO code)', 'as-the-crow-flies' ) }
								value={ country }
								onChange={ ( val ) => setAttributes( { country: val.toUpperCase() } ) }
								help={ __( 'e.g. US, CA, GB', 'as-the-crow-flies' ) }
								maxLength={ 2 }
							/>
						) }
					</PanelBody>
				</InspectorControls>

				<div { ...blockProps }>
					<div className="atcf-editor-placeholder">
						<span className="dashicons dashicons-admin-site" style={ { fontSize: '2em', marginRight: '8px' } } />
						<strong>{ __( 'Location Finder', 'as-the-crow-flies' ) }</strong>
						<p>
							{ taxonomy
								? /* translators: %s: taxonomy slug */ sprintf( __( 'Searches taxonomy: %s', 'as-the-crow-flies' ), taxonomy )
								: __( 'Searches all enabled taxonomies', 'as-the-crow-flies' ) }
						</p>
						{ taxonomy && termIds && termIds.length > 0 && (
							<p>
								{ /* translators: %d: number of terms */ sprintf( _n( 'Filtered to %d specific term', 'Filtered to %d specific terms', termIds.length, 'as-the-crow-flies' ), termIds.length ) }
							</p>
						) }
						{ htaxActive && groupIds && groupIds.length > 0 && (
							<p>
								{ /* translators: %d: number of groups */ sprintf( _n( 'Expanded from %d HyperTaxonomy term group', 'Expanded from %d HyperTaxonomy term groups', groupIds.length, 'as-the-crow-flies' ), groupIds.length ) }
							</p>
						) }
						<p className="atcf-editor-note">
							{ __( 'Interactive on the frontend — configure in the sidebar.', 'as-the-crow-flies' ) }
						</p>
					</div>
				</div>
			</>
		);
	},

	save() {
		return null; // Dynamic block — PHP renders the output.
	},
} );
