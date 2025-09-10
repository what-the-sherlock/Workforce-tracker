-- MySQL DDL (run once)
CREATE DATABASE IF NOT EXISTS time_tracking;
USE time_tracking;

CREATE TABLE sessions (
  session_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id VARCHAR(100) NOT NULL,
  machine_id VARCHAR(100) NOT NULL,
  login_time DATETIME NOT NULL,
  logout_time DATETIME DEFAULT NULL,
  total_idle_seconds BIGINT DEFAULT 0,
  INDEX idx_user_login (user_id, login_time)
) ENGINE=InnoDB;

CREATE TABLE idle_events (
  event_id INT AUTO_INCREMENT PRIMARY KEY,
  session_id INT NOT NULL,
  idle_start DATETIME NOT NULL,
  idle_end DATETIME DEFAULT NULL,
  duration_seconds BIGINT DEFAULT 0,
  FOREIGN KEY (session_id) REFERENCES sessions(session_id) ON DELETE CASCADE,
  INDEX idx_session_start (session_id, idle_start)
) ENGINE=InnoDB;


