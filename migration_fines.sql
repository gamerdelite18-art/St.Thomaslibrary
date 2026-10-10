-- ============================================================
--  LIBRARY MANAGEMENT SYSTEM - LATE FINE MIGRATION
--  For installations that ALREADY imported the older library.sql
--  (those with data you want to keep). Run once, in phpMyAdmin.
--  A fresh install? Just import library.sql instead - it already
--  contains everything below.
-- ============================================================

USE library_db;

-- ------------------------------------------------------------
-- 1. Extend issued_books with the fine columns
-- ------------------------------------------------------------
ALTER TABLE issued_books
    ADD COLUMN fine_days   INT NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN fine_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER fine_days,
    ADD COLUMN fine_paid   DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER fine_amount,
    ADD COLUMN fine_status ENUM('none','unpaid','partial','paid') NOT NULL DEFAULT 'none' AFTER fine_paid,
    ADD INDEX idx_issue_fine (fine_status);

-- ------------------------------------------------------------
-- 2. Configurable fine settings (Rs 5/day, on, 0 grace, no cap)
-- ------------------------------------------------------------
CREATE TABLE settings (
    setting_key   VARCHAR(50) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO settings (setting_key, setting_value) VALUES
('fine_enabled',    '1'),
('fine_per_day',    '5.00'),
('fine_grace_days', '0'),
('fine_max_amount', '0');

-- ------------------------------------------------------------
-- 3. Payment audit trail
-- ------------------------------------------------------------
CREATE TABLE fine_payments (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    issued_book_id INT NOT NULL,
    amount         DECIMAL(10,2) NOT NULL,
    payment_date   DATE NOT NULL,
    payment_method VARCHAR(30) NOT NULL DEFAULT 'Cash',
    note           VARCHAR(255) DEFAULT NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (issued_book_id) REFERENCES issued_books(id) ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_payment_loan (issued_book_id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 4. Backfill fines for loans that were already returned
--    The due date is return_date, the actual return is returned_at,
--    and Rs 5 is charged per day after the due date.
--    GREATEST(0, ...) matters: a book returned early or on time must never
--    store a negative day count.
--    The rate below is the default Rs 5 with no grace period; if you later
--    change those in Fine Settings, already-returned books keep these figures.
-- ------------------------------------------------------------
UPDATE issued_books
   SET fine_days   = GREATEST(0, DATEDIFF(returned_at, return_date)),
       fine_amount = GREATEST(0, DATEDIFF(returned_at, return_date)) * 5.00,
       fine_status = IF(DATEDIFF(returned_at, return_date) > 0, 'unpaid', 'none')
 WHERE status = 'returned'
   AND returned_at IS NOT NULL;

-- Sanity check: run these, every value should be >= 0
SELECT id, issue_date, return_date, returned_at, fine_days, fine_amount, fine_status
  FROM issued_books
 WHERE status = 'returned';

-- ------------------------------------------------------------
-- NOTE: books that are still issued (status = 'issued') keep
-- fine_amount = 0 on purpose. Their fine is worked out live from
-- today's date while the book is out, and is only written to the
-- database once the book comes back.
-- ------------------------------------------------------------
