<?php
/**
 * Copie este arquivo para config.php e preencha com os dados reais.
 * config.php está no .gitignore e NUNCA deve ser versionado.
 */

/**
 * JÁ TEM OUTRA PÁGINA ZUCKPAY FUNCIONANDO NESTE SERVIDOR?
 *
 * Aponte aqui o caminho do config.php dela (o do Arritmias, por exemplo) e
 * este arquivo reaproveita o que já está comprovadamente funcionando:
 * client_id, client_secret, api_base e webhook_secret.
 *
 * Assim a credencial fica em um lugar só: mudou lá, muda aqui também, e você
 * não copia segredo de um arquivo para outro.
 *
 * Exemplos de caminho:
 *   '/home/usuario/public_html/api/config.php'
 *   __DIR__ . '/../../arritmias/api/config.php'
 *
 * Deixe vazio para preencher as credenciais direto neste arquivo.
 */
$configQueJaFunciona = '';

$herdado = [];
if ($configQueJaFunciona !== '' && is_file($configQueJaFunciona)) {
    $lido = require $configQueJaFunciona;
    $herdado = is_array($lido) ? $lido : [];
}

/**
 * Plataforma serverless (Vercel, Netlify Functions, Lambda).
 *
 * Lá o disco do projeto é somente leitura: só /tmp aceita escrita. E não
 * existe config.php (ele fica fora do git), então as credenciais vêm das
 * variáveis de ambiente do projeto.
 */
$serverless = getenv('VERCEL') !== false || getenv('AWS_LAMBDA_FUNCTION_NAME') !== false;

/** O próprio domínio que está respondendo, para não precisar repetir no config. */
$dominio = (string) ($_SERVER['HTTP_HOST'] ?? '');
$esteSite = $dominio !== '' ? 'https://' . $dominio : '';

return [
    /**
     * Credenciais da ZuckPay (painel > Integrações > API keys).
     *
     * Ordem: variável de ambiente > config herdado acima > valor escrito aqui.
     */
    'client_id'     => getenv('ZUCKPAY_CLIENT_ID')     ?: ($herdado['client_id']     ?? 'seu_client_id'),
    'client_secret' => getenv('ZUCKPAY_CLIENT_SECRET') ?: ($herdado['client_secret'] ?? 'seu_client_secret'),

    /**
     * Base da API.
     *
     * ATENÇÃO: as duas fontes da ZuckPay divergem. A documentação completa
     * usa https://www.zuckpay.com.br; a tela de Credenciais API mostra
     * https://zuckpay.com.br (sem www). Com o host errado a API responde um
     * redirecionamento e o POST autenticado não é reenviado — a cobrança
     * nunca chega.
     *
     * Rode o api/diagnostico.php: ele detecta o redirect e testa as duas
     * variantes, dizendo qual funciona na sua conta.
     *
     * Para testes: https://www.zuckpay.com.br/conta/dev/api/pix
     */
    'api_base' => $herdado['api_base'] ?? 'https://www.zuckpay.com.br/conta/v3/pix',

    /**
     * Planos vendidos na página.
     *
     * O preço fica AQUI, no servidor. O navegador envia apenas o id do plano
     * ("materia" ou "completo") — um valor vindo do cliente é sempre ignorado.
     *
     * exige_materia   -> o comprador precisa escolher uma matéria da lista
     *                    'materias' abaixo; sem ela a cobrança é recusada.
     * inclui_materias -> o plano já entrega todas as matérias, então os order
     *                    bumps de matéria são ignorados (não cobra de novo por
     *                    algo que já está incluso).
     * prefixo         -> prefixo do external_id_client, para separar os
     *                    pedidos deste produto nos relatórios.
     * product_id      -> id do produto cadastrado no painel da ZuckPay. É
     *                    opcional na API, mas preenchê-lo vincula a venda ao
     *                    produto nos relatórios.
     */
    'planos' => [
        'materia' => [
            'nome'          => 'Mapas ENEM — Mini App de 1 Matéria',
            'valor'         => 9.90,
            'exige_materia' => true,
            'prefixo'       => 'ENEM',
            'product_id'    => 593187, // mesmo produto usado na outra página; troque se cadastrar um só do ENEM
        ],
        'completo' => [
            'nome'            => 'Mapas ENEM — Mini App Completo',
            'valor'           => 19.90,
            'inclui_materias' => true,
            'prefixo'         => 'ENEM',
            'product_id'      => 593187, // mesmo produto usado na outra página; troque se cadastrar um só do ENEM
        ],
    ],

    /**
     * Matérias vendidas no plano de 1 matéria.
     *
     * Os ids precisam ser os mesmos usados no <script> do index.html
     * (constante MATERIAS), nos order bumps do tipo 'materia' abaixo e nos
     * nomes dos arquivos em img/ (capa-<id>.svg).
     */
    'materias' => [
        'matematica' => 'Matemática',
        'fisica'     => 'Física',
        'quimica'    => 'Química',
        'biologia'   => 'Biologia',
        'historia'   => 'História',
        'geografia'  => 'Geografia',
        'filosofia'  => 'Filosofia e Sociologia',
        'portugues'  => 'Português e Literatura',
        'linguas'    => 'Inglês e Espanhol',
    ],

    /**
     * Order bumps — itens que o comprador adiciona no checkout.
     *
     * O valor fica AQUI, no servidor: o navegador só envia a lista de ids.
     * tipo 'materia' = mapas de outra matéria (some quando o plano já inclui
     * todas); tipo 'extra' = produto avulso, sempre disponível.
     */
    'bumps' => [
        'matematica' => ['nome' => 'Mapas de Matemática',             'valor' => 9.90, 'tipo' => 'materia'],
        'fisica'     => ['nome' => 'Mapas de Física',                 'valor' => 9.90, 'tipo' => 'materia'],
        'quimica'    => ['nome' => 'Mapas de Química',                'valor' => 9.90, 'tipo' => 'materia'],
        'biologia'   => ['nome' => 'Mapas de Biologia',               'valor' => 9.90, 'tipo' => 'materia'],
        'historia'   => ['nome' => 'Mapas de História',               'valor' => 9.90, 'tipo' => 'materia'],
        'geografia'  => ['nome' => 'Mapas de Geografia',              'valor' => 9.90, 'tipo' => 'materia'],
        'filosofia'  => ['nome' => 'Mapas de Filosofia e Sociologia', 'valor' => 9.90, 'tipo' => 'materia'],
        'portugues'  => ['nome' => 'Mapas de Português e Literatura', 'valor' => 9.90, 'tipo' => 'materia'],
        'linguas'    => ['nome' => 'Mapas de Inglês e Espanhol',      'valor' => 9.90, 'tipo' => 'materia'],

        'redacao900' => ['nome' => '10 Segredos da Redação 900+',     'valor' => 15.99, 'tipo' => 'extra'],
    ],

    /**
     * Entrega automática.
     *
     * Quando o pagamento é confirmado, api/webhook.php manda um e-mail com os
     * acessos do que foi comprado. Cada id abaixo é o mesmo usado nos planos,
     * nas matérias e nos bumps — preencha com o link do material (área de
     * membros, Google Drive, Notion, o mini app publicado...).
     *
     * Item pago sem link não some: o comprador recebe o e-mail avisando que
     * aquele acesso chega em seguida, e a pendência fica em
     * storage/entregas.log para você mandar na mão. Se NENHUM link estiver
     * preenchido, o e-mail não é enviado e a entrega fica registrada como
     * pendente — a próxima notificação da ZuckPay tenta de novo.
     */
    'entrega' => [
        'remetente_nome'  => 'Mapas ENEM',
        'remetente_email' => 'contato@SEU-DOMINIO.com.br', // precisa ser do seu domínio
        'responder_para'  => 'contato@SEU-DOMINIO.com.br',
        'assunto'         => 'Seu acesso ao Mapas ENEM chegou 🎉',
        'suporte'         => '', // opcional: link do WhatsApp/e-mail que aparece no rodapé

        'links' => [
            // Plano completo: um acesso com todas as matérias + questionário
            'completo'   => '',

            // Mini app de cada matéria (plano de 1 matéria e order bumps)
            'matematica' => '',
            'fisica'     => '',
            'quimica'    => '',
            'biologia'   => '',
            'historia'   => '',
            'geografia'  => '',
            'filosofia'  => '',
            'portugues'  => '',
            'linguas'    => '',

            // Order bump da redação
            'redacao900' => '',
        ],
    ],

    /**
     * Limite de cobranças por IP (janela deslizante).
     *
     * Evita que um script gere cobranças em massa e estoure o rate limit da
     * ZuckPay para quem está comprando de verdade.
     *
     * Não aperte demais: no 4G brasileiro (CGNAT) e em escolas, muita gente
     * sai pelo MESMO IP — um limite baixo barraria compradores reais. 30 por
     * 10 minutos já mata script e sobra folga para o tráfego normal. Use
     * tentativas => 0 para desligar.
     */
    'limite_pix' => [
        'tentativas' => 30,
        'janela'     => 600, // segundos
    ],

    /**
     * Ligue se o site estiver atrás de Cloudflare ou outro proxy — sem isso
     * o limite acima enxerga o IP do proxy e conta todo mundo junto.
     */
    'atras_de_proxy' => false,

    /**
     * URL pública que a ZuckPay chama quando o pagamento muda de status.
     * Cadastre-a também em Integrações > Webhooks no painel.
     *
     * Por padrão é montada com o domínio que está respondendo, então funciona
     * sem editar nada. Para fixar, use a variável ZUCKPAY_WEBHOOK_URL.
     */
    'webhook_url' => getenv('ZUCKPAY_WEBHOOK_URL')
        ?: ($esteSite !== '' ? $esteSite . '/api/webhook.php' : 'https://SEU-DOMINIO.com.br/api/webhook.php'),

    /**
     * Webhook Secret — gerado no painel em Integrações > Webhook Secret.
     * É DIFERENTE do client_secret.
     *
     * Com ele preenchido, api/webhook.php valida o header
     * X-ZuckPay-Signature e recusa qualquer POST que não venha da ZuckPay.
     * Vazio, os postbacks continuam chegando sem assinatura e a validação
     * fica só por reconsulta à API.
     */
    'webhook_secret' => getenv('ZUCKPAY_WEBHOOK_SECRET') ?: ($herdado['webhook_secret'] ?? ''),

    /**
     * Origens autorizadas a chamar estes endpoints (CORS).
     *
     * O próprio domínio entra sozinho, então página e API no mesmo lugar
     * funcionam sem ajuste. Para liberar outro domínio (página estática em um
     * host e API em outro), use ZUCKPAY_ORIGENS com as URLs separadas por
     * vírgula.
     */
    'allowed_origins' => array_values(array_filter(array_unique(array_merge(
        $esteSite !== '' ? [$esteSite] : [],
        array_map('trim', explode(',', (string) getenv('ZUCKPAY_ORIGENS'))),
        ['https://SEU-DOMINIO.com.br']
    )))),

    /**
     * Onde gravar pedidos, entregas e o log de pagamentos.
     *
     * Em serverless o projeto é somente leitura, então vai para /tmp — que é
     * apagado entre execuções. Por isso a composição do pedido também viaja no
     * external_id_client da cobrança: o webhook não depende deste arquivo.
     */
    'log_path' => $serverless
        ? sys_get_temp_dir() . '/mapasenem/pagamentos.log'
        : __DIR__ . '/../storage/pagamentos.log',

    /**
     * Modo diagnóstico.
     *
     * Com true, os endpoints devolvem a mensagem de erro real da ZuckPay em
     * vez da mensagem genérica — útil para descobrir por que o PIX não gera.
     * DESLIGUE depois de resolver: mensagens de erro podem revelar detalhes
     * da conta.
     */
    'debug' => false,

    /**
     * Token do api/diagnostico.php. Troque por uma string aleatória.
     * Sem ele o diagnóstico responde 404.
     */
    'debug_token' => getenv('ZUCKPAY_DEBUG_TOKEN') ?: '',

    /**
     * Segredo que assina o token de acesso do mini app.
     *
     * Gere uma string aleatória longa (ex.: `openssl rand -hex 32`) e guarde em
     * MAPASENEM_ACESSO_SECRET. Trocar este valor invalida os links já enviados.
     */
    'acesso_secret' => getenv('MAPASENEM_ACESSO_SECRET') ?: '',
];
