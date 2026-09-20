/**
 * One creative as it will appear, at the widths people browse at.
 *
 * **The exact revision, and untrusted.** P17 forbids editing a reviewed
 * revision to make something to look at, so this frames the bytes that were
 * uploaded, from the authenticated route; and a reviewer's browser is not a
 * safer place to run a creative than a visitor's, so the frame carries the
 * sandbox the server sends — empty, meaning every restriction — over a
 * response whose own policy allows the image and nothing else.
 *
 * The widths come from the server too (`Domain\Preview_Frame`), because the
 * advertiser's portal draws this same frame in PHP.
 */

import type { ReactElement } from 'react';
import { useState } from '@wordpress/element';
import { t } from '../shared/save';

/** What each width is called, in the order they are offered. */
const NAMES: Record< string, string > = {
	phone: 'previewPhone',
	tablet: 'previewTablet',
	desktop: 'previewDesktop',
};

export function DevicePreview( {
	src,
	placement,
	widths,
	sandbox,
}: {
	src: string;
	placement: string;
	widths: Record< string, number >;
	sandbox: string;
} ): ReactElement {
	const keys = Object.keys( widths );
	const [ chosen, setChosen ] = useState( keys[ 0 ] ?? '' );
	const width = widths[ chosen ] ?? 0;

	return (
		<div className="aggr-device">
			{ keys.length > 0 && (
				<div
					className="aggr-device__widths"
					role="group"
					aria-label={ t( 'previewWidth' ) }
				>
					{ keys.map( ( key ) => (
						<button
							key={ key }
							type="button"
							className="aggr-device__width"
							aria-pressed={ key === chosen }
							onClick={ () => setChosen( key ) }
						>
							{ t( NAMES[ key ] ?? '' ) || key }
							<span className="aggr-device__px">
								{ `${ widths[ key ] }px` }
							</span>
						</button>
					) ) }
				</div>
			) }
			<div className="aggr-device__stage">
				<iframe
					className="aggr-device__frame"
					src={ src }
					sandbox={ sandbox }
					referrerPolicy="no-referrer"
					style={ width > 0 ? { width: `${ width }px` } : undefined }
					title={ ( t( 'previewTitle' ) || '%s' ).replace(
						'%s',
						placement
					) }
				/>
			</div>
		</div>
	);
}
