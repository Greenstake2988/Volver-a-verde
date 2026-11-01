# Volver a verde

App para administrar el Airbnb familiar (5 cuartos) y contestar cada mes: **¿cuánto nos quedó y de dónde sale?**
No reemplaza al contador; le da a él la información ordenada.

## Cómo usarla

Solo necesitas Python 3 (ya viene en Mac y Linux; en Windows se instala desde python.org).

```bash
python3 app.py          # abre http://localhost:8000 en el navegador
python3 app.py --demo   # primera vez: carga datos de ejemplo para ver cómo se ve
```

Los datos se guardan en `data.db`, en la misma carpeta. Respáldalo de vez en cuando; no se sube a GitHub.
Para empezar de cero después de probar la demo, borra `data.db`.

## Pantallas

- **Resumen**: utilidad neta, ocupación, tarifa promedio, de dónde sale la utilidad (cobrado → comisión → impuestos retenidos → depositado → gastos), gastos por categoría y rentabilidad por cuarto, con conclusiones en lenguaje simple.
- **Flujo de efectivo**: saldo actual de la cuenta, entradas y salidas por mes y lista de movimientos. Se puede registrar un ajuste cuando no cuadre con el banco.
- **Reservas** y **Gastos**: captura a mano.
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

Fechas en formato día/mes/año o año-mes-día. `descripcion` y `cuarto` son opcionales.
