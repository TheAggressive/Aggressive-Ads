/**
 * Reference-counted body scroll lock with scrollbar-width compensation.
 *
 * Shared by every portal overlay. A stacked dialog closing must not unlock
 * the page underneath — that is the whole reason for the counter.
 */

let lockCount = 0;

function measureScrollbarWidth(): number {
	const probe = document.createElement( 'div' );
	probe.style.cssText =
		'position:fixed;top:0;left:0;right:0;overflow-y:scroll;visibility:hidden;pointer-events:none;';
	document.body.appendChild( probe );
	const width = probe.offsetWidth - probe.clientWidth;
	probe.remove();
	return width;
}

export function lockScroll(): void {
	lockCount += 1;
	if ( lockCount !== 1 ) {
		return;
	}

	const scrollbarWidth = measureScrollbarWidth();

	document.body.style.overflow = 'hidden';

	if ( scrollbarWidth > 0 ) {
		document.documentElement.style.setProperty(
			'--scrollbar-width',
			`${ scrollbarWidth }px`
		);
		document.body.style.paddingRight = `${ scrollbarWidth }px`;
	}
}

export function unlockScroll(): void {
	lockCount = Math.max( 0, lockCount - 1 );
	if ( lockCount !== 0 ) {
		return;
	}

	document.documentElement.style.removeProperty( '--scrollbar-width' );
	document.body.style.overflow = '';
	document.body.style.paddingRight = '';
}
