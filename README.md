# LifeLink — Blood Donation Matching Platform for Ghana
**CS415 Software Engineering | Ashesi University | Group 3**

---

## 📋 Project Overview

LifeLink is a full-stack web application that connects blood donors with hospitals in Ghana, addressing the country's 40% blood shortage gap.

**Features:**
- 🩸 Donor registration with blood type (including "Unknown" option)
- 🏥 Hospital blood request creation with urgency levels
- 🤖 Automated donor-matching algorithm (blood type compatibility + location)
- 📊 Admin dashboard with analytics
- 🔔 Real-time notifications
- 🔬 Health worker blood type verification
- 📚 Blood education hub with quiz

---

## 🗂️ Project Structure

```
lifelink/
├── index.html              Landing page
├── login.html              Login / Register (combined)
├── register.html           Register page
├── donor-dashboard.html    Donor portal
├── hospital-dashboard.html Hospital management
├── admin-panel.html        Admin analytics & management
├── health-worker.html      Health worker verification
├── request-blood.html      Multi-step blood request form
├── requests.html           Browse all requests
├── matching.html           Donor matching results
├── notifications.html      Notifications center
├── profile.html            Profile & settings
├── education.html          Blood education + quiz
│
├── assets/
│   ├── css/styles.css      Global styles
│   └── js/app.js           Shared JS (API client, auth, utils)
│
├── api/
│   ├── config.php          DB connection & utilities
│   ├── auth.php            Login, register, logout, session
│   ├── requests.php        Blood requests CRUD + matching
│   ├── donors.php          Donor profiles, verification
│   └── admin.php           Admin stats, users, hospitals, notifications
│
└── database/
    └── schema.sql          Full MySQL schema + sample data
```

---

## 🚀 Local Deployment (XAMPP / WAMP / MAMP)

### Prerequisites
- PHP 8.0+
- MySQL 8.0+
- XAMPP, WAMP, or MAMP

### Steps

**1. Clone / Copy the project**
```bash
cp -r lifelink/ /xampp/htdocs/lifelink
# or for MAMP: /Applications/MAMP/htdocs/lifelink
```

**2. Start your local server**
- Open XAMPP Control Panel
- Start **Apache** and **MySQL**

**3. Set up the database**
- Go to http://localhost/phpmyadmin
- Click **New** → Name it `lifelink` → Click **Create**
- Click the `lifelink` database → **Import** tab
- Upload `database/schema.sql` → Click **Go**

**4. Configure database connection**
Edit `api/config.php` if needed:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');  // your MySQL password
define('DB_NAME', 'lifelink');
```

**5. Open the app**
```
http://localhost/lifelink/
```

---

## 🔑 Demo Accounts

All accounts use password: **`password`**

| Role | Email | Notes |
|------|-------|-------|
| Admin | admin@lifelink.gh | Full access |
| Donor (Verified) | kwame@lifelink.gh | O+ blood type, verified |
| Donor (Unverified) | kofi@lifelink.gh | B+ blood type |
| Hospital | korlebu@lifelink.gh | Korle Bu Teaching Hospital |
| Health Worker | abena@lifelink.gh | Can verify donors |

---

## ☁️ Free Cloud Deployment

### Option A: Railway (Recommended — Full PHP + MySQL)

1. Create account at https://railway.app
2. **New Project** → **Deploy from GitHub** (push your code to GitHub first)
3. Add **MySQL** plugin → Copy `DATABASE_URL`
4. Set environment variables in Railway:
   ```
   DB_HOST=your-railway-mysql-host
   DB_USER=root
   DB_PASS=your-password
   DB_NAME=railway
   ```
5. Update `api/config.php` to use `getenv()`:
   ```php
   define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
   define('DB_USER', getenv('DB_USER') ?: 'root');
   define('DB_PASS', getenv('DB_PASS') ?: '');
   define('DB_NAME', getenv('DB_NAME') ?: 'lifelink');
   ```
6. Import `database/schema.sql` via Railway's MySQL console

### Option B: InfinityFree (Free PHP Hosting)

1. Sign up at https://infinityfree.net
2. Create new hosting account
3. Upload all files via **File Manager** or FTP (FileZilla)
4. Go to **MySQL Databases** → Create database
5. Import `schema.sql` via phpMyAdmin
6. Update `api/config.php` with your credentials

### Option C: AwardSpace / 000webhost

Similar to InfinityFree — both support PHP + MySQL for free.

---

## 🔧 API Reference

### Authentication
```
POST /api/auth.php?action=login      { email, password }
POST /api/auth.php?action=register   { full_name, email, password, role, ... }
POST /api/auth.php?action=logout
GET  /api/auth.php?action=me
```

### Blood Requests
```
GET  /api/requests.php                         All requests (with filters)
POST /api/requests.php                         Create request (triggers matching)
GET  /api/requests.php?id=X                   Single request with matches
PUT  /api/requests.php?id=X                   Update status
```

### Donors
```
GET  /api/donors.php                           List donors (admin)
GET  /api/donors.php?id=X                     Donor profile
PUT  /api/donors.php?id=X                     Update profile
PUT  /api/donors.php?id=X&action=verify       Verify blood type
PUT  /api/donors.php?id=X&action=availability Toggle availability
GET  /api/donors.php?action=history           Donation history
GET  /api/donors.php?action=stats             Donor stats
```

### Admin
```
GET  /api/admin.php?action=stats       System statistics
GET  /api/admin.php?action=users       User management
GET  /api/admin.php?action=hospitals   Hospital management
PUT  /api/admin.php?action=approve     Approve hospital
PUT  /api/admin.php?action=toggle_user Toggle user active
GET  /api/admin.php?action=notifications  User notifications
PUT  /api/admin.php?action=mark_read   Mark notifications read
```

---

## 🩸 Blood Matching Algorithm

When a hospital creates a blood request, the system automatically:

1. **Looks up compatible donor blood types** using the `blood_compatibility` table
2. **Queries available, eligible donors** with matching blood types
3. **Calculates distance** using the Haversine formula (GPS coordinates)
4. **Scores each match** = (20 if verified) + (100 - distance_km)
5. **Inserts matches** into `donor_matches` table
6. **Sends notifications** to all matched donors
7. **Updates request status** to `matched`

---

## 📱 User Flows

### Donor Flow
1. Register → Select blood type (or "Unknown")
2. Get blood type verified at a clinic (health worker updates in system)
3. Receive emergency notifications when compatible blood needed
4. Accept/decline match requests
5. Donate → Record logged → Eligibility timer starts (90 days)

### Hospital Flow
1. Register hospital → Admin approval required
2. Create blood request (3-step form) → Automatic matching
3. View matched donors → Contact and confirm
4. Mark request as fulfilled

### Admin Flow
1. Monitor system stats on dashboard
2. Approve new hospital accounts
3. Manage users (activate/deactivate)
4. View all requests and matches

---

## 🎨 Design System

| Property | Value |
|----------|-------|
| Primary Red | `#B71C1C` (landing, urgent) |
| Primary Blue | `#1975d2` (dashboards) |
| Background | `#F6F7F8` |
| Font | Inter (400–900) |
| Icons | Material Symbols Outlined |
| CSS Framework | Tailwind CSS (CDN) |

---

## 🛠️ Tech Stack

| Layer | Technology |
|-------|-----------|
| Frontend | HTML5, Tailwind CSS, Vanilla JS |
| Backend | PHP 8.0+ (REST API) |
| Database | MySQL 8.0 |
| Auth | PHP Sessions |
| Icons | Google Material Symbols |

---

## ⚠️ Production Checklist

- [ ] Change `DB_PASS` in `config.php`
- [ ] Change `JWT_SECRET` in `config.php`
- [ ] Enable HTTPS (required for sessions)
- [ ] Set `session.cookie_secure = 1` in php.ini
- [ ] Remove demo accounts from `schema.sql`
- [ ] Set up email notifications (SMTP)
- [ ] Add rate limiting to API endpoints

---

## 👥 Team

**Group 3 — CS415 Software Engineering, Ashesi University, 2026**
- Deubaybe Dounia (Project Manager)
- Inares Kenn Tsangue
- Ibrahim Mahamadou Abdou
- Abdoul Akim N'goila Karimou

---

*Built with ❤️ to close Ghana's blood shortage gap.*
