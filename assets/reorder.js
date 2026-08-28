/**
 * Tools → ImageSnippets → Arrange.
 *
 * Plain DOM and HTML5 drag-and-drop: this screen is one grid and two buttons,
 * and the block editor's toolchain has no business here. Keyboard users get
 * "earlier" / "later" buttons on every tile, which do the same thing as a drag.
 */
( function () {
	'use strict';

	const grid = document.getElementById( 'isg-reorder-grid' );
	const saveBtn = document.getElementById( 'isg-reorder-save' );
	const clearBtn = document.getElementById( 'isg-reorder-clear' );
	if ( ! grid || ! saveBtn || ! clearBtn ) {
		return;
	}
	const cfg = window.isgReorder || {};
	const statusEl = document.getElementById( 'isg-reorder-status' );

	let items = [];
	let dirty = false;
	let dragging = null;

	function t( key, arg ) {
		const s = ( cfg.i18n && cfg.i18n[ key ] ) || key;
		return arg === undefined ? s : s.replace( '%s', arg );
	}

	function setStatus( text, isError ) {
		statusEl.textContent = text || '';
		statusEl.classList.toggle( 'is-error', !! isError );
	}

	function setDirty( value ) {
		dirty = value;
		saveBtn.disabled = ! value;
	}

	function routeUrl() {
		const url = new URL( cfg.route, window.location.href );
		url.searchParams.set( 'gallery', cfg.gallery );
		if ( cfg.endpoint ) {
			url.searchParams.set( 'endpoint', cfg.endpoint );
		}
		return url.toString();
	}

	function request( method, body ) {
		return fetch( routeUrl(), {
			method,
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': cfg.nonce,
				'Content-Type': 'application/json',
			},
			body: body ? JSON.stringify( body ) : undefined,
		} ).then( function ( res ) {
			return res.json().then( function ( json ) {
				if ( ! res.ok ) {
					throw new Error(
						( json && json.message ) || res.statusText
					);
				}
				return json;
			} );
		} );
	}

	function move( from, to ) {
		if ( from === to || to < 0 || to >= items.length ) {
			return;
		}
		const moved = items.splice( from, 1 )[ 0 ];
		items.splice( to, 0, moved );
		setDirty( true );
		render();
	}

	function render() {
		grid.innerHTML = '';
		if ( ! items.length ) {
			const empty = document.createElement( 'li' );
			empty.className = 'isg-reorder__empty';
			empty.textContent = t( 'empty' );
			grid.appendChild( empty );
			return;
		}
		items.forEach( function ( item, index ) {
			const li = document.createElement( 'li' );
			li.className = 'isg-reorder__item';
			li.draggable = true;
			li.dataset.index = String( index );

			const img = document.createElement( 'img' );
			img.className = 'isg-reorder__thumb';
			img.src = item.thumb;
			img.alt = '';
			img.loading = 'lazy';
			if ( item.fallback && item.fallback !== item.thumb ) {
				img.addEventListener( 'error', function () {
					if ( img.src !== item.fallback ) {
						img.src = item.fallback;
					}
				} );
			}
			li.appendChild( img );

			const pos = document.createElement( 'span' );
			pos.className = 'isg-reorder__pos';
			pos.textContent = String( index + 1 );
			li.appendChild( pos );

			if ( ! item.arranged ) {
				const badge = document.createElement( 'span' );
				badge.className = 'isg-reorder__new';
				badge.textContent = t( 'newBadge' );
				li.appendChild( badge );
			}

			const title = document.createElement( 'span' );
			title.className = 'isg-reorder__title';
			title.textContent = item.title || '';
			title.title = item.title || '';
			li.appendChild( title );

			const nudge = document.createElement( 'div' );
			nudge.className = 'isg-reorder__nudge';
			const up = document.createElement( 'button' );
			up.type = 'button';
			up.textContent = '←';
			up.setAttribute( 'aria-label', t( 'moveUp' ) );
			up.disabled = index === 0;
			up.addEventListener( 'click', function () {
				move( index, index - 1 );
				focusItem( index - 1 );
			} );
			const down = document.createElement( 'button' );
			down.type = 'button';
			down.textContent = '→';
			down.setAttribute( 'aria-label', t( 'moveDown' ) );
			down.disabled = index === items.length - 1;
			down.addEventListener( 'click', function () {
				move( index, index + 1 );
				focusItem( index + 1 );
			} );
			nudge.appendChild( up );
			nudge.appendChild( down );
			li.appendChild( nudge );

			li.addEventListener( 'dragstart', function ( e ) {
				dragging = index;
				li.classList.add( 'is-dragging' );
				e.dataTransfer.effectAllowed = 'move';
				try {
					e.dataTransfer.setData( 'text/plain', String( index ) );
				} catch ( err ) {
					// Older engines reject setData; the index is in the closure anyway.
				}
			} );
			li.addEventListener( 'dragend', function () {
				dragging = null;
				grid.querySelectorAll( '.is-over, .is-dragging' ).forEach(
					function ( el ) {
						el.classList.remove( 'is-over', 'is-dragging' );
					}
				);
			} );
			li.addEventListener( 'dragover', function ( e ) {
				if ( dragging === null || dragging === index ) {
					return;
				}
				e.preventDefault();
				e.dataTransfer.dropEffect = 'move';
				li.classList.add( 'is-over' );
			} );
			li.addEventListener( 'dragleave', function () {
				li.classList.remove( 'is-over' );
			} );
			li.addEventListener( 'drop', function ( e ) {
				e.preventDefault();
				if ( dragging === null || dragging === index ) {
					return;
				}
				move( dragging, index );
				dragging = null;
			} );

			grid.appendChild( li );
		} );
	}

	function focusItem( index ) {
		const li = grid.querySelector( '[data-index="' + index + '"]' );
		if ( li ) {
			const btn = li.querySelector( 'button:not(:disabled)' );
			if ( btn ) {
				btn.focus();
			}
		}
	}

	function load() {
		setStatus( '' );
		request( 'GET' )
			.then( function ( json ) {
				items = json.items || [];
				setDirty( false );
				render();
			} )
			.catch( function ( err ) {
				grid.innerHTML = '';
				setStatus( t( 'loadFailed', err.message ), true );
			} );
	}

	function save( pages, doneMessage ) {
		saveBtn.disabled = true;
		clearBtn.disabled = true;
		setStatus( t( 'saving' ) );
		request( 'POST', { pages } )
			.then( function () {
				setStatus( doneMessage );
				load();
			} )
			.catch( function ( err ) {
				setStatus( t( 'failed', err.message ), true );
				saveBtn.disabled = ! dirty;
			} )
			.then( function () {
				clearBtn.disabled = false;
			} );
	}

	saveBtn.addEventListener( 'click', function () {
		save(
			items.map( function ( item ) {
				return item.page;
			} ),
			t( 'saved' )
		);
	} );

	clearBtn.addEventListener( 'click', function () {
		// eslint-disable-next-line no-alert
		if ( window.confirm( t( 'confirmClr' ) ) ) {
			save( [], t( 'cleared' ) );
		}
	} );

	window.addEventListener( 'beforeunload', function ( e ) {
		if ( dirty ) {
			e.preventDefault();
			e.returnValue = t( 'unsaved' );
		}
	} );

	load();
} )();
