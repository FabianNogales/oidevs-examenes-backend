<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Backend Architecture

This backend is organized as a Laravel REST API. API routes start under `/api/v1`; version-specific routes are loaded from `routes/api/v1.php`.

The main request flow is:

`HTTP Request -> Form Request -> Controller -> Service -> Eloquent Model -> PostgreSQL`

The main response flow is:

`Model -> API Resource -> JSON -> React`

- Controllers handle HTTP concerns and coordinate use cases.
- Requests will contain request validation and authorization rules.
- Resources will control the JSON representation returned to the React frontend.
- Services will contain business logic for each domain.
- Enums will represent domain states and fixed value sets.
- Policies will contain authorization rules.
- Support will contain shared utilities that do not belong to a specific domain.

The initial domain folders are prepared for Auth, Students, Subjects, Exams, Collaborators, Eligibility, Entries, Infractions, and Reports, without implementing business logic or database models yet.

## Documentación técnica

- [HU02 — Autenticación y sesión](docs/HU02_AUTH_API.md)

## Database

The backend is prepared for PostgreSQL 15.

The database schema uses technical English names, Laravel/Eloquent naming conventions, plural table names where appropriate, snake_case columns, and Laravel timestamps (`created_at`, `updated_at`) where the table needs both lifecycle timestamps.

Migrations are the source of truth for the schema. Do not modify tables manually from pgAdmin.

During initial setup, after configuring `.env`, apply the schema with:

```bash
php artisan migrate
```

Once the initial migration has been shared and used by the team, do not modify it for later schema changes. Every later schema change must be introduced with a new migration, for example:

```bash
php artisan make:migration add_institutional_code_to_users_table
```

## Authentication

Laravel Fortify manages the backend authentication endpoints. Laravel Sanctum authenticates the first-party React SPA through session cookies.

React is responsible for the full authentication interface. The backend does not render Blade pages for login, registration, password reset, or dashboards.

Current authentication decisions:

- Public registration is disabled.
- Two-factor authentication is disabled.
- Passkeys are disabled.
- Password reset is enabled.
- Authenticated password updates are enabled.
- Email verification is pending a product decision.

Before sending login credentials, the React frontend must request:

```http
GET /sanctum/csrf-cookie
```

Credentialed frontend requests must be sent with cookies enabled, for example Axios `withCredentials: true`.

Each developer should configure local SPA variables in `.env`:

```dotenv
FRONTEND_URL=http://127.0.0.1:5173
SANCTUM_STATEFUL_DOMAINS=127.0.0.1:5173,localhost:5173
```

### Local Login Test User

Public registration is disabled, so local login testing requires an existing user.
For local development only, create one manually with Tinker:

```bash
php artisan tinker
```

```php
$user = new App\Models\User();
$user->email = 'login.test@oipass.local';
$user->password = 'Test12345!';
$user->status = 'ACTIVE';
$user->save();
```

The `User` model currently casts `password` as `hashed`, so do not wrap the
password with `Hash::make()` here. Delete this development-only user when it is
no longer needed.

## User Domain

The user model is organized around authentication accounts plus role-specific profile tables:

```text
users
|-- students
`-- teachers
```

- `users` stores authentication and account data.
- `students` stores student-specific information.
- `teachers` stores teacher-specific information.
- Administrators are represented only by `users` plus the `ADMINISTRATOR` role.

Authorization roles are handled through `roles` and `role_user`.

Academic course offerings reference teachers through `course_offerings.teacher_id -> teachers.id`, not directly through `users.id`.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

You may also try the [Laravel Bootcamp](https://bootcamp.laravel.com), where you will be guided through building a modern Laravel application from scratch.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com/)**
- **[Tighten Co.](https://tighten.co)**
- **[WebReinvent](https://webreinvent.com/)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel/)**
- **[Cyber-Duck](https://cyber-duck.co.uk)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Jump24](https://jump24.co.uk)**
- **[Redberry](https://redberry.international/laravel/)**
- **[Active Logic](https://activelogic.com)**
- **[byte5](https://byte5.de)**
- **[OP.GG](https://op.gg)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
