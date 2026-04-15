-- PHPChatRoom MySQL schema (UTF-8)
-- Create database then import: mysql -u root -p phpchatroom < sql/schema.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS room_presence;
DROP TABLE IF EXISTS messages;
DROP TABLE IF EXISTS rooms;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(50) NOT NULL,
  nickname VARCHAR(50) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  is_admin TINYINT(1) NOT NULL DEFAULT 0,
  is_frozen TINYINT(1) NOT NULL DEFAULT 0,
  is_muted TINYINT(1) NOT NULL DEFAULT 0,
  can_upload_file TINYINT(1) NOT NULL DEFAULT 1,
  is_approved TINYINT(1) NOT NULL DEFAULT 0,
  created_at INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rooms (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  slug VARCHAR(80) NOT NULL,
  anonymous_mode TINYINT(1) NOT NULL DEFAULT 0,
  guest_allowed TINYINT(1) NOT NULL DEFAULT 0,
  room_password_hash VARCHAR(255) NULL DEFAULT NULL,
  created_by INT UNSIGNED NULL,
  created_at INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rooms_slug (slug),
  KEY idx_rooms_created_by (created_by),
  CONSTRAINT fk_rooms_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE messages (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  room_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  guest_key VARCHAR(64) NULL,
  display_name VARCHAR(80) NOT NULL,
  content TEXT NOT NULL,
  content_kind VARCHAR(16) NOT NULL DEFAULT 'text',
  attachment_path VARCHAR(512) NULL DEFAULT NULL,
  attachment_name VARCHAR(255) NULL DEFAULT NULL,
  reply_to_message_id INT UNSIGNED NULL DEFAULT NULL,
  is_recalled TINYINT(1) NOT NULL DEFAULT 0,
  recalled_at INT UNSIGNED NULL DEFAULT NULL,
  recalled_by_user_id INT UNSIGNED NULL DEFAULT NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  created_at INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  KEY idx_messages_room_id (room_id, id),
  CONSTRAINT fk_messages_room FOREIGN KEY (room_id) REFERENCES rooms (id) ON DELETE CASCADE,
  CONSTRAINT fk_messages_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE room_presence (
  room_id INT UNSIGNED NOT NULL,
  session_key VARCHAR(96) NOT NULL,
  display_label VARCHAR(80) NOT NULL,
  last_active INT UNSIGNED NOT NULL,
  PRIMARY KEY (room_id, session_key),
  CONSTRAINT fk_presence_room FOREIGN KEY (room_id) REFERENCES rooms (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO rooms (name, slug, anonymous_mode, guest_allowed, room_password_hash, created_by, created_at) VALUES
('大厅', 'lobby', 0, 0, NULL, NULL, UNIX_TIMESTAMP()),
('匿名树洞', 'anonymous-pit', 1, 1, NULL, NULL, UNIX_TIMESTAMP());

INSERT INTO messages (room_id, user_id, guest_key, display_name, content, is_system, created_at) VALUES
(1, NULL, NULL, '系统', '欢迎来到大厅！请文明交流。', 1, UNIX_TIMESTAMP()),
(2, NULL, NULL, '系统', '本房间为匿名模式：昵称对其他人不可追溯；访客也可进入（若开启）。', 1, UNIX_TIMESTAMP());
