/**
 * Acumatica Sync admin UI behaviour
 * File: admin/admin.js
 */
( function () {
	'use strict';

	/* Payment method blocks */

	function initMethods() {
		var add = document.querySelector( '.acm-add-method' );
		var list = document.querySelector( '.acm-methods' );
		var template = document.getElementById( 'acm-method-template' );

		if ( ! add || ! list || ! template ) {
			return;
		}

		var empty = document.querySelector( '.acm-methods-empty' );

		// Gateway titles are already in the page as datalist options, so the
		// summary can name a slug without a second copy of the list.
		var titles = {};
		document.querySelectorAll( '#acm-gateways option' ).forEach( function ( option ) {
			titles[ option.value ] = option.textContent;
		} );

		function syncEmpty() {
			if ( empty ) {
				empty.hidden = list.children.length > 0;
			}
		}

		// The summary is all that survives the block being collapsed again, so it
		// tracks the inputs rather than staying as the page was rendered.
		function refresh( block ) {
			var slug = block.querySelector( '.acm-slug' ).value.trim();
			var known = Object.prototype.hasOwnProperty.call( titles, slug );
			var method = '';
			var overrides = 0;

			block.querySelectorAll( '[data-key]' ).forEach( function ( field ) {
				var value = field.value.trim();
				if ( value === '' ) {
					return;
				}
				overrides++;
				if ( field.dataset.key === 'acumatica_method' ) {
					method = value;
				}
			} );

			var gateway = block.querySelector( '.acm-method-gateway' );

			block.querySelector( '.acm-method-slug' ).textContent = slug || 'New mapping';
			gateway.textContent = known
				? titles[ slug ]
				: ( slug ? 'No gateway with this slug' : 'No method set' );
			gateway.classList.toggle( 'is-unknown', ! known );

			// Same order as Acumatica_Config::get_payment_config().
			block.querySelector( '.acm-method-target code' ).textContent =
				method || block.dataset.defaultMethod || slug.toUpperCase() || '—';

			block.querySelector( '.acm-method-count' ).textContent = overrides === 0
				? 'All defaults'
				: overrides + ( overrides === 1 ? ' override' : ' overrides' );
		}

		list.addEventListener( 'input', function ( event ) {
			var block = event.target.closest( '.acm-method' );
			if ( block ) {
				refresh( block );
			}
		} );

		// Every summary quotes the default method, so they all move with it.
		var fallback = document.querySelector( '.acm-defaults [data-key="acumatica_method"]' );
		if ( fallback ) {
			fallback.addEventListener( 'input', function () {
				list.querySelectorAll( '.acm-method' ).forEach( function ( block ) {
					block.dataset.defaultMethod = fallback.value.trim();
					refresh( block );
				} );
			} );
		}

		add.addEventListener( 'click', function () {
			// Indices only have to be unique within the post: the sanitiser
			// re-packs the rows into a list. Never reused, so removing a block
			// cannot collide with a later add.
			var index = parseInt( add.dataset.nextIndex, 10 ) || 0;
			add.dataset.nextIndex = index + 1;

			var block = template.content.cloneNode( true );

			block.querySelectorAll( '[name]' ).forEach( function ( field ) {
				field.name = field.name.replace( '__i__', index );
			} );

			list.appendChild( block );
			syncEmpty();

			// A new mapping opens: its summary has nothing to show yet.
			var added = list.lastElementChild;
			added.open = true;
			added.querySelector( '.acm-slug' ).focus();
		} );

		list.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '.acm-remove-method' );
			if ( ! button ) {
				return;
			}

			var block = button.closest( '.acm-method' );
			var slug = block.querySelector( '.acm-slug' );
			var name = slug && slug.value ? slug.value : 'this method';

			if ( ! window.confirm( 'Remove the mapping for ' + name + '? Nothing is deleted until you save.' ) ) {
				return;
			}

			block.remove();
			syncEmpty();
		} );
	}

	/* Secret reveal */

	function initReveal() {
		document.querySelectorAll( '.acm-reveal' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var input = button.parentNode.querySelector( 'input' );
				if ( ! input ) {
					return;
				}

				var hidden = input.type === 'password';
				input.type = hidden ? 'text' : 'password';
				button.setAttribute( 'aria-pressed', hidden ? 'true' : 'false' );
				button.setAttribute( 'aria-label', hidden ? 'Hide value' : 'Show value' );
				button.querySelector( '.dashicons' ).className =
					'dashicons dashicons-' + ( hidden ? 'hidden' : 'visibility' );
			} );
		} );
	}

	/* Copy to clipboard */

	function write( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text );
		}

		// wp-admin over plain http has no clipboard API.
		var area = document.createElement( 'textarea' );
		area.value = text;
		area.setAttribute( 'readonly', '' );
		area.style.position = 'fixed';
		area.style.opacity = '0';
		document.body.appendChild( area );
		area.select();
		try {
			document.execCommand( 'copy' );
		} catch ( e ) {}
		document.body.removeChild( area );
		return Promise.resolve();
	}

	function initCopy() {
		document.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '.acm-copy' );
			if ( ! button ) {
				return;
			}

			event.preventDefault();

			var source = document.getElementById( button.dataset.copyTarget );
			if ( ! source ) {
				return;
			}

			var label = button.textContent;
			write( source.textContent ).then( function () {
				button.textContent = 'Copied';
				setTimeout( function () {
					button.textContent = label;
				}, 1200 );
			} );
		} );
	}

	/* Settings section nav */

	function initNav() {
		var links = document.querySelectorAll( '.acm-nav a' );
		var sections = document.querySelectorAll( '.acm-sections section[id]' );

		if ( ! links.length || ! sections.length || ! window.IntersectionObserver ) {
			return;
		}

		var byId = {};
		links.forEach( function ( link ) {
			byId[ link.getAttribute( 'href' ).slice( 1 ) ] = link;
		} );

		// Bottom margin keeps the highlight on the section you are reading
		// rather than the one just entering at the foot of the window.
		var observer = new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( ! entry.isIntersecting || ! byId[ entry.target.id ] ) {
					return;
				}

				links.forEach( function ( link ) {
					link.classList.remove( 'is-current' );
				} );
				byId[ entry.target.id ].classList.add( 'is-current' );
			} );
		}, { rootMargin: '-90px 0px -65% 0px' } );

		sections.forEach( function ( section ) {
			observer.observe( section );
		} );
	}

	/* Boot */

	function init() {
		initMethods();
		initReveal();
		initCopy();
		initNav();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
