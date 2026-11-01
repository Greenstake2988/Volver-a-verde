# Volver a verde

App para administrar el Airbnb familiar (5 cuartos) y contestar cada mes: **¿cuánto nos quedó y de dónde sale?**
No reemplaza al contador; le da a él la información ordenada.

## Cómo usarla

Solo necesitas Python 3 (ya viene en Mac y Linux; en Windows se instala desde python.org).

```bash
python3 app.py          # abre http://localhost:8000 en el navegador
python3 app.py --demo   # primera vez: carga datos de ejemplo para ver cómo se ve
```

Los datos se guardan en `data.db` y los comprobantes en `uploads/`, en la misma carpeta. Respáldalos de vez en cuando; no se suben a GitHub.
Para empezar de cero después de probar la demo, borra `data.db` y `uploads/`.

La primera vez que abres la app te pide crear tu usuario de **administrador**. Después, en **Ajustes → Usuarios**, das de alta a cada socio con su propio usuario y contraseña.

## Usuarios

| | Socio | Administrador |
|---|---|---|
| Registrar gastos y subir comprobantes (foto, PDF o XML) | ✓ | ✓ |
| Ver resumen, flujo, reservas y gastos | ✓ | ✓ |
| Borrar gastos | solo los suyos | todos |
| Importar CSV, capturar reservas, ajustes, usuarios, reporte del contador | | ✓ |

Desde el celular, el botón de comprobante abre la cámara para tomarle foto al ticket o factura.

## Pantallas

- **Resumen**: utilidad neta, ocupación, tarifa promedio, de dónde sale la utilidad (cobrado → comisión → impuestos retenidos → depositado → gastos), gastos por categoría y rentabilidad por cuarto, con conclusiones en lenguaje simple.
- **Flujo de efectivo**: saldo actual de la cuenta, entradas y salidas por mes y lista de movimientos. Se puede registrar un ajuste cuando no cuadre con el banco.
- **Reservas** y **Gastos**: captura a mano. Cada gasto se puede marcar como *tiene factura*, *no es del negocio* y *pagado desde la cuenta del negocio*. Los que no son del negocio salen en el reporte del contador, pero no se restan en la utilidad ni en la rentabilidad de los cuartos; solo bajan el saldo si se pagaron desde la cuenta del negocio.
- **Importar / Contador**: importa el CSV de Airbnb (Ganancias → Historial de transacciones) y el CSV de gastos de Excel; descarga reservas y gastos del periodo para el contador.
- **Ajustes**: saldo inicial de la cuenta y nombres de los cuartos / anuncios de Airbnb.

## Cómo se calcula

- **Depositado** = cobrado − comisión de Airbnb − impuestos retenidos.
- **Utilidad neta** = depositado − gastos.
- **Ocupación** = noches rentadas ÷ (cuartos × días del periodo). Una reserva que cruza de mes se reparte por noches.
- **Por cuarto**: los gastos marcados con un cuarto se le cargan a ese cuarto; los generales se reparten igual entre los 5.
- **Saldo** = saldo inicial + depósitos (por fecha de pago) − gastos + ajustes.

## Importar gastos desde Excel

Guarda la hoja como CSV con estas columnas (la primera fila son los nombres):

| fecha | monto | categoria | descripcion | cuarto |
|---|---|---|---|---|
| 05/10/2026 | 1450 | Luz | CFE | |
| 18/10/2026 | 800 | Mantenimiento | Llave del baño | Cuarto 3 |

Fechas en formato día/mes/año o año-mes-día. `descripcion` y `cuarto` son opcionales. También puedes agregar las columnas `factura` y `personal` con "si" o "no".

## Ponerla en el servidor Linux

```bash
sudo git clone https://github.com/Greenstake2988/Volver-a-verde /opt/volver-a-verde
sudo mkdir -p /opt/volver-a-verde-datos && sudo chown $USER /opt/volver-a-verde-datos
# Edita User= en deploy/volver-a-verde.service con tu usuario de Linux, y luego:
sudo cp /opt/volver-a-verde/deploy/volver-a-verde.service /etc/systemd/system/
sudo systemctl daemon-reload && sudo systemctl enable --now volver-a-verde
```

Para actualizar: `cd /opt/volver-a-verde && sudo git pull && sudo systemctl restart volver-a-verde`.

### Opción 1: solo en la red de la casa (gratis)

Con `HOST=0.0.0.0` (ya viene en el servicio), desde cualquier celular o compu conectada a la misma WiFi entra a `http://IP-DEL-SERVIDOR:8000`.
La IP la ves con `hostname -I`. Si tienes firewall: `sudo ufw allow 8000/tcp`.

### Opción 2: desde cualquier lugar con Cloudflare Tunnel (gratis)

Instala `cloudflared` ([instrucciones](https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/downloads/)).

- **Rápido, para probar** (sin cuenta, la dirección cambia cada vez que lo reinicias):
  ```bash
  cloudflared tunnel --url http://localhost:8000
  ```
  Te imprime una dirección `https://algo.trycloudflare.com` para compartir con los socios.

- **Fijo** (gratis, pero necesitas un dominio en tu cuenta de Cloudflare, ~US$10/año):
  ```bash
  cloudflared tunnel login
  cloudflared tunnel create volver-a-verde
  cloudflared tunnel route dns volver-a-verde finanzas.tudominio.com
  cloudflared tunnel run --url http://localhost:8000 volver-a-verde
  ```
  Para que arranque solo: `sudo cloudflared service install` (ver la documentación de Cloudflare).

Con el túnel ya no hace falta abrir puertos del módem. Si solo usas el túnel, puedes quitar `HOST=0.0.0.0` del servicio para que la app solo escuche dentro del servidor.

### Respaldos

Copia de vez en cuando la carpeta `/opt/volver-a-verde-datos` (base de datos y comprobantes) a otro disco o a la nube. Por ejemplo, con cron cada noche:

```bash
0 3 * * * tar czf ~/respaldos/vav-$(date +\%F).tgz -C /opt volver-a-verde-datos
```
