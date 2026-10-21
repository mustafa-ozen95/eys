# =============================================================================
#  eys — Erişim Yönetim Sistemi (parola kasası), tek imaj (appliance)
# =============================================================================
#
#  Neden uygulama tek konteyner: kod her şeyin localhost'ta olduğunu
#  VARSAYIYOR. ttyd 127.0.0.1:7500-7699'da süreç açıyor, nginx auth_request
#  ile yine localhost'taki php-fpm'e soruyor; guacd ve Tomcat de öyle. Bu
#  varsayım aynen korunur ve kod değişmez. Veritabanı (MariaDB) ve redis ise
#  AYRI servisler (docker-compose.yml); TLS'i önündeki vekil bitirir, panel
#  yalnız 80'i ve cihaz arayüzü için 9080'i dinler. Ayrıntı: docker/README.md.
#
#  Servisleri supervisord yönetir. Yatay ölçekleme YOKTUR ve istenmez: parola
#  döndürme işleri kayıt başına zaten sıraya sokulmuş durumda.
#
#  Derleme:  docker compose build   (ya da: docker build -f docker/Dockerfile .)
# =============================================================================

ARG PHP_VERSION=8.5
ARG TTYD_VERSION=1.7.7
ARG GUACAMOLE_VERSION=1.5.5

# -----------------------------------------------------------------------------
#  1. Arayüz varlıkları
# -----------------------------------------------------------------------------
FROM node:22-bookworm-slim AS varliklar

WORKDIR /uygulama

# Önce yalnızca kilit dosyaları: kaynak değişince npm ci yeniden koşmasın.
COPY package.json package-lock.json ./
# --ignore-scripts: Playwright postinstall'ı tarayıcı indirmeye kalkıyor.
RUN npm ci --ignore-scripts

COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

# -----------------------------------------------------------------------------
#  2. guacd — KAYNAKTAN derlenir
# -----------------------------------------------------------------------------
#  Resmi guacamole/guacd:1.5.5 imajı Alpine/musl tabanlıdır; ikilileri glibc
#  tabanlı bu imaja kopyalanamaz. Sürüm yine 1.5.5, yalnızca glibc'ye bağlanır.
#
#  Java tarafı (guacamole.war, auth-json eklentisi) taşınabilir olduğu için
#  resmi imajdan doğrudan kopyalanır.
FROM debian:bookworm-slim AS guacd-derleme

ARG GUACAMOLE_VERSION
ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      build-essential ca-certificates curl \
      libcairo2-dev libjpeg62-turbo-dev libpng-dev libtool-bin libossp-uuid-dev \
      freerdp2-dev libpango1.0-dev libssh2-1-dev libtelnet-dev \
      libvncserver-dev libwebsockets-dev libpulse-dev libssl-dev \
      libvorbis-dev \
      libavcodec-dev libavformat-dev libavutil-dev libswscale-dev \
 && rm -rf /var/lib/apt/lists/*

RUN curl -fsSL "https://archive.apache.org/dist/guacamole/${GUACAMOLE_VERSION}/source/guacamole-server-${GUACAMOLE_VERSION}.tar.gz" \
      -o /tmp/guacamole-server.tar.gz \
 && tar -xzf /tmp/guacamole-server.tar.gz -C /tmp \
 && cd "/tmp/guacamole-server-${GUACAMOLE_VERSION}" \
 # WEBP KAPALI — kodlaması guacd'nin tek çekirdeğini yiyor.
 #
 # guacd, tarayıcı WebP destekliyorsa fotoğrafik karoları WebP'ye çeviriyor;
 # WebP daha iyi sıkıştırır ama KODLAMASI çok daha pahalı. Ekranı sürekli
 # değişen bir makinede bu, oturumu yavaşlatan şeyin ta kendisi oluyor: guacd
 # bir çekirdeği dolduruyor ve güncellemeler geride kalıyor.
 #
 # WebP kapalıyken görüntü belirgin biçimde daha akıcı ve guacd tavana
 # dayanmıyor. Bant genişliği artıyor (JPEG/PNG, WebP'den büyük) — yerel ağda
 # bant bol, takas doğru taraftan.
 #
 # Seçenek AÇIKÇA yazılıyor: yukarıdaki listeden webp geliştirme paketini
 # çıkarmak da yeterdi ama o zaman ileride başka bir paket onu bağımlılık
 # olarak geri getirdiğinde webp sessizce geri gelirdi.
 && ./configure --prefix=/opt/guacamole --disable-guaclog --without-webp \
 && make -j"$(nproc)" \
 && make install \
 && rm -rf /tmp/guacamole-server*

# -----------------------------------------------------------------------------
#  3. PHP tabanı — hem bağımlılık çözümü hem çalışma zamanı bunu paylaşır
# -----------------------------------------------------------------------------
FROM debian:bookworm-slim AS php-taban

ARG PHP_VERSION
ENV DEBIAN_FRONTEND=noninteractive

# PHP 8.5 Debian'da yok; Sury deposu eklenir.
RUN apt-get update \
 && apt-get install -y --no-install-recommends ca-certificates curl gnupg \
 && curl -fsSL https://packages.sury.org/php/apt.gpg -o /usr/share/keyrings/sury-php.gpg \
 && echo "deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ bookworm main" \
      > /etc/apt/sources.list.d/sury-php.list \
 && apt-get update \
 && apt-get install -y --no-install-recommends \
      php${PHP_VERSION}-cli \
      php${PHP_VERSION}-fpm \
      php${PHP_VERSION}-bcmath \
      php${PHP_VERSION}-curl \
      php${PHP_VERSION}-gd \
      php${PHP_VERSION}-intl \
      php${PHP_VERSION}-mbstring \
      php${PHP_VERSION}-mysql \
      php${PHP_VERSION}-readline \
      php${PHP_VERSION}-redis \
      php${PHP_VERSION}-xml \
      php${PHP_VERSION}-zip \
 && rm -rf /var/lib/apt/lists/*
# NOT: php8.5-opcache diye ayrı bir paket YOK; 8.5'te opcache php-common
# içinde geliyor. 8.3 ve öncesinde ayrıydı, alışkanlıkla eklenirse derleme
# "Unable to locate package" ile düşer.

# "php" her yerde 8.5 demek olsun: zamanlayıcı, artisan ve supervisord aynı
# ikiliyi çağırmalı.
RUN update-alternatives --set php /usr/bin/php${PHP_VERSION}

# -----------------------------------------------------------------------------
#  4. PHP bağımlılıkları
# -----------------------------------------------------------------------------
FROM php-taban AS saticilar

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /uygulama

# Kaynak kod olmadan çözüm yapılamaz (post-autoload-dump artisan çağırıyor),
# bu yüzden önce kilit dosyaları çözülür, betikler sonraya bırakılır.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# -----------------------------------------------------------------------------
#  5. Çalışma zamanı
# -----------------------------------------------------------------------------
FROM php-taban AS calisma

ARG PHP_VERSION
ARG TTYD_VERSION
ENV DEBIAN_FRONTEND=noninteractive \
    PHP_VERSION=${PHP_VERSION} \
    TZ=Europe/Istanbul

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      nginx \
      # Yalnız istemci: veritabanını bekleme ve Ayarlar → Veritabanı taşıması
      # (mariadb-dump). Sunucu compose'taki db servisi, redis de öyle.
      mariadb-client \
      supervisor \
      procps \
      tzdata \
      openssh-client \
      sshpass \
      # WinRM taşıması: OpenSSH'i olmayan Windows makinelerde parola
      # döndürme, pywinrm'i çağıran küçük bir Python yardımcısıyla yapılır
      # (bkz. resources/bin/winrm-runner.py). Modül kurulu değilken tur
      # "pywinrm yüklü değil" diyerek daha başlamadan düşüyordu.
      python3-winrm \
      gosu \
      openssl \
      unzip \
      curl \
      # guacd'nin çalışma zamanı kütüphaneleri (derleme aşamasındaki -dev
      # paketlerinin karşılıkları). Eksik biri "error while loading shared
      # libraries" ile guacd'yi hiç başlatmaz.
      libcairo2 libjpeg62-turbo libpng16-16 libossp-uuid16 \
      libfreerdp-client2-2 libfreerdp2-2 libwinpr2-2 \
      libpango-1.0-0 libpangocairo-1.0-0 \
      libssh2-1 libtelnet2 libvncclient1 libwebsockets17 \
      libpulse0 libvorbis0a libvorbisenc2 libwebp7 \
      libavcodec59 libavformat59 libavutil57 libswscale6 \
 && rm -rf /var/lib/apt/lists/* \
 && ln -snf /usr/share/zoneinfo/${TZ} /etc/localtime

# ttyd: uzak SSH oturumları için. Statik ikili, sürümü sabit — dağıtımın
# paketlemesine bağlı kalmamak için doğrudan yayından alınır.
RUN curl -fsSL "https://github.com/tsl0922/ttyd/releases/download/${TTYD_VERSION}/ttyd.x86_64" \
      -o /usr/local/bin/ttyd \
 && chmod 0755 /usr/local/bin/ttyd \
 && ttyd --version

# --- Guacamole ---------------------------------------------------------------
# guacd kendi derlediğimiz, Java tarafı resmi imajdan: sürümler birbirine
# uymak zorunda ve ikisi de 1.5.5.
COPY --from=guacd-derleme /opt/guacamole /opt/guacamole

# FreeRDP KANAL EKLENTİLERİ (guac-common-svc, guacsnd, guacdr…).
#
# Bunlar /opt/guacamole'ün ALTINA kurulmaz: FreeRDP eklentilerini KENDİ
# dizininden arar ve guacamole-server de oraya kurar. Yalnızca prefix
# kopyalanırsa derleme aşamasında kalırlar ve guacd her RDP oturumunda şunu
# der:
#
#   Cannot create static channel "rdpdr": failed to load "guac-common-svc"
#   Support for the RDPSND channel (audio output) could not be loaded
#
# Ardından bağlantı "Security negotiation failed" ile düşer — hata mesajı
# güvenlik kipini işaret ettiği için parola ya da NLA sanılır. Aynı makineye
# aynı kimlikle doğrudan xfreerdp ile hem /sec:nla hem /sec:any çalışır;
# fark yalnızca eksik eklentilerdir.
COPY --from=guacd-derleme /usr/lib/x86_64-linux-gnu/freerdp2 /usr/lib/x86_64-linux-gnu/freerdp2
COPY --from=guacamole/guacamole:1.5.5 /opt/java/openjdk /opt/java/openjdk
COPY --from=guacamole/guacamole:1.5.5 /usr/local/tomcat /usr/local/tomcat
COPY --from=guacamole/guacamole:1.5.5 /opt/guacamole/guacamole.war /usr/local/tomcat/webapps/guacamole.war
COPY --from=guacamole/guacamole:1.5.5 /opt/guacamole/json/guacamole-auth-json-1.5.5.jar /etc/guacamole/extensions/

ENV JAVA_HOME=/opt/java/openjdk \
    CATALINA_HOME=/usr/local/tomcat \
    GUACAMOLE_HOME=/etc/guacamole \
    PATH=/opt/java/openjdk/bin:/opt/guacamole/sbin:$PATH

# EKLENTİ YOLU AYARDA DURUR, YALNIZCA ÖNBELLEKTE DEĞİL.
#
# guacd protokol eklentilerini (libguac-client-rdp.so) dlopen ile, dinamik
# bağlayıcının arama yolundan açar. `ldconfig <dizin>` dizini yalnızca
# ld.so.cache'e yazar; ayar dosyası bırakmaz. Kabın içinde ldconfig'i
# tetikleyen HERHANGİ bir işlem (bir apt kurulumu yeter) önbelleği bu dizin
# olmadan yeniden üretiyor ve guacd o andan sonra her RDP bağlantısında
#
#   WARNING: Support for protocol "rdp" is not installed
#
# deyip düşüyor; tarayıcıda görünen şey ise "sunucu yanıt vermiyor" oluyor —
# yani ağ gibi görünen bir hata.
# Ayar dosyası bırakınca yeniden üretilen her önbellekte dizin yerinde kalır.
RUN echo /opt/guacamole/lib > /etc/ld.so.conf.d/guacamole.conf \
 && ldconfig \
 # Tomcat 8090'ı dinler: config/remote.php ve nginx'teki /rdp-proxy/ oraya
 # bakıyor. Varsayılan 8080 bırakılsaydı üç yerde ayrı ayrı değiştirmek
 # gerekirdi. Yalnız 127.0.0.1: veritabanı ve redis artık aynı Docker ağında
 # ayrı konteyner; Guacamole'e nginx'ten başka kimse doğrudan ulaşmasın.
 && sed -i 's|<Connector port="8080"|<Connector address="127.0.0.1" port="8090"|' /usr/local/tomcat/conf/server.xml \
 && grep -q 'address="127.0.0.1" port="8090"' /usr/local/tomcat/conf/server.xml \
 && rm -rf /usr/local/tomcat/webapps/ROOT /usr/local/tomcat/webapps/docs \
           /usr/local/tomcat/webapps/examples /usr/local/tomcat/webapps/manager \
           /usr/local/tomcat/webapps/host-manager \
 && guacd -v

# Uygulama kullanıcısı: hiçbir servis root olarak çalışmaz.
RUN useradd --system --create-home --home-dir /uygulama --shell /usr/sbin/nologin eys

# WAR derleme sırasında AÇILIR ve dosyanın kendisi silinir.
#
# Tomcat eys olarak çalışıyor, kurulum dizini ise root'un: çalışma anında ne
# WAR'ı açabiliyor ne de conf/Catalina/localhost'u yaratabiliyordu — sonuç
# "Unable to create directory for deployment" ve /guacamole için 404.
# Yalnızca YAZMASI gereken dizinler devredilir; bin ve lib root'ta kalır.
RUN mkdir -p /usr/local/tomcat/webapps/guacamole /usr/local/tomcat/conf/Catalina/localhost \
 && unzip -q /usr/local/tomcat/webapps/guacamole.war -d /usr/local/tomcat/webapps/guacamole \
 && rm -f /usr/local/tomcat/webapps/guacamole.war \
 && chown -R eys:eys /usr/local/tomcat/webapps /usr/local/tomcat/work \
                     /usr/local/tomcat/temp /usr/local/tomcat/logs \
                     /usr/local/tomcat/conf/Catalina

WORKDIR /uygulama

COPY --chown=eys:eys . .
COPY --from=saticilar --chown=eys:eys /uygulama/vendor ./vendor
COPY --from=varliklar --chown=eys:eys /uygulama/public/build ./public/build

# Yapılandırmalar ve başlatıcı
COPY docker/rootfs/ /

# PHP yapılandırmaları sürüm numaralı dizinlerde durur; ARG ile yerleştirilir.
RUN cp /etc/eys/php.ini /etc/php/${PHP_VERSION}/fpm/conf.d/99-eys.ini \
 && cp /etc/eys/php.ini /etc/php/${PHP_VERSION}/cli/conf.d/99-eys.ini \
 && rm -f /etc/php/${PHP_VERSION}/fpm/pool.d/www.conf \
 && cp /etc/eys/php-fpm-pool.conf /etc/php/${PHP_VERSION}/fpm/pool.d/eys.conf \
 && chmod 0755 /usr/local/bin/eys-* /usr/local/bin/arayuz-havuzlari /usr/local/bin/dc-* \
 && rm -f /etc/nginx/sites-enabled/default /etc/nginx/conf.d/default.conf \
 && mkdir -p storage/framework/{cache/data,sessions,views} storage/logs bootstrap/cache \
 && chown -R eys:eys storage bootstrap/cache \
 && chmod -R 0775 storage bootstrap/cache

VOLUME ["/uygulama/storage/app", "/uygulama/storage/logs"]

# 80: panel. 9080: cihaz arayüzü kökeni (vekil 9443 ve 9450-9479'u buraya verir).
EXPOSE 80 9080

STOPSIGNAL SIGTERM

HEALTHCHECK --interval=30s --timeout=5s --start-period=90s --retries=3 \
  CMD curl -fsS http://127.0.0.1/up || exit 1

# Hangi commit'ten derlendiği: panel kendi sürümünü buradan bilir (Ayarlar →
# Güncelleme). Son katmanda: değeri her derlemede değişir, önbelleği bozmasın.
ARG SURUM=""
ENV UYGULAMA_SURUMU=${SURUM}
# SURUM verilmezse (NineDeploy gibi platformlar derlemeye commit vermez)
# bağlamdaki .git'ten okunur ve /uygulama/SURUM'a yazılır; config önce
# ortama, sonra bu dosyaya bakar. .git yoksa (zip) dosya boş kalır.
RUN --mount=type=bind,target=/tmp/baglam sh /tmp/baglam/docker/surum-oku.sh > /uygulama/SURUM

ENTRYPOINT ["/usr/local/bin/eys-entrypoint"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/supervisord.conf"]
