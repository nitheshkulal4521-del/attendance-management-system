CREATE DATABASE attendance_system;
USE attendance_system;

-- =========================================
-- 1. USERS
-- =========================================
CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('teacher','student') NOT NULL
);


-- =========================================
-- 2. TEACHERS
-- =========================================
CREATE TABLE teachers (
    teacher_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    teacher_name VARCHAR(100) NOT NULL,
    employee_id VARCHAR(30) UNIQUE NOT NULL,
    department VARCHAR(100) NOT NULL,
    email VARCHAR(100),

    FOREIGN KEY (user_id)
        REFERENCES users(user_id)
        ON DELETE SET NULL
);


-- =========================================
-- 3. STUDENTS
-- =========================================
CREATE TABLE students (
    student_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNIQUE,
    roll_no VARCHAR(20) UNIQUE,
    student_name VARCHAR(100) NOT NULL,
    semester INT NOT NULL,
    department VARCHAR(50),

    FOREIGN KEY (user_id)
        REFERENCES users(user_id)
        ON DELETE SET NULL
);


-- =========================================
-- 4. SUBJECTS
-- =========================================
CREATE TABLE subjects (
    subject_id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT,
    subject_name VARCHAR(100) NOT NULL,
    semester INT NOT NULL,

    FOREIGN KEY (teacher_id)
        REFERENCES teachers(teacher_id)
        ON DELETE SET NULL
);


-- =========================================
-- 5. CLASSES
-- =========================================
CREATE TABLE classes (
    class_id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    class_name VARCHAR(100) NOT NULL,
    department VARCHAR(100) NOT NULL,
    semester INT NOT NULL,
    academic_year VARCHAR(20) NOT NULL,
    room_number VARCHAR(50),

    FOREIGN KEY (teacher_id)
        REFERENCES teachers(teacher_id)
        ON DELETE CASCADE
);


-- =========================================
-- 6. CLASS SESSIONS
-- =========================================
CREATE TABLE class_sessions (
    session_id INT AUTO_INCREMENT PRIMARY KEY,
    class_id INT NOT NULL,
    subject_id INT NOT NULL,
    class_date DATE NOT NULL,

    FOREIGN KEY (class_id)
        REFERENCES classes(class_id)
        ON DELETE CASCADE,

    FOREIGN KEY (subject_id)
        REFERENCES subjects(subject_id)
        ON DELETE CASCADE
);


-- =========================================
-- 7. CLASS STUDENTS
-- =========================================
CREATE TABLE class_students (
    class_id INT NOT NULL,
    student_id INT NOT NULL,

    PRIMARY KEY (class_id, student_id),

    FOREIGN KEY (class_id)
        REFERENCES classes(class_id)
        ON DELETE CASCADE,

    FOREIGN KEY (student_id)
        REFERENCES students(student_id)
        ON DELETE CASCADE
);


-- =========================================
-- 8. ATTENDANCE
-- =========================================
CREATE TABLE attendance (
    attendance_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    session_id INT NOT NULL,
    status ENUM('Present','Absent') DEFAULT 'Present',

    FOREIGN KEY (student_id)
        REFERENCES students(student_id)
        ON DELETE CASCADE,

    FOREIGN KEY (session_id)
        REFERENCES class_sessions(session_id)
        ON DELETE CASCADE,

    UNIQUE (student_id, session_id)
);


-- =========================================
-- 9. ABSENCE REASONS / PROOF
-- =========================================
CREATE TABLE absence_reasons (
    reason_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    session_id INT NOT NULL,
    reason TEXT,
    proof_file VARCHAR(255),
    status ENUM('Pending','Approved','Rejected') DEFAULT 'Pending',

    FOREIGN KEY (student_id)
        REFERENCES students(student_id)
        ON DELETE CASCADE,

    FOREIGN KEY (session_id)
        REFERENCES class_sessions(session_id)
        ON DELETE CASCADE
);


-- =========================================
-- 10. GRACE ATTENDANCE
-- =========================================
CREATE TABLE grace_attendance (
    grace_id INT AUTO_INCREMENT PRIMARY KEY,
    attendance_id INT NOT NULL,
    teacher_id INT NOT NULL,
    status ENUM('Granted','Denied') NOT NULL,
    remarks TEXT NULL,
    action_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_grace_attendance (attendance_id),

    FOREIGN KEY (attendance_id)
        REFERENCES attendance(attendance_id)
        ON DELETE CASCADE,

    FOREIGN KEY (teacher_id)
        REFERENCES teachers(teacher_id)
        ON DELETE CASCADE
);


-- =========================================
-- 11. ACTIVITY LOGS
-- =========================================
CREATE TABLE activity_logs (
    activity_id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    class_id INT DEFAULT NULL,
    action_type VARCHAR(50) NOT NULL,
    description VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (teacher_id)
        REFERENCES teachers(teacher_id)
        ON DELETE CASCADE,

    FOREIGN KEY (class_id)
        REFERENCES classes(class_id)
        ON DELETE SET NULL
);

-- =========================================
-- 12. CLASS SUBJECTS
-- =========================================
CREATE TABLE class_subjects (
    class_id INT NOT NULL,
    subject_id INT NOT NULL,

    PRIMARY KEY (class_id, subject_id),

    FOREIGN KEY (class_id)
        REFERENCES classes(class_id)
        ON DELETE CASCADE,

    FOREIGN KEY (subject_id)
        REFERENCES subjects(subject_id)
        ON DELETE CASCADE
);


-- =========================================
-- CHECK TABLES
-- =========================================
SHOW TABLES;
