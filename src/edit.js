import { __ } from '@wordpress/i18n';
import {
	InspectorControls,
	InspectorAdvancedControls,
	useBlockProps,
} from '@wordpress/block-editor';
import {
	PanelBody,
	TextControl,
	ToggleControl,
	SelectControl,
	RangeControl,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

import './editor.scss';

const IRI_SAFE = /[^\w@.\-]/g; // mirror the server-side sanitizer

export default function Edit( { attributes, setAttributes } ) {
	const {
		gallery,
		userId,
		endpoint,
		displayCaption,
		displayTitle,
		layout,
		order,
		orderBy,
		limit,
		thumbSize,
	} = attributes;

	const blockProps = useBlockProps();

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Gallery', 'image-snippets-gallery' ) }>
					<TextControl
						label={ __( 'Gallery name', 'image-snippets-gallery' ) }
						help={ __(
							'ImageSnippets entity label.',
							'image-snippets-gallery'
						) }
						value={ gallery }
						onChange={ ( v ) =>
							setAttributes( { gallery: v.replace( IRI_SAFE, '' ) } )
						}
					/>
					<ToggleControl
						label={ __( 'Show captions', 'image-snippets-gallery' ) }
						checked={ displayCaption }
						onChange={ ( v ) => setAttributes( { displayCaption: v } ) }
					/>
					<ToggleControl
						label={ __( 'Show gallery title', 'image-snippets-gallery' ) }
						checked={ displayTitle }
						onChange={ ( v ) => setAttributes( { displayTitle: v } ) }
					/>
					<SelectControl
						label={ __( 'Layout', 'image-snippets-gallery' ) }
						value={ layout }
						options={ [
							{ label: 'Grid', value: 'grid' },
							{ label: 'Masonry', value: 'masonry' },
							{ label: 'Justified', value: 'justified' },
						] }
						onChange={ ( v ) => setAttributes( { layout: v } ) }
					/>
					<SelectControl
						label={ __( 'Thumbnail size', 'image-snippets-gallery' ) }
						value={ thumbSize }
						options={ [
							{ label: 'Small', value: 'small' },
							{ label: 'Medium', value: 'medium' },
							{ label: 'Large', value: 'large' },
						] }
						onChange={ ( v ) => setAttributes( { thumbSize: v } ) }
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Sorting', 'image-snippets-gallery' ) }
					initialOpen={ false }
				>
					<SelectControl
						label={ __( 'Order by', 'image-snippets-gallery' ) }
						value={ orderBy }
						options={ [
							{ label: 'Title', value: 'title' },
							{ label: 'Date', value: 'date' },
						] }
						onChange={ ( v ) => setAttributes( { orderBy: v } ) }
					/>
					<SelectControl
						label={ __( 'Order', 'image-snippets-gallery' ) }
						value={ order }
						options={ [
							{ label: 'Ascending', value: 'asc' },
							{ label: 'Descending', value: 'desc' },
						] }
						onChange={ ( v ) => setAttributes( { order: v } ) }
					/>
					<RangeControl
						label={ __( 'Maximum images', 'image-snippets-gallery' ) }
						value={ limit }
						min={ 1 }
						max={ 200 }
						onChange={ ( v ) => setAttributes( { limit: v } ) }
					/>
				</PanelBody>
			</InspectorControls>

			<InspectorAdvancedControls>
				<TextControl
					label={ __( 'User ID', 'image-snippets-gallery' ) }
					help={ __(
						'Filter to one ImageSnippets user (optional).',
						'image-snippets-gallery'
					) }
					value={ userId }
					onChange={ ( v ) =>
						setAttributes( { userId: v.replace( IRI_SAFE, '' ) } )
					}
				/>
				<TextControl
					label={ __( 'SPARQL endpoint', 'image-snippets-gallery' ) }
					help={ __(
						'Override the default ImageSnippets endpoint (optional).',
						'image-snippets-gallery'
					) }
					value={ endpoint }
					onChange={ ( v ) => setAttributes( { endpoint: v } ) }
				/>
			</InspectorAdvancedControls>

			<div { ...blockProps }>
				<ServerSideRender
					block="imagesnippets/gallery"
					attributes={ attributes }
				/>
			</div>
		</>
	);
}
