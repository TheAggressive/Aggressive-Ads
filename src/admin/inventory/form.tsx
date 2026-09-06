/**
 * The placement form, for a new one and for an existing one.
 *
 * A module-level component taking its state as props, like Conversions.
 * Nothing here is a DataViews action, so the frozen-action hazard
 * Organizations documents does not apply — but a form defined inside the
 * screen's render would remount on every keystroke elsewhere.
 *
 * Held as a draft rather than written through, so an abandoned edit changes
 * nothing. The Save button is the only thing that writes.
 */

import type { ReactElement } from 'react';
import { useState } from '@wordpress/element';
import {
	Button,
	Flex,
	FormTokenField,
	Modal,
	Notice,
	SelectControl,
	TextControl,
	ToggleControl,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { t } from '../shared/save';
import { CUSTOM, MAX_BREAKPOINTS, MAX_GROUPS, type Placement } from './types';

export function PlacementModal( {
	value,
	sizes,
	allGroups,
	ceiling,
	submitLabel,
	busy,
	error,
	onCancel,
	onSubmit,
}: {
	value: Placement;
	sizes: Record< string, string >;
	allGroups: string[];
	ceiling: number;
	submitLabel: string;
	busy: boolean;
	error: string;
	onCancel: () => void;
	onSubmit: ( draft: Placement ) => void;
} ): ReactElement {
	const [ draft, setDraft ] = useState( value );

	const set = ( patch: Partial< Placement > ): void =>
		setDraft( { ...draft, ...patch } );

	/*
	 * Breakpoints are edited as an ordered list and stored as a map.
	 *
	 * A map cannot hold two rows that both say "0" while somebody is halfway
	 * through typing the second one, so the list is the editing shape and the
	 * map is built from it on submit. Ordering is by floor ascending, which is
	 * how a person reads a set of screen widths — narrowest first — and the
	 * opposite of how `Size_Map` resolves them.
	 */
	const rows: Array< [ string, string ] > = Object.entries(
		draft.breakpoints
	).sort( ( a, b ) => Number( a[ 0 ] ) - Number( b[ 0 ] ) );

	const commit = ( next: Array< [ string, string ] > ): void => {
		const map: Record< string, string > = {};

		for ( const [ floor, size ] of next ) {
			const width = Math.max( 0, Math.floor( Number( floor ) || 0 ) );

			map[ String( width ) ] = size;
		}

		set( { breakpoints: map } );
	};

	const setRow = ( index: number, floor: string, size: string ): void => {
		const next = rows.slice();

		next[ index ] = [ floor, size ];
		commit( next );
	};

	const removeRow = ( index: number ): void =>
		commit( rows.filter( ( _row, at ) => at !== index ) );

	const addRow = (): void =>
		commit( [ ...rows, [ 0 === rows.length ? '0' : '', '' ] ] );

	const sizeOptions = [
		{ label: t( 'chooseSize' ), value: '' },
		...Object.entries( sizes ).map( ( [ stored, label ] ) => ( {
			label,
			value: stored,
		} ) ),
		{ label: t( 'customSize' ), value: CUSTOM },
	];

	return (
		<Modal
			title={
				0 === value.id ? t( 'newPlacement' ) : t( 'editPlacement' )
			}
			className="aggr-inventory-modal"
			onRequestClose={ onCancel }
		>
			<VStack spacing={ 4 }>
				{ '' !== error ? (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) : null }

				<TextControl
					label={ t( 'name' ) }
					value={ draft.name }
					onChange={ ( name: string ) => set( { name } ) }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>

				<TextControl
					label={ t( 'slug' ) }
					help={ t( 'slugHelp' ) }
					value={ draft.slug }
					onChange={ ( slug: string ) => set( { slug } ) }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>

				<SelectControl
					label={ t( 'size' ) }
					value={ draft.size_preset }
					options={ sizeOptions }
					onChange={ ( size_preset: string ) =>
						set( { size_preset } )
					}
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>

				{ CUSTOM === draft.size_preset ? (
					<HStack
						justify="flex-start"
						alignment="flex-start"
						spacing={ 3 }
					>
						<TextControl
							label={ t( 'customWidth' ) }
							type="number"
							min={ 1 }
							max={ 10000 }
							value={ String( draft.size_width ) }
							onChange={ ( width: string ) =>
								set( { size_width: Number( width ) || 0 } )
							}
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
						<TextControl
							label={ t( 'customHeight' ) }
							type="number"
							min={ 1 }
							max={ 10000 }
							value={ String( draft.size_height ) }
							onChange={ ( height: string ) =>
								set( { size_height: Number( height ) || 0 } )
							}
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
					</HStack>
				) : null }

				<TextControl
					label={ t( 'sortOrder' ) }
					help={ t( 'sortOrderHelp' ) }
					type="number"
					min={ 0 }
					max={ 9999 }
					value={ String( draft.sort_order ) }
					onChange={ ( order: string ) =>
						set( { sort_order: Number( order ) || 0 } )
					}
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>

				<ToggleControl
					label={ t( 'active' ) }
					help={ t( 'activeHelp' ) }
					checked={ draft.active }
					__nextHasNoMarginBottom
					onChange={ ( active: boolean ) => set( { active } ) }
				/>

				<fieldset>
					<legend>{ t( 'responsive' ) }</legend>
					<VStack spacing={ 3 }>
						<p className="aggr-field-help">
							{ t( 'responsiveHelp' ) }
						</p>

						{ rows.map( ( [ floor, size ], index ) => (
							<HStack
								key={ `bp-${ index }` }
								alignment="flex-end"
								spacing={ 2 }
							>
								<TextControl
									label={ t( 'breakpointWidth' ) }
									type="number"
									min={ 0 }
									value={ floor }
									onChange={ ( next: string ) =>
										setRow( index, next, size )
									}
									__nextHasNoMarginBottom
									__next40pxDefaultSize
								/>
								<SelectControl
									label={ t( 'breakpointSize' ) }
									value={ size }
									options={ sizeOptions }
									onChange={ ( next: string ) =>
										setRow( index, floor, next )
									}
									__nextHasNoMarginBottom
									__next40pxDefaultSize
								/>
								<Button
									variant="tertiary"
									isDestructive
									__next40pxDefaultSize
									onClick={ () => removeRow( index ) }
								>
									{ t( 'removeBreakpoint' ) }
								</Button>
							</HStack>
						) ) }

						{ /*
						 * Said rather than silently repaired. `Size_Map` reads a
						 * map with no floor at zero as "not a map" and falls
						 * back to the single size, so a publisher who only
						 * described wide screens would see their configuration
						 * saved and ignored. Inventing a zero row for them
						 * would be guessing what a phone should show.
						 */ }
						{ rows.length > 0 &&
						! rows.some(
							( [ floor ] ) => 0 === Number( floor )
						) ? (
							<Notice status="warning" isDismissible={ false }>
								{ t( 'breakpointBaseNote' ) }
							</Notice>
						) : null }

						<div>
							<Button
								variant="secondary"
								__next40pxDefaultSize
								disabled={ rows.length >= MAX_BREAKPOINTS }
								onClick={ addRow }
							>
								{ t( 'addBreakpoint' ) }
							</Button>
						</div>
					</VStack>
				</fieldset>

				<fieldset>
					<legend>{ t( 'groups' ) }</legend>
					<VStack spacing={ 3 }>
						<p className="aggr-field-help">{ t( 'groupsHelp' ) }</p>

						{ /*
						 * Suggestions come from the groups already in use, so a
						 * publisher reuses a label instead of retyping it and
						 * quietly creating a near-duplicate.
						 *
						 * The server owns the slug rules, so what is typed here
						 * is a label and what comes back after saving may be a
						 * tidied version of it. Normalising in the browser too
						 * would mean two implementations of the same rules that
						 * have to agree forever.
						 */ }
						<FormTokenField
							label={ t( 'groupsLabel' ) }
							value={ draft.groups }
							suggestions={ allGroups }
							maxLength={ MAX_GROUPS }
							onChange={ (
								next: ( string | { value: string } )[]
							) =>
								set( {
									groups: next.map( ( item ) =>
										'string' === typeof item
											? item
											: item.value
									),
								} )
							}
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
					</VStack>
				</fieldset>

				<fieldset>
					<legend>{ t( 'refresh' ) }</legend>
					<VStack spacing={ 4 }>
						<ToggleControl
							label={ t( 'refreshEnabled' ) }
							help={ t( 'refreshEnabledHelp' ) }
							checked={ draft.refresh_enabled }
							__nextHasNoMarginBottom
							onChange={ ( refresh_enabled: boolean ) =>
								set( { refresh_enabled } )
							}
						/>
						<TextControl
							label={ t( 'refreshSeconds' ) }
							help={ t( 'refreshSecondsHelp' ) }
							type="number"
							min={ 1 }
							disabled={ ! draft.refresh_enabled }
							value={ String( draft.refresh_seconds ) }
							onChange={ ( seconds: string ) =>
								set( {
									refresh_seconds: Number( seconds ) || 0,
								} )
							}
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
						<TextControl
							label={ t( 'refreshMax' ) }
							help={ t( 'refreshMaxHelp' ) }
							type="number"
							min={ 0 }
							max={ ceiling }
							disabled={ ! draft.refresh_enabled }
							value={ String( draft.refresh_max_per_view ) }
							onChange={ ( max: string ) =>
								set( {
									refresh_max_per_view: Number( max ) || 0,
								} )
							}
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
					</VStack>
				</fieldset>

				<fieldset>
					<legend>{ t( 'house' ) }</legend>
					<VStack spacing={ 4 }>
						<TextControl
							label={ t( 'houseAttachment' ) }
							help={ t( 'houseAttachmentHelp' ) }
							type="number"
							min={ 0 }
							value={ String( draft.house_attachment_id ) }
							onChange={ ( id: string ) =>
								set( {
									house_attachment_id: Number( id ) || 0,
								} )
							}
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
						<TextControl
							label={ t( 'houseUrl' ) }
							type="url"
							value={ draft.house_click_url }
							onChange={ ( house_click_url: string ) =>
								set( { house_click_url } )
							}
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
						<TextControl
							label={ t( 'houseAlt' ) }
							value={ draft.house_alt }
							onChange={ ( house_alt: string ) =>
								set( { house_alt } )
							}
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>

						{ /*
						 * Not an error — a placement with no house
						 * advertisement is a legitimate configuration, and the
						 * default one. It is said out loud because the
						 * consequence is invisible from this screen: the slot
						 * is simply absent on the page, which looks the same
						 * as a slot nobody placed.
						 */ }
						{ 0 === draft.house_attachment_id ? (
							<Notice status="info" isDismissible={ false }>
								{ t( 'houseMissing' ) }
							</Notice>
						) : null }
					</VStack>
				</fieldset>

				<Flex justify="flex-end">
					<Button
						variant="tertiary"
						__next40pxDefaultSize
						disabled={ busy }
						onClick={ onCancel }
					>
						{ t( 'cancel' ) }
					</Button>
					<Button
						variant="primary"
						__next40pxDefaultSize
						disabled={ busy }
						isBusy={ busy }
						onClick={ () => onSubmit( draft ) }
					>
						{ submitLabel }
					</Button>
				</Flex>
			</VStack>
		</Modal>
	);
}
