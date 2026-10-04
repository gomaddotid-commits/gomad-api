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

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## GoMad local Docker environment

Run these commands from this directory. `bin/compose` creates `.env` from the
example on first run and matches PHP-FPM's user ID to the host user for
writeable bind mounts:

```sh
bash bin/compose up -d --build
bash bin/compose exec app php artisan migrate:fresh --seed
bash bin/test
bash bin/compose exec app php artisan app:reset-demo
bash bin/compose logs -f
bash bin/compose down
```

The local web app is at <http://localhost:8200>; Mailpit is at
<http://localhost:8125>. MariaDB and Redis are available on host ports 3307 and
6380. Set `LOCAL_UID` and `LOCAL_GID` in `.env` to the output of `id -u` and
`id -g` before the first build so bind-mounted files remain writable. Do not
use the development credentials outside this local environment.
Use `bash bin/test` rather than running tests in the local app environment; it
forces an in-memory SQLite database so tests cannot reset or write to the
development MariaDB database.

## Database backups

The scheduler writes daily SQL dumps to `backups/`, retains 30 days, and uses
temporary credentials with owner-only permissions. Verify an existing backup
by restoring it into a disposable database:

```sh
bash bin/compose exec app php artisan app:backup-db
bash bin/verify-backup-restore
```

Local backups are not off-site backups. Before staging or production is
considered ready, configure encrypted off-host storage, access controls, and
retention monitoring; run the restore verification against that storage too.

## API contract

The versioned API contract is generated from Laravel routes with Scramble. After
starting the stack, run `bash bin/export-api-contract` to update
`openapi/openapi.yaml` and copy it to `../mobile/contracts/openapi.yaml`.
Configure the API repository's `MOBILE_REPOSITORY` variable and
`MOBILE_REPOSITORY_TOKEN` secret to send a GitHub `repository_dispatch` event
when a pushed API change updates the contract. The mobile repository can then
generate its client from the synced OpenAPI document.
