-- Schema cho scanpostfbapi. Chạy lại nhiều lần không lỗi (CREATE TABLE IF NOT EXISTS).
-- Thời gian lưu theo giờ Việt Nam (Asia/Ho_Chi_Minh, +07:00).

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(64) NOT NULL PRIMARY KEY,
  v TEXT NULL,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fb_groups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  input_raw VARCHAR(500) NOT NULL,
  fb_ref VARCHAR(191) NOT NULL,
  ref_type ENUM('url','group_id') NOT NULL,
  name VARCHAR(255) NULL,
  in_list TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  every_n_cycles INT NOT NULL DEFAULT 1,
  max_pages TINYINT NOT NULL DEFAULT 2,
  cap_hits INT NOT NULL DEFAULT 0,
  last_cap_hit_at DATETIME NULL,
  last_scanned_cycle INT NULL,
  last_scanned_at DATETIME NULL,
  consecutive_errors INT NOT NULL DEFAULT 0,
  paused_until_cycle INT NULL,
  last_error TEXT NULL,
  last_error_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_fb_ref (fb_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_keys (
  id INT AUTO_INCREMENT PRIMARY KEY,
  api_key VARCHAR(128) NOT NULL,
  priority INT NOT NULL DEFAULT 0,
  in_list TINYINT(1) NOT NULL DEFAULT 1,
  status ENUM('active','exhausted','invalid') NOT NULL DEFAULT 'active',
  credits_remaining INT NULL,
  last_used_at DATETIME NULL,
  last_checked_at DATETIME NULL,
  last_error TEXT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_api_key (api_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS posts (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  group_id INT NOT NULL,
  fb_post_id VARCHAR(64) NOT NULL,
  author_id VARCHAR(191) NULL,
  author_name VARCHAR(255) NULL,
  text MEDIUMTEXT NULL,
  content_hash CHAR(32) NULL,
  url VARCHAR(500) NULL,
  media TEXT NULL,
  publish_time INT NULL,
  first_seen_at DATETIME NOT NULL,
  status ENUM('queued','skipped_seller','baseline','duplicate') NOT NULL,
  UNIQUE KEY uq_group_post (group_id, fb_post_id),
  KEY idx_author (author_id),
  KEY idx_first_seen (first_seen_at),
  KEY idx_content (content_hash, first_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sellers (
  author_id VARCHAR(191) NOT NULL PRIMARY KEY,
  author_name VARCHAR(255) NULL,
  marked_by_chat_id BIGINT NULL,
  marked_at DATETIME NOT NULL,
  note VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tg_users (
  chat_id BIGINT NOT NULL PRIMARY KEY,
  username VARCHAR(64) NULL,
  first_name VARCHAR(128) NULL,
  state ENUM('new','awaiting_password','authorized','revoked') NOT NULL DEFAULT 'new',
  failed_attempts INT NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  authorized_at DATETIME NULL,
  blocked_bot TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  last_seen_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS outbox (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  chat_id BIGINT NOT NULL,
  post_id BIGINT NULL,
  kind ENUM('post','alert') NOT NULL,
  payload JSON NOT NULL,
  status ENUM('pending','sent','failed','cancelled') NOT NULL DEFAULT 'pending',
  attempts INT NOT NULL DEFAULT 0,
  media_sent TINYINT(1) NOT NULL DEFAULT 0,
  not_before DATETIME NULL,
  tg_message_id BIGINT NULL,
  last_error TEXT NULL,
  created_at DATETIME NOT NULL,
  sent_at DATETIME NULL,
  KEY idx_status (status, not_before),
  KEY idx_chat (chat_id, status),
  KEY idx_post (post_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scan_runs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cycle_no INT NOT NULL,
  started_at DATETIME NOT NULL,
  ended_at DATETIME NULL,
  groups_due INT NOT NULL DEFAULT 0,
  groups_scanned INT NOT NULL DEFAULT 0,
  api_calls INT NOT NULL DEFAULT 0,
  credits_used INT NOT NULL DEFAULT 0,
  new_posts INT NOT NULL DEFAULT 0,
  queued_posts INT NOT NULL DEFAULT 0,
  skipped_seller INT NOT NULL DEFAULT 0,
  errors INT NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  KEY idx_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_calls (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  run_id INT NULL,
  key_id INT NULL,
  group_id INT NULL,
  endpoint VARCHAR(64) NOT NULL DEFAULT 'group_posts',
  http_code INT NOT NULL DEFAULT 0,
  credits_charged INT NULL,
  duration_ms INT NOT NULL DEFAULT 0,
  posts_returned INT NULL,
  error VARCHAR(500) NULL,
  called_at DATETIME NOT NULL,
  KEY idx_group_time (group_id, called_at),
  KEY idx_run (run_id),
  KEY idx_called (called_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Thông tin group lấy từ /v1/facebook/group (trang "Giới thiệu"). raw_json giữ nguyên toàn bộ phản hồi API.
CREATE TABLE IF NOT EXISTS fb_group_info (
  group_id INT NOT NULL PRIMARY KEY,
  fb_group_id VARCHAR(32) NULL,
  name VARCHAR(255) NULL,
  privacy VARCHAR(64) NULL,
  visibility VARCHAR(64) NULL,
  member_count INT NULL,
  posts_last_day INT NULL,
  posts_last_month INT NULL,
  administrator_count INT NULL,
  moderator_count INT NULL,
  created_at_fb DATETIME NULL,
  raw_json MEDIUMTEXT NULL,
  fetched_at DATETIME NULL,
  last_error TEXT NULL,
  last_error_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
