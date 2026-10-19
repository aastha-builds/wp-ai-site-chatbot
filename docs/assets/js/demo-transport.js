/**
 * SiteSage Chat: browser-only transport for the GitHub Pages preview.
 *
 * The real plugin embeds the question, ranks your content by cosine
 * similarity and asks OpenAI or Claude. This preview has no server, so it
 * matches the question against the 30 saved answers in data/answers.json
 * with simple keyword overlap. Leads stay in this browser (localStorage).
 */
( function ( root ) {
	'use strict';

	var STOPWORDS = (
		'a about after again all also am an and any are as at be because been before being both but by can could did do ' +
		'does doing done each else etc every few for from get got had has have having he her here hi him his how i if in ' +
		'into is it its just let like me might more most my need no not of off on once one only or other our ours out ' +
		'over own please same she should so some such than that the their them then there these they this those through ' +
		'to too under until up us very via want was way we well were what when which while who whom why will with would ' +
		'yes you your yours ve ll re s t d m dental dentistry clinic practice tell know much many offer really bad good ' +
		'help take see make give keep come go usually often able thing'
	).split( ' ' );

	var SYNONYMS = {
		cost: 'price', fee: 'price', charge: 'price', pric: 'price', expensive: 'price', cheap: 'price', afford: 'price',
		kid: 'child', children: 'child', toddler: 'child', son: 'child', daughter: 'child', pediatric: 'child', baby: 'child',
		toothache: 'emergency', urgent: 'emergency', pain: 'emergency',
		nervous: 'anxious', anxiety: 'anxious', scar: 'anxious', scared: 'anxious', afraid: 'anxious', fear: 'anxious',
		weekend: 'saturday', hour: 'hours', open: 'hours', clos: 'hours', close: 'hours', time: 'hours',
		park: 'parking', whiten: 'whitening', white: 'whitening', whiter: 'whitening', bleach: 'whitening', stain: 'whitening',
		aligner: 'aligners', invisalign: 'aligners', brace: 'aligners', straighten: 'aligners', crooked: 'aligners',
		insur: 'insurance', insurer: 'insurance', ppo: 'insurance', coverage: 'insurance', cover: 'insurance',
		book: 'appointment', schedul: 'appointment', schedule: 'appointment', reschedul: 'cancel', reschedule: 'cancel',
		pay: 'payment', financ: 'payment', finance: 'payment', installment: 'payment', monthly: 'payment',
		clean: 'cleaning', hygien: 'cleaning', hygienist: 'cleaning', checkup: 'cleaning',
		radiograph: 'xray', spanish: 'language', speak: 'language', paperwork: 'form',
		locat: 'location', address: 'location', direction: 'location', where: 'location',
		teeth: 'tooth', doctor: 'dentist', dr: 'dentist', dentists: 'dentist'
	};

	var TARGETS = {};
	Object.keys( SYNONYMS ).forEach( function ( key ) {
		TARGETS[ SYNONYMS[ key ] ] = true;
	} );

	var STOP = {};
	STOPWORDS.forEach( function ( word ) {
		STOP[ word ] = true;
	} );

	function stem( word ) {
		var len = word.length;
		if ( len > 4 && /ies$/.test( word ) ) {
			word = word.slice( 0, -3 ) + 'y';
		} else if ( len > 3 && /s$/.test( word ) && ! /(ss|us)$/.test( word ) ) {
			word = word.slice( 0, -1 );
		}
		len = word.length;
		if ( len > 5 && /ing$/.test( word ) ) {
			word = word.slice( 0, -3 );
		} else if ( len > 4 && /ed$/.test( word ) ) {
			word = word.slice( 0, -2 );
		} else if ( len > 4 && /e$/.test( word ) ) {
			word = word.slice( 0, -1 );
		}
		return word;
	}

	function canonical( word ) {
		if ( TARGETS[ word ] ) {
			return word;
		}
		if ( SYNONYMS[ word ] ) {
			return SYNONYMS[ word ];
		}
		var stemmed = stem( word );
		return SYNONYMS[ stemmed ] || stemmed;
	}

	function tokens( text ) {
		var clean = String( text || '' )
			.toLowerCase()
			.replace( /x-?rays?/g, 'xray' )
			.replace( /check-?ups?/g, 'checkup' )
			.replace( /[^a-z0-9]+/g, ' ' )
			.trim();
		var out = [];
		clean.split( /\s+/ ).forEach( function ( word ) {
			if ( ! word || STOP[ word ] || /^\d+$/.test( word ) ) {
				return;
			}
			var token = canonical( word );
			if ( token && ! STOP[ token ] && out.indexOf( token ) === -1 ) {
				out.push( token );
			}
		} );
		return out;
	}

	function words( text ) {
		return String( text || '' ).toLowerCase().split( /[^a-z0-9]+/ ).filter( function ( word ) {
			return word && ! STOP[ word ];
		} );
	}

	/**
	 * Score = share of the visitor's words found in the entry (question +
	 * keywords), plus small bonuses for overlap with the saved question and
	 * for exact keyword hits.
	 */
	function score( queryTokens, entry, queryWords ) {
		if ( ! entry._q ) {
			entry._q = tokens( entry.question );
			entry._all = tokens( entry.question + ' ' + ( entry.keywords || [] ).join( ' ' ) );
			entry._kw = words( ( entry.keywords || [] ).join( ' ' ) );
		}
		if ( ! queryTokens.length || ! entry._all.length ) {
			return 0;
		}
		var inAll = 0;
		var inQuestion = 0;
		queryTokens.forEach( function ( token ) {
			if ( entry._all.indexOf( token ) !== -1 ) {
				inAll += 1;
			}
			if ( entry._q.indexOf( token ) !== -1 ) {
				inQuestion += 1;
			}
		} );
		var exact = ( queryWords || [] ).filter( function ( word ) {
			return entry._kw.indexOf( word ) !== -1;
		} ).length;
		var coverage = inAll / queryTokens.length;
		var dice = ( 2 * inQuestion ) / ( queryTokens.length + entry._q.length );
		return 0.7 * coverage + 0.3 * dice + 0.25 * ( queryWords.length ? exact / queryWords.length : 0 );
	}

	var THRESHOLD = 0.5;

	function match( question, data ) {
		var query = tokens( question );
		var queryWords = words( question );
		var best = null;
		var bestScore = 0;
		( data.answers || [] ).forEach( function ( entry ) {
			var s = score( query, entry, queryWords );
			if ( s > bestScore ) {
				bestScore = s;
				best = entry;
			}
		} );
		if ( ! best || bestScore < THRESHOLD || best.source === 'none' ) {
			return { entry: null, score: bestScore };
		}
		return { entry: best, score: bestScore };
	}

	function reply( question, data ) {
		var hit = match( question, data );
		if ( ! hit.entry ) {
			return { answer: data.fallback, sources: [], fallback: true };
		}
		var e = hit.entry;
		return {
			answer: e.answer + ' Read more: [' + e.source + '](' + e.source_url + ')',
			sources: [ { title: e.source, url: e.source_url } ],
			fallback: false,
		};
	}

	function wait( value ) {
		return new Promise( function ( resolve ) {
			setTimeout( function () {
				resolve( value );
			}, 700 + Math.floor( Math.random() * 500 ) );
		} );
	}

	var api = { tokens: tokens, match: match, reply: reply, THRESHOLD: THRESHOLD };

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = api;
	}

	if ( root && root.document ) {
		var script = root.document.currentScript;
		var dataUrl = ( script && script.getAttribute( 'data-answers' ) ) || '../data/answers.json';
		var dataPromise = null;

		var load = function () {
			if ( ! dataPromise ) {
				dataPromise = root.fetch( dataUrl ).then( function ( response ) {
					if ( ! response.ok ) {
						throw new Error( 'Could not load the sample answers.' );
					}
					return response.json();
				} );
			}
			return dataPromise;
		};

		root.SiteSageDemoTransport = {
			ask: function ( body ) {
				return load().then( function ( data ) {
					return wait( reply( body.question, data ) );
				} );
			},
			lead: function ( body ) {
				try {
					var leads = JSON.parse( root.localStorage.getItem( 'sitesage-demo-leads' ) || '[]' );
					leads.push( { name: body.name, phone: body.phone, email: body.email, question: body.question, at: new Date().toISOString() } );
					root.localStorage.setItem( 'sitesage-demo-leads', JSON.stringify( leads ) );
				} catch ( e ) {
					// Storage blocked: nothing to keep, nothing sent either way.
				}
				return wait( {
					saved: true,
					message: 'Thanks! In this preview your details stay in your browser only. Nothing was sent. In the real plugin they go to the Leads screen and your Google Sheet.',
				} );
			},
			handoff: function ( handoff ) {
				return 'In the real plugin this button opens ' + ( handoff.type === 'email' ? 'an email' : 'WhatsApp' ) + ' with the number or address set in the settings. This preview does not open it.';
			},
		};
	}
} )( typeof window !== 'undefined' ? window : null );
