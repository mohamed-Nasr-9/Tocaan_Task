# Orders and Payments API

A versioned Laravel REST API for user-owned orders and simulated payment attempts. It uses PostgreSQL for local and deployed application data, JWT bearer authentication, and a configuration-driven payment gateway registry.

## Requirements

- PHP 8.4 or later with `pdo_pgsql` enabled.
- Composer 2.x.
- PostgreSQL and an empty database created for this application.

The API does not require a frontend build, Redis, a queue worker, or live payment credentials. The configured database-backed session, cache, and queue drivers have matching migrations.

## Setup

Create a PostgreSQL database for the application. Copy the example environment file only if `.env` does not already exist, then set the database name, username, and password in `.env` before migrating:

```powershell
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
```

Install dependencies, generate application and JWT secrets, apply the migrations, and start the local server:

```powershell
composer install
php artisan key:generate
php artisan jwt:secret
php artisan migrate
php artisan serve
```

The API base URL is `http://127.0.0.1:8000/api/v1`. Keep `.env` private. Use `APP_DEBUG=false` outside local development and store production secrets in the deployment environment. Review the target database before running migrations; the payment identity migration is additive and preserves existing rows.

The migrations create the `users`, `sessions`, `password_reset_tokens`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `orders`, `order_items`, and `payments` tables. A later additive migration adds nullable `payments.simulate_outcome`. Existing payment rows are not backfilled because their original simulation input cannot be reliably recovered.

## Authentication and API workflow

Register to receive a JWT:

```http
POST /api/v1/auth/register
Content-Type: application/json

{"name":"Alex Example","email":"alex@example.com","password":"replace-with-a-local-password","password_confirmation":"replace-with-a-local-password"}
```

Use the returned token as `Authorization: Bearer <token>`. `POST /api/v1/auth/login` accepts `email` and `password`; `GET /api/v1/auth/me` returns the current account, and `POST /api/v1/auth/logout` invalidates the current token.

Create an order. Prices are decimal strings with at most two fractional digits; the server calculates the total:

```http
POST /api/v1/orders
Authorization: Bearer <token>
Content-Type: application/json

{
  "customer_name": "Alex Example",
  "customer_email": "alex@example.com",
  "customer_phone": "+1 555 0100",
  "items": [
    {"product_name": "Coffee", "quantity": 2, "price": "12.34"},
    {"product_name": "Tea", "quantity": 1, "price": "5.34"}
  ]
}
```

The order starts as `pending`. Confirm it with `PATCH /api/v1/orders/{order}` and `{"status":"confirmed"}`. Then submit a simulated payment:

```http
POST /api/v1/orders/{order}/payments
Authorization: Bearer <token>
Idempotency-Key: checkout-unique-key-001
Content-Type: application/json

{"method":"credit_card","simulate_outcome":"success"}
```

`simulate_outcome` accepts `success` or `failure`. The idempotency key is required in the `Idempotency-Key` header. Repeating the same key with the same owner, order, method, and outcome returns the original payment. Reusing a key for a changed request returns HTTP 422. A failed payment may be retried with a new key. A successful payment prevents another attempt for that order.

The Postman collection and local environment are in `docs/postman/`. Import both, select the environment, and fill in the token and record IDs as you work. The environment contains no credentials or live tokens.

## Endpoints

| Method | Path | Description |
| --- | --- | --- |
| `POST` | `/api/v1/auth/register` | Register and receive a JWT |
| `POST` | `/api/v1/auth/login` | Log in |
| `GET` | `/api/v1/auth/me` | Return the authenticated user |
| `POST` | `/api/v1/auth/logout` | Invalidate the current JWT |
| `GET` | `/api/v1/orders` | List the caller's orders; supports `status` and `per_page` |
| `POST` | `/api/v1/orders` | Create an order |
| `GET` | `/api/v1/orders/{order}` | Retrieve an owned order |
| `PUT`, `PATCH` | `/api/v1/orders/{order}` | Update an owned order |
| `DELETE` | `/api/v1/orders/{order}` | Delete an order without payment history |
| `GET` | `/api/v1/payments` | List the caller's payments |
| `GET` | `/api/v1/payments/{payment}` | Retrieve an owned payment |
| `GET` | `/api/v1/orders/{order}/payments` | List payment attempts for an owned order |
| `GET` | `/api/v1/orders/{order}/payments/{payment}` | Retrieve a payment under an owned order |
| `POST` | `/api/v1/orders/{order}/payments` | Create or replay a simulated payment attempt |

Pagination defaults to 15 records and allows 1–100. Validation errors return HTTP 422, missing or invalid authentication returns 401, and another user's resources return 404. Successful creates return 201; successful deletion returns 204.

## Domain rules

- Order states are `pending`, `confirmed`, and `cancelled`. Allowed transitions are pending to confirmed, pending to cancelled, and confirmed to cancelled. Cancellation is terminal.
- Only confirmed orders can be paid. Payments cover the full order total and preserve the amount and currency used for the attempt.
- Payment states are `pending`, `successful`, and `failed`. An in-progress attempt blocks another attempt; a successful payment blocks further attempts. Orders with any payment history cannot be deleted.
- Order edits are blocked during a pending payment and after a successful payment. Edits after failed attempts do not change earlier payment snapshots.
- Idempotency keys are unique per user. For historical payment rows where `simulate_outcome` is null, the original request cannot be proven; replaying that key safely returns HTTP 422 and requires a new key.
- Currency defaults to USD and can be changed with `PAYMENTS_CURRENCY`. Prices must be from 0.01 to 999999.99, quantities from 1 to 1000, and an order may contain at most 50 items.
- Payment inputs are simulation-only. The API does not accept card numbers, CVVs, PayPal credentials, or requests to charge a live provider.

## Architecture and extension

API routes in `routes/api.php` call controllers in `app/Http/Controllers/Api/`. Form Requests validate writes, API Resources define response fields, and owner-scoped Eloquent relationships enforce data isolation. `OrderService` handles order totals and state rules. `PaymentService` handles eligibility, idempotency, immutable snapshots, gateway calls, and payment results. `PaymentGateway` is the strategy contract; `GatewayResolver` selects a container-resolved implementation from `config/payments.php`.

To add a gateway:

1. Implement `PaymentGateway::charge(int $amountMinor, string $currency, array $options = []): PaymentResult` in `app/Payments/`.
2. Register its key and class in `config/payments.php`.
3. Enable the key in `PAYMENTS_GATEWAYS` for the environment.
4. Add gateway and API feature tests.

The current gateways are local simulators. A real provider adapter needs provider-side idempotency, timeout handling, and reconciliation. Network calls should not run while holding the current database transaction; production integration should commit an attempt first, call the provider, then persist and reconcile its result.

## Tests and limitations

PHPUnit/Pest uses an in-memory SQLite database as configured in `phpunit.xml`; the suite does not use or migrate the configured PostgreSQL database. Run:

```powershell
php artisan test --compact
vendor/bin/pint --dirty --format agent
composer validate --no-check-publish
```

The tests cover authentication, ownership, order rules and totals, payment success and failure, retries, idempotency, gateway registration, and safe handling of unexpected gateway errors. Money conversion has unit tests.

These tests exercise sequential behavior. SQLite does not provide PostgreSQL's row-level `lockForUpdate()` guarantees, and concurrent production behavior has not been verified. A production database and any real payment provider require separate concurrency, idempotency, timeout, and reconciliation testing.
