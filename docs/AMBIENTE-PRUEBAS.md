# Ambiente de pruebas (pruebas.altoque.cloud) + paso a producción real

Objetivo de la operación (en este orden, **el orden es inamovible**):

1. Crear `pruebas.altoque.cloud` con **copia completa** de los datos actuales
   (DB propia `altoque_pruebas`) para que el cliente siga probando.
2. Verificar que la instancia de pruebas funciona.
3. Recién entonces: **limpiar movimientos** en `altoque.cloud` (conservando
   usuarios, productos, precios, combos, clientes y configuración), cargar el
   **CAI real** y pasar a `APP_ENV=production`.

> ⚠️ **Regla de oro:** no se borra nada en producción hasta que pruebas esté
> arriba con la copia Y exista un backup del borrado guardado en `/root/backups`.

---

## FASE 0 — DNS (panel de Hostinger)

Crear un registro **A**: `pruebas` → `148.230.90.58` (misma IP del VPS).
Esperar a que resuelva: `ping pruebas.altoque.cloud`.

---

## FASE 1 — Copiar la base de datos (en el VPS)

```bash
sudo -u postgres psql -c "CREATE DATABASE altoque_pruebas OWNER hefesto;"
sudo -u postgres pg_dump sistema_altoque | sudo -u postgres psql altoque_pruebas
# Resultado esperado: una lista larga de CREATE/ALTER/COPY sin errores.

# Verificación rápida — deben coincidir los conteos con producción:
sudo -u postgres psql altoque_pruebas -c "SELECT (SELECT count(*) FROM ventas) AS ventas, (SELECT count(*) FROM facturas) AS facturas, (SELECT count(*) FROM productos) AS productos, (SELECT count(*) FROM users) AS users;"
```

---

## FASE 2 — Clonar la aplicación

```bash
sudo mkdir -p /var/www/pruebas.altoque.cloud
git clone https://github.com/hefesto22/altoque-produccion.git /var/www/pruebas.altoque.cloud
cd /var/www/pruebas.altoque.cloud

composer install --no-dev --optimize-autoloader
npm ci && npm run build

# Partir del .env de producción y ajustar SOLO lo de pruebas:
cp /var/www/altoque.cloud/.env .env
nano .env
```

Cambiar en el `.env` de pruebas (el resto queda igual, **incluido APP_KEY** —
debe ser el mismo para que los links firmados viejos de la copia sigan siendo válidos):

```dotenv
APP_NAME="Al Toque PRUEBAS"
APP_ENV=staging
APP_URL=https://pruebas.altoque.cloud

DB_DATABASE=altoque_pruebas

# Aislamiento en Redis: prefijo propio + índices DISTINTOS a los de producción
# (verificá índices libres con: redis-cli INFO keyspace)
REDIS_PREFIX=altoque_pruebas_
REDIS_DB=3
REDIS_CACHE_DB=4

SESSION_DOMAIN=pruebas.altoque.cloud
```

```bash
php artisan storage:link
# Copiar archivos subidos (logo, etc.) desde producción:
rsync -a /var/www/altoque.cloud/storage/app/public/ storage/app/public/

php artisan config:cache && php artisan route:cache && php artisan view:cache

sudo chown -R www-data:www-data /var/www/pruebas.altoque.cloud
sudo chmod -R 775 /var/www/pruebas.altoque.cloud/storage /var/www/pruebas.altoque.cloud/bootstrap/cache
```

---

## FASE 3 — Nginx + SSL

```bash
# Duplicar el vhost de producción y adaptarlo:
sudo cp /etc/nginx/sites-available/altoque.cloud /etc/nginx/sites-available/pruebas.altoque.cloud
sudo nano /etc/nginx/sites-available/pruebas.altoque.cloud
#   → server_name pruebas.altoque.cloud;
#   → root /var/www/pruebas.altoque.cloud/public;
#   → borrar las líneas "listen 443 ssl", "ssl_certificate*" e "include ...letsencrypt*"
#     que agregó certbot en el original (certbot las re-crea para este dominio)

sudo ln -s /etc/nginx/sites-available/pruebas.altoque.cloud /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx

sudo certbot --nginx -d pruebas.altoque.cloud
# Resultado esperado: "Successfully deployed certificate"
```

---

## FASE 4 — Horizon y cron propios

```bash
sudo nano /etc/supervisor/conf.d/horizon-pruebas.conf
```

```ini
[program:horizon-pruebas]
process_name=%(program_name)s
command=php /var/www/pruebas.altoque.cloud/artisan horizon
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/pruebas.altoque.cloud/storage/logs/horizon.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl status   # horizon-pruebas debe estar RUNNING

# Cron (crontab de www-data — agregar línea, NO borrar la de producción):
sudo crontab -u www-data -e
# * * * * * cd /var/www/pruebas.altoque.cloud && php artisan schedule:run >> /dev/null 2>&1
```

---

## FASE 5 — Verificar pruebas ANTES de tocar producción

En `https://pruebas.altoque.cloud`:

- [ ] Login con un usuario existente (los datos son la copia).
- [ ] El historial de ventas/facturas de producción aparece completo.
- [ ] Hacer una venta de prueba e imprimir su documento.
- [ ] Verificar que esa venta **NO** aparece en `altoque.cloud` (aislamiento OK).

**Si algo falla acá, DETENERSE. Producción sigue intacta.**

---

## FASE 6 — Limpiar producción (altoque.cloud)

### 6.1 Backup obligatorio del estado previo

```bash
sudo mkdir -p /root/backups
sudo -u postgres pg_dump -Fc sistema_altoque > /root/backups/sistema_altoque_pre_limpieza_$(date +%Y%m%d_%H%M).dump
ls -lh /root/backups/   # verificar que el archivo pesa > 0
```

### 6.2 Mantenimiento y limpieza

```bash
cd /var/www/altoque.cloud
php artisan down --retry=60

sudo -u postgres psql sistema_altoque
```

Confirmar en el prompt que dice `sistema_altoque=#` (¡NO `altoque_pruebas`!) y ejecutar:

```sql
BEGIN;

TRUNCATE TABLE
    venta_items,
    ventas,
    facturas,
    compras,
    corte_cajas,
    comandas,
    alertas_reposicion,
    pedidos_online,
    periodos_fiscales,
    contador_tickets,
    notifications,
    activity_log,
    jobs,
    failed_jobs,
    job_batches,
    sessions
RESTART IDENTITY CASCADE;

-- Verificar lo que se CONSERVA antes de confirmar:
SELECT (SELECT count(*) FROM users)     AS users,
       (SELECT count(*) FROM productos) AS productos,
       (SELECT count(*) FROM tiers)     AS tiers,
       (SELECT count(*) FROM clientes)  AS clientes,
       (SELECT count(*) FROM cais)      AS cais;
-- Si esos conteos se ven bien (¡ninguno en 0 inesperado!):
COMMIT;
-- Si algo se ve mal: ROLLBACK; y me avisás.
\q
```

Se conserva: `users` + roles/permisos, `productos`, `combos`, `combo_especial_items`,
`tiers`, `servicios`, `menu_dia`, `menu_dia_combos`, `clientes`, `empresa_settings`,
`branding_settings`, `cais`.

### 6.3 CAI real + modo producción

```bash
nano /var/www/altoque.cloud/.env
#   APP_ENV=production
#   APP_DEBUG=false

php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan cache:clear        # limpia menú cacheado y settings en Redis
php artisan horizon:terminate  # reinicia con la config nueva
php artisan up
```

Luego, en el panel (`altoque.cloud/admin` → CAIs): **crear el CAI real** con su
rango y fecha límite, estado **activo**. El sistema desactiva solo el CAI de
prueba automáticamente (regla del modelo: un solo CAI activo).

### 6.4 Verificación final

- [ ] Login OK, menú y precios completos, usuarios y roles intactos.
- [ ] Ventas/facturas en cero; correlativo listo en el rango real.
- [ ] Emitir UNA factura real de prueba mínima, verificar número, CAI y QR.
- [ ] Esa factura NO aparece en pruebas.altoque.cloud.

---

## Resultado final

| | altoque.cloud | pruebas.altoque.cloud |
|---|---|---|
| Rol | **Producción real** (desde mañana) | Juguete del cliente |
| APP_ENV | production | staging |
| DB | sistema_altoque (movimientos en cero) | altoque_pruebas (copia completa) |
| CAI | Real del SAR | De prueba |
| Redis | prefijo/índices actuales | `altoque_pruebas_` + db 3/4 |
| Horizon | horizon | horizon-pruebas |
