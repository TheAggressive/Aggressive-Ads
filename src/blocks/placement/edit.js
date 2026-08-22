import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	Notice,
	PanelBody,
	Placeholder,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

/**
 * Editor view: pick a slot. Core block supports style the wrapper.
 *
 * @param {Object}   props
 * @param {{ slot: string }} props.attributes
 * @param {(next: { slot: string }) => void} props.setAttributes
 * @return {JSX.Element} The editor view.
 */
export default function Edit( { attributes, setAttributes } ) {
	const { slot } = attributes;
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
