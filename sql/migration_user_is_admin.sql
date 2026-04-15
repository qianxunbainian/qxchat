-- 用户表增加管理员标记；升级后若尚无任何管理员，则将 id 最小的用户设为管理员。
-- 应用也会在首次连接数据库时自动执行等价迁移（见 includes/db.php）。

SET NAMES utf8mb4;

ALTER TABLE users
  ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash;

UPDATE users u
INNER JOIN (SELECT MIN(id) AS id FROM users) first_user ON u.id = first_user.id
SET u.is_admin = 1
WHERE (SELECT COUNT(*) FROM users WHERE is_admin = 1) = 0;
