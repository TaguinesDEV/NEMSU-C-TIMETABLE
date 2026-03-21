-- Fixed migration: Create users FIRST, then instructors (no FK error)
USE `academic_scheduling`;

-- Create users (password: 'instructor123' for all)
INSERT IGNORE INTO `users` (username, password, email, role, full_name) VALUES
('nelyne', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'nelyne@nemsu.edu.ph', 'instructor', 'NELYNE LOURDES Y. PLAZA'),
('nancy', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'nancy@nemsu.edu.ph', 'instructor', 'NANCY MARIE M. ARIMANG'),
('cheryl', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'cheryl@nemsu.edu.ph', 'instructor', 'CHERYL O. TAYO'),
('fae', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'fae@nemsu.edu.ph', 'instructor', 'FAE MYLENE M. ETCHON'),
('jaypee', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'jaypee@nemsu.edu.ph', 'instructor', 'JAYPEE B. JULVE'),

('ganancias_franklin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'ganancias@nemsu.edu.ph', 'instructor', 'FRANKLIN M. GANANCIAS'),
('rubenial_jehu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'rubenial@nemsu.edu.ph', 'instructor', 'JEHU ROEH C. RUBENIAL'),
('azarcon_sharon', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'azarcon@nemsu.edu.ph', 'instructor', 'SHARON G. AZARCON'),
('gracia_joel', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'gracia@nemsu.edu.ph', 'instructor', 'JOEL S. GRACIA'),
('rosas_lyndon', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'rosas@nemsu.edu.ph', 'instructor', 'LYNDON A. ROSAS'),
('basadre_josephine', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'basadre@nemsu.edu.ph', 'instructor', 'JOSEPHINE A. BASADRE'),
('sering_jemimah', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'sering@nemsu.edu.ph', 'instructor', 'JEMIMAH MAE C. SERING'),
('orozco_jennifer', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'orozco@nemsu.edu.ph', 'instructor', 'JENNIFER L. OROZCO'),
('cantila_brieg', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'cantila@nemsu.edu.ph', 'instructor', 'BRIEG DINESE A. CANTILA'),
('arnaldo_mark', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'arnaldo@nemsu.edu.ph', 'instructor', 'MARK VINCENT ARNALDO'),
('arreza_michol', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'arreza@nemsu.edu.ph', 'instructor', 'MICHOL ANTHONY U. ARREZA'),
('tiu_clyde', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'tiu@nemsu.edu.ph', 'instructor', 'CLYDE CHECTOPHER A. TIU'),
('luad_marnie', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'luad@nemsu.edu.ph', 'instructor', 'MARNIE ROSE P. LUAD'),
('gomez_merinel', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'gomez@nemsu.edu.ph', 'instructor', 'MERINELLE A. GOMEZ'),
('fallado_pearl', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'fallado@nemsu.edu.ph', 'instructor', 'PEARL AJ G. FALLADO'),
('obatay_roy', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'obatay@nemsu.edu.ph', 'instructor', 'ROY D. OBATAY'),
('flores_iza', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'flores@nemsu.edu.ph', 'instructor', 'IZA C. FLORES'),
('duero_dione', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'duero@nemsu.edu.ph', 'instructor', 'DIONE S. DUERO');

-- Create instructors (match user_id, set status/max_hours/deload)
INSERT INTO `instructors` (user_id, department, status, max_hours_per_week, designation_units, research_extension_units, special_assignment_units, rank) VALUES
((SELECT id FROM users WHERE username = 'plaza_nelyne'), 'CPE', 'Permanent', 24, 6, 0, 0, 'Associate Prof. IV'),
((SELECT id FROM users WHERE username = 'arimang_nancy'), 'CPE', 'Permanent', 24, 3, 3, 0, 'Assistant Prof. IV'),
((SELECT id FROM users WHERE username = 'tayo_cheryl'), 'CPE', 'Permanent', 26, 3, 3, 0, 'Assistant Prof. III'),
((SELECT id FROM users WHERE username = 'etchon_fae'), 'CPE', 'Permanent', 24, 3, 3, 0, 'Instructor III'),
((SELECT id FROM users WHERE username = 'julve_jaypee'), 'CPE', 'Permanent', 21, 3, 0, 0, 'Instructor III'),
((SELECT id FROM users WHERE username = 'ganancias_franklin'), 'CPE', 'Permanent', 24, 3, 3, 0, 'Instructor II'),
((SELECT id FROM users WHERE username = 'rubenial_jehu'), 'CPE', 'Permanent', 24, 3, 0, 0, 'Instructor II'),
((SELECT id FROM users WHERE username = 'azarcon_sharon'), 'CPE', 'Permanent', 23, 0, 3, 0, 'Instructor I'),
((SELECT id FROM users WHERE username = 'gracia_joel'), 'CPE', 'Permanent', 26, 0, 3, 0, 'Instructor I'),
((SELECT id FROM users WHERE username = 'rosas_lyndon'), 'CPE', 'Permanent', 23, 0, 3, 0, 'Instructor I'),
((SELECT id FROM users WHERE username = 'basadre_josephine'), 'CPE', 'Permanent', 21, 6, 0, 0, 'Instructor I'),
((SELECT id FROM users WHERE username = 'sering_jemimah'), 'CPE', 'Permanent', 23, 0, 0, 0, 'Instructor I'),
((SELECT id FROM users WHERE username = 'orozco_jennifer'), 'CPE', 'Permanent', 24, 0, 0, 0, 'Instructor I'),
((SELECT id FROM users WHERE username = 'cantila_brieg'), 'CPE', 'Contractual', 27, 0, 0, 0, 'Contractual'),
((SELECT id FROM users WHERE username = 'arnaldo_mark'), 'CPE', 'Contractual', 27, 0, 0, 0, 'Contractual'),
((SELECT id FROM users WHERE username = 'arreza_michol'), 'CPE', 'Contractual', 30, 0, 0, 0, 'Contractual'),
((SELECT id FROM users WHERE username = 'tiu_clyde'), 'CPE', 'Contractual', 27, 0, 0, 0, 'Contractual'),
((SELECT id FROM users WHERE username = 'luad_marnie'), 'CPE', 'Contractual', 27, 0, 0, 0, 'Contractual'),
((SELECT id FROM users WHERE username = 'gomez_merinel'), 'CPE', 'Contractual', 30, 0, 0, 0, 'Contractual'),
((SELECT id FROM users WHERE username = 'fallado_pearl'), 'CPE', 'Contractual', 30, 0, 0, 0, 'Contractual'),
((SELECT id FROM users WHERE username = 'obatay_roy'), 'CPE', 'Contractual', 27, 0, 0, 0, 'Contractual'),
((SELECT id FROM users WHERE username = 'flores_iza'), 'CPE', 'Contractual', 27, 0, 0, 0, 'Contractual'),
((SELECT id FROM users WHERE username = 'duero_dione'), 'CPE', 'Contractual', 27, 0, 0, 0, 'Contractual');

SELECT '23 instructors imported - view admin/manage_instructors.php' AS status;
