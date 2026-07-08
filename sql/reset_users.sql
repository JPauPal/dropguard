-- Re-seed default Drop Guard login accounts (does not drop tables or student data).
-- Run in phpMyAdmin or: mysql -u root dropguard < sql/reset_users.sql

USE dropguard;

-- Default users (hashes from PHP password_hash(..., PASSWORD_DEFAULT))
-- Admin:     username jp.palma   password !2011Pau
-- Teacher:   username teacher     password !2004Pau
-- Counselor: username palma       password !1107Pau
DELETE FROM users;

INSERT INTO users (username, full_name, role, password_hash, is_active) VALUES
('jp.palma', 'Jose Paulo Palma', 'Admin', '$2y$10$xjh4ilpU3SMETg657JspjOJa8r7Mc480sYbBi5uT/zD2.yuLYyUVi', 1),
('teacher', 'Teacher', 'Teacher', '$2y$10$xTh3DfL5pae0CB8fDX4W1eJTEvfYI77NLdWhnDSDwE/Q76oDabZCO', 1),
('palma', 'Palma', 'Counselor', '$2y$10$CQVjWwEA5nvv.e3AhBpP/uJn0JPNExaHpd4DxGMn/xduUyt0YVSRO', 1);
