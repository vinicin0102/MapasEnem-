<?php
declare(strict_types=1);

/**
 * Entrega do produto: manda o e-mail com os acessos do que foi comprado.
 *
 * Este arquivo só define funções — quem chama é o api/webhook.php, depois de
 * confirmar o pagamento na API da ZuckPay.
 *
 * Os links de cada item ficam em config.php (`entrega.links`), com o mesmo id
 * usado nos planos, nas matérias e nos order bumps.
 */

/**
 * Monta a lista do que precisa ser entregue a partir da composição gravada
 * por api/pix.php.
 *
 * @return array<int, array{id:string, nome:string, link:string}>
 */
function itensDaEntrega(array $config, array $pedido): array
{
    $links    = is_array($config['entrega']['links'] ?? null) ? $config['entrega']['links'] : [];
    $materias = is_array($config['materias'] ?? null) ? $config['materias'] : [];
    $bumps    = is_array($config['bumps'] ?? null) ? $config['bumps'] : [];
    $planos   = is_array($config['planos'] ?? null) ? $config['planos'] : [];

    $itens = [];
    $incluir = static function (string $id, string $nome) use (&$itens, $links): void {
        $itens[$id] = [
            'id'   => $id,
            'nome' => $nome,
            'link' => trim((string) ($links[$id] ?? '')),
        ];
    };

    $planoId = (string) ($pedido['plano'] ?? '');
    $plano   = $planos[$planoId] ?? null;

    if ($plano !== null && !empty($plano['inclui_materias'])) {
        // Plano completo: um acesso só, com todas as matérias e o questionário.
        $incluir($planoId, (string) $plano['nome']);
    } elseif (($pedido['materia'] ?? null) !== null) {
        $id = (string) $pedido['materia'];
        $incluir($id, 'Mapas mentais de ' . ($materias[$id] ?? $id));
    }

    foreach ((array) ($pedido['bumps'] ?? []) as $bumpId) {
        $bumpId = (string) $bumpId;
        $incluir($bumpId, (string) ($bumps[$bumpId]['nome'] ?? $bumpId));
    }

    return array_values($itens);
}

/** Corpo do e-mail em HTML e em texto puro. */
function corpoDaEntrega(array $config, string $nome, array $itens): array
{
    $suporte    = trim((string) ($config['entrega']['suporte'] ?? ''));
    $primeiro   = trim(explode(' ', trim($nome))[0] ?? '');
    $saudacao   = $primeiro !== '' ? 'Oi, ' . $primeiro . '!' : 'Oi!';
    $comLink    = array_filter($itens, static fn (array $i): bool => $i['link'] !== '');
    $semLink    = array_filter($itens, static fn (array $i): bool => $i['link'] === '');

    $linhasHtml = '';
    foreach ($comLink as $item) {
        $linhasHtml .= sprintf(
            '<tr><td style="padding:10px 0;border-bottom:1px solid #EDE9FE">'
            . '<strong style="color:#1E1B4B;font-size:15px">%s</strong><br>'
            . '<a href="%s" style="display:inline-block;margin-top:8px;background:#7C3AED;color:#fff;'
            . 'text-decoration:none;font-weight:bold;padding:10px 18px;border-radius:8px;font-size:14px">'
            . 'Abrir agora</a></td></tr>',
            htmlspecialchars($item['nome'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($item['link'], ENT_QUOTES, 'UTF-8')
        );
    }

    foreach ($semLink as $item) {
        // Item pago sem link configurado: avisa em vez de sumir com ele.
        $linhasHtml .= sprintf(
            '<tr><td style="padding:10px 0;border-bottom:1px solid #EDE9FE">'
            . '<strong style="color:#1E1B4B;font-size:15px">%s</strong><br>'
            . '<span style="color:#6b7280;font-size:13px">Enviamos o acesso deste item em instantes.</span>'
            . '</td></tr>',
            htmlspecialchars($item['nome'], ENT_QUOTES, 'UTF-8')
        );
    }

    $rodape = $suporte !== ''
        ? sprintf(
            '<p style="color:#6b7280;font-size:13px">Qualquer coisa, fala com a gente: '
            . '<a href="%s" style="color:#7C3AED">%s</a></p>',
            htmlspecialchars($suporte, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($suporte, ENT_QUOTES, 'UTF-8')
        )
        : '';

    $html = '<!DOCTYPE html><html lang="pt-BR"><body style="margin:0;background:#F5F7FF;'
        . 'font-family:Arial,Helvetica,sans-serif;color:#1f2937">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:24px 12px">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
        . 'style="max-width:520px;background:#fff;border-radius:16px;padding:28px">'
        . '<tr><td>'
        . '<p style="margin:0 0 4px;color:#7C3AED;font-size:12px;font-weight:bold;letter-spacing:2px">MAPAS ENEM</p>'
        . '<h1 style="margin:0 0 16px;font-size:22px;color:#1E1B4B">Seu acesso está liberado 🎉</h1>'
        . '<p style="font-size:15px;line-height:1.5">' . htmlspecialchars($saudacao, ENT_QUOTES, 'UTF-8')
        . ' Pagamento confirmado! Aqui está tudo o que você comprou:</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $linhasHtml . '</table>'
        . '<p style="font-size:14px;line-height:1.5;margin-top:20px">Dica: abra o link no celular e toque em '
        . '<strong>“Adicionar à tela de início”</strong>. O mini app fica igual a um aplicativo, '
        . 'sem ocupar memória.</p>'
        . $rodape
        . '<p style="color:#9ca3af;font-size:12px;margin-top:20px">Guarde este e-mail: o acesso é vitalício.</p>'
        . '</td></tr></table></td></tr></table></body></html>';

    $texto = $saudacao . " Pagamento confirmado! Aqui está o seu acesso:\n\n";
    foreach ($itens as $item) {
        $texto .= '- ' . $item['nome'] . ($item['link'] !== '' ? "\n  " . $item['link'] : "\n  (enviamos em instantes)") . "\n";
    }
    $texto .= "\nAbra no celular e adicione à tela de início para usar como app.\n";
    if ($suporte !== '') {
        $texto .= "Suporte: " . $suporte . "\n";
    }
    $texto .= "\nGuarde este e-mail: o acesso é vitalício.\n";

    return [$html, $texto];
}

/** Envia o e-mail em multipart (texto + HTML). */
function enviarEmailEntrega(array $config, string $para, string $assunto, string $html, string $texto): bool
{
    $deNome  = (string) ($config['entrega']['remetente_nome'] ?? 'Mapas ENEM');
    $deEmail = (string) ($config['entrega']['remetente_email'] ?? '');
    $responder = (string) ($config['entrega']['responder_para'] ?? $deEmail);

    if ($deEmail === '' || !filter_var($deEmail, FILTER_VALIDATE_EMAIL)) {
        registrarErro('entrega', 'remetente_email inválido ou vazio no config.php');
        return false;
    }

    $limite = '=_' . bin2hex(random_bytes(12));

    // O nome do remetente vai codificado: acento cru no header quebra em
    // vários servidores de e-mail.
    $cabecalhos = [
        'From: ' . mb_encode_mimeheader($deNome, 'UTF-8') . ' <' . $deEmail . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $limite . '"',
    ];
    if ($responder !== '' && filter_var($responder, FILTER_VALIDATE_EMAIL)) {
        $cabecalhos[] = 'Reply-To: ' . $responder;
    }

    $corpo = "--{$limite}\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . $texto . "\r\n"
        . "--{$limite}\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . $html . "\r\n"
        . "--{$limite}--";

    $assuntoCodificado = mb_encode_mimeheader($assunto, 'UTF-8');
    $cabecalhosTexto   = implode("\r\n", $cabecalhos);

    // O -f alinha o envelope com o From e ajuda a não cair em spam. Parte das
    // hospedagens compartilhadas bloqueia esse parâmetro, então se o envio
    // falhar com ele, tenta de novo sem — melhor entregar sem o envelope
    // alinhado do que não entregar.
    $enviado = @mail($para, $assuntoCodificado, $corpo, $cabecalhosTexto, '-f' . $deEmail);

    if (!$enviado) {
        registrarErro('entrega', 'mail() com -f falhou para ' . $para . ' — tentando sem o parâmetro');
        $enviado = @mail($para, $assuntoCodificado, $corpo, $cabecalhosTexto);
    }

    if (!$enviado) {
        registrarErro('entrega', 'mail() falhou para ' . $para);
    }

    return $enviado;
}

/**
 * Entrega o pedido uma única vez.
 *
 * O marcador de entrega é criado ANTES do envio (para duas notificações
 * simultâneas não mandarem dois e-mails) e apagado se o envio falhar — assim a
 * próxima notificação da ZuckPay tenta de novo em vez de deixar o comprador
 * sem nada.
 *
 * @return array{status:string, itens?:array, sem_link?:array}
 */
function entregarPedido(array $config, string $transactionId, ?array $pedido, string $email, string $nome): array
{
    $resultado = static function (string $status, array $extra = []) use ($config, $transactionId, $email): array {
        $linha = ['transactionId' => $transactionId, 'email' => $email, 'status' => $status, 'em' => date('c')] + $extra;
        @file_put_contents(
            diretorioEstado($config) . '/entregas.log',
            json_encode($linha, JSON_UNESCAPED_UNICODE) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
        if ($status !== 'enviado' && $status !== 'ja_entregue') {
            registrarErro('entrega', $status . ' — ' . json_encode($linha, JSON_UNESCAPED_UNICODE));
        }
        return ['status' => $status] + $extra;
    };

    if ($pedido === null) {
        return $resultado('sem_pedido');
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $resultado('sem_email');
    }

    $itens = itensDaEntrega($config, $pedido);
    if ($itens === []) {
        return $resultado('sem_itens');
    }

    $semLink = array_values(array_map(
        static fn (array $i): string => $i['id'],
        array_filter($itens, static fn (array $i): bool => $i['link'] === '')
    ));

    // Nenhum link configurado: não adianta mandar e-mail vazio. Fica
    // registrado para envio manual e a próxima notificação tenta de novo.
    if (count($semLink) === count($itens)) {
        return $resultado('links_nao_configurados', ['itens' => array_column($itens, 'id')]);
    }

    $marcador = diretorioEstado($config) . '/entregue-' . sha1($transactionId) . '.flag';
    $handle = @fopen($marcador, 'x');
    if ($handle === false) {
        return $resultado('ja_entregue');
    }
    fwrite($handle, date('c'));
    fclose($handle);

    [$html, $texto] = corpoDaEntrega($config, $nome, $itens);
    $assunto = (string) ($config['entrega']['assunto'] ?? 'Seu acesso ao Mapas ENEM');

    if (!enviarEmailEntrega($config, $email, $assunto, $html, $texto)) {
        @unlink($marcador); // libera a retentativa
        return $resultado('falha_no_envio', ['itens' => array_column($itens, 'id')]);
    }

    return $resultado('enviado', [
        'itens'    => array_column($itens, 'id'),
        'sem_link' => $semLink,
    ]);
}
