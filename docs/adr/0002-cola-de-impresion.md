# ADR-0002 — Cola de impresión persistida: pedidos desde tablets, papel solo en la caja

**Estado:** Aceptado
**Fecha:** 2026-08-04
**Decididores:** Mauricio Cruz (arquitecto técnico) · dueño del restaurante (decisión de operación)

## Contexto

El local tiene **una sola impresora térmica**, conectada por USB a la computadora fija de la caja. El restaurante quiere que los meseros tomen pedidos desde tablets en el salón.

Hasta hoy la impresión era 100 % del navegador: el componente Livewire despachaba `imprimir-factura` / `imprimir-comanda` y un script global cargaba la URL firmada en un iframe oculto y llamaba `print()`. Es decir, **imprime el dispositivo que dispara la acción**. Desde una tablet eso imprime al vacío.

El agravante: **no hay pantalla en la cocina**. El KDS existe en el sistema pero el local no lo usa — la cocina trabaja con el ticket de comanda en papel. Un ticket que no sale es un pedido que la cocina nunca ve.

## Decisión

Se agrega una **cola de impresión persistida en Postgres** (tabla `impresiones`). Todo documento imprimible deja una fila; quien tiene el permiso `ImprimirDirecto` la reclama y la imprime en el acto, el resto la deja `pendiente` y la caja la saca desde un panel dentro del Punto de Venta.

Decisiones concretas y por qué:

**1. Cola en la base, no websockets.** El proyecto no tiene broadcasting (`BROADCAST_CONNECTION=log`) y todo el "tiempo real" del sistema ya es `wire:poll` (Cocina 5 s, POS 15 s). Montar Reverb significa un daemon más en el VPS, supervisión, y un punto de caída nuevo en el camino crítico del papel. Un poll de 4 s sobre una tabla con índice parcial resuelve el mismo problema con cero infraestructura nueva. La cola además **sobrevive a recargas y caídas del navegador**, cosa que la cola en memoria del script no hacía.

**2. Impresión manual, no automática.** Chrome abre un diálogo por cada `print()` salvo que corra en modo kiosco. Sacar tickets solos desde un poll produciría diálogos apareciendo en medio de un cobro. El cajero decide cuándo, con un botón por trabajo y un "Imprimir todo" capado a 10.

**3. Permiso de Shield (`ImprimirDirecto`), no marca por dispositivo.** Fue decisión del dueño: se administra desde la pantalla de Roles, junto a todo lo demás, sin configurar cada máquina. El costo aceptado es que si alguien de caja se loguea en una tablet, el trabajo se marca impreso sin que salga papel — por eso el bloque **"Reimprimir" de las últimas 2 h es parte del núcleo**, no un extra.

**4. Opt-in y no opt-out.** Se evaluó invertir el permiso (`SoloEncolarImpresion` solo para meseros), que degradaría al comportamiento actual si el permiso se pierde. Se descartó: esa falla es **silenciosa** — la tablet imprimiría al vacío y, sin pantalla en cocina, el pedido se pierde. La falla del opt-in es ruidosa y recuperable: se acumulan pendientes visibles y nada se descarta. `super_admin` siempre pasa (bypass en `Acceso::puede`), así que nunca hay lock-out total.

**5. `enviar()` nunca lanza y nunca va dentro de la transacción de la venta.** Los correlativos SAR salen de secuencias de Postgres, que no son transaccionales: un rollback quema el número y abre un hueco en la serie. Si la escritura en `impresiones` falla, se reporta a Sentry y se devuelve la URL igual (**fail-open**): un ticket de más se tira, uno perdido no se recupera. Mantiene el contrato del POS: *la venta queda registrada aunque falle la impresión*.

**6. La URL se reconstruye, no se guarda.** `URL::signedRoute()` firma con `APP_KEY`; guardar la URL dejaría la cola histórica inservible el día que se rote la key. Además `url()` devuelve `null` si el documento ya no existe, para no mandar un 404 a la térmica.

**7. Rol `mesero` + permiso `CerrarTurno`.** El rol nuevo solo lleva `View:PuntoDeVenta`. Al crearlo salió a la luz que **cerrar el turno de caja no pedía ningún permiso**: cualquiera con el POS abierto podía cerrar la caja. Se agrega `CerrarTurno` (permiso nuevo, no `AbrirTurno`, porque el cajero no abre pero sí cierra todos los días) y se gatea `confirmarCierre()`.

**Tandas, no clics** (2026-08-04, tras la primera prueba). En hora pico la caja junta decenas de pendientes, y cada `print()` abre un diálogo que **bloquea la pestaña** hasta que alguien lo cierra: veinte pendientes serían veinte diálogos encadenados. Los botones de tanda reclaman todo lo pendiente de un tipo y lo mandan como **un solo documento** con salto de página entre tickets (`TandaImpresionController` + `resources/views/tickets/tanda.blade.php`, reusando los partials y el CSS que ya usa el documento factura+comanda). Un diálogo, un envío, y la térmica corta en cada salto.

Comandas y facturas van en **tandas separadas** porque el papel de cada una termina en un lugar distinto — la cocina y el cliente — y separar veinte tickets mezclados a mano en plena caja es exactamente el trabajo que esto viene a evitar. El corte de caja no entra en ninguna tanda: arma sus datos con consultas propias y sale una vez al día, así que conserva su botón individual.

Tope de 60 tickets por tanda, y cuando se pasa el panel lo dice en vez de truncar en silencio. La lista uno-por-uno se pliega arriba de 3 pendientes y tiene `max-height` con scroll, para que 40 pendientes no empujen el POS fuera de la pantalla. Si alguien cancela el diálogo a mitad de una tanda, los trabajos ya quedaron marcados impresos: **"Reimprimir todo esto de un saque"** rehace el documento completo sin tocar el estado.

## Consecuencias

- En la computadora de la caja el flujo se ve **igual que siempre**; la diferencia es una fila más por ticket.
- La tabla crece ~1.500 filas/día. Dos índices parciales mantienen la cola en un puñado de tuplas y `MassPrunable` borra impresas/canceladas de más de 60 días con el `model:prune` diario. **Nunca poda pendientes.**
- El cierre de turno (`confirmarCierre`) sigue imprimiendo directo, sin pasar por la cola: es un acto físico de la caja y es el camino más delicado del sistema. Las reimpresiones del corte sí pasan por la cola.
- Si el cajero está en otra pantalla no ve el panel; el badge del menú lateral avisa, pero solo se refresca en carga completa de página. Si esto molesta en la práctica, el paso siguiente es mover el panel a un render hook global.

## Alternativas descartadas

- **Compartir la impresora por red / servidor de impresión local.** Requiere instalar y mantener software en la máquina del cliente y depende de la red del local. La cola no necesita nada instalado.
- **Reverb / websockets.** Ver punto 1.
- **Marcar la estación con una cookie del navegador.** Es lo que mejor amarra la impresora física, pero el dueño prefirió administrarlo desde Roles.
