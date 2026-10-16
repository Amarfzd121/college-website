# 🎓 School of Management Sciences (SMS) - College Website & Student Portal

A modern, full-stack, responsive college web application built with **PHP**, **MySQL**, **CSS3**, and **JavaScript**. Designed for higher education institutions to manage students, academic branches, online admissions, and digital fee transactions.

---

## ✨ Features

- **📱 100% Mobile Responsive Design**: Optimized for smartphones, tablets, and desktops with smooth hamburger navigation drawer, touch controls, and fluid typography.
- **🏛️ Public Academic Portal**:
  - **Home**: Dynamic hero section, NAAC accreditations, stats, and key highlights.
  - **About**: Vision, mission, institutional accreditations, and achievements.
  - **Courses**: Undergraduate & postgraduate degree catalogs (BCA, BBA, B.Com, MCA, MBA, B.Tech) with fees and eligibility.
  - **Admissions**: Digital application submission with real-time database recording.
  - **Faculty Directory**: Professor profiles, qualifications, and department details.
  - **Contact & Inquiries**: Direct inquiry form connected to admin desk.
- **💳 Online Fee Payment Gateway**:
  - Live Roll Number search and invoice lookup.
  - UPI / Dynamic QR Code payment simulation.
  - Debit/Credit card payment simulation with live interactive card preview.
  - Net Banking & Edu EMI channels.
  - Official printable/downloadable institutional fee receipts with authentication QR code.
- **📅 Student Attendance Portal & Exam Eligibility Radar**:
  - Live Roll Number attendance search with animated circular percentage meter.
  - Mandatory university 75% examination eligibility indicator (Eligible vs Shortage warnings).
  - Lecture statistics: Total classes held, attended, absent, approved leaves, and late entries.
  - Chronological day-by-day attendance register.
  - Printable official attendance slips with signature verification blocks.
- **🔐 Administrative Control Panel**:
  - Executive Dashboard with real-time KPI metrics (Revenue collected, pending dues, enrollment counters, daily attendance rate).
  - Daily Attendance Roll Call Sheet with one-click bulk marking ("Mark All Present/Absent/Late").
  - Cumulative Student Attendance Radar & Shortage Monitor with color-coded progress bars.
  - Branch & Department Management (Add/Delete).
  - Student Records Directory (Add/Delete/List).
  - Fee Invoicing & Desk Cash Collection (Mark Paid, record transactions).
  - Online Admission Applications review (Approve/Reject/Review).
  - Student Inquiries Management.

---

## 🛠️ Technology Stack

- **Backend**: PHP (PDO with MySQL prepared statements)
- **Database**: MySQL / MariaDB (UTF-8 mb4)
- **Frontend**: HTML5, Vanilla CSS3 (Custom design system, glassmorphism), Vanilla JavaScript
- **Server**: Apache (via XAMPP / WAMP / LAMP)

---

## 🚀 Localhost Setup & Installation

### 1. Requirements
- [XAMPP](https://www.apachefriends.org/) (PHP 7.4+ or PHP 8.x, Apache, MySQL)

### 2. Deployment
1. Clone this repository or copy the project folder into your XAMPP `htdocs` directory:
   ```bash
   git clone https://github.com/Amarfzd121/college-website.git C:\xampp\htdocs\college_website
   ```
2. Start **Apache** and **MySQL** from the **XAMPP Control Panel**.

### 3. Database Import
1. Open your browser and navigate to `http://localhost/phpmyadmin/`.
2. Click on **Import**.
3. Choose the `database.sql` file included in this repository and click **Go**.
4. The `college_website` database will be created with pre-seeded sample data.

### 4. Run the Website
- **Main Website**: [http://localhost/college_website/](http://localhost/college_website/)
- **Student Attendance Portal**: [http://localhost/college_website/attendance.php](http://localhost/college_website/attendance.php)
- **Fee Payment Portal**: [http://localhost/college_website/payment.php](http://localhost/college_website/payment.php)
- **Admin Control Panel**: [http://localhost/college_website/admin/login.php](http://localhost/college_website/admin/login.php)
- **Admin Attendance Management**: [http://localhost/college_website/admin/dashboard.php?s=attendance](http://localhost/college_website/admin/dashboard.php?s=attendance)


---

## 🔑 Default Admin Credentials

- **URL**: `http://localhost/college_website/admin/login.php`
- **Username**: `Amar`
- **Password**: `Bhavya@3108`

---

## 📄 License
This project is open-source and available for educational and institutional management use.
