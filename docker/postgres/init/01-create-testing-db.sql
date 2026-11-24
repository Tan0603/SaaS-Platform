-- Runs once, the first time the Postgres volume is created.
-- Tests use their own database so `php artisan test` never wipes your dev data.
CREATE DATABASE saas_platform_testing;
