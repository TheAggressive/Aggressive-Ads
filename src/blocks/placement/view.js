/**
 * Fills reserved placement slots after paint and beacons one impression.
 */

const SLOT_SELECTOR = '[data-aggr-slot][data-aggr-fill]';

const fillSlot = async ( root ) => {
	if ( root.dataset.aggrFilled === '1' ) {
		return;
	}

	const url = root.dataset.aggrFill;
	if ( ! url ) {
		return;
	}

	root.dataset.aggrFilled = '1';

	try {
		const response = await fetch( url, {
			credentials: 'omit',
			headers: { Accept: 'application/json' },
		} );

		if ( ! response.ok ) {
			return;
		}

		const payload = await response.json();
		const creative = payload.creative ?? payload.house;
		if ( ! creative?.image || ! creative.click ) {
			return;
		}

		const link = document.createElement( 'a' );
		link.href = creative.click;
		link.rel = 'noopener noreferrer';

		const image = document.createElement( 'img' );
		image.src = creative.image;
		image.alt = creative.alt ?? '';
		image.decoding = 'async';
		link.appendChild( image );
		root.appendChild( link );

		if ( payload.beacon && creative.token ) {
			window.navigator.sendBeacon?.(
				payload.beacon,
				new URLSearchParams( { token: creative.token } )
			);
		}
	} catch {
		root.dataset.aggrFilled = '0';
	}
};

const boot = () => {
	document.querySelectorAll( SLOT_SELECTOR ).forEach( ( slot ) => {
		void fillSlot( slot );
	} );
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', boot );
} else {
	boot();
}
