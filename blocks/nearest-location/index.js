import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import {
	InspectorControls,
	useBlockProps,
} from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import metadata from './block.json';
import { useTaxonomyOptions, TermsTokenField, GroupsChecklist, unitOptions, directionsProviderOptions } from '../shared/controls';

registerBlockType( metadata.name, {
	edit( { attributes, setAttributes } ) {
		const {
			taxonomy,
			termIds,
			groupIds,
			country,
			unit,
			promptText,
			showDistance,
			itemTemplate,
			directionsProvider,
		} = attributes;


		const providerOptions = directionsProviderOptions();

		const blockProps = useBlockProps( {
			className: 'atcf-nearest-location-editor',
		} );


		const enabledCountries = ( window.atcfEditorData && window.atcfEditorData.countries ) || [];
		const countryOptions = [
			{ label: __( '— All countries —', 'as-the-crow-flies' ), value: '' },
			...enabledCountries.map( ( c ) => ( { label: c.label, value: c.code } ) ),
		];

		const taxonomyOptions = useTaxonomyOptions( __( '— All taxonomies —', 'as-the-crow-flies' ) );

		return (
			<>
				<InspectorControls>
					<PanelBody className="atcf-inspector-panel" title={ __( 'Nearest Location Settings', 'as-the-crow-flies' ) }>
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
							id="atcf-nl-groups"
							groupIds={ groupIds }
							onChange={ ( ids ) => setAttributes( { groupIds: ids } ) }
						/>
						<div style={ { marginTop: '16px' } }><SelectControl
							label={ __( 'Unit', 'as-the-crow-flies' ) }
							value={ unit }
							options={ unitOptions() }
							onChange={ ( val ) => setAttributes( { unit: val } ) }
						/></div>
						<ToggleControl
							label={ __( 'Show distance', 'as-the-crow-flies' ) }
							checked={ showDistance }
							onChange={ ( val ) => setAttributes( { showDistance: val } ) }
						/>
						<TextControl
							label={ __( 'Item template', 'as-the-crow-flies' ) }
							value={ itemTemplate }
							onChange={ ( val ) => setAttributes( { itemTemplate: val } ) }
							help={ __( 'Optional. Use {label}, {address_line}, {city}, {distance}, {directions}, etc.', 'as-the-crow-flies' ) }
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
								label={ __( 'Country', 'as-the-crow-flies' ) }
								value={ country }
								options={ countryOptions }
								onChange={ ( val ) => setAttributes( { country: val } ) }
							/>
						) : (
							<TextControl
								label={ __( 'Country (ISO code)', 'as-the-crow-flies' ) }
								value={ country }
								onChange={ ( val ) => setAttributes( { country: val.toUpperCase() } ) }
								maxLength={ 2 }
							/>
						) }
						<TextControl
							label={ __( 'Loading prompt text', 'as-the-crow-flies' ) }
							value={ promptText }
							onChange={ ( val ) => setAttributes( { promptText: val } ) }
							help={ __( 'Shown while location is being resolved.', 'as-the-crow-flies' ) }
						/>
					</PanelBody>
				</InspectorControls>

				<div { ...blockProps }>
					<div className="atcf-editor-placeholder">
						<span className="dashicons dashicons-groups" style={ { fontSize: '2em', marginRight: '8px' } } />
						<strong>{ __( 'Nearest Location', 'as-the-crow-flies' ) }</strong>
						<p>{ __( 'Shows the single nearest location to the visitor.', 'as-the-crow-flies' ) }</p>
						<p className="atcf-editor-note">
							{ __( 'Resolved client-side — configure in the sidebar.', 'as-the-crow-flies' ) }
						</p>
					</div>
				</div>
			</>
		);
	},

	save() {
		return null;
	},
} );
