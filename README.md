# Library Management System

Admin-focused library management system built with **core PHP**, **MySQL**, **HTML/CSS** and **Bootstrap 5**.
No frameworks, no Composer dependencies — just plain PHP files you can drop into XAMPP and run.

---

## 1. Folder structure

```
library-ai/
├── index.php                  Entry point -> redirects to admin login/dashboard
├── admin/                     All admin pages
│   ├── login.php              Admin login
│   ├── logout.php             Ends the session
│   ├── index.php              Dashboard (statistics cards)
│   ├── add_book.php           Add a book
│   ├── manage_books.php       List / search / update qty / delete
│   ├── edit_book.php          Edit a book
│   ├── add_student.php        Register a student
│   ├── manage_students.php    List / search / edit / delete students
│   ├── issue_book.php         Issue a book to a student
│   ├── return_book.php        Mark books as returned (single or bulk)
│   ├── fines.php              Fine report, filters and payment collection
│   ├── fine_settings.php      Configure the fine rate, grace period and cap
│   ├── student_fines.php      Per-student loans, fines and payment history
│   └── reports.php            Issued / Returned / Overdue / Fines reports
├── assets/
│   ├── css/style.css          Custom styles on top of Bootstrap 5
│   └── vendor/                Third-party files served from your own server
│       ├── bootstrap/css/bootstrap.min.css
│       ├── bootstrap/js/bootstrap.bundle.min.js
│       └── bootstrap-icons/css/bootstrap-icons.min.css
│           └── fonts/bootstrap-icons.woff2 + .woff
├── database/
│   ├── library.sql            Database schema + demo data (fresh install)
│   ├── migration_fines.sql    Upgrade an EXISTING install to the fine system
│   ├── test_fines.php         Fine calculation tests (command line)
│   ├── test_fine_writes.php   Return assessment + payment tests
│   ├── test_fine_batch.php    Checks the batched student-fine totals match
│   │                          the per-student version, and compares timing
│   ├── test_input_hardening.php  Crafted array-shaped input against every page
│   ├── test_admin_forms.php   End-to-end happy path for every admin form
│   ├── test_xss.php           Stored-XSS payloads in every user-typed field
│   ├── test_money_precision.php  Float/rounding behaviour of the fine engine
│   └── migration_test_setup.sql   Fixture used to test the migration
└── includes/                  Reusable code
    ├── db.php                 PDO connection + query helpers
    ├── auth.php               Session authentication / login check
    ├── functions.php          Escaping, flash messages, CSRF, formatting
    ├── fines.php              The fine engine (all fine maths lives here)
    ├── sidebar.php            Sidebar navigation (shared by desktop + mobile)
    ├── header.php             <head>, navbar, sidebar, content open
    └── footer.php             Content close, footer, scripts
```

> Bootstrap 5.3.3 and Bootstrap Icons 1.11.3 are **vendored locally**, not loaded from a CDN,
> so the UI renders correctly with no internet connection.

---

## 2. Setup on XAMPP (step by step)

1. **Copy the project** into the XAMPP web root so it becomes `C:\xampp\htdocs\library-ai`
   (or `C:\xampp\htdocs` / `htdocs/library-ai` on macOS/Linux).

2. **Start Apache and MySQL** from the XAMPP Control Panel.

3. **Create the database**
   - Open <http://localhost/phpmyadmin>
   - Click the **Import** tab
   - Choose `database/library.sql` and press **Go**
   - This creates the `library_db` database, all 6 tables, one admin and demo data.

   > The SQL file also drops `library_db` first, so importing it again simply rebuilds the database.

   > **Already running an older copy with data you want to keep?** Do **not** re-import.
   > Import `database/migration_fines.sql` instead — see section 3a.

4. **Check the credentials** in `includes/db.php` (XAMPP defaults):

   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'library_db');
   define('DB_USER', 'root');
   define('DB_PASS', '');
   ```

5. **Open the application**: <http://localhost/library-ai/>
   You will be redirected to the login page.

6. **Log in** with the default admin account:

   | Email              | Password   |
   | ------------------ | ---------- |
   | `admin@library.com`| `admin123` |

---

## 3. Database tables

| Table          | Purpose                                        | Key columns |
| -------------- | ---------------------------------------------- | ----------- |
| `admins`       | Librarian accounts                             | `id`, `name`, `email` (unique), `password` (bcrypt) |
| `books`        | Catalogue + stock levels                       | `title`, `author`, `category`, `isbn`, `quantity`, `available_quantity`, `added_date` |
| `students`     | Registered members                             | `name`, `email` (unique), `phone`, `created_at` |
| `issued_books` | Every issue/return transaction                 | `book_id` → `books.id`, `student_id` → `students.id`, `issue_date`, `return_date` (due), `returned_at` (actual), `status`, `fine_days`, `fine_amount`, `fine_paid`, `fine_status` |
| `settings`     | Admin-configurable key/value pairs               | `setting_key` (primary key), `setting_value`, `updated_at` |
| `fine_payments`| Every fine payment received                      | `issued_book_id` → `issued_books.id`, `amount`, `payment_date`, `payment_method`, `note` |

Relationships and behaviour:

- `issued_books.book_id` → `books.id` and `issued_books.student_id` → `students.id`,
  both with `ON UPDATE CASCADE ON DELETE CASCADE`.
- `available_quantity` is kept in sync automatically: it goes **down 1** when a book is issued
  and **up 1** when it is returned.
- A book cannot be deleted while copies of it are still issued, and its total `quantity`
  cannot be set below the number of copies currently out.
- `return_date` stores the **due date**; the extra `returned_at` column records the
  **actual return date**, which is what the Overdue / Late columns are based on.
- Deleting a loan also deletes its fine payments (`ON DELETE CASCADE`), so the
  payment history can never disagree with the loan it belongs to.

---

## 3a. Upgrading an existing install (adding the fine system)

If you already ran the project before the fine system existed and want to keep your
data, import **one** file in phpMyAdmin:

```
database/migration_fines.sql
```

It is safe for your existing rows and does all of the following:

1. Adds `fine_days`, `fine_amount`, `fine_paid`, `fine_status` to `issued_books`.
2. Creates the `settings` and `fine_payments` tables with the defaults
   (₹5 per day, fines on, 0 grace days, no maximum).
3. Backfills a fine for loans that had already been returned, using ₹5/day with no
   grace period. Books that were returned on time or early get `0`, never a negative count.

Books that were still out keep `fine_amount = 0` on purpose — their fine is worked out
live while the book is away and written once, when it comes back.

> Run it **once**. It uses `ALTER TABLE ... ADD COLUMN`, so a second run fails with a
> duplicate-column error (that error is harmless: it means it already ran).
> The resulting schema is identical to a fresh `library.sql` import.

---

## 4. Features

**Authentication**
- Session based login with `password_verify()` (bcrypt) + `session_regenerate_id()`
- Every admin page is protected by `require_login()`
- Logout fully destroys the session cookie

**Dashboard**
- Statistic cards: total books, total students, issued count, returned count,
  available books, overdue count
- Table of currently issued books (soonest due first) and recently added books
- Notification strip: books that are overdue and accruing a fine right now, plus
  books due within the next 3 days
- Fine cards (assessed / collected / pending / current rate) and a fine detail table

**Late-return fines** (see section 4a for the rules)
- ₹5 per overdue day by default, with the rate, a grace period and an optional
  maximum all editable from **Fine Settings** (no code change needed)
- Fines on books that are still out are calculated live and **never written** to the
  database, so refreshing a page cannot change a number or double-charge anything
- The fine is assessed and **frozen** exactly once, inside the return transaction
- Partial payments and "Mark as Paid" settle the rest; every payment is logged with
  its method and an optional note
- Statuses: `No Fine` → `Unpaid` → `Partially Paid` → `Paid`
- Fine report with search and filters, plus a per-student fine and payment history page
- Returned books cannot be fined or paid twice; a payment is refused if it exceeds
  the outstanding balance

**Book management**
- Add, edit, delete, view all
- Search across title / author / ISBN / category, plus a category filter
- Update stock quantity inline from the list or on the edit page
- Stock badges (Out of stock / Low stock / n of m available)

**Student management**
- Add, edit (inline expandable row form), delete
- Search by name, email or phone
- Shows how many books each student holds, how many are overdue, and their
  outstanding fine (linking to their fine history)

**Issue system**
- Student dropdown + book dropdown (only books with available copies)
- Issue date and return (due) date, validated so the due date is not before the issue date
- The due date field reminds the librarian what the current fine rate is
- Runs in a transaction with a guarded `available_quantity - 1` update, so the last copy
  can never be over-issued

**Return system**
- Return a single book or select several and return them in bulk
- Search and "overdue only" filter
- Sets `status = 'returned'`, stamps `returned_at`, restores the available copy
- Assesses and stores the final fine in the same transaction, and reports it in the
  confirmation message ("Late fine: Rs 15.00 — record the payment under Fines")

**Reports**
- Tabs for Issued, Returned, Overdue and Fines lists with a shared search filter
- Extra insights: most issued titles, catalogue split by category
- One-click print button

---

## 4a. How the fine is worked out

All fine maths lives in `includes/fines.php` — the single source of truth.
Every page uses those functions, so a figure can never disagree between two screens.

```
overdue days     = calendar days between the due date and the return date
                   (still-out books are measured up to today)
chargeable days  = max(0, overdue days − grace days)
fine             = chargeable days × rate per day
fine             = min(fine, maximum)      ← only when a maximum is set
```

Details that matter:

- **Counting starts the day after the due date.** A book returned *on* its due date
  has 0 overdue days and no fine. A book returned one day late is charged one day.
- **Date-only maths.** Dates are read as calendar days at midnight and compared with
  `DateTime::diff()`, so the time of day can never add a day, and month ends, leap
  years and daylight-saving changes are all handled correctly.
- **Never negative.** Returning a book early or on time gives 0 days and ₹0.
- **Live while out, frozen once back.** For `status = 'issued'` the fine is computed on
  the fly for display only and nothing is saved. On return, `fine_days`, `fine_amount`
  and `fine_status` are written once, inside the transaction, and only if the row is
  still `status = 'issued'` — so a book cannot be fined twice.
- **Nothing is collected in advance.** `fine_paid` is 0 until the book is back, so a
  student cannot be charged for a book they still legitimately have.
- **Settings changes do not rewrite history.** Changing the rate affects books that are
  still out and future returns. Fines already stored on returned books keep the amount
  they were assessed at. (A one-off migration backfill uses the default ₹5/day.)

Defaults: **₹5 per day, fines enabled, 0 grace days, no maximum.**

---

## 5. Security notes

- **All queries use PDO prepared statements** with bound parameters
  (`includes/db.php` exposes `db_query()`, `db_all()`, `db_one()`, `db_val()`).
- All output is escaped with `e()` (`htmlspecialchars`) to prevent XSS. It also
  returns an empty string for non-scalar input rather than printing `Array`.
- Every state-changing form is protected by a CSRF token (`csrf_field()` / `check_csrf()`).
- `PDO::ERRMODE_EXCEPTION` and `PDO::ATTR_EMULATE_PREPARES => false` are enabled.
- Destructive actions ask for confirmation and use POST, never GET.
- **Request values are normalised through typed helpers**, never read raw.
  A hand-crafted request can send `?q[]=x` or `id[]=1`, and that matters more
  than it looks:
  - `trim()` / `htmlspecialchars()` / `urlencode()` throw a `TypeError` on an
    array, so a bad request becomes a fatal error page. On the login form that
    was reachable **without logging in**.
  - A bare `(int)` / `(float)` cast on an array silently yields `1` — no
    warning at all. A delete aimed at "no id" would quietly hit record #1.

  So `includes/functions.php` provides, and every page uses:

  | helper | use | array input |
  |---|---|---|
  | `str_param($v, $max)` | text fields | `''` |
  | `int_param($v, $default)` | ids, counts | `$default` (0), never `1` |
  | `float_param($v, $default)` | money | `$default` (0.0), rejects `INF`/`NAN` |

  `str_param()` also truncates to the column width, so an over-long value
  cannot overflow its `VARCHAR` and abort the query in strict SQL mode.
  `database/test_input_hardening.php` locks all of this in place.

---

## 6. Responsive UI

- Desktop: fixed sidebar (250px) + sticky top navbar + scrollable content area
- Mobile (<992px): the sidebar becomes a Bootstrap offcanvas toggled from the navbar
- Tables scroll horizontally inside `.table-responsive` instead of breaking the layout
- Smaller fonts, padding and card sizes under 576px
- Bootstrap Icons are used throughout

---

## 7. Creating another admin

Run this in phpMyAdmin's SQL tab (the password is plain here, hash it in PHP first):

```sql
INSERT INTO admins (name, email, password) VALUES
('Librarian', 'librarian@library.com',
 '$2y$10$M1596sOjwJzzr9NO0j2S9ukQ/Q2gZd/vIEKqXpXG695ya46onyJeO');  -- password: admin123
```

Or create a hash from a one-liner:

```bash
php -r "echo password_hash('yourpassword', PASSWORD_DEFAULT), PHP_EOL;"
```

---

## 8. Troubleshooting

| Problem                          | Fix |
| -------------------------------- | --- |
| "Database connection failed"     | Import `database/library.sql` and confirm the credentials in `includes/db.php`. |
| Blank page                       | Enable error display temporarily: set `display_errors = On` in `C:\xampp\php\php.ini`. |
| Page loads but is unstyled       | A vendored file is missing. Re-download the `assets/vendor/` files listed in section 1. |
| Icons show as empty boxes        | `assets/vendor/bootstrap-icons/css/fonts/` is missing or the CSS was moved without the fonts. |
| "Invalid or expired request"     | Session expired — just log in again. |
| Stuck on the login page          | Check that `session.save_path` in `php.ini` is writable, and cookies are not blocked. |
| Fine pages show SQL errors       | The fine columns/tables are missing. Import `database/migration_fines.sql` (section 3a). |
| "Duplicate column name" on import| You already ran `migration_fines.sql`. Nothing to do — the fine system is in place. |
| A fine looks wrong               | Check **Fine Settings** (rate / grace / cap) and remember the fine is frozen at return. |
| Rupee symbol shows as `?`        | A terminal/PHP console encoding issue, not a data problem. The web pages are UTF-8. |

---

## 9. Running the tests

The project ships with seven command-line test scripts. Start Apache and MySQL
first, then:

```bash
C:\xampp\php\php.exe database\test_fines.php             # 45 calculation checks
C:\xampp\php\php.exe database\test_fine_writes.php       # 54 return/payment checks
C:\xampp\php\php.exe database\test_fine_batch.php        # batched vs per-student totals
C:\xampp\php\php.exe database\test_input_hardening.php  # 58 crafted-input checks
C:\xampp\php\php.exe database\test_admin_forms.php       # 60 end-to-end form checks
C:\xampp\php\php.exe database\test_xss.php               # 38 stored-XSS checks
C:\xampp\php\php.exe database\test_money_precision.php   # 64 rounding checks
```

Each prints `RESULT: N passed, 0 failed` and exits non-zero on failure.

`test_fines.php` and `test_fine_batch.php` are read-only. The other five write
to the database, but they only touch rows they create themselves, and they put
the fine settings back the way they found them — so they can be run against the
live install, though a copy is still the safer habit.

Two of them are worth knowing about:

- **`test_input_hardening.php`** sends array-shaped parameters (`?q[]=x`) to
  every page and form, then fingerprints every table before and after to prove
  that none of those requests quietly deleted or edited a row. It covers the
  login form too, since that is reachable without a session.
- **`test_admin_forms.php`** is the counterpart: it drives the ordinary path end
  to end — add a book, edit it, add and edit a student, issue, return late,
  confirm the fine, take a partial payment, settle in full, bulk-return — to
  prove the stricter input helpers did not break normal submissions.

`test_money_precision.php` exists because amounts are `DECIMAL(10,2)` in MySQL
but computed in PHP floats. It pins down the messy cases: a Rs 0.10/day rate
over three days must be Rs 0.30 and not `0.30000000000000004`, remaining must
never go negative, and paying the exact outstanding balance must be accepted
rather than reported as an overpayment.

To rehearse the upgrade path, `database/migration_test_setup.sql` builds a scratch
database with the old schema; point `migration_fines.sql` at it (change the `USE`
line) and confirm the backfill, then drop the scratch database.
