[Unit]
Description=Laravel Queue Worker (Auto-Deployment System)
After=network.target

[Service]
User=awbuilder
Group=www-data
Restart=always
ExecStart=/usr/bin/php /var/www/auto-deployment-system/artisan queue:work --sleep=3 --tries=3 --timeout=90
StandardOutput=syslog
StandardError=syslog
SyslogIdentifier=laravel-worker

[Install]
WantedBy=multi-user.target
