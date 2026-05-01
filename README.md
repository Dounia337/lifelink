# LifeLink — Blood Donation Matching Platform

**CS415 Software Engineering | Ashesi University | Group 3 | 2026**

---

## Table of Contents

1. [Project Overview](#1-project-overview)
2. [Tech Stack](#2-tech-stack)
3. [Project Structure](#3-project-structure)
4. [Architecture](#4-architecture)
5. [Software Engineering Principles](#5-software-engineering-principles)
6. [Design Patterns](#6-design-patterns)
7. [Algorithms](#7-algorithms)
8. [Role-Based Access Control](#8-role-based-access-control)
9. [Database Schema](#9-database-schema)
10. [API Reference](#10-api-reference)
11. [Setup & Deployment](#11-setup--deployment)
12. [Demo Accounts](#12-demo-accounts)
13. [User Flows](#13-user-flows)
14. [Production Checklist](#14-production-checklist)
15. [Team](#15-team)

---

## 1. Project Overview

LifeLink is a full-stack web application that digitises Ghana's blood donation ecosystem. It addresses the country's 40% blood shortage gap by connecting hospitals that need blood with verified donors in real time.

The system performs automatic donor matching the moment a blood request is submitted — finding compatible donors by blood type, ranking them by proximity and verification status, and dispatching notifications — all without any manual intervention.

**Core capabilities:**

| Capability | Description |
|---|---|
| Donor registration | Register with blood type, location, and availability |
| Hospital blood requests | 3-step form → instant automatic matching |
| Matching algorithm | Blood type compatibility + Haversine distance scoring |
| Blood type verification | Health workers confirm donor blood types in the system |
| Real-time notifications | Alerts for emergency requests, matches, verifications |
| Admin control panel | System-wide analytics, user and hospital management |
| Education hub | Blood type facts and a knowledge quiz |
| Role-based dashboards | Separate UI for donors, hospitals, health workers, admins |

---

## 2. Tech Stack

| Layer | Technology | Purpose |
|---|---|---|
| Frontend | HTML5 + Tailwind CSS (CDN) | Page structure and styling |
| Frontend | Vanilla JavaScript (ES2022) | Dynamic rendering, API calls |
| Backend | PHP 8.0+ | REST API endpoints |
| Database | MySQL 8.0 | Relational data store |
| Authentication | PHP Sessions | Stateful login / role enforcement |
| Icons | Google Material Symbols | UI iconography |
| Fonts | Inter (Google Fonts) | Typography |
| Local Server | XAMPP / WAMP / MAMP | Development environment |

No build tools, no frameworks, no package manager — the stack is deliberately minimal so the focus stays on software engineering principles rather than tooling.

---

## 3. Project Structure

```
lifelink/
│
├── index.html                  Public landing page
├── login.html                  Login (also serves as register entry)
├── register.html               Registration (same content as login.html)
├── donor-dashboard.html        Donor portal — stats, requests, availability
├── hospital-dashboard.html     Hospital portal — requests, donors, fulfillment
├── admin-panel.html            Admin — users, hospitals, full system stats
├── health-worker.html          Verification dashboard
├── request-blood.html          3-step blood request form (hospital/public)
├── requests.html               Browse all open requests (all roles)
├── matching.html               View matched donors for a specific request
├── notifications.html          Notification centre (all roles)
├── profile.html                Profile editor (all roles)
├── education.html              Blood education + quiz
│
├── assets/
│   ├── css/
│   │   └── styles.css          Global stylesheet (cards, buttons, tables, badges)
│   └── js/
│       └── app.js              Shared JS — API client, auth, navbar, toast, formatters
│
├── api/
│   ├── config.php              DB config, session helpers, shared PHP utilities
│   ├── Database.php            *** PATTERN: Singleton — single PDO connection
│   ├── UserFactory.php         *** PATTERN: Factory — role-based user creation
│   ├── EventSystem.php         *** PATTERN: Observer — event-driven notifications
│   ├── auth.php                Login, register, logout, session check
│   ├── requests.php            Blood requests CRUD + matching engine
│   ├── donors.php              Donor profiles, verification, availability, history
│   └── admin.php               Stats, user management, hospital approval
│
└── database/
    └── schema.sql              Full MySQL schema + seed data (8 sample accounts)
```

---

## 4. Architecture

LifeLink uses a **3-Tier Architecture** separating presentation, business logic, and data storage.

```
┌─────────────────────────────────────────────────────────────┐
│  PRESENTATION TIER (Browser)                                │
│  HTML + Tailwind CSS + Vanilla JS                           │
│  app.js: shared API client, auth, navbar, toast, formatters │
└────────────────────────┬────────────────────────────────────┘
                         │  HTTP (JSON)
                         ▼
┌─────────────────────────────────────────────────────────────┐
│  BUSINESS LOGIC TIER (PHP REST API)                         │
│                                                             │
│  auth.php ──────────── login, register, session             │
│  requests.php ─────── blood requests + matching engine      │
│  donors.php ────────── profiles, verification, availability │
│  admin.php ─────────── stats, user/hospital management      │
│                                                             │
│  Design Pattern Layer:                                      │
│  Database.php    [Singleton]  — one DB connection           │
│  UserFactory.php [Factory]    — role-based object creation  │
│  EventSystem.php [Observer]   — decoupled notifications     │
└────────────────────────┬────────────────────────────────────┘
                         │  PDO (Prepared Statements)
                         ▼
┌─────────────────────────────────────────────────────────────┐
│  DATA TIER (MySQL 8.0)                                      │
│                                                             │
│  users · donor_profiles · hospitals · blood_requests        │
│  donor_matches · notifications · donation_records           │
│  blood_compatibility                                        │
└─────────────────────────────────────────────────────────────┘
```

### Frontend Communication Pattern

Every page loads `assets/js/app.js` which provides a centralised API client:

```javascript
api.get('requests.php?status=open')        // GET
api.post('requests.php', payload)          // POST
api.put('donors.php?id=2', { status })     // PUT
```

All API calls include session cookies (`credentials: 'include'`). The server validates the session on every request before executing any logic.

---

## 5. Software Engineering Principles

Four key software engineering principles were applied throughout the project. Separation of Concerns was enforced by dividing the system into distinct presentation, logic, and data layers. Modularity was applied by centralizing shared behavior in app.js on the frontend and config.php on the backend, ensuring that each component was cohesive and focused on a single, well-constrained function. Functional Independence was upheld by ensuring each PHP file owned exactly one domain of logic, with high cohesion and low coupling to other components. Information Hiding was implemented on the server at every endpoint, with frontend mirroring to redirect users who access pages outside their role, ensuring that internal data structures and processing details remained inaccessible to unauthorized modules.

---

## 6. Design Patterns

Three design patterns from the Gang of Four are implemented in the backend. Each pattern file has a header comment explaining which cheat-sheet elements it fulfils.

---

### 6.1 Singleton Pattern — `api/Database.php`

**Category:** Creational

**Problem it solves:** The database connection is an expensive resource that should exist exactly once. Before this pattern, `getDB()` used a local `static $pdo` variable — an informal singleton with no class structure, no clone protection, and no clear ownership.

**How it works:**

```php
class Database {
    private static ?Database $instance = null;  // the one instance
    private PDO $connection;

    private function __construct() { /* create PDO */ }  // blocks "new Database()"

    public static function getInstance(): Database {
        if (self::$instance === null) {
            self::$instance = new Database();   // created only once
        }
        return self::$instance;                 // always the same object
    }

    public function getConnection(): PDO { return $this->connection; }
    private function __clone() {}               // prevents clone()
}
```

`getDB()` in `config.php` now delegates to it:

```php
function getDB(): PDO {
    return Database::getInstance()->getConnection();
}
```

**Pattern elements present:**

| Element | Where |
|---|---|
| `private static $instance` | `private static ?Database $instance = null` |
| `private constructor` | `private function __construct()` |
| `public static getInstance()` | Returns the single instance, lazy-initialised |
| Clone prevention | `private function __clone()` |

---

### 6.2 Factory Pattern — `api/UserFactory.php`

**Category:** Creational

**Problem it solves:** `handleRegister()` in `auth.php` had a 40-line if/elseif block that directly inserted rows into `donor_profiles`, `hospitals`, or nothing depending on role. Adding a new role (e.g., `lab_technician`) meant editing the registration handler. The Factory centralises this decision — the caller never knows which concrete class it gets.

**How it works:**

```
UserFactory::getCreator($role)
  ├── 'donor'        → returns new DonorCreator($db)
  ├── 'hospital'     → returns new HospitalCreator($db)
  └── 'health_worker'→ returns new HealthWorkerCreator($db)
        all extend UserCreator (base class)
```

Each subclass implements `createProfile(int $userId, array $data)`:

- **`DonorCreator`** — inserts into `donor_profiles` (blood type, DOB, gender, weight)
- **`HospitalCreator`** — inserts into `hospitals` (hospital name, registration number, type)
- **`HealthWorkerCreator`** — no extra table; method body is empty

`auth.php::handleRegister()` is now three lines:

```php
$creator = UserFactory::getCreator($role, $db); // returns UserCreator (base type)
$userId  = $creator->createBaseUser($data);      // shared: INSERT INTO users
$creator->createProfile($userId, $data);          // role-specific INSERT
```

**Pattern elements present:**

| Element | Where |
|---|---|
| Base class | `abstract class UserCreator` |
| Subclasses | `DonorCreator`, `HospitalCreator`, `HealthWorkerCreator` |
| if/switch inside factory | `switch ($role)` inside `UserFactory::getCreator()` |
| Return type is base class | `getCreator()` returns `UserCreator`, not a specific subclass |
| Client unaware of subclass | `auth.php` only calls `createBaseUser()` and `createProfile()` |

---

### 6.3 Observer Pattern — `api/EventSystem.php`

**Category:** Behavioral

**Problem it solves:** Notification SQL was hardcoded inside the functions that triggered the events. `verifyBloodType()` contained notification INSERT statements. `runMatching()` contained both donor_matches INSERTs and notification INSERTs in the same loop. These functions "knew" about notifications — tight coupling that violates SRP.

**How it works:**

```
EventSubject (Observable)
  addObserver(event, observer)
  notifyObservers(event, data) ──► observer.update(event, data)
                                       │
              ┌────────────────────────┴─────────────────────────┐
              │                                                   │
  BloodTypeVerifiedObserver                  DonorMatchNotificationObserver
  update('blood_type_verified', data)        update('blood_request_created', data)
    → INSERT notification                      → INSERT donor_matches rows
    → UPDATE users SET is_verified=1           → INSERT notifications for each donor
```

**Usage in `donors.php`** after updating blood type:

```php
$subject = new EventSubject();
$subject->addObserver('blood_type_verified', new BloodTypeVerifiedObserver($db));
$subject->notifyObservers('blood_type_verified', [
    'donor_id'   => $donorId,
    'blood_type' => $data['blood_type'],
]);
// verifyBloodType() no longer contains any notification SQL
```

**Usage in `requests.php`** after computing matches:

```php
$subject = new EventSubject();
$subject->addObserver('blood_request_created', new DonorMatchNotificationObserver($db));
$subject->notifyObservers('blood_request_created', [
    'request' => $request,
    'donors'  => $scoredDonors,
]);
// runMatching() no longer contains notification or donor_matches SQL
```

**Pattern elements present:**

| Element | Where |
|---|---|
| Observer interface | `interface LifeLinkObserver { update(string $event, array $data) }` |
| Subject (Observable) | `class EventSubject` with `addObserver()` and `notifyObservers()` |
| Concrete Observer 1 | `BloodTypeVerifiedObserver` — verification notifications |
| Concrete Observer 2 | `DonorMatchNotificationObserver` — match + emergency notifications |
| Subject unaware of observers | `EventSubject` holds a typed array; knows nothing about what observers do |

---

## 7. Algorithms

### 7.1 Donor Matching Algorithm

**Location:** `api/requests.php::runMatching()`

**Triggered by:** Every time a hospital submits a blood request (`POST /api/requests.php`)

**Purpose:** Automatically identify the best available donors for a blood request based on blood type compatibility and geographic proximity, then notify them.

#### Step-by-step flow

```
1. Fetch the blood request record from blood_requests table

2. Query blood_compatibility table to find all donor blood types
   that are compatible with the requested recipient type
   Example: recipient = 'AB+' → compatible donors: O-, O+, A-, A+, B-, B+, AB-, AB+

3. Query users + donor_profiles for donors who:
   - Have a blood type in the compatible types list
   - Are marked is_eligible = 1
   - Have availability_status = 'available' (not 'unavailable' or 'cooldown')
   - Have is_active = 1 on their account
   - Are NOT already matched to this request (avoids duplicate notifications)
   - Ordered: verified donors first (blood_type_verified DESC)
   - Capped at 20 candidates

4. For each candidate donor, calculate:
   (a) Distance in km using the Haversine formula (see §7.2)
   (b) Match score = (blood_type_verified ? 20 : 0) + max(0, 100 - distance_km)
       - Verification bonus: +20 points for a confirmed blood type
       - Distance score: starts at 100 and decreases by 1 per km (floor 0)
       - A verified donor 5 km away scores 115; an unverified donor 80 km away scores 20

5. Fire 'blood_request_created' event (Observer Pattern)
   → DonorMatchNotificationObserver:
       - INSERT into donor_matches (request_id, donor_id, distance_km, match_score, 'notified')
       - INSERT into notifications for each donor with urgency label and distance

6. UPDATE blood_requests SET status = 'matched' WHERE status = 'open'
```

#### Blood type compatibility table

The `blood_compatibility` table stores all 29 valid donor→recipient pairs as data, not code. This means compatibility rules can be updated in the database without changing any PHP.

```
Donor   Can give to
------  ----------------------------------------
O-      O-, O+, A-, A+, B-, B+, AB-, AB+  (universal donor)
O+      O+, A+, B+, AB+
A-      A-, A+, AB-, AB+
A+      A+, AB+
B-      B-, B+, AB-, AB+
B+      B+, AB+
AB-     AB-, AB+
AB+     AB+                                (universal recipient)
```

---

### 7.3 Fulfillment Rate Calculation

**Location:** `api/admin.php::getStats()`

**Formula:**

```
fulfillment_rate = (fulfilled_requests / non_cancelled_requests) × 100
```

Cancelled requests are excluded from the denominator because they represent requests that were withdrawn — including them would unfairly suppress the rate. A request that was cancelled before it could be matched is not a failure to fulfil.

---

## 8. Role-Based Access Control

### Roles

| Role | Registered via | Dashboard | Key permissions |
|---|---|---|---|
| `donor` | Public registration | `donor-dashboard.html` | View requests, toggle availability, respond to matches, view own history |
| `hospital` | Public registration (admin approval required) | `hospital-dashboard.html` | Create blood requests, view matches, mark requests fulfilled |
| `health_worker` | Public registration | `health-worker.html` | Verify donor blood types, view all donors |
| `admin` | Seeded in DB only (cannot self-register) | `admin-panel.html` | Full system access, approve hospitals, toggle users, all stats |

### Enforcement — two layers

**Layer 1 — Server (PHP):** Every API endpoint calls `requireAuth()` or `requireRole(...)` before executing. These read from `$_SESSION` which is set on login and cleared on logout.

```php
// In donors.php — only admin and health_worker can list all donors
function listDonors(): void {
    requireRole('admin', 'health_worker', 'hospital');
    ...
}

// In admin.php — only admin can approve hospitals
function approveHospital(): void {
    requireRole('admin');
    ...
}
```

**Layer 2 — Frontend (JS):** `initPage(['donor'])` in each page's script calls `auth.me()`, checks the role, and redirects to the correct dashboard if the role doesn't match. This is a UX guard, not a security boundary — the server is always the authoritative check.

### Hospital login gate

When a hospital logs in, `auth.php` additionally checks `hospitals.is_approved`:

```php
if ($user['role'] === 'hospital') {
    $hospital = fetchHospitalRow($user['id']);
    if (!$hospital['is_approved']) {
        jsonResponse(false, 'Hospital account pending approval by admin.', [], 403);
    }
}
```

An unapproved hospital gets a 403 even with correct credentials.

---

## 9. Database Schema

### Tables

| Table | Purpose | Key columns |
|---|---|---|
| `users` | All accounts regardless of role | `id`, `role`, `email`, `password_hash`, `is_active`, `is_verified` |
| `donor_profiles` | Donor-specific data | `user_id (FK)`, `blood_type`, `blood_type_verified`, `availability_status`, `total_donations` |
| `hospitals` | Hospital-specific data | `user_id (FK)`, `hospital_name`, `is_approved`, `latitude`, `longitude` |
| `blood_requests` | Every blood request ever submitted | `hospital_id (FK)`, `blood_type`, `urgency`, `status`, `units_needed`, `fulfilled_at` |
| `donor_matches` | Links a request to a notified donor | `request_id (FK)`, `donor_id (FK)`, `distance_km`, `match_score`, `status` |
| `notifications` | In-app alerts per user | `user_id (FK)`, `type`, `title`, `message`, `is_read`, `related_request_id (FK)` |
| `donation_records` | Log of actual donations | `donor_id (FK)`, `hospital_id (FK)`, `blood_type`, `donation_date` |
| `blood_compatibility` | Donor→recipient compatibility pairs | `donor_type`, `recipient_type` (29 rows, static reference data) |

### Status enumerations

```
blood_requests.status:   open → matched → in_progress → fulfilled | cancelled
donor_matches.status:    notified → accepted | declined → completed | cancelled
donor_profiles.availability_status:  available | unavailable | cooldown
```

### Key relationships

```
users ──1:1──► donor_profiles
users ──1:1──► hospitals
hospitals ──1:N──► blood_requests
blood_requests ──1:N──► donor_matches ──N:1──► users (donors)
users ──1:N──► notifications
users ──1:N──► donation_records
blood_compatibility (standalone reference table, no FK)
```

---

## 10. API Reference

All endpoints return JSON with the shape `{ "success": bool, "message": string, ...data }`.
All endpoints except `GET /api/requests.php` and `GET /api/requests.php?id=X` require an active session.

### Authentication — `api/auth.php`

| Method | Endpoint | Auth required | Body / Params | Response |
|---|---|---|---|---|
| POST | `?action=login` | No | `{ email, password }` | `{ user: { id, role, full_name, email, is_verified } }` |
| POST | `?action=register` | No | `{ full_name, email, password, role, [blood_type], [hospital_name], ... }` | `{ user: { id, role, full_name } }` |
| POST | `?action=logout` | Yes | — | `{}` |
| GET | `?action=me` | Yes | — | `{ user: { ...profile + role-specific data } }` |

### Blood Requests — `api/requests.php`

| Method | Endpoint | Role | Description |
|---|---|---|---|
| GET | `/` | Any | List requests. Filters: `?status=open&blood_type=O+&urgency=critical&limit=20&offset=0` |
| GET | `?id=X` | Any | Single request with all matched donors |
| POST | `/` | hospital, admin | Create request → triggers matching algorithm automatically |
| PUT | `?id=X` | Any (authenticated) | Update `status`, `notes`, `fulfilled_at` |
| PUT | `?id=X&match_id=Y` | hospital, admin | Update a specific match status (accepted / declined) |

### Donors — `api/donors.php`

| Method | Endpoint | Role | Description |
|---|---|---|---|
| GET | `/` | admin, health_worker, hospital | List donors. Filters: `?search=&blood_type=&verified=1&availability=available` |
| GET | `?id=X` | donor (own), admin, health_worker | Donor profile |
| GET | `?action=stats` | donor | Own stats (donations, lives impacted, pending matches, blood type) |
| GET | `?action=history` | any (own), admin | Donation history records |
| PUT | `?id=X` | donor (own), admin | Update profile fields |
| PUT | `?id=X&action=verify` | admin, health_worker | Verify blood type → fires Observer event |
| PUT | `?id=X&action=availability` | donor (own), admin | Toggle available / unavailable |
| PUT | `?id=X&action=respond` | donor | Accept or decline a match |

### Admin — `api/admin.php`

| Method | Endpoint | Role | Description |
|---|---|---|---|
| GET | `?action=stats` | admin | System-wide statistics (8 metrics + recent requests) |
| GET | `?action=users` | admin | All users. Filters: `?role=donor&search=kwame` |
| GET | `?action=hospitals` | admin | All hospitals. Filter: `?approved=0` for pending |
| PUT | `?action=approve` | admin | Approve a hospital `{ hospital_id }` |
| PUT | `?action=toggle_user` | admin | Activate / deactivate a user `{ user_id }` |
| GET | `?action=notifications` | any | Own notifications. Filters: `?unread=1&limit=20&offset=0` |
| PUT | `?action=mark_read` | any | Mark notifications read `{ id }` or `{ all: true }` |

---

## 11. Setup & Deployment

### Prerequisites

- PHP 8.0 or higher
- MySQL 8.0 or higher
- XAMPP, WAMP, or MAMP installed

### Local setup

**Step 1 — Place the project**
```
Copy the lifelink/ folder into:
  XAMPP:  C:\xampp\htdocs\lifelink\
  MAMP:   /Applications/MAMP/htdocs/lifelink/
  WAMP:   C:\wamp64\www\lifelink\
```

**Step 2 — Start services**

Open XAMPP (or WAMP/MAMP) Control Panel and start **Apache** and **MySQL**.

**Step 3 — Create the database**

1. Open `http://localhost/phpmyadmin`
2. Click **New** in the left sidebar
3. Name it `lifelink` → click **Create**
4. Select the `lifelink` database → click **Import**
5. Choose `database/schema.sql` → click **Go**

This creates all tables and loads 8 seed accounts with sample data.

**Step 4 — Verify config**

Open `api/config.php` and confirm:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');        // change if your MySQL has a password
define('DB_NAME', 'lifelink');
```

**Step 5 — Open the app**
```
http://localhost/lifelink/
```

### Cloud deployment (Railway — recommended)

1. Push the project to a GitHub repository
2. Create an account at `https://railway.app`
3. **New Project** → **Deploy from GitHub** → select your repo
4. Add a **MySQL** plugin to the project
5. Set these environment variables in Railway's dashboard:
   ```
   DB_HOST=your-railway-host
   DB_USER=root
   DB_PASS=your-password
   DB_NAME=railway
   ```
6. Update `api/config.php` to read from environment:
   ```php
   define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
   define('DB_USER', getenv('DB_USER') ?: 'root');
   define('DB_PASS', getenv('DB_PASS') ?: '');
   define('DB_NAME', getenv('DB_NAME') ?: 'lifelink');
   ```
7. Import `database/schema.sql` via Railway's MySQL console

---

## 12. Demo Accounts

All seed accounts use the password: **`password`**

| Role | Name | Email | Notes |
|---|---|---|---|
| Admin | Admin User | `admin@lifelink.gh` | Full access to all sections |
| Donor | Kwame Asante | `kwame@lifelink.gh` | O+, verified, 5 donations on record |
| Donor | Ama Serwaa | `ama@lifelink.gh` | A-, verified, 2 donations |
| Donor | Kofi Boateng | `kofi@lifelink.gh` | B+, **unverified** — use to test health worker verification flow |
| Donor | Efua Darko | `efua@lifelink.gh` | AB+, verified, status: cooldown |
| Hospital | KATH Hospital | `kath@lifelink.gh` | Komfo Anokye Teaching Hospital, Kumasi |
| Hospital | Korle Bu Hospital | `korlebu@lifelink.gh` | Korle Bu Teaching Hospital, Accra |
| Health Worker | Dr. Abena Mensah | `abena@lifelink.gh` | Can verify donor blood types |

### Recommended test sequence

1. Log in as **hospital** (`kath@lifelink.gh`) → create a blood request → observe automatic matching
2. Log in as **donor** (`kwame@lifelink.gh`) → check notifications → accept the match
3. Log in as **health worker** (`abena@lifelink.gh`) → find Kofi → verify his blood type as B+
4. Log in as **admin** (`admin@lifelink.gh`) → check system stats → approve a new hospital

---

## 13. User Flows

### Donor

```
Register (blood type optional at signup)
    │
    ▼
Get blood type verified at a clinic
    │  Health worker updates system → Observer fires → notification sent
    ▼
Receive emergency notification when compatible blood is needed nearby
    │  DonorMatchNotificationObserver fires when hospital submits request
    ▼
Accept or decline the match
    │  accepted → request status → in_progress
    ▼
Donate at the hospital
    │  Health worker records donation → donation_records INSERT
    ▼
90-day cooldown (availability_status = 'cooldown')
    │
    ▼
Eligibility restored → available again
```

### Hospital

```
Register hospital account
    │
    ▼
Wait for admin approval (is_approved = 0 until then — login blocked)
    │
    ▼
Log in → Hospital dashboard
    │
    ▼
Submit blood request (3-step form: patient info → blood details → confirm)
    │  POST /api/requests.php → runMatching() fires immediately
    │  → compatible donors found → scored → matched → notified
    ▼
Monitor matched donors on matching.html
    │
    ▼
Donor accepts → status → in_progress
    │
    ▼
Blood received → Mark request fulfilled (sets fulfilled_at timestamp)
```

### Admin

```
Log in → Admin panel
    │
    ├── Overview: live system stats (donors, hospitals, open requests, rate)
    ├── Users: search, filter by role, activate/deactivate
    ├── Hospitals: see pending approvals, approve with one click
    ├── Requests: full request history with status filters
    └── Donors: filter by blood type / verification, verify blood types
```

---

## 14. Production Checklist

Before going live, complete every item below:

- [ ] Change `DB_PASS` in `api/config.php` to a strong password
- [ ] Rotate `JWT_SECRET` to a randomly generated 64-character string
- [ ] Restrict `Access-Control-Allow-Origin` in `config.php` to your actual domain
- [ ] Enable HTTPS (sessions must be transmitted over TLS only)
- [ ] Set `session.cookie_secure = 1` and `session.cookie_httponly = 1` in `php.ini`
- [ ] Set `session.cookie_samesite = Strict` to prevent CSRF
- [ ] Remove or disable the seed demo accounts from `schema.sql`
- [ ] Add rate limiting to `auth.php` login endpoint (prevent brute force)
- [ ] Replace Tailwind CDN with a local build (faster, no external dependency)
- [ ] Replace Google Fonts CDN with locally hosted fonts
- [ ] Set up email/SMS notifications (currently notification-only via DB)
- [ ] Enable MySQL slow query log and add indexes on frequently filtered columns
- [ ] Add a `fulfilled_at` default and trigger if hospitals forget to set it

---

## 15. Team

**Group 3 — CS415 Software Engineering, Ashesi University, 2026**

| Name | Role |
|---|---|
| Deubaybe Dounia | Project Manager |
| Inares Kenn Tsangue | Developer |
| Ibrahim Mahamadou Abdou | Developer |
| Abdoul Akim N'goila Karimou | Developer |

---

*Built to close Ghana's 40% blood shortage gap.*
