/* Botões anterior/próximo da faixa de candidatos. Sem JS a lista continua rolável; os botões só aparecem se houver o que rolar. */
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
	function ready() { Array.prototype.forEach.call( document.querySelectorAll( '.ae-strip' ), init ); }
	if ( 'loading' === document.readyState ) { document.addEventListener( 'DOMContentLoaded', ready ); } else { ready(); }
})();
