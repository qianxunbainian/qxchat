<?php
declare(strict_types=1);

/**
 * 一键安装执行的建表语句（可重复执行：IF NOT EXISTS）
 *
 * @copyright 千寻百念工作室
 *
 * @return list<string>
 */
function install_schema_statements(): array
{
    return [
        <<<SQL
CREATE TABLE IF NOT EXISTS users (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<SQL
CREATE TABLE IF NOT EXISTS rooms (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  slug VARCHAR(80) NOT NULL,
  broadcast_text VARCHAR(200) NULL DEFAULT NULL,
  anonymous_mode TINYINT(1) NOT NULL DEFAULT 0,
  guest_allowed TINYINT(1) NOT NULL DEFAULT 0,
  room_password_hash VARCHAR(255) NULL DEFAULT NULL,
  created_by INT UNSIGNED NULL,
  created_at INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rooms_slug (slug),
  KEY idx_rooms_created_by (created_by),
  CONSTRAINT fk_rooms_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<SQL
CREATE TABLE IF NOT EXISTS messages (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<SQL
CREATE TABLE IF NOT EXISTS room_presence (
  room_id INT UNSIGNED NOT NULL,
  session_key VARCHAR(96) NOT NULL,
  display_label VARCHAR(80) NOT NULL,
  ip VARCHAR(45) NULL DEFAULT NULL,
  geo_text VARCHAR(120) NULL DEFAULT NULL,
  geo_updated_at INT UNSIGNED NULL DEFAULT NULL,
  last_active INT UNSIGNED NOT NULL,
  PRIMARY KEY (room_id, session_key),
  CONSTRAINT fk_presence_room FOREIGN KEY (room_id) REFERENCES rooms (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ];
}
