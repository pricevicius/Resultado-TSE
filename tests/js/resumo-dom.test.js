// Teste da lógica de assets/js/tse-resumo.js com um DOM mínimo. Uso: node tests/js/resumo-dom.test.js assets/js/tse-resumo.js
const fs = require('fs'); const assert = require('assert');
class El { constructor(cls='', ds={}) { this.classList = new Set(cls.split(' ').filter(Boolean)); this.classList.toggle = (c, on) => { on ? this.classList.add(c) : this.classList.delete(c); }; this.classList.contains = c => this.classList.has(c); this.classList.remove = c => { this.classList.delete(c); }; this.dataset = ds; this.kids = {}; this._t = ''; this.children = []; }
  get textContent() { return this._t; } set textContent(v) { this._t = v; }
  querySelector(sel) { return this.kids[sel] || null; } querySelectorAll(sel) { return this.lists && this.lists[sel] || []; }
  appendChild(c) { this.children.push(c); } remove() { this.removed = true; } }
const mk = (cls, ds) => new El(cls, ds);
const item = mk('tse-resumo-item', { cargo: 'governador', uf: 'zy', turno: '1' });
['.tse-resumo-nome','.tse-resumo-partido','.tse-resumo-pct','.tse-resumo-apurado','.tse-resumo-lider'].forEach(s => item.kids[s] = mk());
const estado = mk('tse-resumo-estado'); const widget = mk('tse-resumo', { atualizar: '60' });
widget.lists = { '.tse-resumo-item': [item] }; widget.kids['.tse-resumo-estado'] = estado;
let timers = []; const payload = { status: 'Parcial', atrasado: false, pct_apurado: '50,00%', candidatos: [{ nome: 'ANA', partido: 'AAA', percentual: '41,00%', eleito: false }] };
global.TSEConfig = { restUrl: 'http://x/rest', nonce: 'n' };
global.document = { querySelectorAll: s => s === '.tse-resumo' ? [widget] : [], addEventListener() {} };
global.document.createTextNode = t => ({ t }); global.document.createElement = () => mk();
global.window = { location: { reload() { throw new Error('reload inesperado'); } } };
global.setTimeout = (f) => { timers.push(f); }; global.setInterval = () => {};
let calls = []; global.fetch = (u) => { calls.push(u); return Promise.resolve({ ok: true, json: () => Promise.resolve(payload) }); };
eval(fs.readFileSync(process.argv[2], 'utf8'));
(async () => {
  timers[0](); await new Promise(r => setImmediate(r)); await new Promise(r => setImmediate(r));
  assert.strictEqual(calls.length, 1); assert(calls[0].includes('cargo=governador') && calls[0].includes('uf=zy') && calls[0].includes('limite=1'));
  assert.strictEqual(item.kids['.tse-resumo-nome'].textContent, 'ANA'); assert.strictEqual(item.kids['.tse-resumo-pct'].textContent, '41,00%');
  assert.strictEqual(item.kids['.tse-resumo-apurado'].textContent, '50,00% apurado'); assert.strictEqual(estado.textContent, 'Ao vivo');
  payload.status = 'Totalizado'; payload.candidatos[0].eleito = true; timers[0](); await new Promise(r => setImmediate(r)); await new Promise(r => setImmediate(r));
  assert.strictEqual(estado.textContent, 'Apuração concluída'); assert(item.classList.has('tse-eleito')); assert.strictEqual(item.children.length, 0);
  payload.status = 'Parcial'; payload.atrasado = true; payload.candidatos[0].eleito = false; timers[0](); await new Promise(r => setImmediate(r)); await new Promise(r => setImmediate(r));
  assert.strictEqual(estado.textContent, 'Dados atrasados');
  console.log('ok   tse-resumo.js: atualiza linha, estado Ao vivo / concluída / atrasado');
})().catch(e => { console.error('FAIL', e.message); process.exit(1); });
