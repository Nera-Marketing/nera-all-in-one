/**
 * Nera All In One — Admin JS.
 *
 * No build step. Plain ES5-compatible vanilla JS, loaded after DOM ready
 * via wp_enqueue_script( ..., true ) (footer).
 *
 * Globals provided by wp_localize_script('nera-aio-admin', 'neraAioAdmin', {...}):
 *   neraAioAdmin.ajaxurl     — admin-ajax.php URL
 *   neraAioAdmin.checkNonce  — nonce for nera_aio_check_now
 *   neraAioAdmin.toggleNonce — nonce for nera_aio_toggle
 *   neraAioAdmin.installNonce — nonce for nera_aio_install
 *   neraAioAdmin.demoKey     — demo key string (empty when not demo mode)
 *   neraAioAdmin.demoMode    — boolean
 */
( function () {
	'use strict';

	/* -------------------------------------------------------------------------
	   Helpers
	   ------------------------------------------------------------------------- */

	/**
	 * Show a temporary inline notice next to an element.
	 *
	 * @param {HTMLElement} el
	 * @param {string}      message
	 * @param {string}      type    'success' | 'error'
	 */
	function showInlineNotice( el, message, type ) {
		if ( ! el ) {
			return;
		}
		el.textContent   = message;
		el.className     = 'nera-aio-inline-notice is-' + type;
		el.style.display = 'inline';

		setTimeout( function () {
			el.style.display = 'none';
		}, 6000 );
	}

	/* -------------------------------------------------------------------------
	   Check license now (AJAX)
	   ------------------------------------------------------------------------- */

	var checkBtn = document.getElementById( 'nera-aio-check-now' );
	if ( checkBtn ) {
		checkBtn.addEventListener( 'click', function () {
			var cfg = window.neraAioAdmin || {};
			if ( ! cfg.ajaxurl || ! cfg.checkNonce ) {
				return;
			}

			checkBtn.disabled    = true;
			checkBtn.textContent = 'Checking…';

			var body = new URLSearchParams();
			body.append( 'action', 'nera_aio_check_now' );
			body.append( '_ajax_nonce', cfg.checkNonce );

			fetch( cfg.ajaxurl, {
				method:      'POST',
				credentials: 'same-origin',
				body:        body,
			} )
				.then( function ( res ) {
					return res.json();
				} )
				.then( function ( json ) {
					checkBtn.disabled    = false;
					checkBtn.textContent = 'Check license now';

					var resultEl = document.getElementById( 'nera-aio-check-result' );

					if ( json && json.success && json.data ) {
						var tier   = json.data.tier   || '';
						var source = json.data.source || '';

						// Update on-page tier display if present.
						var tierEls = document.querySelectorAll( '.nera-aio-badge--tier' );
						tierEls.forEach( function ( el ) {
							// Swap tier modifier class.
							el.className = el.className.replace( /nera-aio-badge--tier-\S+/, '' ).trim();
							el.classList.add( 'nera-aio-badge--tier-' + tier );
							el.textContent = tier.toUpperCase();
						} );

						var msg = 'Tier: ' + tier.toUpperCase();
						if ( source ) {
							msg += ' (' + source + ')';
						}
						showInlineNotice( resultEl, msg, 'success' );
					} else {
						var errMsg = ( json && json.data && json.data.message ) ? json.data.message : 'Check failed.';
						showInlineNotice( resultEl, errMsg, 'error' );
					}
				} )
				.catch( function () {
					checkBtn.disabled    = false;
					checkBtn.textContent = 'Check license now';
					var resultEl = document.getElementById( 'nera-aio-check-result' );
					showInlineNotice( resultEl, 'Network error.', 'error' );
				} );
		} );
	}

	/* -------------------------------------------------------------------------
	   Module on/off toggles (AJAX)
	   ------------------------------------------------------------------------- */

	var toggles = document.querySelectorAll( '.nera-aio-toggle' );
	toggles.forEach( function ( input ) {
		input.addEventListener( 'change', function () {
			var cfg = window.neraAioAdmin || {};
			if ( ! cfg.ajaxurl || ! cfg.toggleNonce ) {
				return;
			}

			var id      = input.getAttribute( 'data-id' );
			var enabled = input.checked ? 1 : 0;
			var card    = input.closest( '.nera-aio-plugin-card' );

			input.disabled = true;
			if ( card ) {
				card.classList.add( 'is-pending' );
			}

			var body = new URLSearchParams();
			body.append( 'action', 'nera_aio_toggle' );
			body.append( '_ajax_nonce', cfg.toggleNonce );
			body.append( 'id', id );
			body.append( 'enabled', enabled );

			fetch( cfg.ajaxurl, {
				method:      'POST',
				credentials: 'same-origin',
				body:        body,
			} )
				.then( function ( res ) {
					return res.json();
				} )
				.then( function ( json ) {
					if ( json && json.success ) {
						// Reload so every card's status, badges and any newly
						// (de)activated plugin's admin chrome re-render correctly.
						window.location.reload();
						return;
					}
					revert( json && json.data && json.data.message ? json.data.message : 'Toggle failed.' );
				} )
				.catch( function () {
					revert( 'Network error.' );
				} );

			/**
			 * Roll the switch back to its prior state and surface an error.
			 *
			 * @param {string} message
			 */
			function revert( message ) {
				input.checked  = ! input.checked;
				input.disabled = false;
				if ( card ) {
					card.classList.remove( 'is-pending' );
				}
				window.alert( message );
			}
		} );
	} );

	/* -------------------------------------------------------------------------
	   Install / update / version-switch (AJAX)
	   ------------------------------------------------------------------------- */

	/**
	 * POST an install request for a plugin id at an optional release ref.
	 *
	 * @param {string}   id
	 * @param {string}   ref     Release tag, or '' for latest.
	 * @param {Function} onFail  Called with an error message on failure.
	 */
	function postInstall( id, ref, onFail ) {
		var cfg = window.neraAioAdmin || {};
		if ( ! cfg.ajaxurl || ! cfg.installNonce ) {
			return;
		}

		var body = new URLSearchParams();
		body.append( 'action', 'nera_aio_install' );
		body.append( '_ajax_nonce', cfg.installNonce );
		body.append( 'id', id );
		if ( ref ) {
			body.append( 'ref', ref );
		}

		fetch( cfg.ajaxurl, {
			method:      'POST',
			credentials: 'same-origin',
			body:        body,
		} )
			.then( function ( res ) {
				return res.json();
			} )
			.then( function ( json ) {
				if ( json && json.success ) {
					window.location.reload();
					return;
				}
				onFail( json && json.data && json.data.message ? json.data.message : 'Install failed.' );
			} )
			.catch( function () {
				onFail( 'Network error.' );
			} );
	}

	// Install + Update buttons.
	document.querySelectorAll( '.nera-aio-install-btn, .nera-aio-update-btn' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var id   = btn.getAttribute( 'data-id' );
			var ref  = btn.getAttribute( 'data-ref' ) || '';
			var card = btn.closest( '.nera-aio-plugin-card' );
			var prev = btn.textContent;

			btn.disabled    = true;
			btn.textContent = 'Installing…';
			if ( card ) {
				card.classList.add( 'is-pending' );
			}

			postInstall( id, ref, function ( message ) {
				btn.disabled    = false;
				btn.textContent = prev;
				if ( card ) {
					card.classList.remove( 'is-pending' );
				}
				window.alert( message );
			} );
		} );
	} );

	/* -------------------------------------------------------------------------
	   Upgrade modal — open / close / copy / use-key
	   ------------------------------------------------------------------------- */

	var modal = document.getElementById( 'nera-aio-upgrade-modal' );

	/**
	 * Open the modal.
	 */
	function openModal() {
		if ( ! modal ) {
			return;
		}
		modal.style.display = 'block';
		document.body.style.overflow = 'hidden';

		// Move focus to the close button for accessibility.
		var closeBtn = document.getElementById( 'nera-aio-modal-close' );
		if ( closeBtn ) {
			closeBtn.focus();
		}
	}

	/**
	 * Close the modal.
	 */
	function closeModal() {
		if ( ! modal ) {
			return;
		}
		modal.style.display         = 'none';
		document.body.style.overflow = '';
	}

	// Open via "Upgrade" buttons on the Modules screen.
	var upgradeBtns = document.querySelectorAll( '.nera-aio-upgrade-btn' );
	upgradeBtns.forEach( function ( btn ) {
		btn.addEventListener( 'click', openModal );
	} );

	// Open via explicit "Get Demo PRO Key" button (Licensing screen).
	var openUpgradeBtn = document.getElementById( 'nera-aio-open-upgrade' );
	if ( openUpgradeBtn ) {
		openUpgradeBtn.addEventListener( 'click', openModal );
	}

	// Close via X button.
	var closeBtn = document.getElementById( 'nera-aio-modal-close' );
	if ( closeBtn ) {
		closeBtn.addEventListener( 'click', closeModal );
	}

	// Close via overlay click.
	var overlay = document.getElementById( 'nera-aio-modal-overlay' );
	if ( overlay ) {
		overlay.addEventListener( 'click', closeModal );
	}

	// Close on Escape key.
	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && modal && 'none' !== modal.style.display ) {
			closeModal();
		}
	} );

	// Copy demo key.
	var copyBtn = document.getElementById( 'nera-aio-copy-demo-key' );
	if ( copyBtn ) {
		copyBtn.addEventListener( 'click', function () {
			var cfg     = window.neraAioAdmin || {};
			var demoKey = cfg.demoKey || '';
			var keyEl   = document.getElementById( 'nera-aio-demo-key-display' );
			var keyText = keyEl ? keyEl.textContent.trim() : demoKey;

			if ( ! keyText ) {
				return;
			}

			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( keyText ).then( function () {
					copyBtn.textContent = 'Copied!';
					setTimeout( function () {
						copyBtn.textContent = 'Copy';
					}, 2000 );
				} ).catch( function () {
					fallbackCopy( keyText );
				} );
			} else {
				fallbackCopy( keyText );
			}
		} );
	}

	/**
	 * Fallback clipboard copy using a temp textarea.
	 *
	 * @param {string} text
	 */
	function fallbackCopy( text ) {
		var ta       = document.createElement( 'textarea' );
		ta.value     = text;
		ta.style.position = 'fixed';
		ta.style.opacity  = '0';
		document.body.appendChild( ta );
		ta.focus();
		ta.select();
		try {
			document.execCommand( 'copy' );
			if ( copyBtn ) {
				copyBtn.textContent = 'Copied!';
				setTimeout( function () {
					copyBtn.textContent = 'Copy';
				}, 2000 );
			}
		} catch ( err ) {
			// Silently fail.
		}
		document.body.removeChild( ta );
	}

	// "Use this key" — fill the license key input if present on this page
	// (only meaningful on Licensing screen where the input exists).
	var useKeyBtn = document.getElementById( 'nera-aio-use-demo-key' );
	if ( useKeyBtn ) {
		// Only wire up if there's a key input on this page (Licensing screen).
		var keyInput = document.getElementById( 'nera_aio_key' );
		if ( keyInput ) {
			useKeyBtn.addEventListener( 'click', function () {
				var cfg     = window.neraAioAdmin || {};
				var demoKey = cfg.demoKey || '';
				var keyEl   = document.getElementById( 'nera-aio-demo-key-display' );
				var keyText = keyEl ? keyEl.textContent.trim() : demoKey;

				if ( keyText ) {
					keyInput.value = keyText;
					closeModal();
					keyInput.focus();
				}
			} );
		}
	}

} )();
