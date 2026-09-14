/**
 * TSE Apuração — Live update
 * Faz polling no endpoint REST do plugin e atualiza o DOM sem reload.
 */
( function () {
    'use strict';

    if ( typeof TSEConfig === 'undefined' ) return;

    const { restUrl, nonce } = TSEConfig;

    function fmt( n ) {
        return Number( n ).toLocaleString( 'pt-BR' );
    }

    function updateWidget( widget ) {
        const cargo   = widget.dataset.cargo;
        const uf      = widget.dataset.uf;
        const limite  = widget.dataset.limite || 10;
		const turno   = widget.dataset.turno || 1;
		const url     = `${ restUrl }?cargo=${ encodeURIComponent( cargo ) }&uf=${ encodeURIComponent( uf ) }&turno=${ turno }&limite=${ limite }`;

        fetch( url, { headers: { 'X-WP-Nonce': nonce } } )
            .then( r => r.ok ? r.json() : Promise.reject( r.status ) )
            .then( data => applyUpdate( widget, data ) )
            .catch( () => {} ); // falha silenciosa — mantém conteúdo anterior
    }

    function applyUpdate( widget, data ) {
        if ( ! data || ! data.candidatos ) return;
		const content = widget.querySelector( '.tse-content' );
		let list = widget.querySelector( '.tse-lista' );
		if ( data.candidatos.length && ! list && content ) {
			content.replaceChildren();
			list = document.createElement( 'ol' );
			list.className = 'tse-lista';
			list.setAttribute( 'aria-label', 'Candidatos por número de votos' );
			content.appendChild( list );
		}

        // Atualiza % apurado
        const fillEl    = widget.querySelector( '.tse-apurado-fill' );
        const labelEl   = widget.querySelector( '.tse-apurado-label' );
        const pctBar    = widget.querySelector( '.tse-apurado-barra' );

        if ( fillEl && data.pct_apurado ) {
            fillEl.style.width = data.pct_apurado;
        }
        if ( labelEl && data.pct_apurado ) {
            labelEl.textContent = data.pct_apurado + ' apurado';
        }
        if ( pctBar && data.pct_apurado ) {
            const val = parseFloat( data.pct_apurado );
            if ( ! isNaN( val ) ) {
                pctBar.setAttribute( 'aria-valuenow', val );
            }
        }

        // Atualiza status
        const statusEl = widget.querySelector( '.tse-status' );
        if ( statusEl && data.status ) {
            statusEl.textContent = data.status;
        }

        // Atualiza timestamp
        const tsEl = widget.querySelector( '.tse-timestamp' );
        if ( tsEl && data.atualizado_em ) {
            tsEl.textContent  = data.atualizado_em;
            tsEl.dateTime     = data.atualizado_em;
        }

        // Calcula max votos para escalar barras
        const votos = data.candidatos.map( c => c.votos );
        const maxVotos = Math.max( 1, ...votos );

        // Atualiza cada candidato existente
        data.candidatos.forEach( ( cand, i ) => {
			let li = Array.from( widget.querySelectorAll( '.tse-candidato' ) ).find( item => item.dataset.numero === String( cand.numero ) );
			if ( ! li && list ) {
				li = document.createElement( 'li' ); li.className = 'tse-candidato'; li.dataset.numero = String( cand.numero );
				li.innerHTML = '<div class="tse-cand-posicao"></div><div class="tse-cand-info"><div class="tse-cand-top"><span class="tse-cand-numero"></span><span class="tse-cand-nome"></span><span class="tse-cand-partido"></span></div><div class="tse-cand-barra-wrap" role="presentation"><div class="tse-cand-barra"></div></div></div><div class="tse-cand-votos"><span class="tse-votos-num"></span><span class="tse-votos-pct"></span></div>';
				li.querySelector( '.tse-cand-numero' ).textContent = cand.numero;
				li.querySelector( '.tse-cand-nome' ).textContent = cand.nome;
				li.querySelector( '.tse-cand-partido' ).textContent = cand.partido;
				list.appendChild( li );
			}
            if ( ! li ) return;

            const numEl  = li.querySelector( '.tse-votos-num' );
            const pctEl  = li.querySelector( '.tse-votos-pct' );
            const barEl  = li.querySelector( '.tse-cand-barra' );
            const posEl  = li.querySelector( '.tse-cand-posicao' );

            if ( numEl )  numEl.textContent = fmt( cand.votos );
            if ( pctEl )  pctEl.textContent = cand.percentual;
            if ( posEl )  posEl.textContent = ( i + 1 ) + 'º';
            if ( barEl ) {
                const pctBarra = maxVotos > 0 ? ( ( cand.votos / maxVotos ) * 100 ).toFixed( 1 ) : 0;
                barEl.style.width = pctBarra + '%';
            }

            if ( cand.eleito ) {
                li.classList.add( 'tse-eleito' );
				li.querySelector( '.tse-badge-status' )?.remove();
                if ( ! li.querySelector( '.tse-badge-eleito' ) ) {
                    const badge = document.createElement( 'span' );
                    badge.className   = 'tse-badge-eleito';
                    badge.textContent = 'Eleito';
                    li.querySelector( '.tse-cand-top' )?.appendChild( badge );
                }
			} else {
				li.classList.remove( 'tse-eleito' );
				li.querySelector( '.tse-badge-eleito' )?.remove();
				let badge = li.querySelector( '.tse-badge-status' );
				if ( data.status === 'Totalizado' && cand.situacao ) {
					if ( ! badge ) { badge = document.createElement( 'span' ); badge.className = 'tse-badge-status'; li.querySelector( '.tse-cand-top' )?.appendChild( badge ); }
					badge.textContent = cand.situacao;
				} else { badge?.remove(); }
            }
        } );

        // Feedback visual de atualização
        widget.classList.add( 'tse-atualizando' );
        setTimeout( () => widget.classList.remove( 'tse-atualizando' ), 450 );
    }

    function initWidget( widget ) {
        const intervalo = parseInt( widget.dataset.atualizar, 10 );
        if ( ! intervalo || intervalo <= 0 ) return;

        // Primeiro update imediato após 3s (dá tempo de carregar o resto da página)
        setTimeout( () => updateWidget( widget ), 3000 );

        // Depois polling regular
        setInterval( () => updateWidget( widget ), intervalo * 1000 );
    }

    // Inicializa todos os widgets na página
    document.querySelectorAll( '.tse-apuracao-widget' ).forEach( initWidget );
} )();
