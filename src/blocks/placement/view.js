/**
 * Fills reserved placement slots after paint and beacons one impression.
 */

const SLOT_SELECTOR = '[data-aggr-slot][data-aggr-fill]';

export const fillSlot = async ( root ) => {
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
		link.style.display = 'block';
		link.style.width = '100%';

		const image = document.createElement( 'img' );
		image.src = creative.image;
		image.alt = creative.alt ?? '';
		image.decoding = 'async';
		image.style.display = 'block';
		image.style.width = '100%';
		image.style.height = 'auto';

		if ( Number.isInteger( creative.width ) && creative.width > 0 ) {
			image.width = creative.width;
		}

		if ( Number.isInteger( creative.height ) && creative.height > 0 ) {
			image.height = creative.height;
		}

		link.appendChild( image );

		const canvas = root.querySelector( ':scope > .aggr-slot__canvas' );
		( canvas ?? root ).appendChild( link );

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

export const boot = () => {
	document.querySelectorAll( SLOT_SELECTOR ).forEach( ( slot ) => {
		void fillSlot( slot );
	} );
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', boot );
} else {
	boot();
}
