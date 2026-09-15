/**
 * Campaign wizard store.
 *
 * Namespace: aggr/wizard
 * State is keyed per instance at state.wizards[ wizardId ].
 *
 * The forms stay server-rendered. This store announces the current step and
 * moves focus to the step heading when Interactivity hydrates. Navigation
 * remains ordinary links, so no-JS is unchanged.
 */

import { store, getContext } from '@wordpress/interactivity';
import { isWizardStep } from '@aggr/logic';

interface WizardState {
	current: string;
}

interface WizardContext {
	wizardId?: string;
}

const initializedIds = new Set< string >();

const { state, actions } = store( 'aggr/wizard', {
	state: {
		wizards: {} as Record< string, WizardState >,
		// Empty by design: the server hydrates it and this object is merged
		// over the server's. See the note in autosave.ts.
		i18n: {} as { step?: string },
	},
	actions: {
		init() {
			const { wizardId } = getContext< WizardContext >();
			if ( ! wizardId || initializedIds.has( wizardId ) ) {
				return;
			}
			initializedIds.add( wizardId );

			const current = state.wizards[ wizardId ];
			if ( ! current || ! isWizardStep( current.current ) ) {
				return;
			}

			const announcer = document.getElementById(
				`aggr-wizard-status-${ wizardId }`
			);
			if ( announcer && state.i18n.step ) {
				announcer.textContent = state.i18n.step;
			}

			// Full navigation lands focus on the document. Move it to the
			// step heading so a screen-reader user hears the new context.
			// Leave hash targets (fields, :target dialogs) alone.
			if (
				typeof window !== 'undefined' &&
				'' === window.location.hash
			) {
				const heading = document.getElementById(
					'aggr-details-heading'
				);
				if ( heading instanceof HTMLElement ) {
					heading.focus( { preventScroll: true } );
				}
			}
		},
	},
} );

export { state, actions };
