login_app is a PHP-based web application implementing a DB-First email engine on top of the Gmail API. 
It separates identity (Google OAuth), Gmail authorization, and synchronization into modular components. 
A CLI-driven incremental sync persists emails locally, allowing the UI to operate exclusively on the database, improving performance, resilience, and auditability.
