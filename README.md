login_app – Google OAuth + Motor Gmail DB-First

Descripción General
- login_app es una aplicación web desarrollada en PHP que implementa un motor de ingestión de correos basado en un modelo DB-First sobre la API de Gmail.
- El sistema desacopla identidad, autorización, sincronización y lógica de negocio en módulos claramente definidos.
- La interfaz de usuario nunca consulta Gmail directamente.
- Todos los datos visibles por el usuario provienen exclusivamente de la base de datos local.

Arquitectura Principal
El sistema está estructurado en cuatro responsabilidades:

1. Autenticación (Google OAuth – Identidad)
Scopes utilizados:
openid
email
profile

Responsabilidades:
  Crear o validar usuarios en la tabla usuarios
  Generar sesión interna segura
  No concede acceso a Gmail

2. Autorización Gmail (OAuth – Acceso a correo)
Scope requerido:
https://www.googleapis.com/auth/gmail.modify

Responsabilidades:
  Solicitar permisos para acceder a Gmail API
  Almacenar tokens en google_gmail_tokens
  Permitir lectura y envío de correos
  Un usuario puede estar autenticado sin tener Gmail conectado.

3. Motor de Sincronización DB-First
Implementado mediante CLI + cron.

Responsabilidades:
  Obtener cambios mediante Gmail History API
  Persistir hilos, mensajes, headers y adjuntos
  Ejecutar reconciliación de mensajes enviados
  Mantener estado incremental por usuario

4. Capa de Negocio

Responsabilidades:
  Inbox desde base de datos
  Renderizado de hilos
  Eliminación / restauración local
  Gestión de adjuntos
  Control del ciclo de vida de tokens
  Registro auditable de sincronizaciones

Modelo DB-First
En lugar de consultar Gmail en cada request:

  Un proceso CLI sincroniza periódicamente.
  Los datos se almacenan localmente.
  La UI opera exclusivamente sobre la persistencia local.

Esto proporciona:
  Alto rendimiento
  Resiliencia ante fallos de Gmail
  Capacidad de auditoría
  Base para extensibilidad futura
  Ciclo de Vida de Tokens

Gestionado a través de:
helpers/gmail_oauth.php

Función principal:
getValidAccessToken()

Responsabilidades:
  Verificar expiración
  Refrescar automáticamente
  Manejar errores invalid_grant
  Actualizar estado del token en base de datos

Sincronización
  Sincronización Inicial = Se activa cuando last_history_id es NULL.

Función Sync_initial:
  Inicializa el estado del buzón
  Guarda el historyId actual
  Prepara el sistema para sincronización incremental

  Sincronización Incremental = Se ejecuta mediante cron.

Procesa eventos:
  messageAdded
  labelAdded
  labelRemoved

Responsabilidades:
  Insertar nuevos mensajes
  Descargar adjuntos
  Ejecutar reconciliación de mensajes enviados
  Actualizar estado de history
  Envío y Reconciliación

Cuando se envía un correo:
  Se crea un mensaje temporal local.
  El usuario lo visualiza inmediatamente.

Durante la sincronización incremental:
  Se detecta el mensaje real proveniente de Gmail.
  El registro temporal es reemplazado.
  No ocurre duplicación visual.
  Gestión de Estados Locales

Las acciones:
  Delete
  Restore
  Empty Trash
  Operan únicamente a nivel local.
  Actualmente no existe sincronización automática hacia Gmail (push-to-Gmail).

Nota de Escalabilidad
Intervalo actual de cron:
*/2 * * * *
Adecuado para entorno de desarrollo.

En producción se requerirá:
  Workers horizontales
  Procesamiento basado en colas
  Notificaciones push o eventos
  Planificación distribuida por usuario

Capacidades Actuales
  Separación dual de OAuth
  Ingestión DB-First
  Persistencia completa de correos
  Control robusto del ciclo de vida de tokens
  Motor de reconciliación
  Registro auditable de sincronizaciones