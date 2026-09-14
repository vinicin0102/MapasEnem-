/**
 * Desenha o mapa mental em SVG a partir dos dados da matéria.
 *
 * O mapa não é uma imagem pronta: ele é montado aqui, então o conteúdo mora em
 * conteudo/<materia>.json e o desenho se adapta ao número de ramos.
 */
(function (janela) {
    'use strict';

    var LARGURA = 820;
    var ALTURA  = 820;

    function svgEl(nome, atributos) {
        var el = document.createElementNS('http://www.w3.org/2000/svg', nome);
        Object.keys(atributos || {}).forEach(function (chave) {
            el.setAttribute(chave, atributos[chave]);
        });
        return el;
    }

    /** Quebra o título do ramo em até duas linhas, sem cortar palavra. */
    function quebrar(texto, limite) {
        var palavras = String(texto).split(' ');
        var linhas = [''];

        palavras.forEach(function (palavra) {
            var atual = linhas[linhas.length - 1];
            var candidata = atual ? atual + ' ' + palavra : palavra;

            if (candidata.length <= limite || atual === '') {
                linhas[linhas.length - 1] = candidata;
            } else {
                linhas.push(palavra);
            }
        });

        if (linhas.length > 2) {
            // Mais que duas linhas fica ilegível no celular: junta o resto.
            linhas = [linhas[0], linhas.slice(1).join(' ')];
        }
        return linhas;
    }

    function clarear(hex, quanto) {
        var n = parseInt(String(hex).replace('#', ''), 16);
        var r = Math.min(255, ((n >> 16) & 255) + quanto);
        var g = Math.min(255, ((n >> 8) & 255) + quanto);
        var b = Math.min(255, (n & 255) + quanto);
        return 'rgb(' + r + ',' + g + ',' + b + ')';
    }

    /**
     * Monta o SVG do mapa.
     *
     * @param {object} mapa    {central, ramos:[{titulo, itens}]}
     * @param {string} cor     cor da matéria
     * @param {function} aoTocar  recebe o índice do ramo tocado
     */
    function desenhar(mapa, cor, aoTocar) {
        var ramos = mapa.ramos || [];
        var svg = svgEl('svg', {
            viewBox: '0 0 ' + LARGURA + ' ' + ALTURA,
            role: 'img',
            'aria-label': 'Mapa mental de ' + (mapa.titulo || '')
        });

        var defs = svgEl('defs', {});
        var grad = svgEl('linearGradient', { id: 'grad-central', x1: '0', y1: '0', x2: '1', y2: '1' });
        grad.appendChild(svgEl('stop', { offset: '0%', 'stop-color': cor }));
        grad.appendChild(svgEl('stop', { offset: '100%', 'stop-color': clarear(cor, 46) }));
        defs.appendChild(grad);

        var sombra = svgEl('filter', { id: 'sombra-no', x: '-40%', y: '-40%', width: '180%', height: '180%' });
        sombra.appendChild(svgEl('feDropShadow', {
            dx: '0', dy: '2', stdDeviation: '3', 'flood-color': '#1E1B4B', 'flood-opacity': '.18'
        }));
        defs.appendChild(sombra);
        svg.appendChild(defs);

        var cx = LARGURA / 2;
        var cy = ALTURA / 2;
        var rx = 268;
        var ry = 250;
        var total = ramos.length || 1;

        // Ramos primeiro, para o nó central ficar por cima das linhas.
        ramos.forEach(function (ramo, i) {
            var ang = (-Math.PI / 2) + (2 * Math.PI * i / total) + (total % 2 === 0 ? 0.18 : 0);
            var px = cx + rx * Math.cos(ang);
            var py = cy + ry * Math.sin(ang);
            var corRamo = i % 2 === 0 ? cor : clarear(cor, 40);

            var c1x = cx + rx * 0.42 * Math.cos(ang) + 16;
            var c1y = cy + ry * 0.24 * Math.sin(ang);
            var c2x = px - 52 * Math.cos(ang);
            var c2y = py - 16 * Math.sin(ang);

            svg.appendChild(svgEl('path', {
                d: 'M' + cx + ' ' + cy + ' C' + c1x.toFixed(1) + ' ' + c1y.toFixed(1) + ' '
                   + c2x.toFixed(1) + ' ' + c2y.toFixed(1) + ' ' + px.toFixed(1) + ' ' + py.toFixed(1),
                fill: 'none',
                stroke: corRamo,
                'stroke-width': '3.4',
                'stroke-linecap': 'round',
                opacity: '.8'
            }));

            var linhas = quebrar(ramo.titulo, 17);
            var larguraNo = Math.max(132, Math.min(250, linhas[0].length * 9.4 + 40));
            var alturaNo = linhas.length > 1 ? 62 : 46;

            var grupo = svgEl('g', {
                class: 'ramo',
                filter: 'url(#sombra-no)',
                style: 'cursor:pointer',
                tabindex: '0',
                role: 'button',
                'aria-label': ramo.titulo + ': ver detalhes'
            });

            grupo.appendChild(svgEl('rect', {
                x: (px - larguraNo / 2).toFixed(1),
                y: (py - alturaNo / 2).toFixed(1),
                width: larguraNo,
                height: alturaNo,
                rx: 16,
                fill: '#fff',
                stroke: corRamo,
                'stroke-width': '2.4'
            }));

            linhas.forEach(function (linha, j) {
                var deslocamento = linhas.length > 1 ? (j === 0 ? -9 : 12) : 5;
                var texto = svgEl('text', {
                    x: px.toFixed(1),
                    y: (py + deslocamento).toFixed(1),
                    'text-anchor': 'middle',
                    'font-size': '15',
                    'font-weight': '700',
                    fill: '#1E1B4B',
                    'font-family': 'inherit'
                });
                texto.textContent = linha;
                grupo.appendChild(texto);
            });

            // Contador de itens do ramo: mostra que dá para abrir.
            var quantos = (ramo.itens || []).length;
            if (quantos) {
                var bolinha = svgEl('circle', {
                    cx: (px + larguraNo / 2 - 8).toFixed(1),
                    cy: (py - alturaNo / 2 + 8).toFixed(1),
                    r: 11,
                    fill: corRamo
                });
                var numero = svgEl('text', {
                    x: (px + larguraNo / 2 - 8).toFixed(1),
                    y: (py - alturaNo / 2 + 12).toFixed(1),
                    'text-anchor': 'middle',
                    'font-size': '12',
                    'font-weight': '800',
                    fill: '#fff',
                    'font-family': 'inherit'
                });
                numero.textContent = String(quantos);
                grupo.appendChild(bolinha);
                grupo.appendChild(numero);
            }

            grupo.addEventListener('click', function () { aoTocar(i); });
            grupo.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); aoTocar(i); }
            });

            svg.appendChild(grupo);
        });

        // Nó central
        var textoCentral = String(mapa.central || mapa.titulo || '').toUpperCase();
        var larguraCentral = Math.max(190, textoCentral.length * 12.5 + 46);

        var central = svgEl('g', { filter: 'url(#sombra-no)' });
        central.appendChild(svgEl('rect', {
            x: (cx - larguraCentral / 2).toFixed(1),
            y: (cy - 34).toFixed(1),
            width: larguraCentral,
            height: 68,
            rx: 34,
            fill: 'url(#grad-central)'
        }));

        var rotulo = svgEl('text', {
            x: cx,
            y: cy + 8,
            'text-anchor': 'middle',
            'font-size': '22',
            'font-weight': '900',
            fill: '#fff',
            'font-family': 'inherit'
        });
        rotulo.textContent = textoCentral;
        central.appendChild(rotulo);
        svg.appendChild(central);

        return svg;
    }

    /** Liga arrastar/pinçar/zoom no SVG já desenhado. */
    function ativarZoom(caixa, svg) {
        var vista = { x: 0, y: 0, l: LARGURA, a: ALTURA };
        var arrastando = false;
        var ultimo = null;
        var distanciaInicial = 0;

        function aplicar() {
            svg.setAttribute('viewBox', vista.x + ' ' + vista.y + ' ' + vista.l + ' ' + vista.a);
        }

        function ampliar(fator, foco) {
            var nova = Math.max(LARGURA * 0.45, Math.min(LARGURA * 1.35, vista.l * fator));
            var razao = nova / vista.l;
            var fx = foco ? foco.x : 0.5;
            var fy = foco ? foco.y : 0.5;

            vista.x += (vista.l - nova) * fx;
            vista.y += (vista.a - vista.a * razao) * fy;
            vista.l = nova;
            vista.a = ALTURA * (nova / LARGURA);
            aplicar();
        }

        function distancia(toques) {
            var dx = toques[0].clientX - toques[1].clientX;
            var dy = toques[0].clientY - toques[1].clientY;
            return Math.sqrt(dx * dx + dy * dy);
        }

        caixa.addEventListener('touchstart', function (e) {
            if (e.touches.length === 2) {
                distanciaInicial = distancia(e.touches);
            } else if (e.touches.length === 1) {
                arrastando = true;
                ultimo = { x: e.touches[0].clientX, y: e.touches[0].clientY };
            }
        }, { passive: true });

        caixa.addEventListener('touchmove', function (e) {
            if (e.touches.length === 2 && distanciaInicial) {
                e.preventDefault();
                var agora = distancia(e.touches);
                ampliar(distanciaInicial / agora, { x: 0.5, y: 0.5 });
                distanciaInicial = agora;
                return;
            }

            if (arrastando && ultimo && e.touches.length === 1) {
                var escala = vista.l / caixa.clientWidth;
                vista.x -= (e.touches[0].clientX - ultimo.x) * escala;
                vista.y -= (e.touches[0].clientY - ultimo.y) * escala;
                ultimo = { x: e.touches[0].clientX, y: e.touches[0].clientY };
                aplicar();
            }
        }, { passive: false });

        caixa.addEventListener('touchend', function () {
            arrastando = false;
            distanciaInicial = 0;
        });

        // No computador: arrastar com o mouse e zoom nos botões.
        caixa.addEventListener('mousedown', function (e) {
            arrastando = true;
            ultimo = { x: e.clientX, y: e.clientY };
        });
        window.addEventListener('mousemove', function (e) {
            if (!arrastando || !ultimo) return;
            var escala = vista.l / caixa.clientWidth;
            vista.x -= (e.clientX - ultimo.x) * escala;
            vista.y -= (e.clientY - ultimo.y) * escala;
            ultimo = { x: e.clientX, y: e.clientY };
            aplicar();
        });
        window.addEventListener('mouseup', function () { arrastando = false; });

        return {
            mais:    function () { ampliar(0.8); },
            menos:   function () { ampliar(1.25); },
            reiniciar: function () {
                vista = { x: 0, y: 0, l: LARGURA, a: ALTURA };
                aplicar();
            }
        };
    }

    janela.Mapa = { desenhar: desenhar, ativarZoom: ativarZoom };
})(window);
