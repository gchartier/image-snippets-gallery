/**
 * Front-end behaviour: the lightbox.
 *
 * Everything it shows was rendered by the server into the block's context, so
 * opening an image is a state change, not a request. The <dialog> element
 * supplies the modal behaviour (focus trap, Esc, backdrop) natively.
 */
import { store, getContext, getElement } from '@wordpress/interactivity';

const { state, actions } = store( 'imagesnippets/gallery', {
	state: {
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
			return `${ ctx.index + 1 } / ${ ctx.items.length }`;
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
			getContext().open = false;
		},
		next() {
			const ctx = getContext();
			ctx.index = ( ctx.index + 1 ) % ctx.items.length;
		},
		prev() {
			const ctx = getContext();
			ctx.index = ( ctx.index - 1 + ctx.items.length ) % ctx.items.length;
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
