-- Migration: Add sample instructors from user data (PERMANENT/CONTRACTUAL)
-- Run AFTER main DB import if needed
USE `academic_scheduling`;

-- Sample usernames/passwords: change as needed (passwords: 'instructor123')
INSERT INTO `users` (username, password, email, role, full_name) VALUES
('plaza_nelyne', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'plaza@example.com', 'instructor', 'NELYNE LOURDES Y. PLAZA'),
('arimang_nancy', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'arimang@example.com', 'instructor', 'NANCY MARIE M. ARIMANG'),
('tayo_cheryl', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'tayo@example.com', 'instructor', 'CHERYL O. TAYO'),
('etchon_fae', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'etchon@example.com', 'instructor', 'FAE MYLENE M. ETCHON'),
('julve_jaypee', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'julve@example.com', 'instructor', 'JAYPEE B. JULVE'),
('ganancias_franklin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'ganancias@example.com', 'instructor', 'FRANKLIN M. GANANCIAS'),
('rubenial_jehu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'rubenial@example.com', 'instructor', 'JEHU ROEH C. RUBENIAL'),
('azarcon_sharon', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'azarcon@example.com', 'instructor', 'SHARON G. AZARCON'),
('gracia_joel', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'gracia@example.com', 'instructor', 'JOEL S. GRACIA'),
('rosas_lyndon', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'rosas@example.com', 'instructor', 'LYNDON A. ROSAS'),
('basadre_josephine', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'basadre@example.com', 'instructor', 'JOSEPHINE A. BASADRE'),
('sering_jemimah', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'sering@example.com', 'instructor', 'JEMIMAH MAE C. SERING'),
('orozco_jennifer', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'orozco@example.com', 'instructor', 'JENNIFER L. OROZCO'),
('cantila_brieg', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'cantila@example.com', 'instructor', 'BRIEG DINESE A. CANTILA'),
('arnaldo_mark', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'arnaldo@example.com', 'instructor', 'MARK VINCENT ARNALDO'),
('arreza_michol', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'arreza@example.com', 'instructor', 'MICHOL ANTHONY U. ARREZA'),
('tiu_clyde', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'tiu@example.com', 'instructor', 'CLYDE CHECTOPHER A. TIU'),
('luad_marnie', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'luad@example.com', 'instructor', 'MARNIE ROSE P. LUAD'),
('gomez_merinel', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'gomez@example.com', 'instructor', 'MERINELLE A. GOMEZ'),
('fallado_pearl', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'fallado@example.com', 'instructor', 'PEARL AJ G. FALLADO'),
('obatay_roy', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'obatay@example.com', 'instructor', 'ROY D. OBATAY'),
('flores_iza', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'flores@example.com', 'instructor', 'IZA C. FLORES'),
('duero_dione', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'duero@example.com', 'instructor', 'DIONE S. DUERO');

-- Link to instructors table (status, max_hours, designation_units, research_extension_units, rank)
INSERT INTO `instructors` (user_id, department, status, max_hours_per_week, designation_units, research_extension_units, rank) VALUES
((SELECT id FROM users WHERE username = 'plaza_nelyne'), 'CPE', 'Permanent', 24, 6, 0, 'Associate Prof. IV'),
((SELECT id FROM users WHERE username = 'arimang_nancy'), 'CPE', 'Permanent', 24, 3, 3, 'Assistant Prof. IV'),
((SELECT id FROM users WHERE username = 'tayo_cheryl'), 'CPE', 'Permanent', 26, 3, 3, 'Assistant Prof. III'),
((SELECT id FROM users WHERE username = 'etchon_fae'), 'CPE', 'Permanent', 24, 3, 3, 'Instructor III'),
((SELECT id FROM users WHERE username = 'julve_jaypee'), 'CPE', 'Permanent', 21, 3, 0, 'Instructor III'),
((SELECT id FROM users WHERE username = 'ganancias_franklin'), 'CPE', 'Permanent', 24, 3, 3, 'Instructor II'),
((SELECT id FROM users WHERE username = 'rubenial_jehu'), 'CPE', 'Permanent', 24, 3, 0, 'Instructor II'),
((SELECT id FROM users WHERE username = 'azarcon_sharon'), 'CPE', 'Permanent', 23, 0, 3, 'Instructor I'),
((SELECT id FROM users WHERE username = 'gracia_joel'), 'CPE', 'Permanent', 26, 0, 3, 'Instructor I'),
((SELECT id FROM users WHERE username = 'rosas_lyndon'), 'CPE', 'Permanent', 23, 0, 3, 'Instructor I'),
((SELECT id FROM users WHERE username = 'basadre_josephine'), 'CPE', 'Permanent', 21, 6, 0, 'Instructor I'),
((SELECT id FROM users WHERE username = 'sering_jemimah'), 'CPE', 'Permanent', 23, 0, 0, 'Instructor I'),
((SELECT id FROM users WHERE username = 'orozco_jennifer'), 'CPE', 'Permanent', 24, 0, 0, 'Instructor I'),
((SELECT id FROM users WHERE username = 'cantila_brieg'), 'CPE', 'Contractual', 27, 0, 0, 'Contractual'),
((SELECT id FROM users WHERE username = 'arnaldo_mark'), 'CPE', 'Contractual', 27, 0, 0, 'Contractual'),
((SELECT id FROM users WHERE username = 'arreza_michol'), 'CPE', 'Contractual', 30, 0, 0, 'Contractual'),
((SELECT id FROM users WHERE username = 'tiu_clyde'), 'CPE', 'Contractual', 27, 0, 0, 'Contractual'),
((SELECT id FROM users WHERE username = 'luad_marnie'), 'CPE', 'Contractual', 27, 0, 0, 'Contractual'),
((SELECT id FROM users WHERE username = 'gomez_merinel'), 'CPE', 'Contractual', 30, 0, 0, 'Contractual'),
((SELECT id FROM users WHERE username = 'fallado_pearl'), 'CPE', 'Contractual', 30, 0, 0, 'Contractual'),
((SELECT id FROM users WHERE username = 'obatay_roy'), 'CPE', 'Contractual', 27, 0, 0, 'Contractual'),
('flores_iza', 'CPE', 'Contractual', 27, 0, 0, 'Contractual'),
('duero_dione', 'CPE', 'Contractual', 27, 0, 0, 'Contractual');

-- Migration complete
SELECT 'Migration complete - check admin/manage_instructors.php' AS status;
