#!/usr/bin/env python3
"""
Gera a variante PHP 7.2 do plugin a partir da árvore da branch `php7.4`.

Uso (na raiz do plugin, já com a árvore da php7.4 em disco):
    python3 tools/port-php72.py

Reescreve só o que o PHP 7.2 não tem (arrow functions, ??=, propriedades tipadas e
JSON_THROW_ON_ERROR/JsonException, que são do 7.3/7.4) e ajusta o cabeçalho. O resto da compatibilidade
(polyfills de str_*/wp_date e a guarda do bloco) já vive em includes/compat.php e class-plugin.php, em
todas as branches. Se o código mudar de um jeito que o script não conhece, ele para com erro em vez de
gerar algo errado. Depois de rodar: lint no PHP 7.2 e as suítes em WordPress (ver PENDENCIAS.md).
"""
import glob
import re
import sys


def read(path):
    with open(path, encoding='utf-8') as handle:
        return handle.read()


def write(path, text):
    with open(path, 'w', encoding='utf-8') as handle:
        handle.write(text)


def replace_exact(path, old, new):
    text = read(path)
    if text.count(old) != 1:
        sys.exit('port-php72: trecho esperado não encontrado (ou repetido) em %s: %s' % (path, old[:70]))
    write(path, text.replace(old, new))


def arrow_to_closure(source):
    """`fn( $a ) => expr` vira `function ( $a ) use ( capturas ) { return expr; }`."""
    pattern = re.compile(r'(static\s+)?\bfn\s*\(')
    while True:
        match = pattern.search(source)
        if not match:
            return source
        i, depth = match.end(), 1
        while depth:
            depth += (source[i] == '(') - (source[i] == ')')
            i += 1
        args = source[match.end():i - 1]
        j, ret = i, ''
        typed = re.match(r'\s*:\s*[\?\w\\]+', source[j:])
        if typed:
            ret, j = typed.group(0), j + typed.end()
        if not source[j:].lstrip().startswith('=>'):
            sys.exit('port-php72: arrow function inesperada: ' + source[match.start():match.start() + 80])
        j = source.index('=>', j) + 2
        k, depth, quote = j, 0, None
        while True:
            char = source[k]
            if quote:
                if char == '\\':
                    k += 2
                    continue
                if char == quote:
                    quote = None
            elif char in '"\'':
                quote = char
            elif char in '([{':
                depth += 1
            elif char in ')]}':
                if depth == 0:
                    break
                depth -= 1
            elif char in ',;' and depth == 0:
                break
            k += 1
        expr = source[j:k].strip()
        own = set(re.findall(r'\$(\w+)', args))
        captured = [v for v in dict.fromkeys(re.findall(r'\$(\w+)', expr)) if v not in own and v != 'this' and not v.startswith('_') and v != 'GLOBALS']
        use = ' use ( ' + ', '.join('$' + v for v in captured) + ' )' if captured else ''
        source = source[:match.start()] + (match.group(1) or '') + 'function (' + args + ')' + use + ret + ' { return ' + expr + '; }' + source[k:]


def main():
    code = glob.glob('includes/*.php') + glob.glob('templates/*.php') + glob.glob('tests/*.php') + glob.glob('tests/phpunit/*.php') + glob.glob('blocks/**/*.php', recursive=True) + glob.glob('bin/*.php') + ['tse-apuracao.php']

    # JSON_THROW_ON_ERROR (7.3): json_decode + json_last_error.
    replace_exact('includes/class-job-runner.php',
        "$payload = json_decode( $job->payload_json, true, 512, JSON_THROW_ON_ERROR );\n\t\t\t$cursor = $job->cursor_json ? json_decode( $job->cursor_json, true, 512, JSON_THROW_ON_ERROR ) : array();",
        "$payload = json_decode( $job->payload_json, true );\n\t\t\t$cursor = $job->cursor_json ? json_decode( $job->cursor_json, true ) : array();\n\t\t\tif ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $payload ) || ! is_array( $cursor ) ) { throw new RuntimeException( 'Payload ou cursor do job inválido.' ); }")
    replace_exact('includes/class-tse-client.php',
        "try { $data = json_decode( wp_remote_retrieve_body( $response ), true, 512, JSON_THROW_ON_ERROR ); if ( ! is_array( $data ) ) { throw new RuntimeException( 'JSON TSE sem objeto raiz.' ); } return $data; } catch ( JsonException $e ) { throw new RuntimeException( 'JSON TSE inválido.' ); }",
        "$data = json_decode( wp_remote_retrieve_body( $response ), true ); if ( JSON_ERROR_NONE !== json_last_error() ) { throw new RuntimeException( 'JSON TSE inválido.' ); } if ( ! is_array( $data ) ) { throw new RuntimeException( 'JSON TSE sem objeto raiz.' ); } return $data;")

    for path in code:
        text = read(path)
        new = text.replace('return self::$instance ??= new self();', 'if ( null === self::$instance ) { self::$instance = new self(); } return self::$instance;')
        new = re.sub(r'((?:private|public|protected)(?:\s+static)?)\s+\??[A-Za-z_\\]+\s+(\$)', r'\1 \2', new)
        new = arrow_to_closure(new)
        if new != text:
            write(path, new)

    replace_exact('tse-apuracao.php', ' * Requires PHP: 7.4', ' * Requires PHP: 7.2')

    # Conferência final: nada do 7.3/7.4 pode ter sobrado.
    leftovers = []
    forbidden = re.compile(r'\bfn\s*\(|\?\?=|JSON_THROW_ON_ERROR|JsonException|^\s*(?:private|public|protected)(?:\s+static)?\s+\??[A-Za-z_\\]+\s+\$', re.M)
    for path in code:
        for number, line in enumerate(read(path).split('\n'), 1):
            if forbidden.search(line) and not line.lstrip().startswith(('*', '//', '/*')):
                leftovers.append('%s:%d: %s' % (path, number, line.strip()[:90]))
    if leftovers:
        sys.exit('port-php72: sobrou sintaxe do 7.3/7.4:\n' + '\n'.join(leftovers))
    print('port-php72: ok')


if __name__ == '__main__':
    main()
