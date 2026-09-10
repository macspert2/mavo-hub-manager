/* global mavoHubManager */
( function () {
	'use strict';

	var cfg = window.mavoHubManager || {};
	var i18n = cfg.i18n || {};

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) { node.className = className; }
		if ( undefined !== text && null !== text ) { node.textContent = text; }
		return node;
	}

	/** Meta line under a result title: type · status · language · hub type. */
	function metaLine( row ) {
		var bits = [ '#' + row.id, row.post_type, row.status ];
		if ( row.lang ) { bits.push( row.lang ); }
		if ( row.hub_type ) {
			bits.push( 'geo' === row.hub_type ? 'geo hub' : 'theme hub' );
		}
		if ( row.current_hub ) {
			bits.push( ( row.conflict ? i18n.conflict + ': ' : '' ) + row.current_hub.title );
		}
		return bits.join( ' · ' );
	}

	function targets( context ) {
		var prefix = 'mark' === context ? 'mark' : 'child';
		return {
			form: document.querySelector( '[data-mhm-' + prefix + '-form]' ),
			id: document.querySelector( '[data-mhm-' + prefix + '-id]' ),
			label: document.querySelector( '[data-mhm-' + prefix + '-label]' ),
			cancel: document.querySelector( '[data-mhm-' + prefix + '-cancel]' )
		};
	}

	function wireSearch( box ) {
		var context = box.getAttribute( 'data-mhm-search' );
		var hub = box.getAttribute( 'data-mhm-hub' ) || '';
		var input = box.querySelector( '[data-mhm-input]' );
		var results = box.querySelector( '[data-mhm-results]' );
		var timer = null;
		var sequence = 0;

		if ( ! input || ! results ) { return; }

		function choose( row ) {
			var t = targets( context );
			if ( ! t.form || ! t.id || ! t.label ) { return; }

			t.id.value = row.id;
			t.label.textContent = row.title + ' (#' + row.id + ')';
			t.form.hidden = false;
			results.innerHTML = '';
			input.value = '';
			t.form.scrollIntoView( { block: 'nearest' } );
		}

		function render( rows ) {
			results.innerHTML = '';

			if ( ! rows.length ) {
				results.appendChild( el( 'p', 'mhm-muted', i18n.noResults ) );
				return;
			}

			var list = el( 'ul', 'mhm-search-list' );

			rows.forEach( function ( row ) {
				var item = el( 'li' );
				var button = el( 'button', 'button button-small', 'mark' === context ? i18n.select : i18n.assign );

				button.type = 'button';
				button.addEventListener( 'click', function () { choose( row ); } );

				item.appendChild( button );
				item.appendChild( el( 'span', 'mhm-search-title', ' ' + row.title ) );
				item.appendChild( el( 'span', 'mhm-muted', ' — ' + metaLine( row ) ) );
				list.appendChild( item );
			} );

			results.appendChild( list );
		}

		function search() {
			var term = input.value.trim();
			var mine = ++sequence;

			if ( term.length < 2 ) {
				results.innerHTML = '';
				return;
			}

			results.innerHTML = '';
			results.appendChild( el( 'p', 'mhm-muted', i18n.searching ) );

			var body = new URLSearchParams();
			body.set( 'action', cfg.action );
			body.set( 'nonce', cfg.nonce );
			body.set( 'q', term );
			body.set( 'context', context );
			body.set( 'hub', hub );

			window.fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			} ).then( function ( response ) {
				return response.json();
			} ).then( function ( payload ) {
				if ( mine !== sequence ) { return; } // A newer keystroke already won.
				if ( ! payload || ! payload.success ) {
					render( [] );
					return;
				}
				render( payload.data.results || [] );
			} ).catch( function () {
				if ( mine !== sequence ) { return; }
				results.innerHTML = '';
				results.appendChild( el( 'p', 'mhm-error-text', i18n.error ) );
			} );
		}

		input.addEventListener( 'input', function () {
			window.clearTimeout( timer );
			timer = window.setTimeout( search, 300 );
		} );

		input.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key ) {
				event.preventDefault(); // Never submit the surrounding form by accident.
				window.clearTimeout( timer );
				search();
			}
		} );

		var t = targets( context );
		if ( t.cancel && t.form ) {
			t.cancel.addEventListener( 'click', function () {
				t.form.hidden = true;
				if ( t.id ) { t.id.value = ''; }
			} );
		}
	}

	function wireConfirmations() {
		document.addEventListener( 'click', function ( event ) {
			var button = event.target.closest ? event.target.closest( '[data-mhm-confirm]' ) : null;
			if ( ! button ) { return; }

			if ( ! window.confirm( button.getAttribute( 'data-mhm-confirm' ) ) ) {
				event.preventDefault();
				event.stopPropagation();
			}
		} );
	}

	function wireCheckAll() {
		document.querySelectorAll( '[data-mhm-check-all]' ).forEach( function ( master ) {
			var table = master.closest( 'table' );
			if ( ! table ) { return; }

			master.addEventListener( 'change', function () {
				table.querySelectorAll( 'input[name="targets[]"]' ).forEach( function ( box ) {
					box.checked = master.checked;
				} );
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '[data-mhm-search]' ).forEach( wireSearch );
		wireConfirmations();
		wireCheckAll();
	} );
}() );
