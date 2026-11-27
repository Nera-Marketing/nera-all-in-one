/**
 * Nera All In One — Admin JS.
 *
 * No build step. Plain ES5-compatible vanilla JS, loaded after DOM ready
 * via wp_enqueue_script( ..., true ) (footer).
 *
 * Globals provided by wp_localize_script('nera-aio-admin', 'neraAioAdmin', {...}):
 *   neraAioAdmin.ajaxurl      — admin-ajax.php URL
 *   neraAioAdmin.toggleNonce  — nonce for nera_aio_toggle
 *   neraAioAdmin.installNonce — nonce for nera_aio_install
 *   neraAioAdmin.detailsNonce — nonce for nera_aio_details
 *   neraAioAdmin.removeNonce  — nonce for nera_aio_remove
 *   neraAioAdmin.repoAddNonce / repoDelistNonce — custom catalog entries
 *
 * Mutating nonces are empty strings for non-manager viewers; every mutating
 * handler no-ops when its nonce is missing.
 */
( function () {
	'use strict';

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

} )();

/* =========================================================================
   Plugin details modal — open card → fetch info + GitHub README → remove
   ========================================================================= */
( function () {
	'use strict';

	var modal = document.getElementById( 'nera-aio-details-modal' );
	if ( ! modal ) {
		return;
	}

	var head    = document.getElementById( 'nera-aio-details-head' );
	var body    = document.getElementById( 'nera-aio-details-body' );
	var actions = document.getElementById( 'nera-aio-details-actions' );

	/**
	 * Open the details modal.
	 */
	function openModal() {
		modal.style.display          = 'block';
		document.body.style.overflow = 'hidden';
		var closeBtn = document.getElementById( 'nera-aio-details-close' );
		if ( closeBtn ) {
			closeBtn.focus();
		}
	}

	/**
	 * Close the details modal.
	 */
	function closeModal() {
		modal.style.display          = 'none';
		document.body.style.overflow = '';
	}

	/**
	 * Create an element with an optional class and text.
	 *
	 * @param {string}  tag
	 * @param {?string} className
	 * @param {?string} text
	 * @return {HTMLElement}
	 */
	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( null != text ) {
			node.textContent = text;
		}
		return node;
	}

	/**
	 * Build the modal head (icon, name, badges, version/deps/repo) from the
	 * details payload. Built with DOM nodes (no innerHTML) since these values
	 * are not pre-escaped.
	 *
	 * @param {Object} d
	 */
	function renderHead( d ) {
		head.textContent = '';

		var titleRow = el( 'div', 'nera-aio-details__title-row' );
		if ( d.icon ) {
			titleRow.appendChild( el( 'span', 'nera-aio-details__icon', d.icon ) );
		}
		var h2 = el( 'h2', null, d.name );
		h2.id = 'nera-aio-details-title';
		titleRow.appendChild( h2 );
		head.appendChild( titleRow );

		var badges = el( 'div', 'nera-aio-details__badges' );
		if ( d.status ) {
			badges.appendChild( el( 'span', 'nera-aio-badge nera-aio-badge--' + ( d.status_key || '' ), d.status ) );
		}
		head.appendChild( badges );

		var meta = el( 'p', 'nera-aio-details__meta' );
		var bits = [];
		if ( d.version ) {
			bits.push( 'Version ' + d.version );
		}
		if ( d.deps && d.deps.length ) {
			bits.push( 'Requires: ' + d.deps.join( ', ' ) );
		}
		if ( bits.length ) {
			meta.appendChild( document.createTextNode( bits.join( '  ·  ' ) ) );
		}
		if ( d.repo_url ) {
			if ( meta.childNodes.length ) {
				meta.appendChild( document.createTextNode( '  ·  ' ) );
			}
			var a    = el( 'a', null, d.repo || 'GitHub' );
			a.href   = d.repo_url;
			a.target = '_blank';
			a.rel    = 'noopener noreferrer';
			meta.appendChild( a );
		}
		if ( meta.childNodes.length ) {
			head.appendChild( meta );
		}
	}

	/**
	 * Fill the modal body with the rendered README, or a fallback.
	 *
	 * @param {Object} d
	 */
	function renderBody( d ) {
		if ( d.description_html ) {
			// Pre-sanitized server-side with wp_kses_post().
			body.innerHTML = d.description_html;
		} else {
			body.textContent = '';
			body.appendChild( el( 'p', 'nera-aio-details__empty', 'No extended description published yet.' ) );
			if ( d.tagline ) {
				body.appendChild( el( 'p', null, d.tagline ) );
			}
		}
	}

	/**
	 * Build the action row: Remove (when allowed) + Edit page (when the plugin
	 * has an editor and is active) + Close.
	 *
	 * @param {Object} d
	 */
	function renderActions( d ) {
		actions.textContent = '';

		if ( d.can_remove ) {
			var removeBtn  = el( 'button', 'button nera-aio-remove-btn', 'Remove plugin' );
			removeBtn.type = 'button';
			removeBtn.setAttribute( 'data-id', d.id );
			removeBtn.addEventListener( 'click', function () {
				doRemove( d, removeBtn );
			} );
			actions.appendChild( removeBtn );
		}

		if ( d.edit_url ) {
			var editLink  = el( 'a', 'button button-primary', 'Edit page' );
			editLink.href = d.edit_url;
			actions.appendChild( editLink );
		}

		var closeBtn  = el( 'button', 'button button-secondary', 'Close' );
		closeBtn.type = 'button';
		closeBtn.addEventListener( 'click', closeModal );
		actions.appendChild( closeBtn );
	}

	/**
	 * Full delete a plugin, behind an explicit confirm().
	 *
	 * @param {Object}      d
	 * @param {HTMLElement} btn
	 */
	function doRemove( d, btn ) {
		var cfg = window.neraAioAdmin || {};
		if ( ! cfg.ajaxurl || ! cfg.removeNonce ) {
			return;
		}

		var ok = window.confirm(
			'Permanently delete "' + d.name + '"?\n\n'
			+ 'This deactivates the plugin, deletes its files from disk, and runs its '
			+ 'uninstall routine (removing its stored data). This cannot be undone.'
		);
		if ( ! ok ) {
			return;
		}

		btn.disabled    = true;
		btn.textContent = 'Removing…';

		var rq = new URLSearchParams();
		rq.append( 'action', 'nera_aio_remove' );
		rq.append( '_ajax_nonce', cfg.removeNonce );
		rq.append( 'id', d.id );

		fetch( cfg.ajaxurl, { method: 'POST', credentials: 'same-origin', body: rq } )
			.then( function ( res ) {
				return res.json();
			} )
			.then( function ( json ) {
				if ( json && json.success ) {
					window.location.reload();
					return;
				}
				btn.disabled    = false;
				btn.textContent = 'Remove plugin';
				window.alert( json && json.data && json.data.message ? json.data.message : 'Removal failed.' );
			} )
			.catch( function () {
				btn.disabled    = false;
				btn.textContent = 'Remove plugin';
				window.alert( 'Network error.' );
			} );
	}

	/**
	 * Fetch + show details for a catalog id.
	 *
	 * @param {string} id
	 */
	function openDetails( id ) {
		var cfg = window.neraAioAdmin || {};
		if ( ! id || ! cfg.ajaxurl || ! cfg.detailsNonce ) {
			return;
		}

		head.textContent    = '';
		actions.textContent = '';
		body.textContent    = 'Loading…';
		openModal();

		var rq = new URLSearchParams();
		rq.append( 'action', 'nera_aio_details' );
		rq.append( '_ajax_nonce', cfg.detailsNonce );
		rq.append( 'id', id );

		fetch( cfg.ajaxurl, { method: 'POST', credentials: 'same-origin', body: rq } )
			.then( function ( res ) {
				return res.json();
			} )
			.then( function ( json ) {
				if ( json && json.success && json.data ) {
					renderHead( json.data );
					renderBody( json.data );
					renderActions( json.data );
					return;
				}
				body.textContent = ( json && json.data && json.data.message ) ? json.data.message : 'Failed to load details.';
			} )
			.catch( function () {
				body.textContent = 'Network error.';
			} );
	}

	// Trigger: explicit Details (ⓘ) buttons.
	document.querySelectorAll( '.nera-aio-card__info' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function ( e ) {
			e.stopPropagation();
			openDetails( btn.getAttribute( 'data-id' ) );
		} );
	} );

	// Trigger: whole-card click, except on the card's own interactive controls.
	document.querySelectorAll( '.nera-aio-plugin-card' ).forEach( function ( card ) {
		card.addEventListener( 'click', function ( e ) {
			if ( e.target.closest( 'button, input, a, label, select, .nera-aio-switch' ) ) {
				return;
			}
			openDetails( card.getAttribute( 'data-id' ) );
		} );
	} );

	// Close: X, overlay, Escape.
	var closeX = document.getElementById( 'nera-aio-details-close' );
	if ( closeX ) {
		closeX.addEventListener( 'click', closeModal );
	}
	var overlay = document.getElementById( 'nera-aio-details-overlay' );
	if ( overlay ) {
		overlay.addEventListener( 'click', closeModal );
	}
	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && 'none' !== modal.style.display ) {
			closeModal();
		}
	} );

} )();

/* =========================================================================
   Manage table — select-all, bulk actions, per-row remove
   ========================================================================= */
( function () {
	'use strict';

	var table = document.getElementById( 'nera-aio-manage-table' );
	if ( ! table ) {
		return;
	}

	var checkAll  = document.getElementById( 'nera-aio-check-all' );
	var actionSel = document.getElementById( 'nera-aio-bulk-action' );
	var applyBtn  = document.getElementById( 'nera-aio-bulk-apply' );
	var statusEl  = document.getElementById( 'nera-aio-bulk-status' );

	/**
	 * Show a temporary inline notice next to the bulk bar.
	 *
	 * @param {string} message
	 * @param {string} type    'success' | 'error'
	 */
	function showStatus( message, type ) {
		if ( ! statusEl ) {
			return;
		}
		statusEl.textContent   = message;
		statusEl.className     = 'nera-aio-inline-notice is-' + type;
		statusEl.style.display = 'inline';
	}

	/* -------------------------------------------------------------------------
	   Selection
	   ------------------------------------------------------------------------- */

	function rowChecks() {
		return Array.prototype.slice.call( table.querySelectorAll( '.nera-aio-row-check' ) );
	}

	function selectedRows() {
		return Array.prototype.slice.call( table.querySelectorAll( 'tbody tr' ) ).filter( function ( tr ) {
			var cb = tr.querySelector( '.nera-aio-row-check' );
			return cb && cb.checked;
		} );
	}

	function syncCheckAll() {
		if ( ! checkAll ) {
			return;
		}
		var checks  = rowChecks();
		var checked = checks.filter( function ( cb ) {
			return cb.checked;
		} ).length;

		checkAll.checked       = checks.length > 0 && checked === checks.length;
		checkAll.indeterminate = checked > 0 && checked < checks.length;
	}

	if ( checkAll ) {
		checkAll.addEventListener( 'change', function () {
			rowChecks().forEach( function ( cb ) {
				cb.checked = checkAll.checked;
			} );
			checkAll.indeterminate = false;
		} );
	}

	rowChecks().forEach( function ( cb ) {
		cb.addEventListener( 'change', syncCheckAll );
	} );

	/* -------------------------------------------------------------------------
	   Bulk runner
	   ------------------------------------------------------------------------- */

	/**
	 * Whether the action makes sense for the row's current state. Select-all
	 * may select anything; non-applicable rows are skipped, never errors.
	 *
	 * @param {string}      action
	 * @param {HTMLElement} tr
	 * @return {boolean}
	 */
	function isApplicable( action, tr ) {
		var installed = '1' === tr.getAttribute( 'data-installed' );
		var active    = '1' === tr.getAttribute( 'data-active' );
		var hasRepo   = '1' === tr.getAttribute( 'data-has-repo' );
		var hasUpdate = '1' === tr.getAttribute( 'data-has-update' );
		var backing   = tr.getAttribute( 'data-backing' );
		var status    = tr.getAttribute( 'data-status' );

		switch ( action ) {
			case 'activate':
				return installed && ! active;
			case 'deactivate':
				return active;
			case 'update':
				return hasUpdate;
			case 'install':
				return ! installed && hasRepo && 'module' !== backing && 'locked' !== status;
			case 'uninstall':
				return installed && 'module' !== backing;
			case 'delist':
				return ! installed;
			default:
				return false;
		}
	}

	/**
	 * Map a bulk action + plugin id to the existing single-plugin AJAX request.
	 *
	 * @param {string} action
	 * @param {string} id
	 * @return {?URLSearchParams} Null when the required nonce is missing.
	 */
	function requestFor( action, id ) {
		var cfg  = window.neraAioAdmin || {};
		var body = new URLSearchParams();

		if ( 'activate' === action || 'deactivate' === action ) {
			if ( ! cfg.toggleNonce ) {
				return null;
			}
			body.append( 'action', 'nera_aio_toggle' );
			body.append( '_ajax_nonce', cfg.toggleNonce );
			body.append( 'id', id );
			body.append( 'enabled', 'activate' === action ? 1 : 0 );
			return body;
		}

		if ( 'install' === action || 'update' === action ) {
			if ( ! cfg.installNonce ) {
				return null;
			}
			body.append( 'action', 'nera_aio_install' );
			body.append( '_ajax_nonce', cfg.installNonce );
			body.append( 'id', id ); // No 'ref' — installs the latest release.
			return body;
		}

		if ( 'uninstall' === action ) {
			if ( ! cfg.removeNonce ) {
				return null;
			}
			body.append( 'action', 'nera_aio_remove' );
			body.append( '_ajax_nonce', cfg.removeNonce );
			body.append( 'id', id );
			return body;
		}

		if ( 'delist' === action ) {
			if ( ! cfg.repoDelistNonce ) {
				return null;
			}
			body.append( 'action', 'nera_aio_repo_delist' );
			body.append( '_ajax_nonce', cfg.repoDelistNonce );
			body.append( 'id', id );
			return body;
		}

		return null;
	}

	var VERBS = {
		activate:   'Activating',
		deactivate: 'Deactivating',
		update:     'Updating',
		install:    'Installing',
		uninstall:  'Removing',
		delist:     'Removing from list',
	};

	function runBulk() {
		var cfg    = window.neraAioAdmin || {};
		var action = actionSel ? actionSel.value : '';

		if ( ! cfg.ajaxurl || ! action ) {
			showStatus( 'Choose a bulk action first.', 'error' );
			return;
		}

		var selected = selectedRows();
		if ( ! selected.length ) {
			showStatus( 'Select at least one plugin.', 'error' );
			return;
		}

		var applicable = selected.filter( function ( tr ) {
			return isApplicable( action, tr );
		} );
		var skipped = selected.length - applicable.length;

		if ( ! applicable.length ) {
			showStatus( 'Nothing to do — selected plugins are not applicable for this action.', 'error' );
			return;
		}

		if ( 'uninstall' === action ) {
			var names = applicable.map( function ( tr ) {
				return '  ' + tr.getAttribute( 'data-name' );
			} );
			var ok = window.confirm(
				'Permanently delete ' + applicable.length + ' plugin(s):\n' + names.join( '\n' ) +
				'\n\nThis deactivates each plugin, deletes its files, and runs its uninstall routine. This cannot be undone.'
			);
			if ( ! ok ) {
				return;
			}
		}

		if ( 'delist' === action ) {
			var delistNames = applicable.map( function ( tr ) {
				return '  ' + tr.getAttribute( 'data-name' );
			} );
			var okDelist = window.confirm(
				'Remove ' + applicable.length + ' plugin(s) from the catalog?\n' + delistNames.join( '\n' ) +
				'\n\nNo files are deleted — add them back by pasting their GitHub URL.'
			);
			if ( ! okDelist ) {
				return;
			}
		}

		// Lock the UI for the duration of the run.
		table.classList.add( 'is-bulk-running' );
		if ( actionSel ) {
			actionSel.disabled = true;
		}
		if ( applyBtn ) {
			applyBtn.disabled = true;
		}
		if ( checkAll ) {
			checkAll.disabled = true;
		}
		rowChecks().forEach( function ( cb ) {
			cb.disabled = true;
		} );

		var results = [];

		// Strictly sequential: plugin (de)activation mutates the shared
		// active_plugins option — parallel requests would race.
		function next( i ) {
			if ( i >= applicable.length ) {
				finish();
				return;
			}

			var tr   = applicable[ i ];
			var id   = tr.getAttribute( 'data-id' );
			var name = tr.getAttribute( 'data-name' ) || id;
			var body = requestFor( action, id );

			if ( ! body ) {
				results.push( { name: name, ok: false, message: 'Not permitted.' } );
				next( i + 1 );
				return;
			}

			tr.classList.add( 'is-pending' );
			showStatus( ( i + 1 ) + '/' + applicable.length + ' — ' + VERBS[ action ] + ' ' + name + '…', 'success' );

			fetch( cfg.ajaxurl, {
				method:      'POST',
				credentials: 'same-origin',
				body:        body,
			} )
				.then( function ( res ) {
					return res.json();
				} )
				.then( function ( json ) {
					var ok = !! ( json && json.success );
					results.push( {
						name:    name,
						ok:      ok,
						message: ok ? '' : ( json && json.data && json.data.message ? json.data.message : 'Request failed.' ),
					} );
					tr.classList.remove( 'is-pending' );
					if ( ! ok ) {
						tr.classList.add( 'is-error' );
					}
					next( i + 1 );
				} )
				.catch( function () {
					results.push( { name: name, ok: false, message: 'Network error.' } );
					tr.classList.remove( 'is-pending' );
					tr.classList.add( 'is-error' );
					next( i + 1 );
				} );
		}

		function finish() {
			var failed  = results.filter( function ( r ) {
				return ! r.ok;
			} );
			var summary = 'Done: ' + ( results.length - failed.length ) + ' succeeded';
			if ( failed.length ) {
				summary += ', ' + failed.length + ' failed';
			}
			if ( skipped ) {
				summary += ', ' + skipped + ' skipped (not applicable)';
			}

			if ( failed.length ) {
				// Blocking alert so the messages are read before the reload.
				window.alert( summary + '\n\n' + failed.map( function ( r ) {
					return r.name + ' — ' + r.message;
				} ).join( '\n' ) );
			}

			// Single reload, always — the table re-renders server truth,
			// including partially completed runs.
			window.location.reload();
		}

		next( 0 );
	}

	if ( applyBtn ) {
		applyBtn.addEventListener( 'click', runBulk );
	}

	/* -------------------------------------------------------------------------
	   Add plugin by GitHub repo URL
	   ------------------------------------------------------------------------- */

	var repoInput = document.getElementById( 'nera-aio-repo-url' );
	var repoBtn   = document.getElementById( 'nera-aio-repo-add' );
	var repoStat  = document.getElementById( 'nera-aio-repo-status' );

	function showRepoStatus( message, type ) {
		if ( ! repoStat ) {
			return;
		}
		repoStat.textContent   = message;
		repoStat.className     = 'nera-aio-inline-notice is-' + type;
		repoStat.style.display = 'inline';
	}

	function submitRepo() {
		var cfg = window.neraAioAdmin || {};
		var url = repoInput ? repoInput.value.trim() : '';

		if ( ! cfg.ajaxurl || ! cfg.repoAddNonce ) {
			return;
		}
		if ( ! url ) {
			showRepoStatus( 'Paste a GitHub repository URL first.', 'error' );
			return;
		}

		repoBtn.disabled   = true;
		repoInput.disabled = true;
		showRepoStatus( 'Validating & installing…', 'success' );

		var body = new URLSearchParams();
		body.append( 'action', 'nera_aio_repo_add' );
		body.append( '_ajax_nonce', cfg.repoAddNonce );
		body.append( 'url', url );

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
				repoBtn.disabled   = false;
				repoInput.disabled = false;
				showRepoStatus( json && json.data && json.data.message ? json.data.message : 'Request failed.', 'error' );
			} )
			.catch( function () {
				repoBtn.disabled   = false;
				repoInput.disabled = false;
				showRepoStatus( 'Network error.', 'error' );
			} );
	}

	if ( repoBtn ) {
		repoBtn.addEventListener( 'click', submitRepo );
	}
	if ( repoInput ) {
		repoInput.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key ) {
				e.preventDefault();
				submitRepo();
			}
		} );
	}

	/* -------------------------------------------------------------------------
	   Remove custom entry from the catalog (no files touched)
	   ------------------------------------------------------------------------- */

	table.querySelectorAll( '.nera-aio-row-delist' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var cfg  = window.neraAioAdmin || {};
			var id   = btn.getAttribute( 'data-id' );
			var name = btn.getAttribute( 'data-name' ) || id;

			if ( ! cfg.ajaxurl || ! cfg.repoDelistNonce ) {
				return;
			}
			if ( ! window.confirm( 'Remove ' + name + ' from the catalog? No files are deleted — you can add it back later by pasting its GitHub URL.' ) ) {
				return;
			}

			btn.disabled = true;

			var body = new URLSearchParams();
			body.append( 'action', 'nera_aio_repo_delist' );
			body.append( '_ajax_nonce', cfg.repoDelistNonce );
			body.append( 'id', id );

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
					btn.disabled = false;
					window.alert( json && json.data && json.data.message ? json.data.message : 'Request failed.' );
				} )
				.catch( function () {
					btn.disabled = false;
					window.alert( 'Network error.' );
				} );
		} );
	} );

	/* -------------------------------------------------------------------------
	   Per-row Remove
	   ------------------------------------------------------------------------- */

	table.querySelectorAll( '.nera-aio-row-remove' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var cfg  = window.neraAioAdmin || {};
			var id   = btn.getAttribute( 'data-id' );
			var name = btn.getAttribute( 'data-name' ) || id;

			if ( ! cfg.ajaxurl || ! cfg.removeNonce ) {
				return;
			}

			var ok = window.confirm(
				'Permanently delete ' + name + '?\n\nThis deactivates the plugin, deletes its files, and runs its uninstall routine. This cannot be undone.'
			);
			if ( ! ok ) {
				return;
			}

			btn.disabled = true;

			var body = new URLSearchParams();
			body.append( 'action', 'nera_aio_remove' );
			body.append( '_ajax_nonce', cfg.removeNonce );
			body.append( 'id', id );

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
					btn.disabled = false;
					window.alert( json && json.data && json.data.message ? json.data.message : 'Remove failed.' );
				} )
				.catch( function () {
					btn.disabled = false;
					window.alert( 'Network error.' );
				} );
		} );
	} );

} )();

/* ------------------------------------------------------------------ *
 * "Check for updates" — flush the release caches, then reload so the
 * table re-fetches fresh GitHub release data.
 * ------------------------------------------------------------------ */
( function () {
	var btn = document.getElementById( 'nera-aio-refresh-updates' );
	if ( ! btn ) {
		return;
	}

	var status = document.getElementById( 'nera-aio-refresh-status' );

	btn.addEventListener( 'click', function () {
		var cfg = window.neraAioAdmin || {};
		if ( ! cfg.ajaxurl || ! cfg.refreshNonce ) {
			return;
		}

		var original   = btn.textContent;
		btn.disabled    = true;
		btn.textContent = 'Checking…';
		if ( status ) {
			status.style.display = 'none';
			status.textContent   = '';
		}

		var rq = new URLSearchParams();
		rq.append( 'action', 'nera_aio_refresh_updates' );
		rq.append( '_ajax_nonce', cfg.refreshNonce );

		fetch( cfg.ajaxurl, { method: 'POST', credentials: 'same-origin', body: rq } )
			.then( function ( res ) {
				return res.json();
			} )
			.then( function ( json ) {
				if ( json && json.success ) {
					window.location.reload();
					return;
				}
				btn.disabled    = false;
				btn.textContent = original;
				window.alert( json && json.data && json.data.message ? json.data.message : 'Update check failed.' );
			} )
			.catch( function () {
				btn.disabled    = false;
				btn.textContent = original;
				window.alert( 'Network error.' );
			} );
	} );
} )();
