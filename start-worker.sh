#!/bin/bash
# Start sync worker in background with nohup
# Usage: ./start-worker.sh

cd /home/converter/web/devsync.konsulence.al/public_html
nohup php bin/sync-worker.php > logs/sync-worker.log 2>&1 &
echo $! > logs/sync-worker.pid
echo "✅ Sync worker started with PID $(cat logs/sync-worker.pid)"
echo "📋 Logs: logs/sync-worker.log"
echo "🛑 To stop: kill $(cat logs/sync-worker.pid)"
