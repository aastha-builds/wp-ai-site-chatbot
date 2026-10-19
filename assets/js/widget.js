/**
 * SiteSage Chat widget.
 *
 * Plain JavaScript, no build step. Reads window.SiteSageConfig.
 * transport "rest" calls the WordPress REST API; transport "demo" uses
 * window.SiteSageDemoTransport (the GitHub Pages preview).
 */
( function () {
	'use strict';

	var config = window.SiteSageConfig;
	if ( ! config || document.getElementById( 'sitesage-chat' ) ) {
		return;
	}

	var t = config.i18n || {};
	var STORAGE_KEY = 'sitesage-chat-state';
	var state = loadState();
	var busy = false;
	var els = {};

	/* ---------- helpers ---------- */

	function loadState() {
		try {
			var saved = JSON.parse( window.sessionStorage.getItem( STORAGE_KEY ) || '{}' );
			return {
				questions: saved.questions || 0,
				lead: saved.lead || '',
			};
		} catch ( e ) {
			return { questions: 0, lead: '' };
		}
	}

	function saveState() {
		try {
			window.sessionStorage.setItem( STORAGE_KEY, JSON.stringify( state ) );
		} catch ( e ) {
			// Storage can be blocked; the chat still works.
		}
	}

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( key ) {
			if ( 'text' === key ) {
				node.textContent = attrs[ key ];
			} else if ( 'className' === key ) {
				node.className = attrs[ key ];
			} else if ( key.indexOf( 'on' ) === 0 ) {
				node.addEventListener( key.slice( 2 ), attrs[ key ] );
			} else if ( attrs[ key ] !== null && attrs[ key ] !== undefined && attrs[ key ] !== false ) {
				node.setAttribute( key, attrs[ key ] );
			}
		} );
		( children || [] ).forEach( function ( child ) {
			if ( child ) {
				node.appendChild( 'string' === typeof child ? document.createTextNode( child ) : child );
			}
		} );
		return node;
	}

	function icon( path ) {
		var svg = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
		svg.setAttribute( 'viewBox', '0 0 24 24' );
		svg.setAttribute( 'aria-hidden', 'true' );
		svg.setAttribute( 'focusable', 'false' );
		var p = document.createElementNS( 'http://www.w3.org/2000/svg', 'path' );
		p.setAttribute( 'd', path );
		svg.appendChild( p );
		return svg;
	}

	var ICONS = {
		chat: 'M4 4h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H8l-4 4V6a2 2 0 0 1 2-2z',
		close: 'M6.4 5 12 10.6 17.6 5 19 6.4 13.4 12 19 17.6 17.6 19 12 13.4 6.4 19 5 17.6 10.6 12 5 6.4z',
		send: 'M3 20.5 21 12 3 3.5v6.6l12 1.9-12 1.9z',
		person: 'M12 12a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9zm0 2c-4 0-8 2-8 5v2h16v-2c0-3-4-5-8-5z',
	};

	function linkAttrs( url, text ) {
		var attrs = { href: url, text: text };
		if ( url.charAt( 0 ) !== '#' ) {
			attrs.target = '_blank';
			attrs.rel = 'noopener';
		}
		return attrs;
	}

	function isSafeUrl( url ) {
		return /^(https?:\/\/|\/|#|mailto:|tel:)/i.test( url ) || /^[\w./-]+(\.pdf|\.html)?(#[\w-]+)?$/i.test( url );
	}

	/**
	 * Render answer text: Markdown links become anchors, newlines become breaks.
	 * Everything else is inserted as text, never as HTML.
	 */
	function renderText( text ) {
		var frag = document.createDocumentFragment();
		var pattern = /\[([^\]]+)\]\(([^)\s]+)\)/g;
		var last = 0;
		var match;

		function addPlain( chunk ) {
			chunk.split( '\n' ).forEach( function ( line, i ) {
				if ( i > 0 ) {
					frag.appendChild( document.createElement( 'br' ) );
				}
				if ( line ) {
					frag.appendChild( document.createTextNode( line ) );
				}
			} );
		}

		while ( ( match = pattern.exec( text ) ) !== null ) {
			addPlain( text.slice( last, match.index ) );
			if ( isSafeUrl( match[ 2 ] ) ) {
				frag.appendChild( el( 'a', linkAttrs( match[ 2 ], match[ 1 ] ) ) );
			} else {
				addPlain( match[ 1 ] );
			}
			last = pattern.lastIndex;
		}
		addPlain( text.slice( last ) );
		return frag;
	}

	function contrastColor( hex ) {
		var m = /^#?([0-9a-f]{6})$/i.exec( hex || '' );
		if ( ! m ) {
			return '#ffffff';
		}
		var n = parseInt( m[ 1 ], 16 );
		var lum = ( 0.299 * ( ( n >> 16 ) & 255 ) + 0.587 * ( ( n >> 8 ) & 255 ) + 0.114 * ( n & 255 ) ) / 255;
		return lum > 0.6 ? '#111111' : '#ffffff';
	}

	function hasIntent( text ) {
		var lower = String( text ).toLowerCase();
		return ( config.intentWords || [] ).some( function ( word ) {
			var escaped = word.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
			return new RegExp( '\\b' + escaped + '[a-z]*\\b' ).test( lower );
		} );
	}

	/* ---------- transport ---------- */

	function post( path, body ) {
		if ( 'demo' === config.transport && window.SiteSageDemoTransport ) {
			return window.SiteSageDemoTransport[ path ]( body );
		}
		return window.fetch( config.restUrl + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-SiteSage-Nonce': config.nonce,
				'X-WP-Nonce': config.wpNonce,
			},
			body: JSON.stringify( body ),
		} ).then( function ( response ) {
			return response.json().then( function ( data ) {
				if ( ! response.ok ) {
					var error = new Error( ( data && data.message ) || t.error );
					error.status = response.status;
					throw error;
				}
				return data;
			} );
		} );
	}

	/* ---------- UI ---------- */

	function build() {
		var root = el( 'div', { id: 'sitesage-chat', className: 'ssc' } );
		root.style.setProperty( '--ssc-brand', config.brandColor || '#0e7c86' );
		root.style.setProperty( '--ssc-on-brand', contrastColor( config.brandColor ) );

		els.launcher = el(
			'button',
			{
				type: 'button',
				className: 'ssc-launcher',
				'aria-expanded': 'false',
				'aria-controls': 'ssc-panel',
				'aria-label': t.open,
				onclick: toggle,
			},
			[ icon( ICONS.chat ) ]
		);

		els.log = el( 'div', { className: 'ssc-log', role: 'log', 'aria-live': 'polite', 'aria-relevant': 'additions' } );

		els.input = el( 'textarea', {
			id: 'ssc-input',
			className: 'ssc-input',
			rows: '1',
			maxlength: String( config.maxLength || 500 ),
			placeholder: t.placeholder,
			onkeydown: function ( event ) {
				if ( 'Enter' === event.key && ! event.shiftKey ) {
					event.preventDefault();
					submit();
				}
			},
		} );

		els.send = el( 'button', { type: 'submit', className: 'ssc-send', 'aria-label': t.send }, [ icon( ICONS.send ) ] );

		var form = el(
			'form',
			{
				className: 'ssc-form',
				onsubmit: function ( event ) {
					event.preventDefault();
					submit();
				},
			},
			[ el( 'label', { for: 'ssc-input', className: 'ssc-sr', text: t.placeholder } ), els.input, els.send ]
		);

		var handoff = null;
		if ( config.handoff && config.handoff.url ) {
			handoff = el(
				'a',
				{
					className: 'ssc-handoff',
					href: config.handoff.url,
					target: '_blank',
					rel: 'noopener',
					onclick: function ( event ) {
						if ( 'demo' === config.transport && window.SiteSageDemoTransport && window.SiteSageDemoTransport.handoff ) {
							event.preventDefault();
							addBot( window.SiteSageDemoTransport.handoff( config.handoff ) );
						}
					},
				},
				[ icon( ICONS.person ), config.handoff.label || 'Talk to a person' ]
			);
		}

		var subtitle = config.demoMode ? el( 'span', { className: 'ssc-badge', text: t.demoBadge } ) : el( 'span', { className: 'ssc-subtitle', text: t.poweredBy } );

		els.panel = el(
			'div',
			{ id: 'ssc-panel', className: 'ssc-panel', role: 'dialog', 'aria-label': config.botName, hidden: 'hidden' },
			[
				el( 'div', { className: 'ssc-header' }, [
					el( 'span', { className: 'ssc-avatar', 'aria-hidden': 'true' }, [ icon( ICONS.chat ) ] ),
					el( 'div', { className: 'ssc-title' }, [ el( 'strong', { text: config.botName } ), subtitle ] ),
					el( 'button', { type: 'button', className: 'ssc-close', 'aria-label': t.close, onclick: close }, [ icon( ICONS.close ) ] ),
				] ),
				els.log,
				el( 'div', { className: 'ssc-footer' }, [ handoff, form ] ),
			]
		);

		els.panel.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				close();
			}
		} );

		root.appendChild( els.panel );
		root.appendChild( els.launcher );
		document.body.appendChild( root );
		els.root = root;
	}

	function addMessage( who, content ) {
		var bubble = el( 'div', { className: 'ssc-msg ssc-msg--' + who } );
		bubble.appendChild( content );
		els.log.appendChild( bubble );
		scroll();
		return bubble;
	}

	function addBot( text, sources ) {
		var bubble = addMessage( 'bot', renderText( text ) );
		if ( sources && sources.length ) {
			var list = el( 'ul', { className: 'ssc-sources' } );
			sources.forEach( function ( source ) {
				if ( isSafeUrl( source.url ) ) {
					list.appendChild( el( 'li', {}, [ el( 'a', linkAttrs( source.url, source.title ) ) ] ) );
				}
			} );
			bubble.appendChild( el( 'div', { className: 'ssc-sources-wrap' }, [ el( 'span', { className: 'ssc-sources-label', text: t.sources } ), list ] ) );
		}
		return bubble;
	}

	function addSuggestions() {
		if ( ! config.suggestions || ! config.suggestions.length ) {
			return;
		}
		var wrap = el( 'div', { className: 'ssc-suggestions' } );
		config.suggestions.forEach( function ( text ) {
			wrap.appendChild(
				el( 'button', {
					type: 'button',
					className: 'ssc-chip',
					text: text,
					onclick: function () {
						wrap.remove();
						ask( text );
					},
				} )
			);
		} );
		els.log.appendChild( wrap );
		els.suggestions = wrap;
	}

	function showTyping() {
		var dots = el( 'span', { className: 'ssc-dots', 'aria-hidden': 'true' }, [ el( 'span' ), el( 'span' ), el( 'span' ) ] );
		els.typing = addMessage( 'bot', el( 'span', {}, [ dots, el( 'span', { className: 'ssc-sr', text: t.typing } ) ] ) );
		els.typing.classList.add( 'ssc-typing' );
	}

	function hideTyping() {
		if ( els.typing ) {
			els.typing.remove();
			els.typing = null;
		}
	}

	function scroll() {
		els.log.scrollTop = els.log.scrollHeight;
	}

	function setBusy( value ) {
		busy = value;
		els.send.disabled = value;
		els.input.setAttribute( 'aria-busy', value ? 'true' : 'false' );
	}

	function submit() {
		var text = els.input.value.trim();
		if ( ! text || busy ) {
			return;
		}
		if ( text.length > ( config.maxLength || 500 ) ) {
			addBot( t.tooLong );
			return;
		}
		els.input.value = '';
		ask( text );
	}

	function ask( question ) {
		if ( els.suggestions ) {
			els.suggestions.remove();
			els.suggestions = null;
		}
		addMessage( 'user', document.createTextNode( question ) );
		state.questions += 1;
		state.lastQuestion = question;
		saveState();
		setBusy( true );
		showTyping();

		var leadShown = false;
		return Promise.resolve( post( 'ask', { question: question, page_url: window.location.href } ) )
			.then( function ( data ) {
				hideTyping();
				addBot( data.answer, data.fallback ? [] : data.sources );
				leadShown = maybeAskForLead( data.lead_intent || hasIntent( question ) );
			} )
			.catch( function ( error ) {
				hideTyping();
				addBot( ( error && error.message ) || t.error );
			} )
			.then( function () {
				setBusy( false );
				// Keyboard users land in the lead form when it appears, otherwise back in the input.
				if ( ! leadShown ) {
					els.input.focus();
				}
			} );
	}

	function maybeAskForLead( intent ) {
		if ( state.lead ) {
			return false;
		}
		var threshold = parseInt( config.leadAfterQuestions, 10 ) || 0;
		if ( intent || ( threshold > 0 && state.questions >= threshold ) ) {
			showLeadForm();
			return true;
		}
		return false;
	}

	function field( name, type, label, autocomplete ) {
		var id = 'ssc-lead-' + name;
		return el( 'p', { className: 'ssc-field' }, [
			el( 'label', { for: id, text: label } ),
			el( 'input', { id: id, name: name, type: type, required: 'required', autocomplete: autocomplete } ),
		] );
	}

	function showLeadForm() {
		state.lead = 'shown';
		saveState();

		var error = el( 'p', { className: 'ssc-error', role: 'alert' } );
		var honeypot = el( 'p', { className: 'ssc-hp', 'aria-hidden': 'true' }, [
			el( 'label', { for: 'ssc-lead-website', text: 'Website' } ),
			el( 'input', { id: 'ssc-lead-website', name: 'website', type: 'text', tabindex: '-1', autocomplete: 'off' } ),
		] );
		var submitBtn = el( 'button', { type: 'submit', className: 'ssc-btn', text: t.submit } );
		var skipBtn = el( 'button', {
			type: 'button',
			className: 'ssc-btn ssc-btn--ghost',
			text: t.noThanks,
			onclick: function () {
				state.lead = 'dismissed';
				saveState();
				card.remove();
				els.input.focus();
			},
		} );

		var form = el(
			'form',
			{ className: 'ssc-lead-form', novalidate: 'novalidate' },
			[
				el( 'strong', { className: 'ssc-lead-title', text: t.leadTitle } ),
				el( 'span', { className: 'ssc-lead-intro', text: t.leadIntro } ),
				field( 'name', 'text', t.name, 'name' ),
				field( 'phone', 'tel', t.phone, 'tel' ),
				field( 'email', 'email', t.email, 'email' ),
				honeypot,
				error,
				el( 'div', { className: 'ssc-lead-actions' }, [ submitBtn, skipBtn ] ),
				el( 'small', { className: 'ssc-privacy', text: t.leadPrivacy } ),
			]
		);

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			var data = {
				name: form.elements.name.value.trim(),
				phone: form.elements.phone.value.trim(),
				email: form.elements.email.value.trim(),
				website: form.elements.website.value,
				question: state.lastQuestion || '',
				page_url: window.location.href,
			};
			if ( ! data.name || ! data.phone || ! data.email ) {
				error.textContent = t.requiredField;
				return;
			}
			submitBtn.disabled = true;
			error.textContent = '';
			Promise.resolve( post( 'lead', data ) )
				.then( function ( response ) {
					state.lead = 'done';
					saveState();
					card.remove();
					addBot( ( response && response.message ) || t.leadThanks );
					els.input.focus();
				} )
				.catch( function ( err ) {
					submitBtn.disabled = false;
					error.textContent = ( err && err.message ) || t.error;
				} );
		} );

		var card = addMessage( 'bot', form );
		card.classList.add( 'ssc-msg--form' );
		form.elements.name.focus( { preventScroll: true } );
		scroll();
	}

	function open() {
		if ( ! els.panel.hidden ) {
			return;
		}
		els.panel.hidden = false;
		els.root.classList.add( 'is-open' );
		els.launcher.setAttribute( 'aria-expanded', 'true' );
		els.launcher.setAttribute( 'aria-label', t.close );
		els.launcher.replaceChildren( icon( ICONS.close ) );
		if ( ! els.log.childNodes.length ) {
			addBot( config.greeting );
			addSuggestions();
		}
		els.input.focus();
	}

	function close() {
		if ( els.panel.hidden ) {
			return;
		}
		els.panel.hidden = true;
		els.root.classList.remove( 'is-open' );
		els.launcher.setAttribute( 'aria-expanded', 'false' );
		els.launcher.setAttribute( 'aria-label', t.open );
		els.launcher.replaceChildren( icon( ICONS.chat ) );
		els.launcher.focus();
	}

	function toggle() {
		if ( els.panel.hidden ) {
			open();
		} else {
			close();
		}
	}

	function init() {
		build();
		window.SiteSageWidget = { open: open, close: close, ask: ask };
		var params = new window.URLSearchParams( window.location.search );
		if ( config.autoOpen || '1' === params.get( 'sitesage_open' ) ) {
			open();
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
