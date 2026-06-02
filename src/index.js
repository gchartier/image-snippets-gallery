import { registerBlockType } from '@wordpress/blocks';

import Edit from './edit';
import metadata from './block.json';

import './style.scss';

/**
 * Dynamic (server-rendered) block: no save markup is stored in post content,
 * so `save` returns null and rendering is handled by render.php on the server.
 */
registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
} );
