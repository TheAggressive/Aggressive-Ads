/* global afterEach, beforeEach, describe, expect, it */

import { EMPTY_CLASS, collapsesWhenEmpty, settleEmptySlot } from '../empty.js';

describe( 'an unfilled ad slot', () => {
	/**
	 * Builds a slot inside a paragraph-and-slot page.
	 *
	 * The sibling matters: collapsing has to leave the rest of the page alone,
	 * and a test whose document holds only the slot cannot tell the difference
	 * between removing one element and emptying the body.
	 *
	 * @return {HTMLElement} The slot wrapper.
	 */
	const renderSlot = () => {
		document.body.innerHTML = `
			<p id="before">Above the fold</p>
			<div class="wp-block-aggr-ad-slot aggr-slot" data-aggr-slot="leaderboard" style="border:1px solid">
				<div class="aggr-slot__canvas" style="width:728px;aspect-ratio:728/90"></div>
			</div>
			<p id="after">Below the fold</p>
		`;

		return document.querySelector( '[data-aggr-slot]' );
	};

	beforeEach( () => {
		document.body.replaceChildren();
	} );

	afterEach( () => {
		document.body.replaceChildren();
	} );

	it( 'removes the whole wrapper by default, not just the canvas', () => {
		const root = renderSlot();

		expect( settleEmptySlot( root, { rotate: false } ) ).toBe( true );

		// The canvas alone would leave the block's border on the page as a
		// bordered strip of nothing, which is the box collapsing exists to
		// remove.
		expect( document.querySelector( '[data-aggr-slot]' ) ).toBeNull();
		expect( document.querySelector( '.aggr-slot__canvas' ) ).toBeNull();

		// And nothing else moved out with it.
		expect( document.querySelector( '#before' ) ).not.toBeNull();
		expect( document.querySelector( '#after' ) ).not.toBeNull();
	} );

	it( 'keeps the reserved space when the block asked it to', () => {
		const root = renderSlot();

		expect( settleEmptySlot( root, { collapseWhenEmpty: false } ) ).toBe(
			false
		);

		const kept = document.querySelector( '[data-aggr-slot]' );

		expect( kept ).not.toBeNull();
		expect( kept.classList.contains( EMPTY_CLASS ) ).toBe( true );

		// The canvas keeps its declared size, which is the entire point: the
		// publisher asked for the box, so the box has to be ad-shaped.
		expect(
			kept.querySelector( '.aggr-slot__canvas' ).style.aspectRatio
		).toBe( '728/90' );
	} );

	it( 'marks a kept slot once, however many times it is settled', () => {
		const root = renderSlot();

		settleEmptySlot( root, { collapseWhenEmpty: false } );
		settleEmptySlot( root, { collapseWhenEmpty: false } );

		expect(
			document
				.querySelector( '[data-aggr-slot]' )
				.className.split( /\s+/ )
				.filter( ( name ) => name === EMPTY_CLASS )
		).toHaveLength( 1 );
	} );

	describe( 'deciding whether to collapse', () => {
		/*
		 * Collapsing is the shipped behaviour and the recoverable one, so
		 * everything that is not an explicit `false` has to collapse. The
		 * cases below are the ones that actually reach the store: content
		 * written before the attribute existed (no key at all), a context that
		 * failed to encode, and a slot rendered by the plain shortcode
		 * wrapper.
		 */
		it.each( [
			[ 'a context with no such key', { rotate: true } ],
			[ 'an empty context', {} ],
			[ 'an undefined context', undefined ],
			[ 'a null context', null ],
			[ 'an explicit true', { collapseWhenEmpty: true } ],
		] )( 'collapses for %s', ( _label, context ) => {
			expect( collapsesWhenEmpty( context ) ).toBe( true );
		} );

		it( 'keeps the space only for an explicit false', () => {
			expect( collapsesWhenEmpty( { collapseWhenEmpty: false } ) ).toBe(
				false
			);
		} );

		/**
		 * A truthy-looking string is not a decision.
		 *
		 * PHP encodes this key as a real JSON boolean, so a string here means
		 * something built the context by hand. `0` and `''` are the dangerous
		 * pair: a loose check would read either as "keep the space" and hold
		 * an empty box open on every page of a site that never asked.
		 */
		it.each( [ [ 0 ], [ '' ], [ 'false' ], [ null ] ] )(
			'collapses rather than reading %p as a choice',
			( value ) => {
				expect(
					collapsesWhenEmpty( { collapseWhenEmpty: value } )
				).toBe( true );
			}
		);
	} );
} );
