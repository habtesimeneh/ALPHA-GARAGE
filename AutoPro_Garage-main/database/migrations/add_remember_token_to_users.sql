-- =====================================================================
-- AutoPro Garage Management System — Remember Me Migration
-- Adds persistent-login token columns to users (Remember Me feature).
-- Tokens are stored as SHA-256 hashes; the raw token exists only in
-- the HttpOnly/Secure/SameSite cookie until rotation or logout.
-- =====================================================================

ALTER TABLE users
  ADD COLUMN remember_token CHAR(64) NULL AFTER last_login_ip,
  ADD COLUMN remember_expires_at DATETIME NULL AFTER remember_token,
  ADD INDEX idx_users_remember_token (remember_token);
