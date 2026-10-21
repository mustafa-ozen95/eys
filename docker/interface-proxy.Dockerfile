# Cihaz arayüzü vekili (9443, 9450-9479): resmi Caddy + bu deponun ayarı. Ayar
# imajda durur, sunucuya dosya bağlanmaz; sertifika hacimden (/sertifika).
FROM caddy:2.11-alpine
COPY docker/interface-proxy.Caddyfile /etc/caddy/Caddyfile
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s CMD nc -z 127.0.0.1 9443 || exit 1
