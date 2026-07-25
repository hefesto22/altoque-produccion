<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Menú del día — {{ config('app.name') }}</title>
    @livewireStyles
    <style>
        * { box-sizing: border-box; }
        [x-cloak] { display: none !important; }
        html, body { margin: 0; padding: 0; height: 100%; background: #fff; color: #111; font-family: 'Segoe UI', system-ui, sans-serif; }

        /* Pantalla VERTICAL (tótem). Una sola columna a todo lo alto. */
        /* --esc: factor de ajuste que calcula el script del pie para que el
           menú SIEMPRE quepa en la pantalla (en una TV nadie hace scroll).
           Vale 1 si el JS no corre, así el diseño queda igual que siempre. */
        .board { --esc: 1; min-height: 100vh; display: flex; flex-direction: column; padding: 2.5vw 4vw 5vw; }
        .head { text-align: center; border-bottom: 4px solid #1f9d3a; padding-bottom: 1.5vw; margin-bottom: 2vw; perspective: 900px; }
        .head img { max-height: calc(18vw * var(--esc)); max-width: 60%; width: auto; margin-bottom: 1vw; }

        /* Logo tipo MONEDA: descansa de frente y da una vuelta completa sobre
           su eje cada 6s. El giro ocupa el último 30% del ciclo (~1.8s) — se
           lee como el volteo de una moneda y no como un trompo mareador en una
           pantalla encendida todo el día.
           Son DOS copias del logo (cara y reverso, la de atrás pre-rotada
           180°) con backface-visibility: hidden. Sin eso, media vuelta se
           vería el logo en espejo, que parece un error de la pantalla. */
        .moneda { position: relative; display: inline-block; transform-style: preserve-3d; margin-bottom: 1vw; animation: logo-moneda 6s ease-in-out infinite; }
        /* max-width en vw, NO en %: dentro de un inline-block el porcentaje se
           resuelve contra un ancho que depende de la propia imagen y el logo
           colapsa a 0 (queda invisible). */
        .moneda img { max-height: calc(18vw * var(--esc)); max-width: 60vw; width: auto; margin-bottom: 0; backface-visibility: hidden; display: block; }
        .moneda .reverso { position: absolute; top: 0; left: 0; width: 100%; height: 100%; max-height: none; max-width: none; transform: rotateY(180deg); }
        @keyframes logo-moneda { 0%, 70% { transform: rotateY(0deg); } 100% { transform: rotateY(360deg); } }
        @media (prefers-reduced-motion: reduce) { .moneda { animation: none; } }
        .head .titulo { font-size: calc(5.5vw * var(--esc)); font-weight: 800; line-height: 1.1; }
        .head .sub { font-size: calc(3vw * var(--esc)); color: #444; margin-top: .5vw; }
        /* Servicio que se está mostrando: sin esto, con la pantalla bloqueada
           no hay manera de saber si es Desayuno, Almuerzo o Cena. */
        .head .sub .servicio { display: inline-block; margin-left: .8vw; padding: .15vw 1.2vw; border-radius: 999px;
            background: #1f9d3a; color: #fff; font-weight: 800; letter-spacing: .03em; }

        .seccion { font-size: calc(4.4vw * var(--esc)); font-weight: 800; margin: calc(2vw * var(--esc)) 0 calc(.8vw * var(--esc)); color: #1f9d3a; }
        .item { display: flex; align-items: flex-start; gap: 1.2vw; font-size: calc(4vw * var(--esc)); line-height: 1.4; }
        .item .ck { color: #1f9d3a; font-weight: 900; }
        .precios { font-size: calc(3.8vw * var(--esc)); font-weight: 800; margin-top: calc(2vw * var(--esc)); background: #fff8e1; padding: 1.5vw 2vw; border-radius: 1.5vw; }
        .combo-h { font-size: calc(4.4vw * var(--esc)); font-weight: 800; margin-top: calc(2vw * var(--esc)); color: #1f9d3a; }
        .combo { font-size: calc(3.7vw * var(--esc)); font-weight: 600; line-height: 1.5; }
        .pie-wrap { margin-top: auto; border-top: 3px dashed #ccc; padding-top: 1.5vw; }
        .pie { font-size: calc(3vw * var(--esc)); margin-top: .6vw; line-height: 1.4; }

        /* Barra de control (oculta al bloquear). */
        .bar { position: fixed; top: 0; left: 0; right: 0; background: #111; color: #fff; display: flex; align-items: center; gap: .5rem; padding: .6rem 1rem; flex-wrap: wrap; z-index: 50; }
        .bar .sp { flex: 1; }
        .btn { background: #2b3a59; color: #fff; border: none; border-radius: .5rem; padding: .7rem 1.3rem; font-size: 1.2rem; cursor: pointer; font-weight: 700; }
        .btn.on { background: #f59e0b; color: #111; }
        .btn.lock { background: #dc2626; }
        .unlock { position: fixed; bottom: 16px; right: 16px; z-index: 50; background: rgba(0,0,0,.2); color: #fff; border: none; border-radius: 999px; width: 56px; height: 56px; font-size: 1.6rem; cursor: pointer; }
        .pushed { padding-top: 4rem; }

        /* Aviso de que el menú sigue más abajo. Solo aparece los días de menú
           cargado, cuando achicar más dejaría la letra ilegible y se prefiere
           scroll. Se oculta al llegar al final. */
        /* Abajo a la IZQUIERDA: el botón de desbloquear vive a la derecha y al
           centro taparía el menú. Desaparece apenas el cliente desliza. */
        .mas-abajo { position: fixed; left: 16px; bottom: 16px; z-index: 45; display: none; align-items: center; gap: .4rem;
            padding: .45rem 1rem; border-radius: 999px; border: none; cursor: pointer;
            background: rgba(31,157,58,.95); color: #fff; font-size: .95rem; font-weight: 800;
            box-shadow: 0 6px 18px rgba(0,0,0,.28); animation: rebote 1.8s ease-in-out infinite; }
        body.con-scroll .mas-abajo { display: flex; }
        body.con-scroll.oculta-aviso .mas-abajo { display: none; }
        @keyframes rebote { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(5px); } }

        /* Cinta de bebidas: banda con marquee JUSTO DEBAJO del encabezado —
           ocupa el lugar de la línea verde separadora (la cinta ES la línea).
           Dos copias idénticas del contenido y translateX(-50%) → bucle
           perfecto sin salto, todo en CSS (la TV no gasta en JS). */
        .cinta { background: #1f9d3a; color: #fff; overflow: hidden; padding: 1.1vw 0; margin: 0 -4vw 2vw; }
        .cinta-track { display: inline-flex; align-items: center; white-space: nowrap; will-change: transform; animation: cinta-scroll linear infinite; }
        .cinta-grupo { display: inline-flex; align-items: center; }
        .cinta-item { font-size: calc(3.2vw * var(--esc)); font-weight: 800; padding: 0 1.2vw; }
        .cinta-item .precio { color: #ffe27a; margin-left: .6vw; }
        .cinta-sep { font-size: calc(2.4vw * var(--esc)); opacity: .65; }
        @keyframes cinta-scroll { from { transform: translateX(0); } to { transform: translateX(-50%); } }

        /* Con cinta, el encabezado suelta su línea verde: la banda de bebidas
           queda exactamente donde iba esa línea. */
        .head.sin-linea { border-bottom: none; padding-bottom: .8vw; margin-bottom: 0; }
    </style>
</head>
<body>
    {{ $slot }}
    @livewireScripts
    <script>
        // AJUSTE AUTOMÁTICO AL ALTO DE LA PANTALLA. En una TV nadie puede hacer
        // scroll: el menú tiene que caber entero, con 6 platillos o con 30.
        // Se busca (por bisección) el factor --esc más grande con el que el
        // contenido todavía entra; con poco menú crece hasta llenar la pantalla
        // y con mucho se achica hasta que entra todo.
        (() => {
            // Piso LEGIBLE: por debajo de esto no se lee desde la fila, así que
            // en vez de seguir achicando se deja que la pantalla haga scroll
            // (es táctil) y se avisa con el botón "Hay más menú".
            const MIN = 0.72;
            const MAX = 1.25;  // tope para que no se desfigure con poco contenido
            let pendiente = null;
            let ultimaFirma = '';

            // Firma barata de todo lo que puede cambiar el ajuste. Si no cambió,
            // NO se vuelve a medir.
            //
            // Sin esto, los días de menú largo (que no entra ni al mínimo) la
            // salida temprana de abajo nunca se cumplía y la bisección corría
            // ENTERA en cada refresco de 10 s: ~26 reflows forzados sobre un DOM
            // grande. En un tótem con hardware flojo eso congela la pantalla y se
            // come los toques — se sentía como que el candado no respondía.
            const firma = (board) => [
                board.textContent.length,
                board.children.length,
                board.className,
                window.innerHeight,
                window.innerWidth,
            ].join('|');

            // Alto natural del contenido: sin el min-height de 100vh, que si no
            // siempre mediría "la pantalla completa" y nunca detectaría el sobrante.
            const medir = (board) => {
                const previo = board.style.minHeight;
                board.style.minHeight = '0';
                const alto = board.scrollHeight;
                board.style.minHeight = previo;
                return alto;
            };

            const ajustar = () => {
                const board = document.querySelector('.board');
                if (! board) return;

                // Nada cambió desde el último ajuste: ni una sola medición.
                const actualFirma = firma(board);
                if (actualFirma === ultimaFirma) return;
                ultimaFirma = actualFirma;

                const disponible = window.innerHeight;
                const actual = medir(board);

                // Ya está bien ajustado (entra y casi no sobra): no recalcular.
                if (actual > 0 && actual <= disponible && actual >= disponible * 0.96) return;

                // 8 pasos: precisión de ~0.2% del rango, de sobra, y un tercio
                // menos de trabajo que 12 en pantallas lentas.
                let bajo = MIN, alto = MAX, mejor = MIN;

                for (let i = 0; i < 8; i++) {
                    const medio = (bajo + alto) / 2;
                    board.style.setProperty('--esc', medio);

                    if (medir(board) <= disponible) {
                        mejor = medio;
                        bajo = medio;
                    } else {
                        alto = medio;
                    }
                }

                board.style.setProperty('--esc', mejor);

                // ¿Ni siquiera al tamaño mínimo entra? Entonces hay scroll:
                // preferimos que se lea y se deslice, no letra de hormiga.
                document.body.classList.toggle('con-scroll', medir(board) > disponible + 1);
                actualizarAviso();
            };

            // El aviso solo sirve para quien no sabe que hay más: se va apenas
            // desliza un poco, y también al llegar al final del menú.
            const actualizarAviso = () => {
                const resto = document.documentElement.scrollHeight - window.innerHeight - window.scrollY;
                document.body.classList.toggle('oculta-aviso', window.scrollY > 40 || resto <= 8);
            };

            const programar = () => { clearTimeout(pendiente); pendiente = setTimeout(ajustar, 60); };

            // load (no solo DOMContentLoaded): el logo tiene que estar cargado,
            // si no se mide un alto que todavía no incluye la imagen.
            window.addEventListener('load', programar);
            window.addEventListener('resize', programar);
            window.addEventListener('scroll', actualizarAviso, { passive: true });

            // Bloquear/desbloquear muestra u oculta la barra superior y cambia
            // el alto disponible (lo hace Alpine, sin pasar por el servidor).
            document.addEventListener('click', (e) => {
                if (e.target.closest('.unlock, .bar .btn')) programar();
            });

            document.addEventListener('livewire:init', () => {
                // Tras cada refresco del menú, reajustar por si cambió la cantidad.
                Livewire.hook('commit', ({ succeed }) => succeed(() => programar()));
                programar();
            });
        })();
    </script>
    <script>
        // La TV queda abierta todo el día: cuando un poll de Livewire falla
        // (419 sesión vencida, 500 por deploy nuevo, o red caída), en vez de
        // quedar congelada, reintenta contra el server y se recarga sola en
        // cuanto responde OK. Así el menú de la pantalla siempre es el vigente.
        document.addEventListener('livewire:init', () => {
            let recuperando = false;

            const recuperar = () => {
                if (recuperando) return;
                recuperando = true;

                const intentar = () => fetch(window.location.href, { cache: 'no-store' })
                    .then((r) => r.ok ? window.location.reload() : setTimeout(intentar, 5000))
                    .catch(() => setTimeout(intentar, 5000));

                setTimeout(intentar, 2000);
            };

            Livewire.hook('request', ({ fail }) => {
                fail(({ preventDefault }) => {
                    preventDefault();
                    recuperar();
                });
            });
        });
    </script>
</body>
</html>
