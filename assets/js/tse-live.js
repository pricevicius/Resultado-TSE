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

	function liveLabel( data ) {
		if ( data.atrasado ) return 'Dados atrasados';
		if ( 'Totalizado' === data.status ) return 'Apuração concluída';
		return 'Ao vivo';
	}

    function updateWidget( widget ) {
        const cargo   = widget.dataset.cargo;
        const uf      = widget.dataset.uf;
        const limite  = widget.dataset.limite || 10;
		const turno   = widget.dataset.turno || 1;
		const url     = `${ restUrl }?cargo=${ encodeURIComponent( cargo ) }&uf=${ encodeURIComponent( uf ) }&turno=${ turno }&limite=${ limite }`;
		const apply   = widget.classList.contains( 'tse-card-secao' ) ? applySecaoUpdate
			: widget.classList.contains( 'tse-card' ) ? applyCardUpdate
			: applyUpdate;

        fetch( url, { headers: { 'X-WP-Nonce': nonce } } )
            .then( r => r.ok ? r.json() : Promise.reject( r.status ) )
			.then( data => apply( widget, data ) )
            .catch( () => {} ); // falha silenciosa — mantém conteúdo anterior
    }

	// [tse_apuracao_card limite=">1"]: cabeçalho único (título + % apurado) para a grade toda.
	function applySecaoUpdate( widget, data ) {
		if ( ! data ) return;
		const pctEl = widget.querySelector( '.tse-card-secao-pct' );
		if ( pctEl && data.pct_apurado ) pctEl.textContent = data.pct_apurado + ' apurado';
	}

	function createCardBadge( c, status ) {
		if ( c.eleito ) {
			const badge = document.createElement( 'span' );
			badge.className = 'tse-badge-eleito';
			badge.textContent = 'Eleito';
			return badge;
		}
		if ( 'Totalizado' === status && c.situacao ) {
			const badge = document.createElement( 'span' );
			badge.className = 'tse-badge-status' + ( c.segundo_turno ? ' tse-badge-turno2' : '' );
			badge.textContent = c.situacao;
			return badge;
		}
		return null;
	}

	// [tse_apuracao_card]: um card por colocado (data-posicao decide qual candidato este card mostra).
	function applyCardUpdate( widget, data ) {
		if ( ! data ) return;
		const posicao = parseInt( widget.dataset.posicao, 10 ) || 0;
		const lider = data.candidatos && data.candidatos[ posicao ];

		const pctEl = widget.querySelector( '.tse-card-pct' );
		if ( pctEl && data.pct_apurado ) pctEl.textContent = data.pct_apurado + ' apurado';

		const atualizadoEl = widget.querySelector( '.tse-card-atualizado' );
		if ( atualizadoEl ) {
			atualizadoEl.classList.toggle( 'tse-dados-atrasados', Boolean( data.atrasado ) );
			atualizadoEl.textContent = liveLabel( data );
		}

		if ( ! lider ) return;

		const liderEl = widget.querySelector( '.tse-card-lider' );
		if ( liderEl ) {
			liderEl.classList.toggle( 'tse-eleito', Boolean( lider.eleito ) );
			liderEl.classList.toggle( 'tse-segundo-turno', Boolean( lider.segundo_turno ) );
		}

		const numEl = widget.querySelector( '.tse-card-numero' );
		const nomeEl = widget.querySelector( '.tse-card-nome' );
		const partidoEl = widget.querySelector( '.tse-card-partido' );
		const pctCandEl = widget.querySelector( '.tse-card-percentual' );
		const barraEl = widget.querySelector( '.tse-card-barra' );
		if ( numEl ) numEl.textContent = lider.numero;
		if ( nomeEl ) nomeEl.textContent = lider.nome;
		if ( partidoEl ) partidoEl.textContent = lider.partido;
		if ( pctCandEl ) pctCandEl.textContent = lider.percentual;
		if ( barraEl ) {
			// 'percentual' vem formatado ("12,85%") para exibição; a largura da barra precisa de número com ponto.
			const pctBarra = parseFloat( String( lider.percentual || '0' ).replace( ',', '.' ) );
			barraEl.style.width = ( isNaN( pctBarra ) ? 0 : pctBarra ) + '%';
		}

		const rodapeEl = widget.querySelector( '.tse-card-rodape' );
		if ( rodapeEl ) {
			rodapeEl.querySelector( '.tse-badge-eleito, .tse-badge-status' )?.remove();
			const badge = createCardBadge( lider, data.status );
			if ( badge ) { rodapeEl.prepend( badge ); }
		}
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

        const pctNumber = Number.isFinite( Number( data.pct_apurado_numero ) )
            ? Number( data.pct_apurado_numero )
            : parseFloat( String( data.pct_apurado || '' ).replace( ',', '.' ) );
        if ( fillEl && ! isNaN( pctNumber ) ) {
            fillEl.style.width = Math.max( 0, Math.min( 100, pctNumber ) ) + '%';
        }
        if ( labelEl && data.pct_apurado ) {
            labelEl.textContent = data.pct_apurado + ' apurado';
        }
        if ( pctBar && ! isNaN( pctNumber ) ) {
            pctBar.setAttribute( 'aria-valuenow', pctNumber );
        }

		const liveEl = widget.querySelector( '.tse-ao-vivo' );
		const liveLabelEl = widget.querySelector( '.tse-live-label' );
		if ( liveEl ) liveEl.classList.toggle( 'tse-dados-atrasados', Boolean( data.atrasado ) );
		if ( liveLabelEl ) liveLabelEl.textContent = liveLabel( data );

        // Atualiza status
        const statusEl = widget.querySelector( '.tse-status' );
        if ( statusEl && data.status ) {
            statusEl.textContent = data.status;
        }

        // Atualiza timestamp — texto exibido em horario local (fuso do site); dateTime continua em ISO/UTC (correto para o atributo HTML).
        const tsEl = widget.querySelector( '.tse-timestamp' );
        if ( tsEl && data.atualizado_em ) {
            tsEl.textContent  = data.atualizado_em_local || data.atualizado_em;
            tsEl.dateTime     = data.atualizado_em;
        }

        const proximaEl = widget.querySelector( '.tse-proxima' );
        if ( proximaEl ) {
            if ( data.proxima_atualizacao_ts ) {
                proximaEl.dataset.proxima = data.proxima_atualizacao_ts;
                proximaEl.hidden = false;
            } else {
                proximaEl.hidden = true; // apuracao totalizada: nao ha proxima coleta
            }
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

			// Reordena o <li> para a posição atual: o polling só reaproveita nós
			// existentes (chave = numero de urna) e sem isto a ordem do DOM
			// congela na primeira renderização, dessincronizando do ranking.
			if ( list ) { list.appendChild( li ); }

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
					badge.classList.toggle( 'tse-badge-turno2', Boolean( cand.segundo_turno ) );
				} else { badge?.remove(); }
            }
			li.classList.toggle( 'tse-segundo-turno', Boolean( cand.segundo_turno ) );
			li.dataset.situacao = cand.situacao || '';
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

	function formatCountdown( seconds ) {
		if ( seconds <= 0 ) return 'agora';
		if ( seconds < 60 ) return seconds + 's';
		const min = Math.floor( seconds / 60 );
		const sec = seconds % 60;
		return sec > 0 ? `${ min }min ${ sec }s` : `${ min }min`;
	}

	// Contagem regressiva local (não faz requisição): só reflete a estimativa que o
	// servidor devolveu em proxima_atualizacao_ts a cada poll; nunca é a fonte da verdade.
	function tickCountdowns() {
		document.querySelectorAll( '.tse-proxima[data-proxima]' ).forEach( ( el ) => {
			const target = parseInt( el.dataset.proxima, 10 );
			if ( ! target ) return;
			const contador = el.querySelector( '.tse-proxima-contador' );
			if ( contador ) contador.textContent = formatCountdown( target - Math.floor( Date.now() / 1000 ) );
		} );
	}

    // Inicializa todos os widgets na página
    document.querySelectorAll( '.tse-apuracao-widget, .tse-card, .tse-card-secao' ).forEach( initWidget );
	tickCountdowns();
	setInterval( tickCountdowns, 1000 );
} )();
