/**
 * TSE Apuração — atualização do widget [tse_apuracao_resumo].
 * Consulta o endpoint REST local (nunca o TSE) uma vez por linha e atualiza o texto, sem recarregar.
 */
( function () {
	'use strict';
	if ( typeof TSEConfig === 'undefined' ) return;
	const { restUrl, nonce } = TSEConfig;
	const widgets = Array.from( document.querySelectorAll( '.tse-resumo' ) );
	if ( ! widgets.length ) return;

	function setText( root, selector, text ) {
		const el = root.querySelector( selector );
		if ( el && text !== undefined && el.textContent !== text ) el.textContent = text;
	}

	function applyCand( li, cand ) {
		setText( li, '.tse-resumo-nome', cand.nome );
		setText( li, '.tse-resumo-partido', cand.partido );
		setText( li, '.tse-resumo-pct', cand.percentual );
		li.classList.toggle( 'tse-eleito', Boolean( cand.eleito ) );
		const lideraEl = li.querySelector( '.tse-resumo-lider' );
		let badge = li.querySelector( '.tse-badge-eleito' );
		if ( cand.eleito && ! badge && lideraEl ) {
			badge = document.createElement( 'span' );
			badge.className = 'tse-badge-eleito';
			badge.textContent = 'Eleito';
			lideraEl.appendChild( document.createTextNode( ' ' ) );
			lideraEl.appendChild( badge );
		} else if ( ! cand.eleito && badge ) { badge.remove(); }
	}

	function applyRow( item, data ) {
		const candidatos = data.candidatos || [];
		item.dataset.status = data.status || '';
		item.dataset.atrasado = data.atrasado ? '1' : '';
		if ( ! candidatos.length ) return;
		if ( item.querySelector( '.tse-resumo-aguardando' ) ) { window.location.reload(); return; } // primeira vez que chega dado: o markup é outro
		const itens = Array.from( item.querySelectorAll( '.tse-resumo-cand' ) );
		if ( candidatos.length > itens.length ) { window.location.reload(); return; } // entrou candidato novo: o markup mudou
		itens.forEach( ( li, i ) => { if ( candidatos[ i ] ) applyCand( li, candidatos[ i ] ); } );
		setText( item, '.tse-resumo-apurado', ( data.pct_apurado || '0%' ) + ' apurado' );
	}

	function refreshState( widget ) {
		const items = Array.from( widget.querySelectorAll( '.tse-resumo-item' ) ).filter( i => ! i.querySelector( '.tse-resumo-aguardando' ) );
		const el = widget.querySelector( '.tse-resumo-estado' );
		if ( ! el ) return;
		let label = 'Aguardando apuração';
		if ( items.length ) {
			if ( items.some( i => i.dataset.atrasado === '1' ) ) label = 'Dados atrasados';
			else if ( items.some( i => i.dataset.status !== 'Totalizado' ) ) label = 'Ao vivo';
			else label = 'Apuração concluída';
		}
		el.textContent = label;
		el.classList.toggle( 'tse-dados-atrasados', 'Dados atrasados' === label );
	}

	function update( widget ) {
		const jobs = Array.from( widget.querySelectorAll( '.tse-resumo-item' ) ).map( item => {
			const url = `${ restUrl }?cargo=${ encodeURIComponent( item.dataset.cargo ) }&uf=${ encodeURIComponent( item.dataset.uf ) }&turno=${ item.dataset.turno || 1 }&limite=${ parseInt( item.dataset.limite, 10 ) || 1 }`;
			return fetch( url, { headers: { 'X-WP-Nonce': nonce } } )
				.then( r => r.ok ? r.json() : Promise.reject( r.status ) )
				.then( data => applyRow( item, data ) )
				.catch( () => {} ); // falha silenciosa: mantém o conteúdo anterior
		} );
		Promise.all( jobs ).then( () => refreshState( widget ) );
	}

	widgets.forEach( widget => {
		const intervalo = parseInt( widget.dataset.atualizar, 10 );
		if ( ! intervalo || intervalo <= 0 ) return;
		setTimeout( () => update( widget ), 3000 );
		setInterval( () => update( widget ), intervalo * 1000 );
	} );
	// Abas em segundo plano têm o setInterval estrangulado: ao voltar, atualiza na hora.
	document.addEventListener( 'visibilitychange', () => {
		if ( 'visible' === document.visibilityState ) widgets.forEach( update );
	} );
} )();
