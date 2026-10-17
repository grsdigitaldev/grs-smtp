/* GRS SMTP - admin */
( function () {
	'use strict';

	var cfg = window.grsSmtp || {};
	var i18n = cfg.i18n || {};

	function $( sel, ctx ) {
		return ( ctx || document ).querySelector( sel );
	}
	function $$( sel, ctx ) {
		return Array.prototype.slice.call( ( ctx || document ).querySelectorAll( sel ) );
	}
	function setVisible( rows, visible ) {
		rows.forEach( function ( row ) {
			row.style.display = visible ? '' : 'none';
		} );
	}

	/* Impostazioni */
	var provider = $( '#grs-smtp-provider' );
	var host = $( '#grs-smtp-host' );
	var port = $( '#grs-smtp-port' );
	var note = $( '#grs-smtp-provider-note' );
	var encRadios = $$( 'input[name="grs_smtp_settings[encryption]"]' );
	var auth = $( '#grs-smtp-auth' );

	function currentEnc() {
		var checked = encRadios.filter( function ( r ) {
			return r.checked;
		} )[ 0 ];
		return checked ? checked.value : 'none';
	}

	function toggleRows() {
		setVisible( $$( '.grs-smtp__row-autotls' ), currentEnc() === 'none' );
		if ( auth ) {
			setVisible( $$( '.grs-smtp__row-auth' ), auth.checked );
		}
	}

	if ( provider ) {
		provider.addEventListener( 'change', function () {
			var data = ( cfg.providers || {} )[ provider.value ];
			if ( ! data ) {
				return;
			}
			if ( note ) {
				note.textContent = data.note || '';
			}
			if ( provider.value === 'custom' ) {
				return;
			}
			if ( host && ! host.disabled ) {
				host.value = data.host;
			}
			if ( port && ! port.disabled ) {
				port.value = data.port;
			}
			encRadios.forEach( function ( r ) {
				if ( ! r.disabled ) {
					r.checked = r.value === data.enc;
				}
			} );
			if ( auth && ! auth.disabled ) {
				auth.checked = true;
			}
			toggleRows();
		} );
	}

	encRadios.forEach( function ( r ) {
		r.addEventListener( 'change', function () {
			// Porta suggerita solo se l'utente non ne ha messa una personalizzata.
			if ( port && ! port.disabled && [ '25', '465', '587', '' ].indexOf( String( port.value ) ) !== -1 ) {
				if ( r.value === 'ssl' ) {
					port.value = 465;
				} else if ( r.value === 'tls' ) {
					port.value = 587;
				} else {
					port.value = 25;
				}
			}
			toggleRows();
		} );
	} );

	if ( auth ) {
		auth.addEventListener( 'change', toggleRows );
	}
	toggleRows();

	/* Conferme */
	$$( '[data-grs-confirm]' ).forEach( function ( el ) {
		var evt = el.tagName === 'FORM' ? 'submit' : 'click';
		el.addEventListener( evt, function ( e ) {
			// eslint-disable-next-line no-alert
			var msg = el.getAttribute( 'data-grs-confirm' ) === 'all' ? i18n.confirm : i18n.confirmDelete;
			if ( ! window.confirm( msg || 'OK?' ) ) {
				e.preventDefault();
			}
		} );
	} );

	/* Email di test */
	var form = $( '#grs-smtp-test-form' );
	var result = $( '#grs-smtp-test-result' );

	function el( tag, cls, text ) {
		var node = document.createElement( tag );
		if ( cls ) {
			node.className = cls;
		}
		if ( text ) {
			node.textContent = text;
		}
		return node;
	}

	function render( ok, data ) {
		result.innerHTML = '';
		var box = el( 'div', 'notice inline ' + ( ok ? 'notice-success' : 'notice-error' ) );
		box.appendChild( el( 'p', '', data.message || i18n.error ) );
		result.appendChild( box );

		if ( data.hint ) {
			var hint = el( 'div', 'grs-smtp__hint' );
			hint.appendChild( el( 'strong', '', i18n.hint ) );
			hint.appendChild( el( 'p', '', data.hint ) );
			result.appendChild( hint );
		}

		if ( data.debug && data.debug.length ) {
			var details = el( 'details', 'grs-smtp__debug' );
			if ( ! ok ) {
				details.open = true;
			}
			details.appendChild( el( 'summary', '', i18n.debug ) );
			details.appendChild( el( 'pre', 'grs-smtp__pre', data.debug.join( '\n' ) ) );
			result.appendChild( details );
		}
	}

	if ( form && result ) {
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var button = $( 'button', form );
			var to = $( '#grs-smtp-test-to' ).value;

			button.disabled = true;
			result.innerHTML = '';
			result.appendChild( el( 'p', 'grs-smtp__sending', i18n.sending ) );

			var body = new FormData();
			body.append( 'action', 'grs_smtp_send_test' );
			body.append( 'nonce', cfg.nonce );
			body.append( 'to', to );

			fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( json ) {
					render( !! json.success, json.data || {} );
				} )
				.catch( function () {
					render( false, { message: i18n.error } );
				} )
				.then( function () {
					button.disabled = false;
				} );
		} );
	}
} )();
