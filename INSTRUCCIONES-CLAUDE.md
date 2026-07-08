# INSTRUCCIONES DE DESARROLLO — SISTEMA AL TOQUE (POS Restaurante Buffet)
# Leer y aplicar en cada sesión de desarrollo
# Laravel 12 · Filament v4 (Schemas) · PostgreSQL 16 · PHP 8.4 · Redis 7

---

## ⚡ ESTADO REAL DEL SISTEMA — LO PRIMERO QUE DEBO SABER

**Este sistema NO es un proyecto en construcción. Está EN PRODUCCIÓN con datos fiscales reales.**

| Ambiente | URL | Naturaleza |
|---|---|---|
| **Producción** | `altoque.cloud` | CAI real, correlativos SAR reales, ventas reales. Intocable sin proceso. |
| **Staging** | `pruebas.altoque.cloud` | Copia funcional en el mismo VPS (Hostinger KVM4). Todo cambio pasa por aquí primero. |

Runbooks de operación: `docs/DEPLOY.md` (deploy doble prod+staging) y `docs/AMBIENTE-PRUEBAS.md`. Los sigo, no los improviso.

**Consecuencia en la mentalidad:** ya no diseño desde cero — **evoluciono un sistema vivo que factura todos los días**. Cada cambio se evalúa con tres preguntas, en este orden:

1. **¿Rompe algo que hoy funciona en producción?** (compatibilidad hacia atrás, migraciones aditivas, datos existentes)
2. **¿Aguanta 10x el volumen actual sin rediseño?** (500–1,000 ventas/día hoy → millones de filas en `venta_items` en pocos años)
3. **¿El desglose fiscal sigue cuadrando al centavo?** (ISV, correlativos, períodos declarados)

---

## STACK OFICIAL — VERSIONES EXACTAS INSTALADAS (composer.lock / package.json)

| Capa | Paquete | Versión instalada |
|---|---|---|
| Lenguaje | PHP | 8.4 (constraint `^8.4`) |
| Framework | `laravel/framework` | **12.58.0** |
| Panel admin | `filament/filament` | **4.11.1** (Schemas, namespaces v4) |
| Frontend reactivo | `livewire/livewire` | 3.7.15 |
| Base de datos | PostgreSQL | **16** |
| Cache / Sesión / Queue | Redis 7 vía `predis/predis` | 2.4.1 |
| Colas | `laravel/horizon` | 5.46.0 |
| PDFs | `spatie/browsershot` + puppeteer | 5.2.3 / puppeteer ^25.1 |
| Excel | `maatwebsite/excel` | 3.1.68 |
| Permisos | `bezhansalleh/filament-shield` + `spatie/laravel-permission` | 4.2.0 / 6.25.0 |
| Auditoría | `spatie/laravel-activitylog` | 4.12.3 |
| Backups | `spatie/laravel-backup` | 9.4.1 |
| Health checks | `spatie/laravel-health` | 1.39.2 |
| Observabilidad | `sentry/sentry-laravel` | 4.25.0 |
| QR (facturas) | `bacon/bacon-qr-code` | 3.1.1 (SVG, sin GD/Imagick) |
| Schema tools | `doctrine/dbal` | 4.4.3 |
| Tests | `pestphp/pest` | **3.8.6** |
| Análisis estático | `larastan/larastan` | 3.9.6 — **nivel 7** |
| Code style | `laravel/pint` | 1.29.1 |
| Modernización | `rector/rector` | 2.4.2 |
| Build | Vite 7 + Tailwind CSS 4 | `vite ^7.0.7` / `tailwindcss ^4.0.0` |

Reglas sobre versiones:

- Cuando consulte documentación o escriba código, apunto a **estas versiones**, no a la última de internet. Filament aquí es **v4 con Schemas** (`Filament\Schemas\...`), no v3. Livewire es v3. Tailwind es v4 (sin `tailwind.config.js` clásico).
- Nunca propongo reemplazar herramientas del stack. PDF → Browsershot. Excel → Maatwebsite. Permisos → Shield + spatie. QR → bacon-qr-code en SVG.
- No mezclo sintaxis de otros proyectos (la clínica dental es Filament v3 + MySQL — nada de eso aquí).
- Actualizaciones de dependencias: solo con razón concreta, primero en staging, con `composer.lock` versionado.

---

## MAPA DEL SISTEMA — LO QUE YA EXISTE (no lo re-propongo, lo extiendo)

Antes de crear cualquier cosa, verifico contra este mapa. Si algo ya existe, **lo leo y lo extiendo**; jamás creo un duplicado paralelo.

### Módulos construidos y funcionando

| Módulo | Piezas clave |
|---|---|
| **POS (cobro)** | Página `PuntoDeVenta`, `VentaService` (fachada), `CotizadorVenta` (motor de precios), `CalculadorVenta` (impuestos, puro), `TicketDiarioService` (número de orden con reinicio diario) |
| **Facturación SAR** | `FacturacionSarService` (correlativo CAI con `lockForUpdate()`), `Cai`, `Factura` (con `anulada/motivo_anulacion`, `hash_verificacion`), `FacturaPdfService` (80mm térmica), `QrService`, verificación pública `/verificar/{hash}`, PDFs por links firmados compartibles por WhatsApp (`wa.me`) |
| **Pagos** | `VentaPago` — **pago mixto** (efectivo/tarjeta/transferencia por venta), `PagosNoCuadranException` |
| **Corte de caja** | `CorteCajaService`, comando `CerrarTurnosAbiertos` (cierre automático), conciliación efectivo vs. registrado con desglose por método |
| **Pedidos online** | Ruta pública `/pedir` (Livewire), `PedidoOnline`, `PedidoOnlineService`, página `BandejaPedidos`, comprobante adjunto, confirmación/rechazo → conversión a venta |
| **Cocina** | `Comanda`, `ComandaService`, página `Cocina`, ticket 80mm firmado `/comandas/{comanda}/ticket`, `ReposicionService` + `AlertaReposicion` (buffet) |
| **Menú del día** | `MenuDia`/`MenuDiaCombo`, `MenuDiaService`, página admin `MenuDelDia` (`/menu-del-dia`), pantalla pública `/menu` (menu board), `Servicio` (franjas: desayuno/almuerzo/cena) |
| **Precios y combos** | `Tier` (niveles de precio), `Combo`, `ComboEspecial`+items, `Producto` con configuración de combos (`tier_combo`, `combo_proteina_id`, `combo_num_complementos/bebidas`, `combo_modo`) |
| **Fiscal contable** | `PeriodoFiscal` (estados, declarado_por/at), `DeclaracionIsvService`, página `DeclaracionIsvMensual`, página `LibrosFiscales`, exports `LibroVentasExport`/`LibroComprasExport`/`VentasFiscalesExport`, `Compra` (**crédito fiscal** de compras) |
| **Empresa/branding** | `EmpresaSetting`+`DatosEmpresaPage`, `BrandingSetting`+`BrandingSettingsPage`, trait `BelongsToEmpresa` (existe, single-tenant activo) |
| **Auditoría** | ActivityLog Resource + Policy, `HasAuditFields`, `RecordUserLogin`, `FilterSensitiveData` (PII en logs) |

### Estructura real del dominio

```
app/
├── Domain/
│   ├── ValueObjects/     # CAI, RTN, Monto, LineaVenta, ComponenteLinea, ResumenVenta, ResumenPeriodo
│   ├── Contracts/        # CalculaImpuestos
│   └── Exceptions/       # RestauranteException, FacturacionException, SinCaiActivoException,
│                         # RangoCaiAgotadoException, FacturaNoAnulableException, PagosNoCuadranException,
│                         # PeriodoYaDeclaradoException, PeriodoNoFinalizadoException, VentaSinLineasException...
├── Models/               # 23 modelos (Venta, VentaItem, VentaPago, Factura, Cai, CorteCaja,
│                         # PeriodoFiscal, Compra, PedidoOnline, Comanda, MenuDia, Producto, Combo, Tier...)
├── Services/
│   ├── Pos/              # VentaService, CotizadorVenta, CalculadorVenta, MenuDiaService, TicketDiarioService
│   ├── Facturacion/      # FacturacionSarService, FacturaPdfService, QrService
│   ├── Fiscal/           # DeclaracionIsvService
│   ├── Caja/             # CorteCajaService
│   ├── Cocina/           # ComandaService, ReposicionService
│   └── Pedidos/          # PedidoOnlineService
├── Support/              # Acceso (permisos), helpers.php
├── Filament/
│   ├── Pages/            # PuntoDeVenta, Cocina, BandejaPedidos, MenuDelDia, DeclaracionIsvMensual,
│   │                     # LibrosFiscales, DatosEmpresaPage, BrandingSettingsPage
│   ├── Resources/        # 13 (Producto, Venta, Cai, CorteCaja, Compra, PeriodoFiscal, Cliente, Combo...)
│   └── Schemas/Components/ # MontoField, RTNField, TelefonoHondurasField
└── Exports/              # LibroVentasExport, LibroComprasExport, VentasFiscalesExport
```

Notas de estructura: las excepciones viven en `app/Domain/Exceptions/` (no `app/Exceptions/`). No existe `app/Jobs/` ni `app/Enums/` hoy — si un cambio los necesita, lo propongo explícitamente. Base de datos: **43 migraciones**, tablas de dominio ya creadas.

### Rutas públicas (sin login)

```
/pedir                              → pedidos online (Livewire PedirOnline)
/menu                               → menu board público (MenuPantalla)
/verificar/{hash}                   → verificación de autenticidad de factura (destino del QR)
/facturas/{factura}/pdf|ticket|documentos → documentos de factura (middleware signed)
/comandas/{comanda}/ticket          → ticket de cocina 80mm (signed)
```

---

## REGLA 0 — CONSTRUYO SOBRE LO QUE EXISTE

- Antes de crear una clase, busco en el mapa de arriba y en el repo. Si existe algo equivalente, lo extiendo.
- Reglas fiscales y de dominio se leen de `config/honduras.php`. Claves reales: `impuestos.isv.tasa_general` (0.15), `impuestos.isv.tasa_alcohol_tabaco` (0.18), `sar.rtn_regex`, `sar.cai_dias_validez`, `moneda.*`, `localizacion.*`. **Nada de esto se hardcodea.**
- Value Objects (`Monto`, `RTN`, `CAI`, `LineaVenta`, `ResumenVenta`) y componentes Filament (`MontoField`, `RTNField`, `TelefonoHondurasField`) se reutilizan tal cual.
- Si no tengo a la vista la firma exacta de una pieza, **la leo del repo** antes de asumir su API.

## REGLA 1 — ANALIZO ANTES DE CODIFICAR

Antes de escribir una línea comunico:

1. **Impacto en producción**: ¿toca tablas con datos reales? ¿la migración es aditiva? ¿qué pasa con las filas existentes?
2. **Dominio**: ¿venta, documento fiscal, corte, comanda, pedido, período fiscal? ¿Qué invariantes protejo? (correlativo único, snapshot inmutable, período declarado no se toca)
3. **Volumen**: a 500–1,000 ventas/día, ¿esta query aguanta 2 años de datos? ¿hay índice para el filtro real?
4. **Contexto SAR**: ¿emite factura con CAI o recibo? ¿afecta un período fiscal ya declarado?
5. **Big-O y N+1**: ¿el POS sigue instantáneo? ¿la pantalla pinta sin pegarle a la BD por ítem?
6. **Concurrencia**: ¿dos cajas a la vez? ¿corte de caja mientras se vende? ¿luz cortada a media transacción?

Si la dirección pedida tiene un problema de raíz, lo digo directo con la alternativa correcta antes de continuar.

## REGLA 2 — RECOMIENDO Y PIDO AUTORIZACIÓN

```
📋 ANÁLISIS      [entendimiento + qué existente reutilizo]
⚠️ RIESGOS       [impacto en producción, trampas fiscales, race conditions]
🔀 OPCIONES      [A y B con pro/contra/escala]
✅ RECOMIENDO    [opción + justificación técnica concreta]
¿Confirmas?
```

No procedo sin confirmación. Decisiones **fiscales nuevas** (nuevas tasas, qué grava, cambios al modelo de precio) las marco como decisión de Mauricio + contador. **Decisiones fiscales ya tomadas y en producción no se reabren sin causa**: el modelo es **ISV incluido en el precio** (`base = neto / (1 + tasa)`), tasa inyectada desde `config('honduras.impuestos.isv.tasa_general')` vía container, flag `grava_isv` por producto con snapshot en `venta_items`.

## REGLA 3 — YO ESCRIBO ARCHIVOS, MAURICIO CORRE COMANDOS

- **Archivos**: los creo y edito directamente en el repo (modelos, services, migraciones, tests, vistas).
- **Comandos** (artisan, tests, composer, deploy): los doy con resultado esperado y Mauricio los ejecuta y reporta el output. No continúo sin ver el output de pasos críticos.

```
Comandos a ejecutar (en orden):
1. php artisan migrate
   → Resultado esperado: "2026_xx_xx_create_x_table ... DONE"
2. php artisan test --filter=NombreTest
   → Resultado esperado: todos en verde
Avísame el output antes de continuar.
```

⚠️ `migrate:fresh` / `migrate:refresh` / `db:wipe` **jamás** — ni en staging si tiene datos que se quieren conservar, y en producción nunca bajo ninguna circunstancia: destruye historial fiscal irrecuperable.

## REGLA 4 — DETECTO Y REPORTO DEUDA TÉCNICA SIEMPRE

Señalo todas, aunque no me las pidan: N+1 en POS/Cocina/Bandeja, queries sin índice sobre `ventas`/`venta_items`, correlativo sin lock, ISV calculado fuera de `CalculadorVenta`, lógica de negocio en páginas Filament, `->get()` sin límite en tablas grandes, agregaciones en PHP que van en SQL, tasa hardcodeada, módulo sin tests.

```
⚠️ DEUDA TÉCNICA DETECTADA
Problema / Impacto a escala / Solución
¿Lo resuelvo ahora o lo anotamos como deuda documentada?
```

## REGLA 5 — SEGURIDAD DE PRODUCCIÓN (nueva, la más importante)

1. **Migraciones aditivas**: agregar columnas/tablas/índices, sí. Renombrar o borrar columnas con datos: proceso en dos fases (expand → migrate data → contract) y solo con autorización explícita.
2. **Staging primero**: todo cambio se valida en `pruebas.altoque.cloud` antes de tocar `altoque.cloud`. El deploy es doble y sigue `docs/DEPLOY.md`.
3. **Lo fiscal emitido es inmutable**: facturas no se editan ni borran — se **anulan** (`anulada`, `motivo_anulacion`, `FacturaNoAnulableException` protege las reglas). Períodos fiscales declarados no se recalculan (`PeriodoYaDeclaradoException`).
4. **Snapshots inmutables**: `venta_items` congela `nombre`, `precio_unitario`, `grava_isv` al vender. Nunca recalculo ventas históricas con datos actuales.
5. **`RestauranteAccessSeeder` resetea la matriz de roles completa**: si lo toco, aviso que correrlo pisa cualquier ajuste manual de permisos hecho en la pantalla de Roles.
6. **Índices creados con la migración**, no después de que la tabla tenga millones de filas.

---

## NÚCLEO FISCAL — DECISIONES YA TOMADAS (así funciona hoy)

- **Dos documentos**: sin RTN → recibo interno (con desglose ISV completo igual); con RTN → factura SAR con CAI y correlativo. La diferencia es el correlativo SAR, **no** el cálculo del impuesto.
- **ISV incluido en el precio**: `base = neto / (1 + tasa)`, `isv = neto − base`. Implementado en `CalculadorVenta` (puro, tasa por constructor desde config). Todo cálculo de impuestos pasa por ahí — cero cálculos dispersos.
- **`grava_isv` por producto** + snapshot en el detalle. Tasas disponibles en config: general 15%, alcohol/tabaco 18%.
- **Correlativo CAI**: transacción + `lockForUpdate()` sobre el rango activo, validación de vigencia y agotamiento (`SinCaiActivoException`, `RangoCaiAgotadoException`). La caja sin CAI activo bloquea solo la factura, no el recibo.
- **Verificación pública**: cada factura lleva `hash_verificacion` + QR → `/verificar/{hash}`. Los PDFs se comparten por links firmados (WhatsApp).
- **Ciclo contable**: ventas → `PeriodoFiscal` mensual → `DeclaracionIsvService` (ventas) + `Compra` (crédito fiscal) → `isv_a_pagar` → libros fiscales exportables.
- **Pago mixto**: una venta acepta múltiples `VentaPago`; si no cuadran contra el total → `PagosNoCuadranException`.

Cambios en esta zona exigen: análisis previo (Regla 1), autorización (Regla 2), tests que prueben el centavo, y validación en staging.

---

## PERMISOS — CÓMO FUNCIONA (Shield gobierna todo)

- Convención Shield PascalCase: `ViewAny:Venta`, `Update:CorteCaja`, `View:PuntoDeVenta` (páginas incluidas).
- Permisos de dominio adicionales: `ExportVentas`, `VerCortesTodos`, `AbrirTurno`, `AnularFactura`, `CorregirPago`.
- Toda verificación pasa por **`Acceso::puede('Permiso')`** (`app/Support/Acceso.php`) o Policies — nunca `if ($user->rol === ...)` disperso. `super_admin` tiene bypass en código.
- **Roles reales** (fuente: `RestauranteAccessSeeder`): `super_admin`, `administrador` (todo operativo + CAI + períodos, sin usuarios/roles ni Registro de Actividad), `gerente` (operativo sin CAI ni corrección de cortes, sí anula facturas), `cajero` (POS, cocina, bandeja, su corte), `contador` (solo lectura fiscal + exports + declaración/libros), `panel_user` (base de acceso al panel).
- Fronteras nuevas de roles = decisión de negocio → confirmar con Mauricio antes de implementar.

---

## BASE DE DATOS — REGLAS INAMOVIBLES (PostgreSQL 16)

- Toda FK con índice. Índices compuestos para las queries reales (columna más selectiva primero). Índices parciales de Postgres cuando el filtro es fijo (ej: `WHERE anulada = false`).
- Dinero en `decimal(12,2)` (`numeric`), nunca float. En PHP, redondeos con `round(..., 2)` en el calculador — un solo lugar.
- `id()` bigint como PK; documentos expuestos usan correlativo formateado, no el id.
- `softDeletes()` solo donde el negocio lo pida — **no** en facturas (se anulan).
- Unicidad de correlativo: `lockForUpdate()` **y** constraint única en BD. Cinturón y tirantes.
- `jsonb` solo para datos genuinamente dinámicos (ya se usa en `pedidos_online.items` y `comandas.items` — snapshot de pedido, correcto).
- Aprovecho Postgres real: CTE recursivo (ya usado en `getDescendantIds()`), `CHECK` constraints, índices parciales.

### Patrones Eloquent obligatorios

```php
// ❌ NUNCA
Venta::all();                          // OOM
$turno->ventas->sum('total');          // suma en PHP cargando todo
foreach ($ventas as $v) $v->cajero->name;  // N+1

// ✅ SIEMPRE
Venta::query()                          // agregación en SQL
    ->selectRaw('tipo, count(*) c, sum(gravado) g, sum(isv) i, sum(total) t')
    ->whereBetween('vendida_at', [$desde, $hasta])
    ->groupBy('tipo')->get();

Venta::select([...columnas explícitas...])
    ->with(['cajero:id,name'])
    ->whereDate('vendida_at', today())
    ->paginate(50);

Venta::where(...)->cursor()->each(...);  // memoria O(1) en procesos largos
```

---

## SOLID Y DISEÑO — COMO YA ESTÁ APLICADO

- **S**: páginas Filament solo orquestan; el dominio vive en Services (`VentaService`, `FacturacionSarService`, `CorteCajaService`, `ComandaService`...). Mantengo esa frontera.
- **O**: extensiones = clases nuevas (nuevo método de pago, nuevo export), no modificar lo estable.
- **D**: contratos en `app/Domain/Contracts/` (`CalculaImpuestos`); bindings y parámetros de config en el ServiceProvider (`giveConfig('honduras.impuestos.isv.tasa_general')`).
- **Excepciones tipadas** de `app/Domain/Exceptions/` — nunca `\Exception` genérica para errores de dominio.
- **Eventos de dominio**: `FacturaEmitida` existe; efectos colaterales nuevos van como listeners, no dentro del service.
- **Fail fast**: venta sin líneas, pagos que no cuadran, CAI agotado → excepción inmediata, nunca datos corruptos.
- **YAGNI con datos**: nada de tablas resumen ni materialized views "por si acaso"; primero query agregada indexada, resumen solo si las métricas reales lo piden.
- **DRY regla del tres**; Law of Demeter; CQRS light (comandos no reportan, queries no mutan).

---

## POS — VELOCIDAD ANTE TODO (ya construido; se protege)

- `PuntoDeVenta` es pantalla dedicada Livewire. Optimizo **toques por venta**, no elegancia de código.
- El menú del día se resuelve por `MenuDiaService` (fecha + servicio). Cero N+1 al pintar; si se detecta carga repetida del menú, cache en Redis con invalidación al editar productos/menú.
- Cierre en un toque: recibo directo o factura con `RTNField`.
- **Tolerante a fallos**: la venta queda registrada aunque falle la impresión; reimprimir es acción idempotente separada. Ese contrato no se rompe jamás.
- Cambios de UX en el POS se miden en toques y milisegundos percibidos por el cajero.

---

## CALIDAD — PIPELINE NO NEGOCIABLE

- **Pest 3.8.6** — ~22 archivos de tests cubren: cotizador, calculador, facturación SAR, anulación, cortes, pago mixto, cocina, pedidos online, menú del día, declaración ISV, crédito fiscal, permisos, value objects. **Todo cambio en un módulo actualiza o agrega sus tests.** El módulo fiscal no se toca sin tests que prueben el centavo y la unicidad del correlativo.
- **Larastan nivel 7** — el código nuevo pasa `composer stan` sin ignorar errores nuevos.
- **Pint** (`composer lint`) antes de dar por terminado.
- CI en GitHub Actions sobre Postgres + Redis reales: `composer ci` = lint:check + stan + test.
- Comando de verificación completo que doy a Mauricio: `composer ci`.

---

## SEGURIDAD — ACTIVA SIEMPRE

- Acceso por Policy/`Acceso::puede()` en toda acción; Shield gobierna.
- `FilterSensitiveData` ya redacta RTN/tarjetas/tokens en logs — nunca loguear RTN ni datos de pago.
- Rutas públicas de documentos siempre con middleware `signed` — nunca exponer por id secuencial.
- Rate limiters de la plantilla (`api`, `login`, `exports`, `pdfs`) activos; rutas públicas nuevas (`/pedir`) evalúan su propio limiter.
- Anulación de factura y edición de precios: permiso explícito (`AnularFactura`) + Activity Log.
- Backups (`spatie/laravel-backup`) + health checks (`spatie/laravel-health`): el historial fiscal perdido no se recupera. Alertas de CAI por agotarse/vencer viven aquí.
- Bindings en todas las queries — nunca interpolación.

---

## DEPLOY — DOBLE AMBIENTE, RUNBOOK PRIMERO

El proceso completo vive en `docs/DEPLOY.md` (y `docs/AMBIENTE-PRUEBAS.md` para staging). Resumen del contrato:

```
Orden SIEMPRE: staging (pruebas.altoque.cloud) → validar → producción (altoque.cloud)

Por ambiente:
1. git pull origin main                  → actualizado o "Already up to date."
2. composer install --no-dev --optimize-autoloader
3. php artisan down --retry=60
4. php artisan migrate --force           ⚠️ error aquí = STOP, reportar completo
5. php artisan config:cache && php artisan route:cache && php artisan view:cache
6. php artisan horizon:terminate         → Horizon reinicia con código nuevo
7. php artisan up

Avísame el output exacto de cada paso.
```

⚠️ Nunca `migrate:fresh`/`refresh` — producción tiene CAI real y correlativos emitidos. ⚠️ Nunca `ALTER TABLE` manual — todo por migración versionada.

---

## CLEAN CODE — NAMING

Español de dominio (como ya usa el repo): `camelCase` descriptivo (`$resumenFiscal`, `$siguienteCorrelativo`), clases `PascalCase` sustantivo (`CotizadorVenta`), métodos verbo+sustantivo (`emitirFactura()`, `cerrarTurno()`), booleanos con prefijo (`$gravaIsv`, `$isAnulada`). PHPDoc en métodos públicos de Service explicando el **por qué** no obvio (ya es el estilo de `CalculadorVenta` — mantenerlo).

---

## RESUMEN: LO QUE NUNCA HAGO

- ❌ Tratar esto como proyecto nuevo — **está en producción con CAI real**
- ❌ Cambios directo a producción sin pasar por staging
- ❌ `migrate:fresh`/`refresh`/`db:wipe` con datos fiscales — jamás
- ❌ Migraciones destructivas sin proceso expand/contract autorizado
- ❌ Reabrir decisiones fiscales ya en producción (ISV incluido, flag por producto) sin causa
- ❌ Editar o borrar facturas emitidas — se anulan con motivo y registro
- ❌ Recalcular ventas históricas con precios actuales — el detalle es snapshot
- ❌ Recalcular períodos fiscales ya declarados
- ❌ Correlativo SAR sin transacción + `lockForUpdate()` + constraint única
- ❌ Duplicar lo que ya existe (ver Mapa del Sistema) en vez de extenderlo
- ❌ Hardcodear tasas — todo de `config/honduras.php`
- ❌ Cálculo de impuestos fuera de `CalculadorVenta`
- ❌ Lógica de negocio en páginas/Resources Filament — va en Services
- ❌ `if ($user->rol === ...)` — todo por `Acceso::puede()` / Policies / Shield
- ❌ Correr `RestauranteAccessSeeder` sin avisar que resetea la matriz de roles
- ❌ Sintaxis Filament v3 o MySQL — esto es Filament 4.11 (Schemas) + PostgreSQL 16
- ❌ Correr comandos yo — los doy con resultado esperado; los archivos sí los escribo directo
- ❌ FKs o columnas de filtro sin índice; `->get()` sin límite; `SELECT *` en tablas grandes
- ❌ Sumar en PHP lo que se agrega en SQL
- ❌ Loguear/exponer RTN o datos de pago; rutas de documentos sin `signed`
- ❌ Entregar cambios en módulo fiscal sin tests del centavo y del correlativo
- ❌ Marcar terminado sin `composer ci` en verde
- ❌ Elegir la solución rápida de hoy si no aguanta 10x mañana
