/**
 * Styles-tab panels that go beyond what native block supports can express.
 *
 * Native supports (Color, Typography, Dimensions, Border & Shadow) style the
 * gallery as a whole. These panels add:
 *
 *  - Images: border, radius and shadow for the thumbnails themselves.
 *  - Title & captions: optionally give the title and the captions their own
 *    text colour and size instead of inheriting the gallery's.
 *
 * Every value is stored as a plain CSS string (or a per-side object) and the
 * server turns it into custom properties on the wrapper; see
 * isgal_style_vars() in includes/query.php. Nothing here is duplicated in the
 * editor preview because ServerSideRender receives these attributes intact.
 */
import { __ } from '@wordpress/i18n';
import {
	InspectorControls,
	useSettings,
	// Core's own Image and Cover blocks use these two under the same names, and
	// as of WP 7.0 no stable export exists for either. Revisit if one appears.
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalBorderRadiusControl as BorderRadiusControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalColorGradientSettingsDropdown as ColorGradientSettingsDropdown,
} from '@wordpress/block-editor';
import * as components from '@wordpress/components';

const {
	BaseControl,
	FontSizePicker,
	SelectControl,
	ToggleControl,
	__experimentalToolsPanel: ToolsPanel,
	__experimentalToolsPanelItem: ToolsPanelItem,
} = components;
// Stable export since WP 6.7-ish; the experimental name is what 6.4–6.6 ship.
const BorderBoxControl =
	components.BorderBoxControl ?? components.__experimentalBorderBoxControl;

// Flatten the { default, theme, custom } origins WordPress hands back into the
// multi-origin shape the pickers accept, dropping empty origins.
function origins( setting, labels ) {
	if ( Array.isArray( setting ) ) {
		return setting;
	}
	return Object.entries( labels )
		.filter( ( [ key ] ) => setting?.[ key ]?.length )
		.map( ( [ key, name ] ) => ( {
			name,
			colors: setting[ key ],
			slug: key,
		} ) );
}

function flatten( setting ) {
	if ( Array.isArray( setting ) ) {
		return setting;
	}
	return [ 'theme', 'custom', 'default' ].flatMap(
		( k ) => setting?.[ k ] ?? []
	);
}

function hasBorder( b ) {
	if ( ! b ) {
		return false;
	}
	const sides = [ 'top', 'right', 'bottom', 'left' ];
	if ( sides.some( ( s ) => b[ s ] ) ) {
		return sides.some(
			( s ) => b[ s ]?.width || b[ s ]?.color || b[ s ]?.style
		);
	}
	return !! ( b.width || b.color || b.style );
}

export default function GalleryStyleControls( {
	attributes,
	setAttributes,
	clientId,
} ) {
	const {
		imageBorder,
		imageRadius,
		imageShadow,
		separateText,
		titleColor,
		titleSize,
		captionColor,
		captionSize,
		captionBackground,
		captionPosition,
	} = attributes;
	const overlaid =
		'overlay' === captionPosition || 'hover' === captionPosition;

	const [ palette, shadowPresets, fontSizes ] = useSettings(
		'color.palette',
		'shadow.presets',
		'typography.fontSizes'
	);

	const colors = origins( palette, {
		theme: __( 'Theme', 'image-snippets-gallery' ),
		custom: __( 'Custom', 'image-snippets-gallery' ),
		default: __( 'Default', 'image-snippets-gallery' ),
	} );
	const shadows = flatten( shadowPresets );
	const sizes = flatten( fontSizes );

	const set = ( key ) => ( value ) => setAttributes( { [ key ]: value } );

	return (
		<InspectorControls group="styles">
			<ToolsPanel
				label={ __( 'Images', 'image-snippets-gallery' ) }
				panelId={ clientId }
				resetAll={ () =>
					setAttributes( {
						imageBorder: undefined,
						imageRadius: undefined,
						imageShadow: undefined,
					} )
				}
			>
				<ToolsPanelItem
					panelId={ clientId }
					label={ __( 'Border', 'image-snippets-gallery' ) }
					hasValue={ () => hasBorder( imageBorder ) }
					onDeselect={ () =>
						setAttributes( { imageBorder: undefined } )
					}
					isShownByDefault
				>
					<BorderBoxControl
						label={ __( 'Border', 'image-snippets-gallery' ) }
						colors={ colors }
						value={ imageBorder }
						onChange={ set( 'imageBorder' ) }
						enableAlpha
						enableStyle
						size="__unstable-large"
						__experimentalIsRenderedInSidebar
					/>
				</ToolsPanelItem>
				<ToolsPanelItem
					panelId={ clientId }
					label={ __( 'Radius', 'image-snippets-gallery' ) }
					hasValue={ () => !! imageRadius }
					onDeselect={ () =>
						setAttributes( { imageRadius: undefined } )
					}
					isShownByDefault
				>
					<BorderRadiusControl
						values={ imageRadius }
						onChange={ set( 'imageRadius' ) }
					/>
				</ToolsPanelItem>
				<ToolsPanelItem
					panelId={ clientId }
					label={ __( 'Shadow', 'image-snippets-gallery' ) }
					hasValue={ () => !! imageShadow }
					onDeselect={ () =>
						setAttributes( { imageShadow: undefined } )
					}
					isShownByDefault
				>
					<SelectControl
						label={ __( 'Shadow', 'image-snippets-gallery' ) }
						value={ imageShadow ?? '' }
						options={ [
							{
								label: __( 'None', 'image-snippets-gallery' ),
								value: '',
							},
							...shadows.map( ( s ) => ( {
								label: s.name,
								value: `var:preset|shadow|${ s.slug }`,
							} ) ),
						] }
						onChange={ ( v ) =>
							setAttributes( { imageShadow: v || undefined } )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</ToolsPanelItem>
			</ToolsPanel>

			<ToolsPanel
				label={ __( 'Title & captions', 'image-snippets-gallery' ) }
				panelId={ clientId }
				resetAll={ () =>
					setAttributes( {
						separateText: false,
						titleColor: undefined,
						titleSize: undefined,
						captionColor: undefined,
						captionSize: undefined,
						captionBackground: undefined,
					} )
				}
			>
				{ overlaid && (
					<ColorGradientSettingsDropdown
						panelId={ clientId }
						colors={ colors }
						__experimentalIsRenderedInSidebar
						settings={ [
							{
								label: __(
									'Caption background',
									'image-snippets-gallery'
								),
								colorValue: captionBackground,
								onColorChange: set( 'captionBackground' ),
								clearable: true,
								isShownByDefault: true,
								resetAllFilter: () => ( {
									captionBackground: undefined,
								} ),
							},
						] }
					/>
				) }
				<ToolsPanelItem
					panelId={ clientId }
					label={ __( 'Style separately', 'image-snippets-gallery' ) }
					hasValue={ () => !! separateText }
					onDeselect={ () =>
						setAttributes( { separateText: false } )
					}
					isShownByDefault
				>
					<ToggleControl
						label={ __(
							'Style title and captions separately',
							'image-snippets-gallery'
						) }
						help={
							separateText
								? __(
										'Each has its own colour and size below. Anything left unset still follows the gallery’s Color and Typography.',
										'image-snippets-gallery'
								  )
								: __(
										'Both follow the gallery’s Color and Typography settings above.',
										'image-snippets-gallery'
								  )
						}
						checked={ !! separateText }
						onChange={ set( 'separateText' ) }
						__nextHasNoMarginBottom
					/>
				</ToolsPanelItem>

				{ separateText && (
					<>
						<ColorGradientSettingsDropdown
							panelId={ clientId }
							colors={ colors }
							__experimentalIsRenderedInSidebar
							settings={ [
								{
									label: __(
										'Title colour',
										'image-snippets-gallery'
									),
									colorValue: titleColor,
									onColorChange: set( 'titleColor' ),
									clearable: true,
									isShownByDefault: true,
									resetAllFilter: () => ( {
										titleColor: undefined,
									} ),
								},
								{
									label: __(
										'Caption colour',
										'image-snippets-gallery'
									),
									colorValue: captionColor,
									onColorChange: set( 'captionColor' ),
									clearable: true,
									isShownByDefault: true,
									resetAllFilter: () => ( {
										captionColor: undefined,
									} ),
								},
							] }
						/>
						<ToolsPanelItem
							panelId={ clientId }
							label={ __(
								'Title size',
								'image-snippets-gallery'
							) }
							hasValue={ () => !! titleSize }
							onDeselect={ () =>
								setAttributes( { titleSize: undefined } )
							}
							isShownByDefault
						>
							<BaseControl.VisualLabel>
								{ __( 'Title', 'image-snippets-gallery' ) }
							</BaseControl.VisualLabel>
							<FontSizePicker
								fontSizes={ sizes }
								value={ titleSize }
								onChange={ set( 'titleSize' ) }
								withSlider
								withReset={ false }
								__next40pxDefaultSize
							/>
						</ToolsPanelItem>
						<ToolsPanelItem
							panelId={ clientId }
							label={ __(
								'Caption size',
								'image-snippets-gallery'
							) }
							hasValue={ () => !! captionSize }
							onDeselect={ () =>
								setAttributes( { captionSize: undefined } )
							}
							isShownByDefault
						>
							<BaseControl.VisualLabel>
								{ __( 'Captions', 'image-snippets-gallery' ) }
							</BaseControl.VisualLabel>
							<FontSizePicker
								fontSizes={ sizes }
								value={ captionSize }
								onChange={ set( 'captionSize' ) }
								withSlider
								withReset={ false }
								__next40pxDefaultSize
							/>
						</ToolsPanelItem>
					</>
				) }
			</ToolsPanel>
		</InspectorControls>
	);
}
