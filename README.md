# Volver a verde

App para administrar el Airbnb familiar (5 cuartos) y contestar cada mes: **¿cuánto nos quedó y de dónde sale?**
No reemplaza al contador; le da la información ordenada.

Hecha con **Laravel 13** (PHP 8.3) y SQLite. Las vistas son Blade con CSS propio, sin paso de compilación (no necesita Node).

## Qué hace

- **Resumen**: utilidad neta, ocupación, tarifa promedio, de dónde sale la utilidad (cobrado → comisión → impuestos retenidos → depositado → gastos), gastos por categoría y rentabilidad por cuarto, con conclusiones en lenguaje simple.
- **Flujo de efectivo**: saldo de cada cuenta (débito y crédito), lo que se debe en la tarjeta, lo que se debe a cada socio, el disponible real, entradas y salidas por mes y lo que falta por cobrar. Ahí se registran los pagos de la tarjeta, los reembolsos a socios y los ajustes con el banco.
- **Gastos**: cada socio registra sus gastos desde el celular con foto, PDF o XML del comprobante. Se marcan *con factura*, *no es del negocio* (sale en el reporte del contador pero no resta en la utilidad) y con qué se pagó: una cuenta del negocio (débito o crédito) o dinero propio del socio, que queda por reembolsar.
- **Importar / Contador** (admin): importa el CSV de Airbnb (Ganancias → Historial de transacciones) y el CSV de gastos de Excel; descarga reservas y gastos del periodo para el contador.
- **Ajustes**: usuarios, cuentas (saldo o deuda inicial y cuál recibe los depósitos de Airbnb), nombres de los cuartos y su anuncio en Airbnb.

| | Socio | Administrador |
|---|---|---|
| Registrar gastos y subir comprobantes | ✓ | ✓ |
| Ver resumen, flujo, reservas y gastos | ✓ | ✓ |
| Borrar gastos | solo los suyos | todos |
| Registrar gastos a nombre de otro socio (“¿Quién lo pagó?”) | | ✓ |
| Importar, capturar reservas, ajustes, usuarios, reporte del contador | | ✓ |

## Cómo se calcula

- **Depositado** = cobrado − comisión de Airbnb − impuestos retenidos.
- **Utilidad neta** = depositado − gastos del negocio.
- **Ocupación** = noches rentadas ÷ (cuartos × días del periodo). Una reserva que cruza de mes se reparte por noches.
- **Por cuarto**: los gastos marcados con un cuarto se le cargan a ese cuarto; los generales se reparten igual entre los 5.
- **Saldo de cada cuenta** = saldo inicial + depósitos de Airbnb (en la cuenta que los recibe, por fecha de pago) − gastos pagados con esa cuenta ± pagos de tarjeta y reembolsos + ajustes. En una tarjeta de crédito el saldo es la deuda.
- **Disponible real** = débito − deuda de tarjetas − lo que se debe a socios.
- Una compra con tarjeta cuenta como gasto y como salida el día que se hizo; pagar la tarjeta no vuelve a contar.

### Importar de Airbnb

En airbnb.mx (en la computadora): **Menú → Ganancias → Crear informe → Historial de transacciones → CSV**. El importador:

- Junta todos los renglones de cada reserva (por código de confirmación): la reservación, el **IVA que pagó el huésped**, las **retenciones de IVA e ISR**, cancelaciones (*Ajuste*) y cobros o ajustes de resoluciones. Lo que queda es exactamente lo que Airbnb depositó por esa reserva.
- Guarda los **depósitos reales** (renglones *Payout*) con su fecha estimada de llegada al banco; el flujo de efectivo usa esos depósitos.
- Asigna cada anuncio "Volver a Verde N" al Cuarto N la primera vez (después se pueden renombrar los cuartos).
- Se puede subir el mismo archivo o uno que se empalme: las reservas se actualizan y los depósitos no se duplican.

El código de los cálculos está en `app/Services/Finanzas.php` y la importación en `app/Services/Importador.php`.

## Desarrollo

```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate
php artisan db:seed --class=DemoSeeder        # opcional: reservas y gastos inventados
php artisan db:seed --class=GastosDemoSeeder  # opcional: solo gastos inventados, para usar con reservas reales
php artisan serve                        # http://localhost:8000
php artisan test                         # pruebas de cálculos, importación y permisos
```

La primera vez que abres la app te pide crear el usuario **administrador**. Después, en **Ajustes → Usuarios**, das de alta a cada socio.

## Ponerla en el servidor Linux

Necesitas PHP 8.3 con `php-fpm`, `php-sqlite3`, `php-mbstring`, `php-xml`, `php-curl`, `php-intl`, Composer y nginx.

```bash
sudo apt install nginx php8.3-fpm php8.3-sqlite3 php8.3-mbstring php8.3-xml php8.3-curl php8.3-intl php8.3-zip unzip composer
sudo git clone https://github.com/Greenstake2988/Volver-a-verde /var/www/volver-a-verde
sudo chown -R $USER /var/www/volver-a-verde && cd /var/www/volver-a-verde
./deploy/instalar.sh
sudo cp deploy/nginx.conf /etc/nginx/sites-available/volver-a-verde
sudo ln -s /etc/nginx/sites-available/volver-a-verde /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

En `/etc/php/8.3/fpm/php.ini` sube `upload_max_filesize = 15M` y `post_max_size = 80M` para las fotos, y reinicia: `sudo systemctl restart php8.3-fpm`.

Para actualizar después: `./deploy/actualizar.sh`.

### Opción 1: solo en la red de la casa (gratis)

Desde cualquier celular o compu en la misma WiFi: `http://IP-DEL-SERVIDOR:8000` (la IP la ves con `hostname -I`). Si tienes firewall: `sudo ufw allow 8000/tcp`.

### Opción 2: desde cualquier lugar con Cloudflare Tunnel (gratis)

Instala `cloudflared` ([instrucciones](https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/downloads/)).

- **Rápido, para probar** (sin cuenta; la dirección cambia cada vez que lo reinicias):
  ```bash
  cloudflared tunnel --url http://localhost:8000
  ```
- **Fijo** (gratis, pero necesitas un dominio en tu cuenta de Cloudflare, ~US$10/año):
  ```bash
  cloudflared tunnel login
  cloudflared tunnel create volver-a-verde
  cloudflared tunnel route dns volver-a-verde finanzas.tudominio.com
  cloudflared tunnel run --url http://localhost:8000 volver-a-verde
  ```
  Para que arranque solo: `sudo cloudflared service install`.

Con el túnel no hace falta abrir puertos del módem. Pon la dirección pública en `APP_URL` del `.env` y corre `php artisan config:cache`.

### Respaldos

Todo lo importante está en `database/database.sqlite` y `storage/app/private/comprobantes/`. Por ejemplo, con cron cada noche:

```bash
0 3 * * * tar czf ~/respaldos/vav-$(date +\%F).tgz -C /var/www/volver-a-verde database/database.sqlite storage/app/private
```

## Prototipo

La primera versión (Python sin dependencias) quedó en `prototipo-python/` como referencia.
