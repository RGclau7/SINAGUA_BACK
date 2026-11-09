# SINAGUA_APP-DE-ESCRITORIO

Gestor financiero del pozo comunitario: cuotas por familia, cobranza, gastos con comprobante,
entregas, cortes, alertas y bitácora. Por ahora solo la **app de escritorio**, que funciona
**sin internet** y sincroniza con el servidor al reconectarse.

Estado: **M0 a M12** completos: cimientos, autenticación, usuarios y permisos, bitácora y anulación, padrón, cuotas y multas, cobranza, ingresos extras, gastos, entregas y cortes, alertas y reportes.

El sistema atiende **varias comunidades**, cada una con su información independiente
(ver [Varias comunidades](#varias-comunidades)).

## Estructura

```
backend/                     API Laravel 13 (PHP 8.3) — autoridad central
  app/Http/Controllers/Api/V1  health, devices, sync
  app/Sync/                  contrato de sincronización (handlers, políticas, servicio)
  app/Models/                User (UUID), Device, AuditLog (inmutable), SyncChange, SyncMutation
  docker-compose.yml         api (php-fpm), nginx, postgres, minio (+ creación del bucket)
  tests/                     PHPUnit
frontend/                    App de escritorio .NET 8 + Avalonia 11 (MVVM)
  src/Sinagua.Core/          sin UI: SQLite/EF Core, cola de salida, cliente HTTP,
                             monitor de conexión, sincronización, folios
  src/Sinagua.Desktop/       ventanas y view models (login, menú de módulos, indicador)
  tests/Sinagua.Core.Tests/  xUnit (SQLite en memoria, API falsa, prueba E2E opcional)
  tests/Sinagua.Desktop.Tests/ pruebas de UI headless (Avalonia.Headless)
.github/workflows/ci.yml     pruebas de backend y frontend, compilación para Windows
```

## Backend

Requisitos: Docker. No hace falta PHP ni Composer en el equipo.

```bash
cd backend
cp .env.example .env          # cambie DB_PASSWORD, DB_ROOT_PASSWORD y AWS_SECRET_ACCESS_KEY
docker compose up -d --build
docker compose exec api php artisan key:generate
docker compose exec api php artisan migrate --seed          # tablas y roles
docker compose exec api php artisan sinagua:superadmin correo@ejemplo.mx "Nombre"
curl http://localhost:8081/api/v1/health
```

| Servicio | URL / puerto en el equipo |
|---|---|
| API (nginx) | http://localhost:8081/api/v1 (`APP_PORT`) |
| PostgreSQL 16 | localhost:5433 (`DB_FORWARD_PORT`) |
| MinIO (S3) | API en :9000, consola en http://localhost:9001 |

El `.env` no se versiona; las credenciales solo viven ahí. PostgreSQL tiene dos usuarios: el
superusuario `postgres` (`DB_ROOT_PASSWORD`, solo administración) y el rol de la API
(`DB_USERNAME`), que no es superusuario ni tiene `BYPASSRLS`; lo crea
`docker/postgres/init-app-role.sh` al crear el volumen por primera vez. La imagen oficial `minio/minio`
ya no se publica en Docker Hub, por eso se usa `cgr.dev/chainguard/minio`.

Documentación de la API (con el backend levantado):

| Qué | Dónde |
|---|---|
| Swagger UI (probar endpoints con el JWT) | http://localhost:8081/docs |
| Contrato OpenAPI 3.1 | `backend/docs/api/openapi.yaml` |
| Colección y entorno de Postman | `backend/docs/postman/` |
| Seguridad, concurrencia y memoria | [backend/docs/API-SEGURIDAD-CONCURRENCIA.md](backend/docs/API-SEGURIDAD-CONCURRENCIA.md) |

Pruebas:

```bash
docker compose exec api php artisan test                       # SQLite en memoria
docker compose exec -e DB_CONNECTION=pgsql -e DB_HOST=postgres -e DB_DATABASE=sinagua_test api php artisan test
```

La base `sinagua_test` la crea el mismo script de inicio. Las pruebas de Row-Level Security
solo corren en PostgreSQL (en SQLite se omiten).

### Formato de respuestas

- Éxito: `{ "data": ... }`
- Error: `{ "error": { "code": "validation_error", "message": "...", "details": { ... } } }`

Códigos: `invalid_credentials` (401), `account_locked` (423, con `details.retry_after_seconds`),
`account_inactive` (403), `validation_error` (422), `unauthenticated` (401), `forbidden` (403), `not_found` (404),
`method_not_allowed` (405), `too_many_requests` (429), `device_not_registered` (409),
`device_inactive` (403), `device_other_community` (403/409), `community_required` (403),
`community_inactive` (403), `server_error` (500).

### Endpoints de M0

| Método | Ruta | Auth | Uso |
|---|---|---|---|
| GET | `/api/v1/health` | no | Estado de la API y de la base |
| POST | `/api/v1/auth/login` | no | `{email, password, device_id?}` → token, expiración, usuario, roles, panel y comunidad |
| GET | `/api/v1/auth/me` | JWT | Usuario actual |
| POST | `/api/v1/auth/refresh` | JWT | Cambia el JWT por uno nuevo y revoca el anterior |
| POST | `/api/v1/auth/logout` | JWT | Revoca la sesión del JWT |
| POST | `/api/v1/devices` | JWT | Registra el equipo `{id, name}` y devuelve su `receipt_prefix` (E01, E02...) |
| POST | `/api/v1/sync/push` | JWT | Envía la cola de salida |
| GET | `/api/v1/sync/pull` | JWT | Descarga cambios de su comunidad desde un cursor |
| GET | `/api/v1/users?search=&active=&page=` | `usuarios.gestionar` | Usuarios de la comunidad, de 20 en 20 |
| POST | `/api/v1/users` | `usuarios.gestionar` | Alta `{name, email, role, password}` |
| GET / PATCH | `/api/v1/users/{id}` | `usuarios.gestionar` | Consulta / edita datos, rol o contraseña |
| POST | `/api/v1/users/{id}/activate` · `/deactivate` | `usuarios.gestionar` | Activa / desactiva (devuelve `notices`) |
| GET | `/api/v1/roles` | `usuarios.gestionar` | Roles asignables y sus permisos |
| POST | `/api/v1/padron/registro` | `padron.gestionar` | Alta de familia con titular y vivienda `{vivienda \| vivienda_id, familia?, titular, confirm_shared_address?}` |
| GET | `/api/v1/padron/{viviendas\|familias\|habitantes}?search=&status=&page=` | `padron.gestionar` | Búsqueda de 20 en 20 (`status`: activos, baja, todos) |
| POST | `/api/v1/padron/{entidad}` | `padron.gestionar` | Alta de una vivienda, familia o habitante |
| GET / PATCH | `/api/v1/padron/{entidad}/{id}` | `padron.gestionar` | Consulta / edita |
| POST | `/api/v1/padron/{entidad}/{id}/deactivate` · `/activate` | `padron.gestionar` | Baja lógica `{reason?, confirm_debt?}` (devuelve `notices`) / reactivación |
| GET / POST | `/api/v1/cuotas` | `padron.gestionar` | Historial de cuotas (con `is_current`) / alta `{amount_cents, valid_from: "2026-11", note?}` |
| PATCH | `/api/v1/cuotas/{id}` | `padron.gestionar` | Corrige una cuota (monto y fecha solo si aún no se usó) |
| GET | `/api/v1/periodos` | `padron.gestionar` | Meses generados con cargos, anulados y total |
| POST | `/api/v1/periodos/generar` | `padron.gestionar` | Asigna la cuota del mes y genera o completa sus cargos `{year, month, amount_cents?}` |
| GET / POST | `/api/v1/multas` | `padron.gestionar` | Multas / asignar `{familia_ids, description, amount_cents, year, month}` |
| GET | `/api/v1/periodos/{id}/cargos?search=` | `padron.gestionar` | Cargos del mes, de 20 en 20 |
| GET | `/api/v1/familias/{id}/cargos` | `padron.gestionar` | Cargos de una familia por mes (estado de cuenta) |
| POST | `/api/v1/movements/cargos/{id}/void` | `movimientos.anular` | Anula un cargo `{reason}` |
| GET | `/api/v1/cobradores` | `usuarios.gestionar` | Cobradores con su número de viviendas |
| GET / POST | `/api/v1/asignaciones` | `usuarios.gestionar` | Asignaciones (`?cobrador_id=&vivienda_id=&historial=1`) / asignar `{cobrador_id, vivienda_ids: []}` |
| POST | `/api/v1/asignaciones/{id}/end` · `/asignaciones/reasignar` | `usuarios.gestionar` | Quitar una vivienda / pasar todas `{from_cobrador_id, to_cobrador_id}` |
| GET | `/api/v1/mis-viviendas` | `padron.consultar_asignadas` | El cobrador consulta sus viviendas, familias y titulares |
| GET | `/api/v1/cobranza/buscar?search=` | `cobros.registrar` o `_asignadas` | Viviendas con sus familias y lo que deben (el cobrador, solo las suyas) |
| GET | `/api/v1/cobranza/familias/{id}` | `cobros.registrar` o `_asignadas` | Cuadrícula: cada cuota y multa con su estado y folio |
| GET / POST | `/api/v1/pagos` | `cobros.registrar` o `_asignadas` | Recibos / cobrar `{familia_id, cargo_ids: []}` (folio `W-000001`) |
| POST | `/api/v1/movements/pagos/{id}/void` | `movimientos.anular` | Anula un recibo `{reason}`; sus cargos vuelven a quedar por pagar |
| GET | `/api/v1/admin/communities` | superadministrador | Lista comunidades |
| POST | `/api/v1/admin/communities` | superadministrador | Crea una comunidad y su primer administrador `{name, admin: {name, email, password}}` |

## Autenticación (M1)

- **Bloqueo:** 5 intentos fallidos bloquean la cuenta 15 minutos (`423 account_locked`). Un correo
  inexistente responde igual y también se bloquea, para no revelar qué correos existen.
- **Token:** JWT firmado con HS256 (`Authorization: Bearer`); su `jti` apunta a una sesión en
  `auth_sessions`, así expira tras **30 minutos sin usarse** y, en todo caso, a las 12 horas, y se
  puede revocar al cerrar sesión. Contraseñas con bcrypt (costo 12). Detalle en
  [backend/docs/API-SEGURIDAD-CONCURRENCIA.md](backend/docs/API-SEGURIDAD-CONCURRENCIA.md).
- **Desactivación:** un usuario desactivado no puede entrar y, si tenía sesión, su siguiente
  petición (por ejemplo, al sincronizar) responde `account_inactive` y se revocan sus tokens.
  Lo mismo ocurre con una comunidad desactivada (`community_inactive`).
- **Panel según rol:** `panel` en la respuesta es el rol de mayor jerarquía (superadministrador >
  administrador > cobrador > habitante > público). El escritorio muestra el menú según ese panel
  y no admite habitante ni público.
- Tiempos configurables en `.env` (`AUTH_*`).

## Usuarios y permisos (M2)

Matriz del SRS 2.3 en `backend/app/Models/Permissions.php`. Los permisos se asignan a roles
(`php artisan db:seed` los deja al día), nunca a personas:

| Permiso | Administrador | Cobrador |
|---|---|---|
| Gestionar usuarios, permisos y cobradores | Sí | No |
| Gestionar habitantes, viviendas y cuotas | Sí | Solo consulta de asignadas |
| Registrar cobros y multas | Sí | Solo asignadas |
| Confirmar recepción de dinero, ingresos de baños y gastos | Sí | No |
| Generar cortes y reportes | Sí | Solo el propio |
| Anular movimientos y consultar bitácora | Sí | No |

Habitante y público no tienen permisos en el escritorio; el superadministrador solo gestiona comunidades.

- **Por ruta:** middleware `perm:<permiso>`, siempre después de `community`.
- **Por registro:** `App\Auth\AccessRules` (vivienda asignada, corte propio), con
  `Gate::forUser($user)->allows(AccessRules::COBRAR_VIVIENDA, $viviendaId)`. Las asignaciones
  reales llegan en M6; hasta entonces `NoAssignments` dice que ningún cobrador tiene viviendas.
- **Reglas de usuarios:** no puede cambiar su propio rol ni desactivarse a sí mismo, y siempre
  queda un administrador activo. Desactivar revoca los tokens; si es cobrador con viviendas, avisa
  que hay que reasignarlas (`reassign_viviendas`). Cambiar la contraseña cierra sus sesiones.
- **Sin conexión:** los usuarios se sincronizan como entidad `usuarios` (gana el servidor). El
  escritorio envía un hash bcrypt `$2y$` generado en el equipo, nunca la contraseña. Una edición
  basada en una versión vieja se rechaza (`conflict`) si el último cambio vino de otro equipo.
- **Visibilidad:** el feed de `usuarios` solo lo descarga quien tiene `usuarios.gestionar`
  (`scope` en `/sync/pull`). Si en el equipo entra alguien con otros permisos, el escritorio
  borra lo que ya no puede ver y descarga de nuevo desde el cursor 0.

**En el escritorio** (`Sinagua.Core/Auth/AuthService.cs`):

- Con red, valida en la API y guarda en SQLite una credencial local con hash PBKDF2-SHA256
  (nunca la contraseña).
- Sin red, solo entra quien ya inició sesión antes en ese equipo, verificando ese hash, con el
  mismo bloqueo de 5 intentos / 15 minutos.
- La contraseña se conserva solo en memoria mientras dure la sesión, para pedir un token nuevo
  al volver la red o cuando expira. Si la API responde que el usuario fue desactivado, se cierra
  la sesión y se borra su credencial local.
- Cierre de sesión automático tras 30 minutos sin teclado ni ratón.

## Varias comunidades

Una sola base PostgreSQL; cada tabla de negocio lleva `community_id`.

- **La comunidad sale siempre del usuario autenticado**, nunca del cliente. El middleware
  `community` la fija en `App\Tenancy\CurrentCommunity`; si el cliente manda `community_id`,
  se ignora.
- **Primera barrera, Laravel:** el trait `App\Tenancy\BelongsToCommunity` agrega un filtro global
  a cada modelo y llena `community_id` al crear. Sin comunidad activa no se ve nada, y no se puede
  crear un registro en otra comunidad ni cambiarlo de comunidad.
- **Segunda barrera, PostgreSQL:** Row-Level Security en `devices`, `sync_mutations`,
  `sync_changes`, `audit_logs`, `viviendas`, `familias`, `habitantes`, `cuotas`, `periodos`,
  `cargos`, `asignaciones`, `pagos` y `pago_detalles`, con la comunidad pasada en `app.community_id`. Aunque una
  consulta se salte Eloquent, no ve ni escribe filas ajenas.
- Índices únicos por comunidad: el prefijo de folio `E01` existe una vez en cada comunidad.
  Los módulos siguientes harán lo mismo con folio + comunidad y familia + periodo + comunidad.
- **Cualquier cuenta entra en cualquier equipo.** Un equipo registrado guarda datos de una sola
  comunidad (su SQLite lleva el `community_id`). Si entra una cuenta de otra comunidad, el
  escritorio borra los datos locales y las credenciales guardadas (`LocalData.ResetAsync`), y se
  registra de nuevo con otro id y otro prefijo de folio; si hay cambios sin enviar, no borra nada
  y pide enviarlos antes con una cuenta de esa comunidad. El servidor sigue rechazando sincronizar
  con un equipo de otra comunidad (`device_other_community`).
- Rol **superadministrador** (sin comunidad): crea comunidades y su primer administrador, y
  no tiene acceso a movimientos. Roles definidos: `superadministrador`, `administrador`,
  `cobrador`, `habitante`, `publico` (la matriz de permisos llega en M2).
- Al crear un modelo de negocio: `use BelongsToCommunity`, columna `community_id`, la tabla en
  la lista de RLS y sus índices únicos con `community_id`.

## Bitácora y anulación (M3)

- **Bitácora inmutable:** cada registro guarda una huella SHA-256 encadenada a la del registro
  anterior de su comunidad; `php artisan sinagua:verificar-bitacora` o `GET /api/v1/audit-logs/verify`
  detectan cualquier alteración. La base de datos rechaza UPDATE, DELETE y TRUNCATE en
  `audit_logs`. Nunca se guardan contraseñas ni sus hashes.
- **Consulta:** `GET /api/v1/audit-logs?user_id=&category=&from=&to=` (20 por página), y en el
  escritorio la pantalla Bitácora, que funciona sin conexión con lo sincronizado (entidad
  `bitacora`, solo para quien tiene `bitacora.consultar`).
- **Anulación:** los movimientos de dinero usan el trait `App\Movements\Voidable`
  (columnas con `$table->voidable()`): motivo obligatorio, aviso si ya estaba anulado, nunca
  se borran ni se modifican, queda en la bitácora y se dispara `MovementVoided` para que los
  módulos recalculen adeudos y cortes. Se anula con `POST /api/v1/movements/{entidad}/{id}/void`
  o, sin conexión, con la operación `void` de la sincronización (`VoidsMovements` en el handler
  y `VoidableMovement` / `MovementVoider` en el escritorio).

## Padrón (M4)

RF3 a RF5, CDU3 a CDU5. Tablas `viviendas` (domicilios), `familias` y `habitantes`, con
`community_id`, filtro global y RLS; los UUID los genera el equipo.

- **Alta completa (CDU3):** familia + titular (quien responde por la cuota) + vivienda en un paso.
  El nombre de la familia, si no se escribe, son los apellidos del titular. El teléfono es
  opcional (si se escribe, de 10 dígitos).
- **Varias familias por domicilio:** el domicilio se normaliza (sin acentos, signos ni espacios de
  más). Si ya tiene familias activas, la API responde `409 address_has_family` con sus nombres y,
  con `confirm_shared_address`, registra la familia en esa misma vivienda. El escritorio avisa
  mientras se escribe y pide marcar "Sí, es otra familia en el mismo domicilio".
- **Baja lógica (CDU5):** nada se borra; se guarda fecha y motivo. La baja de una vivienda da de
  baja a sus familias, y la de una familia a sus integrantes (motivo "Baja de la vivienda" /
  "Baja de la familia"); al reactivar, vuelven los que se dieron de baja junto. Si el titular se
  da de baja o cambia de familia, la familia queda sin titular y se avisa.
- **Adeudo:** antes de dar de baja una familia o vivienda se consulta `App\Padron\Debts`
  (escritorio: `IDebtLookup`). Con adeudo responde `409 has_debt` con el monto y, con
  `confirm_debt`, da de baja conservando el adeudo. Por ahora `NoDebts` (nadie debe nada);
  Cobranza (M7) pondrá la implementación real.
- **Sincronización:** entidades `viviendas`, `familias` y `habitantes` (gana el servidor). Solo
  las descarga quien tiene `padron.gestionar`; en M6 el cobrador recibirá sus viviendas asignadas.
  Las bajas en cascada las repite el servidor y las publica al feed, por eso el escritorio no las
  encola. Todo queda en la bitácora (tipo "Padrón") en frases sencillas.
- **Escritorio:** módulo Padrón con pestañas Familias / Habitantes / Viviendas, búsqueda sin
  acentos de 20 en 20 y filtro Activos / Dados de baja / Todos.

## Cuotas y cargos (M5)

- **Cuota con vigencia:** tabla `cuotas` (monto en centavos + mes "vigente desde"). La vigente
  para un mes es la más reciente que ya empezó. Cambiar la cuota = registrar otra a partir de
  otro mes; una cuota ya usada en cargos solo admite cambiar su nota (`cuota_in_use`).
- **Generar el mes:** `periodos` (uno por mes y comunidad, guarda el monto) y `cargos` (uno por
  familia activa y mes, único por comunidad). Se puede repetir: solo agrega a las familias nuevas.
- **Sin conexión y sin duplicados:** los ids de periodo y cargo son UUID v5 de la comunidad, el
  mes y la familia (`CuotasService::periodoId/cargoId` y `CuotaIds` en el escritorio). El
  escritorio encola una sola mutación `periodos` y calcula los cargos con los mismos ids; el
  servidor los crea y los publica. El periodo trae `cargo_ids` para quitar del equipo lo que el
  servidor no generó.
- **Pantalla "cuota por mes":** el administrador elige el mes y el monto (viene el del mes
  anterior) y asigna. Si el monto cambia, se registra una cuota a partir de ese mes; un mes ya
  asignado no cambia de monto (`month_has_cuota`). El historial de montos queda en su pestaña.
- **Multas:** cargos con `concept = multa`, motivo (`description`), monto y mes, sin periodo,
  para una o varias familias. Cuentan como adeudo, se cobrarán junto con las cuotas (M7) y se
  anulan con motivo; también se crean sin conexión.
- **Paquetes de casa:** cada comunidad define sus paquetes (nombre, cuántas familias caben y cuota
  al mes; null = la cuota normal) en "Cuotas y paquetes > Cómo se cobra". Vienen tres de fábrica
  (1, 2 y 3 familias). Se guardan en `communities.paquetes` y viajan con los ajustes; cada vivienda
  guarda su `paquete_id`. Una casa con paquete paga **una sola cuota**, a nombre de su familia
  titular (la primera por nombre y, si empatan, por id; servidor y escritorio usan el mismo
  criterio para no duplicar cargos). La cuota especial de la familia manda sobre la del paquete, y
  esta sobre la del mes. Una casa no admite más familias de las que permite su paquete
  (`paquete_full`) y un paquete en uso no se borra, se desactiva. Las casas sin paquete cobran por
  familia, como antes. Al migrar, las casas existentes pasan al paquete de su número de familias.
- **Cargos = movimientos:** no se editan ni se borran; se anulan con motivo (M3).
- **Adeudo:** `CargoDebts` (servidor y escritorio) = cargos sin anular; M7 restará lo pagado.
  Ya se usa para avisar al dar de baja una familia o vivienda.
- Todo con `community_id`, filtro global, RLS y bitácora (tipo "Cuotas y cargos").

## Cobradores (M6)

- **Asignaciones con vigencia:** tabla `asignaciones` (cobrador, vivienda, `starts_on`, `ends_on`).
  Una vivienda tiene a lo más un cobrador vigente (índice único parcial en PostgreSQL); asignarla
  a otro cierra la anterior, que se conserva como historial. Se puede quitar una vivienda o pasar
  todas las de un cobrador a otro (por ejemplo, al desactivarlo; el aviso `reassign_viviendas`
  ya usa las asignaciones reales).
- **Lo que descarga el cobrador:** `App\Sync\RestrictsRecords` filtra el feed por registro. El
  cobrador recibe solo sus viviendas asignadas, las familias que viven ahí, sus integrantes,
  sus cargos y sus propias asignaciones. El `scope` lleva `|restricted:entidades:huella`; si le
  cambian las viviendas, cambia la huella y el escritorio borra esas tablas y descarga de nuevo.
- **Reglas de acceso** (`AccessRules`): ver o cobrar una vivienda = administrador o cobrador con
  esa vivienda vigente (`DbAssignments`). El cobrador consulta, pero no modifica el padrón.
- **Escritorio:** ya no hay pantallas de Cobradores ni Mis viviendas: la comunidad decidió que
  los cobradores cobran a todos los titulares (ver "Cobro como lo hace la comunidad"). Las
  asignaciones siguen en el servidor por si se vuelven a usar.

## Cobranza (M7)

- **Pagos con detalle:** `pagos` (familia, vivienda, quién cobró, folio, total, fecha) y
  `pago_detalles` (un renglón por cargo). Cada cargo se paga **completo** (`partial_payment` si
  no) y solo una vez: si dos equipos cobran lo mismo sin conexión, el segundo pago se rechaza
  (`already_paid`, con el folio del primero). Un cargo anulado ya no se cobra.
- **Folio:** el del equipo (`E01-000123`, prefijo asignado al registrarlo + consecutivo local),
  validado en el servidor; desde la web, `W-000001`. Único por comunidad.
- **Quién cobra:** el administrador cualquier familia; el cobrador solo las de sus viviendas
  asignadas (`AccessRules::COBRAR_VIVIENDA`). Solo el administrador anula recibos; al anular, sus
  cuotas y multas vuelven a quedar por pagar.
- **Adeudo** = cuotas y multas sin anular que no tienen un pago vigente (aviso de baja, Mis
  viviendas, lista de cobro).
- **Escritorio:** módulo Cobranza con pestañas Cobrar (buscar domicilio, elegir familia,
  cuadrícula de meses y multas, total y recibo) y Recibos. Funciona sin conexión; el cobrador
  descarga los pagos de sus familias.
- **Recibo en PDF** (`Services/ReciboPdf.cs`): media carta con la comunidad, folio, fecha,
  familia, domicilio, titular, conceptos, total con letra y quién cobró; "ANULADO" si se anuló.
  Se hace en el equipo con SkiaSharp (el motor que ya usa la app) y la letra Inter, sin internet.
  Botones **Guardar PDF** e **Imprimir** (abre el visor del sistema). Ejemplo: `docs/capturas/recibo-ejemplo.pdf`.

## Cobro como lo hace la comunidad

- **Titular** = quien paga el agua (en el código, la familia y su `head_id`). Una casa puede tener
  varios titulares; cada uno paga su cuota. Las pantallas dicen "titular".
- **Cómo se cobra** (`communities`: cuota vía `cuotas`, `periodicidad_meses` 1/2/3/4/6/12,
  `dias_pago`, `cobro_fuera_de_dias`, `multa_cents`, `meses_adelanto` en periodos), entidad
  `ajustes` en la sincronización (la descargan todos sus usuarios, con los nombres de los
  cobradores y los montos de la cuota). Se contesta en Cuotas y paquetes → Cómo se cobra, o
  `PATCH /api/v1/ajustes`. Un periodo empieza en enero cada N meses y se paga en los días de pago
  de su primer mes; pasado el último, lleva multa (`App\Comunidad\Calendario`).
- **Automático** (`App\Cobranza\CobroAutomatico`): la cuota del mes se carga sola a cada titular
  activo, y a cada periodo no pagado después de su último día de pago se le suma la multa
  fija (una por periodo, id UUID v5 `multa:{cargo}`). Corre al sincronizar y al cobrar (máximo una vez
  por hora y comunidad) y con `php artisan sinagua:cobro-automatico` (programado diario a las
  00:30 si corre el planificador de Laravel).
- **Cuota especial** por titular (`familias.cuota_especial_cents`): dato de antes de los paquetes.
  Ya no se pide al registrar (el paquete pone el precio); si una familia la tiene guardada, se le
  sigue cargando y en la pantalla de edición aparece solo para poder quitarla.
- **Registro por familia:** la pestaña Familias muestra cada familia con su titular y el paquete
  de su casa; el alta pide el titular, el paquete y la casa. `POST /padron/registro` acepta además
  `otros_titulares` (una familia más por titular, para clientes que registren varias a la vez).
  El servidor y el escritorio rechazan (`paquete_full`) si la casa quedaría con más familias
  activas que su paquete, también al reactivar una familia; esto se revisa antes de pedir
  confirmar el domicilio compartido.
- **Adelantos:** en la cuadrícula aparecen el periodo actual (si aún no se cargó) y hasta
  `meses_adelanto` periodos futuros; al cobrarlos, el equipo y el servidor crean su cargo con el
  mismo id. Más allá del límite: `too_far_ahead`.
- **Cobradores:** la gente va con ellos a pagar, así que consultan y cobran a todos los titulares
  (permiso `cobros.registrar`). La asignación de viviendas (M6) sigue en el servidor pero ya no se
  usa en el escritorio. El recibo guarda los nombres de los cobradores de ese momento
  (`pagos.cobradores`) y lleva una línea de firma para quien reciba.

- **Escritorio:** Inicio con botones grandes (Cobrar, Deudores, Registrar titular, Cómo se
  cobra), si hoy es día de cobro y los números del día. **Cobranza** es una sola pantalla con el
  filtro **Todos / Deben / Al corriente**: "Deben" ordena del que más debe al que menos y dice
  cuántos periodos y multas y desde cuándo (el botón Deudores de Inicio abre ese filtro).
- **Búsquedas:** en todas las listas se busca sin importar mayúsculas, acentos ni el orden de las
  palabras ("lopez jose" encuentra a "José López"; `PadronDirectory.SearchMatches`). El domicilio
  o la familia se eligen escribiendo (`Controls/SearchPicker`).

## Diseño acordado para el cobro (M5/M7)

- Para cobrar, el cobrador o administrador **busca por domicilio** y ve las **familias
  registradas** en él (puede haber varias); elige a qué familia cobra.
- De esa familia se muestra una **cuadrícula de meses** (una celda por mes con su estado:
  pagado, pendiente, con multa) y se **seleccionan las celdas** de los meses que va a pagar;
  el total se calcula con esas celdas y el recibo lista los meses pagados.
- **No hay pagos parciales:** cada celda (cuota de un mes o multa) se paga completa, por el
  monto que tiene asignado. No se puede cobrar menos ni dejar saldo de un mes o de una multa.

## Ingresos extras (M8)

- Dinero que entra a la comunidad además de las cuotas del agua. El administrador crea las
  **categorías** que quiera (`categorias_ingreso`: Baños comunitarios, Renta del salón…); no
  vienen fijas, no se borran y se pueden dejar de usar (`is_active`).
- Cada **ingreso** (`ingresos`: categoría, monto, fecha, nota, quién lo registró) es un
  movimiento: no se edita ni se borra; si está mal se anula con motivo
  (`POST /movements/ingresos/{id}/void` o la mutación `void`) y deja de contar.
- Sincronización: `categorias_ingreso` (gana el servidor) e `ingresos` (solo se anexan); solo los
  descarga quien tiene `ingresos_gastos.registrar` (administrador). REST: `GET/POST
  /ingresos/categorias` (con `saldo_cents`), `PATCH /ingresos/categorias/{id}`, `GET/POST /ingresos`.
- Escritorio, módulo **Ingresos extras**: arriba una tarjeta por categoría con su saldo (clic =
  ver solo esa), **Nueva categoría** y el lápiz para editarla; el **+** registra un ingreso.

## Gastos (M9)

- Cada gasto (`gastos`: fondo, concepto, monto, fecha, nota, quién lo registró) **sale de un
  fondo**: `fondo_id` es una categoría de ingresos extras o, si es null, **Cuotas del agua**.
  Saldo de un fondo = lo que entró (cobros o ingresos de la categoría) − sus gastos; nada anulado
  cuenta (`App\Gastos\Fondos`, `GET /api/v1/fondos`).
- Es un movimiento: solo se anula con motivo y el dinero vuelve a su fondo. Lo único que se
  agrega después es el **comprobante** (foto JPG/PNG o PDF, máx. 10 MB) que se guarda en MinIO/S3
  (`{comunidad}/gastos/{id}.ext`): `POST /api/v1/gastos/{id}/comprobante` y `GET` para descargarlo.
- El escritorio no deja gastar más de lo que hay en el fondo; el servidor, como puede llegar
  sin conexión, lo acepta y avisa (`fondo_negativo`). El comprobante se copia a la carpeta
  `comprobantes` junto a la base local y `ComprobanteUploader` lo sube después de sincronizar el gasto.

## Entregas y cortes (M10)

- **Corte** de una persona = sus recibos vigentes que no están en una entrega vigente
  (`GET /api/v1/cortes`). El cobrador ve e imprime solo el suyo.
- **Entrega** (`entregas` + `entrega_pagos`, movimiento anulable): quien tiene
  `dinero.confirmar_recepcion` registra lo que recibió de todo el corte y lo que contó; si no
  coincide, la nota es obligatoria (`note_required`). Un recibo no puede estar en dos entregas
  vigentes (`already_delivered`) ni entregarse anulado. Anularla devuelve los recibos al corte.
- Sincronización: `entregas` solo se anexan; el cobrador descarga solo las suyas.

## Alertas (M11)

Calculadas en el escritorio con lo sincronizado (`Sinagua.Core/Alertas`): diferencias en
entregas, recibos anulados que ya se habían entregado, fondos en negativo, gastos sin comprobante,
dinero con 7 días o más sin entregar y cambios rechazados por el servidor en los últimos 30 días.

## Reportes (M12)

En el escritorio (`Sinagua.Core/Reportes` y `Services/ReportPdf.cs`), en PDF tamaño carta y sin
conexión: resumen del periodo por fondo, cobranza por cobrador (cobrado, entregado, diferencias,
por entregar), adeudos, gastos (con o sin comprobante) e ingresos extras.

## Sincronización sin conexión

1. Todo registro nace con un **UUID generado en el equipo** (`SyncableEntity`).
2. La app **lee y escribe siempre en SQLite**. `SinaguaDbContext.Record(entidad, op)` guarda el
   registro y su mutación en la **cola de salida** (`OutboxMessage`) en la misma transacción.
3. `ConnectivityMonitor` sondea `/health`; al haber red, `SyncService` registra el equipo si hace
   falta, **empuja** la cola en lotes de 100 y **descarga** cambios con su cursor en lotes de 500.
4. En el servidor cada entidad registra un `SyncHandler` con su política:
   - `append_only` (pagos, entregas, gastos): solo altas; corregir = anulación. Sin conflictos.
   - `server_wins` (viviendas, cuotas, asignaciones): si hay conflicto el servidor rechaza y la
     mutación queda en la bitácora y marcada como rechazada en el equipo para avisar al usuario.
5. Reenviar la misma mutación es idempotente (`mutation_id`).
6. Folios de recibo: `FolioService` genera `E01-000123` con el prefijo que asignó el servidor al
   equipo y un consecutivo local, así son únicos aunque se emitan sin red.

Contrato de `/sync/push`:

```json
{
  "device_id": "uuid",
  "mutations": [
    { "mutation_id": "uuid", "entity": "pagos", "entity_id": "uuid",
      "op": "create|update|delete", "payload": { }, "occurred_at": "2026-10-01T10:00:00Z" }
  ]
}
```

Respuesta: `{"data": {"results": [{"mutation_id": "...", "status": "accepted|rejected", "code": "...", "message": "..."}], "server_time": "..."}}`

`/sync/pull?device_id=&cursor=0&limit=500` responde
`{"data": {"changes": [{"cursor": 1, "entity": "...", "entity_id": "...", "op": "...", "payload": {}, "changed_at": "..."}], "next_cursor": 1, "has_more": false, "server_time": "..."}}`.

Para agregar una entidad (M4 en adelante): un `SyncHandler` en `backend/app/Sync` registrado en
`SyncServiceProvider`, y en el escritorio la entidad (`SyncableEntity`), su `DbSet` y un
`IChangeApplier`.

