DROP DATABASE IF EXISTS `academic_scheduling`;
CREATE DATABASE `academic_scheduling` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `academic_scheduling`;

-- Users table
CREATE TABLE `users` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `username` VARCHAR(50) UNIQUE NOT NULL,
    `password` VARCHAR(255) NOT NULL,
    `email` VARCHAR(100),
    `role` ENUM('admin', 'instructor', 'program_chair') NOT NULL,
    `full_name` VARCHAR(100),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Instructors table
CREATE TABLE `instructors` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `user_id` INT UNIQUE,
    `department` VARCHAR(100),
    `specialization` VARCHAR(100),
    `status` VARCHAR(20),
    `max_hours_per_week` INT DEFAULT 20,
    `program_id` INT NULL,
    `designation` VARCHAR(100),
    `designation_units` DECIMAL(4,2) DEFAULT 0,
    `research_extension` VARCHAR(20),
    `research_extension_units` DECIMAL(4,2) DEFAULT 0,
    `special_assignment` VARCHAR(100),
    `special_assignment_units` DECIMAL(4,2) DEFAULT 0,
    `rank` VARCHAR(100),
    `education` TEXT,
    `eligibility` VARCHAR(100),
    `service_years` VARCHAR(20),
    `photo` VARCHAR(255) NULL,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Rooms table
CREATE TABLE `rooms` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `room_number` VARCHAR(20) UNIQUE NOT NULL,
    `capacity` INT NOT NULL,
    `building` VARCHAR(50),
    `has_projector` BOOLEAN DEFAULT FALSE,
    `has_computers` BOOLEAN DEFAULT FALSE
) ENGINE=InnoDB;

-- Subjects table
CREATE TABLE `subjects` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `subject_code` VARCHAR(20) UNIQUE NOT NULL,
    `subject_name` VARCHAR(100) NOT NULL,
    `credits` INT NOT NULL,
    `department` VARCHAR(100),
    `subject_type` ENUM('major','minor') NOT NULL DEFAULT 'major',
    `semester` ENUM('1st Semester','2nd Semester','Summer') NOT NULL DEFAULT '1st Semester',
    `year_level` INT NULL,
    `prerequisites` TEXT NULL,
    `program_id` INT NULL,
    `hours_per_week` DECIMAL(4,2) NOT NULL,
    `lecture_hours` DECIMAL(4,2) NOT NULL DEFAULT 0.00,
    `lab_hours` DECIMAL(4,2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (`program_id`) REFERENCES `programs`(`id`)
) ENGINE=InnoDB;

-- Departments table
CREATE TABLE `departments` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `dept_name` VARCHAR(100) UNIQUE NOT NULL,
    `dept_code` VARCHAR(20) UNIQUE NOT NULL
) ENGINE=InnoDB;

-- Programs table (for program_chairs)
CREATE TABLE `programs` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `program_name` VARCHAR(100) UNIQUE NOT NULL,
    `program_code` VARCHAR(20) UNIQUE NOT NULL,
    `department_id` INT,
    FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`)
) ENGINE=InnoDB;

-- Time slots table
CREATE TABLE `time_slots` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `day` ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'),
    `start_time` TIME NOT NULL,
    `end_time` TIME NOT NULL,
    `slot_type` VARCHAR(20) DEFAULT 'regular',
    UNIQUE KEY `unique_timeslot` (`day`, `start_time`, `end_time`)
) ENGINE=InnoDB;

-- Program subjects junction
CREATE TABLE `program_subjects` (
  `program_id` INT NOT NULL,
  `subject_id` INT NOT NULL,
  PRIMARY KEY (`program_id`, `subject_id`),
  FOREIGN KEY (`program_id`) REFERENCES `programs`(`id`),
  FOREIGN KEY (`subject_id`) REFERENCES `subjects`(`id`)
) ENGINE=InnoDB;

-- Instructor availability
CREATE TABLE `instructor_availability` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `instructor_id` INT,
    `time_slot_id` INT,
    `is_available` BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (`instructor_id`) REFERENCES `instructors`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`time_slot_id`) REFERENCES `time_slots`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Specializations
CREATE TABLE `specializations` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `specialization_name` VARCHAR(100) UNIQUE NOT NULL,
    `description` TEXT
) ENGINE=InnoDB;

-- Instructor specializations junction
CREATE TABLE `instructor_specializations` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `instructor_id` INT NOT NULL,
    `specialization_id` INT NOT NULL,
    `priority` INT DEFAULT 1,
    UNIQUE KEY `unique_instructor_spec` (`instructor_id`, `specialization_id`),
    FOREIGN KEY (`instructor_id`) REFERENCES `instructors`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`specialization_id`) REFERENCES `specializations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Program chairs
CREATE TABLE `program_chairs` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `user_id` INT UNIQUE,
    `program_id` INT NOT NULL,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`program_id`) REFERENCES `programs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Schedule generation jobs
CREATE TABLE `schedule_jobs` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `job_name` VARCHAR(100),
    `status` ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `completed_at` TIMESTAMP NULL,
    `created_by` INT,
    `input_data` JSON,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB;

-- Generated schedules
CREATE TABLE `schedules` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `job_id` INT,
    `subject_id` INT,
    `instructor_id` INT,
    `room_id` INT,
    `time_slot_id` INT,
    `department` VARCHAR(100),
    `year_level` INT,
    `section` VARCHAR(10),
    `is_published` BOOLEAN DEFAULT FALSE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`job_id`) REFERENCES `schedule_jobs`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`subject_id`) REFERENCES `subjects`(`id`),
    FOREIGN KEY (`instructor_id`) REFERENCES `instructors`(`id`),
    FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`),
    FOREIGN KEY (`time_slot_id`) REFERENCES `time_slots`(`id`)
) ENGINE=InnoDB;

-- Insert default admin user (password: admin123)
INSERT INTO `users` (`username`, `password`, `email`, `role`, `full_name`) VALUES 
('admin', '$2y$10$J9gJpGCWsVv7Q7QgPrD3B.ACeAbeH9YyzAQc3rg30cyJnRbn9Kjhm', 'admin@school.edu', 'admin', 'System Administrator');

-- Insert demo program chair (password: chair123)
INSERT INTO `users` (`username`, `password`, `email`, `role`, `full_name`) VALUES 
('chair', '$2y$10$8K1p/a0dL3LXMIgoEDRwu.Z6G6U0XqNQ5YM8dK5L6X5QYJX8YJD.e', 'chair@school.edu', 'program_chair', 'Program Chair');

-- Insert sample departments
INSERT INTO `departments` (`dept_name`, `dept_code`) VALUES 
('Computer Science', 'CS'),
('Information Technology', 'IT'),
('Mathematics', 'MATH');

-- Insert sample programs
INSERT INTO `programs` (`program_name`, `program_code`, `department_id`) VALUES 
('BS Computer Science', 'BSCS', 1),
('BS Information Technology', 'BSIT', 2);

-- Link program chair to BSCS
INSERT INTO `program_chairs` (`user_id`, `program_id`) VALUES 
((SELECT `id` FROM `users` WHERE `username` = 'chair'), 1);

-- Insert sample time slots (7:00 AM - 5:30 PM, weekdays + some Saturdays)
INSERT INTO `time_slots` (`day`, `start_time`, `end_time`, `slot_type`) VALUES
('Monday', '07:00:00', '08:30:00', 'regular'),
('Monday', '08:30:00', '10:00:00', 'regular'),
('Monday', '10:00:00', '11:30:00', 'regular'),
('Monday', '11:30:00', '13:00:00', 'regular'),
('Monday', '13:00:00', '14:30:00', 'regular'),
('Monday', '14:30:00', '16:00:00', 'regular'),
('Monday', '16:00:00', '17:30:00', 'regular'),
('Tuesday', '07:00:00', '08:30:00', 'regular'),
('Tuesday', '08:30:00', '10:00:00', 'regular'),
('Tuesday', '10:00:00', '11:30:00', 'regular'),
('Tuesday', '11:30:00', '13:00:00', 'regular'),
('Tuesday', '13:00:00', '14:30:00', 'regular'),
('Tuesday', '14:30:00', '16:00:00', 'regular'),
('Tuesday', '16:00:00', '17:30:00', 'regular'),
('Wednesday', '07:00:00', '08:30:00', 'regular'),
('Wednesday', '08:30:00', '10:00:00', 'regular'),
('Wednesday', '10:00:00', '11:30:00', 'regular'),
('Wednesday', '11:30:00', '13:00:00', 'regular'),
('Wednesday', '13:00:00', '14:30:00', 'regular'),
('Wednesday', '14:30:00', '16:00:00', 'regular'),
('Wednesday', '16:00:00', '17:30:00', 'regular'),
('Thursday', '07:00:00', '08:30:00', 'regular'),
('Thursday', '08:30:00', '10:00:00', 'regular'),
('Thursday', '10:00:00', '11:30:00', 'regular'),
('Thursday', '11:30:00', '13:00:00', 'regular'),
('Thursday', '13:00:00', '14:30:00', 'regular'),
('Thursday', '14:30:00', '16:00:00', 'regular'),
('Thursday', '16:00:00', '17:30:00', 'regular'),
('Friday', '07:00:00', '08:30:00', 'regular'),
('Friday', '08:30:00', '10:00:00', 'regular'),
('Friday', '10:00:00', '11:30:00', 'regular'),
('Friday', '11:30:00', '13:00:00', 'regular'),
('Friday', '13:00:00', '14:30:00', 'regular'),
('Friday', '14:30:00', '16:00:00', 'regular'),
('Friday', '16:00:00', '17:30:00', 'regular'),
('Saturday', '08:00:00', '09:30:00', 'makeup'),
('Saturday', '09:30:00', '11:00:00', 'makeup'),
('Saturday', '11:00:00', '12:30:00', 'makeup'),
('Saturday', '13:30:00', '15:00:00', 'summer'),
('Saturday', '15:00:00', '16:30:00', 'summer');

-- Insert ALL subjects from "subject and sem.sql" WITH full details (year/sem/prereq)
INSERT INTO `subjects` (`subject_code`, `subject_name`, `credits`, `department`, `hours_per_week`, `semester`, `year_level`, `prerequisites`, `lecture_hours`, `lab_hours`) VALUES
('MATH1', 'Advanced Algebra and Trigonometry', 3, 'CPE', 3.00, '1st Sem', 1, '', 3.00, 0.00),
('MATH2', 'Calculus 1', 3, 'CPE', 3.00, '1st Sem', 1, '', 3.00, 0.00),
('CHEM1', 'Chemistry for Engineers', 4, 'CPE', 6.00, '1st Sem', 1, '', 3.00, 3.00),
('CPE1', 'Computer Hardware Fundamentals', 1, 'CPE', 3.00, '1st Sem', 1, '', 0.00, 3.00),
('CPE2', 'Programming Logic and Design', 2, 'CPE', 6.00, '1st Sem', 1, '', 0.00, 6.00),
('GE-MMW', 'Mathematics in the Modern World', 3, 'CPE', 3.00, '1st Sem', 1, '', 3.00, 0.00),
('GE-STS', 'Science, Technology, and Society', 3, 'CPE', 3.00, '1st Sem', 1, '', 3.00, 0.00),
('GE-US', 'Understanding the Self', 3, 'CPE', 3.00, '1st Sem', 1, '', 3.00, 0.00),
('PATH-FIT1', 'Movement Competency Training', 2, 'CPE', 2.00, '1st Sem', 1, '', 2.00, 0.00),
('NSTP1', 'National Service Training Program 1', 3, 'CPE', 3.00, '1st Sem', 1, '', 3.00, 0.00),
('MATH3', 'Calculus 2', 3, 'CPE', 3.00, '2nd Sem', 1, 'Calculus 1', 3.00, 0.00),
('PHYS1', 'Physics for Engineers', 4, 'CPE', 6.00, '2nd Sem', 1, 'Calculus 1', 3.00, 3.00),
('CPE3', 'Computer Engineering as a Discipline', 1, 'CPE', 1.00, '2nd Sem', 1, 'CPE1', 1.00, 0.00),

('MATH2', 'Calculus 1', 3, 'CPE', 3),
('CHEM1', 'Chemistry for Engineers', 4, 'CPE', 6),
('CPE1', 'Computer Hardware Fundamentals', 1, 'CPE', 3),
('CPE2', 'Programming Logic and Design', 2, 'CPE', 6),
('GE-MMW', 'Mathematics in the Modern World', 3, 'CPE', 3),
('GE-STS', 'Science, Technology, and Society', 3, 'CPE', 3),
('GE-US', 'Understanding the Self', 3, 'CPE', 3),
('PATH-FIT1', 'Movement Competency Training', 2, 'CPE', 2),
('NSTP1', 'National Service Training Program 1', 3, 'CPE', 3),
('MATH3', 'Calculus 2', 3, 'CPE', 3),
('PHYS1', 'Physics for Engineers', 4, 'CPE', 6),
('CPE3', 'Computer Engineering as a Discipline', 1, 'CPE', 1),
('CPE4', 'Object Oriented Programming', 2, 'CPE', 6),
('MATH4', 'Engineering Data Analysis', 3, 'CPE', 3),
('CPE5', 'Discrete Mathematics', 3, 'CPE', 3),
('GE-RPH', 'Readings in Philippine History', 3, 'CPE', 3),
('PATH-FIT2', 'Exercise-based Fitness Activities', 2, 'CPE', 2),
('NSTP2', 'National Service Training Program 2', 3, 'CPE', 3),
('MATH5', 'Differential Equations', 3, 'CPE', 3),
('GE-AA', 'Art Appreciation', 3, 'CPE', 3),
('CPE6', 'Data Structures and Algorithms', 2, 'CPE', 6),
('ES1', 'Engineering Economics', 3, 'CPE', 3),
('EE1', 'Fundamentals of Electrical Circuits', 4, 'CPE', 6),
('GEC-EL1', 'Living in the IT Era', 3, 'CPE', 3),
('ES2', 'Computer-Aided Drafting', 1, 'CPE', 3),
('PATH-FIT3', 'Menu of Dance, Sports, Martial Arts, Group Exercise, Outdoor and Adventure Activities', 2, 'CPE', 2),
('CPE7', 'Numerical Methods', 3, 'CPE', 3),
('CPE8', 'Software Design', 4, 'CPE', 6),
('GE-PC', 'Purposive Communication', 3, 'CPE', 3),
('ECE1', 'Fundamentals of Electronic Circuits', 4, 'CPE', 6),
('RIZAL', 'Life and Works of Rizal', 3, 'CPE', 3),
('GE-CW', 'Contemporary World', 3, 'CPE', 3),
('GEC-EL2', 'People and the Earth`s Ecosystem', 3, 'CPE', 3),
('PATH-FIT4', 'Menu of Dance, Sports, Martial Arts, Group Exercise, Outdoor and Adventure Activities', 2, 'CPE', 2),
('CPE9', 'Logic Circuits and Design', 4, 'CPE', 6),
('CPE10', 'Operating Systems', 3, 'CPE', 3),
('CPE11', 'Data and Digital Communications', 3, 'CPE', 3),
('CPE12', 'Introduction to HDL', 1, 'CPE', 3),
('CPE13', 'Feedback and Control Systems', 3, 'CPE', 3),
('CPE14', 'Fundamentals of Mixed Signals and Sensors', 3, 'CPE', 3),
('CPE15', 'Computer Engineering Drafting and Design', 1, 'CPE', 3),
('CPE-ELEC1', 'CpE Elective 1', 3, 'CPE', 5),
('CPE16', 'Basic Occupational Health and Safety', 3, 'CPE', 3),
('CPE17', 'Computer Networks and Security', 4, 'CPE', 6),
('CPE18', 'Microprocessors', 4, 'CPE', 6),
('CPE19', 'Methods of Research', 3, 'CPE', 3),
('ES3', 'Technopreneurship', 3, 'CPE', 3),
('GE-E', 'Ethics', 3, 'CPE', 3),
('CPE20', 'CpE Laws and Professional Practice', 3, 'CPE', 3),
('CPE-ELEC2', 'CpE Elective 2', 3, 'CPE', 5),
('CPE21', 'On the Job Training', 3, 'CPE', 243),
('CPE22', 'Embedded Systems', 4, 'CPE', 6),
('CPE23', 'Computer Architecture and Organization', 4, 'CPE', 6),
('CPE24', 'Emerging Technologies in CpE', 3, 'CPE', 3),
('CPE25', 'CpE Practice and Design 1', 1, 'CPE', 3),
('CPE26', 'Digital Signal Processing', 4, 'CPE', 6),
('CPE-ELEC3', 'CpE Elective 3', 3, 'CPE', 5),
('CPE27', 'CpE Practice and Design 2', 2, 'CPE', 6),
('CPE28', 'Seminars and Fieldtrips', 1, 'CPE', 3),
('GEC-EL3', 'Gender and Society', 0, 'CPE', 3),
('CS 111', 'Introduction to Computing', 3, 'CS', 5),
('CS 112', 'Fundamentals of Programming', 3, 'CS', 5),
('CS 121', 'Discrete Structure 1', 3, 'CS', 6),
('CS 122', 'Discrete Structure 1', 4, 'CS', 5),
('CS 123', 'Multimedia Systems and Technology', 3, 'CS', 2),
('GE-BC', 'Business Correspondence', 3, 'CS', 3),
('CS 211', 'Discrete Structure 2', 3, 'CS', 3),
('CS 212', 'Object-Oriented Programming', 3, 'CS', 5),
('CS 213', 'Data Structures and Algorithms', 3, 'CS', 5),
('CS 214', 'Embedded Systems', 3, 'CS', 5),
('GE - Stat', 'Descriptive and Inferential Statistics', 3, 'CS', 3),
('Entrep 1', 'The Entrepreneurial Mind', 3, 'CS', 3),
('CS 221', 'Algorith and Complexity', 3, 'CS', 5),
('CS 222', 'Information Management', 3, 'CS', 5),
('CS 223', 'Web System and Technologies 1', 3, 'CS', 5),
('CS 224', 'Computational Science', 3, 'CS', 3),
('Ecos 1', 'People and the Earths Ecosystem', 3, 'CS', 3),
('CS 311', 'Automata Theory and Formal Languages', 3, 'CS', 3),
('CS 312', 'Architecture and Organization', 3, 'CS', 3),
('CS 313', 'Information Assurance and Security', 3, 'CS', 3),
('CS 314', 'CS Elective 1', 3, 'CS', 5),
('CS 315', 'Appication Devt & Emerging Technologies', 3, 'CS', 5),
('CS 316', 'Web Systems and Technologies 2', 3, 'CS', 5),
('CS 321', 'Programming Languages', 3, 'CS', 5),
('CS 322', 'Software Engineering 1', 3, 'CS', 5),
('CS 323', 'Social Issues and Professional Practice', 3, 'CS', 3),
('CS 324', 'CS Elective 2', 3, 'CS', 5),
('CS 325', 'Mobile Computing', 3, 'CS', 5),
('CS 326', 'Modeling and Simulation', 3, 'CS', 5),
('CS 327', 'Data Mining Concepts and Techniques', 3, 'CS', 5),
('CS 328', 'Machine Learning', 3, 'CS', 5),
('CS 411', 'Human Computer Interaction', 3, 'CS', 5),
('CS 412', 'Operating Systems', 3, 'CS', 5),
('CS 413', 'Software Engineering 2', 3, 'CS', 5),
('CS 414', 'CS Thesis Writing 1', 3, 'CS', 5),
('CS 415', 'CS Elective 3', 3, 'CS', 5),
('CS 416', 'Mobile Computing 2', 3, 'CS', 5),
('CS 421', 'Networks and Communication', 3, 'CS', 5),
('CS 422', 'CS Thesis Writing 2', 3, 'CS', 5),
('IT111', 'Introduction to Computing', 3, 'IT', 5),
('IT112', 'Fundamentals of Programming', 3, 'IT', 5),
('IT121', 'Intermediate Programming', 3, 'IT', 5),
('IT122', 'Introduction to Human Computer Interaction', 3, 'IT', 5),
('IT123', 'Discrete Mathematics', 3, 'IT', 3),
('IT211', 'Data Structures and Algorithm', 3, 'IT', 5),
('IT212', 'Object Oriented Programming', 3, 'IT', 5),
('IT213', 'IT Elective 1', 3, 'IT', 5),
('IT214', 'Hardware, Software and Peripherals', 3, 'IT', 5),
('IT215', 'Internet of Things (IoT)', 3, 'IT', 5),
('ENTREP1', 'The Entrepreneurial Mind', 3, 'IT', 3),
('IT221', 'Qualitative Methods (Including Modeling & Simulation)', 3, 'IT', 3),
('IT222', 'Networking 1', 3, 'IT', 5),
('IT223', 'Integrative programming & Technologies 1', 3, 'IT', 5),
('IT224', 'Information Management', 3, 'IT', 6),
('IT225', 'IT Elective 2', 3, 'IT', 5),
('IT311', 'Advanced Database System', 3, 'IT', 5),
('IT312', 'Networking 2', 3, 'IT', 5),
('IT313', 'System Integration & Architecture 1', 3, 'IT', 5),
('IT314', 'IT Elective 3', 3, 'IT', 5),
('IT315', 'System Analysis & Design', 3, 'IT', 5),
('IT316', 'Integrative Programming and Technologies 2', 3, 'IT', 5),
('IT321', 'Information Assurance and Security 1', 3, 'IT', 5),
('IT322', 'Social Professional Issues', 3, 'IT', 3),
('IT323', 'Application Development and Emerging Technologies', 3, 'IT', 5),
('IT324', 'Capstone Project and Research 1', 3, 'IT', 5),
('IT325', 'Visual Graphics Design', 3, 'IT', 5),
('IT326', 'Web Systems and Technologies 1', 3, 'IT', 5),
('IT411', 'Capstone Project and Research 2', 3, 'IT', 5),
('IT412', 'System Administration and Maintenance', 3, 'IT', 5),
('IT413', 'Information Assurance and Security 2', 3, 'IT', 5),
('IT414', 'Web Systems and Technologies 2', 3, 'IT', 5),
('IT415', 'IT Elective 4', 3, 'IT', 5),
('IT416', 'Animation 2D/3D', 3, 'IT', 5),
('IT421', 'Practicum/On Job Training (486 hours)', 9, 'IT', 27);


-- Insert sample rooms
INSERT INTO `rooms` (`room_number`, `capacity`, `building`, `has_projector`, `has_computers`) VALUES
('CS-101', 40, 'CS Building', TRUE, TRUE),
('CS-102', 35, 'CS Building', TRUE, FALSE),
('IT-201', 45, 'IT Building', TRUE, TRUE),
('Lecture Hall A', 100, 'Main Building', TRUE, FALSE);

PRINT 'Database recreated successfully! Login: admin/admin123 or chair/chair123';
