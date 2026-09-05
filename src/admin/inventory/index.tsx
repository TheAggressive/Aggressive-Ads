/**
 * The placement catalogue, in DataViews.
 *
 * The previous version rendered one expanded Card per placement: every field
 * open at once, plus a second always-open create form. That reads at five
 * placements and stops reading at fifty. DataViews inverts it. The list is a
 * table you can search, sort and filter, and the writes move behind a modal.
 *
 * Nothing autosaves, for the reason Packages does not: a slot slug is what a
 * published page renders an ad into, and "active" decides whether advertisers
 * can buy the slot at all. A half-typed slug is not a state the catalogue
 * should ever briefly hold.
 *
 * There is no delete, and there must not be one. A placement is referenced by
 * every package that sells it and every campaign that bought one, so removing
 * a row would orphan the snapshot those point at. Deactivating hides it from
 * advertisers and leaves the history intact.
 *
 * `@wordpress/dataviews` is bundled, not externalised. WordPress 7.1 registers
 * no `wp-dataviews` handle. See the BUNDLE_NOT_EXTERNAL note in
 * webpack.admin.config.mjs.
 *
 * Strings arrive from PHP. `wp i18n make-pot` does not parse .tsx, so an __()
 * call here would compile, run, and produce no catalog entry at all.
 */

import type { ReactElement } from 'react';
import apiFetch from '@wordpress/api-fetch';
import { createRoot, useMemo, useState } from '@wordpress/element';
import { Button, Notice } from '@wordpress/components';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';
import type {
	Action,
	Field as DataField,
	View as DataView,
} from '@wordpress/dataviews';
import { errorMessage, setStrings, t } from '../shared/save';
import { PlacementModal } from './form';
import {
	EMPTY,
	blankPlacement,
	body,
	type Bootstrap,
	type Catalogue,
	type Placement,
} from './types';
import './style.css';

const DEFAULT_VIEW: DataView = {
	type: 'table',
	search: '',
	page: 1,
	perPage: 25,
	sort: { field: 'name', direction: 'asc' },
	filters: [],
	titleField: 'name',
	fields: [ 'slug', 'size', 'status', 'refresh' ],
	layout: {},
};

function App( { data }: { data: Bootstrap } ): ReactElement {
	const [ catalogue, setCatalogue ] = useState< Catalogue >( data.view );
	const [ table, setTable ] = useState< DataView >( DEFAULT_VIEW );
	const [ creating, setCreating ] = useState( false );
	const [ editing, setEditing ] = useState< Placement | null >( null );
	const [ formError, setFormError ] = useState( '' );
	const [ saved, setSaved ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	const persist = async ( draft: Placement ): Promise< void > => {
		setBusy( true );
		setFormError( '' );
		setSaved( '' );

		try {
			const result = await apiFetch< { view: Catalogue } >(
				0 === draft.id
					? {
							path: `${ data.restPath }/catalogue`,
							method: 'POST',
							data: body( draft ),
					  }
					: {
							path: `${ data.restPath }/${ draft.id }`,
							method: 'PATCH',
							data: body( draft ),
					  }
			);

			// The server's catalogue, not a local guess. Sort order
			// re-sequences the whole list, and only the server knows what
			// else moved.
			setCatalogue( result.view );
			setCreating( false );
			setEditing( null );
			setSaved( 0 === draft.id ? t( 'created' ) : t( 'saved' ) );
		} catch ( failure ) {
			setFormError( errorMessage( failure ) );
		} finally {
			setBusy( false );
		}
	};

	const fields: DataField< Placement >[] = useMemo(
		() => [
			{
				id: 'name',
				label: t( 'name' ),
				type: 'text',
				enableGlobalSearch: true,
			},
			{
				id: 'slug',
				label: t( 'slug' ),
				type: 'text',
				enableGlobalSearch: true,
			},
			{
				id: 'size',
				label: t( 'size' ),
				type: 'text',
			},
			{
				id: 'status',
				label: t( 'status' ),
				elements: [
					{ value: 'active', label: t( 'active' ) },
					{ value: 'inactive', label: t( 'inactive' ) },
				],
				filterBy: { operators: [ 'is' ] },
				getValue: ( { item }: { item: Placement } ) =>
					item.active ? 'active' : 'inactive',
			},
			{
				id: 'refresh',
				label: t( 'refresh' ),
				elements: [
					{ value: 'on', label: t( 'refreshOn' ) },
					{ value: 'off', label: t( 'refreshOff' ) },
				],
				filterBy: { operators: [ 'is' ] },
				getValue: ( { item }: { item: Placement } ) =>
					item.refresh_enabled ? 'on' : 'off',
			},
			{
				id: 'sort_order',
				label: t( 'sortOrder' ),
				type: 'integer',
			},
		],
		[]
	);

	const actions: Action< Placement >[] = useMemo(
		() => [
			{
				id: 'edit',
				label: t( 'edit' ),
				isPrimary: true,
				supportsBulk: false,
				callback: ( items: Placement[] ) => {
					const item = items[ 0 ];

					if ( item ) {
						setFormError( '' );
						setSaved( '' );
						setEditing( item );
					}
				},
			},
		],
		[]
	);

	const { data: rows, paginationInfo } = useMemo(
		() => filterSortAndPaginate( catalogue.rows, table, fields ),
		[ catalogue.rows, table, fields ]
	);

	const open =
		creating || null !== editing
			? editing ??
			  blankPlacement(
					catalogue.refresh_defaults ?? EMPTY.view.refresh_defaults
			  )
			: null;

	return (
		<>
			{ saved ? (
				<Notice
					status="success"
					isDismissible
					onRemove={ () => setSaved( '' ) }
				>
					{ saved }
				</Notice>
			) : null }

			<section className="aggr-section">
				<DataViews< Placement >
					data={ rows }
					fields={ fields }
					view={ table }
					onChangeView={ setTable }
					actions={ actions }
					paginationInfo={ paginationInfo }
					getItemId={ ( item ) => String( item.id ) }
					defaultLayouts={ { table: {} } }
					searchLabel={ t( 'search' ) }
					header={
						<Button
							variant="primary"
							onClick={ () => {
								setFormError( '' );
								setSaved( '' );
								setEditing( null );
								setCreating( true );
							} }
						>
							{ t( 'newPlacement' ) }
						</Button>
					}
					empty={ <p>{ t( 'none' ) }</p> }
				/>
			</section>

			{ null !== open ? (
				<PlacementModal
					key={ 0 === open.id ? 'new' : open.id }
					value={ open }
					sizes={ catalogue.sizes }
					ceiling={
						catalogue.refresh_ceiling ?? EMPTY.view.refresh_ceiling
					}
					submitLabel={ 0 === open.id ? t( 'create' ) : t( 'save' ) }
					busy={ busy }
					error={ formError }
					onCancel={ () => {
						setCreating( false );
						setEditing( null );
						setFormError( '' );
					} }
					onSubmit={ ( draft ) => void persist( draft ) }
				/>
			) : null }
		</>
	);
}

const root = document.getElementById( 'aggr-inventory-root' );

if ( root ) {
	const raw = root.getAttribute( 'data-aggr-inventory' );
	let data: Bootstrap = EMPTY;

	try {
		data = raw ? ( JSON.parse( raw ) as Bootstrap ) : EMPTY;
	} catch {
		// A malformed payload renders an empty screen rather than throwing
		// inside a page the administrator still needs to use.
		data = EMPTY;
	}

	setStrings( data.i18n ?? {} );
	createRoot( root ).render( <App data={ data } /> );
}
