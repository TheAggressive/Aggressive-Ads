import { collapse } from '../trail';
import type { AuditEvent } from '../types';

const event = ( overrides: Partial< AuditEvent > ): AuditEvent => ( {
	message: 'This campaign has not reached its end date.',
	actor: 'System',
	system: true,
	created_at: 0,
	created_text: '',
	day_text: 'Oct 1, 2026',
	time_text: '2:38 am',
	outcome: 'denied',
	outcome_label: 'Refused',
	from_label: 'Live',
	from_pill: 'live',
	to_label: 'Completed',
	to_pill: 'ended',
	...overrides,
} );

describe( 'collapse', () => {
	it( 'folds a run of identical refusals into one entry with its count and span', () => {
		const entries = collapse( [
			event( { created_at: 3, created_text: 'newest' } ),
			event( { created_at: 2 } ),
			event( { created_at: 1, created_text: 'oldest' } ),
		] );

		expect( entries ).toHaveLength( 1 );
		expect( entries[ 0 ]?.count ).toBe( 3 );
		expect( entries[ 0 ]?.first.created_text ).toBe( 'newest' );
		expect( entries[ 0 ]?.last.created_text ).toBe( 'oldest' );
	} );

	// The negatives: the same words either side of a decision are two facts.
	it( 'keeps identical entries apart when something else happened between them', () => {
		const entries = collapse( [
			event( { created_at: 3 } ),
			event( {
				created_at: 2,
				message: 'Campaign moved from Live to Paused.',
				actor: 'Dana Okonkwo',
				system: false,
				outcome: 'ok',
				outcome_label: '',
				to_label: 'Paused',
			} ),
			event( { created_at: 1 } ),
		] );

		expect( entries.map( ( entry ) => entry.count ) ).toEqual( [
			1, 1, 1,
		] );
	} );

	it( 'does not merge the same message from different people or outcomes', () => {
		expect(
			collapse( [
				event( {} ),
				event( { actor: 'Dana Okonkwo', system: false } ),
				event( { outcome: 'ok', outcome_label: '' } ),
			] )
		).toHaveLength( 3 );
	} );

	it( 'returns nothing for no events', () => {
		expect( collapse( [] ) ).toEqual( [] );
	} );
} );
