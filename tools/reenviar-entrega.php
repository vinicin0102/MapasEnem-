<?php
declare(strict_types=1);

/**
 * Reenvia o e-mail de acesso de uma compra. Rode no servidor:
 *
 *   php tools/reenviar-entrega.php <transactionId|e-mail>
 *   php tools/reenviar-entrega.php --pendentes
 *
 * Serve para quando o comprador não achou o e-mail, quando o envio falhou, ou
 * quando os links de entrega só foram preenchidos depois da venda.
 *
 * Ele lê os pedidos gravados por api/pix.php em storage/ — nada é recriado nem
 * cobrado de novo.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../api/_bootstrap.php';
require __DIR__ . '/../api/entrega.php';

$config = carregarConfig();
$alvo   = trim((string) ($argv[1] ?? ''));

if ($alvo === '') {
    fwrite(STDERR, "uso: php tools/reenviar-entrega.php <transactionId|e-mail>\n"
                 . "     php tools/reenviar-entrega.php --pendentes\n");
    exit(1);
}

$arquivos = glob(diretorioEstado($config) . '/pedido-*.json') ?: [];
if ($arquivos === []) {
    fwrite(STDERR, "nenhum pedido registrado em " . diretorioEstado($config) . "\n");
    exit(1);
}

/** Pedidos que casam com o alvo informado. */
$pedidos = [];
foreach ($arquivos as $arquivo) {
    $pedido = json_decode((string) @file_get_contents($arquivo), true);
    if (!is_array($pedido)) {
        continue;
    }

    $casa = $alvo === '--pendentes'
        ? !is_file(diretorioEstado($config) . '/entregue-' . sha1((string) ($pedido['transactionId'] ?? '')) . '.flag')
        : (($pedido['transactionId'] ?? '') === $alvo
            || strcasecmp((string) ($pedido['email'] ?? ''), $alvo) === 0);

    if ($casa) {
        $pedidos[] = $pedido;
    }
}

if ($pedidos === []) {
    fwrite(STDERR, "nada encontrado para: $alvo\n");
    exit(1);
}

// Só reenvia o que já foi pago: o pedido existe desde a criação da cobrança,
// e um PIX não pago não pode virar entrega.
$enviados = 0;
foreach ($pedidos as $pedido) {
    $transactionId = (string) ($pedido['transactionId'] ?? '');
    $pago = is_file(diretorioEstado($config) . '/pago-' . sha1($transactionId) . '.flag');

    if (!$pago) {
        printf("[pulado]  %s (%s) — sem pagamento confirmado\n", $transactionId, (string) ($pedido['email'] ?? '?'));
        continue;
    }

    // Apaga o marcador para que a entrega possa sair de novo.
    @unlink(diretorioEstado($config) . '/entregue-' . sha1($transactionId) . '.flag');

    $r = entregarPedido(
        $config,
        $transactionId,
        $pedido,
        (string) ($pedido['email'] ?? ''),
        (string) ($pedido['nome'] ?? '')
    );

    printf(
        "[%s] %s (%s) — %s\n",
        $r['status'] === 'enviado' ? 'ok     ' : 'FALHOU ',
        $transactionId,
        (string) ($pedido['email'] ?? '?'),
        $r['status']
    );

    $enviados += $r['status'] === 'enviado' ? 1 : 0;
}

printf("\n%d entrega(s) reenviada(s).\n", $enviados);
exit($enviados > 0 ? 0 : 1);
