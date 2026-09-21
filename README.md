# CivoraX Infra — Backend

Backend of CivoraX Infra: a Laravel + Filament operations platform for an architecture &
construction company, covering client/project/task/payment management, staff training
(courses), and lead capture — with role-based portals for **Admin**, **Team**, **Client**,
and **Student** users.

See [Schema.md](../civorax-infra/Schema.md) in the frontend repo for the full data model and
system design.

## Stack

- Laravel 13 (PHP 8.3)
- Filament 3 (Admin/Team/Client/Student panels)
- Spatie Permission + Filament Shield (roles/permissions)
- Spatie Activitylog (audit trail)
- MySQL

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
# configure DB_* in .env, then:
php artisan migrate --seed
php artisan serve
```

Seeded Super Admin login: phone `9800000000`, password `password` (change immediately).

Panels: `/admin`, `/team`, `/client`, `/student`.
