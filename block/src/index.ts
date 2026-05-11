import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import Edit from './edit';
import './style.scss';
import './editor.scss';

// block.json attribute schema shape differs from BlockConfiguration's TS type; cast is safe.
registerBlockType(metadata as any, { edit: Edit });
