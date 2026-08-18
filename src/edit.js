import { __, _n, sprintf } from '@wordpress/i18n';
import {
	BlockControls,
	InspectorControls,
	InspectorAdvancedControls,
	useBlockProps,
} from '@wordpress/block-editor';
import {
	PanelBody,
	ComboboxControl,
	TextControl,
	ToggleControl,
	SelectControl,
	RangeControl,
	Button,
	Notice,
	ToolbarDropdownMenu,
} from '@wordpress/components';
import {
	headingLevel1,
	headingLevel2,
	headingLevel3,
	headingLevel4,
	headingLevel5,
	headingLevel6,
} from '@wordpress/icons';
import ServerSideRender from '@wordpress/server-side-render';
import apiFetch from '@wordpress/api-fetch';
import { useState, useEffect, useMemo } from '@wordpress/element';

import GalleryStyleControls from './style-controls';
import './editor.scss';

const IRI_SAFE = /[^\w@.\-]/g; // mirror the server-side sanitizer
// Gallery names may carry an owner: "owner/gallery". Each part is IRI_SAFE.
const GALLERY_SAFE = /[^\w@.\-/]/g;

const HEADING_ICONS = [
	headingLevel1,
	headingLevel2,
	headingLevel3,
	headingLevel4,
	headingLevel5,
	headingLevel6,
];

// Mirror of isg_block_gap_css() in includes/query.php: turn the stored
// "Block spacing" value into a CSS length for the --isg-gap custom property.
function blockGapStyle( gap ) {
	if ( gap && typeof gap === 'object' ) {
		gap = gap.top;
	}
	if ( typeof gap !== 'string' || ! gap ) {
		return undefined;
	}
	const preset = 'var:preset|spacing|';
	if ( gap.includes( preset ) ) {
		const slug = gap
			.slice( gap.lastIndexOf( '|' ) + 1 )
			.replace( /([a-z])([A-Z])/g, '$1-$2' )
			.toLowerCase();
		gap = `var(--wp--preset--spacing--${ slug })`;
	}
	return { '--isg-gap': gap };
}

export default function Edit( { attributes, setAttributes, clientId } ) {
	const {
		gallery,
		userId,
		endpoint,
		displayCaption,
		displayTitle,
		titleLevel,
		layout,
		order,
		orderBy,
		limit,
		columns,
		aspectRatio,
		useFilename,
		cacheTtl,
		jsonldProfile,
	} = attributes;

	// Native block supports (spacing/color/typography/border/shadow) are applied
	// to this wrapper by useBlockProps. The server-side preview inside it is
	// asked to skip them (skipBlockSupportAttributes below) so they are not
	// applied twice. blockGap is the exception: core emits it only for blocks
	// with `layout` support, so we bridge it to --isg-gap here as render does.
	const blockProps = useBlockProps( {
		style: blockGapStyle( attributes?.style?.spacing?.blockGap ),
	} );

	// Bumping this remounts ServerSideRender, forcing a re-fetch once the server
	// has dropped the cached rows.
	const [ refreshKey, setRefreshKey ] = useState( 0 );
	const [ refreshing, setRefreshing ] = useState( false );
	const [ refreshError, setRefreshError ] = useState( '' );
	const [ refreshNotice, setRefreshNotice ] = useState( '' );

	// The gallery picker's list, from imagesnippets/v1/galleries. Failure is
	// not fatal: the picker still accepts a typed name.
	const [ galleryList, setGalleryList ] = useState( [] );
	const [ galleryListState, setGalleryListState ] = useState( 'loading' ); // loading | ready | error
	const [ galleryTyped, setGalleryTyped ] = useState( '' );
	useEffect( () => {
		let cancelled = false;
		setGalleryListState( 'loading' );
		const query = endpoint
			? `?endpoint=${ encodeURIComponent( endpoint ) }`
			: '';
		apiFetch( { path: `/imagesnippets/v1/galleries${ query }` } )
			.then( ( result ) => {
				if ( cancelled ) {
					return;
				}
				setGalleryList( result?.galleries ?? [] );
				setGalleryListState( 'ready' );
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setGalleryList( [] );
					setGalleryListState( 'error' );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ endpoint ] );

	const galleryOptions = useMemo( () => {
		const options = galleryList.map( ( g ) => ( {
			value: g.value,
			label: sprintf(
				/* translators: 1: gallery name, 2: number of images */
				_n(
					'%1$s — %2$d image',
					'%1$s — %2$d images',
					g.count,
					'image-snippets-gallery'
				),
				g.value,
				g.count
			),
		} ) );
		const known = new Set( options.map( ( o ) => o.value ) );
		// A stored name that is not on the list — set before the list existed,
		// or newer than the cached list — stays selectable, shown plainly.
		if ( gallery && ! known.has( gallery ) ) {
			options.unshift( { value: gallery, label: gallery } );
			known.add( gallery );
		}
		// A typed name that matches nothing is offered as an explicit choice.
		// It goes last so Enter picks a real match when there is one.
		const typed = galleryTyped.replace( GALLERY_SAFE, '' );
		if ( typed && ! known.has( typed ) ) {
			options.push( {
				value: typed,
				label: sprintf(
					/* translators: %s: gallery name as typed */
					__( 'Use “%s”', 'image-snippets-gallery' ),
					typed
				),
			} );
		}
		return options;
	}, [ galleryList, galleryTyped, gallery ] );

	let galleryHelp = __(
		'The gallery on ImageSnippets. Galleries outside the main Imagesnippets datasets are listed as owner/gallery.',
		'image-snippets-gallery'
	);
	if ( 'loading' === galleryListState ) {
		galleryHelp = __(
			'Loading galleries from ImageSnippets…',
			'image-snippets-gallery'
		);
	} else if ( 'error' === galleryListState ) {
		galleryHelp = __(
			'Could not load the gallery list from ImageSnippets. Type the gallery name; for a gallery outside the main Imagesnippets datasets, include its owner: owner/gallery.',
			'image-snippets-gallery'
		);
	}

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

	// Site-wide defaults from Tools → ImageSnippets, injected by
	// isg_editor_defaults_script() ahead of this bundle.
	const siteDefaults = window.isgEditorDefaults ?? {};
	const defaultTtl = Number.isFinite( siteDefaults.ttl )
		? siteDefaults.ttl
		: 10;
	const overridesTtl = null !== cacheTtl && undefined !== cacheTtl;

	// Masonry keeps natural heights, so a crop ratio cannot apply there. The
	// server forces 'original' in that case; show the same so the control tells
	// the truth while disabled.
	const isMasonry = 'masonry' === layout;
	const shownRatio = isMasonry ? 'original' : aspectRatio;

	return (
		<>
			{ displayTitle && (
				<BlockControls group="block">
					<ToolbarDropdownMenu
						icon={ HEADING_ICONS[ titleLevel - 1 ] }
						label={ __(
							'Change title level',
							'image-snippets-gallery'
						) }
						controls={ [ 1, 2, 3, 4, 5, 6 ].map( ( level ) => ( {
							icon: HEADING_ICONS[ level - 1 ],
							title: sprintf(
								/* translators: %d: heading level */
								__( 'Heading %d', 'image-snippets-gallery' ),
								level
							),
							isActive: level === titleLevel,
							onClick: () =>
								setAttributes( { titleLevel: level } ),
						} ) ) }
					/>
				</BlockControls>
			) }
			<InspectorControls>
				<PanelBody title={ __( 'Source', 'image-snippets-gallery' ) }>
					<ComboboxControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Gallery', 'image-snippets-gallery' ) }
						help={ galleryHelp }
						value={ gallery }
						options={ galleryOptions }
						onChange={ ( v ) =>
							setAttributes( {
								gallery: ( v ?? '' ).replace(
									GALLERY_SAFE,
									''
								),
							} )
						}
						onFilterValueChange={ setGalleryTyped }
						allowReset
						expandOnFocus
					/>
					<SelectControl
						label={ __( 'Sort by', 'image-snippets-gallery' ) }
						value={ `${ orderBy }-${ order }` }
						options={ [
							{
								label: __(
									'Newest first',
									'image-snippets-gallery'
								),
								value: 'date-desc',
							},
							{
								label: __(
									'Oldest first',
									'image-snippets-gallery'
								),
								value: 'date-asc',
							},
							{
								label: __(
									'Title A → Z',
									'image-snippets-gallery'
								),
								value: 'title-asc',
							},
							{
								label: __(
									'Title Z → A',
									'image-snippets-gallery'
								),
								value: 'title-desc',
							},
						] }
						onChange={ ( v ) => {
							const [ by, dir ] = v.split( '-' );
							setAttributes( { orderBy: by, order: dir } );
						} }
					/>
					<RangeControl
						label={ __(
							'Maximum images',
							'image-snippets-gallery'
						) }
						value={ limit }
						min={ 1 }
						max={ 200 }
						onChange={ ( v ) => setAttributes( { limit: v } ) }
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
				<PanelBody title={ __( 'Layout', 'image-snippets-gallery' ) }>
					<SelectControl
						label={ __( 'Layout', 'image-snippets-gallery' ) }
						value={ layout }
						options={ [
							{ label: 'Grid', value: 'grid' },
							{ label: 'Masonry', value: 'masonry' },
						] }
						onChange={ ( v ) => setAttributes( { layout: v } ) }
					/>
					<RangeControl
						label={ __( 'Columns', 'image-snippets-gallery' ) }
						help={ __(
							'Phones show at most two.',
							'image-snippets-gallery'
						) }
						value={ columns }
						min={ 1 }
						max={ 8 }
						onChange={ ( v ) =>
							setAttributes( { columns: v ?? 3 } )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<SelectControl
						label={ __( 'Crop ratio', 'image-snippets-gallery' ) }
						help={
							isMasonry
								? __(
										'Masonry keeps each image’s own proportions.',
										'image-snippets-gallery'
								  )
								: __(
										'A uniform ratio prevents layout shift while images load.',
										'image-snippets-gallery'
								  )
						}
						value={ shownRatio }
						disabled={ isMasonry }
						options={ [
							{ label: 'Original (no crop)', value: 'original' },
							{ label: 'Square (1:1)', value: '1-1' },
							{ label: 'Landscape (4:3)', value: '4-3' },
							{ label: 'Photo (3:2)', value: '3-2' },
							{ label: 'Wide (16:9)', value: '16-9' },
						] }
						onChange={ ( v ) =>
							setAttributes( { aspectRatio: v } )
						}
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Title & captions', 'image-snippets-gallery' ) }
				>
					<ToggleControl
						label={ __(
							'Show gallery title',
							'image-snippets-gallery'
						) }
						checked={ displayTitle }
						onChange={ ( v ) =>
							setAttributes( { displayTitle: v } )
						}
					/>
					<ToggleControl
						label={ __(
							'Show captions',
							'image-snippets-gallery'
						) }
						checked={ displayCaption }
						onChange={ ( v ) =>
							setAttributes( { displayCaption: v } )
						}
					/>
				</PanelBody>
			</InspectorControls>

			<GalleryStyleControls
				attributes={ attributes }
				setAttributes={ setAttributes }
				clientId={ clientId }
			/>

			<InspectorAdvancedControls>
				<SelectControl
					label={ __( 'Structured data', 'image-snippets-gallery' ) }
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
					onChange={ ( v ) => setAttributes( { jsonldProfile: v } ) }
				/>
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
				<ToggleControl
					label={ __(
						'Use filename when title is missing',
						'image-snippets-gallery'
					) }
					checked={ useFilename }
					onChange={ ( v ) => setAttributes( { useFilename: v } ) }
				/>
				<TextControl
					label={ __( 'SPARQL endpoint', 'image-snippets-gallery' ) }
					help={ __(
						'Leave blank to use the site default (Tools → ImageSnippets).',
						'image-snippets-gallery'
					) }
					placeholder={ siteDefaults.endpoint ?? '' }
					value={ endpoint }
					onChange={ ( v ) => setAttributes( { endpoint: v } ) }
				/>
				{ /* One node, so the slider stays with its switch: the Advanced
				     slot orders fills by mount time, and a child mounted later
				     would otherwise land after core's own additions. */ }
				<div>
					<ToggleControl
						label={ __(
							'Custom refetch rate for this gallery',
							'image-snippets-gallery'
						) }
						help={
							overridesTtl
								? undefined
								: sprintf(
										/* translators: %d: minutes */
										__(
											'Uses the site default: every %d minutes.',
											'image-snippets-gallery'
										),
										defaultTtl
								  )
						}
						checked={ overridesTtl }
						onChange={ ( on ) =>
							setAttributes( {
								cacheTtl: on ? defaultTtl : null,
							} )
						}
					/>
					{ overridesTtl && (
						<RangeControl
							label={ __(
								'Refetch every (minutes)',
								'image-snippets-gallery'
							) }
							help={ __(
								'Pages render from the stored copy; this only sets how soon changes from ImageSnippets arrive. 0 refetches on every page view.',
								'image-snippets-gallery'
							) }
							value={ cacheTtl }
							min={ 0 }
							max={ 1440 }
							onChange={ ( v ) =>
								setAttributes( { cacheTtl: v ?? defaultTtl } )
							}
						/>
					) }
				</div>
			</InspectorAdvancedControls>

			<div { ...blockProps }>
				<ServerSideRender
					key={ refreshKey }
					block="imagesnippets/gallery"
					attributes={ attributes }
					skipBlockSupportAttributes
					urlQueryArgs={ { isg_refresh: String( refreshKey ) } }
				/>
			</div>
		</>
	);
}
