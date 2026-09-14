# Mapas ENEM

Página de vendas do mini app de mapas mentais do ENEM, com checkout PIX
integrado à **ZuckPay** e order bumps no próprio checkout.

```
index.html               página de vendas + modal de checkout
img/                     mockups do app e capas das matérias (SVG)
api/pix.php              cria a cobrança PIX (soma plano + order bumps)
api/status.php           consulta o status do pagamento
api/webhook.php          recebe a notificação da ZuckPay e dispara a entrega
api/entrega.php          monta e envia o e-mail com os acessos comprados
api/diagnostico.php      checagem da integração (protegido por token)
api/_bootstrap.php       validação, CORS, limite por IP e chamada à API
api/config.example.php   modelo de configuração
tools/checar-config.php      confere o config.php antes de abrir as vendas (CLI)
tools/testar-webhook.php     testa a validação de assinatura do webhook (CLI)
tools/reenviar-entrega.php   reenvia o e-mail de acesso de uma compra (CLI)
storage/                 pedidos, entregas e log de pagamentos (não versionado)
```

## A oferta

| Item | Preço | O que é |
|---|---|---|
| Plano `materia` | R$ 9,90 | mapas de **1 matéria**, escolhida na etapa 1 do checkout |
| Plano `completo` | R$ 19,90 | as 9 matérias + o questionário bônus |
| Order bump de matéria | R$ 9,90 cada | as outras matérias, somadas ao mesmo pedido |
| Order bump `redacao900` | R$ 15,99 | 10 Segredos da Redação 900+ (o destaque em dourado) |

O checkout tem três etapas: primeiro o comprador escolhe a matéria e marca os
bumps (com o total atualizando na hora), depois preenche os dados, e por fim
paga no PIX. Quem marca uma matéria extra vê um convite para trocar pelo plano
completo, que a partir daí sai mais barato.

Matérias disponíveis: Matemática, Física, Química, Biologia, História,
Geografia, Filosofia e Sociologia, Português e Literatura, Inglês e Espanhol.

## Colocar para vender (na ordem)

1. **Suba os arquivos** no seu domínio, com `index.html` na raiz e a pasta
   `api/` ao lado dela.
2. **Crie o config**: `cp api/config.example.php api/config.php`.
3. **Credenciais**: `client_id` e `client_secret` da tela *Integrações > API
   keys* da ZuckPay (ou as variáveis `ZUCKPAY_CLIENT_ID` /
   `ZUCKPAY_CLIENT_SECRET`).
4. **Produtos**: cadastre o produto no painel da ZuckPay e ponha o id em
   `product_id`, nos dois planos.
5. **Webhook**: aponte `webhook_url` para
   `https://SEU-DOMINIO/api/webhook.php` e cadastre essa mesma URL em
   *Integrações > Webhooks*. Gere o **Webhook Secret** e cole em
   `webhook_secret`.
6. **CORS**: troque `allowed_origins` pelo seu domínio (com e sem `www`).
7. **Entrega**: preencha `entrega.remetente_email` (um e-mail do seu domínio) e
   **os links de cada item** em `entrega.links`. Sem link, o comprador paga e
   não recebe.
8. **Confira**: `php tools/checar-config.php` — ele reprova o que ainda estiver
   faltando.
9. **Teste a API de verdade**: defina um `debug_token` e abra
   `/api/diagnostico.php?token=SEU_TOKEN` (ele cria uma cobrança de R$ 1,00).
   Depois, `php tools/testar-webhook.php`.
10. **Compre você mesmo** uma vez, de celular: pague o PIX de R$ 9,90, veja a
    tela virar "pagamento confirmado" e o e-mail chegar.
11. **Feche o diagnóstico**: apague `api/diagnostico.php` (ou esvazie
    `debug_token`) e deixe `debug => false`.

## Configuração

```bash
cp api/config.example.php api/config.php
```

Preencha `client_id`, `client_secret`, `webhook_url`, `webhook_secret` e
`allowed_origins`. O **Webhook Secret** é gerado no painel em
*Integrações > Webhook Secret* e é diferente do Client Secret; sem ele os
postbacks chegam sem assinatura e só resta a verificação por reconsulta.

O `api_base` precisa usar **exatamente o host da sua tela de Credenciais API**
(com ou sem `www`). Com o host errado a ZuckPay responde um redirecionamento,
e um POST autenticado não é reenviado no redirect — a cobrança nunca chega.
Este é o motivo mais comum de "o PIX não gera".
`api/config.php` está no `.gitignore` — **nunca** versione esse arquivo.
Em produção prefira variáveis de ambiente (`ZUCKPAY_CLIENT_ID` /
`ZUCKPAY_CLIENT_SECRET`), que o `config.example.php` já lê.

Preços, matérias e order bumps ficam em `config.php`, nas chaves `planos`,
`materias` e `bumps`. Os ids das matérias precisam bater com a constante
`MATERIAS` do `<script>` do `index.html` e com os nomes dos arquivos em `img/`.

Requisitos: PHP 8+ com a extensão cURL. O front chama `/api`; se a pasta não
ficar na raiz do site, ajuste a constante `API` no script de checkout do
`index.html`.

## Como funciona

1. O visitante escolhe o plano, a matéria e os order bumps.
2. `api/pix.php` valida os dados, **soma o total no servidor** e chama
   `POST /conta/v3/pix/qrcode`.
3. A página mostra o QR Code e o copia-e-cola, e consulta `api/status.php`
   a cada 4s até o pagamento ser confirmado.
4. A ZuckPay chama `api/webhook.php`, que confirma o pagamento e registra a
   venda com a composição do pedido (plano, matéria e bumps pagos).

## Entrega

`api/pix.php` grava a composição do pedido em `storage/pedido-<hash>.json` na
hora de criar a cobrança. O webhook recebe apenas o `external_id_client`, então
é esse arquivo que diz o que liberar:

```php
$pedido['plano']    // 'materia' ou 'completo'
$pedido['materia']  // id da matéria escolhida no plano de 1 matéria
$pedido['bumps']    // ids dos bumps pagos (outras matérias e/ou 'redacao900')
```

Com o pagamento confirmado, `api/entrega.php` monta o e-mail com **apenas os
itens pagos** e manda para o e-mail do checkout. Os links vêm de
`entrega.links` no `config.php`.

Três detalhes que evitam comprador sem acesso:

- **O envio sai uma vez só.** O marcador de entrega é criado antes do envio,
  então dois postbacks simultâneos não viram dois e-mails.
- **Falha é retentada.** Se o envio falhar, o marcador é apagado e a próxima
  notificação da ZuckPay tenta de novo — em vez de o comprador ficar sem nada.
- **Link faltando não some.** O item pago aparece no e-mail como "enviamos em
  instantes" e a pendência fica em `storage/entregas.log`. Se *nenhum* link
  estiver configurado, o e-mail não é enviado e fica tudo registrado para
  reenvio.

Para reenviar (comprador perdeu o e-mail, envio falhou, ou você só preencheu os
links depois da venda):

```bash
php tools/reenviar-entrega.php comprador@email.com
php tools/reenviar-entrega.php MOCK123456          # por transactionId
php tools/reenviar-entrega.php --pendentes         # tudo que ainda não saiu
```

Ele só reenvia compras com pagamento confirmado — um PIX gerado e não pago é
pulado.

O e-mail sai pelo `mail()` do PHP. Se a sua hospedagem não entregar bem
(mensagem caindo em spam), o caminho é apontar o `sendmail_path` do PHP para um
SMTP autenticado do seu domínio — o código não muda.

### storage/ não pode ser público

A pasta guarda nome e e-mail de quem comprou, em arquivos de nome previsível
(`pagamentos.log`, `entregas.log`). O código já cria um `.htaccess` negando
tudo, o que resolve no Apache. **Em nginx, bloqueie no server block:**

```nginx
location ^~ /storage/ { deny all; }
```

## Conferir o webhook

Depois de configurar o `webhook_secret`, rode **no servidor**:

```bash
php tools/testar-webhook.php
```

Ele monta POSTs assinados como a ZuckPay faz e confere que o endpoint aceita
o legítimo e recusa assinatura falsa, replay e requisição sem header:

```
[ok]   assinatura válida        (esperado: 200) -> HTTP 200
[ok]   assinatura falsa         (esperado: 401) -> HTTP 401
[ok]   replay de 10 minutos     (esperado: 401) -> HTTP 401
[ok]   sem header de assinatura (esperado: 401) -> HTTP 401
```

O segredo é lido do `config.php`; nunca passe por argumento, porque a linha de
comando fica visível para outros processos e no histórico do shell.

## Se o PIX não gerar

1. Defina um `debug_token` no `config.php` e abra:
   `https://seu-dominio.com.br/api/diagnostico.php?token=SEU_TOKEN`

   Ele confere PHP, cURL, credenciais (mascaradas), planos e faz uma cobrança
   de teste de R$ 1,00, mostrando a resposta real da ZuckPay. Os diagnósticos
   possíveis:

   | Resultado | Causa provável |
   |---|---|
   | `REDIRECIONAMENTO` | `api_base` com o host errado (`www` sobrando ou faltando). O diagnóstico mostra o endereço certo em `va_para` e testa a variante em `alternativa`. |
   | `IP BLOQUEADO` | Credenciais válidas, mas o IP do servidor não está na IP Whitelist da ZuckPay. O diagnóstico mostra o IP a liberar. |
   | `RATE LIMIT` | 5 tentativas por 30 minutos. Aguarde. |
   | `FALHA DE CONEXAO` | A hospedagem bloqueia conexões de saída, ou DNS. |
   | `NAO AUTORIZADO` | `client_id`/`client_secret` errados, revogados ou sem permissão para PIX. |
   | `ENDPOINT NAO ENCONTRADO` | `api_base` incorreto. |
   | `OK` | A integração funciona — o problema está no front ou no caminho `/api`. |

2. Se der `OK` no diagnóstico mas o botão da página continuar falhando, o
   problema é o caminho: abra o console do navegador (F12) e veja se o
   `POST /api/pix.php` retorna 404. Nesse caso a pasta `api/` não está onde o
   front espera — ajuste a constante `API` no script de checkout do `index.html`.

3. Ligue `'debug' => true` no `config.php` para que a página mostre o motivo
   real da falha em vez da mensagem genérica. **Desligue depois**, junto com o
   `debug_token`.

## Decisões de segurança

Estas escolhas são deliberadas — mudá-las abre brecha real:

- **O `client_secret` nunca vai para o navegador.** A documentação da ZuckPay
  mostra um exemplo em JavaScript com `btoa(clientId + ':' + clientSecret)`
  rodando no front. Seguir aquele exemplo publica a credencial no código-fonte
  da página: qualquer visitante poderia criar cobranças, listar transações e
  consultar o saldo da conta. Por isso a chamada é feita em PHP, no servidor.
- **O preço é definido no servidor.** `api/pix.php` recebe só os *ids* (plano,
  matéria e bumps) e soma os valores a partir do `config.php`. Um `valor`
  enviado pelo navegador é ignorado — sem isso, bastaria editar a requisição
  para comprar o completo por R$ 0,01.
- **Bump inválido não cobra nem entrega.** Id desconhecido, repetido, igual à
  matéria já escolhida ou de matéria já inclusa no plano completo é descartado
  em silêncio. Matéria fora da lista recusa a cobrança.
- **As respostas são filtradas.** A API devolve `amount_liquid`, e-mail do
  comprador e outros campos internos; os endpoints repassam apenas o necessário.
- **O webhook é verificado em duas camadas.** Primeiro a assinatura HMAC do
  header `X-ZuckPay-Signature` (`HMAC-SHA256("<timestamp>.<corpo_raw>",
  webhook_secret)`), com janela anti-replay de 5 minutos — prova que o POST
  veio da ZuckPay. Depois o `transactionId` é reconsultado na API — prova que
  o pagamento está pago agora. O corpo do POST nunca é a fonte da verdade, então
  um `"status":"PAID"` forjado não libera nada.
- **Entradas são validadas**: CPF com dígito verificador, e-mail, telefone e
  limite de tamanho. Parâmetros de atribuição passam por whitelist.
- **A entrega roda uma vez só.** A ZuckPay reenvia notificações. Antes de
  entregar, `webhook.php` cria um arquivo-marcador com `fopen(..., 'x')`, que
  falha se já existir — duas notificações simultâneas não passam as duas.
- **Cobranças não duplicam.** Cada abertura do checkout gera um `pedido`, usado
  como `external_id_client`. Clicar duas vezes devolve a mesma cobrança em vez
  de criar outra.
- **Há limite de cobranças por IP.** `limite_pix` no `config.php` (30 por 10
  minutos, por padrão) impede que um script gere cobranças em massa e estoure o
  rate limit da ZuckPay para quem está comprando de verdade. O limite é
  propositalmente folgado: no 4G brasileiro (CGNAT) muita gente sai pelo mesmo
  IP, e um limite apertado barraria comprador real. Atrás de Cloudflare, ligue
  `atras_de_proxy` para o limite enxergar o IP verdadeiro.
- **A entrega só manda o que foi pago.** A lista sai da composição gravada no
  servidor, não de nada que o navegador tenha enviado.

## Rate limit

A ZuckPay responde **429 após 5 tentativas em 30 minutos**. Por isso:

- `status.php` guarda a última consulta por 8 segundos, então várias abas ou
  recarregamentos não geram chamadas repetidas.
- Quando a API devolve 429, a resposta pede à página para esperar 30s em vez
  dos 5s normais; o front respeita esse intervalo.
- `webhook.php` responde na hora a `payment_refused`, `payment_pending` e
  `checkout_abandoned`, sem gastar uma chamada de verificação.

## Pendências

1. **`product_id`** — os dois planos estão com `product_id => 0` no
   `config.example.php`; preencha com o id do produto cadastrado na ZuckPay.
2. **Links de entrega** — preencha `entrega.links` no `config.php` com a URL de
   cada matéria, do plano completo e do guia de redação.
3. **Imagens** — as artes em `img/` são mockups feitos em SVG (telas do app,
   capas das matérias, questionário e guia de redação). Troque por prints reais
   do app quando ele existir.
4. **Depoimentos** — os da seção de feedback são de exemplo, marcados com
   `<!-- TROCAR -->`; substitua por comentários reais antes de anunciar.
5. **Pixels** — o `index.html` carrega o Meta Pixel com o id de outro produto
   (`<!-- TROCAR -->`); troque se este produto tiver pixel próprio.
6. **Desativar o diagnóstico** — depois de resolver, apague `api/diagnostico.php`
   ou deixe `debug_token` vazio (assim ele responde 404).
