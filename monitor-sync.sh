#!/bin/bash
# Monitor sync progress logs in real-time
# Usage: ./monitor-sync.sh

echo "📊 Monitoring sync progress..."
echo "Press Ctrl+C to stop"
echo ""

# Check if running with journalctl or tail
if command -v journalctl &> /dev/null; then
    # Use journalctl for systemd systems
    journalctl -u php8.3-fpm -f --no-pager | grep --line-buffered -E '\[([0-9]+)/([0-9]+)\]|Starting|completed|DevPos'
else
    # Fallback to tail
    tail -f /var/log/php8.3-fpm.log 2>/dev/null | grep --line-buffered -E '\[([0-9]+)/([0-9]+)\]|Starting|completed|DevPos'
fi
