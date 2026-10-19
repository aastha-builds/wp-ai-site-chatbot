/**
 * Tests for the GitHub Pages keyword matcher (node --test).
 */
const test = require( 'node:test' );
const assert = require( 'node:assert' );
const path = require( 'node:path' );
const fs = require( 'node:fs' );

const root = path.join( __dirname, '..', '..' );
const transport = require( path.join( root, 'docs/assets/js/demo-transport.js' ) );
const data = JSON.parse( fs.readFileSync( path.join( root, 'docs/data/answers.json' ), 'utf8' ) );

function sourceFor( question ) {
	const result = transport.reply( question, data );
	return result.fallback ? 'none' : result.sources[ 0 ].title;
}

test( 'every saved question answers with its own source', () => {
	for ( const entry of data.answers ) {
		assert.strictEqual( sourceFor( entry.question ), entry.source, entry.question );
	}
} );

test( 'paraphrases find the right answer', () => {
	const cases = {
		'How much is teeth whitening?': 'Pricing',
		'what time do you close on friday': 'Contact',
		'is there parking': 'Contact',
		'do you treat kids?': 'Do you see children?',
		'toothache help!': 'What should I do in a dental emergency?',
		'can I pay monthly': 'Do you offer payment plans?',
		'what is your address': 'Contact',
		'do you do invisalign': 'Do you offer clear aligners instead of braces?',
		'Do you take my insurance?': 'Insurance',
		'What should I bring to my first visit?': 'New Patient Guide',
		'I want to book an appointment': 'Are you accepting new patients?',
		'are xrays safe for me': 'Are dental x-rays safe?',
	};
	for ( const [ question, expected ] of Object.entries( cases ) ) {
		assert.strictEqual( sourceFor( question ), expected, question );
	}
} );

test( 'out-of-scope questions get the handoff reply', () => {
	for ( const question of [ 'Tell me a joke', 'What is the capital of France?', 'Do you sell car insurance quotes for trucks?', '' ] ) {
		const result = transport.reply( question, data );
		assert.strictEqual( result.fallback, true, question );
		assert.strictEqual( result.answer, "I don't know that one, let me connect you with our team" );
	}
} );

test( 'answers link the source page', () => {
	const result = transport.reply( 'How much is a dental implant?', data );
	assert.match( result.answer, /Read more: \[Pricing\]\(#pricing\)$/ );
	assert.deepStrictEqual( result.sources, [ { title: 'Pricing', url: '#pricing' } ] );
} );

test( 'tokenizer mirrors the plugin rules', () => {
	assert.deepStrictEqual( transport.tokens( 'What is the pricing for kids X-rays?' ), [ 'price', 'child', 'xray' ] );
} );
