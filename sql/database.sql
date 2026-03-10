CREATE DATABASE academics_cheduling;
USE academicscheduling;

-- Users table
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100),
    role ENUM('admin', 'instructor', 'program_chair') NOT NULL,
    full_name VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Instructors table
CREATE TABLE instructors (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT UNIQUE,
    department VARCHAR(100),
    specialization VARCHAR(100),
    max_hours_per_week INT DEFAULT 20,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Rooms table
CREATE TABLE rooms (
    id INT PRIMARY KEY AUTO_INCREMENT,
    room_number VARCHAR(20) UNIQUE NOT NULL,
    capacity INT NOT NULL,
    building VARCHAR(50),
    has_projector BOOLEAN DEFAULT FALSE,
    has_computers BOOLEAN DEFAULT FALSE
);

-- Subjects table
CREATE TABLE subjects (
    id INT PRIMARY KEY AUTO_INCREMENT,
    subject_code VARCHAR(20) UNIQUE NOT NULL,
    subject_name VARCHAR(100) NOT NULL,
    credits INT NOT NULL,
    department VARCHAR(100),
    hours_per_week INT NOT NULL
);

-- Departments table
CREATE TABLE departments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    dept_name VARCHAR(100) UNIQUE NOT NULL,
    dept_code VARCHAR(20) UNIQUE NOT NULL
);

-- Time slots table
CREATE TABLE time_slots (
    id INT PRIMARY KEY AUTO_INCREMENT,
    day ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'),
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    UNIQUE KEY unique_timeslot (day, start_time, end_time)
);
CREATE TABLE program_subjects (
  program_id INT NOT NULL,
  subject_id INT NOT NULL,
  PRIMARY KEY (program_id, subject_id),
  FOREIGN KEY (program_id) REFERENCES departments(id),
  FOREIGN KEY (subject_id) REFERENCES subjects(id)
);
-- Instructor availability
CREATE TABLE instructor_availability (
    id INT PRIMARY KEY AUTO_INCREMENT,
    instructor_id INT,
    time_slot_id INT,
    is_available BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (instructor_id) REFERENCES instructors(id) ON DELETE CASCADE,
    FOREIGN KEY (time_slot_id) REFERENCES time_slots(id) ON DELETE CASCADE
);

-- Schedule generation jobs
CREATE TABLE schedule_jobs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    job_name VARCHAR(100),
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    created_by INT,
    input_data JSON,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Generated schedules
CREATE TABLE schedules (
    id INT PRIMARY KEY AUTO_INCREMENT,
    job_id INT,
    subject_id INT,
    instructor_id INT,
    room_id INT,
    time_slot_id INT,
    department VARCHAR(100),
    year_level INT,
    section VARCHAR(10),
    is_published BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (job_id) REFERENCES schedule_jobs(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id),
    FOREIGN KEY (instructor_id) REFERENCES instructors(id),
    FOREIGN KEY (room_id) REFERENCES rooms(id),
    FOREIGN KEY (time_slot_id) REFERENCES time_slots(id)
);

-- Insert default admin user (password: admin123)
INSERT INTO users (username, password, email, role, full_name) VALUES 
('admin', '$2y$10$J9gJpGCWsVv7Q7QgPrD3B.ACeAbeH9YyzAQc3rg30cyJnRbn9Kjhm', 'admin@school.edu', 'admin', 'System Administrator');

-- Insert sample time slots (7:00 AM - 5:30 PM)
INSERT INTO time_slots (day, start_time, end_time) VALUES
('Monday', '07:00', '08:30'),
('Monday', '08:30', '10:00'),
('Monday', '10:00', '11:30'),
('Monday', '11:30', '13:00'),
('Monday', '13:00', '14:30'),
('Monday', '14:30', '16:00'),
('Monday', '16:00', '17:30'),
('Tuesday', '07:00', '08:30'),
('Tuesday', '08:30', '10:00'),
('Tuesday', '10:00', '11:30'),
('Tuesday', '11:30', '13:00'),
('Tuesday', '13:00', '14:30'),
('Tuesday', '14:30', '16:00'),
('Tuesday', '16:00', '17:30');