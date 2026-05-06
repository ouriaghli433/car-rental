# Car Rental API

A REST API backend for a car rental platform built with **Laravel 11** and **PostgreSQL**.  
Frontend team: the API runs at `http://localhost:8000/api` after one command — see [Quick Start](#quick-start).

---

## Tech Stack

| Layer | Technology |
|---|---|
| Language | PHP 8.2 |
| Framework | Laravel 11 |
| Auth | Laravel Sanctum (Bearer token) |
| Database | PostgreSQL 15 |
| Containerization | Docker + Docker Compose |
| Tests | PHPUnit (77 tests) |

---

## Quick Start

You only need **Docker Desktop** installed. No PHP or PostgreSQL required on your machine.

```bash
# 1. Clone the repo
git clone <repo-url>
cd car-rental

# 2. Create your local env file
cp .env.example .env

# 3. Generate the app key
docker compose run --rm app php artisan key:generate

# 4. Start everything (builds image, runs migrations, seeds DB, starts server)
docker compose up --build
```

API is live at **http://localhost:8000/api**

To stop: `docker compose down`

---

## Roles

| Role | What they can do |
|---|---|
| `client` | Browse cars, make reservations, confirm pickup, leave reviews |
| `agency_owner` | Manage their agency, cars, images, maintenance windows, confirm/cancel reservations |
| `admin` | Approve agencies, manage users, top up balances, view platform stats |

Default seeded accounts (password: `123456`):

| Email | Role |
|---|---|
| admin@test.com | admin |
| owner@test.com | agency_owner |
| client@test.com | client |

---

## API Reference

All endpoints are prefixed with `/api`. Protected routes require:
```
Authorization: Bearer <token>
```

### Authentication

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| POST | `/register` | No | Register a new user |
| POST | `/login` | No | Login and receive a Bearer token |
| POST | `/logout` | Yes | Revoke the current token |
| GET | `/me` | Yes | Get the authenticated user's profile |

**Register body:**
```json
{
  "first_name": "John",
  "last_name": "Doe",
  "email": "john@example.com",
  "password": "12345678",
  "password_confirmation": "12345678",
  "role": "client"
}
```
`role` can be `client` or `agency_owner`.

**Login body:**
```json
{ "email": "client@test.com", "password": "123456" }
```

**Login response:**
```json
{ "token": "1|abc123...", "user": { ... } }
```

---

### Cities

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| GET | `/cities` | No | List all active cities |

---

### Cars

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| GET | `/cars` | No | List cars with optional filters |
| GET | `/cars/{id}` | No | Get full car details |
| POST | `/cars` | agency_owner | Add a car to your agency |
| PUT | `/cars/{id}` | agency_owner | Update your car |
| DELETE | `/cars/{id}` | agency_owner | Soft-delete your car |
| POST | `/cars/{id}/images` | agency_owner | Add an image to a car |
| DELETE | `/cars/{id}/images/{imageId}` | agency_owner | Remove a car image |
| POST | `/cars/{id}/maintenance` | agency_owner | Schedule a maintenance period |
| DELETE | `/cars/{id}/maintenance/{periodId}` | agency_owner | Remove a maintenance period |
| GET | `/cars/{id}/reviews` | No | List reviews for a car |

**Car filters (query params):**
```
GET /api/cars?city_id=...&type=sedan&transmission=automatic&min_price=100&max_price=500&start_date=2027-07-01&end_date=2027-07-05
```
When `start_date` + `end_date` are provided, only available cars are returned (no overlapping reservations or maintenance).

---

### Agencies

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| GET | `/agencies` | No | List all approved agencies |
| GET | `/agencies/{id}` | No | Get agency details + available cars |
| POST | `/agencies` | agency_owner | Register a new agency (starts as `pending`) |
| PUT | `/agencies/{id}` | agency_owner | Submit a profile update (goes to `pending_changes` for admin review) |
| GET | `/agencies/{id}/reviews` | No | List reviews for an agency |

---

### Reservations

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| GET | `/reservations` | Yes | List reservations (filtered by role) |
| POST | `/reservations` | client | Create a reservation |
| GET | `/reservations/{id}` | Yes | Get reservation details |
| POST | `/reservations/{id}/confirm` | agency_owner | Confirm a pending reservation |
| POST | `/reservations/{id}/cancel` | client / agency_owner | Cancel a reservation |
| POST | `/reservations/{id}/pickup` | client | Confirm physical car pickup |
| POST | `/reservations/{id}/review` | client | Post a review (reservation must be completed) |

**Create reservation body:**
```json
{
  "car_id": "uuid",
  "start_date": "2027-07-01 17:00",
  "end_date": "2027-07-03 17:00"
}
```
Dates use `YYYY-MM-DD HH:MM` format. Billing is `ceil(hours / 24) × price_per_day`.  
Pending reservations expire automatically after 1 hour if not confirmed.

**Reservation lifecycle:**
```
pending → confirmed → (picked up) → completed
       ↘           ↘
      cancelled   cancelled
```

**Cancellation rules:**
- Clients and agencies are each limited to **2 cancellations per day**. Exceeding this blocks the account for 24 hours.
- Cannot cancel after pickup.

---

### Payments

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| GET | `/payments` | Yes | List payments (admin sees all, agency_owner sees own) |

---

### Admin

All admin endpoints require `role = admin`.

| Method | Endpoint | Description |
|---|---|---|
| GET | `/admin/users` | List all users (paginated) |
| PATCH | `/admin/users/{id}/status` | Set user status: `active` / `blocked` |
| GET | `/admin/agencies` | List all agencies (any status) |
| PATCH | `/admin/agencies/{id}/status` | Set agency status: `approved` / `rejected` / `pending` |
| POST | `/admin/agencies/{id}/top-up` | Add funds to agency balance |
| POST | `/admin/agencies/{id}/approve-changes` | Apply a pending profile update |
| POST | `/admin/agencies/{id}/reject-changes` | Discard a pending profile update |
| GET | `/admin/stats` | Platform stats (users, agencies, cars, reservations, revenue) |

---

## Scheduled Commands

Three commands run automatically via Laravel Scheduler:

| Command | Schedule | What it does |
|---|---|---|
| `reservations:expire` | Every minute | Cancels pending reservations older than 1 hour |
| `reservations:complete` | Daily 01:00 | Marks confirmed+picked-up reservations as completed after end_date passes |
| `users:reset-cancel-counts` | Daily 00:00 | Resets the daily cancellation counter for all users |

To run the scheduler inside Docker:
```bash
docker compose exec app php artisan schedule:run
```

---

## Useful Commands

```bash
# Run all tests
docker compose exec app php artisan test

# Fresh database with seed data
docker compose exec app php artisan migrate:fresh --seed

# Open a shell inside the container
docker compose exec app bash

# View live logs
docker compose logs -f app
```

---

## Environment Variables

Copy `.env.example` to `.env`. Key variables:

| Variable | Description | Default |
|---|---|---|
| `DB_HOST` | Database host (`db` when using Docker) | `db` |
| `DB_DATABASE` | Database name | `car_rental` |
| `DB_USERNAME` | PostgreSQL user | `postgres` |
| `DB_PASSWORD` | PostgreSQL password | `secret` |
| `APP_KEY` | Laravel encryption key (generate with `key:generate`) | — |
