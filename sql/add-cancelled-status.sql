-- Migration: Add 'cancelled' status to sync_jobs table
-- This allows distinguishing between jobs that failed due to errors vs user-initiated cancellation

USE Xhelo_qbo_devpos;

-- Add 'cancelled' to the status ENUM
ALTER TABLE sync_jobs 
MODIFY COLUMN status ENUM('pending', 'running', 'completed', 'failed', 'cancelled') DEFAULT 'pending';

-- Add comment describing the new status
ALTER TABLE sync_jobs 
MODIFY COLUMN status ENUM('pending', 'running', 'completed', 'failed', 'cancelled') DEFAULT 'pending' 
COMMENT 'pending: waiting to start, running: in progress, completed: finished successfully, failed: error occurred, cancelled: manually stopped';

-- Verify the change
SHOW COLUMNS FROM sync_jobs LIKE 'status';
