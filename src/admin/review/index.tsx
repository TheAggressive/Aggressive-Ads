/**
 * The staff review screens.
 *
 * Two views in one bundle — the queue and one campaign — because they are one
 * admin page with one capability and the reviewer moves between them constantly.
 *
 * The URL stays authoritative. Every navigation pushes state, and `popstate`
 * reads it back, so the browser's Back button, a bookmarked campaign and a link
 * pasted to a colleague all keep working. That was free when the screen was
 * server-rendered and has to be paid for once here.
 *
 * Strings arrive from PHP. `wp i18n make-pot` does not parse .tsx, so an __()
 * call here would compile, run, and produce no catalog entry at all.
 */

import type { ReactElement } from 'react';
import apiFetch from '@wordpress/api-fetch';
import { createRoot, useEffect, useState } from '@wordpress/element';
import { errorMessage, setStrings, t } from '../shared/save';
import { QueueView } from './queue';
import { CampaignView } from './campaign';
import type { Bootstrap, Campaign, Queue, Tab } from './types';

const EMPTY: Bootstrap = {
	filter: 'pending',
	paged: 1,
	campaignId: 0,
	queueUrl: '',
	restPath: '',
	tabs: [],
	queue: { rows: [], total: 0, pages: 1, page: 1 },
	campaign: null,
	advertisers: [],
	portalBase: '',
	i18n: {},
};

type Flash = { type: 'success' | 'error'; message: string } | null;

/** The screen's own notice, in the design system the screen already uses. */
function FlashNotice( { flash }: { flash: Flash } ): ReactElement | null {
	if ( ! flash ) {
		return null;
	}

	return (
		<div
			className={ `aggr-flash aggr-flash--${ flash.type }` }
			role="status"
		>
			<p className="aggr-flash__message">{ flash.message }</p>
		</div>
	);
}

function App( { data }: { data: Bootstrap } ): ReactElement {
	const [ filter, setFilter ] = useState( data.filter );
	const [ tabs, setTabs ] = useState< Tab[] >( data.tabs );
	const [ queue, setQueue ] = useState< Queue >( data.queue );
	const [ campaign, setCampaign ] = useState< Campaign | null >(
		data.campaign
	);
	const [ busy, setBusy ] = useState( false );
	const [ flash, setFlash ] = useState< Flash >( null );

	/**
	 * Writes one navigation into the address bar.
	 *
	 * @param next Query parameters for the view being shown.
	 */
	const push = ( next: Record< string, string > ): void => {
		const url = new URL( window.location.href );

		url.searchParams.set( 'page', 'aggr-review' );

		[ 'campaign', 'filter', 'paged' ].forEach( ( key ) =>
			url.searchParams.delete( key )
		);
		Object.entries( next ).forEach( ( [ key, value ] ) =>
			url.searchParams.set( key, value )
		);

		window.history.pushState( next, '', url.toString() );
	};

	const loadQueue = async (
		nextFilter: string,
		page: number,
		navigate = true
	): Promise< void > => {
		setBusy( true );

		try {
			const result = await apiFetch< {
				filter: string;
				tabs: Tab[];
				queue: Queue;
			} >( {
				path: `${ data.restPath }/queue?filter=${ encodeURIComponent(
					nextFilter
				) }&paged=${ page }`,
			} );

			setFilter( result.filter );
			setTabs( result.tabs );
			setQueue( result.queue );
			setCampaign( null );

			if ( navigate ) {
				push( {
					filter: result.filter,
					paged: String( result.queue.page ),
				} );
			}
		} catch ( reason ) {
			setFlash( { type: 'error', message: errorMessage( reason ) } );
		} finally {
			setBusy( false );
		}
	};

	const loadCampaign = async (
		id: number,
		navigate = true
	): Promise< void > => {
		setBusy( true );

		try {
			const result = await apiFetch< { campaign: Campaign } >( {
				path: `${ data.restPath }/campaigns/${ id }`,
			} );

			setCampaign( result.campaign );

			if ( navigate ) {
				push( {
					campaign: String( id ),
					filter,
					paged: String( queue.page ),
				} );
			}
		} catch ( reason ) {
			setFlash( { type: 'error', message: errorMessage( reason ) } );
		} finally {
			setBusy( false );
		}
	};

	/**
	 * Posts one decision and adopts whatever the server sends back.
	 *
	 * @param path    Route below the review namespace.
	 * @param body    Request body.
	 * @param message Success notice.
	 */
	/**
	 * Creates a campaign for an advertiser, then opens it in their wizard.
	 *
	 * Not routed through `write`, which re-reads the campaign under review;
	 * there is no campaign on screen here, and the point of the call is to
	 * leave for the portal with the new id.
	 */
	const createCampaign = async (
		orgId: number,
		title: string
	): Promise< void > => {
		setBusy( true );
		setFlash( null );

		try {
			const created = await apiFetch< { id?: number } >( {
				path: '/aggr/v1/campaigns/for-advertiser',
				method: 'POST',
				data: { org_id: orgId, title },
			} );

			if ( created.id ) {
				window.location.href = `${ data.portalBase }${ created.id }/`;

				return;
			}

			setFlash( { type: 'error', message: errorMessage( null ) } );
		} catch ( reason ) {
			setFlash( { type: 'error', message: errorMessage( reason ) } );
		} finally {
			setBusy( false );
		}
	};

	const write = async (
		path: string,
		body: Record< string, unknown >,
		message: string
	): Promise< void > => {
		setBusy( true );
		setFlash( null );

		try {
			const result = await apiFetch< { campaign?: Campaign } >( {
				path,
				method: 'POST',
				data: body,
			} );

			if ( result.campaign ) {
				setCampaign( result.campaign );
			} else if ( campaign ) {
				// A route that answers with nothing still moved something, so
				// the campaign is re-read rather than left as it was drawn.
				await loadCampaign( campaign.id, false );
			}

			setFlash( { type: 'success', message } );
		} catch ( reason ) {
			setFlash( { type: 'error', message: errorMessage( reason ) } );
		} finally {
			setBusy( false );
		}
	};

	// Back and forward move between the two views without a page load.
	useEffect( () => {
		const onPop = (): void => {
			const params = new URLSearchParams( window.location.search );
			const id = Number( params.get( 'campaign' ) ?? 0 );

			if ( id > 0 ) {
				void loadCampaign( id, false );

				return;
			}

			void loadQueue(
				params.get( 'filter' ) ?? data.filter,
				Number( params.get( 'paged' ) ?? 1 ),
				false
			);
		};

		window.addEventListener( 'popstate', onPop );

		return () => window.removeEventListener( 'popstate', onPop );

		/*
		 * Bound once, and the empty dependency list is correct rather than an
		 * oversight. The handler reads the URL and passes everything it needs as
		 * arguments with navigate=false, so it closes over nothing that changes;
		 * re-binding on every state change would add and remove a listener on
		 * each keystroke in the notes box for no behavioural difference.
		 */
	}, [] );

	return (
		<>
			<FlashNotice flash={ flash } />

			{ campaign ? (
				<CampaignView
					campaign={ campaign }
					busy={ busy }
					onBack={ () => void loadQueue( filter, queue.page ) }
					onTransition={ ( to, notes ) =>
						void write(
							`/aggr/v1/campaigns/${ campaign.id }/transitions`,
							'' === notes.trim()
								? { to }
								: { to, review_notes: notes },
							t( 'transitioned' )
						).then( () => loadCampaign( campaign.id, false ) )
					}
					onNotes={ ( notes ) =>
						void write(
							`${ data.restPath }/campaigns/${ campaign.id }/notes`,
							{ notes },
							t( 'notesSaved' )
						)
					}
					onChanges={ ( decision, notes ) =>
						void write(
							`${ data.restPath }/campaigns/${ campaign.id }/changes`,
							{ decision, notes },
							'approve' === decision
								? t( 'changesApproved' )
								: t( 'changesRejected' )
						)
					}
					onDeclineRequest={ ( notes ) =>
						void write(
							`${ data.restPath }/campaigns/${ campaign.id }/request`,
							{ notes },
							t( 'requestDeclined' )
						)
					}
					onReplacement={ ( id, decision, notes ) =>
						void write(
							`/aggr/v1/creative-replacements/${ id }/decision`,
							{ decision, review_notes: notes },
							'approve' === decision
								? t( 'updateApproved' )
								: t( 'updateRejected' )
						)
					}
				/>
			) : (
				<QueueView
					tabs={ tabs }
					queue={ queue }
					filter={ filter }
					onFilter={ ( key ) => void loadQueue( key, 1 ) }
					onPage={ ( page ) => void loadQueue( filter, page ) }
					onOpen={ ( id ) => void loadCampaign( id ) }
					advertisers={ data.advertisers }
					busy={ busy }
					onCreate={ ( orgId, title ) =>
						void createCampaign( orgId, title )
					}
				/>
			) }
		</>
	);
}

const root = document.getElementById( 'aggr-review-root' );

if ( root ) {
	const raw = root.getAttribute( 'data-aggr-review' );
	let data: Bootstrap = EMPTY;

	try {
		data = raw ? ( JSON.parse( raw ) as Bootstrap ) : EMPTY;
	} catch {
		// A malformed payload renders an empty screen rather than throwing
		// inside a page the reviewer still needs to use.
		data = EMPTY;
	}

	setStrings( data.i18n ?? {} );
	createRoot( root ).render( <App data={ data } /> );
}
