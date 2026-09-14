<?php
declare(strict_types=1);

/**
 * Serve o conteúdo do mini app para quem pagou.
 *
 * GET /api/conteudo.php?a=TOKEN            -> manifesto: o que o token libera
 * GET /api/conteudo.php?a=TOKEN&m=biologia -> conteúdo daquela matéria
 * GET /api/conteudo.php?demo=1             -> amostra grátis (1 mapa)
 *
 * A pasta conteudo/ fica bloqueada no servidor (.htaccess), então este é o
 * único caminho até os mapas — sem token válido, ninguém baixa o material.
 */

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/acesso.php';

$config = carregarConfig();
aplicarCors($config);

$pastaConteudo = __DIR__ . '/../conteudo';

/** Lê um arquivo de conteúdo pelo id, sem deixar o id escapar da pasta. */
function lerConteudo(string $pasta, string $id): ?array
{
    if (preg_match('/^[a-z0-9_]{2,40}$/', $id) !== 1) {
        return null;
    }

    $arquivo = $pasta . '/' . $id . '.json';
    if (!is_file($arquivo)) {
        return null;
    }

    $dados = json_decode((string) file_get_contents($arquivo), true);
    return is_array($dados) ? $dados : null;
}

/* ---------------- amostra grátis ---------------- */

if (isset($_GET['demo'])) {
    $amostra = lerConteudo($pastaConteudo, 'amostra');
    if ($amostra === null) {
        responder(404, ['erro' => 'Amostra indisponível.']);
    }
    responder(200, ['demo' => true, 'materia' => $amostra]);
}

/* ---------------- acesso pago ---------------- */

$token = (string) ($_GET['a'] ?? '');
[$valido, $itens, $motivo] = lerTokenAcesso($config, $token);

if (!$valido) {
    // Mensagem genérica para o comprador; o motivo real fica no log.
    registrarErro('conteudo', 'token recusado: ' . $motivo);
    responder(401, ['erro' => 'Link de acesso inválido ou expirado. Confira o e-mail da compra.']);
}

$liberado = liberacoesDoAcesso($config, $itens);
$materiasConfig = is_array($config['materias'] ?? null) ? $config['materias'] : [];

/* ---------------- uma matéria ---------------- */

$materiaPedida = (string) ($_GET['m'] ?? '');

if ($materiaPedida !== '') {
    $ehRedacao = $materiaPedida === 'redacao900';

    $temAcesso = $ehRedacao
        ? $liberado['redacao']
        : in_array($materiaPedida, $liberado['materias'], true);

    if (!$temAcesso) {
        responder(403, ['erro' => 'Este material não faz parte da sua compra.']);
    }

    $conteudo = lerConteudo($pastaConteudo, $materiaPedida);
    if ($conteudo === null) {
        registrarErro('conteudo', 'arquivo ausente para ' . $materiaPedida);
        responder(404, ['erro' => 'Conteúdo indisponível no momento.']);
    }

    // O questionário é o bônus do plano completo: sai da resposta de quem
    // comprou só a matéria avulsa.
    if (!$liberado['questionario']) {
        unset($conteudo['questoes']);
    }

    responder(200, [
        'materia'      => $conteudo,
        'questionario' => $liberado['questionario'],
    ]);
}

/* ---------------- manifesto ---------------- */

$materias = [];
foreach ($liberado['materias'] as $id) {
    $conteudo = lerConteudo($pastaConteudo, $id);
    $materias[] = [
        'id'     => $id,
        'nome'   => (string) ($materiasConfig[$id] ?? $id),
        'cor'    => (string) ($conteudo['cor'] ?? '#7C3AED'),
        'mapas'  => is_array($conteudo['mapas'] ?? null) ? count($conteudo['mapas']) : 0,
        'chamada' => (string) ($conteudo['chamada'] ?? ''),
    ];
}

responder(200, [
    'materias'     => $materias,
    'questionario' => $liberado['questionario'],
    'redacao'      => $liberado['redacao'],
    'atualizado'   => date('c'),
]);
