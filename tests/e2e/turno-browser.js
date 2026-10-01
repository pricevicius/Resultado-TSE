// Teste no navegador (Chromium headless via CDP, sem dependências) da virada automática para o 2º turno.
// Pré-requisito: o container do site de pé e o Chromium do Playwright (ou CHROME=/caminho/chrome).
//   node tests/e2e/turno-browser.js
// Cria a massa com tests/e2e/turno-fixture.php, abre /ae-e2e-turno/, avança o 2º turno no banco e confere que os widgets
// trocam sozinhos (selo, finalistas, seletor), sem recarregar a página. Remove a massa no fim.
const { spawn, execFileSync } = require( 'child_process' );
const fs = require( 'fs' ); const os = require( 'os' ); const path = require( 'path' );

const BASE = process.env.SITE_URL || 'http://localhost';
const CONTAINER = process.env.TSE_APURACAO_CONTAINER || 'revistaforum-app';
const FIXTURE = 'wp-content/plugins/tse-apuracao/tests/e2e/turno-fixture.php';
const fixture = ( mode ) => execFileSync( 'docker', [ 'exec', '-u', 'www-data', CONTAINER, 'wp', '--path=/var/www/html', 'eval-file', FIXTURE, mode ], { encoding: 'utf8' } );
const sleep = ( ms ) => new Promise( r => setTimeout( r, ms ) );
const chromePath = () => {
	if ( process.env.CHROME ) return process.env.CHROME;
	const root = path.join( os.homedir(), '.cache/ms-playwright' );
	const dir = fs.readdirSync( root ).filter( d => d.startsWith( 'chromium-' ) ).sort().pop();
	return path.join( root, dir, 'chrome-linux64/chrome' );
};

let failures = 0;
const check = ( name, ok, extra ) => { console.log( ( ok ? 'ok   ' : 'FAIL ' ) + name + ( ok ? '' : ' — ' + extra ) ); if ( ! ok ) failures++; };

( async () => {
	let chrome;
	try {
		fixture( 'setup' );
		const port = 9400 + Math.floor( Math.random() * 400 );
		chrome = spawn( chromePath(), [ '--headless=new', '--no-sandbox', '--disable-gpu', `--remote-debugging-port=${ port }`, `--user-data-dir=${ fs.mkdtempSync( path.join( os.tmpdir(), 'ae-e2e-' ) ) }`, '--window-size=1280,900', 'about:blank' ], { stdio: 'ignore' } );
		let target;
		for ( let i = 0; i < 40 && ! target; i++ ) { await sleep( 250 ); try { target = ( await ( await fetch( `http://127.0.0.1:${ port }/json` ) ).json() ).find( t => 'page' === t.type ); } catch ( e ) { /* ainda subindo */ } }
		if ( ! target ) throw new Error( 'Chromium não subiu' );
		const ws = new WebSocket( target.webSocketDebuggerUrl );
		await new Promise( r => ws.addEventListener( 'open', r ) );
		let id = 0; const pending = new Map();
		const errors = [];
		ws.addEventListener( 'message', ( m ) => {
			const msg = JSON.parse( m.data );
			if ( msg.id && pending.has( msg.id ) ) { pending.get( msg.id )( msg.result ); pending.delete( msg.id ); }
			if ( 'Runtime.exceptionThrown' === msg.method ) errors.push( msg.params.exceptionDetails.exception?.description || msg.params.exceptionDetails.text );
		} );
		const send = ( method, params = {} ) => new Promise( r => { const n = ++id; pending.set( n, r ); ws.send( JSON.stringify( { id: n, method, params } ) ); } );
		const ev = async ( expr ) => ( await send( 'Runtime.evaluate', { expression: expr, returnByValue: true } ) ).result.value;
		await send( 'Runtime.enable' ); await send( 'Page.enable' ); await send( 'Network.enable' ); await send( 'Network.setCacheDisabled', { cacheDisabled: true } );

		const requests = [];
		ws.addEventListener( 'message', ( m ) => { const msg = JSON.parse( m.data ); if ( 'Network.requestWillBeSent' === msg.method && msg.params.request.url.includes( '/wp-json/' ) ) requests.push( { url: msg.params.request.url, t: msg.params.timestamp } ); } );
		await send( 'Page.navigate', { url: `${ BASE }/ae-e2e-turno/` } );
		await sleep( 2500 );
		await ev( 'window.__marca = 1' ); // some se a página recarregar

		const state = () => ev( `JSON.stringify({
			marca: window.__marca,
			widgets: [...document.querySelectorAll('.tse-apuracao-widget')].map(w => ({ turnoAtual: w.dataset.turnoAtual, selo: w.querySelector('.tse-turno-selo').hidden ? '' : w.querySelector('.tse-turno-selo').textContent, nomes: [...w.querySelectorAll('.tse-cand-nome')].map(e => e.textContent), seletor: w.querySelector('.tse-turnos').hidden ? [] : [...w.querySelectorAll('.tse-turno-opcao')].map(a => a.textContent) })),
			cards: [...document.querySelectorAll('.tse-card')].map(c => ({ oculto: c.hidden, nome: (c.querySelector('.tse-card-nome') || {}).textContent })),
			resumo: [...document.querySelectorAll('.tse-resumo-item')].map(i => ({ selo: i.querySelector('.tse-turno-selo').hidden ? '' : i.querySelector('.tse-turno-selo').textContent, visiveis: [...i.querySelectorAll('.tse-resumo-cand')].filter(c => !c.hidden).length })),
			faixa: { selo: document.querySelector('.ae-strip-turno').hidden ? '' : document.querySelector('.ae-strip-turno').textContent, nomes: [...document.querySelectorAll('.ae-strip .ae-candidate-item')].filter(i => !i.hidden).map(i => i.dataset.id) }
		})` ).then( JSON.parse );

		let s = await state();
		check( 'antes: os 2 blocos iguais estão no 1º turno, com 5 candidatos e sem selo nem seletor', s.widgets.length === 2 && s.widgets.every( w => '1' === w.turnoAtual && '' === w.selo && 5 === w.nomes.length && 0 === w.seletor.length ), JSON.stringify( s.widgets ) );
		check( 'antes: o resumo e a faixa estão no 1º turno', s.resumo.every( r => '' === r.selo ) && '' === s.faixa.selo && 5 === s.faixa.nomes.length, JSON.stringify( [ s.resumo, s.faixa ] ) );

		fixture( 'advance' );
		let ok = false;
		for ( let i = 0; i < 60 && ! ok; i++ ) { await sleep( 2000 ); s = await state(); ok = s.widgets.every( w => '2' === w.turnoAtual ) && '2º turno' === s.faixa.selo; }
		check( 'depois: os blocos trocam sozinhos para o 2º turno (sem recarregar a página)', ok && 1 === s.marca, JSON.stringify( s ) );
		check( 'depois: os 2 blocos mostram só os 2 finalistas, com o selo "2º turno"', s.widgets.every( w => 2 === w.nomes.length && '2º turno' === w.selo ), JSON.stringify( s.widgets ) );
		check( 'depois: o seletor de turno aparece com 1º e 2º turno', s.widgets.every( w => '1º turno,2º turno' === w.seletor.join( ',' ) ), JSON.stringify( s.widgets.map( w => w.seletor ) ) );
		check( 'depois: a faixa mostra só os finalistas com o selo "2º turno"', 2 === s.faixa.nomes.length && '2º turno' === s.faixa.selo, JSON.stringify( s.faixa ) );
		check( 'depois: o resumo mostra o selo e esconde a linha que sobra', s.resumo[ 0 ].selo === '2º turno' && 2 >= s.resumo[ 0 ].visiveis, JSON.stringify( s.resumo ) );
		check( 'depois: o card de uma posição que deixou de existir some (limite=3, só 2 finalistas)', s.cards.some( c => c.oculto ), JSON.stringify( s.cards ) );

		// Seletor: clicar em "1º turno" num bloco volta só aquele bloco, sem recarregar.
		await ev( `document.querySelectorAll('.tse-apuracao-widget')[0].querySelector('[data-turno-sel="1"]').click()` );
		await sleep( 1500 ); s = await state();
		check( 'seletor: clicar em 1º turno troca só aquele bloco, sem recarregar', '1' === s.widgets[ 0 ].turnoAtual && 5 === s.widgets[ 0 ].nomes.length && '2' === s.widgets[ 1 ].turnoAtual && 1 === s.marca, JSON.stringify( s.widgets ) );

		// Dois blocos iguais pedem a mesma URL: a REST recebe uma requisição, não duas por ciclo.
		const legacy = requests.filter( r => r.url.includes( 'tse/v1/resultado' ) && r.url.includes( 'turno=0' ) && r.url.includes( 'limite=10' ) );
		const simultaneos = legacy.filter( ( r, i ) => legacy.some( ( o, j ) => j !== i && o.url === r.url && Math.abs( o.t - r.t ) < 1 ) ).length;
		check( 'blocos iguais compartilham a requisição (a mesma URL não vai duas vezes no mesmo ciclo)', legacy.length > 0 && 0 === simultaneos, `${ legacy.length } pedidos, ${ simultaneos } simultâneos repetidos` );
		check( 'nenhum erro de JavaScript na página', 0 === errors.length, errors.join( ' | ' ) );

		// ?ae_turno=1 abre o bloco no 1º turno (link do seletor, sem JS e ao compartilhar).
		await send( 'Page.navigate', { url: `${ BASE }/ae-e2e-turno/?ae_turno=1` } );
		await sleep( 2000 ); s = await state();
		check( '?ae_turno=1 abre os blocos no 1º turno (e o seletor continua oferecendo os dois)', s.widgets.every( w => '1' === w.turnoAtual && 2 === w.seletor.length ), JSON.stringify( s.widgets ) );
		ws.close();
	} catch ( e ) {
		console.log( 'FAIL erro no teste — ' + e.message ); failures++;
	} finally {
		if ( chrome ) chrome.kill();
		try { fixture( 'cleanup' ); } catch ( e ) { console.log( 'AVISO: limpeza falhou: ' + e.message ); }
	}
	console.log( failures ? `${ failures } falha(s)` : 'Tudo certo.' );
	process.exit( failures ? 1 : 0 );
} )();
