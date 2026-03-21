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

-- Departments table
CREATE TABLE `departments` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `dept_name` VARCHAR(100) UNIQUE NOT NULL,
    `dept_code` VARCHAR(20) UNIQUE NOT NULL
) ENGINE=InnoDB;

-- Programs table
CREATE TABLE `programs` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `program_name` VARCHAR(100) UNIQUE NOT NULL,
    `program_code` VARCHAR(20) UNIQUE NOT NULL,
    `department_id` INT,
    FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`)
) ENGINE=InnoDB;

-- Subjects table (NO FK to avoid error)
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
    `lab_hours` DECIMAL(4,2) NOT NULL DEFAULT 0.00
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

-- Time slots table
CREATE TABLE `time_slots` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `day` ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'),
    `start_time` TIME NOT NULL,
    `end_time` TIME NOT NULL,
    `slot_type` VARCHAR(20) DEFAULT 'regular',
    UNIQUE KEY `unique_timeslot` (`day`, `start_time`, `end_time`)
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

-- Program chairs
CREATE TABLE `program_chairs` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `user_id` INT UNIQUE,
    `program_id` INT NOT NULL,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`program_id`) REFERENCES `programs`(`id`) ON DELETE CASCADE
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

-- Schedule jobs
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

-- Schedules
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

-- Insert departments
INSERT INTO `departments` (`dept_name`, `dept_code`) VALUES 
('CPE', 'CPE'),
('CS', 'CS'),
('IT', 'IT');

-- Insert programs
INSERT INTO `programs` (`program_name`, `program_code`, `department_id`) VALUES 
('BS Computer Engineering', 'BSCpE', 1),
('BS Computer Science', 'BSCS', 2),
('BS Information Technology', 'BSIT', 3);

-- Insert default admin
INSERT INTO `users` (`username`, `password`, `email`, `role`, `full_name`) VALUES 
('admin', '$2y$10$J9gJpGCWsVv7Q7QgPrD3B.ACeAbeH9YyzAQc3rg30cyJnRbn9Kjhm', 'admin@school.edu', 'admin', 'System Administrator');

-- Insert program chair
INSERT INTO `users` (`username`, `password`, `email`, `role`, `full_name`) VALUES 
('chair', '$2y$10$8K1p/a0dL3LXMIgoEDRwu.Z6G6U0XqNQ5YM8dK5L6X5QYJX8YJD.e', 'chair@school.edu', 'program_chair', 'Program Chair');

INSERT INTO `program_chairs` (`user_id`, `program_id`) VALUES 
((SELECT `id` FROM `users` WHERE `username` = 'chair'), 1);

-- Insert ALL subjects from "subject and sem.sql"
INSERT INTO `subjects` (`subject_code`, `subject_name`, `credits`, `department`, `hours_per_week`, `semester`, `year_level`, `prerequisites`, `lecture_hours`, `lab_hours`) VALUES
('MATH1', 'Advanced Algebra and Trigonometry', 3, 'CPE', 3.00, '1st Semester', 1, '', 3.00, 0.00),
('MATH2', 'Calculus 1', 3, 'CPE', 3.00, '1st Semester', 1, '', 3.00, 0.00),
('CHEM1', 'Chemistry for Engineers', 4, 'CPE', 6.00, '1st Semester', 1, '', 3.00, 3.00),
('CPE1', 'Computer Hardware Fundamentals', 1, 'CPE', 3.00, '1st Semester', 1, '', 0.00, 3.00),
('CPE2', 'Programming Logic and Design', 2, 'CPE', 6.00, '1st Semester', 1, '', 0.00, 6.00),
('GE-MMW', 'Mathematics in the Modern World', 3, 'CPE', 3.00, '1st Semester', 1, '', 3.00, 0.00),
('GE-STS', 'Science, Technology, and Society', 3, 'CPE', 3.00, '1st Semester', 1, '', 3.00, 0.00),
('GE-US', 'Understanding the Self', 3, 'CPE', 3.00, '1st Semester', 1, '', 3.00, 0.00),
('PATH-FIT1', 'Movement Competency Training', 2, 'CPE', 2.00, '1st Semester', 1, '', 2.00, 0.00),
('NSTP1', 'National Service Training Program 1', 3, 'CPE', 3.00, '1st Semester', 1, '', 3.00, 0.00),
('MATH3', 'Calculus 2', 3, 'CPE', 3.00, '2nd Semester', 1, 'Calculus 1', 3.00, 0.00),
('PHYS1', 'Physics for Engineers', 4, 'CPE', 6.00, '2nd Semester', 1, 'Calculus 1', 3.00, 3.00),
('CPE3', 'Computer Engineering as a Discipline', 1, 'CPE', 1.00, '2nd Semester', 1, 'CPE1', 1.00, 0.00),
('CPE4', 'Object Oriented Programming', 2, 'CPE', 6.00, '2nd Semester', 1, 'CPE2', 0.00, 6.00),
('MATH4', 'Engineering Data Analysis', 3, 'CPE', 3.00, '2nd Semester', 1, 'Calculus 1', 3.00, 0.00),
('CPE5', 'Discrete Mathematics', 3, 'CPE', 3.00, '2nd Semester', 1, 'Calculus 1', 3.00, 0.00),
('GE-RPH', 'Readings in Philippine History', 3, 'CPE', 3.00, '2nd Semester', 1, '', 3.00, 0.00),
('PATH-FIT2', 'Exercise-based Fitness Activities', 2, 'CPE', 2.00, '2nd Semester', 1, 'PATH-FIT1', 2.00, 0.00),
('NSTP2', 'National Service Training Program 2', 3, 'CPE', 3.00, '2nd Semester', 1, 'NSTP1', 3.00, 0.00),
('MATH5', 'Differential Equations', 3, 'CPE', 3.00, '1st Semester', 2, 'Calculus 2', 3.00, 0.00),
('GE-AA', 'Art Appreciation', 3, 'CPE', 3.00, '1st Semester', 2, '', 3.00, 0.00),
('CPE6', 'Data Structures and Algorithms', 2, 'CPE', 6.00, '1st Semester', 2, 'Object-Oriented Programming', 0.00, 6.00),
('ES1', 'Engineering Economics', 3, 'CPE', 3.00, '1st Semester', 2, '2nd year Standing', 3.00, 0.00),
('EE1', 'Fundamentals of Electrical Circuits', 4, 'CPE', 6.00, '1st Semester', 2, 'Physics for Engineering', 3.00, 3.00),
('GEC-EL1', 'Living in the IT Era', 3, 'CPE', 3.00, '1st Semester', 2, '', 3.00, 0.00),
('ES2', 'Computer-Aided Drafting', 1, 'CPE', 3.00, '1st Semester', 2, '2nd year Standing', 0.00, 3.00),
('PATH-FIT3', 'Menu of Dance, Sports, Martial Arts, Group Exercise, Outdoor and Adventure Activities', 2, 'CPE', 2.00, '1st Semester', 2, 'PATH-FIT2', 2.00, 0.00),
('CPE7', 'Numerical Methods', 3, 'CPE', 3.00, '2nd Semester', 2, 'Differential Equation; Object Oriented Programming', 3.00, 0.00),
('CPE8', 'Software Design', 4, 'CPE', 6.00, '2nd Semester', 2, 'Data Structures and Algorithms', 3.00, 3.00),
('GE-PC', 'Purposive Communication', 3, 'CPE', 3.00, '2nd Semester', 2, '', 3.00, 0.00),
('ECE1', 'Fundamentals of Electronic Circuits', 4, 'CPE', 6.00, '2nd Semester', 2, 'Fundamentals of Electrical Circuits', 3.00, 3.00),
('RIZAL', 'Life and Works of Rizal', 3, 'CPE', 3.00, '2nd Semester', 2, '', 3.00, 0.00),
('GE-CW', 'Contemporary World', 3, 'CPE', 3.00, '2nd Semester', 2, '', 3.00, 0.00),
('GEC-EL2', 'People and the Earth`s Ecosystem', 3, 'CPE', 3.00, '2nd Semester', 2, 'GEC-EL1', 3.00, 0.00),
('PATH-FIT4', 'Menu of Dance, Sports, Martial Arts, Group Exercise, Outdoor and Adventure Activities', 2, 'CPE', 2.00, '2nd Semester', 2, '', 2.00, 0.00),
('CPE9', 'Logic Circuits and Design', 4, 'CPE', 6.00, '1st Semester', 3, 'Fundamentals of Electronic Circuits', 3.00, 3.00),
('CPE10', 'Operating Systems', 3, 'CPE', 3.00, '1st Semester', 3, 'Data Structures and Algorithms', 3.00, 0.00),
('CPE11', 'Data and Digital Communications', 3, 'CPE', 3.00, '1st Semester', 3, 'Fundamentals of Electronic Circuits', 3.00, 0.00),
('CPE12', 'Introduction to HDL', 1, 'CPE', 3.00, '1st Semester', 3, 'Programming Logic and Design; Fundamentals of Electronic Circuits', 0.00, 3.00),
('CPE13', 'Feedback and Control Systems', 3, 'CPE', 3.00, '1st Semester', 3, 'Numerical Methods; Fundamentals of Electronic Circuits', 3.00, 0.00),
('CPE14', 'Fundamentals of Mixed Signals and Sensors', 3, 'CPE', 3.00, '1st Semester', 3, 'Fundamentals of Electronic Circuits', 3.00, 0.00),
('CPE15', 'Computer Engineering Drafting and Design', 1, 'CPE', 3.00, '1st Semester', 3, 'Fundamentals of Electronic Circuits', 0.00, 3.00),
('CPE-ELEC1', 'CpE Elective 1', 3, 'CPE', 5.00, '1st Semester', 3, '3rd Year Standing', 2.00, 3.00),
('CPE16', 'Basic Occupational Health and Safety', 3, 'CPE', 3.00, '2nd Sem', 3, '3rd Year Standing', 3.00, 0.00),
('CPE17', 'Computer Networks and Security', 4, 'CPE', 6.00, '2nd Sem', 3, 'Data and Digital Communication', 3.00, 3.00),
('CPE18', 'Microprocessors', 4, 'CPE', 6.00, '2nd Sem', 3, 'Logic Circuits and Design', 3.00, 3.00),
('CPE19', 'Methods of Research', 3, 'CPE', 3.00, '2nd Sem', 3, 'Engineering Data Analysis; Purposive Communication; Logic Circuits and Design', 3.00, 0.00),
('ES3', 'Technopreneurship', 3, 'CPE', 3.00, '2nd Sem', 3, '3rd Year Standing', 3.00, 0.00),
('GE-E', 'Ethics', 3, 'CPE', 3.00, '2nd Sem', 3, '3rd Year Standing', 3.00, 0.00),
('CPE20', 'CpE Laws and Professional Practice', 3, 'CPE', 3.00, '2nd Sem', 3, '3rd Year Standing', 3.00, 0.00),
('CPE-ELEC2', 'CpE Elective 2', 3, 'CPE', 5.00, '2nd Sem', 3, 'CPE Elective 1', 2.00, 3.00),
('CPE21', 'On the Job Training', 3, 'CPE', 243.00, 'Summer', 3, 'Only Regular Graduating Students can enroll in this course', 3.00, 240.00),
('CPE22', 'Embedded Systems', 4, 'CPE', 6.00, '1st Sem', 4, 'Microprocessors', 3.00, 3.00),
('CPE23', 'Computer Architecture and Organization', 4, 'CPE', 6.00, '1st Sem', 4, 'Microprocessors', 3.00, 3.00),
('CPE24', 'Emerging Technologies in CpE', 3, 'CPE', 3.00, '1st Sem', 4, '4th Year Standing', 3.00, 0.00),
('CPE25', 'CpE Practice and Design 1', 1, 'CPE', 3.00, '1st Sem', 4, 'Microprocessors; Methods of Research', 0.00, 3.00),
('CPE26', 'Digital Signal Processing', 4, 'CPE', 6.00, '1st Sem', 4, 'Feedback and Control Systems', 3.00, 3.00),
('CPE-ELEC3', 'CpE Elective 3', 3, 'CPE', 5.00, '1st Sem', 4, 'CPE Elective 2', 2.00, 3.00),
('CPE27', 'CpE Practice and Design 2', 2, 'CPE', 6.00, '2nd Sem', 4, 'CpE Practice and Design 1', 0.00, 6.00),
('CPE28', 'Seminars and Fieldtrips', 1, 'CPE', 3.00, '2nd Sem', 4, '4th Year Standing', 0.00, 3.00),
('GEC-EL3', 'Gender and Society', 0, 'CPE', 3.00, '2nd Sem', 4, 'GEC Elective 2', 3.00, 0.00),
('CS 111', 'Introduction to Computing', 3, 'CS', 5.00, '1st Sem', 1, '', 2.00, 3.00),
('CS 112', 'Fundamentals of Programming', 3, 'CS', 5.00, '1st Sem', 1, '', 2.00, 3.00),
('CS 121', 'Discrete Structure 1', 3, 'CS', 6.00, '2nd Sem', 1, 'MATH 1', 3.00, 3.00),
('CS 122', 'Discrete Structure 1', 4, 'CS', 5.00, '2nd Sem', 1, 'CS 112', 2.00, 3.00),
('CS 123', 'Multimedia Systems and Technology', 3, 'CS', 2.00, '2nd Sem', 1, '', 2.00, 0.00),
('GE-BC', 'Business Correspondence', 3, 'CS', 3.00, '2nd Sem', 1, '', 3.00, 0.00),
('CS 211', 'Discrete Structure 2', 3, 'CS', 3.00, '1st Sem', 2, 'CS 121', 3.00, 0.00),
('CS 212', 'Object-Oriented Programming', 3, 'CS', 5.00, '1st Sem', 2, 'CS 122', 2.00, 3.00),
('CS 213', 'Data Structures and Algorithms', 3, 'CS', 5.00, '1st Sem', 2, 'CS 122', 2.00, 3.00),
('CS 214', 'Embedded Systems', 3, 'CS', 5.00, '1st Sem', 2, 'CS 122', 2.00, 3.00),
('GE - Stat', 'Descriptive and Inferential Statistics', 3, 'CS', 3.00, '1st Sem', 2, '', 3.00, 0.00),
('Entrep 1', 'The Entrepreneurial Mind', 3, 'CS', 3.00, '1st Sem', 2, '', 3.00, 0.00),
('CS 221', 'Algorith and Complexity', 3, 'CS', 5.00, '2nd Sem', 2, 'CS 213', 2.00, 3.00),
('CS 222', 'Information Management', 3, 'CS', 5.00, '2nd Sem', 2, 'CS 212', 2.00, 3.00),
('CS 223', 'Web System and Technologies 1', 3, 'CS', 5.00, '2nd Sem', 2, 'CS 212', 2.00, 3.00),
('CS 224', 'Computational Science', 3, 'CS', 3.00, '2nd Sem', 2, 'CS 211', 3.00, 0.00),
('Ecos 1', 'People and the Earths Ecosystem', 3, 'CS', 3.00, '2nd Sem', 2, '', 3.00, 0.00),
('CS 311', 'Automata Theory and Formal Languages', 3, 'CS', 3.00, '1st Sem', 3, 'CS 221', 3.00, 0.00),
('CS 312', 'Architecture and Organization', 3, 'CS', 3.00, '1st Sem', 3, 'CS 213', 3.00, 0.00),
('CS 313', 'Information Assurance and Security', 3, 'CS', 3.00, '1st Sem', 3, 'CS 222', 3.00, 0.00),
('CS 314', 'CS Elective 1', 3, 'CS', 5.00, '1st Sem', 3, 'CS 222', 2.00, 3.00),
('CS 315', 'Appication Devt & Emerging Technologies', 3, 'CS', 5.00, '1st Sem', 3, 'CS 222', 2.00, 3.00),
('CS 316', 'Web Systems and Technologies 2', 3, 'CS', 5.00, '1st Sem', 3, 'CS 223', 2.00, 3.00),
('CS 321', 'Programming Languages', 3, 'CS', 5.00, '2nd Sem', 3, 'CS 213', 2.00, 3.00),
('CS 322', 'Software Engineering 1', 3, 'CS', 5.00, '2nd Sem', 3, 'CS 212 & CS 222', 2.00, 3.00),
('CS 323', 'Social Issues and Professional Practice', 3, 'CS', 3.00, '2nd Sem', 3, 'CS 313', 3.00, 0.00),
('CS 324', 'CS Elective 2', 3, 'CS', 5.00, '2nd Sem', 3, 'CS 316', 2.00, 3.00),
('CS 325', 'Mobile Computing', 3, 'CS', 5.00, '2nd Sem', 3, 'CS 315', 2.00, 3.00),
('CS 326', 'Modeling and Simulation', 3, 'CS', 5.00, '2nd Sem', 3, 'CS 314', 2.00, 3.00),
('CS 327', 'Data Mining Concepts and Techniques', 3, 'CS', 5.00, '2nd Sem', 3, 'GE-STAT', 2.00, 3.00),
('CS 328', 'Machine Learning', 3, 'CS', 5.00, '2nd Sem', 3, 'CS 122 & GE-STAT', 2.00, 3.00),
('CS 411', 'Human Computer Interaction', 3, 'CS', 5.00, '1st Sem', 4, 'CS 323', 2.00, 3.00),
('CS 412', 'Operating Systems', 3, 'CS', 5.00, '1st Sem', 4, 'CS 321', 2.00, 3.00),
('CS 413', 'Software Engineering 2', 3, 'CS', 5.00, '1st Sem', 4, 'CS 322', 2.00, 3.00),
('CS 414', 'CS Thesis Writing 1', 3, 'CS', 5.00, '1st Sem', 4, 'Regular 4th yr.', 2.00, 3.00),
('CS 415', 'CS Elective 3', 3, 'CS', 5.00, '1st Sem', 4, 'CS 328', 2.00, 3.00),
('CS 416', 'Mobile Computing 2', 3, 'CS', 5.00, '1st Sem', 4, 'CS 325', 2.00, 3.00),
('CS 421', 'Networks and Communication', 3, 'CS', 5.00, '2nd Sem', 4, 'CS 412', 2.00, 3.00),
('CS 422', 'CS Thesis Writing 2', 3, 'CS', 5.00, '2nd Sem', 4, 'CS 414', 2.00, 3.00),
('IT111', 'Introduction to Computing', 3, 'IT', 5.00, '1st Sem', 1, '', 2.00, 3.00),
('IT112', 'Fundamentals of Programming', 3, 'IT', 5.00, '1st Sem', 1, '', 2.00, 3.00),
('IT121', 'Intermediate Programming', 3, 'IT', 5.00, '2nd Sem', 1, 'IT112', 2.00, 3.00),
('IT122', 'Introduction to Human Computer Interaction', 3, 'IT', 5.00, '2nd Sem', 1, 'IT112', 2.00, 3.00),
('IT123', 'Discrete Mathematics', 3, 'IT', 3.00, '2nd Sem', 1, 'IT112', 3.00, 0.00),
('IT211', 'Data Structures and Algorithm', 3, 'IT', 5.00, '1st Sem', 2, 'IT121', 2.00, 3.00),
('IT212', 'Object Oriented Programming', 3, 'IT', 5.00, '1st Sem', 2, 'IT121', 2.00, 3.00),
('IT213', 'IT Elective 1', 3, 'IT', 5.00, '1st Sem', 2, '', 2.00, 3.00),
('IT214', 'Hardware, Software and Peripherals', 3, 'IT', 5.00, '1st Sem', 2, 'IT122', 2.00, 3.00),
('IT215', 'Internet of Things (IoT)', 3, 'IT', 5.00, '1st Sem', 2, 'IT121', 2.00, 3.00),
('ENTREP1', 'The Entrepreneurial Mind', 3, 'IT', 3.00, '1st Sem', 2, '', 3.00, 0.00),
('IT221', 'Qualitative Methods (Including Modeling & Simulation)', 3, 'IT', 3.00, '2nd Sem', 2, 'IT123', 3.00, 0.00),
('IT222', 'Networking 1', 3, 'IT', 5.00, '2nd Sem', 2, '', 2.00, 3.00),
('IT223', 'Integrative programming & Technologies 1', 3, 'IT', 5.00, '2nd Sem', 2, 'IT212', 2.00, 3.00),
('IT224', 'Information Management', 3, 'IT', 6.00, '2nd Sem', 2, 'IT212', 3.00, 3.00),
('IT225', 'IT Elective 2', 3, 'IT', 5.00, '2nd Sem', 2, 'IT112', 2.00, 3.00),
('IT311', 'Advanced Database System', 3, 'IT', 5.00, '1st Sem', 3, 'IT224', 2.00, 3.00),
('IT312', 'Networking 2', 3, 'IT', 5.00, '1st Sem', 3, 'IT222', 2.00, 3.00),
('IT313', 'System Integration & Architecture 1', 3, 'IT', 5.00, '1st Sem', 3, 'IT223', 2.00, 3.00),
('IT314', 'IT Elective 3', 3, 'IT', 5.00, '1st Sem', 3, 'IT223', 2.00, 3.00),
('IT315', 'System Analysis & Design', 3, 'IT', 5.00, '1st Sem', 3, 'IT224', 2.00, 3.00),
('IT316', 'Integrative Programming and Technologies 2', 3, 'IT', 5.00, '1st Sem', 3, 'IT225', 2.00, 3.00),
('IT321', 'Information Assurance and Security 1', 3, 'IT', 5.00, '2nd Sem', 3, 'IT312', 2.00, 3.00),
('IT322', 'Social Professional Issues', 3, 'IT', 3.00, '2nd Sem', 3, '3rd YR. Standing', 3.00, 0.00),
('IT323', 'Application Development and Emerging Technologies', 3, 'IT', 5.00, '2nd Sem', 3, 'IT313', 2.00, 3.00),
('IT324', 'Capstone Project and Research 1', 3, 'IT', 5.00, '2nd Sem', 3, 'IT315', 2.00, 3.00),
('IT325', 'Visual Graphics Design', 3, 'IT', 5.00, '2nd Sem', 3, 'IT314', 2.00, 3.00),
('IT326', 'Web Systems and Technologies 1', 3, 'IT', 5.00, '2nd Sem', 3, 'IT311', 2.00, 3.00),
('IT411', 'Capstone Project and Research 2', 3, 'IT', 5.00, '1st Sem', 4, 'IT324', 2.00, 3.00),
('IT412', 'System Administration and Maintenance', 3, 'IT', 5.00, '1st Sem', 4, 'IT321', 2.00, 3.00),
('IT413', 'Information Assurance and Security 2', 3, 'IT', 5.00, '1st Sem', 4, 'IT321', 2.00, 3.00),
('IT414', 'Web Systems and Technologies 2', 3, 'IT', 5.00, '1st Sem', 4, 'IT326', 2.00, 3.00),
('IT415', 'IT Elective 4', 3, 'IT', 5.00, '1st Sem', 4, 'IT313', 2.00, 3.00),
('IT416', 'Animation 2D/3D', 3, 'IT', 5.00, '1st Sem', 4, 'IT316', 2.00, 3.00),
('IT421', 'Practicum/On Job Training (486 hours)', 9, 'IT', 27.00, '2nd Sem', 4, 'Completed Academic Requirements', 0.00, 27.00);

-- Insert sample rooms
INSERT INTO `rooms` (`room_number`, `capacity`, `building`, `has_projector`, `has_computers`) VALUES
('CS-101', 40, 'CS Building', TRUE, TRUE),
('CS-102', 35, 'CS Building', TRUE, FALSE),
('IT-201', 45, 'IT Building', TRUE, TRUE),
('Lecture Hall A', 100, 'Main Building', TRUE, FALSE);

-- Database recreated successfully! Login: admin/admin123 or chair/chair123
