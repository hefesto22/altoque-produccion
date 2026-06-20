# Deploy del beta — Hostinger KVM4 (altoque.cloud)

Guía para desplegar **sistema-altoque** en un VPS Hostinger KVM4 con Ubuntu,
**conviviendo con otro sistema que ya corre en el servidor**.

> ⚠️ **Regla de oro de coexistencia:** no tocar nada del otro sistema. Todo lo
> de Al Toque va **aislado**: base de datos propia, Redis con prefijo e índice
> propios, vhost de Nginx propio, pool php8.4-fpm propio y worker de Horizon
> propio. Antes de cada paso global (Nginx, Postgres, Redis), verificá que no
> rompés lo existente.

> 🧪 **Beta:** se despliega con `APP_ENV=staging` (NO `production`) para que los
> seeders creen los usuarios de prueba y el CAI de prueba. Cuando pasen a uso
> fiscal real → `APP_ENV=production` + CAI real cargado desde el panel.

---

## 0. Antes de empezar — relevar el servidor

Conectate por SSH y averiguá qué hay, para no chocar:

```bash
ssh root@TU_IP_DEL_VPS

# ¿Qué versión de Ubuntu?
lsb_release -a

# ¿Qué usa el puerto 80/443? (Nginx del host, o un Docker con su propio proxy)
sudo ss -ltnp | grep -E ':80|:443'

# ¿Hay Nginx del host instalado?
nginx -v 2>&1; ls /etc/nginx/sites-enabled/ 2>/dev/null

# ¿Hay Docker corriendo el otro sistema?
docker ps 2>/dev/null

# ¿Qué PHP hay?
php -v 2>/dev/null; ls /etc/php/ 2>/dev/null

# ¿Postgres y Redis ya instalados? (los reusamos, con DB/prefijo aparte)
psql --version 2>/dev/null; redis-cli ping 2>/dev/null
```

**Decisión según lo que veas:**

- Si el **otro sistema usa el Nginx del host** → agregamos un *server block* nuevo (lo de abajo aplica directo).
- Si el **otro sistema corre en Docker y ocupa 80/443** con su propio proxy → hay que decidir: o publicamos Al Toque por otro puerto detrás de ese proxy, o montamos Al Toque también en Docker. **Avisame en ese caso y ajustamos** — el resto de la guía asume Nginx del host libre para usar el 80/443 con varios vhosts.

---

## 1. Dependencias del sistema (PHP 8.4, Node, Chromium)

> Si Postgres/Redis ya están instalados por el otro sistema, **no los reinstales**: reusalos (creamos DB y prefijo aparte en los pasos 3 y 4).

```bash
# PHP 8.4 desde el PPA de Ondřej (convive con otras versiones de PHP)
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update

sudo apt install -y \
  php8.4-fpm php8.4-cli php8.4-pgsql php8.4-redis php8.4-mbstring \
  php8.4-xml php8.4-curl php8.4-zip php8.4-gd php8.4-bcmath php8.4-intl

# Composer
php -r "copy('https://getcomposer.org/installer','composer-setup.php');"
sudo php composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm composer-setup.php

# Node 20 (para compilar assets y Puppeteer/Chromium de Browsershot)
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo bash -
sudo apt install -y nodejs

# Dependencias de Chromium (las que necesita el Chromium headless de Browsershot)
sudo apt install -y \
  libnss3 libatk1.0-0 libatk-bridge2.0-0 libcups2 libdrm2 libxkbcommon0 \
  libxcomposite1 libxdamage1 libxfixes3 libxrandr2 libgbm1 libpango-1.0-0 \
  libcairo2 libasound2 libatspi2.0-0
```

> Postgres 16 / Redis (solo si NO están ya):
> ```bash
> sudo apt install -y postgresql-16 redis-server
> ```

---

## 2. Clonar el proyecto

```bash
sudo mkdir -p /var/www/altoque.cloud
sudo chown -R $USER:$USER /var/www/altoque.cloud
cd /var/www/altoque.cloud

git clone https://github.com/hefesto22/altoque-produccion.git .

composer install --no-dev --optimize-autoloader
npm install                 # instala Puppeteer → descarga su Chromium
npm run build               # compila los assets (Vite)
```

> Anotá la ruta del Chromium que bajó Puppeteer, la vas a poner en el `.env`:
> ```bash
> node -e "console.log(require('puppeteer').executablePath())"
> which node; which npm
> ```

---

## 3. Base de datos PostgreSQL (aislada del otro sistema)

```bash
sudo -u postgres psql
```

```sql
-- Usuario y base SOLO de Al Toque. Cambiá la contraseña por una fuerte.
CREATE USER altoque WITH PASSWORD 'PONÉ_UNA_CONTRASEÑA_FUERTE';
CREATE DATABASE sistema_altoque OWNER altoque;
GRANT ALL PRIVILEGES ON DATABASE sistema_altoque TO altoque;
\q
```

> No tocamos la base del otro sistema. Esta es independiente.

---

## 4. Redis — prefijo e índice propios (clave para no chocar colas)

No hace falta otro Redis: **reusamos el mismo, pero namespaced**. En el `.env`
(paso 5) usamos un `REDIS_PREFIX` único y un índice de DB libre. Verificá qué
índices usa el otro sistema:

```bash
redis-cli INFO keyspace      # te muestra db0, db1, ... en uso
```

Elegí índices libres (ej. si el otro usa db0, Al Toque usa db1 y db2).

---

## 5. Archivo `.env` de producción/beta

```bash
cp .env.example .env
nano .env
```

Valores clave (ajustá contraseñas/índices):

```dotenv
APP_NAME="Al Toque"
APP_ENV=staging          # ← BETA: crea usuarios y CAI de prueba. (production cuando sea fiscal real)
APP_DEBUG=false
APP_URL=https://altoque.cloud

# Base de datos (la del paso 3)
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=sistema_altoque
DB_USERNAME=altoque
DB_PASSWORD=LA_CONTRASEÑA_FUERTE

# Redis namespaced — NO colisiona con el otro sistema
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null
REDIS_PREFIX=altoque_
REDIS_DB=1
REDIS_CACHE_DB=2

CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

# Horizon: prefijo propio para que no procese las colas del otro app
HORIZON_PREFIX=altoque_horizon:

# Browsershot / PDFs (rutas del paso 2)
PDF_NODE_PATH=/usr/bin/node
PDF_NPM_PATH=/usr/bin/npm
PDF_CHROME_PATH=/var/www/altoque.cloud/node_modules/puppeteer/.local-chromium/.../chrome

# Super admin (login inicial)
SUPER_ADMIN_EMAIL=admin@gmail.com
```

> Verificá que `config/horizon.php` use `env('HORIZON_PREFIX', ...)` como prefijo. Si no, lo ajustamos para garantizar el aislamiento de colas.

Generá la key y el enlace de storage:

```bash
php artisan key:generate
php artisan storage:link
```

---

## 6. Migraciones y datos iniciales (seeders)

```bash
php artisan migrate --force
php artisan db:seed --force      # con APP_ENV=staging crea: usuarios, CAI prueba, menú real, tiers, reglas
```

> ⚠️ **NUNCA** `migrate:fresh`/`migrate:refresh` acá una vez que existan ventas y correlativos reales. En el primer deploy del beta está bien `migrate --force` sobre base vacía.

---

## 7. Permisos de archivos (para Nginx/PHP-FPM)

```bash
sudo chown -R www-data:www-data /var/www/altoque.cloud
sudo find /var/www/altoque.cloud -type f -exec chmod 644 {} \;
sudo find /var/www/altoque.cloud -type d -exec chmod 755 {} \;
sudo chmod -R 775 /var/www/altoque.cloud/storage /var/www/altoque.cloud/bootstrap/cache
```

---

## 8. Nginx — vhost propio para altoque.cloud

```bash
sudo nano /etc/nginx/sites-available/altoque.cloud
```

```nginx
server {
    listen 80;
    server_name altoque.cloud www.altoque.cloud;
    root /var/www/altoque.cloud/public;

    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;   # ← PHP 8.4, no el del otro sistema
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* { deny all; }

    client_max_body_size 20M;   # subidas de comprobantes/imagenes
}
```

```bash
sudo ln -s /etc/nginx/sites-available/altoque.cloud /etc/nginx/sites-enabled/
sudo nginx -t          # NO debe romper la config del otro sitio
sudo systemctl reload nginx
```

---

## 9. DNS + SSL

1. En el panel DNS de **altoque.cloud** (Hostinger), creá un registro **A**:
   `altoque.cloud → IP_DEL_VPS` (y `www` igual, o un CNAME a `altoque.cloud`).
2. Esperá la propagación y emití el certificado:

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d altoque.cloud -d www.altoque.cloud
```

Certbot agrega el bloque `listen 443 ssl` y la renovación automática.

---

## 10. Horizon (colas) con Supervisor — programa propio

```bash
sudo apt install -y supervisor
sudo nano /etc/supervisor/conf.d/altoque-horizon.conf
```

```ini
[program:altoque-horizon]
process_name=%(program_name)s
command=php /var/www/altoque.cloud/artisan horizon
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/altoque.cloud/storage/logs/horizon.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start altoque-horizon
```

> Nombre **`altoque-horizon`** distinto al worker del otro sistema. Con
> `HORIZON_PREFIX` y `REDIS_PREFIX` propios, las colas quedan separadas.

---

## 11. Scheduler (cron) — health checks y backups

```bash
sudo crontab -u www-data -e
```

Agregá (sin borrar lo del otro sistema si comparten crontab; idealmente este es el de `www-data`):

```cron
* * * * * cd /var/www/altoque.cloud && php artisan schedule:run >> /dev/null 2>&1
```

---

## 12. Cachés de producción

```bash
cd /var/www/altoque.cloud
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan filament:cache-components
php artisan icons:cache
```

---

## 13. Verificación post-deploy

- [ ] `https://altoque.cloud` abre el login (SSL en verde).
- [ ] Login con `admin@gmail.com` / (tu pass) y con los de prueba (cajero@gmail.com / 12345678).
- [ ] **Productos / Reglas de Precio / Niveles de Precio** muestran el menú sembrado.
- [ ] **Punto de Venta**: abrir turno, armar un plato, **Cobrar y Facturar** → se emite factura con el CAI de prueba y se imprime el PDF (verifica que Chromium funcione).
- [ ] **/menu** (pantalla pública) y **/pedir** cargan.
- [ ] Horizon procesando: `sudo supervisorctl status altoque-horizon` → RUNNING.
- [ ] El **otro sistema sigue funcionando** igual que antes.

---

## 14. Deploys siguientes (actualizar el beta)

```bash
cd /var/www/altoque.cloud
php artisan down --retry=60

git pull origin main
composer install --no-dev --optimize-autoloader
npm install && npm run build

php artisan migrate --force          # ⚠️ nunca fresh/refresh con datos reales
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan filament:cache-components && php artisan icons:cache

php artisan horizon:terminate        # Horizon reinicia con el código nuevo
php artisan up
```

---

## Notas finales

- **Backups**: el proyecto trae `spatie/laravel-backup`. Configurá un destino (S3 o disco) y dejalo en el scheduler — perder el historial fiscal no se recupera.
- **Paso a fiscal real**: cambiá `APP_ENV=production`, recargá cachés, y cargá el **CAI real autorizado por el SAR** desde *Facturación → Rangos CAI*. Con `production`, los usuarios y el CAI de prueba ya no se siembran.
- **Datos de la empresa**: entrá a *Sistema → Datos de la Empresa* y cargá razón social, RTN, dirección y demás (salen en las facturas).
