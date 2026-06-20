<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pedí en línea — {{ config('empresa.nombre') }}</title>
    @livewireStyles
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, sans-serif; background: #0b1220; color: #e7ecf3; }
        .wrap { max-width: 1000px; margin: 0 auto; padding: 1rem; }
        .top { text-align: center; padding: 1.25rem 0; }
        .top h1 { margin: 0; font-size: 1.5rem; }
        .top p { margin: .25rem 0 0; color: #93a4bd; font-size: .9rem; }
        .grid { display: grid; grid-template-columns: 1fr; gap: 1rem; }
        @media (min-width: 820px) { .grid { grid-template-columns: 1.6fr 1fr; } }
        .card { background: #131c2e; border: 1px solid #243049; border-radius: .9rem; padding: 1rem; margin-bottom: 1rem; }
        .card h2 { margin: 0 0 .75rem; font-size: 1rem; }
        .menu-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: .5rem; }
        .tile { text-align: left; background: #1b2740; border: 1.5px solid #2b3a59; border-radius: .6rem; padding: .55rem .6rem; cursor: pointer; color: inherit; }
        .tile.sel { border-color: #f59e0b; background: rgba(245,158,11,.12); }
        .tile .n { font-weight: 600; font-size: .9rem; display: block; }
        .tile .p { font-size: .72rem; color: #93a4bd; }
        .badge { background: #f59e0b; color: #000; border-radius: 999px; padding: 0 .4rem; font-size: .7rem; font-weight: 700; }
        .btn { display: inline-block; background: #f59e0b; color: #111; border: none; border-radius: .55rem; padding: .6rem 1rem; font-weight: 700; cursor: pointer; font-size: .95rem; }
        .btn.full { width: 100%; }
        .btn.alt { background: transparent; color: #e7ecf3; border: 1px solid #2b3a59; }
        .btn.green { background: #10b981; color: #042; }
        .line { display: flex; justify-content: space-between; gap: .5rem; padding: .5rem; border: 1px solid #243049; border-radius: .5rem; margin-bottom: .4rem; }
        .line .x { background: none; border: none; color: #f87171; cursor: pointer; font-size: 1rem; }
        label { display: block; font-size: .8rem; color: #b9c6db; margin: .5rem 0 .2rem; }
        input, select, textarea { width: 100%; background: #0e1626; border: 1px solid #2b3a59; border-radius: .5rem; padding: .55rem; color: #e7ecf3; font-size: .95rem; }
        .seg { display: flex; gap: .4rem; flex-wrap: wrap; }
        .seg button { flex: 1; }
        .total { font-size: 1.3rem; font-weight: 800; }
        .err { background: rgba(239,68,68,.15); border: 1px solid #f87171; color: #fca5a5; padding: .6rem; border-radius: .5rem; font-size: .85rem; margin-bottom: .6rem; }
        .ok-box { text-align: center; padding: 2rem 1rem; }
        .ok-box .num { font-size: 2rem; font-weight: 800; color: #34d399; }
        .muted { color: #93a4bd; font-size: .8rem; }
    </style>
</head>
<body>
    {{ $slot }}
    @livewireScripts
</body>
</html>
