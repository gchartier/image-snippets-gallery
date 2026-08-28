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
	CheckboxControl,
	BaseControl,
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
import {
	useState,
	useEffect,
	useMemo,
	createInterpolateElement,
} from '@wordpress/element';

import GalleryStyleControls from './style-controls';

// The caption lines an image can show, in the order they are offered. The
// block stores the chosen keys in its own order; the server renders that order.
// Where an image's date may come from; the first with a value wins. Mirrors
// isgal_date_sources() / isgal_default_date_priority() in PHP.
const DATE_SOURCES = [
	{
		key: 'DateTimeOriginal',
		label: __( 'Capture time (EXIF)', 'image-snippets-gallery' ),
	},
	{
		key: 'CreateDate',
		label: __( 'File created (EXIF/XMP)', 'image-snippets-gallery' ),
	},
	{
		key: 'DateCreated',
		label: __( 'Date created (IPTC/Photoshop)', 'image-snippets-gallery' ),
	},
	{
		key: 'ModifyDate',
		label: __( 'Last modified (EXIF)', 'image-snippets-gallery' ),
	},
	{
		key: 'rights',
		label: __( 'Year in the rights statement', 'image-snippets-gallery' ),
	},
];
const DEFAULT_DATE_PRIORITY = [
	'DateTimeOriginal',
	'CreateDate',
	'DateCreated',
	'rights',
];

// What a visitor can filter the gallery by; every one comes from the image's
// ImageSnippets graph. Mirrors isgal_facets() in PHP.
const FACETS = [
	{ key: 'tag', label: __( 'Tags', 'image-snippets-gallery' ) },
	{ key: 'creator', label: __( 'Creator', 'image-snippets-gallery' ) },
	{ key: 'year', label: __( 'Year', 'image-snippets-gallery' ) },
	{ key: 'camera', label: __( 'Camera', 'image-snippets-gallery' ) },
	{ key: 'rights', label: __( 'Rights', 'image-snippets-gallery' ) },
];

const CAPTION_FIELDS = [
	{ key: 'title', label: __( 'Title', 'image-snippets-gallery' ) },
	{ key: 'creator', label: __( 'Creator', 'image-snippets-gallery' ) },
	{ key: 'date', label: __( 'Date', 'image-snippets-gallery' ) },
	{ key: 'rights', label: __( 'Rights', 'image-snippets-gallery' ) },
	{ key: 'tags', label: __( 'Tags', 'image-snippets-gallery' ) },
];

/**
 * An ordered pick-list: a checkbox per field, and Up/Down on the chosen ones.
 * Chosen fields are listed first in their stored order, then the rest.
 *
 * @param {Object}   props          Props.
 * @param {Object[]} props.fields   Every offerable field: { key, label }.
 * @param {string}   props.id       Control id.
 * @param {string}   props.label    Control label.
 * @param {string}   props.help     Help text.
 * @param {string[]} props.value    Chosen field keys, in order.
 * @param {Function} props.onChange Receives the new ordered list.
 */
function OrderedFieldsControl( { fields, id, label, help, value, onChange } ) {
	const chosen = ( value || [] ).filter( ( k ) =>
		fields.some( ( f ) => f.key === k )
	);
	const rest = fields.filter( ( f ) => ! chosen.includes( f.key ) );
	const rows = [
		...chosen.map( ( k ) => fields.find( ( f ) => f.key === k ) ),
		...rest,
	];
	const move = ( key, delta ) => {
		const i = chosen.indexOf( key );
		const j = i + delta;
		if ( i < 0 || j < 0 || j >= chosen.length ) {
			return;
		}
		const next = [ ...chosen ];
		next.splice( i, 1 );
		next.splice( j, 0, key );
		onChange( next );
	};
	return (
		<BaseControl
			id={ id }
			label={ label }
			help={ help }
			__nextHasNoMarginBottom
		>
			<div className="isgal-caption-fields">
				{ rows.map( ( f ) => {
					const on = chosen.includes( f.key );
					const i = chosen.indexOf( f.key );
					return (
						<div
							className="isgal-caption-fields__row"
							key={ f.key }
						>
							<CheckboxControl
								label={ f.label }
								checked={ on }
								onChange={ ( v ) =>
									onChange(
										v
											? [ ...chosen, f.key ]
											: chosen.filter(
													( k ) => k !== f.key
											  )
									)
								}
								__nextHasNoMarginBottom
							/>
							{ on && (
								<span className="isgal-caption-fields__move">
									<Button
										size="small"
										variant="tertiary"
										disabled={ i === 0 }
										onClick={ () => move( f.key, -1 ) }
										label={ __(
											'Move up',
											'image-snippets-gallery'
										) }
									>
										↑
									</Button>
									<Button
										size="small"
										variant="tertiary"
										disabled={ i === chosen.length - 1 }
										onClick={ () => move( f.key, 1 ) }
										label={ __(
											'Move down',
											'image-snippets-gallery'
										) }
									>
										↓
									</Button>
								</span>
							) }
						</div>
					);
				} ) }
			</div>
		</BaseControl>
	);
}
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

// Mirror of isgal_block_gap_css() in includes/query.php: turn the stored
// "Block spacing" value into a CSS length for the --isgal-gap custom property.
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
	return { '--isgal-gap': gap };
}

export default function Edit( { attributes, setAttributes, clientId } ) {
	const {
		gallery,
		userId,
		endpoint,
		displayCaption,
		captionPosition,
		captionFields,
		captionTags,
		hoverEffect,
		dateFields,
		facets,
		facetMax,
		slideAutoplay,
		slideInterval,
		slideNav,
		onClick,
		linkNewTab,
		lightboxDetails,
		displayTitle,
		titleLevel,
		layout,
		order,
		orderBy,
		limit,
		pageSize,
		loadMore,
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
	// with `layout` support, so we bridge it to --isgal-gap here as render does.
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
		'The gallery on ImageSnippets.',
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
	// isgal_editor_defaults_script() ahead of this bundle.
	const siteDefaults = window.isgalEditorDefaults ?? {};
	const reorderUrl = ( () => {
		const base = siteDefaults.reorderUrl ?? '';
		if ( ! base ) {
			return '#';
		}
		const url = new URL( base, window.location.href );
		url.searchParams.set( 'isgal_reorder', gallery ?? '' );
		if ( endpoint ) {
			url.searchParams.set( 'isgal_endpoint', endpoint );
		}
		return url.toString();
	} )();
	const defaultTtl = Number.isFinite( siteDefaults.ttl )
		? siteDefaults.ttl
		: 10;
	const overridesTtl = null !== cacheTtl && undefined !== cacheTtl;

	// Masonry keeps natural heights and justified rows are sized from each
	// image's own proportions, so a crop ratio cannot apply to either. The
	// server forces 'original' in those cases; show the same so the control
	// tells the truth while disabled.
	const isMasonry = 'masonry' === layout;
	const isJustified = 'justified' === layout;
	const isSlideshow = 'slideshow' === layout;
	const shownRatio = isMasonry || isJustified ? 'original' : aspectRatio;

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
							{
								label: __( 'Manual', 'image-snippets-gallery' ),
								value: 'manual-asc',
							},
						] }
						onChange={ ( v ) => {
							const [ by, dir ] = v.split( '-' );
							setAttributes( { orderBy: by, order: dir } );
						} }
						help={
							orderBy === 'manual'
								? createInterpolateElement(
										__(
											'Reorder images under <a>Tools → ImageSnippets</a>.',
											'image-snippets-gallery'
										),
										{
											a: (
												// eslint-disable-next-line jsx-a11y/anchor-has-content
												<a
													href={ reorderUrl }
													target="_blank"
													rel="noreferrer"
												/>
											),
										}
								  )
								: undefined
						}
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
					{ ! isSlideshow && (
						<RangeControl
							label={ __(
								'Images shown at first',
								'image-snippets-gallery'
							) }
							help={
								pageSize
									? __(
											'The rest are in the page but hidden until the visitor asks for more — search engines still see them all.',
											'image-snippets-gallery'
									  )
									: __(
											'All at once. Set a number to add a “Load more” button that reveals that many at a time.',
											'image-snippets-gallery'
									  )
							}
							value={ pageSize || 0 }
							min={ 0 }
							max={ 100 }
							onChange={ ( v ) =>
								setAttributes( { pageSize: v ?? 0 } )
							}
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
					) }
					{ ! isSlideshow && pageSize > 0 && (
						<SelectControl
							label={ __(
								'Reveal the rest',
								'image-snippets-gallery'
							) }
							value={ loadMore || 'button' }
							options={ [
								{
									label: __(
										'With a “Load more” button',
										'image-snippets-gallery'
									),
									value: 'button',
								},
								{
									label: __(
										'As the visitor scrolls (button stays as fallback)',
										'image-snippets-gallery'
									),
									value: 'scroll',
								},
							] }
							onChange={ ( v ) =>
								setAttributes( { loadMore: v } )
							}
						/>
					) }
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
							{ label: 'Justified rows', value: 'justified' },
							{ label: 'Timeline', value: 'timeline' },
							{ label: 'Slideshow', value: 'slideshow' },
						] }
						onChange={ ( v ) => setAttributes( { layout: v } ) }
					/>
					{ ! isSlideshow && (
						<RangeControl
							label={ __( 'Columns', 'image-snippets-gallery' ) }
							help={ __(
								'Max. 2 on mobile',
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
					) }
					{ isSlideshow && (
						<>
							<SelectControl
								label={ __(
									'Slide picker',
									'image-snippets-gallery'
								) }
								value={ slideNav }
								options={ [
									{
										label: __(
											'Dots',
											'image-snippets-gallery'
										),
										value: 'dots',
									},
									{
										label: __(
											'Thumbnails',
											'image-snippets-gallery'
										),
										value: 'thumbnails',
									},
									{
										label: __(
											'None (arrows only)',
											'image-snippets-gallery'
										),
										value: 'none',
									},
								] }
								onChange={ ( v ) =>
									setAttributes( { slideNav: v } )
								}
							/>
							<ToggleControl
								label={ __(
									'Play automatically',
									'image-snippets-gallery'
								) }
								help={ __(
									'Pauses while the pointer is over the gallery, and stays stopped for visitors who ask for less motion.',
									'image-snippets-gallery'
								) }
								checked={ !! slideAutoplay }
								onChange={ ( v ) =>
									setAttributes( { slideAutoplay: v } )
								}
							/>
							{ slideAutoplay && (
								<RangeControl
									label={ __(
										'Seconds per image',
										'image-snippets-gallery'
									) }
									value={ slideInterval }
									min={ 2 }
									max={ 60 }
									onChange={ ( v ) =>
										setAttributes( {
											slideInterval: v ?? 5,
										} )
									}
									__nextHasNoMarginBottom
									__next40pxDefaultSize
								/>
							) }
						</>
					) }
					<SelectControl
						label={ __( 'Crop ratio', 'image-snippets-gallery' ) }
						help={
							isMasonry || isJustified
								? __(
										'This layout keeps each image’s own proportions.',
										'image-snippets-gallery'
								  )
								: undefined
						}
						value={ shownRatio }
						disabled={ isMasonry || isJustified }
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
					title={ __( 'Filters', 'image-snippets-gallery' ) }
					initialOpen={ false }
				>
					<OrderedFieldsControl
						fields={ FACETS }
						id="isgal-facets"
						label={ __(
							'Let visitors filter by',
							'image-snippets-gallery'
						) }
						help={ __(
							'A row of buttons above the gallery, in this order, built from what the images are tagged with on ImageSnippets. A filter every image shares is left out. Filtered views are shareable links.',
							'image-snippets-gallery'
						) }
						value={ facets }
						onChange={ ( v ) => setAttributes( { facets: v } ) }
					/>
					{ ( facets || [] ).length > 0 && (
						<RangeControl
							label={ __(
								'Buttons per filter',
								'image-snippets-gallery'
							) }
							help={ __(
								'The most common values are kept.',
								'image-snippets-gallery'
							) }
							value={ facetMax }
							min={ 3 }
							max={ 50 }
							onChange={ ( v ) =>
								setAttributes( { facetMax: v ?? 12 } )
							}
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
					) }
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
					{ displayCaption && (
						<>
							<SelectControl
								label={ __(
									'Caption position',
									'image-snippets-gallery'
								) }
								value={ captionPosition }
								options={ [
									{
										label: __(
											'Below the image',
											'image-snippets-gallery'
										),
										value: 'below',
									},
									{
										label: __(
											'Over the image',
											'image-snippets-gallery'
										),
										value: 'overlay',
									},
									{
										label: __(
											'Over the image, on hover',
											'image-snippets-gallery'
										),
										value: 'hover',
									},
								] }
								onChange={ ( v ) =>
									setAttributes( { captionPosition: v } )
								}
							/>
							<OrderedFieldsControl
								fields={ CAPTION_FIELDS }
								id="isgal-caption-fields"
								label={ __(
									'Caption lines',
									'image-snippets-gallery'
								) }
								help={ __(
									'Shown in this order. Lines an image has no data for are left out.',
									'image-snippets-gallery'
								) }
								value={ captionFields }
								onChange={ ( v ) =>
									setAttributes( { captionFields: v } )
								}
							/>
							{ ( captionFields || [] ).includes( 'tags' ) && (
								<RangeControl
									label={ __(
										'Tags per image',
										'image-snippets-gallery'
									) }
									value={ captionTags }
									min={ 1 }
									max={ 20 }
									onChange={ ( v ) =>
										setAttributes( { captionTags: v ?? 3 } )
									}
									__nextHasNoMarginBottom
									__next40pxDefaultSize
								/>
							) }
						</>
					) }
					<SelectControl
						label={ __( 'Hover effect', 'image-snippets-gallery' ) }
						value={ hoverEffect }
						options={ [
							{
								label: __( 'None', 'image-snippets-gallery' ),
								value: 'none',
							},
							{
								label: __( 'Zoom', 'image-snippets-gallery' ),
								value: 'zoom',
							},
							{
								label: __( 'Fade', 'image-snippets-gallery' ),
								value: 'fade',
							},
							{
								label: __( 'Lift', 'image-snippets-gallery' ),
								value: 'lift',
							},
						] }
						onChange={ ( v ) =>
							setAttributes( { hoverEffect: v } )
						}
					/>
					<ToggleControl
						label={ __(
							'Use filename when title is missing',
							'image-snippets-gallery'
						) }
						checked={ useFilename }
						onChange={ ( v ) =>
							setAttributes( { useFilename: v } )
						}
					/>
				</PanelBody>
			</InspectorControls>

			<InspectorControls>
				<PanelBody
					title={ __(
						'Clicking an image',
						'image-snippets-gallery'
					) }
					initialOpen={ false }
				>
					<SelectControl
						label={ __( 'Opens', 'image-snippets-gallery' ) }
						value={ onClick }
						options={ [
							{
								label: __(
									'Its ImageSnippets page',
									'image-snippets-gallery'
								),
								value: 'page',
							},
							{
								label: __(
									'A lightbox on this page',
									'image-snippets-gallery'
								),
								value: 'lightbox',
							},
						] }
						onChange={ ( v ) => setAttributes( { onClick: v } ) }
					/>
					{ 'lightbox' === onClick ? (
						<ToggleControl
							label={ __(
								'Show creator, date, rights, tags and a source link',
								'image-snippets-gallery'
							) }
							help={ __(
								'The lightbox is previewed on the published page, not here.',
								'image-snippets-gallery'
							) }
							checked={ lightboxDetails }
							onChange={ ( v ) =>
								setAttributes( { lightboxDetails: v } )
							}
						/>
					) : (
						<ToggleControl
							label={ __(
								'Open in a new tab',
								'image-snippets-gallery'
							) }
							checked={ linkNewTab }
							onChange={ ( v ) =>
								setAttributes( { linkNewTab: v } )
							}
						/>
					) }
				</PanelBody>
			</InspectorControls>

			<GalleryStyleControls
				attributes={ attributes }
				setAttributes={ setAttributes }
				clientId={ clientId }
			/>

			<InspectorAdvancedControls>
				<ToggleControl
					label={ __(
						'Include full JSON-LD for images',
						'image-snippets-gallery'
					) }
					help={
						'schema' === jsonldProfile
							? __(
									'Off: only the schema.org description of each image is embedded — what search engines read. Smallest page.',
									'image-snippets-gallery'
							  )
							: __(
									'On: each image’s ImageSnippets graph — what it depicts, where, by whom, and who asserted it — is embedded alongside the schema.org description, for semantic-web tools.',
									'image-snippets-gallery'
							  )
					}
					checked={ 'schema' !== jsonldProfile }
					onChange={ ( on ) =>
						setAttributes( {
							jsonldProfile: on ? 'provenance' : 'schema',
						} )
					}
				/>
				<OrderedFieldsControl
					fields={ DATE_SOURCES }
					id="isgal-date-fields"
					label={ __( 'Date comes from', 'image-snippets-gallery' ) }
					help={ __(
						'Tried in this order per image; the first that has a value is used for sorting, captions, the lightbox and the timeline.',
						'image-snippets-gallery'
					) }
					value={ dateFields || DEFAULT_DATE_PRIORITY }
					onChange={ ( v ) => setAttributes( { dateFields: v } ) }
				/>
				<TextControl
					label={ __( 'Only images by', 'image-snippets-gallery' ) }
					help={ __(
						'ImageSnippets user name. Leave blank for everyone.',
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
					urlQueryArgs={ { isgal_refresh: String( refreshKey ) } }
				/>
			</div>
		</>
	);
}
