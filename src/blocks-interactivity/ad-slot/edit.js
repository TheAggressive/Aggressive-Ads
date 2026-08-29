import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	Notice,
	PanelBody,
	Placeholder,
	RangeControl,
	SelectControl,
	Spinner,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

/**
 * The shortest rotation the editor offers.
 *
 * Matches the floor the view module and PHP both enforce. Three copies of one
 * number is two too many, but the alternatives are worse: the editor bundle
 * cannot import the view module without pulling the Interactivity runtime into
 * the editor, and reading it from PHP would mean a REST round trip to render a
 * slider. `AdSlotRotationTest` asserts the three agree.
 */
const MIN_ROTATE_SECONDS = 30;

/**
 * Editor view: pick a slot. Core block supports style the wrapper.
 *
 * @param {Object}   props
 * @param {{ slot: string, rotate: boolean, rotateSeconds: number }} props.attributes
 * @param {(next: Object) => void} props.setAttributes
 * @return {JSX.Element} The editor view.
 */
export default function Edit( { attributes, setAttributes } ) {
	const { slot, rotate, rotateSeconds } = attributes;
	const [ placements, setPlacements ] = useState( null );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		let cancelled = false;

		apiFetch( { path: '/aggr/v1/placements' } )
			.then( ( body ) => {
				if ( cancelled ) {
					return;
				}

				const rows = Array.isArray( body?.placements )
					? body.placements
					: [];
				setPlacements( rows );
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setError(
						__(
							'Placements could not be loaded.',
							'aggressive-ads'
						)
					);
					setPlacements( [] );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [] );

	const loading = null === placements;
	const selected = ( placements ?? [] ).find( ( row ) => row.slug === slot );
	const width = selected?.width ?? 0;
	const height = selected?.height ?? 0;

	const configured = '' !== slot;
	const staleSlot = configured && ! loading && ! selected;
	const ready = ! loading && '' === error;

	const blockProps = useBlockProps( {
		className: 'aggr-slot',
		style:
			width > 0 && height > 0
				? {
						display: 'grid',
						width: 'fit-content',
						maxWidth: '100%',
						boxSizing: 'border-box',
				  }
				: undefined,
	} );

	const canvasStyle =
		width > 0 && height > 0
			? {
					width: `${ width }px`,
					maxWidth: '100%',
					aspectRatio: `${ width } / ${ height }`,
			  }
			: undefined;

	const options = [
		{
			label: __( 'Select a placement', 'aggressive-ads' ),
			value: '',
		},
		...( placements ?? [] ).map( ( row ) => ( {
			label: `${ row.name } (${ row.size })`,
			value: row.slug,
		} ) ),
	];

	/*
	 * One control, in one place at a time. The inline picker is the setup
	 * affordance while the block is empty — the pattern core's own Image and
	 * Embed blocks use — and the sidebar owns it once a slot is chosen.
	 * Rendering both at once produced two selects with the same accessible
	 * name, which reads to a screen reader as two different settings.
	 */
	const slotField = (
		<SelectControl
			label={ __( 'Slot', 'aggressive-ads' ) }
			help={ __(
				'Editors place a slot, never a campaign. The live creative fills at request time.',
				'aggressive-ads'
			) }
			value={ slot }
			options={ options }
			onChange={ ( value ) => setAttributes( { slot: value } ) }
			__next40pxDefaultSize
			__nextHasNoMarginBottom
		/>
	);

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __( 'Placement', 'aggressive-ads' ) }
					initialOpen
				>
					{ loading ? <Spinner /> : null }

					{ '' !== error ? (
						<Notice status="error" isDismissible={ false }>
							{ error }
						</Notice>
					) : null }

					{ ready && ! configured ? (
						<p className="aggr-slot__hint">
							{ __(
								'Choose a slot on the block to reserve space on the page.',
								'aggressive-ads'
							) }
						</p>
					) : null }

					{ ready && configured ? slotField : null }
				</PanelBody>

				<PanelBody title={ __( 'Rotation', 'aggressive-ads' ) }>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Rotate ads', 'aggressive-ads' ) }
						help={
							rotate
								? __(
										'A new ad is requested on the interval below, while the slot is on screen.',
										'aggressive-ads'
								  )
								: __(
										'A new ad is chosen on every page load.',
										'aggressive-ads'
								  )
						}
						checked={ !! rotate }
						onChange={ ( value ) =>
							setAttributes( { rotate: value } )
						}
					/>

					{ rotate ? (
						<RangeControl
							__nextHasNoMarginBottom
							label={ __(
								'Seconds between ads',
								'aggressive-ads'
							) }
							help={ __(
								'Rotation pauses while the slot is off screen or the tab is in the background, because each rotation counts as an impression.',
								'aggressive-ads'
							) }
							value={ rotateSeconds }
							onChange={ ( value ) =>
								setAttributes( {
									rotateSeconds: Math.max(
										MIN_ROTATE_SECONDS,
										Number( value ) || MIN_ROTATE_SECONDS
									),
								} )
							}
							min={ MIN_ROTATE_SECONDS }
							max={ 600 }
							step={ 5 }
						/>
					) : null }
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<div
					className="aggr-slot__canvas"
					style={ canvasStyle }
					data-aggr-editor-preview={
						selected ? 'configured' : 'empty'
					}
				>
					{ loading ? (
						<div className="aggr-slot__loading">
							<Spinner />
						</div>
					) : null }

					{ ! loading && '' !== error ? (
						<Placeholder
							icon="megaphone"
							label={ __( 'Ad placement', 'aggressive-ads' ) }
							instructions={ __(
								'Placements could not be loaded. Try again after refreshing the editor.',
								'aggressive-ads'
							) }
						/>
					) : null }

					{ ready && ! configured ? (
						<Placeholder
							icon="megaphone"
							label={ __( 'Ad placement', 'aggressive-ads' ) }
							instructions={ __(
								'Choose a slot to reserve space on the page. The live creative fills after publish.',
								'aggressive-ads'
							) }
						>
							{ 0 === placements.length ? (
								<Notice
									status="warning"
									isDismissible={ false }
								>
									{ __(
										'No active placements yet. Create one under Advertising → Inventory.',
										'aggressive-ads'
									) }
								</Notice>
							) : (
								<div className="aggr-slot__picker">
									{ slotField }
								</div>
							) }
						</Placeholder>
					) : null }

					{ ready && configured ? (
						<div className="aggr-slot__preview">
							{ /*
							 * A sibling of the summary rather than a child of
							 * it. This warning used to sit inside a
							 * `role="img"` wrapper, where ARIA treats every
							 * descendant as presentational — the one
							 * actionable message in the block was announced to
							 * nobody.
							 */ }
							{ staleSlot ? (
								<Notice
									status="warning"
									isDismissible={ false }
								>
									{ __(
										'This placement is no longer active. Choose another slot.',
										'aggressive-ads'
									) }
								</Notice>
							) : null }

							<span className="aggr-slot__preview-name">
								{ selected?.name ?? slot }
							</span>

							{ selected?.size ? (
								<span className="aggr-slot__preview-size">
									{ selected.size }
								</span>
							) : null }

							<span className="aggr-slot__preview-note">
								{ __(
									'Filled at request time',
									'aggressive-ads'
								) }
							</span>
						</div>
					) : null }
				</div>
			</div>
		</>
	);
}
