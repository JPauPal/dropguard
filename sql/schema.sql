-- Drop Guard (PHP + Python + MySQL) schema
-- Import in phpMyAdmin or MySQL Workbench.

CREATE DATABASE IF NOT EXISTS dropguard CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE dropguard;

-- Users (Role-based login)
CREATE TABLE IF NOT EXISTS users (
  user_id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  full_name VARCHAR(150) NOT NULL,
  role ENUM('Teacher','Counselor','Admin') NOT NULL DEFAULT 'Teacher',
  email VARCHAR(150) NULL,
  contact_number VARCHAR(30) NULL,
  profile_photo_path VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Students (per Technical Spec)
CREATE TABLE IF NOT EXISTS students (
  student_id INT AUTO_INCREMENT PRIMARY KEY,
  lrn VARCHAR(30) NULL UNIQUE,
  name VARCHAR(150) NOT NULL,
  grade_level VARCHAR(20) NOT NULL,
  strand VARCHAR(40) NULL,
  section VARCHAR(80) NOT NULL,
  face_image_path VARCHAR(255) NULL,
  gpa DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  absences INT NOT NULL DEFAULT 0,
  risk_score DECIMAL(3,2) NOT NULL DEFAULT 0.00,
  risk_level ENUM('Low','Moderate','High') NOT NULL DEFAULT 'Low',
  is_archived TINYINT(1) NOT NULL DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE INDEX idx_students_risk ON students (risk_level, risk_score);
CREATE INDEX idx_students_grade ON students (grade_level);

-- Student batches by school year
CREATE TABLE IF NOT EXISTS student_batches (
  batch_id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  school_year VARCHAR(9) NOT NULL,
  grade_level VARCHAR(20) NOT NULL,
  strand VARCHAR(40) NULL,
  section VARCHAR(80) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_student_year (student_id, school_year),
  CONSTRAINT fk_batches_student FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_batches_year ON student_batches (school_year);
CREATE INDEX idx_batches_student ON student_batches (student_id);

-- Teacher class ownership / visibility map
CREATE TABLE IF NOT EXISTS teacher_students (
  teacher_user_id INT NOT NULL,
  student_id INT NOT NULL,
  assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (teacher_user_id, student_id),
  CONSTRAINT fk_ts_teacher FOREIGN KEY (teacher_user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  CONSTRAINT fk_ts_student FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_ts_student ON teacher_students (student_id);

-- Admin-managed Sections (used by schedules and sheets)
CREATE TABLE IF NOT EXISTS sections (
  section_id BIGINT AUTO_INCREMENT PRIMARY KEY,
  section_name VARCHAR(80) NOT NULL UNIQUE,
  grade_level VARCHAR(20) NULL,
  section_short VARCHAR(80) NULL,
  strand VARCHAR(20) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Admin-managed Subjects (code + display name)
CREATE TABLE IF NOT EXISTS subjects (
  subject_id BIGINT AUTO_INCREMENT PRIMARY KEY,
  subject_code VARCHAR(30) NOT NULL UNIQUE,
  subject_name VARCHAR(120) NOT NULL,
  grade_level VARCHAR(20) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_subjects_grade (grade_level),
  INDEX idx_subjects_active (is_active, subject_code)
) ENGINE=InnoDB;

-- Subject subscriptions by section
CREATE TABLE IF NOT EXISTS section_subjects (
  section_id BIGINT NOT NULL,
  subject_id BIGINT NOT NULL,
  assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (section_id, subject_id),
  CONSTRAINT fk_section_subject_section FOREIGN KEY (section_id) REFERENCES sections(section_id) ON DELETE CASCADE,
  CONSTRAINT fk_section_subject_subject FOREIGN KEY (subject_id) REFERENCES subjects(subject_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Password change requests (teacher/counselor -> admin approval)
CREATE TABLE IF NOT EXISTS password_change_requests (
  request_id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  new_password_hash VARCHAR(255) NOT NULL,
  status ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  acted_at TIMESTAMP NULL,
  acted_by INT NULL,
  INDEX idx_pcr_status (status, requested_at),
  INDEX idx_pcr_user (user_id, requested_at),
  CONSTRAINT fk_pcr_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  CONSTRAINT fk_pcr_acted_by FOREIGN KEY (acted_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Account deactivation requests (teacher/counselor -> admin approval)
CREATE TABLE IF NOT EXISTS account_deactivation_requests (
  request_id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  reason VARCHAR(500) NULL,
  status ENUM('Pending','Approved','Rejected','Cancelled') NOT NULL DEFAULT 'Pending',
  requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  acted_at TIMESTAMP NULL,
  acted_by INT NULL,
  INDEX idx_adr_status (status, requested_at),
  INDEX idx_adr_user (user_id, requested_at),
  CONSTRAINT fk_adr_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  CONSTRAINT fk_adr_acted_by FOREIGN KEY (acted_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Performance: semantic term_id (JHS_Q1… / SHS_S1_Q1…) + quarter slot 1–4 for ML (see app/config/curriculum/*.json)
CREATE TABLE IF NOT EXISTS performance (
  performance_id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  school_year VARCHAR(9) NULL,
  term_id VARCHAR(32) NOT NULL,
  quarter TINYINT NOT NULL,
  gpa DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  days_present INT NOT NULL DEFAULT 0,
  total_school_days INT NOT NULL DEFAULT 0,
  absences INT NOT NULL DEFAULT 0,
  consecutive_absences INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_student_quarter_year (student_id, school_year, quarter),
  UNIQUE KEY uniq_student_term_year (student_id, school_year, term_id),
  CONSTRAINT fk_perf_student FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_perf_student ON performance (student_id);

-- Grading component breakdown and final calculated scores
CREATE TABLE IF NOT EXISTS grading_components (
  component_id BIGINT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  term_id VARCHAR(32) NOT NULL,
  subject_id BIGINT NOT NULL DEFAULT 0,
  quiz DECIMAL(6,2) NULL,
  exam DECIMAL(6,2) NULL,
  project DECIMAL(6,2) NULL,
  extracurricular_score DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  academic_score DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  initial_score DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  final_score DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  is_final TINYINT(1) NOT NULL DEFAULT 0,
  finalized_at TIMESTAMP NULL,
  finalized_by INT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_student_term_subject (student_id, term_id, subject_id),
  CONSTRAINT fk_gc_student FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Risk analysis (output history from Python script)
CREATE TABLE IF NOT EXISTS risk_analysis (
  risk_id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  school_year VARCHAR(9) NULL,
  term_id VARCHAR(32) NULL,
  quarter TINYINT NULL,
  probability_score DECIMAL(5,4) NOT NULL,
  risk_level ENUM('Low','Moderate','High') NOT NULL,
  generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_risk_student FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_risk_student ON risk_analysis (student_id, generated_at);

-- Intervention logs (counselor notes)
CREATE TABLE IF NOT EXISTS interventions (
  intervention_id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  created_by INT NOT NULL,
  note TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_int_student FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
  CONSTRAINT fk_int_user FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_int_student ON interventions (student_id, created_at);

-- Manual teacher referrals (non-academic observations)
CREATE TABLE IF NOT EXISTS manual_referrals (
  referral_id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  teacher_user_id INT NOT NULL,
  reason VARCHAR(255) NOT NULL,
  details TEXT NULL,
  status ENUM('New','Reviewed','Closed') NOT NULL DEFAULT 'New',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ref_student FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
  CONSTRAINT fk_ref_teacher FOREIGN KEY (teacher_user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_ref_status ON manual_referrals (status, created_at);

-- Counselor case management
CREATE TABLE IF NOT EXISTS counseling_cases (
  case_id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL UNIQUE,
  status ENUM('Flagged','Ongoing Counseling','Resolved') NOT NULL DEFAULT 'Flagged',
  counselor_user_id INT NULL,
  notes TEXT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_case_student FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
  CONSTRAINT fk_case_counselor FOREIGN KEY (counselor_user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Student issue flags (multiple per student)
CREATE TABLE IF NOT EXISTS student_flags (
  flag_id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  issue_type ENUM('Health','Financial','Behavioral','Academic','Family','Other') NOT NULL,
  severity ENUM('Low','Moderate','High') NOT NULL DEFAULT 'Moderate',
  note VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  flagged_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  resolved_at TIMESTAMP NULL,
  CONSTRAINT fk_flags_student FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
  CONSTRAINT fk_flags_user FOREIGN KEY (flagged_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_flags_student ON student_flags (student_id, is_active);
CREATE INDEX idx_flags_type ON student_flags (issue_type);

-- Audit logs (admin monitoring)
CREATE TABLE IF NOT EXISTS audit_logs (
  audit_id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  action VARCHAR(120) NOT NULL,
  status ENUM('info','success','failure') NOT NULL DEFAULT 'info',
  target_type VARCHAR(60) NULL,
  target_id INT NULL,
  description TEXT NULL,
  details_json LONGTEXT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_audit_created ON audit_logs (created_at);
CREATE INDEX idx_audit_user ON audit_logs (user_id);
CREATE INDEX idx_audit_action ON audit_logs (action);

-- Login security / brute-force protection attempts
CREATE TABLE IF NOT EXISTS auth_login_attempts (
  attempt_id BIGINT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL,
  ip_address VARCHAR(45) NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  user_agent VARCHAR(255) NULL,
  attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE INDEX idx_auth_attempts_user_time ON auth_login_attempts (username, attempted_at);
CREATE INDEX idx_auth_attempts_ip_time ON auth_login_attempts (ip_address, attempted_at);

-- Configurable app settings (e.g., risk thresholds)
CREATE TABLE IF NOT EXISTS app_settings (
  setting_key VARCHAR(80) PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO app_settings (setting_key, setting_value)
VALUES
('risk_low_max', '0.40'),
('risk_high_min', '0.70'),
('grade_weight_quiz', '30'),
('grade_weight_exam', '40'),
('grade_weight_project', '30'),
('grade_extracurricular_max', '10')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- DepEd transmutation ON by default (Philippine K-12); INSERT IGNORE preserves admin overrides.
INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
('enable_transmutation', '1'),
('grade_use_deped_transmutation', '1');

-- Default sections and subjects for fresh installs
INSERT INTO sections (section_name, grade_level, section_short, strand)
VALUES
('Grade 7 - A', 'Grade 7', 'A', NULL),
('Grade 9 - A', 'Grade 9', 'A', NULL),
('Grade 12 - STEM A', 'Grade 12', 'STEM A', 'STEM')
ON DUPLICATE KEY UPDATE section_name = VALUES(section_name), grade_level = VALUES(grade_level), section_short = VALUES(section_short), strand = VALUES(strand);

INSERT INTO subjects (subject_code, subject_name, grade_level)
VALUES
('MATH7', 'Mathematics', 'Grade 7'),
('ENG9', 'English', 'Grade 9'),
('PR2', 'Practical Research 2', 'Grade 12')
ON DUPLICATE KEY UPDATE subject_name = VALUES(subject_name), grade_level = VALUES(grade_level), is_active = 1;

INSERT IGNORE INTO section_subjects (section_id, subject_id)
SELECT sec.section_id, sub.subject_id
FROM sections sec
INNER JOIN subjects sub ON
  (sec.section_name = 'Grade 7 - A' AND sub.subject_code = 'MATH7')
  OR (sec.section_name = 'Grade 9 - A' AND sub.subject_code = 'ENG9')
  OR (sec.section_name = 'Grade 12 - STEM A' AND sub.subject_code = 'PR2');

-- Default users (hashes from PHP password_hash(..., PASSWORD_DEFAULT))
-- Admin:   username jp.palma   password !2011Pau
-- Teacher: username teacher     password !2004Pau
-- Counselor: username palma   password !1107Pau
DELETE FROM users;
INSERT INTO users (username, full_name, role, password_hash, is_active) VALUES
('jp.palma', 'Jose Paulo Palma', 'Admin', '$2y$10$xjh4ilpU3SMETg657JspjOJa8r7Mc480sYbBi5uT/zD2.yuLYyUVi', 1),
('teacher', 'Teacher', 'Teacher', '$2y$10$xTh3DfL5pae0CB8fDX4W1eJTEvfYI77NLdWhnDSDwE/Q76oDabZCO', 1),
('palma', 'Palma', 'Counselor', '$2y$10$CQVjWwEA5nvv.e3AhBpP/uJn0JPNExaHpd4DxGMn/xduUyt0YVSRO', 1);

-- Optional sample data
INSERT INTO students (name, grade_level, strand, section, gpa, absences, risk_score, risk_level)
VALUES
('Sample Student A', 'Grade 7', NULL, 'Grade 7 - A', 88.50, 2, 0.10, 'Low'),
('Sample Student B', 'Grade 9', NULL, 'Grade 9 - A', 74.25, 8, 0.45, 'Moderate'),
('Sample Student C', 'Grade 12', 'STEM', 'Grade 12 - STEM A', 65.00, 15, 0.75, 'High')
ON DUPLICATE KEY UPDATE name=name;

