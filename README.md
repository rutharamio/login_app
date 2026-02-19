login_app is a PHP-based web application implementing a DB-First email ingestion engine on top of the Gmail API. 
The system separates identity (Google OAuth), Gmail authorization, synchronization, and business logic into modular components.
Instead of querying Gmail on every request, a CLI-based sync engine persists threads, messages, and attachments locally using incremental updates via the Gmail History API.
The UI operates exclusively on the database, ensuring performance, resilience, and auditability, while robust token lifecycle management handles expiration and refresh automatically.
