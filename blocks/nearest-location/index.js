import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
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
			unit,
			promptText,
			showDistance,
			itemTemplate,
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
			className: 'atcf-nearest-location-editor',
		} );

		const [ termSearch, setTermSearch ] = useState( '' );
		const [ groupSearch, setGroupSearch ] = useState( '' );

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

		const terms = useSelect( ( select ) => {
			if ( ! taxonomy ) return [];
			return select( 'core' ).getEntityRecords( 'taxonomy', taxonomy, { per_page: -1 } ) || [];
		}, [ taxonomy ] );

		const taxonomyOptions = [
			{ label: __( '— All taxonomies —', 'as-the-crow-flies' ), value: '' },
			...taxonomies,
		];

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
					<PanelBody title={ __( 'Nearest Location Settings', 'as-the-crow-flies' ) }>
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
								id="atcf-nl-groups"
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
						<div style={ { marginTop: '16px' } }><SelectControl
							label={ __( 'Unit', 'as-the-crow-flies' ) }
							value={ unit }
							options={ [
								{ label: __( 'Miles', 'as-the-crow-flies' ), value: 'miles' },
								{ label: __( 'Kilometers', 'as-the-crow-flies' ), value: 'km' },
							] }
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
