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
bash bin/compose exec app php artisan migrate --seed
bash bin/test
bash bin/compose exec app php artisan app:reset-demo
bash bin/compose logs -f
bash bin/compose down
```

The local web app is at <http://localhost:8200>; Mailpit is at
<http://localhost:8125>; Redis is available on host port 6380. The application
connects to Aiven MySQL using `../kredensial/aiven-mysql-db.txt` and the
read-only CA mount `../kredensial/aiven-ca.pem`. `bin/compose` passes credentials
to Docker without copying them into `.env`; Docker services still hold database
credentials in their runtime environment. Do not use the Aiven database for
unreviewed migrations or destructive operations.
Use `bash bin/test` rather than running tests in the local app environment; it
forces an in-memory SQLite database so tests cannot reset or write to the
development Aiven database. The unused Aiven schema was initialized for GoMad
after explicit approval to drop its former tables. Continue with additive
`migrate --seed` only. `app:reset-demo` is reserved for a disposable local
database and refuses to run against remote MySQL servers; never run
`migrate:fresh` against Aiven again.

## Database backups

The scheduler writes daily SQL dumps from Aiven to local `backups/`, retains 30
days, and uses a temporary owner-only database credentials file. MySQL dump
connections verify TLS using the Aiven CA. Verify a dump by restoring it into a
disposable local MariaDB instance (started only for this check):

```sh
bash bin/compose exec app php artisan migrate --seed
bash bin/compose exec app php artisan app:backup-db
bash bin/verify-backup-restore
```

Local backups are not off-site backups. Cloudflare R2 stores private daily dumps under the `backups/` prefix. Verify
both the local dump and the latest R2 object by restoring them into a disposable
local MariaDB database:

```sh
bash bin/verify-backup-restore
bash bin/verify-backup-restore --r2-latest
```

The S3-compatible adapter and bucket are configured from
`../kredensial/cloudflare-storage-r2.txt`; object uploads are size-verified and
expire after 30 days. Keep the R2 token limited to the dedicated backup bucket.

## API contract

The versioned API contract is generated from Laravel routes with Scramble. After
starting the stack, run `bash bin/export-api-contract` to update
`openapi/openapi.yaml` and copy it to `../mobile/contracts/openapi.yaml`.
Configure the API repository's `MOBILE_REPOSITORY_TOKEN` secret with a token
that has write access to the mobile repository. On a pushed API contract
change, CI sends the generated OpenAPI document in a `repository_dispatch`
event; the check fails explicitly if that secret is missing. The mobile
workflow validates the source and document, then commits only when the
contract has changed, without needing a token to read the API repository.

## Render staging

The Render web service uses `docker/render/Dockerfile` to serve Nginx and
PHP-FPM in one container. The Fase 0 staging instance uses an isolated,
ephemeral SQLite database, file sessions/cache, and synchronous queues; it
does not connect to Aiven or share local data. Its health check is
`/api/v1/health`. Do not use this minimal staging configuration for production.
