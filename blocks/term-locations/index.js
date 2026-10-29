import { registerBlockType } from '@wordpress/blocks';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	InspectorControls,
	useBlockProps,
} from '@wordpress/block-editor';
import {
	FormTokenField,
	PanelBody,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { decodeEntities } from '@wordpress/html-entities';
import metadata from './block.json';

registerBlockType( metadata.name, {
	edit( { attributes, setAttributes } ) {
		const {
			taxonomy, termIds, country, unit, sortPostalCode, limit,
			format, itemTemplate,
			showAddressLine, showLocality, showRegion, showPostalCode, showCountry,
			directionsProvider,
		} = attributes;

		const providerOptions = [
			{ label: __( 'Site default', 'as-the-crow-flies' ),  value: '' },
			{ label: __( 'Google Maps', 'as-the-crow-flies' ),    value: 'google' },
			{ label: __( 'Apple Maps', 'as-the-crow-flies' ),     value: 'apple' },
			{ label: __( 'Waze', 'as-the-crow-flies' ),           value: 'waze' },
			{ label: __( 'OpenStreetMap', 'as-the-crow-flies' ),  value: 'osm' },
		];

		const blockProps = useBlockProps( {
			className: 'atcf-term-locations-editor',
		} );

		const [ termSearch, setTermSearch ] = useState( '' );

		const enabledCountries = ( window.atcfEditorData && window.atcfEditorData.countries ) || [];
		const countryOptions = [
			{ label: __( '— All countries —', 'as-the-crow-flies' ), value: '' },
			...enabledCountries.map( ( c ) => ( { label: c.label, value: c.code } ) ),
		];

		const taxonomies = useSelect( ( select ) => {
			const all = select( 'core' ).getTaxonomies( { per_page: -1 } );
			return all
				? all
					.filter( ( t ) => t.visibility?.publicly_queryable )
					.map( ( t ) => ( { label: t.name + ' (' + t.slug + ')', value: t.slug } ) )
				: [];
		}, [] );

		const terms = useSelect(
			( select ) => {
				if ( ! taxonomy ) return [];
				return select( 'core' ).getEntityRecords( 'taxonomy', taxonomy, { per_page: -1 } ) || [];
			},
			[ taxonomy ]
		);

		const taxonomyOptions = [
			{ label: __( '— Select taxonomy —', 'as-the-crow-flies' ), value: '' },
			...taxonomies,
		];

		// Build a decoded-name → id map so FormTokenField shows readable names.
		const termNameMap = {};
		terms.forEach( ( t ) => { termNameMap[ decodeEntities( t.name ) ] = t.id; } );

		const allTermSuggestions = Object.keys( termNameMap );
		const termSuggestions = termSearch.length >= 2 ? allTermSuggestions : [];

		const selectedTermNames = ( termIds || [] )
			.map( ( id ) => {
				const t = terms.find( ( tt ) => tt.id === id );
				return t ? decodeEntities( t.name ) : null;
			} )
			.filter( ( n ) => null !== n );

		return (
			<>
				<InspectorControls>
					<PanelBody title={ __( 'Term Locations Settings', 'as-the-crow-flies' ) }>
						<SelectControl
							label={ __( 'Taxonomy', 'as-the-crow-flies' ) }
							value={ taxonomy }
							options={ taxonomyOptions }
							onChange={ ( val ) => setAttributes( { taxonomy: val, termIds: [] } ) }
						/>
						{ taxonomy && (
							<FormTokenField
								label={ __( 'Terms', 'as-the-crow-flies' ) }
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
						<div style={ { marginTop: '16px' } }><TextControl
							label={ __( 'Sort by distance from postal code', 'as-the-crow-flies' ) }
							value={ sortPostalCode }
							onChange={ ( val ) => setAttributes( { sortPostalCode: val } ) }
							help={ __( 'Leave blank to sort by label', 'as-the-crow-flies' ) }
						/></div>
						<SelectControl
							label={ __( 'Unit', 'as-the-crow-flies' ) }
							value={ unit }
							options={ [
								{ label: __( 'Miles', 'as-the-crow-flies' ), value: 'miles' },
								{ label: __( 'Kilometers', 'as-the-crow-flies' ), value: 'km' },
							] }
							onChange={ ( val ) => setAttributes( { unit: val } ) }
							help={ __( 'Used for the distance label when sorting by postal code.', 'as-the-crow-flies' ) }
						/>
						<TextControl
							label={ __( 'Max results', 'as-the-crow-flies' ) }
							type="number"
							value={ limit || '' }
							min={ 0 }
							onChange={ ( val ) => setAttributes( { limit: Math.max( 0, parseInt( val, 10 ) || 0 ) } ) }
							help={ __( 'Leave blank or 0 to show all locations.', 'as-the-crow-flies' ) }
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
						<SelectControl
							label={ __( 'Directions provider', 'as-the-crow-flies' ) }
							value={ directionsProvider }
							options={ providerOptions }
							onChange={ ( val ) => setAttributes( { directionsProvider: val } ) }
							help={ __( '"Site default" uses the value from ATCF Settings.', 'as-the-crow-flies' ) }
						/>
						{ enabledCountries.length > 0 ? (
							<SelectControl
								label={ __( 'Filter by country', 'as-the-crow-flies' ) }
								value={ country }
								options={ countryOptions }
								onChange={ ( val ) => setAttributes( { country: val } ) }
								help={ __( 'Leave at "All countries" to show every location for the term.', 'as-the-crow-flies' ) }
							/>
						) : (
							<TextControl
								label={ __( 'Filter by country (ISO code)', 'as-the-crow-flies' ) }
								value={ country }
								onChange={ ( val ) => setAttributes( { country: val.toUpperCase() } ) }
								help={ __( 'e.g. US, CA, GB. Leave blank to show all.', 'as-the-crow-flies' ) }
								maxLength={ 2 }
							/>
						) }
					</PanelBody>
					<PanelBody title={ __( 'Columns shown', 'as-the-crow-flies' ) } initialOpen={ false }>
						<ToggleControl
							label={ __( 'Address line', 'as-the-crow-flies' ) }
							checked={ showAddressLine }
							onChange={ ( val ) => setAttributes( { showAddressLine: val } ) }
						/>
						<ToggleControl
							label={ __( 'City', 'as-the-crow-flies' ) }
							checked={ showLocality }
							onChange={ ( val ) => setAttributes( { showLocality: val } ) }
						/>
						<ToggleControl
							label={ __( 'State / Region', 'as-the-crow-flies' ) }
							checked={ showRegion }
							onChange={ ( val ) => setAttributes( { showRegion: val } ) }
						/>
						<ToggleControl
							label={ __( 'Postal code', 'as-the-crow-flies' ) }
							checked={ showPostalCode }
							onChange={ ( val ) => setAttributes( { showPostalCode: val } ) }
						/>
						<ToggleControl
							label={ __( 'Country', 'as-the-crow-flies' ) }
							checked={ showCountry }
							onChange={ ( val ) => setAttributes( { showCountry: val } ) }
						/>
					</PanelBody>
				</InspectorControls>

				<div { ...blockProps }>
					<div className="atcf-editor-placeholder">
						<span className="dashicons dashicons-admin-site-alt3" style={ { fontSize: '2em', marginRight: '8px' } } />
						<strong>{ __( 'Term Locations', 'as-the-crow-flies' ) }</strong>
						{ taxonomy && termIds && termIds.length > 0 ? (
							<p>{ sprintf( _n( 'Showing locations for %d selected term.', 'Showing locations for %d selected terms.', termIds.length, 'as-the-crow-flies' ), termIds.length ) }</p>
						) : (
							<p>{ __( 'Select a taxonomy and one or more terms in the sidebar.', 'as-the-crow-flies' ) }</p>
						) }
					</div>
				</div>
			</>
		);
	},

	save() {
		return null;
	},
} );
