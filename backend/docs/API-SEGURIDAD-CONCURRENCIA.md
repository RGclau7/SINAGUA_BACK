# API, seguridad y concurrencia del backend

Este documento explica cómo el backend de SINAGUA cumple tres requisitos y cómo comprobarlo.

| Requisito | Qué se usó | Dónde verlo |
|---|---|---|
| Arquitectura y documentación de la API | REST versionada (`/api/v1`), contrato **OpenAPI 3.1** con Swagger UI (base) y **colección de Postman** con pruebas en Newman (extensión elegida) | `docs/api/openapi.yaml`, `/docs`, `docs/postman/` |
| Autenticación, autorización y datos | Middleware **JWT HS256** (`auth:api`), contraseñas con **bcrypt** (costo 12), permisos por rol (RBAC), aislamiento por comunidad (RLS) | `app/Auth/Jwt/`, `app/Http/Middleware/`, `routes/api.php` |
| Recursos, memoria y concurrencia | Transacciones con `SELECT … FOR UPDATE`, **candados consultivos** de PostgreSQL, idempotencia, **cola con worker** (trabajos únicos y sin traslape), recorrido por bloques con memoria constante | `app/Support/TransactionLock.php`, `app/Jobs/VerificarBitacora.php`, `app/Audit/AuditChain.php` |

## 1. Arquitectura y documentación de la API

- **Estilo:** REST sobre JSON, versionado en la URL (`/api/v1`). Éxito `{ "data": … }`, error
  `{ "error": { code, message, details } }` con mensajes en español sencillo.
- **Contrato:** `docs/api/openapi.yaml` (OpenAPI 3.1) describe las 75 operaciones, sus parámetros,
  cuerpos, respuestas, errores y el esquema de seguridad `bearerAuth` (JWT).
- **Swagger UI:** `http://localhost:8081/docs`. Botón *Authorize*: pegue el `token` del login y use
  *Try it out*.
- **El contrato no se queda viejo:** `tests/Feature/OpenApiContractTest.php` compara las rutas reales
  con las documentadas; si alguien agrega un endpoint sin documentarlo, la prueba falla.
- **Postman:** `docs/postman/SINAGUA.postman_collection.json` y el entorno
  `SINAGUA-local.postman_environment.json`. 56 peticiones en 13 carpetas con 128 pruebas automáticas
  (códigos HTTP, forma de la respuesta, token alterado, `alg: none`, cobro doble, etc.).

### Usar la colección

1. En Postman: pulse **Import** y arrastre los dos archivos de `docs/postman/`.
2. Elija el entorno **SINAGUA-local** (arriba a la derecha).
3. Con el backend levantado y los datos de prueba cargados
   (`docker compose exec api php artisan db:seed --class=DemoSeeder`), abra la colección y pulse
   **Run collection**. El administrador entra en dos pasos (contraseña y código de 6 números por correo);
   la colección lee el código de Mailpit (`http://localhost:8025`, ya viene en `docker compose`), guarda el
   JWT y todas las demás peticiones lo usan.
4. Desde la terminal (Newman):

```bash
cd backend/docs/postman
npx newman run SINAGUA.postman_collection.json -e SINAGUA-local.postman_environment.json
```

## 2. Autenticación, autorización y seguridad de datos

### JWT (middleware `auth:api`)

1. `POST /auth/login` revisa la contraseña con bcrypt y crea una fila en `auth_sessions`.
2. Responde un JWT **HS256** (librería `firebase/php-jwt` 7) con los claims `iss`, `aud`, `sub`,
   `jti`, `iat`, `nbf`, `exp`, `cid` (comunidad), `did` (equipo) y `roles`.
3. Cada petición pasa por `App\Auth\Jwt\JwtGuard`:
   - firma correcta con la llave del servidor (256 bits, `JWT_SECRET` o derivada de `APP_KEY`);
   - algoritmo fijo `HS256`: se rechazan `alg: none` y otros algoritmos;
   - `exp`, `nbf`, `iat` (con 30 s de tolerancia de reloj), `iss` y `aud`;
   - el `jti` debe existir en `auth_sessions`, sin revocar, sin vencer y usado en los últimos 30 min.
4. `POST /auth/refresh` rota el token (el viejo deja de servir) y `POST /auth/logout` lo revoca.
   Desactivar un usuario o cambiar su contraseña revoca todas sus sesiones.

¿Por qué un JWT con sesión en el servidor y no un JWT "puro"? El SRS pide cerrar la sesión tras 30
minutos sin uso y poder sacar al instante a un usuario desactivado. Un JWT sin estado no puede
revocarse antes de `exp`. Con el `jti` se conserva lo mejor de los dos: el token es autocontenido y
verificable por firma, y el servidor puede invalidarlo.

Los roles del token son informativos para el cliente. **La autorización siempre se revisa en el
servidor**: middleware `perm:<permiso>` (spatie/laravel-permission), `superadmin` y `community`.

### Contraseñas

- `bcrypt` con costo 12 (`BCRYPT_ROUNDS`), cast `hashed` en el modelo: nunca se guarda en claro.
- Si una contraseña se guardó con un costo menor (por ejemplo, creada en el escritorio sin conexión),
  se vuelve a cifrar sola en el siguiente login (`Hash::needsRehash`).
- Contraseñas de al menos 10 caracteres; nunca se devuelven en la API.
- Bloqueo de 15 minutos tras 5 intentos fallidos; correo inexistente responde igual (no revela qué
  correos existen) y gasta el mismo tiempo de bcrypt. Límite de 30 logins por minuto por IP.

### Datos

- Aislamiento por comunidad en dos capas: filtro global de Eloquent y **Row-Level Security** de
  PostgreSQL con un rol de base de datos sin privilegios.
- Bitácora inmutable con cadena de huellas SHA-256; los movimientos de dinero se anulan, no se borran.
- Cabeceras de seguridad en toda respuesta de la API (`X-Content-Type-Options`, `X-Frame-Options`,
  `Referrer-Policy`, `Cache-Control: no-store`, HSTS con HTTPS).

## 3. Recursos, memoria y concurrencia

PHP-FPM atiende cada petición en un proceso aislado ("nada compartido"): la memoria de una petición se
libera completa al terminar, así que no hay estado compartido entre hilos ni fugas que se acumulen
entre peticiones. El riesgo real está en la **base de datos**, donde varias peticiones (varios
equipos y cobradores) escriben al mismo tiempo, y en los **procesos largos** (workers de la cola).

| Riesgo | Solución | Archivo |
|---|---|---|
| Dos equipos cobran el mismo mes a la vez (cobro doble) | Transacción + `SELECT … FOR UPDATE` sobre los cargos | `CobranzaService::pay` |
| Dos cobros simultáneos reciben el mismo folio | Candado consultivo `pg_advisory_xact_lock` por comunidad | `TransactionLock`, `nextWebFolio` |
| Dos equipos nuevos reciben el mismo prefijo E01 | Mismo candado al registrar el equipo | `Device::nextReceiptPrefix` |
| Dos registros de bitácora con la misma huella anterior | Candado consultivo por comunidad | `AuditChain::lastHash` |
| Un recibo se entrega dos veces / una vivienda con dos cobradores | `FOR UPDATE` sobre los recibos y la asignación vigente | `EntregasService`, `AsignacionesService` |
| El equipo reintenta un envío por un corte de red | Idempotencia por `mutation_id` | `SyncService` |
| Muchas peticiones escriben `last_used_at` de la misma sesión | `UPDATE` condicional atómico, máximo una vez por minuto | `AuthSession::touchIfStale` |
| Cargar toda la bitácora en memoria | `lazyById(500)`: memoria constante | `AuditChain::verify` |
| Trabajo pesado dentro de la petición HTTP | Cola `database` + worker, trabajo `ShouldBeUnique` + `WithoutOverlapping` | `VerificarBitacora` |
| Fuga de memoria en un worker que vive horas | `queue:work --max-jobs=500 --max-time=3600 --memory=128` | `docker-compose.yml` |
| Candados olvidados (bloqueos eternos) | Candados de transacción (se liberan con COMMIT/ROLLBACK) y con caducidad en la cola | `TransactionLock`, `VerificarBitacora` |

### El error que encontró la prueba de concurrencia

`tests/Feature/ConcurrencyTest.php` lanza 8 procesos PHP a la vez (cada uno con su conexión a
PostgreSQL, como 8 workers de PHP-FPM). Antes del cambio, al cobrar a 8 familias distintas al mismo
tiempo, **6 de 8 cobros fallaban** con `duplicate key value violates unique constraint` porque todos
leían el mismo último folio: `FOR UPDATE` no bloquea filas que todavía no existen. Con
`TransactionLock` los 8 reciben folios consecutivos `W-000001…W-000008`.

### Memoria al verificar la bitácora

| Registros | `->get()` (antes) | `lazyById(500)` (ahora) |
|---:|---:|---:|
| 1,000 | 2.57 MB | 2.55 MB |
| 5,000 | 12.92 MB | 2.55 MB |
| 15,000 | 38.66 MB | 2.55 MB |

Con `->get()` la memoria crece con cada registro; por bloques se queda igual.

### Comandos de demostración

```bash
# 10 cobros simultáneos del mismo mes contra la API: 1 queda (201) y 9 se rechazan (409)
docker compose exec api php artisan sinagua:demo-concurrencia --url=http://nginx --solicitudes=10

# Memoria de recorrer la bitácora completa contra por bloques
docker compose exec api php artisan sinagua:demo-memoria

# Ver el worker de la cola atendiendo la verificación en segundo plano
docker compose logs -f queue
```

## Pruebas

```bash
docker compose exec api php artisan test                                   # SQLite
docker compose exec -e DB_CONNECTION=pgsql -e DB_HOST=postgres -e DB_DATABASE=sinagua_test api php artisan test
```

En PostgreSQL corren las 210 pruebas, incluidas las de concurrencia real y Row-Level Security.

### En GitHub Actions

Como el administrador entra con un código por correo, el paso de Newman necesita Mailpit. En
`.github/workflows/ci.yml`, dentro de `services:` (junto a `postgres`):

```yaml
      mailpit:
        image: axllent/mailpit:latest
        ports: ["1025:1025", "8025:8025"]
```

Y en el paso *Colección de Postman (Newman)*, dentro de `env:`:

```yaml
          MAIL_MAILER: smtp
          MAIL_HOST: 127.0.0.1
          MAIL_PORT: 1025
```

## Después de bajar esta rama

```bash
cd backend
docker compose up -d --build        # crea el servicio "queue"; el api instala firebase/php-jwt solo
docker compose exec api composer install
docker compose exec api php artisan migrate     # crea auth_sessions (los tokens viejos dejan de servir)
```

La app de escritorio no necesita cambios: guarda el token tal cual y, si recibe 401, vuelve a
iniciar sesión sola.
