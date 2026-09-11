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
        const url     = `${ restUrl }?cargo=${ encodeURIComponent( cargo ) }&uf=${ encodeURIComponent( uf ) }&limite=${ limite }`;

        fetch( url, { headers: { 'X-WP-Nonce': nonce } } )
            .then( r => r.ok ? r.json() : Promise.reject( r.status ) )
            .then( data => applyUpdate( widget, data ) )
            .catch( () => {} ); // falha silenciosa — mantém conteúdo anterior
    }

    function applyUpdate( widget, data ) {
        if ( ! data || ! data.candidatos ) return;

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
            const li = widget.querySelector( `.tse-candidato[data-numero="${ cand.numero }"]` );
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
                if ( ! li.querySelector( '.tse-badge-eleito' ) ) {
                    const badge = document.createElement( 'span' );
                    badge.className   = 'tse-badge-eleito';
                    badge.textContent = 'Eleito';
                    li.querySelector( '.tse-cand-top' )?.appendChild( badge );
                }
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
