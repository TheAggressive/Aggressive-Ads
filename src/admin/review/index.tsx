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
import {
	createRoot,
	useCallback,
	useEffect,
	useState,
} from '@wordpress/element';
import { errorMessage, setStrings, t } from '../shared/save';
import { QueueView } from './queue';
import { CampaignView } from './campaign';
import { navigateSameOrigin } from '../shared/navigate';
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
	const push = useCallback( ( next: Record< string, string > ): void => {
		const url = new URL( window.location.href );

		url.searchParams.set( 'page', 'aggr-review' );

		[ 'campaign', 'filter', 'paged' ].forEach( ( key ) =>
			url.searchParams.delete( key )
		);
		Object.entries( next ).forEach( ( [ key, value ] ) =>
			url.searchParams.set( key, value )
		);

		window.history.pushState( next, '', url.toString() );
	}, [] );

	const loadQueue = useCallback(
		async (
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
					path: `${
						data.restPath
					}/queue?filter=${ encodeURIComponent(
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
		},
		[ data.restPath, push ]
	);

	/*
	 * `filter` and `queue.page` are read here, and they change.
	 *
	 * That matters more than it looks. The popstate listener below was bound
	 * with an empty dependency list under a comment asserting this function
	 * closed over nothing that changes — which was true of loadQueue and false
	 * of this one. It happened not to bite, because popstate calls it with
	 * navigate=false and the stale values are only read inside that branch.
	 * Naming the dependencies is what makes that a fact rather than an
	 * argument the next edit can quietly invalidate.
	 */
	const loadCampaign = useCallback(
		async ( id: number, navigate = true ): Promise< void > => {
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
		},
		[ data.restPath, push, filter, queue.page ]
	);

	/**
	 * Opens an acting-as session before leaving for the portal.
	 *
	 * The portal is scoped by this session, so entering it is what makes the
	 * dashboard, campaign list and organization screens show the advertiser
	 * rather than the staff member's own empty context.
	 */
	const actFor = async ( orgId: number ): Promise< void > => {
		await apiFetch( {
			path: '/aggr/v1/acting-as',
			method: 'POST',
			data: { org_id: orgId },
		} );
	};

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
				await actFor( orgId );

				if (
					navigateSameOrigin( `${ data.portalBase }${ created.id }/` )
				) {
					return;
				}
			}

			setFlash( { type: 'error', message: errorMessage( null ) } );
		} catch ( reason ) {
			setFlash( { type: 'error', message: errorMessage( reason ) } );
		} finally {
			setBusy( false );
		}
	};

	/**
	 * Saves delivery policy on one line item.
	 *
	 * Its own handler rather than `write`, which posts: the line-item route is
	 * a PATCH carrying the revision it expects, so a second window that changed
	 * the same policy is answered with a conflict instead of being overwritten.
	 *
	 * The campaign is re-read afterwards so the panel redraws from the stored
	 * policy — a refused save must not leave the boxes showing what was typed.
	 *
	 * @param lineItemId Line item to write.
	 * @param revision   Revision the panel was drawn from.
	 * @param fields     Policy fields to store.
	 */
	const saveDeliveryPolicy = async (
		lineItemId: number,
		revision: number,
		fields: Record< string, unknown >
	): Promise< void > => {
		if ( ! campaign ) {
			return;
		}

		setBusy( true );
		setFlash( null );

		try {
			await apiFetch( {
				path: `/aggr/v1/campaigns/${ campaign.id }/line-items/${ lineItemId }`,
				method: 'PATCH',
				data: { ...fields, revision },
			} );

			setFlash( {
				type: 'success',
				message: t( 'deliveryPolicySaved' ),
			} );
		} catch ( reason ) {
			setFlash( { type: 'error', message: errorMessage( reason ) } );
		} finally {
			setBusy( false );
			await loadCampaign( campaign.id, false );
		}
	};

	/**
	 * Posts one decision and adopts whatever the server sends back.
	 *
	 * @param path    Route below the review namespace.
	 * @param body    Request body.
	 * @param message Success notice.
	 */
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
		 * The handler reads the URL and passes what it needs as arguments with
		 * navigate=false, so it never reads the stale halves of these
		 * closures. This list used to be empty with a comment saying so, which
		 * was an argument rather than a guarantee — nothing would have noticed
		 * a later edit making it false. Both loaders are useCallback'd now, so
		 * naming them re-binds the listener only when the filter or the page
		 * actually changes, not on every keystroke in the notes box.
		 */
	}, [ loadCampaign, loadQueue, data.filter ] );

	return (
		<>
			<FlashNotice flash={ flash } />

			{ campaign ? (
				<CampaignView
					campaign={ campaign }
					busy={ busy }
					onBack={ () => void loadQueue( filter, queue.page ) }
					onEdit={ () =>
						void actFor( campaign.org_id ).then( () => {
							if ( ! navigateSameOrigin( campaign.edit_url ) ) {
								setFlash( {
									type: 'error',
									message: errorMessage( null ),
								} );
							}
						} )
					}
					onTransition={ ( to, notes ) =>
						void write(
							`/aggr/v1/campaigns/${ campaign.id }/transitions`,
							'' === notes.trim()
								? { to }
								: { to, review_notes: notes },
							t( 'transitioned' )
						).then( () => loadCampaign( campaign.id, false ) )
					}
					onDeliveryPolicy={ ( id, revision, fields ) =>
						void saveDeliveryPolicy( id, revision, fields )
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
					onPublishCreative={ ( id ) =>
						void write(
							`/aggr/v1/review/creatives/${ id }/publish`,
							{},
							t( 'creativePublished' )
						)
					}
					onRejectCreative={ ( id, notes ) =>
						void write(
							`/aggr/v1/review/creatives/${ id }/reject`,
							{ review_notes: notes },
							t( 'creativeRejected' )
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
