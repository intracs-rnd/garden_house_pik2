# GH PIK2 — Backend API (Laravel + MySQL)

REST API backend built with **Laravel 8**, **MySQL**, and **Laravel Sanctum** (token auth).
It follows a layered architecture: **Controller → Service → Repository → Model**.

## Requirements

- PHP >= 7.3 (works on PHP 7.4 / 8.x)
- Composer
- MySQL 5.7+ / MariaDB

## Project structure

```
backend-laravel/
├── app/
│   ├── Console/                 # Artisan kernel & commands
│   ├── Exceptions/              # Exception handler (JSON responses for /api)
│   ├── Helpers/                 # ApiResponse trait + global helpers.php
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── Api/             # AuthController, UserController, KendaraanController, DashboardController
│   │   └── Middleware/
│   ├── Models/                  # User, Kendaraan, Category
│   ├── Providers/
│   ├── Repositories/            # BaseRepository + User/Kendaraan/Category repositories
│   └── Services/                # AuthService, UserService, KendaraanService
├── bootstrap/
├── config/
├── database/
│   ├── factories/
│   ├── migrations/
│   └── seeders/
├── public/
├── resources/
├── routes/                      # api.php, web.php, console.php, channels.php
├── storage/
├── tests/
├── .env
├── artisan
└── composer.json
```

## Installation

```powershell
# 1. Install PHP dependencies (also generates the vendor/ folder + autoloader)
composer install

# 2. Create the .env (already provided) and generate an app key if needed
php artisan key:generate

# 3. Create the MySQL database, then update DB_* values in .env
#    Default: DB_DATABASE=gh_pik2, DB_USERNAME=root, DB_PASSWORD=

# 4. Run migrations and seed sample data
php artisan migrate --seed

# 5. Start the dev server
php artisan serve
```

> If Composer is not installed yet, get it from https://getcomposer.org/download/

## Default seeded account

| Email                | Password  | Role  |
| -------------------- | --------- | ----- |
| admin@ghpik2.test    | password  | admin |

## API endpoints

Base URL: `http://localhost:8000/api`

| Method | Endpoint          | Auth      | Description                    |
| ------ | ----------------- | --------- | ------------------------------ |
| POST   | `/register`       | Public    | Register a new user            |
| POST   | `/login`          | Public    | Login, returns a Bearer token  |
| GET    | `/me`             | Bearer    | Current authenticated user     |
| POST   | `/logout`         | Bearer    | Revoke current token           |
| GET    | `/dashboard`      | Bearer    | Aggregated statistics          |
| GET    | `/users`          | Bearer    | List users (paginated)         |
| POST   | `/users`          | Bearer    | Create user                    |
| GET    | `/users/{id}`     | Bearer    | Show user                      |
| PUT    | `/users/{id}`     | Bearer    | Update user                    |
| DELETE | `/users/{id}`     | Bearer    | Delete user                    |
| GET    | `/kendaraan`      | Bearer    | List vehicles (paginated)      |
| POST   | `/kendaraan`      | Bearer    | Create vehicle                 |
| GET    | `/kendaraan/{id}` | Bearer    | Show vehicle                   |
| PUT    | `/kendaraan/{id}` | Bearer    | Update vehicle                 |
| DELETE | `/kendaraan/{id}` | Bearer    | Delete vehicle                 |

Send the token in the header: `Authorization: Bearer <token>`.

## Architecture notes

- **Controllers** (`app/Http/Controllers/Api`) only handle HTTP concerns and validation, then delegate to services.
- **Services** (`app/Services`) contain business logic.
- **Repositories** (`app/Repositories`) encapsulate all Eloquent/data-access logic and extend `BaseRepository`.
- **ApiResponse** trait standardizes JSON responses (`success`, `message`, `data`).
