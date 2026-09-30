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
import { State } from '../shared/state';
import { Icon } from '../shared/icon';
import { sameOriginUrl } from '../shared/navigate';
import { Empty } from '../shared/empty';
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

/*
 * The slug is shown under the name rather than in a column of its own: it is
 * the name's machine spelling, and two columns of nearly the same word pushed
 * everything else apart. It stays a field, so it is still searchable and can
 * still be switched on as a column.
 */
const DEFAULT_VIEW: DataView = {
	type: 'table',
	search: '',
	page: 1,
	perPage: 25,
	sort: { field: 'name', direction: 'asc' },
	filters: [],
	titleField: 'name',
	fields: [ 'size', 'status', 'refresh', 'groups' ],
	layout: {},
};

/**
 * A placement's shape, drawn to scale inside a fixed box.
 *
 * "300x250" and "728x90" are numbers somebody has to picture; the outline
 * shows at a glance which slot is the leaderboard and which the square. The
 * dimensions are printed beside it, so the drawing is never the only way to
 * know the size, and it is hidden from assistive technology for that reason.
 */
function SizeGlyph( {
	width,
	height,
}: {
	width: number;
	height: number;
} ): ReactElement | null {
	if ( width <= 0 || height <= 0 ) {
		return null;
	}

	const box = { w: 40, h: 28 };
	const scale = Math.min( box.w / width, box.h / height );

	return (
		<span className="aggr-size-glyph" aria-hidden="true">
			<span
				style={ {
					width: `${ Math.max( 3, Math.round( width * scale ) ) }px`,
					height: `${ Math.max(
						3,
						Math.round( height * scale )
					) }px`,
				} }
			/>
		</span>
	);
}

function App( { data }: { data: Bootstrap } ): ReactElement {
	const [ catalogue, setCatalogue ] = useState< Catalogue >( data.view );
	const [ table, setTable ] = useState< DataView >( DEFAULT_VIEW );
	const [ creating, setCreating ] = useState( false );
	const [ editing, setEditing ] = useState< Placement | null >( null );
	const [ formError, setFormError ] = useState( '' );
	const [ saved, setSaved ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	/*
	 * outlookUrl is text from data-aggr-inventory. An href of that text runs a
	 * javascript: scheme on click, so the link exists only for an address on
	 * this origin.
	 */
	const outlookHref = data.outlookUrl
		? sameOriginUrl( data.outlookUrl )
		: null;

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
				render: ( { item }: { item: Placement } ) => (
					<span className="aggr-cell-title">
						<span>{ item.name }</span>
						<span className="aggr-cell-title__sub">
							{ item.slug }
						</span>
					</span>
				),
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
				render: ( { item }: { item: Placement } ) => (
					<span className="aggr-size">
						<SizeGlyph
							width={ item.size_width }
							height={ item.size_height }
						/>
						{ item.size }
					</span>
				),
			},
			{
				/*
				 * A column, not just a form field. The limit is the reason an
				 * advertiser's upload gets refused, and a publisher answering
				 * "why was mine rejected?" should not have to open each
				 * placement in turn to find out what it allows.
				 */
				id: 'max_bytes',
				label: t( 'maxFileSizeColumn' ),
				type: 'text',
				getValue: ( { item }: { item: Placement } ) =>
					`${ Math.round( item.max_bytes / 1024 ) } KB`,
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
				render: ( { item }: { item: Placement } ) =>
					item.active ? (
						<State tone="live">{ t( 'active' ) }</State>
					) : (
						<State tone="neutral">{ t( 'inactive' ) }</State>
					),
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
				/*
				 * The point of a group is finding things, so it is a filter
				 * before it is a column. `elements` is built from the groups
				 * actually in use rather than a fixed list, because the
				 * vocabulary is the publisher's own.
				 *
				 * `getValue` returns the joined slugs so search matches them;
				 * the `is`/`is any` filter compares against that same string,
				 * so a placement in several groups is found by any one of them
				 * only because `contains` is offered alongside.
				 */
				id: 'groups',
				label: t( 'groups' ),
				enableGlobalSearch: true,
				elements: ( catalogue.all_groups ?? [] ).map(
					( group: string ) => ( { value: group, label: group } )
				),
				filterBy: { operators: [ 'contains' ] },
				getValue: ( { item }: { item: Placement } ) =>
					( item.groups ?? [] ).join( ' ' ),
				render: ( { item }: { item: Placement } ) => (
					<span className="aggr-chips">
						{ ( item.groups ?? [] ).map( ( group ) => (
							<span key={ group } className="aggr-chip">
								{ group }
							</span>
						) ) }
					</span>
				),
			},
			{
				id: 'sort_order',
				label: t( 'sortOrder' ),
				type: 'integer',
			},
		],
		// The group filter's options come from the data, so this list is not
		// constant the way the others are — an empty dependency array here
		// would freeze the options at whatever was loaded first and never
		// offer a group created since.
		[ catalogue.all_groups ]
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
					catalogue.refresh_defaults ?? EMPTY.view.refresh_defaults,
					catalogue.upload_limits ?? EMPTY.view.upload_limits
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
						<>
							{ /*
							 * The other half of the same catalogue: what each
							 * placement is forecast to offer and how much is
							 * sold. Two screens on one subject with no way
							 * between them left the outlook undiscovered.
							 */ }
							{ outlookHref ? (
								<Button
									variant="tertiary"
									href={ outlookHref }
									icon={ <Icon name="forecast" /> }
								>
									{ t( 'seeOutlook' ) }
								</Button>
							) : null }
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
						</>
					}
					empty={ <Empty icon="placements">{ t( 'none' ) }</Empty> }
				/>
			</section>

			{ null !== open ? (
				<PlacementModal
					key={ 0 === open.id ? 'new' : open.id }
					value={ open }
					sizes={ catalogue.sizes }
					allGroups={ catalogue.all_groups ?? EMPTY.view.all_groups }
					ceiling={
						catalogue.refresh_ceiling ?? EMPTY.view.refresh_ceiling
					}
					uploadLimits={
						catalogue.upload_limits ?? EMPTY.view.upload_limits
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
