// Carga na REST de resultados sem k6: só Node 18+.
//   node tests/load/results-node.js <url> [conexoes=50] [segundos=10] [etag=0|1] [bust=0|1]
// Com etag=1 cada conexão manda If-None-Match depois da 1ª resposta (como um CDN ou navegador revalidando).
// Com bust=1 cada requisição leva um parâmetro único (?_=n) e fura qualquer cache de página na frente do WordPress:
// mede a capacidade da ORIGEM (PHP + banco). Sem bust, num ambiente com cache de página, mede o cache.
// Imprime requisições/s, latência (p50/p95/p99), códigos HTTP e bytes. Não é teste de produção: mede o
// WordPress local, sem CDN. Em produção a borda absorve a maior parte (Cache-Control público com s-maxage).
const [url, conns = '50', secs = '10', etagMode = '0', bust = '0'] = process.argv.slice(2);
let seq = 0;
if (!url) { console.error('uso: node tests/load/results-node.js <url> [conexoes] [segundos] [etag]'); process.exit(2); }
const deadline = Date.now() + Number(secs) * 1000;
const lat = []; const codes = {}; let bytes = 0; let errors = 0;
async function worker() {
  let etag = '';
  while (Date.now() < deadline) {
    const t = performance.now();
    try {
      const res = await fetch(bust === '1' ? url + (url.includes('?') ? '&' : '?') + '_=' + (++seq) : url, { headers: etagMode === '1' && etag ? { 'If-None-Match': etag } : {} });
      const buf = await res.arrayBuffer();
      bytes += buf.byteLength; codes[res.status] = (codes[res.status] || 0) + 1;
      if (res.headers.get('etag')) etag = res.headers.get('etag');
    } catch (e) { errors++; }
    lat.push(performance.now() - t);
  }
}
const started = Date.now();
Promise.all(Array.from({ length: Number(conns) }, worker)).then(() => {
  lat.sort((a, b) => a - b);
  const q = p => lat[Math.min(lat.length - 1, Math.floor(p * lat.length))].toFixed(0);
  const total = lat.length; const elapsed = (Date.now() - started) / 1000;
  console.log(JSON.stringify({ url, conexoes: Number(conns), segundos: elapsed.toFixed(1), requisicoes: total, req_por_s: (total / elapsed).toFixed(0), p50_ms: q(0.5), p95_ms: q(0.95), p99_ms: q(0.99), codigos: codes, erros: errors, MB: (bytes / 1e6).toFixed(1) }));
  process.exit(errors || Object.keys(codes).some(c => !['200', '304'].includes(c)) ? 1 : 0);
});
