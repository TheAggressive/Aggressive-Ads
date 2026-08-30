import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import Edit from './edit.js';
import './editor.css';
import './style.css';

/*
 * `aggr/ad-slot` is a dynamic block rendered by PHP (`Placement_Slot`), which
 * registers it from dist/blocks-interactivity/ad-slot/block.json — so the
 * client normally receives supports and attributes from the server.
 *
 * Passing metadata as the first argument covers the fallback registration in
 * Placement_Slot::register_block(), which runs when dist/ has no block.json and
 * declares no supports. That is the documented form; spreading block.json into
 * the settings object instead pushes non-settings keys (`$schema`,
 * `editorScript`, `style`, `viewScriptModule`) into block registration.
 */
registerBlockType( metadata, {
	edit: Edit,
	save: () => null,
} );
