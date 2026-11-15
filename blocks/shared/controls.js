/**
 * Inspector controls the blocks share: taxonomy, term and group pickers, unit and directions options.
 *
 * @package AsTheCrowFlies
 */

import { __, sprintf } from '@wordpress/i18n';
import {
	BaseControl,
	CheckboxControl,
	FormTokenField,
	SearchControl,
} from '@wordpress/components';
import { useState, Fragment } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { decodeEntities } from '@wordpress/html-entities';

/**
 * Options for a taxonomy SelectControl: every publicly queryable taxonomy, after an
 * empty first option.
 *
 * @param {string} emptyLabel Label of the empty option.
 * @return {Array} SelectControl options.
 */
export function useTaxonomyOptions( emptyLabel ) {
	const taxonomies = useSelect( ( select ) => {
		const all = select( 'core' ).getTaxonomies( { per_page: -1 } );
		return all
			? all
				.filter( ( t ) => t.visibility?.publicly_queryable )
				.map( ( t ) => ( { label: t.name + ' (' + t.slug + ')', value: t.slug } ) )
			: [];
	}, [] );
	return [ { label: emptyLabel, value: '' }, ...taxonomies ];
}

/**
 * A token field for picking terms of one taxonomy by name.
 *
 * Suggestions appear after two typed characters. Terms that share a decoded name are
 * shown with their IDs, so each name resolves back to one term.
 *
 * @param {Object}   props
 * @param {string}   props.taxonomy Taxonomy slug.
 * @param {number[]} props.termIds  Selected term IDs.
 * @param {string}   props.label    Field label.
 * @param {Function} props.onChange Called with the new term IDs.
 * @return {JSX.Element} The field.
 */
export function TermsTokenField( { taxonomy, termIds, label, onChange } ) {
	const [ search, setSearch ] = useState( '' );
	const terms = useSelect(
		( select ) => select( 'core' ).getEntityRecords( 'taxonomy', taxonomy, { per_page: -1 } ) || [],
		[ taxonomy ]
	);

	const nameCounts = {};
	terms.forEach( ( t ) => {
		const n = decodeEntities( t.name );
		nameCounts[ n ] = ( nameCounts[ n ] || 0 ) + 1;
	} );
	const termLabel = ( t ) => {
		const n = decodeEntities( t.name );
		return nameCounts[ n ] > 1
			/* translators: 1: term name, 2: term ID (shown only when two terms share a name) */
			? sprintf( __( '%1$s (ID: %2$d)', 'as-the-crow-flies' ), n, t.id )
			: n;
	};

	const idByLabel = {};
	terms.forEach( ( t ) => { idByLabel[ termLabel( t ) ] = t.id; } );

	const selected = ( termIds || [] )
		.map( ( id ) => {
			const t = terms.find( ( tt ) => tt.id === id );
			return t ? termLabel( t ) : null;
		} )
		.filter( ( n ) => null !== n );

	return (
		<FormTokenField
			label={ label }
			value={ selected }
			suggestions={ search.length >= 2 ? Object.keys( idByLabel ) : [] }
			onInputChange={ setSearch }
			onChange={ ( names ) => {
				onChange( names.map( ( name ) => idByLabel[ name ] ?? null ).filter( ( id ) => null !== id ) );
			} }
			help={ __( 'Type at least 2 characters to search. Leave empty to include all terms.', 'as-the-crow-flies' ) }
		/>
	);
}

/**
 * A filterable, hierarchical checklist of HyperTaxonomy Term Groups. Renders nothing
 * when HyperTaxonomy isn't active or there are no groups.
 *
 * @param {Object}   props
 * @param {string}   props.id       Control ID.
 * @param {number[]} props.groupIds Selected group IDs.
 * @param {Function} props.onChange Called with the new group IDs.
 * @return {JSX.Element|null} The checklist.
 */
export function GroupsChecklist( { id, groupIds, onChange } ) {
	const [ search, setSearch ] = useState( '' );
	const data       = window.atcfEditorData || {};
	const htaxGroups = data.htaxGroups || [];
	if ( ! data.htaxActive || ! htaxGroups.length ) {
		return null;
	}

	const groupMap = {};
	const children = {};
	htaxGroups.forEach( ( g ) => { groupMap[ g.id ] = g; children[ g.id ] = []; } );
	htaxGroups.forEach( ( g ) => {
		if ( g.parent && children[ g.parent ] !== undefined ) {
			children[ g.parent ].push( g.id );
		}
	} );
	const rootIds = htaxGroups
		.filter( ( g ) => ! g.parent || children[ g.parent ] === undefined )
		.map( ( g ) => g.id );

	const lowerSearch = search.toLowerCase();
	const matches = ( gid ) => {
		const g = groupMap[ gid ];
		if ( ! g ) return false;
		if ( decodeEntities( g.name ).toLowerCase().includes( lowerSearch ) ) return true;
		return ( children[ gid ] || [] ).some( ( cid ) => matches( cid ) );
	};
	const selected = groupIds || [];
	const renderItem = ( gid, depth, index = 0 ) => {
		if ( lowerSearch && ! matches( gid ) ) return null;
		const marginTop = ( depth === 0 && index === 0 ) ? undefined : '8px';
		return (
			<Fragment key={ gid }>
				<div style={ { paddingLeft: depth * 18 + 'px', marginTop } }>
					<CheckboxControl
						label={ decodeEntities( groupMap[ gid ].name ) }
						checked={ selected.includes( gid ) }
						onChange={ ( checked ) => {
							onChange( checked ? [ ...selected, gid ] : selected.filter( ( i ) => i !== gid ) );
						} }
					/>
				</div>
				{ ( children[ gid ] || [] ).map( ( cid, i ) => renderItem( cid, depth + 1, i ) ) }
			</Fragment>
		);
	};

	return (
		<div style={ { marginTop: '16px' } }><BaseControl
			label={ __( 'Term Groups', 'as-the-crow-flies' ) }
			id={ id }
			help={ __( 'Group members are merged with any specific terms above.', 'as-the-crow-flies' ) }
		>
			<SearchControl
				value={ search }
				onChange={ setSearch }
				placeholder={ __( 'Filter groups…', 'as-the-crow-flies' ) }
				size="compact"
			/>
			<div style={ { maxHeight: '160px', overflowY: 'auto', border: '1px solid #dcdcde', padding: '4px 8px', marginTop: '4px' } }>
				{ rootIds.map( ( gid, index ) => renderItem( gid, 0, index ) ) }
			</div>
		</BaseControl></div>
	);
}

/**
 * Options for the Unit SelectControl.
 *
 * @return {Array} SelectControl options.
 */
export function unitOptions() {
	return [
		{ label: __( 'Site default', 'as-the-crow-flies' ), value: '' },
		{ label: __( 'Miles', 'as-the-crow-flies' ), value: 'miles' },
		{ label: __( 'Kilometers', 'as-the-crow-flies' ), value: 'km' },
	];
}

/**
 * Options for the Directions provider SelectControl.
 *
 * @return {Array} SelectControl options.
 */
export function directionsProviderOptions() {
	return [
		{ label: __( 'Site default', 'as-the-crow-flies' ), value: '' },
		{ label: __( 'Google Maps', 'as-the-crow-flies' ), value: 'google' },
		{ label: __( 'Apple Maps', 'as-the-crow-flies' ), value: 'apple' },
		{ label: __( 'Waze', 'as-the-crow-flies' ), value: 'waze' },
		{ label: __( 'OpenStreetMap', 'as-the-crow-flies' ), value: 'osm' },
	];
}
