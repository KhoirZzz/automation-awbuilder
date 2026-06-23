# Auto Deployment System Project Rules & Notes

## SSH & VPS Deployment Memory
- **VPS Username & Host:** `awbuilder@103.150.190.30`
- **SSH Key Path:** `/home/parri/.ssh/key-auto-deploy.pem` (locally) or `~/.ssh/key-auto-deploy.pem`
- **Application Directory on VPS:** `/var/www/auto-deployment-system`
- **VPS Redeployment Command Sequence:**
  ```bash
  ssh -i ~/.ssh/key-auto-deploy.pem awbuilder@103.150.190.30 "cd /var/www/auto-deployment-system && git pull origin main && php artisan migrate --force && npm run build && php artisan optimize && php artisan queue:restart"
  ```
