<?php
/**
 * Copie este arquivo para config.php e preencha com os dados reais.
 * config.php está no .gitignore e NUNCA deve ser versionado.
 */

return [
    // Credenciais da ZuckPay (painel > Integrações > API keys)
    'client_id'     => getenv('ZUCKPAY_CLIENT_ID')     ?: 'seu_client_id',
    'client_secret' => getenv('ZUCKPAY_CLIENT_SECRET') ?: 'seu_client_secret',

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
    'api_base' => 'https://www.zuckpay.com.br/conta/v3/pix',

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
            'product_id'    => 0, // TROCAR pelo id do produto no painel da ZuckPay
        ],
        'completo' => [
            'nome'            => 'Mapas ENEM — Mini App Completo',
            'valor'           => 19.90,
            'inclui_materias' => true,
            'prefixo'         => 'ENEM',
            'product_id'      => 0, // TROCAR pelo id do produto no painel da ZuckPay
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

    // URL pública que a ZuckPay chama quando o pagamento muda de status.
    // Cadastre-a também em Integrações > Webhooks no painel.
    'webhook_url' => 'https://SEU-DOMINIO.com.br/api/webhook.php',

    /**
     * Webhook Secret — gerado no painel em Integrações > Webhook Secret.
     * É DIFERENTE do client_secret.
     *
     * Com ele preenchido, api/webhook.php valida o header
     * X-ZuckPay-Signature e recusa qualquer POST que não venha da ZuckPay.
     * Vazio, os postbacks continuam chegando sem assinatura e a validação
     * fica só por reconsulta à API.
     */
    'webhook_secret' => '',

    // Origens autorizadas a chamar estes endpoints (CORS).
    'allowed_origins' => [
        'https://SEU-DOMINIO.com.br',
        'https://www.SEU-DOMINIO.com.br',
    ],

    // Onde gravar o log de pagamentos confirmados.
    'log_path' => __DIR__ . '/../storage/pagamentos.log',

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
    'debug_token' => '',
];
