import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	Notice,
	PanelBody,
	Placeholder,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import {
	createElement,
	Fragment,
	useEffect,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

/**
 * Editor view: pick a slot. Core block supports style the wrapper.
 *
 * @param {Object}   props
 * @param {{ slot: string }} props.attributes
 * @param {(next: { slot: string }) => void} props.setAttributes
 * @return {unknown}
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

	const selected = ( placements ?? [] ).find( ( row ) => row.slug === slot );
	const width = selected?.width ?? 0;
	const height = selected?.height ?? 0;
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

	return createElement(
		Fragment,
		null,
		createElement(
			InspectorControls,
			null,
			createElement(
				PanelBody,
				{
					title: __( 'Placement', 'aggressive-ads' ),
					initialOpen: true,
				},
				null === placements ? createElement( Spinner ) : null,
				error
					? createElement(
							Notice,
							{ status: 'error', isDismissible: false },
							error
					  )
					: null,
				Array.isArray( placements )
					? createElement( SelectControl, {
							label: __( 'Slot', 'aggressive-ads' ),
							help: __(
								'Editors place a slot, never a campaign. The live creative fills at request time.',
								'aggressive-ads'
							),
							value: slot,
							options,
							onChange: ( value ) =>
								setAttributes( { slot: value } ),
							__next40pxDefaultSize: true,
							__nextHasNoMarginBottom: true,
					  } )
					: null
			)
		),
		createElement(
			'div',
			blockProps,
			createElement(
				'div',
				{ className: 'aggr-slot__canvas', style: canvasStyle },
				createElement( Placeholder, {
					icon: 'megaphone',
					label: selected
						? selected.name
						: __( 'Ad placement', 'aggressive-ads' ),
					instructions: selected
						? selected.size
						: __(
								'Choose a placement in the sidebar. The reserved box keeps its size so the page does not jump when the ad fills.',
								'aggressive-ads'
						  ),
				} )
			)
		)
	);
}
