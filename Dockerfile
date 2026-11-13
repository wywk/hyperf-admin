# 生产镜像：Hyperf Admin 后端
#
# 与开发容器使用同一基础镜像（PHP 8.1 + Swoole），保证行为一致。
# 构建：docker build -t hyperf-admin:latest .
# 配置一律通过运行时环境变量或挂载的 .env 注入（.env 不进镜像，见 .dockerignore）。

FROM hyperf/hyperf:8.1-alpine-v3.18-swoole
LABEL maintainer="hyperf-admin" app.name="hyperf-admin"

ARG timezone=Asia/Shanghai

ENV TIMEZONE=${timezone} \
    APP_ENV=prod \
    # 代码在镜像里已冻结，开启注解扫描缓存加快启动
    SCAN_CACHEABLE=true

RUN set -ex \
    && php -v \
    && php --ri swoole \
    && cd /etc/php* \
    && { \
        echo "opcache.enable_cli=1"; \
        echo "opcache.memory_consumption=256"; \
        echo "memory_limit=512M"; \
        echo "date.timezone=${TIMEZONE}"; \
    } | tee conf.d/99_overrides.ini \
    && ln -sf /usr/share/zoneinfo/${TIMEZONE} /etc/localtime \
    && echo "${TIMEZONE}" > /etc/timezone \
    && rm -rf /var/cache/apk/* /tmp/*

WORKDIR /opt/www

# 先装依赖再拷代码：composer.lock 不变时这一层直接走缓存
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && rm -rf runtime/container

COPY app/ app/
COPY bin/ bin/
COPY config/ config/
COPY migrations/ migrations/
COPY seeders/ seeders/
# /api/docs 接口要读 docs/ 下的 openapi.json 与静态页
COPY docs/ docs/
COPY docker/entrypoint.sh /entrypoint.sh
# 基础镜像默认以 root 运行，无需 chown；只要保证 runtime 目录存在
RUN chmod +x /entrypoint.sh \
    && mkdir -p runtime/logs storage

EXPOSE 9501

# 启动前可自动执行迁移/填充（由环境变量控制），再拉起常驻进程
ENTRYPOINT ["/entrypoint.sh"]

HEALTHCHECK --interval=15s --timeout=5s --start-period=20s --retries=3 \
    CMD wget -q -O /dev/null http://127.0.0.1:9501/api/docs || exit 1
