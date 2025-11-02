#!/bin/bash
mysql Xhelo_qbo_devpos <<EOF
ALTER TABLE sync_jobs MODIFY COLUMN status ENUM('pending','running','completed','failed','cancelled') DEFAULT 'pending';
SELECT 'Migration completed successfully!' as Status;
EOF
