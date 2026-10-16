CREATE DATABASE IF NOT EXISTS college_website CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE college_website;

CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(60) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL
);

CREATE TABLE IF NOT EXISTS branches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_name VARCHAR(120) NOT NULL
);

CREATE TABLE IF NOT EXISTS students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_name VARCHAR(120) NOT NULL,
    roll_no VARCHAR(60) UNIQUE NOT NULL,
    email VARCHAR(150),
    phone VARCHAR(30) NULL,
    branch_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(branch_id) REFERENCES branches(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS fees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    status ENUM('Pending','Paid') NOT NULL DEFAULT 'Pending',
    transaction_id VARCHAR(100) NULL,
    payment_mode VARCHAR(50) DEFAULT 'Online UPI',
    payment_date DATETIME NULL,
    remarks VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS admissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    application_no VARCHAR(60) UNIQUE NOT NULL,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    course_name VARCHAR(120) NOT NULL,
    qualification VARCHAR(100) NOT NULL,
    score_percent DECIMAL(5,2) NOT NULL,
    address TEXT,
    status ENUM('Under Review','Approved','Rejected') NOT NULL DEFAULT 'Under Review',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS inquiries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NULL,
    subject VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('New','Resolved') NOT NULL DEFAULT 'New',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Seed Branches
INSERT IGNORE INTO branches(id, branch_name) VALUES
(1, 'Computer Applications & IT'),
(2, 'Management Studies (BBA/MBA)'),
(3, 'Commerce & Finance'),
(4, 'Applied Sciences & Biotech');

-- Demo password Bhavya@3108 (hash below verified)
INSERT IGNORE INTO admins(username, password_hash) VALUES
('Amar', '$2y$10$PhGFbBcQ4aiyyoxlrDIPguh8Ur/89EDthPGQ9lal6FQQlhbJ.65o2');

-- Sample Students
INSERT IGNORE INTO students (id, student_name, roll_no, email, phone, branch_id) VALUES
(1, 'Aarav Sharma', 'BCA202601', 'aarav.sharma@example.com', '9876543210', 1),
(2, 'Priya Patel', 'BCA202602', 'priya.patel@example.com', '9812345678', 1),
(3, 'Rohan Verma', 'BBA202601', 'rohan.verma@example.com', '9898989898', 2),
(4, 'Ananya Singh', 'BCOM202601', 'ananya.singh@example.com', '9765432109', 3);

-- Sample Fees & Transactions
INSERT IGNORE INTO fees (id, student_id, amount, status, transaction_id, payment_mode, payment_date, remarks) VALUES
(1, 1, 24500.00, 'Pending', NULL, 'Pending Payment', NULL, 'Semester 5 Tuition Fee'),
(2, 2, 24500.00, 'Paid', 'TXN-2026-984321', 'UPI (Google Pay)', NOW(), 'Semester 5 Tuition Fee'),
(3, 3, 31000.00, 'Pending', NULL, 'Pending Payment', NULL, 'Semester 3 Management Fee'),
(4, 4, 18500.00, 'Paid', 'TXN-2026-773412', 'Net Banking HDFC', NOW(), 'Semester 1 Admission Fee'),
(5, 2, 2500.00, 'Pending', NULL, 'Pending Payment', NULL, 'Semester Examination Fee');

-- Sample Online Admission Applications
INSERT IGNORE INTO admissions (id, application_no, full_name, email, phone, course_name, qualification, score_percent, address, status) VALUES
(1, 'APP-2026-1042', 'Neha Gupta', 'neha.gupta@example.com', '9822334455', 'Bachelor of Computer Applications (BCA)', '12th Science / Math', 88.50, 'Lucknow, Uttar Pradesh', 'Approved'),
(2, 'APP-2026-1043', 'Vikram Malhotra', 'vikram.m@example.com', '9833445566', 'Master of Business Admin (MBA)', 'Graduation (B.Com)', 79.20, 'Kanpur, Uttar Pradesh', 'Under Review');

-- Sample Inquiries
INSERT IGNORE INTO inquiries (id, name, email, phone, subject, message, status) VALUES
(1, 'Rajesh Khanna', 'rajesh.k@example.com', '9844556677', 'Hostel Facility Query', 'Does the college provide campus hostel facilities for first-year outstation students?', 'New');

-- Attendance Table
CREATE TABLE IF NOT EXISTS attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    attendance_date DATE NOT NULL,
    status ENUM('Present', 'Absent', 'Leave', 'Late') NOT NULL DEFAULT 'Present',
    remarks VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_student_date (student_id, attendance_date),
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

-- Seed Attendance Records
INSERT IGNORE INTO attendance (student_id, attendance_date, status, remarks) VALUES
(1, CURDATE(), 'Present', 'Regular Class'),
(1, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'Present', 'Regular Class'),
(1, DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'Present', 'Lab Practical'),
(1, DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'Absent', 'Uninformed'),
(1, DATE_SUB(CURDATE(), INTERVAL 4 DAY), 'Present', 'Regular Class'),
(2, CURDATE(), 'Present', 'Regular Class'),
(2, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'Present', 'Regular Class'),
(2, DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'Present', 'Lab Practical'),
(2, DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'Present', 'Regular Class'),
(3, CURDATE(), 'Absent', 'Absent'),
(3, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'Absent', 'Absent'),
(3, DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'Present', 'Seminar'),
(4, CURDATE(), 'Present', 'Regular Class'),
(4, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'Present', 'Regular Class');

