-- La aplicación se conecta con "dte_app": un rol SIN superusuario y SIN BYPASSRLS,
-- para que Row Level Security se aplique siempre (un superusuario la saltaría).
CREATE ROLE dte_app LOGIN PASSWORD 'dte_app' NOSUPERUSER NOBYPASSRLS NOCREATEROLE;
ALTER DATABASE dte OWNER TO dte_app;

-- Base de datos separada para las pruebas automáticas (php artisan test).
CREATE DATABASE dte_test OWNER dte_app;
