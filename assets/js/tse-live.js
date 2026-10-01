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

	// Blocos iguais na mesma página (o mesmo cargo/UF/turno mais de uma vez) pedem a mesma URL: a resposta é compartilhada por
	// alguns segundos, então a REST recebe uma requisição só, e não uma por bloco. Os blocos não dividem estado (cada um lê e
	// escreve só no próprio elemento) e os ids são únicos por bloco, então repetir um shortcode não gera conflito.
	const recentes = new Map();
	function getJson( url ) {
		const hit = recentes.get( url );
		if ( hit && Date.now() - hit.t < 5000 ) return hit.p;
		const p = fetch( url, { headers: { 'X-WP-Nonce': nonce } } ).then( r => r.ok ? r.json() : Promise.reject( r.status ) );
		recentes.set( url, { t: Date.now(), p } );
		p.catch( () => recentes.delete( url ) );
		return p;
	}
	window.TSEGetJson = getJson;

    function updateWidget( widget ) {
        const cargo   = widget.dataset.cargo;
        const uf      = widget.dataset.uf;
        const limite  = widget.dataset.limite || 10;
		const turno   = widget.dataset.turno || 'auto'; // 'auto' segue o turno em andamento; 1 e 2 fixam
		const url     = `${ restUrl }?cargo=${ encodeURIComponent( cargo ) }&uf=${ encodeURIComponent( uf ) }&turno=${ turno === 'auto' ? 0 : encodeURIComponent( turno ) }&limite=${ limite }`;
		const apply   = widget.classList.contains( 'tse-card-secao' ) ? applySecaoUpdate
			: widget.classList.contains( 'tse-card' ) ? applyCardUpdate
			: applyUpdate;

        getJson( url )
			// Resposta de um pedido antigo (o visitante trocou de turno no seletor enquanto ela vinha) não pode sobrescrever a escolha.
			.then( data => { if ( ( widget.dataset.turno || 'auto' ) === turno ) apply( widget, data ); } )
            .catch( () => {} ); // falha silenciosa — mantém conteúdo anterior
    }

	// Selo "2º turno" (some no 1º turno). Serve a todos os widgets: cada um tem um .tse-turno-selo no cabeçalho.
	function syncSelo( widget, data ) {
		const turno = parseInt( data.turno, 10 ) || 1;
		widget.dataset.turnoAtual = String( turno );
		const selo = widget.querySelector( '.tse-turno-selo' );
		if ( ! selo ) return;
		selo.textContent = turno > 1 ? turno + 'º turno' : '';
		selo.hidden = turno <= 1;
	}

	// Seletor de turno (só [tse_apuracao]): aparece sozinho quando o TSE devolve o 2º turno e troca o bloco sem recarregar.
	function syncSeletor( widget, data ) {
		const nav = widget.querySelector( '.tse-turnos' );
		if ( ! nav ) return;
		const turnos = ( data.turnos || [] ).map( Number );
		const atual = parseInt( data.turno, 10 ) || 1;
		const visivel = turnos.length > 1;
		nav.hidden = ! visivel;
		if ( ! visivel ) return;
		const chave = turnos.join( ',' );
		if ( nav.dataset.opcoes !== chave ) {
			nav.dataset.opcoes = chave;
			nav.replaceChildren();
			turnos.forEach( t => {
				const a = document.createElement( 'a' );
				a.className = 'tse-turno-opcao';
				a.dataset.turnoSel = String( t );
				const url = new URL( window.location.href );
				url.searchParams.set( 'ae_turno', t );
				a.href = url.toString();
				a.textContent = t + 'º turno';
				nav.appendChild( a );
			} );
		}
		nav.querySelectorAll( '.tse-turno-opcao' ).forEach( a => {
			if ( Number( a.dataset.turnoSel ) === atual ) a.setAttribute( 'aria-current', 'true' ); else a.removeAttribute( 'aria-current' );
		} );
	}

	document.addEventListener( 'click', ( e ) => {
		const link = e.target.closest && e.target.closest( '.tse-turno-opcao' );
		const widget = link && link.closest( '.tse-apuracao-widget' );
		if ( ! widget || e.metaKey || e.ctrlKey || e.shiftKey ) return;
		e.preventDefault();
		widget.dataset.turno = link.dataset.turnoSel; // escolha manual: fixa o turno até a página recarregar
		updateWidget( widget );
	} );

	// [tse_apuracao_card limite=">1"]: cabeçalho único (título + % apurado) para a grade toda.
	function applySecaoUpdate( widget, data ) {
		if ( ! data ) return;
		syncSelo( widget, data );
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
		syncSelo( widget, data );
		// No 2º turno há menos candidatos que no 1º: o card de uma posição que deixou de existir some, e volta se existir de novo.
		if ( posicao > 0 ) widget.hidden = ! lider;

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

		syncSelo( widget, data );
		syncSeletor( widget, data );

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

		// Quem não consta no turno exibido (os finalistas do 2º turno, por exemplo) sai da lista.
		const presentes = new Set( data.candidatos.map( c => String( c.numero ) ) );
		widget.querySelectorAll( '.tse-candidato' ).forEach( li => { if ( ! presentes.has( li.dataset.numero ) ) li.remove(); } );

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

	// Navegadores throttlam (ou pausam) setInterval em abas em segundo plano —
	// numa aba esquecida em background por vários minutos, o polling regular
	// pode simplesmente não rodar. Sem isto, o visitante volta à aba e vê um
	// resultado antigo sem nenhum aviso, achando que é a apuração que travou.
	const liveWidgets = [];

	function initWidget( widget ) {
        const intervalo = parseInt( widget.dataset.atualizar, 10 );
        if ( ! intervalo || intervalo <= 0 ) return;

        // Primeiro update imediato após 3s (dá tempo de carregar o resto da página)
        setTimeout( () => updateWidget( widget ), 3000 );

        // Depois polling regular
        setInterval( () => updateWidget( widget ), intervalo * 1000 );

		liveWidgets.push( widget );
    }

	// Assim que a aba volta a ficar visível, força um update imediato em vez de
	// esperar o próximo tick do setInterval (que pode ter ficado parado/atrasado
	// por causa do throttling de background do navegador).
	document.addEventListener( 'visibilitychange', () => {
		if ( 'visible' === document.visibilityState ) {
			liveWidgets.forEach( updateWidget );
		}
	} );

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
