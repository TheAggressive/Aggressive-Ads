/**
 * The page heading as the campaign's rename control.
 *
 * Bundled into the two modules that rename a campaign: `@aggr/autosave`,
 * which saves the name on a draft, and `@aggr/save`, which stages it as a
 * proposed change on a running one. One implementation, because the heading
 * is meant to behave identically in both places and two copies are how one
 * of them stops doing so.
 */

export type RenameOutcome = 'saved' | 'error' | 'conflict';

export interface RenameOptions {
	/** What the button does, read after the heading's own name. */
	hint: string;
	/** The field's accessible name while editing. */
	label: string;
	maxLength: number;
	/** Stores the new name. */
	save: ( next: string ) => Promise< RenameOutcome >;
	/** Tells the reader how it went. */
	said: ( outcome: RenameOutcome | 'empty', next: string ) => void;
}

/**
 * Makes the page heading the campaign's rename control.
 *
 * The heading's text becomes a button styled to be indistinguishable from it,
 * so nothing moves when this attaches. Pressing it swaps in a text field in the
 * same type; Enter or leaving the field saves, Escape keeps the old name.
 *
 * The heading's accessible name stays the campaign's name, because that is
 * what the page is about. What the button does is a description, held in a
 * visually hidden node beside the heading rather than inside it.
 *
 * @param heading The page's `<h1>`.
 * @param options How this screen saves a name.
 */
export function enableRename(
	heading: HTMLElement,
	options: RenameOptions
): void {
	let name = ( heading.textContent ?? '' ).trim();

	const button = document.createElement( 'button' );
	button.type = 'button';
	button.className = 'aggr-title__button';
	button.textContent = name;

	const hint = document.createElement( 'span' );
	hint.id = `${ heading.id }-hint`;
	hint.className = 'aggr-sr';
	hint.textContent = options.hint;
	heading.after( hint );
	button.setAttribute( 'aria-describedby', hint.id );

	heading.replaceChildren( button );

	const show = ( text: string, refocus: boolean ): void => {
		button.textContent = text;
		heading.replaceChildren( button );

		// Only after Enter or Escape. Leaving the field by clicking elsewhere
		// put focus somewhere on purpose, and taking it back would fight that.
		if ( refocus ) {
			button.focus();
		}
	};

	button.addEventListener( 'click', () => {
		const input = document.createElement( 'input' );
		input.type = 'text';
		input.className = 'aggr-title__input';
		input.value = name;
		input.maxLength = options.maxLength;
		input.setAttribute( 'aria-label', options.label );

		const fit = (): void => {
			input.style.width = `${ Math.max( input.value.length, 4 ) + 1 }ch`;
		};

		fit();
		input.addEventListener( 'input', fit );
		heading.replaceChildren( input );
		input.focus();
		input.select();

		/*
		 * Once per edit. Enter saves and then removes the field, and removing a
		 * focused field raises `blur` — which would save a second time, a
		 * revision behind the first.
		 */
		let settled = false;

		const settle = async (
			commit: boolean,
			refocus: boolean
		): Promise< void > => {
			if ( settled ) {
				return;
			}
			settled = true;

			const next = input.value.trim();

			if ( ! commit || next === name ) {
				show( name, refocus );
				return;
			}

			if ( '' === next ) {
				show( name, refocus );
				options.said( 'empty', name );
				return;
			}

			input.readOnly = true;

			const outcome = await options.save( next );

			if ( 'saved' !== outcome ) {
				show( name, refocus );
				options.said( outcome, name );
				return;
			}

			if ( document.title.includes( name ) ) {
				document.title = document.title.replace( name, next );
			}

			name = next;
			show( name, refocus );
			options.said( 'saved', name );
		};

		input.addEventListener( 'keydown', ( event ) => {
			if ( 'Enter' === event.key ) {
				event.preventDefault();
				void settle( true, true );
			} else if ( 'Escape' === event.key ) {
				event.preventDefault();
				void settle( false, true );
			}
		} );

		input.addEventListener( 'blur', () => {
			void settle( true, false );
		} );
	} );
}
