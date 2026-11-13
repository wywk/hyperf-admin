#!/bin/sh
# 生产容器入口：可选自动迁移/填充，然后前台启动 Hyperf。
#
# 环境变量：
#   RUN_MIGRATIONS=true  启动前执行 php bin/hyperf.php migrate --force
#   RUN_SEEDER=true      启动前执行 php bin/hyperf.php db:seed --force（幂等）
set -e

cd /opt/www

if [ "${RUN_MIGRATIONS}" = "true" ]; then
    echo "[entrypoint] running migrations..."
    php bin/hyperf.php migrate --force
fi

if [ "${RUN_SEEDER}" = "true" ]; then
    echo "[entrypoint] running seeders..."
    php bin/hyperf.php db:seed --force
fi

exec php bin/hyperf.php start
