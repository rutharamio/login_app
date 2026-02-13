login_app – Google OAuth + Gmail DB-First Engine

Descripción general
login_app es una aplicación web desarrollada en PHP que implementa un sistema desacoplado de gestión de correos basado en un modelo DB-First.

La aplicación permite:
- Autenticación de usuarios mediante Google OAuth 2.0
- Conexión opcional de la cuenta Gmail del usuario mediante un OAuth independiente
- Sincronización progresiva de correos hacia base de datos local
- Persistencia completa de hilos, mensajes, headers y adjuntos
- Envío de correos vía Gmail API con reconciliación automática
- Manejo seguro y automático de expiración y refresco de tokens

La UI no consulta Gmail directamente.
Todos los datos visibles provienen de la base de datos local.

Arquitectura general
La aplicación separa claramente cuatro responsabilidades:
1) Autenticación (Login con Google)
  Identifica al usuario
  Crea o reutiliza registro en tabla usuarios
  No otorga acceso a Gmail
  Genera sesión interna segura

2) Conexión Gmail (OAuth separado)
  Solicita permisos de Gmail API
  Guarda tokens en google_gmail_tokens
  Habilita lectura y envío de correos
  Puede reconectarse sin afectar login

3) Motor de sincronización (DB-First)
  CLI con cron cada 2 minutos (entorno de prueba)
  Sincronización incremental mediante Gmail History API
  Persistencia en tablas propias
  Reconciliación de mensajes enviados

4) Lógica de negocio
  Inbox desde DB
  Lectura de hilos
  Eliminación / restauración local
  Manejo de adjuntos
  Control de expiración de tokens
  Gestión de estados internos

Modelo DB-First (Concepto clave)
La aplicación no funciona como cliente webmail tradicional.

En lugar de consultar Gmail API en cada pantalla:
  Un proceso CLI ejecuta sync_incremental
  Trae cambios desde Gmail
  Persiste todo en base de datos local
  La UI solo consulta la DB

Esto genera:
- Alto rendimiento
- Desacople total de la API
- Resiliencia ante fallos de Gmail
- Capacidad de auditoría
- Base para automatización futura

Flujo OAuth
La aplicación implementa dos flujos OAuth completamente independientes.

OAuth de Login
Objetivo: autenticar al usuario en la aplicación.

Scopes:
openid
email
profile

Archivos principales:
auth/google_callback.php
google_login.php

Resultado:
Usuario autenticado
Registro en usuarios

Variables de sesión:
user_id
email
rol

Importante:
Los tokens obtenidos aquí no permiten acceder a Gmail.
OAuth de Gmail
Objetivo: permitir acceso a Gmail API.

Scope actual:
https://www.googleapis.com/auth/gmail.modify

Archivos principales:
actions/gmail/connect.php
actions/oauth/callback.php

Resultado:
Registro en tabla:
google_gmail_tokens

Campos relevantes:
access_token
refresh_token
expires_at
state (active | expired)
last_history_id
needs_initial_sync

Separación crítica:
Un usuario puede estar logueado sin tener Gmail conectado.

Base de datos (modelo actual)

Tabla usuarios
Campos principales:
id
usuario
email
google_id
rol

Tabla google_gmail_tokens
Gestiona estado OAuth de Gmail.
Campos principales:
user_id
access_token
refresh_token
expires_at
state (active | expired)
last_history_id
needs_initial_sync
updated_at

state permite:
Detectar tokens revocados
Forzar reconexión
Evitar errores silenciosos

Tabla email_threads
Mapping interno de hilos.
id (interno)
gmail_thread_id (real Gmail)

Tabla emails
Persistencia completa de mensajes.
Campos relevantes:
gmail_message_id
rfc_message_id
thread_id (interno)
is_read
is_deleted
is_inbox
is_sent
is_temporary
replaced_by
internal_date
sent_at_local

Importante:
gmail_message_id ≠ rfc_message_id

Tabla email_attachments
Persistencia local de adjuntos en filesystem.

Tabla sync_runs
Auditoría de sincronizaciones:
start_time
end_time
user_id
mensajes procesados
Manejo de tokens (Actualizado)

La lógica está centralizada en:
helpers/gmail_oauth.php

Función clave:
getValidAccessToken(PDO $conn, int $userId)
Flujo:
Carga token desde DB
Si expires_at <= UTC_TIMESTAMP():
→ ejecuta refreshAccessToken()

Si Google devuelve invalid_grant:
→ state = 'expired'
→ access_token = NULL

Devuelve access_token válido

El sistema implementa:

✔ Auto-refresh automático
✔ Detección real de expiración
✔ Manejo de revocación
✔ Sin dependencia de la UI

Sincronización
Sync Initial

Se ejecuta cuando:
last_history_id IS NULL

Acciones:
Obtiene historyId inicial
Guarda en DB
Marca needs_initial_sync
No reemplaza incremental.

Sync Incremental
Ejecutado por cron cada 2 minutos (entorno prueba).

Proceso:
Registra sync_run
Refresca token si necesario
Llama Gmail History API

Detecta:
messageAdded
labelAdded
labelRemoved
Trae FULL message

Inserta en:
email_threads
emails
email_headers
email_recipients
email_attachments

Ejecuta reconciliación
Actualiza last_history_id

Envío de correos y reconciliación
reply.php y send.php

Proceso:
Validación de sesión
Verificación ownership
getValidAccessToken()
Construcción MIME multipart

Envío:
POST gmail/v1/users/me/messages/send
Guardado como mensaje temporal:
is_temporary = 1
sent_at_local = UTC_TIMESTAMP()
gmail_message_id = sent_<uniqid>
Reconciliación automática

Cuando incremental trae el mensaje real:
Se ejecuta:
reconcileSentTempAgainstReal()

Match por:
Ventana temporal (~10 min)
from_email
subject
body_text

Si coincide:

Temporal:
is_deleted = 1
replaced_by = id_real

El usuario ve el mensaje inmediatamente, pero el real lo reemplaza sin duplicación.

Estados locales (Delete / Restore)

Las acciones operan en DB:
delete: is_deleted = 1 is_inbox = 0
restore: is_deleted = 0 is_inbox = 1
empty_trash: is_deleted = 2

Actualmente estas acciones no se reflejan automáticamente en Gmail.

Escalabilidad del cron
Cron
Actualmente:
*/2 * * * * php cli/sync_incremental.php
Es válido en entorno de prueba.
