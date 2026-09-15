<?php
declare(strict_types=1);

/**
 * Utilitários compartilhados pelos endpoints da API.
 * Este arquivo não responde nada sozinho.
 */

/**
 * Carrega a configuração.
 *
 * Em hospedagem tradicional, o arquivo é o api/config.php (fora do git, com as
 * credenciais). Em plataforma serverless (Vercel e afins) o disco é somente
 * leitura e não existe config.php: aí vale o config.example.php, que lê as
 * credenciais das variáveis de ambiente do projeto.
 */
function carregarConfig(): array
{
    foreach ([__DIR__ . '/config.php', __DIR__ . '/config.example.php'] as $caminho) {
        if (is_file($caminho)) {
            $config = require $caminho;
            if (is_array($config)) {
                return $config;
            }
        }
    }

    responder(500, ['erro' => 'Servidor não configurado.']);
}

function responder(int $status, array $dados): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

function aplicarCors(array $config): void
{
    $origem = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origem !== '' && in_array($origem, $config['allowed_origins'], true)) {
        header('Access-Control-Allow-Origin: ' . $origem);
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function corpoJson(): array
{
    $bruto = file_get_contents('php://input');
    $dados = json_decode((string) $bruto, true);
    return is_array($dados) ? $dados : [];
}

/** Registra no log de erros do servidor, sem devolver detalhes ao cliente. */
function registrarErro(string $contexto, string $detalhe): void
{
    error_log(sprintf('[zuckpay][%s] %s', $contexto, $detalhe));
}

/** Valida CPF incluindo os dígitos verificadores. */
function cpfValido(string $cpf): bool
{
    if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
        return false;
    }
    for ($posicao = 9; $posicao < 11; $posicao++) {
        $soma = 0;
        for ($i = 0; $i < $posicao; $i++) {
            $soma += (int) $cpf[$i] * (($posicao + 1) - $i);
        }
        $digito = ((10 * $soma) % 11) % 10;
        if ((int) $cpf[$posicao] !== $digito) {
            return false;
        }
    }
    return true;
}

/**
 * Chamada autenticada à API da ZuckPay.
 * O client_secret nunca sai daqui — não é devolvido ao navegador em hipótese alguma.
 *
 * Redirecionamentos NÃO são seguidos de propósito: seguir um 3xx num POST
 * autenticado reenviaria o Authorization para o host de destino, e o corpo
 * costuma ser descartado no caminho. Em vez disso devolvemos o Location para
 * que o api_base seja corrigido.
 *
 * @return array{0:int,1:array,2:string} [status http, corpo decodificado, destino do redirect]
 */
function chamarZuckpay(array $config, string $metodo, string $caminho, ?array $payload = null): array
{
    $url = rtrim($config['api_base'], '/') . $caminho;
    $autorizacao = 'Basic ' . base64_encode($config['client_id'] . ':' . $config['client_secret']);

    $cabecalhos = ['Accept: application/json', 'Authorization: ' . $autorizacao];
    $ch = curl_init($url);
    $opcoes = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];

    if ($metodo === 'POST') {
        $opcoes[CURLOPT_POST] = true;
        $opcoes[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $cabecalhos[] = 'Content-Type: application/json';
    }

    $opcoes[CURLOPT_HTTPHEADER] = $cabecalhos;
    $opcoes[CURLOPT_HEADER] = true;
    curl_setopt_array($ch, $opcoes);

    $bruto    = curl_exec($ch);
    $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $tamCab   = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $erroCurl = curl_error($ch);
    curl_close($ch);

    if ($bruto === false) {
        registrarErro('curl', $erroCurl);
        return [0, [], ''];
    }

    $cabecalhosResposta = substr((string) $bruto, 0, $tamCab);
    $resposta           = substr((string) $bruto, $tamCab);

    $destino = '';
    if ($status >= 300 && $status < 400
        && preg_match('/^Location:\s*(.+)$/mi', $cabecalhosResposta, $m)) {
        $destino = trim($m[1]);
        registrarErro('redirect', 'HTTP ' . $status . ' -> ' . $destino);
    }

    $decodificado = json_decode($resposta, true);
    if (!is_array($decodificado)) {
        registrarErro('resposta', 'HTTP ' . $status . ' com corpo não-JSON');
        return [$status, [], $destino];
    }

    return [$status, $decodificado, $destino];
}

/**
 * Valida o header X-ZuckPay-Signature.
 *
 * Formato: t=<timestamp>,v1=<hmac_sha256_hex>
 * Cálculo:  HMAC-SHA256("<timestamp>.<corpo_raw>", webhook_secret)
 *
 * @return array{0:bool,1:string} [válida, motivo da recusa]
 */
function assinaturaWebhookValida(string $header, string $corpoRaw, string $segredo): array
{
    if ($header === '') {
        return [false, 'header X-ZuckPay-Signature ausente'];
    }

    parse_str(strtr($header, ',', '&'), $partes);
    $ts = (string) ($partes['t'] ?? '');
    $v1 = (string) ($partes['v1'] ?? '');

    if ($ts === '' || $v1 === '' || !ctype_digit($ts)) {
        return [false, 'header malformado'];
    }

    // Anti-replay: rejeita assinaturas velhas ou com data no futuro.
    if (abs(time() - (int) $ts) > 300) {
        return [false, 'timestamp fora da janela de 5 minutos'];
    }

    $esperado = hash_hmac('sha256', $ts . '.' . $corpoRaw, $segredo);

    if (!hash_equals($esperado, $v1)) {
        return [false, 'assinatura não confere'];
    }

    return [true, ''];
}

/**
 * Diretório de estado (cache, pedidos e registro de vendas).
 *
 * A pasta fica dentro do site, e guarda e-mail e nome de quem comprou — com
 * nomes previsíveis como pagamentos.log e entregas.log. Por isso, ao criá-la,
 * já entram um .htaccess negando tudo (Apache, o caso da maioria das
 * hospedagens) e um index.html vazio contra listagem de diretório.
 *
 * Em nginx não existe .htaccess: bloqueie no server block, por exemplo
 *   location ^~ /storage/ { deny all; }
 */
function diretorioEstado(array $config): string
{
    $dir = dirname((string) ($config['log_path'] ?? __DIR__ . '/../storage/pagamentos.log'));

    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }

    $htaccess = $dir . '/.htaccess';
    if (!is_file($htaccess)) {
        // Apache 2.4 usa Require; o bloco legado cobre 2.2.
        @file_put_contents(
            $htaccess,
            "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n"
        );
    }

    $indice = $dir . '/index.html';
    if (!is_file($indice)) {
        @file_put_contents($indice, '');
    }

    return $dir;
}

/**
 * Cache curto das consultas de status.
 *
 * A ZuckPay aplica rate limit (429). Como a página consulta em intervalos
 * curtos enquanto o comprador paga, várias abas ou recarregamentos poderiam
 * estourar o limite. Guardamos a última resposta por alguns segundos.
 *
 * @return array|null resposta em cache, ou null se não houver/estiver velha
 */
function cacheStatusLer(array $config, string $transactionId, int $validadeSegundos = 8): ?array
{
    $arquivo = diretorioEstado($config) . '/status-' . sha1($transactionId) . '.json';

    if (!is_file($arquivo) || (time() - (int) filemtime($arquivo)) > $validadeSegundos) {
        return null;
    }

    $dados = json_decode((string) @file_get_contents($arquivo), true);
    return is_array($dados) ? $dados : null;
}

function cacheStatusGravar(array $config, string $transactionId, array $dados): void
{
    $arquivo = diretorioEstado($config) . '/status-' . sha1($transactionId) . '.json';
    @file_put_contents($arquivo, json_encode($dados, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/**
 * Idempotência da entrega: diz se este transactionId já foi registrado.
 *
 * A ZuckPay reenvia a mesma notificação, então a entrega do produto precisa
 * acontecer uma única vez. Usa um arquivo-marcador criado atomicamente:
 * duas notificações simultâneas não conseguem passar as duas.
 */
function transacaoJaRegistrada(array $config, string $transactionId): bool
{
    $marcador = diretorioEstado($config) . '/pago-' . sha1($transactionId) . '.flag';

    // 'x' falha se o arquivo já existir — é o teste e a criação num passo só.
    $handle = @fopen($marcador, 'x');

    if ($handle === false) {
        return true;
    }

    fwrite($handle, date('c'));
    fclose($handle);
    return false;
}

/**
 * Limite de cobranças por IP.
 *
 * Sem isso, um script conseguiria disparar centenas de cobranças na conta —
 * cada uma é uma chamada à ZuckPay, que tem rate limit próprio (429) e passa a
 * recusar as cobranças de quem está comprando de verdade.
 *
 * Guarda um contador por IP numa janela deslizante simples.
 *
 * @return bool true quando o IP estourou o limite
 */
function limiteDeTentativasEstourado(array $config, string $ip): bool
{
    $tentativas = (int) ($config['limite_pix']['tentativas'] ?? 30);
    $janela     = (int) ($config['limite_pix']['janela'] ?? 600);

    if ($tentativas <= 0 || $janela <= 0 || $ip === '') {
        return false; // limite desligado no config
    }

    $arquivo = diretorioEstado($config) . '/ip-' . sha1($ip) . '.json';
    $agora   = time();

    $registros = [];
    if (is_file($arquivo)) {
        $lido = json_decode((string) @file_get_contents($arquivo), true);
        $registros = is_array($lido) ? $lido : [];
    }

    // Descarta o que já saiu da janela.
    $registros = array_values(array_filter(
        $registros,
        static fn ($momento): bool => is_int($momento) && ($agora - $momento) < $janela
    ));

    if (count($registros) >= $tentativas) {
        return true;
    }

    $registros[] = $agora;
    @file_put_contents($arquivo, json_encode($registros), LOCK_EX);
    return false;
}

/** IP do visitante, considerando proxy/CDN quando o config autoriza. */
function ipDoVisitante(array $config): string
{
    if (!empty($config['atras_de_proxy'])) {
        // Cloudflare e afins: o IP real vem no header, o REMOTE_ADDR é do proxy.
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP'] as $header) {
            $valor = trim((string) ($_SERVER[$header] ?? ''));
            if (filter_var($valor, FILTER_VALIDATE_IP)) {
                return $valor;
            }
        }
        $encaminhado = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0]);
        if (filter_var($encaminhado, FILTER_VALIDATE_IP)) {
            return $encaminhado;
        }
    }

    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}

/**
 * Soma quanto uma composição de pedido deveria ter custado.
 *
 * Serve para conferir o valor realmente pago contra o que o pedido diz —
 * ninguém paga R$ 9,90 e recebe o pacote completo.
 */
function totalDoPedido(array $config, array $pedido): float
{
    $planos = is_array($config['planos'] ?? null) ? $config['planos'] : [];
    $bumps  = is_array($config['bumps'] ?? null) ? $config['bumps'] : [];

    $total = (float) ($planos[(string) ($pedido['plano'] ?? '')]['valor'] ?? 0);

    foreach ((array) ($pedido['bumps'] ?? []) as $bumpId) {
        $total += (float) ($bumps[(string) $bumpId]['valor'] ?? 0);
    }

    return round($total, 2);
}

/**
 * Composição da compra dentro do external_id_client.
 *
 * O webhook recebe da ZuckPay apenas o external_id_client. Em hospedagem
 * tradicional a composição está gravada em storage/, mas em serverless o disco
 * é apagado entre execuções — então ela viaja também no próprio id, assim:
 *
 *   ENEM-mat-bio-hisred-a1b2c3d4e5f6
 *   |    |   |   |      |
 *   |    |   |   |      +-- id do pedido
 *   |    |   |   +--------- bumps, em códigos de 3 letras colados
 *   |    |   +------------- matéria escolhida (ou 0)
 *   |    +----------------- plano
 *   +---------------------- prefixo do produto
 *
 * Três letras por item porque os ids em uso são únicos nos 3 primeiros
 * caracteres (mat, fis, qui, bio, his, geo, fil, por, lin, red).
 */
function codigoDoItem(string $id): string
{
    return substr($id, 0, 3);
}

function montarExternalId(
    string $prefixo,
    string $planoId,
    string $materiaId,
    array $bumpIds,
    string $pedido
): string {
    $bumps = '';
    foreach ($bumpIds as $bumpId) {
        $bumps .= codigoDoItem((string) $bumpId);
    }

    return implode('-', [
        $prefixo,
        codigoDoItem($planoId),
        $materiaId !== '' ? codigoDoItem($materiaId) : '0',
        $bumps !== '' ? $bumps : '0',
        $pedido,
    ]);
}

/**
 * Lê de volta a composição gravada no external_id_client.
 *
 * @return array{plano:string, materia:?string, bumps:array<int,string>}|null
 */
function lerExternalId(array $config, string $externalId): ?array
{
    $partes = explode('-', $externalId);
    if (count($partes) < 5) {
        return null;
    }

    $mapa = static function (array $ids): array {
        $saida = [];
        foreach ($ids as $id) {
            $saida[codigoDoItem((string) $id)] = (string) $id;
        }
        return $saida;
    };

    $planos   = $mapa(array_keys(is_array($config['planos'] ?? null) ? $config['planos'] : []));
    $materias = $mapa(array_keys(is_array($config['materias'] ?? null) ? $config['materias'] : []));
    $bumps    = $mapa(array_keys(is_array($config['bumps'] ?? null) ? $config['bumps'] : []));

    $planoId = $planos[$partes[1]] ?? '';
    if ($planoId === '') {
        return null;
    }

    $bumpsLidos = [];
    if ($partes[3] !== '0') {
        foreach (str_split($partes[3], 3) as $codigo) {
            if (isset($bumps[$codigo])) {
                $bumpsLidos[] = $bumps[$codigo];
            }
        }
    }

    return [
        'plano'   => $planoId,
        'materia' => $partes[2] !== '0' ? ($materias[$partes[2]] ?? null) : null,
        'bumps'   => $bumpsLidos,
    ];
}

/**
 * Guarda a composição do pedido (plano, matéria e order bumps) no momento em
 * que a cobrança é criada.
 *
 * O webhook só recebe o external_id_client; é aqui que fica registrado o que
 * exatamente foi comprado, para a entrega saber quais materiais enviar.
 */
function registrarPedido(array $config, string $externalId, array $dados): void
{
    $arquivo = diretorioEstado($config) . '/pedido-' . sha1($externalId) . '.json';
    @file_put_contents($arquivo, json_encode($dados, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/** Lê a composição gravada por registrarPedido(). */
function lerPedido(array $config, string $externalId): ?array
{
    $arquivo = diretorioEstado($config) . '/pedido-' . sha1($externalId) . '.json';

    if (!is_file($arquivo)) {
        return null;
    }

    $dados = json_decode((string) @file_get_contents($arquivo), true);
    return is_array($dados) ? $dados : null;
}

/** Acrescenta a venda ao log de pagamentos. */
function registrarPagamento(array $config, array $dados): void
{
    diretorioEstado($config);
    @file_put_contents(
        (string) $config['log_path'],
        json_encode($dados, JSON_UNESCAPED_UNICODE) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}
