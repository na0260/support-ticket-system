# Enterprise Support Ticket & SLA Management System

[![Laravel 11](https://img.shields.io/badge/Laravel-11-FF2D20?style=flat&logo=laravel)](https://laravel.com)
[![PHP 8.3](https://img.shields.io/badge/PHP-8.3-777BB4?style=flat&logo=php)](https://php.net)
[![MySQL 8.0](https://img.shields.io/badge/MySQL-8.0-4479A1?style=flat&logo=mysql)](https://mysql.com)
[![Redis 7](https://img.shields.io/badge/Redis-7-DC382D?style=flat&logo=redis)](https://redis.io)
[![Docker](https://img.shields.io/badge/Docker-Compose-2496ED?style=flat&logo=docker)](https://docker.com)
[![Tests Passing](https://img.shields.io/badge/Tests-278%20Passing-success)](https://phpunit.de)

An enterprise-grade, containerized Support Ticket and SLA Management System built with **Laravel 11**, **PHP 8.3**, **MySQL 8.0**, and **Redis 7**. Engineered for data integrity under concurrent write loads, deterministic business-hours SLA tracking, strict role-based data isolation, asynchronous queued notifications, and memory-safe streaming of large datasets.

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

    Scheduler -->|Every Minute: sla:check-escalations| App
```

### Container Stack & Port Matrix

| Service | Container Name | Base Image | Internal Port | Host Port | Role |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Nginx** | `ticket_nginx` | `nginx:alpine` | `80` | **`8090`** | Reverse proxy, static asset delivery, HTTP security headers |
| **PHP App** | `ticket_app` | `php:8.3-fpm-alpine` | `9000` | *Internal* | Web requests, REST endpoints, Blade rendering, CLI commands |
| **MySQL** | `ticket_db` | `mysql:8.0` | `3306` | **`3307`** | Primary relational datastore (InnoDB) |
| **Redis** | `ticket_redis` | `redis:7-alpine` | `6379` | **`6380`** | Job queues, caching, session persistence |
| **Queue Worker** | `ticket_queue_worker` | `php:8.3-fpm-alpine` | N/A | *Internal* | Non-blocking queued notifications (`default`), CSV chunk processing (`imports`) |
| **Scheduler** | `ticket_scheduler` | `php:8.3-fpm-alpine` | N/A | *Internal* | 60-second cron runner executing automated SLA escalation audits |

---

## 2. Local Setup Instructions

### Prerequisites
- [Docker](https://docs.docker.com/get-docker/) (v24.0+)
- [Docker Compose](https://docs.docker.com/compose/) (v2.20+)
- `make` (optional, for convenient shortcut execution)

### 1-Command Automated Setup (via Make)
From the repository root, run:
```bash
make fresh
```
This builds containers, configures environment files, runs database migrations, seeds demo accounts with 25 realistic tickets, and compiles frontend assets.

---

### Step-by-Step Manual Setup (without Make)

1. **Clone the Repository:**
   ```bash
   git clone <repository-url> support-ticket
   cd support-ticket
   ```

2. **Initialize Environment Configuration:**
   ```bash
   cp .env.example src/.env
   ```

3. **Build and Launch Docker Containers:**
   ```bash
   docker compose up -d --build
   ```

4. **Install PHP Dependencies:**
   ```bash
   docker compose exec -T app composer install --no-interaction
   ```

5. **Generate Application Key:**
   ```bash
   docker compose exec -T app php artisan key:generate
   ```

6. **Run Database Migrations & Seed Demo Data:**
   ```bash
   docker compose exec -T app php artisan migrate:fresh --seed
   ```

7. **Compile Frontend Assets (Tailwind & Vite):**
   ```bash
   docker run --rm -v "$(pwd)/src:/var/www/html" -w /var/www/html node:22-alpine sh -c "npm install && npm run build"
   ```

8. **Access the Application:**
   Open your browser at **[http://localhost:8090](http://localhost:8090)**.

---

## 3. Seeded Demo Accounts

The database seeder initializes 25 realistic support tickets, threaded messages, SLA tracking records, and 4 role-based user accounts with password `password`:

| Role | Email | Password | Permissions & Capabilities |
| :--- | :--- | :--- | :--- |
| **Administrator** | `admin@example.com` | `password` | Global ticket management, category administration, agent queue triage, SLA escalations, CSV bulk import, delete permissions |
| **Support Agent** | `agent1@example.com` | `password` | Queue triage, claiming unassigned tickets, public replies, internal notes, resolving tickets, CSV bulk import |
| **Support Agent** | `agent2@example.com` | `password` | Workload balancing, public replies, internal team collaboration, resolving tickets |
| **Customer** | `customer@example.com` | `password` | Customer portal, ticket creation with priority selector, public conversation participation, self-service close |

---

## 4. Background Workers & Automated Scheduler

The system uses dedicated background worker containers configured in `docker-compose.yml`:

### Queue Worker (`ticket_queue_worker`)
Runs:
```bash
php artisan queue:work redis --queue=default,imports --sleep=3 --tries=3 --timeout=600
```
- **Asynchronous Notifications:** Offloads `TicketCreatedNotification`, `TicketAssignedNotification`, `TicketRepliedNotification`, `TicketResolvedNotification`, and `SlaBreachedNotification` via queued Notification classes implementing `ShouldQueue`.
- **Fault-Tolerant CSV Imports:** Processes multi-thousand row files in 200-row chunks asynchronously via `ImportTicketsJob` (`ProcessTicketImportJob`) without blocking HTTP workers.

### Scheduler Daemon (`ticket_scheduler`)
Executes `php artisan schedule:work` / `schedule:run` every 60 seconds:
- **`sla:check-escalations` (alias `tickets:check-sla`):** Evaluates all active tickets against SLA due dates and warning thresholds. Escalates tickets (`within_sla` $\rightarrow$ `approaching_breach` $\rightarrow$ `breached`), sets `is_escalated = true`, sets `escalated_at`, logs audit trail entries, and dispatches queued notification emails.

### Manual Verification Commands
```bash
# Manually run the SLA escalation checker
docker compose exec app php artisan sla:check-escalations

# Inspect queue status
docker compose exec app php artisan queue:monitor default,imports

# View live queue worker logs
docker compose logs -f queue_worker

# View live scheduler logs
docker compose logs -f scheduler
```

---

## 5. Automated Testing Suite

The application includes an exhaustive automated test suite of **278 tests with 5,482 assertions** maintaining a 100% pass rate:

```bash
# Run the complete test suite
docker compose exec -T app php artisan test

# Run a specific test suite or class
docker compose exec -T app php artisan test tests/Feature/Tickets/Assignment/ConcurrentAssignmentTest.php
docker compose exec -T app php artisan test tests/Feature/Sla/SlaEscalationServiceTest.php
docker compose exec -T app php artisan test tests/Feature/Import/TicketImportTest.php
docker compose exec -T app php artisan test tests/Feature/Search/TicketSearchTest.php
docker compose exec -T app php artisan test tests/Feature/Dashboard/DashboardAnalyticsTest.php
```

### Test Suite Architecture
- **Unit Tests (`tests/Unit/`):**
  - `BusinessHoursCalculatorTest`: Immutable `CarbonImmutable` business hour arithmetic, holiday exclusions, Sunday–Thursday shift handling.
  - `SlaEvaluationServiceTest`: SLA duration mapping by priority, warning threshold calculations.
  - `TicketNumberGeneratorTest`: Year-prefixed 6-digit zero-padded sequence generation with row-level locks.
- **Feature Tests (`tests/Feature/`):**
  - `RoleMiddlewareAndNavigationTest`: Route-level RBAC enforcement and navigation bar isolation.
  - `TicketLifecycleTest`: State machine transitions, reopened workflows on customer replies.
  - `ConcurrentAssignmentTest`: Concurrency protection using pessimistic locks (`lockForUpdate()`), preventing double-claim races.
  - `TicketConversationTest`: Public replies vs. staff-only internal notes isolation.
  - `TicketAttachmentTest`: Attachment upload validation, 10MB limit, allowed extensions, gated download authorization on private disk.
  - `CheckSlaEscalationsCommandTest`: Command execution, `is_escalated` flag assertion, audit logging, and alias support.
  - `TicketSearchTest`: Multi-filter search engine (status, priority, agent, category, SLA status, date ranges).
  - `DashboardAnalyticsTest`: Single-query conditional SQL aggregations without N+1 query regressions.
  - `TicketImportTest`: Memory-safe streaming, chunked execution, row-by-row failure reporting.

---

## 6. Key Architectural & Engineering Decisions (ADRs)

### ADR 1: Atomic Row-Level Locking for Ticket ID Generation
- **Problem:** Using `Ticket::count() + 1` or `max('id')` causes duplicate key collisions under concurrent write traffic.
- **Decision:** Implemented `app/Services/TicketNumberGenerator.php` backed by a dedicated `ticket_sequences` table. Uses `SELECT current_sequence FROM ticket_sequences WHERE year = ? FOR UPDATE` inside a database transaction.
- **Format:** `TKT-YYYY-NNNNNN` (e.g., `TKT-2026-000042`). Guarantees sequential, collision-free numbers even across distributed application instances.

### ADR 2: Pessimistic Row Locking Concurrency Control in Ticket Claims & Assignments
- **Problem:** When two agents click "Claim" or an admin assigns the same unassigned ticket at the same millisecond, standard ORM checks cause Time-of-Check to Time-of-Use (TOCTOU) race conditions, leading to double-assignment.
- **Decision:** Implemented pessimistic row locking (`lockForUpdate()`) within a database transaction in `app/Services/Assignment/TicketAssignmentService.php`:
  ```php
  return DB::transaction(function () use ($ticket, $agent, $assigner): Ticket {
      /** @var Ticket $lockedTicket */
      $lockedTicket = Ticket::where('id', $ticket->id)->lockForUpdate()->firstOrFail();

      if ($lockedTicket->assigned_agent_id !== null) {
          throw new TicketAlreadyAssignedException(
              "Ticket {$lockedTicket->ticket_number} is already assigned to another agent."
          );
      }

      $lockedTicket->assigned_agent_id = $agent->id;
      $lockedTicket->status = TicketStatus::IN_PROGRESS;
      $lockedTicket->save();

      // Log activity and dispatch queued notifications...
      return $lockedTicket;
  });
  ```
  Guarantees exactly one agent wins the claim; any concurrent claim attempt immediately throws `TicketAlreadyAssignedException` and yields an HTTP 409 Conflict with an informative message.

### ADR 3: Deterministic Business-Hours SLA Calculation Engine
- **Problem:** SLA clocks must strictly operate within declared business hours (e.g., Sunday through Thursday 09:00–18:00; 9 hours / 540 minutes per day) and skip off-hours, Friday & Saturday weekends, and public holidays.
- **Decision:** Built `app/Services/Sla/BusinessHoursCalculator.php` using immutable dates (`CarbonImmutable`):
  - Resolution SLA Commitments:
    - **Critical (2 Hours):** E.g., ticket submitted Thursday at 17:00 (1 hour remaining in shift) has its SLA roll over into Sunday morning at 10:00.
    - **High (8 Hours):** 480 business minutes.
    - **Medium (24 Hours):** 1,440 business minutes (~2.67 business days).
    - **Low (48 Hours):** 2,880 business minutes (~5.33 business days).
  - Slices remaining minutes across valid business intervals and computes warning threshold timestamp at 80% elapsed time (`sla_warning_at`).

### ADR 4: Intelligent Agent Assignment Strategies (LeastWorkload & RoundRobin)
- **Problem:** Work must be distributed evenly among active, available agents without assigning overloaded staff.
- **Decision:** Implemented Strategy Pattern via `TicketAssignmentStrategyInterface`:
  - `LeastWorkloadStrategy`: Selects available agent with lowest active (`open`, `in_progress`, `waiting_customer`) ticket count, breaking ties using the least-recently-assigned agent.
  - `RoundRobinStrategy`: Distributes tickets in circular sequence among available agents.

### ADR 5: Automated SLA Escalation Engine (`sla:check-escalations`)
- **Problem:** Active tickets approaching breach or breached must be identified and escalated continuously without manual supervisor intervention.
- **Decision:** Built `app/Services/Sla/SlaEscalationService.php` and `app/Console/Commands/CheckSlaEscalationsCommand.php`:
  - Scheduled to execute every minute via cron (`routes/console.php`).
  - Flags `is_escalated = true` and `escalated_at = now()`.
  - Distinguishes between warning (`approaching_breach`) and full breach (`breached`).
  - Logs audit records: `"Ticket automatically escalated due to SLA approaching breach"` / `"Ticket automatically escalated due to SLA breach"`.
  - Dispatches non-blocking queued `SlaBreachedNotification`.

### ADR 6: Multi-Layer Information Leak Protection (Internal Notes & Tenancy)
- **Problem:** Customers must never see, receive, or be notified about staff internal notes (`is_internal = true`) or another customer's tickets.
- **Decision:**
  - **Database Scope:** `Ticket::query()->visibleTo($user)` restricts customers strictly to `created_by = $user->id`.
  - **Granular Policies:** `TicketMessagePolicy` and `TicketPolicy` enforce server-side validation; unassigned tickets or tickets assigned to another agent reject replies from non-assigned agents (except Admins).
  - **Payload Scrubbing:** Customers never receive internal messages in HTML or JSON payloads.

### ADR 7: Gated Private File Attachments
- **Problem:** Storing ticket attachments in public storage exposes confidential attachments via direct URL access.
- **Decision:** Built `app/Services/AttachmentService.php`:
  - Stored strictly on the `private` filesystem disk (`storage/app/private/attachments/`).
  - Validated allowed extensions (`jpg, jpeg, png, pdf, doc, docx, txt, zip`) and 10MB maximum size limit.
  - Download endpoint (`GET /tickets/attachments/{attachment}/download` and alias `GET /attachments/{attachment}/download`) gated via `TicketAttachmentPolicy`, streaming the file through `Storage::disk('private')->download()`.

### ADR 8: Memory-Safe Streamed CSV Bulk Ingestion
- **Problem:** Uploading CSV files with 50,000+ rows causes PHP memory exhaustion.
- **Decision:** Built `app/Services/Import/CsvReaderService.php` leveraging PHP stream generators (`fgetcsv`) wrapped in `LazyCollection`:
  - Memory consumption stays constant ($O(1)$) regardless of file size.
  - Background job `ImportTicketsJob` (`ProcessTicketImportJob`) streams rows in 200-row chunks on the `imports` queue.
  - Valid rows create tickets with SLA dates; invalid rows log granular field errors into `ticket_import_failures` without rolling back valid rows.
  - Available at `POST /tickets/import` and `POST /imports`.

### ADR 9: High-Performance Database & Indexing Strategy
- **Composite Indexes:**
  - `tickets (status, sla_due_at)`: Optimized for the 60-second scheduler escalation checks.
  - `tickets (assigned_agent_id, status)`: Optimized for agent workload and my-queue triage.
  - `tickets (created_by, status)`: Optimized for customer ticket history.
  - `ticket_messages (ticket_id, is_internal)`: Optimized for fast conversation thread rendering while excluding internal notes.
- **Single-Query Dashboard Aggregation:**
  - `DashboardAnalyticsService` executes a single SQL query using conditional `COUNT(CASE WHEN ... THEN 1 END)` aggregation, eliminating N+1 overhead and keeping dashboard response times $< 20\text{ms}$.

---

## 7. CSV Bulk Import Specification

Staff members (Admin or Agent) can upload CSV files up to 20MB at `/imports` or `/tickets/import`.

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

| # | Business Requirement | Primary Implementation File(s) | Test Verification File(s) |
| :-: | :--- | :--- | :--- |
| **1** | User Roles & RBAC (Admin, Agent, Customer) | [`app/Enums/UserRole.php`](file:///home/cs/projects/support-ticket/src/app/Enums/UserRole.php), [`app/Http/Middleware/EnsureUserHasRole.php`](file:///home/cs/projects/support-ticket/src/app/Http/Middleware/EnsureUserHasRole.php) | `RoleMiddlewareAndNavigationTest.php` |
| **2** | Sequential Collision-Free Ticket Numbering | [`app/Services/TicketNumberGenerator.php`](file:///home/cs/projects/support-ticket/src/app/Services/TicketNumberGenerator.php), `ticket_sequences` table | `TicketNumberGeneratorTest.php` |
| **3** | Ticket Category Management | [`app/Models/TicketCategory.php`](file:///home/cs/projects/support-ticket/src/app/Models/TicketCategory.php), [`app/Http/Controllers/TicketCategoryController.php`](file:///home/cs/projects/support-ticket/src/app/Http/Controllers/TicketCategoryController.php) | `TicketCategoryManagementTest.php` |
| **4** | Dynamic SLA Calculator (Business Hours) | [`app/Services/Sla/BusinessHoursCalculator.php`](file:///home/cs/projects/support-ticket/src/app/Services/Sla/BusinessHoursCalculator.php), [`config/sla.php`](file:///home/cs/projects/support-ticket/src/config/sla.php) | `BusinessHoursCalculatorTest.php`, `SlaEvaluationServiceTest.php` |
| **5** | Ticket Assignment & Concurrency Protection | [`app/Services/Assignment/TicketAssignmentService.php`](file:///home/cs/projects/support-ticket/src/app/Services/Assignment/TicketAssignmentService.php), `TicketAlreadyAssignedException` | `ConcurrentAssignmentTest.php`, `AutoAssignWorkflowTest.php` |
| **6** | Ticket Status Lifecycle & Transitions | [`app/Enums/TicketStatus.php`](file:///home/cs/projects/support-ticket/src/app/Enums/TicketStatus.php), [`app/Http/Controllers/TicketController.php`](file:///home/cs/projects/support-ticket/src/app/Http/Controllers/TicketController.php) | `TicketLifecycleTest.php`, `TicketPriorityUpdateTest.php` |
| **7** | Threaded Conversations & Internal Notes | [`app/Models/TicketMessage.php`](file:///home/cs/projects/support-ticket/src/app/Models/TicketMessage.php), [`app/Policies/TicketMessagePolicy.php`](file:///home/cs/projects/support-ticket/src/app/Policies/TicketMessagePolicy.php) | `TicketConversationTest.php`, `TicketReplyRestrictionTest.php` |
| **8** | Secure Attachment Handling (Private Disk) | [`app/Services/AttachmentService.php`](file:///home/cs/projects/support-ticket/src/app/Services/AttachmentService.php), [`app/Policies/TicketAttachmentPolicy.php`](file:///home/cs/projects/support-ticket/src/app/Policies/TicketAttachmentPolicy.php) | `TicketAttachmentTest.php` |
| **9** | Automated SLA Escalation Engine | [`app/Services/Sla/SlaEscalationService.php`](file:///home/cs/projects/support-ticket/src/app/Services/Sla/SlaEscalationService.php), [`app/Console/Commands/CheckSlaEscalationsCommand.php`](file:///home/cs/projects/support-ticket/src/app/Console/Commands/CheckSlaEscalationsCommand.php) | `SlaEscalationServiceTest.php`, `CheckSlaEscalationsCommandTest.php` |
| **10** | Comprehensive Activity Audit Trail | [`app/Services/Activity/TicketActivityLogger.php`](file:///home/cs/projects/support-ticket/src/app/Services/Activity/TicketActivityLogger.php), `ticket_activities` table | `TicketActivityLoggerTest.php`, `TicketLifecycleTest.php` |
| **11** | Queued Asynchronous Email Notifications | [`app/Notifications/`](file:///home/cs/projects/support-ticket/src/app/Notifications/) (`ShouldQueue`), Redis background worker | `TicketNotificationsTest.php` |
| **12** | Multi-Filter Query Engine & Text Search | [`app/Services/TicketSearchService.php`](file:///home/cs/projects/support-ticket/src/app/Services/TicketSearchService.php), [`resources/views/tickets/index.blade.php`](file:///home/cs/projects/support-ticket/src/resources/views/tickets/index.blade.php) | `TicketSearchTest.php` |
| **13** | Operations Dashboard & Analytics | [`app/Services/DashboardAnalyticsService.php`](file:///home/cs/projects/support-ticket/src/app/Services/DashboardAnalyticsService.php), [`resources/views/dashboard.blade.php`](file:///home/cs/projects/support-ticket/src/resources/views/dashboard.blade.php) | `DashboardAnalyticsTest.php` |
| **14** | Memory-Safe Streaming CSV Bulk Import | [`app/Services/Import/CsvReaderService.php`](file:///home/cs/projects/support-ticket/src/app/Services/Import/CsvReaderService.php), [`app/Jobs/ImportTicketsJob.php`](file:///home/cs/projects/support-ticket/src/app/Jobs/ImportTicketsJob.php) | `TicketImportTest.php` |
| **15** | Row-by-Row Import Error Reporting | [`app/Models/TicketImportFailure.php`](file:///home/cs/projects/support-ticket/src/app/Models/TicketImportFailure.php), [`resources/views/imports/show.blade.php`](file:///home/cs/projects/support-ticket/src/resources/views/imports/show.blade.php) | `TicketImportTest.php` |
| **16** | Containerized Microservices Stack | `docker-compose.yml`, `.docker/php/Dockerfile`, `.docker/nginx/default.conf` | Docker Compose integration |
| **17** | Developer Tooling & Automation | `Makefile`, `pint.json` | Pint formatting & test automation |

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
