/**
 * WP MCP Connector admin behaviour.
 *
 * Two jobs: swap the platform recipe panels, and copy a snippet to the
 * clipboard. Both degrade honestly — the recipes are rendered server-side and
 * simply all show when scripting is unavailable, and a failed copy says so
 * rather than silently doing nothing.
 *
 * No build step, no dependencies. The plugin ships plain PHP and this file.
 */
( function () {
	'use strict';

	var STORAGE_KEY = 'wpmcp-platform';

	/**
	 * Announces a message to screen readers without moving focus.
	 *
	 * @param {string} message Text to announce.
	 */
	function announce( message ) {
		var region = document.getElementById( 'wpmcp-live' );

		if ( ! region ) {
			return;
		}

		// Clearing first forces a re-announcement when the same text repeats.
		region.textContent = '';
		window.setTimeout( function () {
			region.textContent = message;
		}, 60 );
	}

	/* ------------------------------------------------------------------ *
	 * Platform picker
	 * ------------------------------------------------------------------ */

	function initPicker() {
		var list = document.querySelector( '[data-wpmcp-platforms]' );

		if ( ! list ) {
			return;
		}

		var tabs = Array.prototype.slice.call( list.querySelectorAll( '[role="tab"]' ) );

		if ( ! tabs.length ) {
			return;
		}

		/**
		 * Shows one recipe and hides the rest.
		 *
		 * @param {Element} tab      The tab to select.
		 * @param {boolean} setFocus Whether to move focus to it.
		 */
		function select( tab, setFocus ) {
			tabs.forEach( function ( candidate ) {
				var selected = candidate === tab;
				var panel = document.getElementById( candidate.getAttribute( 'aria-controls' ) );

				candidate.setAttribute( 'aria-selected', selected ? 'true' : 'false' );

				// Roving tabindex: only the selected tab is in the tab order,
				// so the arrow keys own movement within the group.
				candidate.setAttribute( 'tabindex', selected ? '0' : '-1' );

				if ( panel ) {
					panel.hidden = ! selected;
				}
			} );

			if ( setFocus ) {
				tab.focus();
			}

			try {
				window.localStorage.setItem( STORAGE_KEY, tab.dataset.platform );
			} catch ( error ) {
				/* Private browsing or a full quota. The picker still works. */
			}
		}

		list.addEventListener( 'click', function ( event ) {
			var tab = event.target.closest( '[role="tab"]' );

			if ( tab ) {
				select( tab, false );
			}
		} );

		list.addEventListener( 'keydown', function ( event ) {
			var current = event.target.closest( '[role="tab"]' );

			if ( ! current ) {
				return;
			}

			var index = tabs.indexOf( current );
			var next = null;

			switch ( event.key ) {
				case 'ArrowRight':
				case 'ArrowDown':
					next = tabs[ ( index + 1 ) % tabs.length ];
					break;
				case 'ArrowLeft':
				case 'ArrowUp':
					next = tabs[ ( index - 1 + tabs.length ) % tabs.length ];
					break;
				case 'Home':
					next = tabs[ 0 ];
					break;
				case 'End':
					next = tabs[ tabs.length - 1 ];
					break;
				default:
					return;
			}

			event.preventDefault();
			select( next, true );
		} );

		// Restore whatever was open last time, so returning to this screen
		// mid-setup does not make the operator find their client again.
		var remembered = null;

		try {
			remembered = window.localStorage.getItem( STORAGE_KEY );
		} catch ( error ) {
			remembered = null;
		}

		var initial = remembered
			? tabs.filter( function ( tab ) {
				return tab.dataset.platform === remembered;
			} )[ 0 ]
			: null;

		select( initial || tabs[ 0 ], false );
	}

	/* ------------------------------------------------------------------ *
	 * Copy to clipboard
	 * ------------------------------------------------------------------ */

	/**
	 * Copies text, falling back to a hidden textarea where the async
	 * Clipboard API is unavailable — which includes any admin served over
	 * plain HTTP, a common local-development case.
	 *
	 * @param {string} text Text to copy.
	 * @return {Promise<void>} Resolves on success.
	 */
	function copyText( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text );
		}

		return new Promise( function ( resolve, reject ) {
			var field = document.createElement( 'textarea' );

			field.value = text;
			field.setAttribute( 'readonly', '' );
			field.style.position = 'fixed';
			field.style.top = '-9999px';

			document.body.appendChild( field );
			field.select();

			var ok = false;

			try {
				ok = document.execCommand( 'copy' );
			} catch ( error ) {
				ok = false;
			}

			document.body.removeChild( field );

			if ( ok ) {
				resolve();
			} else {
				reject( new Error( 'copy-failed' ) );
			}
		} );
	}

	function initCopy() {
		document.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '[data-wpmcp-copy]' );

			if ( ! button ) {
				return;
			}

			event.preventDefault();

			var source = document.getElementById( button.dataset.wpmcpCopy );

			if ( ! source ) {
				return;
			}

			var label = button.querySelector( '[data-label]' );
			var original = button.dataset.original || ( label ? label.textContent : '' );

			button.dataset.original = original;

			copyText( source.textContent )
				.then( function () {
					button.classList.remove( 'is-failed' );
					button.classList.add( 'is-done' );

					if ( label ) {
						label.textContent = button.dataset.doneLabel || 'Copied';
					}

					announce( button.dataset.doneAnnounce || 'Copied to clipboard' );
				} )
				.catch( function () {
					button.classList.remove( 'is-done' );
					button.classList.add( 'is-failed' );

					if ( label ) {
						label.textContent = button.dataset.failLabel || 'Select it';
					}

					announce( button.dataset.failAnnounce || 'Could not copy. Select the text and copy it manually.' );

					// Put the text under the cursor so the manual route is one
					// keystroke away rather than a drag.
					var range = document.createRange();
					range.selectNodeContents( source );

					var selection = window.getSelection();
					selection.removeAllRanges();
					selection.addRange( range );
				} )
				.finally( function () {
					window.setTimeout( function () {
						button.classList.remove( 'is-done', 'is-failed' );

						if ( label ) {
							label.textContent = original;
						}
					}, 2400 );
				} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			initPicker();
			initCopy();
		} );
	} else {
		initPicker();
		initCopy();
	}
}() );
