<?php
declare(strict_types=1);

/**
 * Token de acesso do mini app.
 *
 * O comprador recebe UM link com um token que diz o que ele comprou. O token é
 * assinado com `acesso_secret` (config.php), então editar a lista de itens na
 * URL invalida a assinatura e o conteúdo não abre.
 *
 * Formato: base64url(json).base64url(hmac_sha256)
 *
 * Este arquivo só define funções — quem usa são api/conteudo.php (valida) e
 * api/entrega.php (gera o link do e-mail).
 */

function base64UrlCodificar(string $dados): string
{
    return rtrim(strtr(base64_encode($dados), '+/', '-_'), '=');
}

function base64UrlDecodificar(string $dados): string
{
    return (string) base64_decode(strtr($dados, '-_', '+/'), true);
}

/** Segredo usado para assinar os tokens. */
function segredoDeAcesso(array $config): string
{
    return (string) ($config['acesso_secret'] ?? '');
}

/**
 * Gera o token de acesso de uma compra.
 *
 * @param array<int, string> $itens ids do que foi comprado
 */
function gerarTokenAcesso(array $config, array $itens, string $pedido = ''): string
{
    $segredo = segredoDeAcesso($config);
    if ($segredo === '') {
        return '';
    }

    $carga = json_encode([
        'i' => array_values(array_unique($itens)),
        'p' => $pedido,
        'e' => time(),
    ], JSON_UNESCAPED_UNICODE);

    $corpo = base64UrlCodificar((string) $carga);
    $assinatura = base64UrlCodificar(hash_hmac('sha256', $corpo, $segredo, true));

    return $corpo . '.' . $assinatura;
}

/**
 * Valida o token e devolve os itens liberados.
 *
 * @return array{0:bool, 1:array<int,string>, 2:string} [válido, itens, motivo]
 */
function lerTokenAcesso(array $config, string $token): array
{
    $segredo = segredoDeAcesso($config);
    if ($segredo === '') {
        return [false, [], 'acesso_secret não configurado'];
    }

    $partes = explode('.', $token);
    if (count($partes) !== 2 || $partes[0] === '' || $partes[1] === '') {
        return [false, [], 'token malformado'];
    }

    $esperado = hash_hmac('sha256', $partes[0], $segredo, true);

    // hash_equals evita comparação que vaza tempo e, com ela, o segredo.
    if (!hash_equals($esperado, base64UrlDecodificar($partes[1]))) {
        return [false, [], 'assinatura inválida'];
    }

    $carga = json_decode(base64UrlDecodificar($partes[0]), true);
    if (!is_array($carga) || !is_array($carga['i'] ?? null)) {
        return [false, [], 'conteúdo do token inválido'];
    }

    $itens = array_values(array_filter(
        array_map(static fn ($i): string => is_string($i) ? $i : '', $carga['i']),
        static fn (string $i): bool => $i !== '' && preg_match('/^[a-z0-9_]{2,40}$/', $i) === 1
    ));

    return [true, $itens, ''];
}

/**
 * Expande o que o token libera para a lista real de matérias.
 *
 * 'completo' vale por todas as matérias mais o questionário bônus.
 *
 * @return array{materias:array<int,string>, questionario:bool, redacao:bool}
 */
function liberacoesDoAcesso(array $config, array $itens): array
{
    $todas = array_keys(is_array($config['materias'] ?? null) ? $config['materias'] : []);
    $completo = false;

    foreach ((array) ($config['planos'] ?? []) as $id => $plano) {
        if (!empty($plano['inclui_materias']) && in_array((string) $id, $itens, true)) {
            $completo = true;
        }
    }

    $materias = $completo
        ? $todas
        : array_values(array_intersect($todas, $itens));

    return [
        'materias'     => $materias,
        'questionario' => $completo,
        'redacao'      => $completo || in_array('redacao900', $itens, true),
    ];
}
