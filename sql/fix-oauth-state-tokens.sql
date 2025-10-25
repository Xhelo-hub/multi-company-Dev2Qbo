-- Fix oauth_state_tokens table to prevent duplicate state tokens
-- This migration adds a UNIQUE constraint on company_id

-- First, clean up any duplicate entries (keep only the most recent one per company)
DELETE t1 FROM oauth_state_tokens t1
INNER JOIN oauth_state_tokens t2 
WHERE t1.company_id = t2.company_id 
  AND t1.created_at < t2.created_at;

-- Now add the UNIQUE constraint
ALTER TABLE oauth_state_tokens 
ADD UNIQUE KEY unique_company (company_id);

-- Clean up expired tokens
DELETE FROM oauth_state_tokens WHERE expires_at < NOW();
