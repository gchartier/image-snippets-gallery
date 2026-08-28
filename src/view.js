/**
 * Front-end behaviour: the lightbox, the facet filters and the slideshow.
 *
 * Everything either shows was rendered by the server into the block's context,
 * so opening an image or narrowing the gallery is a state change, not a
 * request. The <dialog> element supplies the modal behaviour (focus trap, Esc,
 * backdrop) natively. Filtering only toggles `hidden` on figures that are all
 * still in the page, so nothing is withheld from a crawler or a visitor
 * without scripts.
 */
import {
	store,
	getContext,
	getElement,
	withScope,
} from '@wordpress/interactivity';

const URL_PREFIX = 'isgal_';

// Does an item pass every facet that has a selection? Within a facet any
// selected value will do (a bird OR a fence); across facets all must hold
// (a bird AND 2012).
function matches( item, active ) {
	for ( const facet in active ) {
		const wanted = active[ facet ];
		if ( ! wanted || ! wanted.length ) {
			continue;
		}
		const has = ( item.f && item.f[ facet ] ) || [];
		if ( ! wanted.some( ( v ) => has.includes( v ) ) ) {
			return false;
		}
	}
	return true;
}

// The selection lives in the URL too (?isgal_tag=fog&isgal_year=2012), so a
// filtered view can be shared. Replace, not push: the back button should leave
// the page, not undo chips one by one.
function writeUrl( active ) {
	const url = new URL( window.location.href );
	[ ...url.searchParams.keys() ]
		.filter( ( k ) => k.startsWith( URL_PREFIX ) )
		.forEach( ( k ) => url.searchParams.delete( k ) );
	for ( const facet in active ) {
		( active[ facet ] || [] ).forEach( ( v ) =>
			url.searchParams.append( URL_PREFIX + facet, v )
		);
	}
	window.history.replaceState( window.history.state, '', url );
}

// "3 / 12", counted among the images the filters leave visible.
function positionOf( ctx, index ) {
	const active = ctx.active || {};
	const shown = ctx.items.filter( ( it ) => matches( it, active ) );
	const at = shown.indexOf( ctx.items[ index ] );
	return `${ at + 1 } / ${ shown.length }`;
}

// The nearest index from `from` (stepping by delta, wrapping) whose item the
// filters allow; -1 when nothing is visible.
function nextVisible( ctx, from, delta ) {
	const n = ctx.items.length;
	let i = from;
	for ( let tries = 0; tries < n; tries++ ) {
		i = ( i + delta + n ) % n;
		if ( matches( ctx.items[ i ], ctx.active || {} ) ) {
			return i;
		}
	}
	return -1;
}

// After a filter change the current slide may have vanished; move to one
// that is still shown.
function settleSlide( ctx ) {
	if ( ! ctx.slides ) {
		return;
	}
	if ( ! matches( ctx.items[ ctx.slide ], ctx.active || {} ) ) {
		const i = nextVisible( ctx, ctx.slide, 1 );
		if ( i >= 0 ) {
			ctx.slide = i;
		}
	}
}

const { state, actions } = store( 'imagesnippets/gallery', {
	state: {
		i18n: {
			play: 'Play slideshow',
			pause: 'Pause slideshow',
		},
		get current() {
			const ctx = getContext();
			return ctx.items[ ctx.index ] || {};
		},
		get hasMany() {
			return getContext().items.length > 1;
		},
		get hasDetails() {
			const c = state.current;
			return !! (
				c.creator ||
				c.date ||
				c.rights ||
				( c.tags && c.tags.length ) ||
				c.page
			);
		},
		get position() {
			const ctx = getContext();
			return positionOf( ctx, ctx.index );
		},
		// Slideshow.
		get slidePosition() {
			const ctx = getContext();
			return positionOf( ctx, ctx.slide );
		},
		get slideHidden() {
			const ctx = getContext();
			return ctx.i !== ctx.slide || state.itemHidden;
		},
		get dotOn() {
			const ctx = getContext();
			return ctx.i === ctx.slide;
		},
		get slidePlaying() {
			return !! getContext().playing;
		},
		get playGlyph() {
			return getContext().playing ? '\u23F8' : '\u25B6';
		},
		get playLabel() {
			return getContext().playing ? state.i18n.pause : state.i18n.play;
		},
		// Facets.
		get chipOn() {
			const ctx = getContext();
			return ( ( ctx.active && ctx.active[ ctx.facet ] ) || [] ).includes(
				ctx.value
			);
		},
		get itemHidden() {
			const ctx = getContext();
			const item = ctx.items[ ctx.i ];
			return !! item && ! matches( item, ctx.active || {} );
		},
		get filtering() {
			const active = getContext().active || {};
			return Object.keys( active ).some(
				( k ) => active[ k ] && active[ k ].length
			);
		},
		get visibleCount() {
			const ctx = getContext();
			return ctx.items.filter( ( it ) => matches( it, ctx.active || {} ) )
				.length;
		},
	},
	actions: {
		open( event ) {
			const ctx = getContext();
			if ( ! ctx.lightbox ) {
				return;
			}
			event.preventDefault();
			const { ref } = getElement();
			const figure = ref.closest( '.isgal-item' );
			const i = ctx.items.findIndex( ( it ) => it.anchor === figure?.id );
			ctx.index = i >= 0 ? i : 0;
			ctx.open = true;
		},
		close() {
			const ctx = getContext();
			ctx.open = false;
			// Leaving the lightbox lands the slideshow on the image just seen.
			if ( ctx.slides ) {
				ctx.slide = ctx.index;
			}
		},
		// Arrows step over images the filters have hidden, so the lightbox
		// shows what the page shows.
		next() {
			actions.step( 1 );
		},
		prev() {
			actions.step( -1 );
		},
		step( delta ) {
			const ctx = getContext();
			const i = nextVisible( ctx, ctx.index, delta );
			if ( i >= 0 ) {
				ctx.index = i;
			}
		},
		// Slideshow: the same stepping, over its own cursor.
		slideNext() {
			actions.slideStep( 1 );
		},
		slidePrev() {
			actions.slideStep( -1 );
		},
		slideStep( delta ) {
			const ctx = getContext();
			const i = nextVisible( ctx, ctx.slide, delta );
			if ( i >= 0 ) {
				ctx.slide = i;
			}
		},
		slideTo() {
			const ctx = getContext();
			ctx.slide = ctx.i;
		},
		togglePlay() {
			const ctx = getContext();
			ctx.playing = ! ctx.playing;
		},
		// Autoplay waits while the pointer or focus is on the gallery, so a
		// visitor reading a caption is not moved on.
		hold() {
			getContext().held = true;
		},
		release() {
			getContext().held = false;
		},
		slideKeydown( event ) {
			const ctx = getContext();
			// The lightbox handles its own keys; and typing in a chip should
			// not turn pages.
			if ( ctx.open || ! ctx.slides ) {
				return;
			}
			if ( 'ArrowRight' === event.key ) {
				actions.slideNext();
			} else if ( 'ArrowLeft' === event.key ) {
				actions.slidePrev();
			}
		},
		slideTouchEnd( event ) {
			const ctx = getContext();
			if ( ctx.open ) {
				return;
			}
			const dx = event.changedTouches[ 0 ].clientX - ( ctx.touchX ?? 0 );
			if ( Math.abs( dx ) > 40 ) {
				if ( dx < 0 ) {
					actions.slideNext();
				} else {
					actions.slidePrev();
				}
			}
		},
		toggle() {
			const ctx = getContext();
			const list = ( ctx.active && ctx.active[ ctx.facet ] ) || [];
			ctx.active[ ctx.facet ] = list.includes( ctx.value )
				? list.filter( ( v ) => v !== ctx.value )
				: [ ...list, ctx.value ];
			writeUrl( ctx.active );
			settleSlide( ctx );
		},
		clearFacets() {
			const ctx = getContext();
			for ( const facet in ctx.active ) {
				ctx.active[ facet ] = [];
			}
			writeUrl( ctx.active );
			settleSlide( ctx );
		},
		keydown( event ) {
			if ( 'ArrowRight' === event.key ) {
				actions.next();
			} else if ( 'ArrowLeft' === event.key ) {
				actions.prev();
			}
		},
		backdrop( event ) {
			// A click on the dialog element itself (not its children) is the backdrop.
			if ( event.target === event.currentTarget ) {
				actions.close();
			}
		},
		touchStart( event ) {
			getContext().touchX = event.changedTouches[ 0 ].clientX;
		},
		touchEnd( event ) {
			const ctx = getContext();
			const dx = event.changedTouches[ 0 ].clientX - ( ctx.touchX ?? 0 );
			if ( Math.abs( dx ) > 40 ) {
				if ( dx < 0 ) {
					actions.next();
				} else {
					actions.prev();
				}
			}
		},
	},
	callbacks: {
		// A shared link carries its filters in the query string.
		initFacets() {
			const ctx = getContext();
			if ( ! ctx.active || ! window.location.search ) {
				return;
			}
			const params = new URLSearchParams( window.location.search );
			for ( const facet in ctx.active ) {
				const values = params.getAll( URL_PREFIX + facet );
				if ( values.length ) {
					ctx.active[ facet ] = values;
				}
			}
			settleSlide( ctx );
		},
		// A visitor who asked for less motion gets a slideshow that waits to
		// be clicked; the play button is still there if they want it.
		initMotion() {
			const ctx = getContext();
			if (
				ctx.playing &&
				window.matchMedia &&
				window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches
			) {
				ctx.playing = false;
			}
		},
		// One timer while the show is playing and nothing holds it (pointer,
		// focus, or the lightbox being open). The watch re-runs on any of those
		// changing and the returned cleanup clears the previous timer.
		autoplay() {
			const ctx = getContext();
			if ( ! ctx.playing || ctx.held || ctx.open ) {
				return;
			}
			const timer = setInterval(
				withScope( () => {
					if ( document.hidden ) {
						return;
					}
					actions.slideNext();
				} ),
				ctx.interval
			);
			return () => clearInterval( timer );
		},
		// Keep the <dialog> in step with context.open. showModal() rather than
		// the open attribute: only the modal form traps focus and inerts the page.
		syncDialog() {
			const ctx = getContext();
			const { ref } = getElement();
			if ( ctx.open && ! ref.open ) {
				ref.showModal();
			} else if ( ! ctx.open && ref.open ) {
				ref.close();
			}
		},
		// A search result or shared link that names an image (#isgal-…) opens
		// it straight away when the gallery is set to lightbox.
		openFromHash() {
			const ctx = getContext();
			if ( ! ctx.lightbox || ! window.location.hash ) {
				return;
			}
			const id = window.location.hash.slice( 1 );
			const i = ctx.items.findIndex( ( it ) => it.anchor === id );
			if ( i >= 0 ) {
				ctx.index = i;
				ctx.open = true;
			}
		},
	},
} );
