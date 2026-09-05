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
	Modal,
	Notice,
	SelectControl,
	TextControl,
	ToggleControl,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { t } from '../shared/save';
import { CUSTOM, type Placement } from './types';

export function PlacementModal( {
	value,
	sizes,
	ceiling,
	submitLabel,
	busy,
	error,
	onCancel,
	onSubmit,
}: {
	value: Placement;
	sizes: Record< string, string >;
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
