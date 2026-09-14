/**
 * Mapas ENEM — mini app.
 *
 * O que a pessoa comprou vem no token do link (?a=...), validado pelo
 * api/conteudo.php. O token fica guardado no aparelho para o app abrir direto
 * da tela de início, sem precisar do e-mail de novo.
 */
(function () {
    'use strict';

    var API = '../api/conteudo.php';
    var CHAVE_TOKEN = 'mapasenem:acesso';
    var CHAVE_LIDOS = 'mapasenem:lidos';

    var elConteudo  = document.getElementById('conteudo');
    var elTitulo    = document.getElementById('titulo');
    var elVoltar    = document.getElementById('btn-voltar');
    var elSelo      = document.getElementById('selo');
    var elLamina    = document.getElementById('lamina');

    var estado = {
        token: '',
        demo: false,
        manifesto: null,
        materia: null,      // conteúdo carregado da matéria atual
        cache: {},          // materia id -> conteúdo
        pilha: []           // histórico de telas, para o botão voltar
    };

    /* ---------------- utilidades ---------------- */

    function guardar(chave, valor) {
        try { localStorage.setItem(chave, valor); } catch (e) { /* modo privado */ }
    }

    function recuperar(chave) {
        try { return localStorage.getItem(chave) || ''; } catch (e) { return ''; }
    }

    function lidos() {
        try { return JSON.parse(recuperar(CHAVE_LIDOS) || '{}'); } catch (e) { return {}; }
    }

    function marcarLido(materiaId, mapaId) {
        var marcas = lidos();
        marcas[materiaId + ':' + mapaId] = 1;
        guardar(CHAVE_LIDOS, JSON.stringify(marcas));
    }

    function limpar(el) {
        while (el.firstChild) { el.removeChild(el.firstChild); }
    }

    function criar(tag, atributos, texto) {
        var el = document.createElement(tag);
        Object.keys(atributos || {}).forEach(function (chave) {
            if (chave === 'class') { el.className = atributos[chave]; }
            else if (chave === 'style') { el.setAttribute('style', atributos[chave]); }
            else { el.setAttribute(chave, atributos[chave]); }
        });
        if (texto !== undefined) { el.textContent = texto; }
        return el;
    }

    function definirTopo(titulo, corMateria, selo) {
        elTitulo.textContent = titulo;
        elVoltar.hidden = estado.pilha.length === 0;
        document.documentElement.style.setProperty('--materia', corMateria || '#7C3AED');

        if (selo) { elSelo.textContent = selo; elSelo.hidden = false; }
        else { elSelo.hidden = true; }
    }

    function buscar(parametros) {
        var url = API + '?' + parametros;
        return fetch(url, { cache: 'no-store' }).then(function (r) {
            return r.json().then(function (dados) {
                if (!r.ok) { throw new Error(dados.erro || 'Falha ao carregar.'); }
                return dados;
            });
        });
    }

    function mostrarErro(mensagem, detalhe) {
        limpar(elConteudo);
        var caixa = criar('div', { class: 'aviso' });
        caixa.appendChild(criar('h2', {}, mensagem));
        caixa.appendChild(criar('p', {}, detalhe || ''));
        elConteudo.appendChild(caixa);
    }

    /* ---------------- navegação ---------------- */

    function irPara(tela, dados, semHistorico) {
        if (!semHistorico) { estado.pilha.push({ tela: tela, dados: dados }); }
        window.scrollTo(0, 0);
        telas[tela](dados);
    }

    elVoltar.addEventListener('click', function () {
        estado.pilha.pop();                       // sai da tela atual
        var anterior = estado.pilha[estado.pilha.length - 1];

        if (!anterior) { telas.biblioteca(); return; }
        telas[anterior.tela](anterior.dados);
    });

    /* ---------------- lâmina do ramo ---------------- */

    function abrirLamina(titulo, itens) {
        document.getElementById('lamina-titulo').textContent = titulo;
        var lista = document.getElementById('lamina-itens');
        limpar(lista);

        (itens || []).forEach(function (item) {
            lista.appendChild(criar('li', {}, item));
        });

        elLamina.hidden = false;
        document.body.style.overflow = 'hidden';
    }

    elLamina.addEventListener('click', function (e) {
        if (e.target.hasAttribute('data-fechar')) {
            elLamina.hidden = true;
            document.body.style.overflow = '';
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !elLamina.hidden) {
            elLamina.hidden = true;
            document.body.style.overflow = '';
        }
    });

    /* ---------------- telas ---------------- */

    var telas = {};

    /** Capa do cartão de matéria: um mini mapa decorativo. */
    function capaMini(cor) {
        var ns = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('viewBox', '0 0 200 80');
        svg.setAttribute('aria-hidden', 'true');

        [[52, 26], [148, 24], [40, 58], [150, 58]].forEach(function (p) {
            var linha = document.createElementNS(ns, 'path');
            linha.setAttribute('d', 'M100 42 Q' + ((100 + p[0]) / 2) + ' ' + p[1] + ' ' + p[0] + ' ' + p[1]);
            linha.setAttribute('stroke', 'rgba(255,255,255,.75)');
            linha.setAttribute('stroke-width', '2');
            linha.setAttribute('fill', 'none');
            svg.appendChild(linha);

            var ponto = document.createElementNS(ns, 'circle');
            ponto.setAttribute('cx', p[0]);
            ponto.setAttribute('cy', p[1]);
            ponto.setAttribute('r', '5');
            ponto.setAttribute('fill', 'rgba(255,255,255,.85)');
            svg.appendChild(ponto);
        });

        var centro = document.createElementNS(ns, 'circle');
        centro.setAttribute('cx', '100');
        centro.setAttribute('cy', '42');
        centro.setAttribute('r', '10');
        centro.setAttribute('fill', '#fff');
        svg.appendChild(centro);
        return svg;
    }

    telas.biblioteca = function () {
        estado.pilha = [];
        definirTopo('Seus materiais', '#7C3AED', estado.demo ? 'AMOSTRA' : '');
        limpar(elConteudo);

        var m = estado.manifesto;

        if (estado.demo) {
            var aviso = criar('div', { class: 'aviso' });
            aviso.appendChild(criar('h2', {}, 'Você está vendo a amostra grátis'));
            aviso.appendChild(criar('p', {}, 'É um mapa de exemplo. Quem compra recebe o link com todos os mapas da matéria e, no plano completo, o questionário.'));
            elConteudo.appendChild(aviso);
        }

        elConteudo.appendChild(criar('p', { class: 'secao-titulo' }, 'Matérias'));

        var grade = criar('div', { class: 'grade' });

        (m.materias || []).forEach(function (materia) {
            var cartao = criar('button', { class: 'cartao', type: 'button', style: '--materia:' + materia.cor });
            var faixa = criar('div', { class: 'faixa' });
            faixa.appendChild(capaMini(materia.cor));
            faixa.appendChild(criar('strong', {}, materia.nome));
            cartao.appendChild(faixa);
            cartao.appendChild(criar('div', { class: 'base' }, materia.mapas + (materia.mapas === 1 ? ' mapa mental' : ' mapas mentais')));
            cartao.addEventListener('click', function () { irPara('materia', materia); });
            grade.appendChild(cartao);
        });

        elConteudo.appendChild(grade);

        /* extras: redação e questionário */
        elConteudo.appendChild(criar('p', { class: 'secao-titulo' }, 'Extras'));

        if (m.redacao) {
            var botaoRedacao = criar('button', { class: 'linha', type: 'button' });
            var n1 = criar('div', { class: 'numero' }, '✍️');
            var t1 = criar('div', { class: 'texto' });
            t1.appendChild(criar('strong', {}, '10 Segredos da Redação 900+'));
            t1.appendChild(criar('span', {}, 'Argumentos universais, palavras-chave e repertórios'));
            botaoRedacao.appendChild(n1);
            botaoRedacao.appendChild(t1);
            botaoRedacao.appendChild(criar('span', { class: 'seta' }, '›'));
            botaoRedacao.addEventListener('click', function () { irPara('redacao'); });
            elConteudo.appendChild(botaoRedacao);
        }

        var botaoQuiz = criar('button', { class: 'linha', type: 'button' });
        var n2 = criar('div', { class: 'numero' }, '🧠');
        var t2 = criar('div', { class: 'texto' });
        t2.appendChild(criar('strong', {}, 'Questionário — será que você absorveu?'));
        t2.appendChild(criar('span', {}, m.questionario
            ? 'Perguntas no estilo da prova, com correção na hora'
            : 'Disponível no plano completo'));
        botaoQuiz.appendChild(n2);
        botaoQuiz.appendChild(t2);
        botaoQuiz.appendChild(criar('span', { class: 'seta' }, '›'));

        if (m.questionario) {
            botaoQuiz.addEventListener('click', function () { irPara('escolherQuiz'); });
        } else {
            botaoQuiz.className = 'linha';
            botaoQuiz.style.opacity = '.6';
            botaoQuiz.addEventListener('click', function () {
                abrirLamina('Bônus do plano completo', [
                    'O questionário vem no plano completo, junto com as 9 matérias.',
                    'Se quiser liberar, é só fazer o upgrade na página de vendas e o novo acesso chega no seu e-mail.'
                ]);
            });
        }
        elConteudo.appendChild(botaoQuiz);

        var rodape = criar('p', { class: 'rodape-app' });
        rodape.appendChild(criar('span', {}, 'Dica: toque em “adicionar à tela de início” para usar como app.'));
        elConteudo.appendChild(rodape);
    };

    telas.materia = function (materia) {
        definirTopo(materia.nome, materia.cor);
        limpar(elConteudo);
        elConteudo.appendChild(criar('div', { class: 'carregando' }, 'Carregando…'));

        carregarMateria(materia.id).then(function (conteudo) {
            limpar(elConteudo);

            if (conteudo.chamada) {
                var intro = criar('div', { class: 'aviso' });
                intro.appendChild(criar('h2', {}, conteudo.nome));
                intro.appendChild(criar('p', {}, conteudo.chamada));
                elConteudo.appendChild(intro);
            }

            elConteudo.appendChild(criar('p', { class: 'secao-titulo' }, 'Mapas mentais'));
            var marcas = lidos();

            (conteudo.mapas || []).forEach(function (mapa, i) {
                var linha = criar('button', {
                    class: 'linha' + (marcas[materia.id + ':' + mapa.id] ? ' feito' : ''),
                    type: 'button'
                });
                linha.appendChild(criar('div', { class: 'numero' }, String(i + 1)));

                var texto = criar('div', { class: 'texto' });
                texto.appendChild(criar('strong', {}, mapa.titulo));
                texto.appendChild(criar('span', {}, mapa.chamada || ((mapa.ramos || []).length + ' tópicos')));
                linha.appendChild(texto);
                linha.appendChild(criar('span', { class: 'seta' }, '›'));

                linha.addEventListener('click', function () {
                    irPara('mapa', { materia: materia, indice: i });
                });
                elConteudo.appendChild(linha);
            });

            if (conteudo.questoes && conteudo.questoes.length) {
                var botao = criar('button', { class: 'botao', type: 'button' },
                    'Fazer o questionário de ' + materia.nome);
                botao.addEventListener('click', function () {
                    irPara('quiz', { materia: materia });
                });
                elConteudo.appendChild(botao);
            }
        }).catch(function (erro) {
            mostrarErro('Não consegui abrir esta matéria', erro.message);
        });
    };

    telas.mapa = function (dados) {
        var conteudo = estado.cache[dados.materia.id];
        var mapa = conteudo.mapas[dados.indice];

        definirTopo(mapa.titulo, dados.materia.cor, (dados.indice + 1) + '/' + conteudo.mapas.length);
        limpar(elConteudo);
        marcarLido(dados.materia.id, mapa.id);

        var caixa = criar('div', { class: 'mapa-caixa' });
        var svg = Mapa.desenhar(mapa, dados.materia.cor, function (indiceRamo) {
            var ramo = mapa.ramos[indiceRamo];
            abrirLamina(ramo.titulo, ramo.itens);
        });
        caixa.appendChild(svg);

        var zoom = criar('div', { class: 'mapa-zoom' });
        var controles = Mapa.ativarZoom(caixa, svg);
        [['−', controles.menos], ['⟳', controles.reiniciar], ['+', controles.mais]].forEach(function (par) {
            var b = criar('button', { type: 'button', 'aria-label': 'zoom' }, par[0]);
            b.addEventListener('click', par[1]);
            zoom.appendChild(b);
        });
        caixa.appendChild(zoom);
        elConteudo.appendChild(caixa);

        var dica = criar('div', { class: 'mapa-dica' }, '👆 Toque em um ramo para ver o que cai dele. Arraste e use +/− para aproximar.');
        elConteudo.appendChild(dica);

        if (mapa.pegadinhas && mapa.pegadinhas.length) {
            var pegadinhas = criar('div', { class: 'bloco pegadinha' });
            pegadinhas.appendChild(criar('h3', {}, '⚠️ Pegadinhas da banca'));
            var lista = criar('ul', {});
            mapa.pegadinhas.forEach(function (p) { lista.appendChild(criar('li', {}, p)); });
            pegadinhas.appendChild(lista);
            elConteudo.appendChild(pegadinhas);
        }

        if (mapa.lembrete) {
            var lembrete = criar('div', { class: 'bloco lembrete' });
            lembrete.appendChild(criar('h3', {}, 'Leve isto para a prova'));
            lembrete.appendChild(criar('p', {}, mapa.lembrete));
            elConteudo.appendChild(lembrete);
        }

        var proximo = conteudo.mapas[dados.indice + 1];
        if (proximo) {
            var botao = criar('button', { class: 'botao', type: 'button' }, 'Próximo mapa: ' + proximo.titulo);
            botao.addEventListener('click', function () {
                estado.pilha.pop();
                irPara('mapa', { materia: dados.materia, indice: dados.indice + 1 });
            });
            elConteudo.appendChild(botao);
        } else if (conteudo.questoes && conteudo.questoes.length) {
            var botaoQuiz = criar('button', { class: 'botao', type: 'button' }, 'Terminou! Testar o que aprendeu →');
            botaoQuiz.addEventListener('click', function () { irPara('quiz', { materia: dados.materia }); });
            elConteudo.appendChild(botaoQuiz);
        }
    };

    telas.escolherQuiz = function () {
        definirTopo('Questionário', '#7C3AED');
        limpar(elConteudo);

        var aviso = criar('div', { class: 'aviso' });
        aviso.appendChild(criar('h2', {}, 'Escolha a matéria'));
        aviso.appendChild(criar('p', {}, 'São perguntas no estilo da prova. Cada erro mostra em qual mapa você precisa voltar.'));
        elConteudo.appendChild(aviso);

        (estado.manifesto.materias || []).forEach(function (materia) {
            var linha = criar('button', { class: 'linha', type: 'button' });
            linha.appendChild(criar('div', { class: 'numero' }, '?'));
            var texto = criar('div', { class: 'texto' });
            texto.appendChild(criar('strong', {}, materia.nome));
            texto.appendChild(criar('span', {}, 'Responder as questões'));
            linha.appendChild(texto);
            linha.appendChild(criar('span', { class: 'seta' }, '›'));
            linha.addEventListener('click', function () { irPara('quiz', { materia: materia }); });
            elConteudo.appendChild(linha);
        });
    };

    telas.quiz = function (dados) {
        definirTopo('Questionário · ' + dados.materia.nome, dados.materia.cor);
        limpar(elConteudo);
        elConteudo.appendChild(criar('div', { class: 'carregando' }, 'Carregando…'));

        carregarMateria(dados.materia.id).then(function (conteudo) {
            var questoes = conteudo.questoes || [];
            if (!questoes.length) {
                mostrarErro('Questionário indisponível', 'Esta matéria ainda não tem questões, ou ele não faz parte da sua compra.');
                return;
            }
            rodarQuiz(dados.materia, conteudo, questoes);
        }).catch(function (erro) {
            mostrarErro('Não consegui abrir o questionário', erro.message);
        });
    };

    function rodarQuiz(materia, conteudo, questoes) {
        var indice = 0;
        var acertos = 0;
        var errados = [];

        function desenharQuestao() {
            limpar(elConteudo);
            var questao = questoes[indice];

            var progresso = criar('div', { class: 'progresso' });
            progresso.appendChild(criar('span', {}, 'Questão ' + (indice + 1) + ' de ' + questoes.length));
            progresso.appendChild(criar('span', { style: 'color:var(--verde)' }, acertos + ' acertos'));
            elConteudo.appendChild(progresso);

            var barra = criar('div', { class: 'barra' });
            barra.appendChild(criar('div', { style: 'width:' + ((indice / questoes.length) * 100) + '%' }));
            elConteudo.appendChild(barra);

            var caixa = criar('div', { class: 'pergunta' });
            caixa.appendChild(criar('div', { class: 'fonte' }, materia.nome + (questao.assunto ? ' · ' + questao.assunto : '')));
            caixa.appendChild(criar('p', {}, questao.enunciado));
            elConteudo.appendChild(caixa);

            var botoes = [];
            questao.alternativas.forEach(function (texto, i) {
                var botao = criar('button', { class: 'alternativa', type: 'button' });
                botao.appendChild(criar('span', { class: 'letra' }, 'ABCDE'.charAt(i)));
                botao.appendChild(criar('span', { class: 'txt' }, texto));
                botao.addEventListener('click', function () { responder(i, botoes, questao); });
                botoes.push(botao);
                elConteudo.appendChild(botao);
            });
        }

        function responder(escolha, botoes, questao) {
            var acertou = escolha === questao.correta;
            if (acertou) { acertos++; } else { errados.push(questao); }

            botoes.forEach(function (botao, i) {
                botao.disabled = true;
                if (i === questao.correta) { botao.className = 'alternativa certa'; }
                else if (i === escolha) { botao.className = 'alternativa errada'; }
            });

            var explicacao = criar('div', { class: 'explicacao' });
            explicacao.appendChild(criar('strong', {}, acertou ? '✅ Isso! ' : '❌ Quase. '));
            explicacao.appendChild(document.createTextNode(questao.explicacao));
            elConteudo.appendChild(explicacao);

            if (questao.mapa) {
                var alvo = (conteudo.mapas || []).findIndex(function (m) { return m.id === questao.mapa; });
                if (alvo >= 0) {
                    var verMapa = criar('button', { class: 'botao secundario', type: 'button' },
                        'Revisar o mapa: ' + conteudo.mapas[alvo].titulo);
                    verMapa.addEventListener('click', function () {
                        irPara('mapa', { materia: materia, indice: alvo });
                    });
                    elConteudo.appendChild(verMapa);
                }
            }

            var seguir = criar('button', { class: 'botao', type: 'button' },
                indice + 1 < questoes.length ? 'Próxima questão' : 'Ver meu resultado');
            seguir.addEventListener('click', function () {
                indice++;
                if (indice < questoes.length) { desenharQuestao(); } else { desenharPlacar(); }
            });
            elConteudo.appendChild(seguir);
            seguir.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        function desenharPlacar() {
            limpar(elConteudo);
            var porcento = Math.round((acertos / questoes.length) * 100);

            var placar = criar('div', { class: 'placar' });
            placar.appendChild(criar('div', { class: 'nota' }, acertos + '/' + questoes.length));
            placar.appendChild(criar('div', { class: 'de' }, porcento + '% de acerto'));

            var titulo, recado;
            if (porcento >= 80) {
                titulo = 'Absorveu de verdade! 🎉';
                recado = 'Esse conteúdo está firme. Volte daqui a uns dias para não esfriar.';
            } else if (porcento >= 50) {
                titulo = 'Metade do caminho 💪';
                recado = 'Você entendeu o essencial. Revise os mapas abaixo e refaça o questionário.';
            } else {
                titulo = 'Bora revisar 📚';
                recado = 'Nada de desanimar: releia os mapas abaixo com calma e volte aqui. É assim que fixa.';
            }

            placar.appendChild(criar('h2', {}, titulo));
            placar.appendChild(criar('p', {}, recado));

            if (errados.length) {
                var revisar = criar('div', { class: 'revisar' });
                revisar.appendChild(criar('h3', {}, 'Volte nestes mapas'));
                var lista = criar('ul', {});
                var jaListados = {};

                errados.forEach(function (questao) {
                    var mapa = (conteudo.mapas || []).find(function (m) { return m.id === questao.mapa; });
                    var nome = mapa ? mapa.titulo : questao.assunto;
                    if (nome && !jaListados[nome]) {
                        jaListados[nome] = 1;
                        lista.appendChild(criar('li', {}, nome));
                    }
                });
                revisar.appendChild(lista);
                placar.appendChild(revisar);
            }

            elConteudo.appendChild(placar);

            var refazer = criar('button', { class: 'botao', type: 'button' }, 'Refazer o questionário');
            refazer.addEventListener('click', function () {
                indice = 0; acertos = 0; errados = [];
                desenharQuestao();
            });
            elConteudo.appendChild(refazer);

            var voltar = criar('button', { class: 'botao secundario', type: 'button' }, 'Voltar para os materiais');
            voltar.addEventListener('click', function () { telas.biblioteca(); });
            elConteudo.appendChild(voltar);
        }

        desenharQuestao();
    }

    telas.redacao = function () {
        definirTopo('Redação 900+', '#F59E0B');
        limpar(elConteudo);
        elConteudo.appendChild(criar('div', { class: 'carregando' }, 'Carregando…'));

        carregarMateria('redacao900').then(function (guia) {
            limpar(elConteudo);

            var intro = criar('div', { class: 'aviso' });
            intro.appendChild(criar('h2', {}, guia.nome));
            intro.appendChild(criar('p', {}, guia.chamada || ''));
            elConteudo.appendChild(intro);

            (guia.segredos || []).forEach(function (segredo, i) {
                var caixa = criar('div', { class: 'segredo' });
                var cabeca = criar('div', { class: 'cabeca' });
                cabeca.appendChild(criar('span', {}, String(i + 1)));
                cabeca.appendChild(criar('h3', {}, segredo.titulo));
                caixa.appendChild(cabeca);

                if (segredo.texto) { caixa.appendChild(criar('p', {}, segredo.texto)); }

                if (segredo.itens && segredo.itens.length) {
                    var lista = criar('ul', {});
                    segredo.itens.forEach(function (item) { lista.appendChild(criar('li', {}, item)); });
                    caixa.appendChild(lista);
                }

                if (segredo.modelo) {
                    caixa.appendChild(criar('p', { class: 'frase' }, segredo.modelo));
                }

                elConteudo.appendChild(caixa);
            });
        }).catch(function (erro) {
            mostrarErro('Não consegui abrir o guia', erro.message);
        });
    };

    /* ---------------- carregamento ---------------- */

    function carregarMateria(id) {
        if (estado.cache[id]) { return Promise.resolve(estado.cache[id]); }

        var parametros = estado.demo ? 'demo=1' : 'a=' + encodeURIComponent(estado.token) + '&m=' + encodeURIComponent(id);

        return buscar(parametros).then(function (dados) {
            estado.cache[id] = dados.materia;
            return dados.materia;
        });
    }

    function iniciar() {
        var url = new URLSearchParams(location.search);
        var tokenDaUrl = url.get('a') || '';
        estado.demo = url.has('demo');

        if (tokenDaUrl) { guardar(CHAVE_TOKEN, tokenDaUrl); }
        estado.token = tokenDaUrl || recuperar(CHAVE_TOKEN);

        if (estado.demo) {
            buscar('demo=1').then(function (dados) {
                estado.cache[dados.materia.id] = dados.materia;
                estado.manifesto = {
                    materias: [{
                        id: dados.materia.id,
                        nome: dados.materia.nome,
                        cor: dados.materia.cor,
                        mapas: (dados.materia.mapas || []).length
                    }],
                    questionario: !!(dados.materia.questoes || []).length,
                    redacao: false
                };
                telas.biblioteca();
            }).catch(function (erro) {
                mostrarErro('Amostra indisponível', erro.message);
            });
            return;
        }

        if (!estado.token) {
            mostrarErro(
                'Abra pelo link do seu e-mail',
                'O acesso vem no e-mail da compra, num link que já sabe o que você comprou. '
                + 'Se não achar, procure por “Mapas ENEM” na caixa de entrada e no spam.'
            );
            return;
        }

        buscar('a=' + encodeURIComponent(estado.token)).then(function (dados) {
            estado.manifesto = dados;
            telas.biblioteca();
        }).catch(function (erro) {
            mostrarErro('Não consegui liberar seu acesso', erro.message);
        });
    }

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('sw.js').catch(function () { /* segue sem offline */ });
        });
    }

    iniciar();
})();
