# Poner SINAGUA en la nube (gratis)

Esta guía explica, sin palabras técnicas de más, cómo poner el **servidor** de SINAGUA en internet
para que la app de escritorio (y luego el teléfono y la web) se conecten desde cualquier lugar.
**Todavía no se ha publicado nada.** El servidor ya está preparado; falta crear las cuentas y
copiar unos datos.

> Los límites de abajo se revisaron en las páginas oficiales de cada servicio el **8 de noviembre
> de 2026**. Las empresas los cambian seguido: antes de empezar, vuelve a revisar los enlaces.

---

## ¿Qué piezas se necesitan?

El servidor de SINAGUA necesita cuatro cosas. Cada una la da un servicio distinto:

| Pieza | Para qué sirve | Servicio gratuito que proponemos |
|---|---|---|
| **El servidor** (el programa) | Recibe y guarda lo que manda la app | **Render** |
| **La base de datos** | Donde quedan cobros, gastos, personas, bitácora | **Neon** (PostgreSQL) |
| **Las fotos** | Fotos de perfil e identificaciones, comprobantes de gasto | **Cloudflare R2** |
| **El correo** | Manda los PIN de activación y los códigos para entrar | **Brevo** |

---

## Opción 1 (la que recomendamos): Render + Neon + Cloudflare R2 + Brevo

### Lo que da gratis cada uno y sus riesgos

**Render (servidor)** — [render.com/docs/free](https://render.com/docs/free)
- Gratis: 750 horas al mes por cuenta (alcanza para un servidor encendido todo el mes).
- **Se duerme:** si pasan 15 minutos sin que nadie lo use, se apaga solo. La siguiente vez tarda
  **cerca de un minuto** en despertar. En la app eso se ve como "sin conexión" un momento; la app
  sigue funcionando sin internet y sincroniza cuando el servidor despierta.
- Si se acaban las 750 horas, el servidor se apaga hasta el mes siguiente.
- No guarda archivos en su disco (se borran al reiniciar). Por eso las fotos van a Cloudflare R2.

**Neon (base de datos)** — [neon.com/pricing](https://neon.com/pricing)
- Gratis y **no vence** (no es prueba). No pide tarjeta.
- 1 GB de datos por proyecto. Para una comunidad, los cobros y gastos de varios años caben de sobra.
- 100 "horas de cómputo" al mes. Se apaga sola tras 5 minutos sin uso y despierta en menos de un
  segundo. Si un mes se gastan las 100 horas, la base queda pausada hasta el mes siguiente
  (**no se borra nada**).
- No incluye copias de seguridad automáticas largas: conviene sacar una copia cada mes (ver abajo).

**Cloudflare R2 (fotos)** — [developers.cloudflare.com/r2/pricing](https://developers.cloudflare.com/r2/pricing/)
- Gratis cada mes: 10 GB guardados, 1 millón de subidas y 10 millones de descargas. Descargar no
  cuesta. Una foto de perfil pesa menos de 5 MB: caben miles.
- Cloudflare puede pedir una tarjeta para activar R2 aunque no se cobre nada. Revísalo al crear la
  cuenta; si la pide y no quieres darla, ver la opción 2.

**Brevo (correo)** — [brevo.com/free-smtp-server](https://www.brevo.com/free-smtp-server/)
- Gratis: **300 correos al día**, sin tarjeta. Un PIN de activación o un código para entrar es un
  correo; 300 al día alcanzan de sobra.
- Hay que confirmar el correo que manda los mensajes (por ejemplo `sinagua.pozo@gmail.com`).
- **Riesgo:** los primeros correos pueden caer en "Spam". Pide a cada persona que revise esa
  carpeta y marque "No es spam". Con un dominio propio (por ejemplo `sinagua.mx`) llegan mejor.

### Riesgos generales
- **Todo gratis tiene límites y puede cambiar.** Si una empresa quita su plan gratuito, hay que
  mudarse. Los datos no se pierden si se sacan copias.
- **El primer acceso del día es lento** (Render despierta en ~1 minuto).
- **Seguridad entre comunidades:** el servidor siempre filtra los datos por comunidad. Además usa una
  segunda barrera en PostgreSQL ("Row-Level Security") que solo funciona si el usuario de la base
  no tiene permiso para saltarla. En Neon hay que revisarlo (lo hacemos nosotros al configurarlo).

---

## Opción 2 (para probar unas semanas): todo en Render + Resend

- **Render** para el servidor (igual que arriba) y **Render Postgres** para la base.
  - **Cuidado:** la base gratuita de Render **vence a los 30 días** (después hay 14 días para
    pagar o se borra), tiene 1 GB y no hace copias. Sirve para probar, **no** para cobrar de verdad.
- **Resend** para el correo — [resend.com/pricing](https://resend.com/pricing): 100 correos al día
  y 3,000 al mes. Para mandar a cualquier persona pide un dominio propio.
- Las fotos siguen necesitando un almacenamiento tipo S3 (Cloudflare R2 u otro).

---

## Qué ya está listo en el servidor

- `backend/Dockerfile.prod`: la versión del servidor para la nube (un solo contenedor).
- Al arrancar: revisa la configuración, **aplica las migraciones** (solo agregan tablas o columnas;
  nunca borran datos), deja los roles al día y, si se lo pides, crea el primer superadministrador.
- Revisión de salud en `/api/v1/health` (Render la usa para saber si el servidor está bien).
- Toda la configuración va en **variables de entorno**, sin contraseñas en el código:
  ver `backend/.env.production.example`.
- La dirección pública para los enlaces de los correos se pone en `SINAGUA_PUBLIC_URL`.
- Límites de intentos: PIN de activación (5 intentos, vence en 24–48 horas), código para entrar
  (5 intentos, 15 minutos), PIN del superadministrador (5 intentos, bloqueo 15 minutos), códigos
  de autorización (5 intentos, 30 minutos).
- La página `/activar` (la del enlace del correo) funciona en el teléfono.
- Se probó en esta computadora con `docker compose -f backend/docker-compose.prod.yml up --build`:
  arranca, crea las tablas, manda el correo con el PIN y la cuenta se activa desde `/activar`.

---

## Pasos para publicarlo (cuando decidas hacerlo)

1. **Crear las cuentas** (las crea abis, con su correo): Render, Neon, Cloudflare (R2) y Brevo.
2. **Neon:** crear un proyecto llamado `sinagua` y copiar la "connection string".
3. **Cloudflare R2:** crear un "bucket" llamado `sinagua` y una llave de acceso (Access Key ID y
   Secret Access Key) solo para ese bucket.
4. **Brevo:** confirmar el correo remitente y copiar los datos de SMTP (usuario y clave SMTP).
5. **Render:** crear un "Web Service" con Docker usando `backend/Dockerfile.prod` y escribir las
   variables de `backend/.env.production.example` en "Environment". Render debe poder leer el código:
   desde GitHub (hay que subir la rama) o desde una imagen ya construida (Docker Hub).
6. Abrir `https://<tu-servicio>.onrender.com/api/v1/health`: debe decir `"status":"ok"`.
7. En la app de escritorio, cambiar la dirección del servidor en `appsettings.json`
   (`ApiBaseUrl`) por `https://<tu-servicio>.onrender.com/api/v1/`.
8. Entrar como superadministrador, registrar la comunidad y dar de alta al administrador por correo.

### Copias de seguridad
Una vez al mes, descargar una copia de la base desde Neon (o con `pg_dump`) y guardarla en una
memoria USB o en Google Drive.

---

## Lo que necesitamos que abis decida o nos dé

1. **¿Opción 1 o 2?** (Recomendamos la 1 para usarla de verdad.)
2. **Crear las cuentas** de Render, Neon, Cloudflare y Brevo **ella misma** (nosotros no creamos
   cuentas ni pedimos contraseñas por chat). Las claves se escriben directamente en el panel de
   Render, no se mandan por mensaje.
3. **¿Desde dónde lee Render el código?** Para publicar hay que **subir la rama a GitHub** (o una
   imagen a Docker Hub). Hasta ahora no se ha subido nada; necesitamos su permiso para hacerlo.
4. **Correo remitente:** cuál usar (un Gmail de la comunidad o un dominio propio).
5. **Nombre del servicio** (queda en la dirección, por ejemplo `sinagua-pozo.onrender.com`).
6. **Correo y nombre del superadministrador** de la nube.
