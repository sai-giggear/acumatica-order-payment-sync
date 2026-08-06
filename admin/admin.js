/**
 * Acumatica Sync — admin UI behaviour
 * File: admin/admin.js
 *
 * Three small jobs: payment-method rows, secret reveal, copy-to-clipboard.
 */
( function () {
	'use strict';

	/* --------------------------------------------- payment method blocks */

	function initMethods() {
		var add = document.querySelector( '.acm-add-method' );
		var list = document.querySelector( '.acm-methods' );
		var template = document.getElementById( 'acm-method-template' );

		if ( ! add || ! list || ! template ) {
			return;
		}

		var empty = document.querySelector( '.acm-methods-empty' );

		function syncEmpty() {
			if ( empty ) {
				empty.hidden = list.children.length > 0;
			}
		}

		add.addEventListener( 'click', function () {
			// Indices only have to be unique within the post, since the
			// sanitiser re-packs the rows into a list. Never reused, so
			// removing a block cannot collide with a later add.
			var index = parseInt( add.dataset.nextIndex, 10 ) || 0;
			add.dataset.nextIndex = index + 1;

			var block = template.content.cloneNode( true );

			block.querySelectorAll( '[name]' ).forEach( function ( field ) {
				field.name = field.name.replace( '__i__', index );
			} );

			list.appendChild( block );
			syncEmpty();

			var slug = list.lastElementChild.querySelector( '.acm-slug' );
			if ( slug ) {
				slug.focus();
			}
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

	/* ---------------------------------------------------- secret reveal */

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

	/* ------------------------------------------------------------- copy */

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

	/* ------------------------------------------------------------- boot */

	function init() {
		initMethods();
		initReveal();
		initCopy();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
