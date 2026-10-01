/* Faixa de candidatos: botões anterior/próximo (só aparecem se houver o que rolar; sem JS a lista continua rolável) e, quando a faixa
 * mostra votos de uma disputa, atualização pela REST local (data-live), sem recarregar a página. */
(function () {
	'use strict';
	function init( strip ) {
		var list = strip.querySelector( '.ae-candidate-list' );
		var nav = strip.querySelector( '.ae-strip-nav' );
		var prev = strip.querySelector( '.ae-strip-prev' );
		var next = strip.querySelector( '.ae-strip-next' );
		if ( ! list || ! nav || ! prev || ! next ) { return; }
		function update() {
			var max = list.scrollWidth - list.clientWidth;
			nav.hidden = max <= 1;
			prev.disabled = list.scrollLeft <= 1;
			next.disabled = list.scrollLeft >= max - 1;
		}
		function go( dir ) { list.scrollBy( { left: dir * list.clientWidth * 0.8, behavior: 'smooth' } ); }
		prev.addEventListener( 'click', function () { go( -1 ); } );
		next.addEventListener( 'click', function () { go( 1 ); } );
		list.addEventListener( 'scroll', update, { passive: true } );
		window.addEventListener( 'resize', update );
		update();
	}
	function fmt( n, d ) { return Number( n ).toLocaleString( 'pt-BR', { minimumFractionDigits: d || 0, maximumFractionDigits: d || 0 } ); }
	function live( strip ) {
		var url = strip.getAttribute( 'data-live' );
		var list = strip.querySelector( '.ae-candidate-list' );
		if ( ! url || ! list || ! window.fetch ) { return; }
		function refresh() {
			fetch( url ).then( function ( r ) { return r.ok ? r.json() : Promise.reject(); } ).then( function ( data ) {
				var byId = {};
				( data.candidates || [] ).forEach( function ( c ) { byId[ c.external_candidate_id ] = c; } );
				Array.prototype.forEach.call( list.querySelectorAll( '.ae-candidate-item' ), function ( li ) {
					var c = byId[ li.getAttribute( 'data-id' ) ];
					var el = li.querySelector( '.ae-candidate-votes' );
					if ( ! c || ! el ) { return; }
					var votes = parseInt( c.votes, 10 ) || 0;
					var showVotes = el.hasAttribute( 'data-votos' );
					var showPct = el.hasAttribute( 'data-pct' );
					var parts = [];
					if ( showPct ) { parts.push( fmt( parseFloat( c.percentage ) || 0, 2 ) + '%' ); }
					if ( showVotes ) { parts.push( fmt( votes ) + ( 1 === votes ? ' voto' : ' votos' ) ); }
					if ( Number( c.elected ) ) { parts.push( 'Eleito' ); }
					el.textContent = parts.join( ' · ' );
				} );
				if ( strip.hasAttribute( 'data-reordenar' ) ) {
					( data.candidates || [] ).forEach( function ( c ) {
						var li = list.querySelector( '.ae-candidate-item[data-id="' + c.external_candidate_id + '"]' );
						if ( li ) { list.appendChild( li ); }
					} );
				}
			} ).catch( function () { /* mantém o que está na tela */ } );
		}
		setInterval( refresh, ( parseInt( strip.getAttribute( 'data-intervalo' ), 10 ) || 60 ) * 1000 );
		document.addEventListener( 'visibilitychange', function () { if ( 'visible' === document.visibilityState ) { refresh(); } } );
	}
	function ready() { Array.prototype.forEach.call( document.querySelectorAll( '.ae-strip' ), function ( strip ) { init( strip ); live( strip ); } ); }
	if ( 'loading' === document.readyState ) { document.addEventListener( 'DOMContentLoaded', ready ); } else { ready(); }
})();
