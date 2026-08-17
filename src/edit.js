import { __, _n, sprintf } from '@wordpress/i18n';
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
	Button,
	Notice,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import apiFetch from '@wordpress/api-fetch';
import { useState } from '@wordpress/element';

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
		aspectRatio,
		useFilename,
		cacheTtl,
		jsonldProfile,
	} = attributes;

	const blockProps = useBlockProps();

	// Bumping this remounts ServerSideRender, forcing a re-fetch once the server
	// has dropped the cached rows.
	const [ refreshKey, setRefreshKey ] = useState( 0 );
	const [ refreshing, setRefreshing ] = useState( false );
	const [ refreshError, setRefreshError ] = useState( '' );
	const [ refreshNotice, setRefreshNotice ] = useState( '' );

	const refresh = () => {
		setRefreshing( true );
		setRefreshError( '' );
		setRefreshNotice( '' );
		apiFetch( {
			path: '/imagesnippets/v1/refresh',
			method: 'POST',
			data: { attributes },
		} )
			.then( ( result ) => {
				setRefreshKey( ( k ) => k + 1 );
				setRefreshNotice(
					sprintf(
						/* translators: %d: number of images pulled */
						_n(
							'Updated — %d image.',
							'Updated — %d images.',
							result?.images ?? 0,
							'image-snippets-gallery'
						),
						result?.images ?? 0
					)
				);
			} )
			.catch( ( err ) =>
				setRefreshError(
					err?.message ||
						__( 'Could not refresh.', 'image-snippets-gallery' )
				)
			)
			.finally( () => setRefreshing( false ) );
	};

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
					<SelectControl
						label={ __( 'Crop ratio', 'image-snippets-gallery' ) }
						help={ __(
							'Uniform ratio prevents layout shift. Ignored for the masonry layout.',
							'image-snippets-gallery'
						) }
						value={ aspectRatio }
						options={ [
							{ label: 'Original (no crop)', value: 'original' },
							{ label: 'Square (1:1)', value: '1-1' },
							{ label: 'Landscape (4:3)', value: '4-3' },
							{ label: 'Photo (3:2)', value: '3-2' },
							{ label: 'Wide (16:9)', value: '16-9' },
						] }
						onChange={ ( v ) => setAttributes( { aspectRatio: v } ) }
					/>
					<ToggleControl
						label={ __(
							'Use filename when title is missing',
							'image-snippets-gallery'
						) }
						checked={ useFilename }
						onChange={ ( v ) => setAttributes( { useFilename: v } ) }
					/>
					<Button
						variant="secondary"
						onClick={ refresh }
						isBusy={ refreshing }
						disabled={ refreshing || ! gallery }
						__next40pxDefaultSize
					>
						{ __(
							'Refresh from ImageSnippets',
							'image-snippets-gallery'
						) }
					</Button>
					<p
						style={ {
							marginTop: '.5em',
							fontSize: '.85em',
							fontStyle: 'italic',
						} }
					>
						{ __(
							'Pulls the gallery again and clears the cached copy the public page serves.',
							'image-snippets-gallery'
						) }
					</p>
					{ refreshError && (
						<Notice status="error" isDismissible={ false }>
							{ refreshError }
						</Notice>
					) }
					{ ! refreshError && refreshNotice && (
						<Notice status="success" isDismissible={ false }>
							{ refreshNotice }
						</Notice>
					) }
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
				<PanelBody
					title={ __( 'Structured data', 'image-snippets-gallery' ) }
					initialOpen={ false }
				>
					<SelectControl
						label={ __(
							'Metadata detail',
							'image-snippets-gallery'
						) }
						help={ __(
							'How much of each image’s ImageSnippets metadata to embed in the page for search engines and semantic-web tools.',
							'image-snippets-gallery'
						) }
						value={ jsonldProfile }
						options={ [
							{
								label: __(
									'schema.org only — smallest',
									'image-snippets-gallery'
								),
								value: 'schema',
							},
							{
								label: __(
									'Provenance — recommended',
									'image-snippets-gallery'
								),
								value: 'provenance',
							},
							{
								label: __(
									'Full graph — largest',
									'image-snippets-gallery'
								),
								value: 'full',
							},
						] }
						onChange={ ( v ) =>
							setAttributes( { jsonldProfile: v } )
						}
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
				<RangeControl
					label={ __(
						'Check ImageSnippets every (minutes)',
						'image-snippets-gallery'
					) }
					help={ __(
						'How often this gallery is re-fetched from ImageSnippets and stored on this site. Pages always render from the stored copy, so this only sets how quickly changes arrive. Set to 0 to re-fetch on every page view — good for demos, heavier on the endpoint. This editor preview re-fetches every few seconds regardless.',
						'image-snippets-gallery'
					) }
					value={ cacheTtl }
					min={ 0 }
					max={ 120 }
					onChange={ ( v ) =>
						setAttributes( { cacheTtl: undefined === v ? 10 : v } )
					}
				/>
			</InspectorAdvancedControls>

			<div { ...blockProps }>
				<ServerSideRender
					key={ refreshKey }
					block="imagesnippets/gallery"
					attributes={ attributes }
					urlQueryArgs={ { isg_refresh: String( refreshKey ) } }
				/>
			</div>
		</>
	);
}
