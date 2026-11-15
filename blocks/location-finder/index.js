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
import { useTaxonomyOptions, TermsTokenField, GroupsChecklist, unitOptions, directionsProviderOptions } from '../shared/controls';

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

		const htaxActive = !! ( window.atcfEditorData && window.atcfEditorData.htaxActive );

		const providerOptions = directionsProviderOptions();

		const blockProps = useBlockProps( {
			className: 'atcf-location-finder-editor',
		} );


		const enabledCountries = ( window.atcfEditorData && window.atcfEditorData.countries ) || [];
		const countryOptions = enabledCountries.map( ( c ) => ( { label: c.label, value: c.code } ) );

		const taxonomyOptions = useTaxonomyOptions( __( '— All taxonomies —', 'as-the-crow-flies' ) );

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
							<TermsTokenField
								taxonomy={ taxonomy }
								termIds={ termIds }
								label={ __( 'Limit to specific terms', 'as-the-crow-flies' ) }
								onChange={ ( ids ) => setAttributes( { termIds: ids } ) }
							/>
						) }
						<GroupsChecklist
							id="atcf-lf-groups"
							groupIds={ groupIds }
							onChange={ ( ids ) => setAttributes( { groupIds: ids } ) }
						/>
						<div style={ { marginTop: '16px' } }><TextControl
							label={ __( 'Radius options (comma-separated)', 'as-the-crow-flies' ) }
							value={ distances }
							onChange={ ( val ) => setAttributes( { distances: val } ) }
							help={ __( 'Values shown in the radius dropdown, e.g. 5,10,25,50', 'as-the-crow-flies' ) }
						/></div>
						<SelectControl
							label={ __( 'Unit', 'as-the-crow-flies' ) }
							value={ unit }
							options={ unitOptions() }
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
					<Disabled>
						<ServerSideRender
							block={ metadata.name }
							attributes={ attributes }
							EmptyResponsePlaceholder={ () => (
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
							) }
						/>
					</Disabled>
				</div>
			</>
		);
	},

	save() {
		return null; // Dynamic block — PHP renders the output.
	},
} );
