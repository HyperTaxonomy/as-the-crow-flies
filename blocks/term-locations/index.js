import { registerBlockType } from '@wordpress/blocks';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	InspectorControls,
	useBlockProps,
} from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	TextControl,
	ToggleControl,
	Disabled,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import metadata from './block.json';
import { useTaxonomyOptions, TermsTokenField, unitOptions, directionsProviderOptions } from '../shared/controls';

registerBlockType( metadata.name, {
	edit( { attributes, setAttributes } ) {
		const {
			taxonomy, termIds, country, unit, sortPostalCode, limit,
			format, itemTemplate,
			showAddressLine, showLocality, showRegion, showPostalCode, showCountry,
			directionsProvider,
		} = attributes;

		const providerOptions = directionsProviderOptions();

		const blockProps = useBlockProps( {
			className: 'atcf-term-locations-editor',
		} );


		const enabledCountries = ( window.atcfEditorData && window.atcfEditorData.countries ) || [];
		const countryOptions = [
			{ label: __( '— All countries —', 'as-the-crow-flies' ), value: '' },
			...enabledCountries.map( ( c ) => ( { label: c.label, value: c.code } ) ),
		];

		const taxonomyOptions = useTaxonomyOptions( __( '— Select taxonomy —', 'as-the-crow-flies' ) );

		return (
			<>
				<InspectorControls>
					<PanelBody className="atcf-inspector-panel" title={ __( 'Term Locations Settings', 'as-the-crow-flies' ) }>
						<SelectControl
							label={ __( 'Taxonomy', 'as-the-crow-flies' ) }
							value={ taxonomy }
							options={ taxonomyOptions }
							onChange={ ( val ) => setAttributes( { taxonomy: val, termIds: [] } ) }
						/>
						{ taxonomy && (
							<TermsTokenField
								taxonomy={ taxonomy }
								termIds={ termIds }
								label={ __( 'Terms', 'as-the-crow-flies' ) }
								onChange={ ( ids ) => setAttributes( { termIds: ids } ) }
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
							options={ unitOptions() }
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
					<PanelBody className="atcf-inspector-panel" title={ __( 'Columns shown', 'as-the-crow-flies' ) } initialOpen={ false }>
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
					<Disabled>
						<ServerSideRender
							block={ metadata.name }
							attributes={ attributes }
							EmptyResponsePlaceholder={ () => (
								<div className="atcf-editor-placeholder">
									<span className="dashicons dashicons-admin-site-alt3" style={ { fontSize: '2em', marginRight: '8px' } } />
									<strong>{ __( 'Term Locations', 'as-the-crow-flies' ) }</strong>
									{ ! taxonomy ? (
										<p>{ __( 'Select a taxonomy in the sidebar.', 'as-the-crow-flies' ) }</p>
									) : termIds && termIds.length > 0 ? (
										<p>{ sprintf( _n( 'Showing locations for %d selected term.', 'Showing locations for %d selected terms.', termIds.length, 'as-the-crow-flies' ), termIds.length ) }</p>
									) : (
										<p>{ __( 'Showing locations for all terms in this taxonomy. Select one or more terms in the sidebar to narrow the list.', 'as-the-crow-flies' ) }</p>
									) }
								</div>
							) }
						/>
					</Disabled>
				</div>
			</>
		);
	},

	save() {
		return null;
	},
} );
