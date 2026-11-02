# Enterprise Support Ticket & SLA Management System

[![Laravel 11](https://img.shields.io/badge/Laravel-11%2F12-FF2D20?style=flat&logo=laravel)](https://laravel.com)
[![PHP 8.3](https://img.shields.io/badge/PHP-8.3-777BB4?style=flat&logo=php)](https://php.net)
[![MySQL 8.0](https://img.shields.io/badge/MySQL-8.0-4479A1?style=flat&logo=mysql)](https://mysql.com)
[![Redis 7](https://img.shields.io/badge/Redis-7-DC382D?style=flat&logo=redis)](https://redis.io)
[![Docker](https://img.shields.io/badge/Docker-Compose-2496ED?style=flat&logo=docker)](https://docker.com)
[![Tests Passing](https://img.shields.io/badge/Tests-232%20Passing-success)](https://phpunit.de)

An enterprise-grade, containerized Support Ticket and SLA Management System built with **Laravel 11**, **PHP 8.3**, **MySQL 8.0**, and **Redis 7**. Engineered for data integrity under concurrent write loads, deterministic business-hours SLA tracking, strict role-based data isolation, and memory-safe streaming of large datasets.

---

## 1. System Architecture & Topology

The system runs across 6 containerized services orchestrated via Docker Compose on a dedicated bridge network (`ticket_network`), with zero PHP/Composer host prerequisites.

```mermaid
flowchart TD
    subgraph Client["Client Browser / API Consumer"]
        Browser["User Web Browser"]
    end

    subgraph Host["Host Machine Network"]
        PortHTTP["Port 8090 (Nginx HTTP)"]
        PortDB["Port 3307 (MySQL)"]
        PortRedis["Port 6380 (Redis)"]
    end

    subgraph DockerNet["ticket_network (Docker Bridge)"]
        Nginx["ticket_nginx (Nginx 1.27 Alpine)"]
        App["ticket_app (PHP 8.3 FPM Alpine)"]
        Worker["ticket_queue_worker (Queue Worker)"]
        Scheduler["ticket_scheduler (Cron Scheduler)"]
        DB[("ticket_db (MySQL 8.0)\nNamed Vol: db_data")]
        Redis[("ticket_redis (Redis 7)\nNamed Vol: redis_data")]
    end

    Browser -->|HTTP Requests| PortHTTP --> Nginx
    Nginx -->|FastCGI (9000)| App

    App -->|Read / Write (3306)| DB
    App -->|Sessions, Cache, Queues (6379)| Redis
    App -->|Pushes Jobs| Redis

    Redis -->|Consumes Jobs| Worker
    Worker -->|Processes Async Imports / Emails| DB

    Scheduler -->|Every Minute: tickets:check-sla| App
```

### Container Stack & Port Matrix

| Service | Container Name | Base Image | Internal Port | Host Port | Role |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Nginx** | `ticket_nginx` | `nginx:alpine` | `80` | **`8090`** | Reverse proxy, static asset delivery, HTTP security |
| **PHP App** | `ticket_app` | `php:8.3-fpm-alpine` | `9000` | *Internal* | Web requests, REST endpoints, Blade rendering |
| **MySQL** | `ticket_db` | `mysql:8.0` | `3306` | **`3307`** | Primary relational datastore (InnoDB) |
| **Redis** | `ticket_redis` | `redis:7-alpine` | `6379` | **`6380`** | Job queues, caching, session persistence |
| **Queue Worker** | `ticket_queue_worker` | `php:8.3-fpm-alpine` | N/A | *Internal* | Non-blocking queued notifications, CSV chunk processing |
| **Scheduler** | `ticket_scheduler` | `php:8.3-fpm-alpine` | N/A | *Internal* | 60-second loop dispatching automated SLA escalation audits |

---

## 2. Quick Start & Installation

### Prerequisites
- [Docker](https://docs.docker.com/get-docker/) (v24.0+)
- [Docker Compose](https://docs.docker.com/compose/) (v2.20+)
- `make` (optional, for convenient shortcut execution)

### 1-Command Automated Setup
From the repository root, run:
```bash
make fresh
```
*Or follow the step-by-step instructions below.*

### Step-by-Step Manual Setup

1. **Clone & Initialize Environment:**
   ```bash
   cp .env.example src/.env
   ```

2. **Build and Launch Containers:**
   ```bash
   make up
   # Or without make: docker compose up -d --build
   ```

3. **Install Dependencies & Generate Application Key:**
   ```bash
   make composer c="install --no-interaction"
   make artisan c="key:generate"
   ```

4. **Run Migrations & Seed Demo Data:**
   ```bash
   make fresh
   # Or without make: docker compose exec app php artisan migrate:fresh --seed
   ```

5. **Build Frontend Assets:**
   ```bash
   make assets
   # Compiles Tailwind CSS & Vite bundle via ephemeral Node 22 container
   ```

6. **Access the Application:**
   Open your browser at **[http://localhost:8090](http://localhost:8090)**.

---

## 3. Seeded Demo Accounts

The database seeder initializes 25 realistic support tickets, threaded messages, SLA tracking records, and 4 role-based user accounts with password `password`:

| Role | Email | Password | Permissions & Workspace |
| :--- | :--- | :--- | :--- |
| **Administrator** | `admin@example.com` | `password` | Global ticket management, category administration, agent workload, SLA reports, CSV bulk imports, delete privileges |
| **Support Agent** | `agent1@example.com` | `password` | Queue triage, claiming unassigned tickets, internal notes, resolving tickets, CSV bulk imports |
| **Support Agent** | `agent2@example.com` | `password` | Workload balancing, public replies, internal team collaboration |
| **Customer** | `customer@example.com` | `password` | Customer portal, ticket submission, conversation participation, self-service close |

---

## 4. Background Workers & Automated Scheduler

The system uses dedicated background worker containers configured in `docker-compose.yml`:

### Queue Worker (`ticket_queue_worker`)
Runs `php artisan queue:work redis --queue=default,imports --sleep=3 --tries=3 --timeout=600`
- **Asynchronous Notifications:** Offloads `TicketCreatedNotification`, `TicketAssignedNotification`, `TicketRepliedNotification`, `TicketResolvedNotification`, and `SlaBreachedNotification` via queued Mailable/Notification classes implementing `ShouldQueue`.
- **Fault-Tolerant CSV Imports:** Processes multi-thousand row files in 200-row chunks asynchronously without blocking HTTP workers.

### Scheduler Daemon (`ticket_scheduler`)
Executes `php artisan schedule:run` every 60 seconds:
- **`tickets:check-sla`:** Evaluates all active tickets against SLA due dates and warning thresholds. Escalates tickets from `within_sla` $\rightarrow$ `approaching_breach` $\rightarrow$ `breached`, sets `escalated_at`, logs audit entries, and dispatches queued email notifications.

### Manual Verification Commands
```bash
# Manually run the SLA escalation checker
make artisan c="tickets:check-sla"

# Inspect queue status
make artisan c="queue:monitor default,imports"

# View live queue worker logs
make logs s=queue_worker

# View live scheduler logs
make logs s=scheduler
```

---

## 5. Automated Testing Suite

The application includes an exhaustive test suite of **232 tests with 5,237 assertions** maintaining 100% pass rate:

```bash
# Run the complete test suite
make test

# Run a filtered test class
make test-filter filter="TicketSearchTest"
make test-filter filter="DashboardAnalyticsTest"
make test-filter filter="TicketImportTest"
make test-filter filter="ConcurrentAssignmentTest"
```

### Test Suite Composition
- **Unit Tests (`tests/Unit/`):**
  - `BusinessHoursCalculatorTest`: Immutable `CarbonImmutable` business hour arithmetic, holiday exclusions, Sunday–Thursday shift handling.
  - `SlaEvaluationServiceTest`: SLA duration mapping by priority, warning threshold calculations.
  - `TicketNumberGeneratorTest`: Year-prefixed 6-digit zero-padded sequence generation with row-level locks.
- **Feature Tests (`tests/Feature/`):**
  - `RoleMiddlewareAndNavigationTest`: Route-level RBAC enforcement and navigation bar isolation.
  - `TicketLifecycleTest`: State machine transitions, reopened workflows on customer replies.
  - `ConcurrentAssignmentTest`: Multi-process database locking simulation preventing double-claim races.
  - `TicketConversationTest`: Public replies vs. staff-only internal notes isolation.
  - `TicketSearchTest`: Multi-filter search engine (status, priority, agent, category, SLA status, date ranges).
  - `DashboardAnalyticsTest`: Single-query conditional SQL aggregations without N+1 query regressions.
  - `TicketImportTest`: Memory-safe streaming, chunked execution, row-by-row failure reporting.

---

## 6. Key Architectural & Engineering Decisions (ADRs)

### ADR 1: Atomic Row-Level Locking for Ticket ID Generation
- **Problem:** Using `Ticket::count() + 1` or `max('id')` causes duplicate key collisions under concurrent write traffic.
- **Decision:** Implemented `app/Services/TicketNumberGenerator.php` backed by a dedicated `ticket_sequences` table. Uses `SELECT current_sequence FROM ticket_sequences WHERE year = ? FOR UPDATE` inside a database transaction.
- **Format:** `TKT-YYYY-NNNNNN` (e.g., `TKT-2026-000042`). Guarantees sequential, collision-free numbers even across distributed app instances.

### ADR 2: Atomic Concurrency Control in Ticket Assignment & Claims
- **Problem:** When two agents click "Claim" on the same unassigned ticket at the same millisecond, standard ORM checks create race conditions (Time-of-Check to Time-of-Use / TOCTOU).
- **Decision:** Implemented database-level atomic conditional updates:
  ```php
  $affected = Ticket::query()
      ->where('id', $ticket->id)
      ->whereNull('assigned_agent_id')
      ->whereNotIn('status', [TicketStatus::CLOSED->value])
      ->update([
          'assigned_agent_id' => $agent->id,
          'status' => TicketStatus::IN_PROGRESS->value,
      ]);

  if ($affected === 0) {
      throw new TicketAlreadyAssignedException();
  }
  ```
  Guarantees exactly one agent wins the claim; the losing agent receives HTTP 409 Conflict with an informative message.

### ADR 3: Deterministic Business-Hours SLA Calculation Engine
- **Problem:** SLA clocks must not run outside business operating hours (e.g., Friday & Saturday weekends, nights between 18:00 and 09:00, or holidays).
- **Decision:** Built `app/Services/Sla/BusinessHoursCalculator.php` using immutable dates (`CarbonImmutable`):
  - Work week: Sunday through Thursday (09:00 – 18:00; 9 hours / 540 minutes per day).
  - Calculates exact resolution timestamps by slicing remaining minutes across valid business intervals, skipping off-hours, weekends, and configured holiday arrays.
  - Computes warning threshold timestamp at 80% elapsed time (`sla_warning_at`).

### ADR 4: Multi-Layer Information Leak Protection (Internal Notes & Tenancy)
- **Problem:** Customers must never see, receive, or be notified about staff internal notes or other customers' tickets.
- **Decision:**
  - **Database Scope:** `Ticket::query()->visibleTo($user)` isolates tickets to `created_by = $user->id` for customers, while granting global visibility to staff.
  - **Granular Policies:** `TicketMessagePolicy` rejects any request where `is_internal = true` from non-staff users with HTTP 403.
  - **Notification Filter:** Event listeners suppress notifications to customers when an internal note is added.

### ADR 5: Memory-Safe Streamed CSV Bulk Ingestion
- **Problem:** Uploading CSV files with 50,000+ rows causes PHP memory exhaustion (`Allowed memory size of X bytes exhausted`).
- **Decision:** Built `app/Services/Import/CsvReaderService.php` leveraging PHP stream generators (`fgetcsv`) wrapped in `LazyCollection`:
  - Memory consumption stays constant ($O(1)$) regardless of file size.
  - Normalizes headers on the fly (case-insensitive, trims UTF-8 BOM, aliases).
  - Background job `ProcessTicketImportJob` streams rows in 200-row chunks.
  - Valid rows create tickets with SLA dates; invalid rows log granular field errors into `ticket_import_failures` without rolling back valid rows.

### ADR 6: High-Performance Database & Indexing Strategy
- **Composite Indexes:**
  - `(status, priority)`: Optimized for dashboard counters and queue triage.
  - `(assigned_agent_id, status)`: Optimized for agent workload and my-queue queries.
  - `(sla_status, sla_due_at)`: Optimized for the 60-second scheduler escalation checks.
  - `(user_id, status)`: Optimized for customer ticket history.
- **Single-Query Dashboard Aggregation:**
  - Instead of running 7+ separate count queries, `DashboardAnalyticsService` executes a single SQL query using conditional `COUNT(CASE WHEN ... THEN 1 END)` aggregation, eliminating N+1 overhead and keeping dashboard response times $< 20\text{ms}$.

---

## 7. CSV Bulk Import Specification

Staff members can upload CSV files up to 20MB at `/imports`.

### Sample CSV Format
```csv
subject,description,priority,category,customer_email,assigned_agent_email,status,ticket_number
"Database connectivity timeout","Application cannot connect to MySQL during peak load","high","technical-inquiry","customer@example.com","agent1@example.com","open",""
"Invoice payment discrepancy","Monthly invoice shows extra charges for unallocated seats","medium","billing-support","customer@example.com","","open",""
```

### Validation Rules
- `subject`: `required|string|max:255`
- `description`: `required|string|min:10`
- `priority`: `required|in:low,medium,high,critical`
- `category`: `required` (matches active category slug or name)
- `customer_email`: `required|email|exists:users,email`
- `assigned_agent_email`: `nullable|email|exists:users,email` (must have `agent` or `admin` role)
- `ticket_number`: `nullable|string|max:32` (if omitted, auto-generated; if supplied, uniqueness is verified)

---

## 8. Business Requirements to Implementation Mapping

| # | Business Requirement | Primary Implementation File(s) |
| :-: | :--- | :--- |
| **1** | User Roles & RBAC (Admin, Agent, Customer) | [`app/Enums/UserRole.php`](file:///home/cs/projects/support-ticket/src/app/Enums/UserRole.php), [`app/Http/Middleware/EnsureUserHasRole.php`](file:///home/cs/projects/support-ticket/src/app/Http/Middleware/EnsureUserHasRole.php) |
| **2** | Sequential Collision-Free Ticket Numbering | [`app/Services/TicketNumberGenerator.php`](file:///home/cs/projects/support-ticket/src/app/Services/TicketNumberGenerator.php), `ticket_sequences` table |
| **3** | Ticket Category Management | [`app/Models/TicketCategory.php`](file:///home/cs/projects/support-ticket/src/app/Models/TicketCategory.php), [`database/seeders/TicketCategorySeeder.php`](file:///home/cs/projects/support-ticket/src/database/seeders/TicketCategorySeeder.php) |
| **4** | Dynamic SLA Calculator (Business Hours) | [`app/Services/Sla/BusinessHoursCalculator.php`](file:///home/cs/projects/support-ticket/src/app/Services/Sla/BusinessHoursCalculator.php), [`config/sla.php`](file:///home/cs/projects/support-ticket/src/config/sla.php) |
| **5** | Ticket Assignment & Concurrency Protection | [`app/Services/Assignment/TicketAssignmentService.php`](file:///home/cs/projects/support-ticket/src/app/Services/Assignment/TicketAssignmentService.php), `TicketAlreadyAssignedException` |
| **6** | Ticket Status Lifecycle & Transitions | [`app/Enums/TicketStatus.php`](file:///home/cs/projects/support-ticket/src/app/Enums/TicketStatus.php), [`app/Http/Controllers/TicketController.php`](file:///home/cs/projects/support-ticket/src/app/Http/Controllers/TicketController.php) |
| **7** | Threaded Conversations & Internal Notes | [`app/Models/TicketMessage.php`](file:///home/cs/projects/support-ticket/src/app/Models/TicketMessage.php), [`app/Policies/TicketMessagePolicy.php`](file:///home/cs/projects/support-ticket/src/app/Policies/TicketMessagePolicy.php) |
| **8** | Secure Attachment Handling (Private Disk) | [`app/Services/AttachmentService.php`](file:///home/cs/projects/support-ticket/src/app/Services/AttachmentService.php), [`app/Policies/TicketAttachmentPolicy.php`](file:///home/cs/projects/support-ticket/src/app/Policies/TicketAttachmentPolicy.php) |
| **9** | Automated SLA Escalation Engine | [`app/Services/Sla/SlaEscalationService.php`](file:///home/cs/projects/support-ticket/src/app/Services/Sla/SlaEscalationService.php), [`app/Console/Commands/CheckSlaEscalationsCommand.php`](file:///home/cs/projects/support-ticket/src/app/Console/Commands/CheckSlaEscalationsCommand.php) |
| **10** | Comprehensive Activity Audit Trail | [`app/Services/Activity/TicketActivityLogger.php`](file:///home/cs/projects/support-ticket/src/app/Services/Activity/TicketActivityLogger.php), `ticket_activities` table |
| **11** | Queued Asynchronous Email Notifications | [`app/Notifications/`](file:///home/cs/projects/support-ticket/src/app/Notifications/) (`ShouldQueue`), Redis background worker |
| **12** | Multi-Filter Query Engine & Text Search | [`app/Services/TicketSearchService.php`](file:///home/cs/projects/support-ticket/src/app/Services/TicketSearchService.php), [`resources/views/tickets/index.blade.php`](file:///home/cs/projects/support-ticket/src/resources/views/tickets/index.blade.php) |
| **13** | Operations Dashboard & Analytics | [`app/Services/DashboardAnalyticsService.php`](file:///home/cs/projects/support-ticket/src/app/Services/DashboardAnalyticsService.php), [`resources/views/dashboard.blade.php`](file:///home/cs/projects/support-ticket/src/resources/views/dashboard.blade.php) |
| **14** | Memory-Safe Streaming CSV Bulk Import | [`app/Services/Import/CsvReaderService.php`](file:///home/cs/projects/support-ticket/src/app/Services/Import/CsvReaderService.php), [`app/Jobs/ProcessTicketImportJob.php`](file:///home/cs/projects/support-ticket/src/app/Jobs/ProcessTicketImportJob.php) |
| **15** | Row-by-Row Import Error Reporting | [`app/Models/TicketImportFailure.php`](file:///home/cs/projects/support-ticket/src/app/Models/TicketImportFailure.php), [`resources/views/imports/show.blade.php`](file:///home/cs/projects/support-ticket/src/resources/views/imports/show.blade.php) |
| **16** | Containerized Microservices Stack | `docker-compose.yml`, `.docker/php/Dockerfile`, `.docker/nginx/default.conf` |
| **17** | Developer Tooling & Automation | `Makefile`, `pint.json` |

---

## 9. Useful Make Commands

```bash
make help          # Show available targets
make up            # Start Docker containers
make down          # Stop Docker containers
make restart       # Restart Docker containers
make ps            # List running container statuses
make logs          # Tail container logs (e.g. make logs s=app)
make bash          # Open interactive shell in app container
make test          # Run PHPUnit test suite inside container
make test-filter   # Run specific test (e.g. make test-filter filter=TicketImport)
make lint          # Run Laravel Pint code formatter (PSR-12 strict)
make fresh         # Rebuild DB with fresh migrations & demo seeders
make assets        # Rebuild frontend Vite / Tailwind assets
```
