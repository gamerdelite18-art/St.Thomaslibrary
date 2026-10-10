-- ============================================================
--  LIBRARY MANAGEMENT SYSTEM - DATABASE SCHEMA
--  MySQL / MariaDB  (import via phpMyAdmin on XAMPP)
-- ============================================================

DROP DATABASE IF EXISTS library_db;
CREATE DATABASE library_db DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE library_db;

-- ------------------------------------------------------------
-- 1. ADMINS  (librarian / administrator accounts)
-- ------------------------------------------------------------
CREATE TABLE admins (
    id       INT AUTO_INCREMENT PRIMARY KEY,
    name     VARCHAR(100) NOT NULL,
    email    VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL          -- stored hashed (password_hash)
) ENGINE=InnoDB;

-- Default login: admin@library.com / admin123
INSERT INTO admins (name, email, password) VALUES
('System Admin', 'admin@library.com', '$2y$10$M1596sOjwJzzr9NO0j2S9ukQ/Q2gZd/vIEKqXpXG695ya46onyJeO');

-- ------------------------------------------------------------
-- 2. BOOKS  (catalogue + stock)
-- ------------------------------------------------------------
CREATE TABLE books (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    title              VARCHAR(200) NOT NULL,
    author             VARCHAR(150) NOT NULL,
    category           VARCHAR(100) DEFAULT NULL,
    isbn               VARCHAR(20)  DEFAULT NULL,
    quantity           INT NOT NULL DEFAULT 1,
    available_quantity INT NOT NULL DEFAULT 1,
    added_date         DATE NOT NULL,
    INDEX idx_books_title (title),
    INDEX idx_books_isbn  (isbn)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 3. STUDENTS  (library members / borrowers)
-- ------------------------------------------------------------
CREATE TABLE students (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(150) NOT NULL,
    email      VARCHAR(150) NOT NULL UNIQUE,
    phone      VARCHAR(20)  DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 4. ISSUED_BOOKS  (issue / return transactions)
--    book_id    -> books.id       (ON DELETE CASCADE)
--    student_id -> students.id    (ON DELETE CASCADE)
-- ------------------------------------------------------------
CREATE TABLE issued_books (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    book_id     INT NOT NULL,
    student_id  INT NOT NULL,
    issue_date  DATE NOT NULL,
    return_date DATE NOT NULL,                       -- DUE date (expected return)
    returned_at DATE DEFAULT NULL,                    -- actual return date (set on return)
    status      ENUM('issued','returned') NOT NULL DEFAULT 'issued',

    -- ---- LATE-RETURN FINE -------------------------------------------------
    -- The fine is assessed ONCE, when the book is returned, and then frozen.
    -- While status = 'issued' the fine is calculated live on screen only,
    -- so viewing a record many times never changes any number.
    fine_days   INT NOT NULL DEFAULT 0,               -- days late (0 when on time)
    fine_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,  -- final fine, frozen at return
    fine_paid   DECIMAL(10,2) NOT NULL DEFAULT 0.00,  -- amount collected so far
    fine_status ENUM('none','unpaid','partial','paid') NOT NULL DEFAULT 'none',
    -- ------------------------------------------------------------------

    FOREIGN KEY (book_id)    REFERENCES books(id)    ON UPDATE CASCADE ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_issue_status (status),
    INDEX idx_issue_fine  (fine_status)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 5. SETTINGS  (admin-configurable key/value pairs)
--    Stores the fine rate, on/off switch, cap and grace period.
-- ------------------------------------------------------------
CREATE TABLE settings (
    setting_key   VARCHAR(50) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Defaults required by the spec: Rs 5 / day, fines ON, 0 grace days, no maximum
INSERT INTO settings (setting_key, setting_value) VALUES
('fine_enabled',    '1'),      -- 1 = fines active, 0 = switched off
('fine_per_day',    '5.00'),   -- Rupees charged per overdue day
('fine_grace_days', '0'),      -- days after the due date that are free
('fine_max_amount', '0');      -- 0 = no maximum

-- ------------------------------------------------------------
-- 6. FINE_PAYMENTS  (audit trail of money collected)
--    issued_book_id -> issued_books.id (ON DELETE CASCADE)
-- ------------------------------------------------------------
CREATE TABLE fine_payments (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    issued_book_id INT NOT NULL,
    amount         DECIMAL(10,2) NOT NULL,             -- amount collected this time
    payment_date   DATE NOT NULL,
    payment_method VARCHAR(30) NOT NULL DEFAULT 'Cash',
    note           VARCHAR(255) DEFAULT NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (issued_book_id) REFERENCES issued_books(id) ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_payment_loan (issued_book_id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- SAMPLE DATA (optional - safe to ignore)
-- ------------------------------------------------------------
INSERT INTO students (name, email, phone) VALUES
('Aarav Sharma',  'aarav@example.com',  '9876543210'),
('Diya Patel',    'diya@example.com',   '9876543211'),
('Rohan Mehta',   'rohan@example.com',  '9876543212'),
('Sneha Iyer',    'sneha@example.com',  '9876543213'),
('Kabir Singh',   'kabir@example.com',  '9876543214');

INSERT INTO books (title, author, category, isbn, quantity, available_quantity, added_date) VALUES
('The Great Gatsby',          'F. Scott Fitzgerald', 'Fiction',     '9780743273565', 5, 5, CURDATE()),
('Introduction to Algorithms', 'Thomas H. Cormen',    'Computer',   '9780262046305', 3, 3, CURDATE()),
('Clean Code',                'Robert C. Martin',     'Technology', '9780132350884', 4, 4, CURDATE()),
('A Brief History of Time',   'Stephen Hawking',     'Science',    '9780553380163', 2, 2, CURDATE()),
('The Selfish Gene',          'Richard Dawkins',      'Biology',    '9780198788607', 3, 3, CURDATE()),
('Sapiens: A Brief History',  'Yuval Noah Harari',    'History',    '9780062316097', 4, 4, CURDATE());

-- Fines are written explicitly for the returned rows so the demo data shows
-- every fine state. Defaults: Rs 5/day, 0 grace days, no cap.
-- (issue_date, return_date=due, returned_at, fine_days, fine_amount, fine_paid, fine_status)
INSERT INTO issued_books
  (book_id, student_id, issue_date, return_date, returned_at, status, fine_days, fine_amount, fine_paid, fine_status)
VALUES
-- on loan, due in 14 days -> no fine yet
(1, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 14 DAY), NULL, 'issued', 0, 0.00, 0.00, 'none'),
-- on loan, due in 7 days -> no fine yet
(2, 2, CURDATE(), DATE_ADD(CURDATE(), INTERVAL  7 DAY), NULL, 'issued', 0, 0.00, 0.00, 'none'),
-- OUTSTANDING: issued 17 days ago, due 3 days ago -> 3 days late, fine accruing live = 3 x 5 = Rs 15
(3, 3, DATE_SUB(CURDATE(), INTERVAL 17 DAY), DATE_SUB(CURDATE(), INTERVAL  3 DAY), NULL, 'issued', 0, 0.00, 0.00, 'none'),
-- RETURNED 2 DAYS LATE and nothing paid: 2 x 5 = Rs 10 UNPAID
(4, 4, DATE_SUB(CURDATE(), INTERVAL 20 DAY), DATE_SUB(CURDATE(), INTERVAL 10 DAY),
    DATE_SUB(CURDATE(), INTERVAL 8 DAY), 'returned', 2, 10.00, 0.00, 'unpaid'),
-- RETURNED ON TIME: no fine
(6, 2, DATE_SUB(CURDATE(), INTERVAL 25 DAY), DATE_SUB(CURDATE(), INTERVAL 12 DAY),
    DATE_SUB(CURDATE(), INTERVAL 15 DAY), 'returned', 0, 0.00, 0.00, 'none'),
-- RETURNED 4 DAYS LATE, PARTIALLY PAID: fine Rs 20, Rs 8 collected -> Rs 12 still due
(5, 5, DATE_SUB(CURDATE(), INTERVAL 30 DAY), DATE_SUB(CURDATE(), INTERVAL 14 DAY),
    DATE_SUB(CURDATE(), INTERVAL 10 DAY), 'returned', 4, 20.00, 8.00, 'partial');

-- payment history for the partially paid row above (loan id 6)
INSERT INTO fine_payments (issued_book_id, amount, payment_date, payment_method, note) VALUES
(6, 8.00, DATE_SUB(CURDATE(), INTERVAL 9 DAY), 'Cash', 'Part payment at the desk');

-- (book_id 1,2,3 are issued; 4,5,6 are returned)
-- keep stock in sync with the sample issues above
UPDATE books b
  LEFT JOIN (
        SELECT book_id, COUNT(*) AS issued
          FROM issued_books
         WHERE status = 'issued'
         GROUP BY book_id
  ) i ON i.book_id = b.id
   SET b.available_quantity = b.quantity - COALESCE(i.issued, 0);

