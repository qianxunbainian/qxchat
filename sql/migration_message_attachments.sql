-- 已有数据库升级：为消息增加图片/附件字段（新安装请直接使用 sql/schema.sql）
-- 执行：mysql -u root -p phpchatroom < sql/migration_message_attachments.sql

ALTER TABLE messages
  ADD COLUMN content_kind VARCHAR(16) NOT NULL DEFAULT 'text' AFTER content,
  ADD COLUMN attachment_path VARCHAR(512) NULL DEFAULT NULL AFTER content_kind,
  ADD COLUMN attachment_name VARCHAR(255) NULL DEFAULT NULL AFTER attachment_path;
