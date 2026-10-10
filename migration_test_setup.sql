-- Scratch database that mimics the PRE-FINE schema, used only to prove
-- migration_fines.sql upgrades an existing install correctly.
DROP DATABASE IF EXISTS library_mig_test;
CREATE DATABASE library_mig_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE library_mig_test;

CREATE TABLE books (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    author VARCHAR(150) NOT NULL,
    category VARCHAR(80) NOT NULL,
    isbn VARCHAR(20) DEFAULT NULL,
    quantity INT NOT NULL DEFAULT 1,
    available_quantity INT NOT NULL DEFAULT 1,
    shelf VARCHAR(50) DEFAULT NULL,
    description TEXT,
    added_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    phone VARCHAR(20) DEFAULT NULL,
    department VARCHAR(100) DEFAULT NULL,
    year VARCHAR(30) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- The OLD issued_books: no fine columns at all
CREATE TABLE issued_books (
    id INT AUTO_INCREMENT PRIMARY KEY,
    book_id INT NOT NULL,
    student_id INT NOT NULL,
    issue_date DATE NOT NULL,
    return_date DATE NOT NULL,
    returned_at DATE DEFAULT NULL,
    status ENUM('issued','returned') NOT NULL DEFAULT 'issued',
    FOREIGN KEY (book_id) REFERENCES books(id) ON UPDATE CASCADE ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_issue_status (status)
) ENGINE=InnoDB;

INSERT INTO books (id,title,author,category,isbn,quantity,available_quantity) VALUES
 (1,'Alpha Book','A','Fiction','X1',5,5),
 (2,'Beta Book','B','Fiction','X2',5,5),
 (3,'Gamma Book','C','Fiction','X3',5,5),
 (4,'Delta Book','D','Fiction','X4',5,5),
 (5,'Epsilon Book','E','Fiction','X5',5,5);
INSERT INTO students (id,name,email) VALUES
 (1,'S One','s1@x.com'),(2,'S Two','s2@x.com'),(3,'S Three','s3@x.com');

-- The five interesting cases
INSERT INTO issued_books (id,book_id,student_id,issue_date,return_date,returned_at,status) VALUES
 (1,1,1,'2026-09-01','2026-09-15','2026-09-18','returned'),  -- 3 days late -> 15.00 unpaid
 (2,2,2,'2026-09-01','2026-09-15','2026-09-15','returned'),  -- on time      -> 0.00 none
 (3,3,3,'2026-09-01','2026-09-15','2026-09-13','returned'),  -- 2 days early -> 0.00 none, days must be 0 not -2
 (4,4,1,'2026-09-01','2026-09-15',NULL,             'returned'),  -- legacy row with no actual return date -> untouched
 (5,5,2,'2026-09-01','2026-09-15',NULL,             'issued');    -- still out -> untouched
