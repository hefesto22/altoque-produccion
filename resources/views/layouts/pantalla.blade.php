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
        .board { min-height: 100vh; display: flex; flex-direction: column; padding: 2.5vw 4vw 5vw; }
        .head { text-align: center; border-bottom: 4px solid #1f9d3a; padding-bottom: 1.5vw; margin-bottom: 2vw; perspective: 900px; }
        .head img { max-height: 18vw; max-width: 60%; width: auto; margin-bottom: 1vw; }

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
        .moneda img { max-height: 18vw; max-width: 60vw; width: auto; margin-bottom: 0; backface-visibility: hidden; display: block; }
        .moneda .reverso { position: absolute; top: 0; left: 0; width: 100%; height: 100%; max-height: none; max-width: none; transform: rotateY(180deg); }
        @keyframes logo-moneda { 0%, 70% { transform: rotateY(0deg); } 100% { transform: rotateY(360deg); } }
        @media (prefers-reduced-motion: reduce) { .moneda { animation: none; } }
        .head .titulo { font-size: 5.5vw; font-weight: 800; line-height: 1.1; }
        .head .sub { font-size: 3vw; color: #444; margin-top: .5vw; }

        .seccion { font-size: 4.4vw; font-weight: 800; margin: 2vw 0 .8vw; color: #1f9d3a; }
        .item { display: flex; align-items: flex-start; gap: 1.2vw; font-size: 4vw; line-height: 1.4; }
        .item .ck { color: #1f9d3a; font-weight: 900; }
        .precios { font-size: 3.8vw; font-weight: 800; margin-top: 2vw; background: #fff8e1; padding: 1.5vw 2vw; border-radius: 1.5vw; }
        .combo-h { font-size: 4.4vw; font-weight: 800; margin-top: 2vw; color: #1f9d3a; }
        .combo { font-size: 3.7vw; font-weight: 600; line-height: 1.5; }
        .pie-wrap { margin-top: auto; border-top: 3px dashed #ccc; padding-top: 1.5vw; }
        .pie { font-size: 3vw; margin-top: .6vw; line-height: 1.4; }

        /* Barra de control (oculta al bloquear). */
        .bar { position: fixed; top: 0; left: 0; right: 0; background: #111; color: #fff; display: flex; align-items: center; gap: .5rem; padding: .6rem 1rem; flex-wrap: wrap; z-index: 50; }
        .bar .sp { flex: 1; }
        .btn { background: #2b3a59; color: #fff; border: none; border-radius: .5rem; padding: .7rem 1.3rem; font-size: 1.2rem; cursor: pointer; font-weight: 700; }
        .btn.on { background: #f59e0b; color: #111; }
        .btn.lock { background: #dc2626; }
        .unlock { position: fixed; bottom: 16px; right: 16px; z-index: 50; background: rgba(0,0,0,.2); color: #fff; border: none; border-radius: 999px; width: 56px; height: 56px; font-size: 1.6rem; cursor: pointer; }
        .pushed { padding-top: 4rem; }

        /* Cinta de bebidas: banda con marquee JUSTO DEBAJO del encabezado —
           ocupa el lugar de la línea verde separadora (la cinta ES la línea).
           Dos copias idénticas del contenido y translateX(-50%) → bucle
           perfecto sin salto, todo en CSS (la TV no gasta en JS). */
        .cinta { background: #1f9d3a; color: #fff; overflow: hidden; padding: 1.1vw 0; margin: 0 -4vw 2vw; }
        .cinta-track { display: inline-flex; align-items: center; white-space: nowrap; will-change: transform; animation: cinta-scroll linear infinite; }
        .cinta-grupo { display: inline-flex; align-items: center; }
        .cinta-item { font-size: 3.2vw; font-weight: 800; padding: 0 1.2vw; }
        .cinta-item .precio { color: #ffe27a; margin-left: .6vw; }
        .cinta-sep { font-size: 2.4vw; opacity: .65; }
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
