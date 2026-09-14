<?php
declare(strict_types=1);

/**
 * Checagem do config.php antes de abrir as vendas. Rode no servidor:
 *
 *   php tools/checar-config.php
 *
 * Diferente do api/diagnostico.php, este script NÃO chama a ZuckPay nem cria
 * cobrança: ele só confere se a configuração está completa e coerente. Rode
 * este primeiro; se passar, rode o diagnóstico para testar a API de verdade.
 *
 * Sai com código 1 se houver erro, para dar para usar em deploy.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../api/_bootstrap.php';

$caminho = __DIR__ . '/../api/config.php';
if (!is_file($caminho)) {
    fwrite(STDERR, "config.php não existe. Rode: cp api/config.example.php api/config.php\n");
    exit(1);
}

$config = require $caminho;

$erros = [];
$avisos = [];
$ok = [];

function valorDeExemplo(string $v): bool
{
    return $v === ''
        || str_starts_with($v, 'seu_')
        || str_contains($v, 'SEU-DOMINIO');
}

/* ---------- ambiente ---------- */
version_compare(PHP_VERSION, '8.0', '>=')
    ? $ok[] = 'PHP ' . PHP_VERSION
    : $erros[] = 'PHP ' . PHP_VERSION . ' — o projeto precisa de PHP 8+';

extension_loaded('curl') ? $ok[] = 'extensão cURL' : $erros[] = 'extensão cURL não está instalada';
extension_loaded('mbstring') ? $ok[] = 'extensão mbstring' : $erros[] = 'extensão mbstring não está instalada';
function_exists('mail') ? $ok[] = 'função mail()' : $avisos[] = 'mail() indisponível — a entrega por e-mail não vai sair';

$dir = diretorioEstado($config);
is_dir($dir) && is_writable($dir)
    ? $ok[] = 'pasta de estado gravável (' . $dir . ')'
    : $erros[] = 'a pasta ' . $dir . ' não existe ou não tem permissão de escrita';

/* ---------- credenciais ---------- */
foreach (['client_id', 'client_secret'] as $campo) {
    valorDeExemplo((string) ($config[$campo] ?? ''))
        ? $erros[] = $campo . ' ainda está com o valor de exemplo'
        : $ok[] = $campo . ' preenchido';
}

$base = (string) ($config['api_base'] ?? '');
str_starts_with($base, 'https://') && str_contains($base, 'zuckpay')
    ? $ok[] = 'api_base: ' . $base
    : $erros[] = 'api_base inválido: ' . $base;

/* ---------- webhook ---------- */
$webhook = (string) ($config['webhook_url'] ?? '');
if (valorDeExemplo($webhook) || !str_starts_with($webhook, 'https://')) {
    $erros[] = 'webhook_url precisa ser a URL pública real do api/webhook.php (https)';
} elseif (!str_ends_with($webhook, '/api/webhook.php')) {
    $avisos[] = 'webhook_url não termina em /api/webhook.php — confira: ' . $webhook;
} else {
    $ok[] = 'webhook_url: ' . $webhook;
}

((string) ($config['webhook_secret'] ?? '')) !== ''
    ? $ok[] = 'webhook_secret preenchido (assinatura dos postbacks validada)'
    : $avisos[] = 'webhook_secret vazio — gere em Integrações > Webhook Secret; sem ele qualquer POST na URL do webhook passa pela primeira camada';

/* ---------- CORS ---------- */
$origens = (array) ($config['allowed_origins'] ?? []);
$origensRuins = array_filter($origens, static fn ($o): bool => valorDeExemplo((string) $o));
if ($origens === [] || $origensRuins !== []) {
    $erros[] = 'allowed_origins ainda tem o domínio de exemplo — ponha o domínio real da página';
} else {
    $ok[] = 'allowed_origins: ' . implode(', ', $origens);
}

/* ---------- planos, matérias e bumps ---------- */
$planos   = (array) ($config['planos'] ?? []);
$materias = (array) ($config['materias'] ?? []);
$bumps    = (array) ($config['bumps'] ?? []);

$planos === [] ? $erros[] = 'nenhum plano configurado' : $ok[] = count($planos) . ' planos configurados';

foreach ($planos as $id => $plano) {
    if ((float) ($plano['valor'] ?? 0) <= 0) {
        $erros[] = "plano '$id' está sem valor";
    }
    if (empty($plano['product_id'])) {
        $avisos[] = "plano '$id' está sem product_id — a venda não fica vinculada ao produto nos relatórios da ZuckPay";
    }
}

// Todo bump do tipo matéria precisa existir na lista de matérias, senão o
// comprador paga por um item que a entrega não sabe identificar.
foreach ($bumps as $id => $bump) {
    if (($bump['tipo'] ?? 'extra') === 'materia' && !isset($materias[$id])) {
        $erros[] = "bump '$id' é do tipo materia mas não está em 'materias'";
    }
    if ((float) ($bump['valor'] ?? 0) <= 0) {
        $erros[] = "bump '$id' está sem valor";
    }
}

// E toda matéria deveria ter o bump correspondente, senão ela nunca aparece
// como item extra no checkout.
foreach ($materias as $id => $nome) {
    if (!isset($bumps[$id])) {
        $avisos[] = "matéria '$id' não tem order bump — ela não vai aparecer como item extra no checkout";
    }
}

/* ---------- a página e o config precisam falar dos mesmos ids ---------- */
$html = (string) @file_get_contents(__DIR__ . '/../index.html');
if ($html !== '') {
    preg_match_all("/\{ id: '([a-z0-9_]+)'/i", $html, $m);
    $naPagina = array_unique($m[1] ?? []);
    $faltando = array_diff($naPagina, array_keys($materias));
    $sobrando = array_diff(array_keys($materias), $naPagina);

    if ($faltando !== []) {
        $erros[] = 'a página oferece matérias que não existem no config: ' . implode(', ', $faltando);
    }
    if ($sobrando !== []) {
        $avisos[] = 'matérias no config que a página não mostra: ' . implode(', ', $sobrando);
    }
    if ($faltando === [] && $sobrando === [] && $naPagina !== []) {
        $ok[] = 'ids das matérias batem entre a página e o config';
    }
}

/* ---------- entrega ---------- */
$entrega = (array) ($config['entrega'] ?? []);
$remetente = (string) ($entrega['remetente_email'] ?? '');

if (valorDeExemplo($remetente) || !filter_var($remetente, FILTER_VALIDATE_EMAIL)) {
    $erros[] = 'entrega.remetente_email precisa ser um e-mail real do seu domínio';
} else {
    $ok[] = 'remetente da entrega: ' . $remetente;
}

$links = (array) ($entrega['links'] ?? []);
$precisamDeLink = array_keys($materias);
foreach ($planos as $id => $plano) {
    if (!empty($plano['inclui_materias'])) {
        $precisamDeLink[] = $id;
    }
}
foreach ($bumps as $id => $bump) {
    if (($bump['tipo'] ?? 'extra') !== 'materia') {
        $precisamDeLink[] = $id;
    }
}

$semLink = [];
foreach (array_unique($precisamDeLink) as $id) {
    $link = trim((string) ($links[$id] ?? ''));
    if ($link === '') {
        $semLink[] = $id;
    } elseif (!filter_var($link, FILTER_VALIDATE_URL)) {
        $erros[] = "entrega.links['$id'] não é uma URL válida: " . $link;
    }
}

if ($semLink === []) {
    $ok[] = 'todos os itens têm link de entrega';
} else {
    $erros[] = 'itens vendáveis sem link de entrega (o comprador paga e não recebe): ' . implode(', ', $semLink);
}

/* ---------- diagnóstico ligado em produção ---------- */
if (!empty($config['debug'])) {
    $avisos[] = "'debug' está ligado — desligue antes de anunciar";
}
if (((string) ($config['debug_token'] ?? '')) !== '') {
    $avisos[] = 'debug_token preenchido — api/diagnostico.php está acessível; apague ou esvazie depois de testar';
}

/* ---------- saída ---------- */
$cor = static fn (string $t, string $c): string => "\033[{$c}m{$t}\033[0m";

foreach ($ok as $linha) {
    echo $cor('[ok]   ', '32'), $linha, PHP_EOL;
}
foreach ($avisos as $linha) {
    echo $cor('[aviso]', '33'), ' ', $linha, PHP_EOL;
}
foreach ($erros as $linha) {
    echo $cor('[ERRO] ', '31'), ' ', $linha, PHP_EOL;
}

echo PHP_EOL;

if ($erros !== []) {
    echo $cor(count($erros) . ' erro(s) — a venda ainda não está pronta.', '31'), PHP_EOL;
    exit(1);
}

echo $cor('Configuração ok.', '32'),
     ' Agora rode o api/diagnostico.php para testar a API da ZuckPay de verdade.', PHP_EOL;
exit(0);
