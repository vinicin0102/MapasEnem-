/**
 * Service worker do Mapas ENEM.
 *
 * A promessa da página é "funciona offline", então:
 * - a casca do app (html/css/js/ícones) é guardada na instalação;
 * - o conteúdo das matérias é guardado conforme a pessoa abre, e volta do
 *   cache quando o celular está sem internet.
 */

var VERSAO = 'mapasenem-v1';
var CASCA = [
    './',
    './index.html',
    './estilo.css',
    './app.js',
    './mapa.js',
    './manifest.json',
    './icone.svg'
];

self.addEventListener('install', function (evento) {
    evento.waitUntil(
        caches.open(VERSAO).then(function (cache) {
            return cache.addAll(CASCA);
        }).then(function () {
            return self.skipWaiting();
        })
    );
});

self.addEventListener('activate', function (evento) {
    evento.waitUntil(
        caches.keys().then(function (chaves) {
            return Promise.all(chaves.map(function (chave) {
                return chave === VERSAO ? null : caches.delete(chave);
            }));
        }).then(function () {
            return self.clients.claim();
        })
    );
});

self.addEventListener('fetch', function (evento) {
    var pedido = evento.request;

    if (pedido.method !== 'GET') {
        return;
    }

    var ehConteudo = pedido.url.indexOf('conteudo.php') !== -1;

    if (ehConteudo) {
        // Conteúdo: tenta a rede (pode ter mapa novo) e cai no cache se offline.
        evento.respondWith(
            fetch(pedido).then(function (resposta) {
                if (resposta.ok) {
                    var copia = resposta.clone();
                    caches.open(VERSAO).then(function (cache) { cache.put(pedido, copia); });
                }
                return resposta;
            }).catch(function () {
                return caches.match(pedido).then(function (guardada) {
                    return guardada || new Response(
                        JSON.stringify({ erro: 'Você está sem internet e este material ainda não foi baixado.' }),
                        { status: 503, headers: { 'Content-Type': 'application/json' } }
                    );
                });
            })
        );
        return;
    }

    // Casca: o cache responde na hora e a rede atualiza por trás.
    evento.respondWith(
        caches.match(pedido).then(function (guardada) {
            var daRede = fetch(pedido).then(function (resposta) {
                if (resposta.ok) {
                    var copia = resposta.clone();
                    caches.open(VERSAO).then(function (cache) { cache.put(pedido, copia); });
                }
                return resposta;
            }).catch(function () { return guardada; });

            return guardada || daRede;
        })
    );
});
