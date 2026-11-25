// Ejemplo mínimo en JavaScript (Node 18+) para la API de Escobar Net DTE.
// Uso: DTE_URL=https://su-dominio DTE_TOKEN=... node cliente.mjs
import { createHmac, timingSafeEqual } from 'node:crypto';

const base = `${process.env.DTE_URL.replace(/\/$/, '')}/api/v1`;
const token = process.env.DTE_TOKEN; // créelo en el panel; nunca lo escriba en el código

async function llamar(metodo, ruta, cuerpo, claveIdempotencia) {
  const r = await fetch(base + ruta, {
    method: metodo,
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(claveIdempotencia ? { 'Idempotency-Key': claveIdempotencia } : {}),
    },
    body: cuerpo ? JSON.stringify(cuerpo) : undefined,
  });
  return { status: r.status, datos: await r.json() };
}

// Montos como texto: la plataforma calcula los totales con decimales exactos.
const { status, datos } = await llamar('POST', '/dte/01', {
  lineas: [{ descripcion: 'Pupusa revuelta', cantidad: '6', precio: '0.66371681' }],
}, `venta-${Date.now()}`);
console.log(status, datos.data?.numero_control ?? datos.message);

// Verificar la firma de un webhook (cuerpo crudo, cabecera X-DTE-Firma, secreto del webhook).
export function firmaValida(cuerpo, cabecera, secreto, tolerancia = 300) {
  const p = Object.fromEntries(cabecera.split(',').map((x) => x.split('=')));
  if (!p.t || !p.v1 || Math.abs(Date.now() / 1000 - Number(p.t)) > tolerancia) return false;
  const esperado = createHmac('sha256', secreto).update(`${p.t}.${cuerpo}`).digest('hex');
  return esperado.length === p.v1.length && timingSafeEqual(Buffer.from(esperado), Buffer.from(p.v1));
}
