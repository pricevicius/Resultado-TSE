# TSE Apuração — Plugin WordPress

Exibe resultados eleitorais em tempo real via API pública do TSE.  
Basta instalar, configurar e jogar um shortcode em qualquer página.

---

## Instalação

1. Copie a pasta `tse-apuracao/` para `wp-content/plugins/`
2. Ative em **Plugins → Plugins Instalados**
3. Acesse **Configurações → TSE Apuração** e verifique se os IDs foram detectados automaticamente
4. Crie uma página e insira o shortcode

---

## Shortcode

```
[tse_apuracao cargo="presidente" uf="br"]
```

### Atributos completos

| Atributo   | Padrão        | Descrição |
|------------|--------------|-----------|
| `cargo`    | `presidente` | Cargo a exibir (ver tabela abaixo) |
| `uf`       | `br`         | Sigla da UF em minúsculas (`br`, `sp`, `rj`, `mg`…) |
| `limite`   | `10`         | Máximo de candidatos exibidos |
| `atualizar`| `60`         | Segundos entre atualizações automáticas. `0` = desligado |
| `titulo`   | _(automático)_| Título exibido no cabeçalho do painel |
| `turno`    | `1`          | `1` ou `2` |

### Exemplos práticos

```
[tse_apuracao cargo="presidente" uf="br" limite="5" atualizar="30"]

[tse_apuracao cargo="governador" uf="sp" titulo="Governador de SP"]

[tse_apuracao cargo="senador" uf="mg" atualizar="0"]

[tse_apuracao cargo="prefeito" uf="rj" limite="3" atualizar="60"]
```

### Cargos disponíveis

| Valor no shortcode    | Cargo |
|-----------------------|-------|
| `presidente`          | Presidente da República |
| `governador`          | Governador de Estado |
| `senador`             | Senador |
| `deputado-federal`    | Deputado Federal |
| `deputado-estadual`   | Deputado Estadual |
| `deputado-distrital`  | Deputado Distrital (DF) |
| `prefeito`            | Prefeito |
| `vereador`            | Vereador |

---

## Configurações (Admin)

Acesse **Configurações → TSE Apuração**.

| Campo | Descrição |
|-------|-----------|
| **URL base do TSE** | `https://resultados.tse.jus.br/oficial/` |
| **Ano da eleição** | `2026` (atualizar a cada eleição) |
| **ID da eleição (CD)** | Deixe em branco para detecção automática via `ele-c.json` |
| **ID do pleito (PL)** | Deixe em branco para detecção automática |
| **Cache ao vivo** | Segundos de cache durante a apuração (padrão: 30s) |
| **Cache padrão** | Segundos de cache fora do período eleitoral (padrão: 300s) |
| **Cor primária** | Cor do cabeçalho e barras |
| **Cor "Eleito"** | Cor do badge e barra dos eleitos |

### Modo de teste (mock local)

Para testar sem esperar o simulado do TSE, ative o modo mock nas configurações:

1. Marque **Usar dados de teste**
2. O plugin servirá os dados do arquivo `tse-apuracao/mock/resultado-mock.json`
3. Edite esse arquivo para simular diferentes cenários

---

## Personalização visual por cliente

Todas as cores são CSS custom properties. Para customizar sem editar o plugin,
adicione no CSS do tema filho ou no Personalizador:

```css
/* Sobrescreve as cores para este cliente */
.tse-apuracao-widget {
    --tse-primary: #cc0000;   /* cor institucional do cliente */
    --tse-eleito:  #005500;   /* cor para candidatos eleitos */
}
```

Variáveis disponíveis:

| Variável | Padrão | Uso |
|----------|--------|-----|
| `--tse-primary` | `#003366` | Cabeçalho, barras, número do candidato |
| `--tse-eleito` | `#007A33` | Badge "Eleito", barra dos eleitos |
| `--tse-bg` | `#ffffff` | Fundo do widget |
| `--tse-bg-alt` | `#f5f7fa` | Fundo do rodapé e hover |
| `--tse-border` | `#dde2ea` | Bordas e separadores |
| `--tse-text` | `#1a2a3a` | Texto principal |
| `--tse-muted` | `#5a6a7a` | Texto secundário |
| `--tse-radius` | `10px` | Border radius do widget |

---

## Arquitetura técnica

```
tse-apuracao/
├── tse-apuracao.php              Bootstrap e constantes
├── includes/
│   ├── class-tse-api.php         Busca + cache dos JSONs do TSE
│   ├── class-tse-settings.php    Página de configurações no admin
│   └── class-tse-shortcode.php   Shortcode + endpoint REST interno
├── assets/
│   ├── css/tse-apuracao.css      Estilos (customizável via CSS vars)
│   └── js/tse-live.js            Polling e atualização do DOM
├── templates/
│   └── resultado.php             Template HTML do painel
├── mock/
│   └── resultado-mock.json       Dados de teste (não vai para produção)
└── README.md                     Esta documentação
```

### Fluxo de dados

```
Página com shortcode
  → PHP: shortcode_atts() valida atributos
  → TSE_API::resolver_ids() busca ele-c.json (cache 5min)
  → TSE_API::get_resultado() busca arquivo de resultado (cache 30s)
  → TSE_API::normalizar() mapeia campos do TSE para estrutura interna
  → templates/resultado.php renderiza o HTML inicial
  → JS (tse-live.js) inicia polling via /wp-json/tse/v1/resultado
  → REST endpoint busca dados frescos e retorna JSON
  → JS atualiza DOM sem reload (votos, barras, % apurado, timestamp)
```

### Endpoint REST

```
GET /wp-json/tse/v1/resultado
    ?cargo=presidente
    &uf=br
    &limite=10
```

Resposta:
```json
{
  "atualizado_em": "05/10/2026 20:15:00",
  "horario": "20:15:00",
  "status": "Parcial",
  "pct_apurado": "87%",
  "turno": "1",
  "candidatos": [
    {
      "numero": "13",
      "nome": "FULANO DA SILVA",
      "partido": "PT",
      "votos": 52000000,
      "percentual": "49,2%",
      "eleito": false,
      "foto_url": "",
      "sequencia": 1
    }
  ]
}
```

---

## Estrutura da API pública do TSE

### Ponto de entrada

```
https://resultados.tse.jus.br/oficial/comum/config/ele-c.json
```

Retorna a configuração da eleição: IDs, datas, cargos disponíveis.  
O plugin usa este arquivo para detectar automaticamente os IDs de eleição e pleito.

### Arquivos de resultado

```
https://resultados.tse.jus.br/oficial/ele{ANO}/{PLEITO}/dados-simplificados/{uf}/{uf}-e{ELEICAO}-r{CARGO_COD}.json
```

Exemplos:
```
/ele2026/544/dados-simplificados/br/br-e000544-r01112026.json   ← Presidente
/ele2026/544/dados-simplificados/sp/sp-e000544-r03112026.json   ← Governador SP
/ele2026/544/dados-simplificados/mg/mg-e000544-r05112026.json   ← Senador MG
```

> **Nota:** Os valores de `{PLEITO}` e `{ELEICAO}` mudam a cada eleição.
> O plugin obtém esses valores automaticamente via `ele-c.json`.

### Codificação de cargos no TSE

| Código | Cargo |
|--------|-------|
| `1`    | Presidente |
| `3`    | Governador |
| `5`    | Senador |
| `6`    | Deputado Federal |
| `7`    | Deputado Estadual |
| `8`    | Deputado Distrital |
| `11`   | Prefeito |
| `13`   | Vereador |

### Campos do JSON retornado pelo TSE

| Campo | Descrição |
|-------|-----------|
| `dt` ou `dh` | Data/hora da última atualização |
| `hg` | Horário da geração do arquivo |
| `s` ou `st` | Status da apuração (`Parcial`, `Totalizado`) |
| `pst` ou `pa` | Percentual de seções apuradas |
| `t` | Turno |
| `cands` ou `c` | Array de candidatos |

Campos por candidato:

| Campo | Alternativa | Descrição |
|-------|-------------|-----------|
| `n` | `nu` | Número do candidato |
| `nm` | `nmc` | Nome do candidato |
| `sg` | `sgp` | Sigla do partido |
| `tv` | `vap` | Total de votos |
| `pvap` | `pv` | Percentual dos votos apurados |
| `e` | `st` | Eleito (`S` = sim) |
| `f` | — | URL da foto |
| `seq` | — | Sequência na listagem |

> Esses campos podem variar entre versões. O método `TSE_API::normalizar()`
> em `includes/class-tse-api.php` é o único ponto de adaptação necessário
> caso o TSE altere o formato.

---

## Atualização para novas eleições

A cada nova eleição (2028, 2030…):

1. Acesse **Configurações → TSE Apuração**
2. Atualize o **Ano da eleição**
3. Deixe os IDs em branco para detecção automática — ou preencha manualmente com os IDs do `ele-c.json`
4. Teste com o simulado do TSE antes da eleição real

> Se o TSE mudar o formato do JSON, edite apenas o método `normalizar()`
> em `includes/class-tse-api.php`. Todo o resto do plugin continua funcionando.

---

## Testes

### Opção 1 — Mock local (disponível agora)

Edite `mock/resultado-mock.json` com dados fictícios e ative o modo mock no admin.  
Permite testar a interface e o auto-refresh sem depender da API do TSE.

### Opção 2 — Simulado oficial do TSE

O TSE publica um ambiente de simulação antes de cada eleição:
- 2026: simulado previsto para **setembro de 2026**
- URL base: `https://resultados-sim.tse.jus.br/simulado/`

### Opção 3 — Dados históricos (2024)

Os dados da eleição municipal de 2024 estão disponíveis no Portal de Dados Abertos:
```
https://dadosabertos.tse.jus.br/dataset/resultados-2024
```

Para usar com o plugin:
1. Baixe o arquivo de resultados em formato JSON
2. Sirva localmente via um servidor simples (Python, Node, nginx)
3. Configure a **URL base** no admin apontando para o servidor local

---

## Suporte e manutenção

- Plugin desenvolvido por **Tribuna Online**
- Dúvidas técnicas: contato via repositório interno
- Documentação oficial TSE: https://www.tse.jus.br/eleicoes/informacoes-tecnicas-sobre-a-divulgacao-de-resultados
- Portal de dados abertos: https://dadosabertos.tse.jus.br
