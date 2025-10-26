-- Create scheduled_syncs table for automated sync jobs
CREATE TABLE IF NOT EXISTS scheduled_syncs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    job_type ENUM('sales', 'purchases', 'bills', 'full') NOT NULL DEFAULT 'full',
    frequency ENUM('hourly', 'daily', 'weekly', 'monthly') NOT NULL DEFAULT 'daily',
    hour_of_day TINYINT DEFAULT 9 COMMENT 'Hour to run (0-23)',
    day_of_week TINYINT DEFAULT NULL COMMENT 'Day of week (0=Sunday, 6=Saturday) for weekly',
    day_of_month TINYINT DEFAULT NULL COMMENT 'Day of month (1-31) for monthly',
    date_range_days INT DEFAULT 30 COMMENT 'How many days back to sync',
    enabled BOOLEAN DEFAULT TRUE,
    last_run_at TIMESTAMP NULL DEFAULT NULL,
    next_run_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    INDEX idx_company (company_id),
    INDEX idx_enabled (enabled),
    INDEX idx_next_run (next_run_at, enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Scheduled sync configuration per company';

-- Add some example scheduled syncs
-- Daily full sync at 9 AM for all companies
INSERT INTO scheduled_syncs (company_id, job_type, frequency, hour_of_day, date_range_days, next_run_at)
SELECT 
    id,
    'full',
    'daily',
    9,
    30,
    DATE_ADD(CURDATE() + INTERVAL 9 HOUR, INTERVAL IF(HOUR(NOW()) >= 9, 1, 0) DAY)
FROM companies
ON DUPLICATE KEY UPDATE company_id = company_id;
