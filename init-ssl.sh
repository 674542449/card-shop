#!/bin/bash
set -e

DOMAIN=$1
EMAIL=$2

if [ -z "$DOMAIN" ] || [ -z "$EMAIL" ]; then
    echo "用法: ./init-ssl.sh <域名> <邮箱>"
    echo "示例: ./init-ssl.sh shop.example.com admin@example.com"
    exit 1
fi

echo "========================================"
echo "  SSL 证书自动申请"
echo "  域名: $DOMAIN"
echo "  邮箱: $EMAIL"
echo "========================================"
echo ""

# 1. Stop nginx to free port 80
echo "[1/5] 停止 Nginx 释放 80 端口..."
docker compose stop nginx 2>/dev/null || true
echo ""

# 2. Request certificate using standalone mode
echo "[2/5] 向 Let's Encrypt 申请证书..."
docker compose run --rm -p 80:80 --entrypoint "certbot" certbot certonly \
    --standalone \
    --email "$EMAIL" \
    --agree-tos \
    --no-eff-email \
    -d "$DOMAIN"
echo ""

# 3. Generate SSL Nginx config
echo "[3/5] 生成 SSL Nginx 配置..."
cat > docker/nginx/default.conf << NGINXEOF
server {
    listen 80;
    server_name $DOMAIN;

    location /.well-known/acme-challenge/ {
        root /var/www/certbot;
    }

    location / {
        return 301 https://\$host\$request_uri;
    }
}

server {
    listen 443 ssl;
    http2 on;
    server_name $DOMAIN;
    root /var/www/html/public;
    index index.php;

    ssl_certificate /etc/letsencrypt/live/$DOMAIN/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/$DOMAIN/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305;
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;

    charset utf-8;
    client_max_body_size 20M;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains; preload" always;

    # Gzip
    gzip on;
    gzip_vary on;
    gzip_proxied any;
    gzip_comp_level 6;
    gzip_min_length 1024;
    gzip_types text/plain text/css text/javascript application/javascript application/json application/xml application/rss+xml image/svg+xml;

    # Laravel
    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        fastcgi_pass app:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_buffers 16 16k;
        fastcgi_buffer_size 32k;
        fastcgi_read_timeout 60;
    }

    # Static files
    location ~* \.(css|js|gif|ico|jpg|jpeg|png|svg|webp|woff|woff2|ttf|eot)\$ {
        expires 30d;
        access_log off;
        add_header Cache-Control "public, immutable";
    }

    # Deny sensitive files
    location ~ /\.env { deny all; return 404; }
    location ~ /\.git { deny all; return 404; }
    location ~ /\.ht { deny all; return 404; }
    location ~ /\. { deny all; return 404; }
    location ~ composer\.(json|lock)\$ { deny all; return 404; }
    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt { access_log off; log_not_found off; }
}
NGINXEOF
echo ""

# 4. Update certbot service entrypoint for webroot renewal
echo "[4/5] 启动所有服务..."
docker compose up -d
echo ""

# 5. Verify
echo "[5/5] 验证证书..."
docker compose exec nginx nginx -t
echo ""

echo "========================================"
echo "  SSL 配置完成!"
echo ""
echo "  https://$DOMAIN"
echo ""
echo "  证书有效期: 90 天"
echo "  自动续期: certbot 容器每 12 小时检查"
echo ""
echo "  如果使用 Cloudflare 请将 SSL 模式"
echo "  改为 Full (Strict)"
echo "========================================"
