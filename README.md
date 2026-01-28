# login_app – Google OAuth + Gmail Integration

## Descripción general

`login_app` es una aplicación web desarrollada en PHP que permite:

- Autenticación de usuarios mediante **Google OAuth 2.0**
- Conexión opcional de la cuenta Gmail del usuario
- Lectura y sincronización de correos desde Gmail
- Persistencia de correos y metadatos en base de datos
- Manejo seguro de tokens OAuth con control de expiración

La aplicación está pensada como base para un sistema de gestión de correos
con sincronización progresiva y futura automatización backend.

---

## Arquitectura general

La aplicación separa claramente **tres responsabilidades**:

### 1. Autenticación (Login con Google)
- Permite identificar al usuario
- Crea o reutiliza un registro en la tabla `usuarios`
- No da acceso al correo

### 2. Conexión Gmail (OAuth separado)
- Solicita permisos específicos de Gmail
- Almacena tokens de acceso y refresco
- Permite leer correos del usuario

### 3. Lógica de negocio
- Lectura de hilos y mensajes
- Manejo de adjuntos
- Control de expiración de tokens
- Estados persistidos en base de datos

---

## Flujo OAuth (importante)

### OAuth de Login
- Objetivo: autenticar al usuario
- Scopes: `openid`, `email`, `profile`
- Archivos principales:
  - `google_login.php`
  - `google_callback.php`

### OAuth de Gmail
- Objetivo: acceso a la bandeja de entrada
- Scopes:
  - `https://www.googleapis.com/auth/gmail.readonly`
  - `https://www.googleapis.com/auth/gmail.send`
- Archivos principales:
  - `gmail/connect.php`
  - `gmail/callback.php`

 Ambos flujos son **independientes**, por diseño.

---

## Base de datos (resumen)

### Tabla `usuarios`
Contiene los usuarios autenticados vía Google.

Campos relevantes:
- `id`
- `usuario`
- `email`
- `google_id`
- `rol`

### Tabla `google_gmail_tokens`
Almacena los tokens OAuth de Gmail.

Campos relevantes:
- `user_id`
- `access_token`
- `refresh_token`
- `expires_at`
- `state` (`active` | `expired`)

El campo `state` permite:
- Detectar tokens inválidos
- Mostrar mensajes claros al usuario
- Evitar errores silenciosos

---

## Manejo de tokens Gmail

La clase `GmailService` centraliza toda la lógica relacionada con Gmail:

Responsabilidades:
- Cargar tokens desde la DB
- Verificar expiración
- Refrescar access token automáticamente
- Marcar tokens como `expired` si Google los revoca
- Ejecutar requests a la API de Gmail

Si un token falla o es revocado:
- Se actualiza el estado en DB
- La UI solicita reconectar Gmail (eso es lo que se espera)

---

## Estructura del proyecto

login_app/
├── admin/
├── assets/
├── auth/
├── cli/                # Reservado para scripts CLI futuros
├── config/
│   ├── db.example.php
│   └── google_config.example.php
├── gmail/
├── helpers/
├── lib/
├── index.php
├── dashboard.php
├── google_login.php
├── google_callback.php
├── logout.php
├── register.php
└── verify_email.php

Carpeta cli/
La carpeta cli/ está preparada para futuras tareas backend que no deben
ejecutarse desde el navegador, como:

    Sincronización automática de correos
    Refresco de tokens por cron
    Limpieza de datos expirados
Actualmente no contiene scripts activos.

Seguridad

    Los archivos con credenciales no se versionan
    Tokens OAuth nunca se exponen al frontend
    Los refresh tokens se almacenan solo en backend
    .gitignore excluye:
        vendor/
        storage/
        archivos de configuración reales
