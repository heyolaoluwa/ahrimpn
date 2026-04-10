-- AHRIMPN Portal — Database Migration
-- Run this once on your MySQL server to add new columns and tables.

-- ── 1. NEW COLUMNS ON users TABLE ───────────────────────────────────────────
ALTER TABLE users
  ADD COLUMN membership_category    VARCHAR(50)  DEFAULT NULL AFTER workplace,
  ADD COLUMN professional_cadre     VARCHAR(50)  DEFAULT NULL AFTER membership_category,
  ADD COLUMN present_qualification  VARCHAR(50)  DEFAULT NULL AFTER professional_cadre,
  ADD COLUMN payment_type           VARCHAR(20)  DEFAULT 'individual' AFTER present_qualification;

-- ── 2. MANUAL PAYMENTS TABLE (replaces Flutterwave) ─────────────────────────
CREATE TABLE IF NOT EXISTS manual_payments (
  id              INT            AUTO_INCREMENT PRIMARY KEY,
  user_id         INT            NOT NULL,
  amount          DECIMAL(10,2)  NOT NULL DEFAULT 0,
  purpose         VARCHAR(30)    NOT NULL DEFAULT 'registration'
                  COMMENT 'registration | dues | certificate',
  plan_type       VARCHAR(20)    DEFAULT NULL
                  COMMENT 'monthly | annual | one-time',
  proof_file      VARCHAR(255)   DEFAULT NULL,
  bank_ref        VARCHAR(255)   DEFAULT NULL,
  status          VARCHAR(20)    NOT NULL DEFAULT 'pending'
                  COMMENT 'pending | approved | rejected',
  reviewed_by     INT            DEFAULT NULL,
  reviewed_at     DATETIME       DEFAULT NULL,
  notes           TEXT           DEFAULT NULL,
  created_at      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user    (user_id),
  KEY idx_status  (status),
  KEY idx_purpose (purpose)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 3. JOBS TABLE ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS jobs (
  id            INT          AUTO_INCREMENT PRIMARY KEY,
  user_id       INT          NOT NULL,
  title         VARCHAR(255) NOT NULL,
  company       VARCHAR(255) NOT NULL,
  location      VARCHAR(255) DEFAULT NULL,
  job_type      VARCHAR(50)  DEFAULT 'Full-time',
  description   TEXT         NOT NULL,
  status        VARCHAR(20)  NOT NULL DEFAULT 'pending'
                COMMENT 'pending | approved | rejected',
  reviewed_by   INT          DEFAULT NULL,
  reviewed_at   DATETIME     DEFAULT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user   (user_id),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
