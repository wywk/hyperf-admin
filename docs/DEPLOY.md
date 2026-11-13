# 部署文档

本项目分为两部分，独立部署：

| 部分 | 技术栈 | 部署产物 |
|---|---|---|
| 后端 API | Hyperf 3.1（PHP 8.1 + Swoole） | Docker 镜像 `hyperf-admin:latest`（约 143MB） |
| 前端管理后台 | Vite 6 + Vue 3 + Element Plus | 静态文件（`npm run build` 产出，Nginx 托管） |

推荐架构：**Nginx 托管前端静态文件并反代 `/api` 到后端 9501 端口**，浏览器只需访问一个域名。

---

## 一、后端部署

### 方式 1：Docker Compose 一键部署（推荐）

后端 + MySQL 5.7 + Redis 7.2 整套拉起，适合全新服务器。

**1. 准备环境**

- Docker 20.10+ 与 Docker Compose v2
- 服务器能访问外网（首次构建需拉取 composer 依赖）

**2. 同步代码到服务器**（git clone 或 scp，整个项目目录）

**3. 创建生产配置 `.env.prod`**（在项目根目录，与 `docker-compose.prod.yml` 同级）

```bash
cp .env.example .env.prod
```

最少需要修改/确认以下项：

```ini
# 必改：JWT 签名密钥（生成命令：openssl rand -hex 32）
JWT_SECRET=<替换为随机值>

# 必改：数据库密码（compose 会同时用它初始化 MySQL）
DB_PASSWORD=<强密码>
MYSQL_ROOT_PASSWORD=<强密码>

# 一般不用改
DB_DATABASE=hyperf
DB_USERNAME=hyperf
DB_PREFIX=ha_          # 统一表前缀，建表时自动拼接
REDIS_HOST=redis
REDIS_PORT=6379
```

> ⚠️ `.env.prod` 含密钥，不要提交到 git（`.gitignore` 应排除 `.env.prod`）。

**4. 启动**

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build
```

首次启动自动完成：建库建号（MySQL 容器初始化）→ 执行迁移建表 → 填充基础数据（内置权限树、admin/user 角色、初始账号）→ 拉起服务。

**5. 确认就绪**

```bash
docker compose -f docker-compose.prod.yml ps
# app 状态应为 Up (healthy) —— 健康检查会打 /api/docs
```

服务端口默认 `9501`（可用 `.env.prod` 里的 `APP_PORT` 改），验证：

```bash
curl http://127.0.0.1:9501/api/docs        # 200 即正常
```

### 方式 2：本机构建镜像，传到服务器

服务器无需访问 composer/packagist，部署更快。

```bash
# 本机构建（如遇 buildx 报错，加 DOCKER_BUILDKIT=0）
cd hyperf-skeleton
DOCKER_BUILDKIT=0 docker build -t hyperf-admin:latest .

# 导出并传输
docker save hyperf-admin:latest | gzip > hyperf-admin.tar.gz
scp hyperf-admin.tar.gz user@server:/opt/

# 服务器上加载后，按方式 1 的第 3、4 步启动（build 已不需要，去掉 --build）
docker load < /opt/hyperf-admin.tar.gz
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d
```

### 方式 3：MySQL / Redis 使用外部实例（云数据库等）

编辑 `docker-compose.prod.yml`，删除 `mysql`、`redis` 两个 service 和 `depends_on` 块、底部的 `volumes` 声明，然后在 `.env.prod` 中配置：

```ini
DB_HOST=<数据库地址>
DB_PORT=3306
REDIS_HOST=<Redis 地址>
REDIS_PORT=6379
```

首次部署仍保持 `RUN_MIGRATIONS=true` 让容器自动建表；账号需预先建好并有建表权限。

### 后端环境变量速查

| 变量 | 默认 | 说明 |
|---|---|---|
| `JWT_SECRET` | **必填** | JWT 签名密钥，`openssl rand -hex 32` 生成；更换后所有已发 token 立即失效 |
| `JWT_TTL` | 7200 | access token 有效期（秒） |
| `JWT_REFRESH_TTL` | 1209600 | refresh token 有效期（秒，7 天） |
| `RUN_MIGRATIONS` | compose 中为 true | 启动前自动执行迁移；**稳定后建议改 false**，迁移手动控制 |
| `RUN_SEEDER` | compose 中为 true | 启动前自动填充基础数据（幂等，重复执行安全） |
| `LOGIN_MAX_ATTEMPTS` / `LOGIN_DECAY_SECONDS` | 5 / 900 | 登录失败限流：同一 IP+用户名失败 N 次锁定 M 秒 |
| `TRUST_PROXY` | false | 仅当请求必经自有反向代理时开启（登录限流按 X-Forwarded-For 记 IP） |
| `LOGIN_LOG_RETENTION_DAYS` | 90 | 登录审计日志保留天数 |
| `DB_PREFIX` | `ha_` | 统一表前缀，迁移/查询/模型自动拼接；首次部署后**不要随意修改**，改了会找不到已有表 |
| `APP_PORT` | 9501 | 对外映射端口 |

---

## 二、前端部署

前端是纯静态文件，构建后交给 Nginx 托管。

```bash
cd frontend
npm ci                # 或 npm install
npm run build         # 产出 dist/
```

Nginx 配置示例（静态托管 + API 反代 + SPA 路由回退）：

```nginx
server {
    listen 80;
    server_name admin.example.com;

    # 前端静态文件
    root /opt/hyperf-admin/frontend/dist;
    index index.html;

    # Vue Router history 模式回退
    location / {
        try_files $uri $uri/ /index.html;
    }

    # 反代后端 API
    location /api/ {
        proxy_pass http://127.0.0.1:9501;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

> 后端反代部署在 Nginx 之后时，把后端环境变量 `TRUST_PROXY` 设为 `true`，登录限流才能按真实客户端 IP 统计。

上线前记得把 CORS 收紧：`.env.prod` / compose 中设置后端允许的来源（如 `CORS_ALLOW_ORIGINS=https://admin.example.com`），同域反代场景下浏览器同源，不依赖 CORS，但显式配置更稳。

---

## 三、初始账号与首次安全操作

| 账号 | 默认密码 | 说明 |
|---|---|---|
| `admin` | `admin123456` | 管理员，全部权限 |
| `demo` | `demo123456` | 演示账号，仅基础菜单 |

初始密码由 `SEED_ADMIN_PASSWORD` 环境变量控制（seeder 幂等，改了变量后重跑 `db:seed` 不会覆盖已存在的用户）。

**上线后立即：**

1. 登录 admin，改掉默认密码
2. 删除或禁用 `demo` 账号
3. 确认 `JWT_SECRET` 已换为随机值（默认空/弱值会导致 token 可伪造）

---

## 四、日常运维

```bash
# 查看状态与日志
docker compose -f docker-compose.prod.yml ps
docker compose -f docker-compose.prod.yml logs -f app
# 日志同时持久化在 app-logs 卷（容器内 /opt/www/runtime/logs）

# 手动执行迁移（RUN_MIGRATIONS=false 时）
docker compose -f docker-compose.prod.yml exec app php bin/hyperf.php migrate

# 清理 RBAC 缓存（权限改动未生效时）
docker compose -f docker-compose.prod.yml exec redis redis-cli --scan --pattern 'rbac:role:*' | \
  xargs -r docker compose -f docker-compose.prod.yml exec -T redis redis-cli del

# 重启
docker compose -f docker-compose.prod.yml restart app

# 更新版本：拉新代码后重新构建
docker compose -f docker-compose.prod.yml up -d --build
```

**数据备份**（MySQL 数据在 `mysql-data` 卷）：

```bash
docker compose -f docker-compose.prod.yml exec mysql \
  mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction hyperf > backup-$(date +%F).sql
```

**登录审计日志清理**：超过 `LOGIN_LOG_RETENTION_DAYS` 的记录不会自动删，可在宿主机 crontab 定期执行：

```bash
docker compose -f /path/to/docker-compose.prod.yml exec app php bin/hyperf.php login-log:prune
```

---

## 五、健康检查与排障

| 现象 | 排查 |
|---|---|
| 容器一直 `starting`/`unhealthy` | `docker logs <容器>` 看启动报错；常见为 DB/Redis 连不上（检查 `DB_HOST`/`REDIS_HOST` 在同一 compose 网络内可达） |
| `/api/docs` 500 | 镜像内缺 `docs/` 目录（正常镜像已包含；自构建时确认未改坏 `.dockerignore`） |
| 登录报 401/422 但账号正确 | `JWT_SECRET` 与签发时不一致，或用户被禁用（status=0 返回 403） |
| 权限改动后菜单/按钮未生效 | 清 RBAC 缓存（见上），前端刷新页面重新拉 `/api/auth/me` |
| 登录一直 429 | 触发限流，等 `LOGIN_DECAY_SECONDS` 秒或清 Redis 中限流 key |
| 构建时 buildx 报 `failed to update builder last activity` | 用 `DOCKER_BUILDKIT=0 docker build ...` |
| 接口 403 但用户是管理员 | 检查角色的权限是否被改（admin 角色的内置权限不可取消；可在角色管理里重新授权） |
| 启动日志报 `Command "migrate" is not defined` 并反复重启 | 用的是 2026-11-13 之前的旧镜像（migrate 命令当时由 dev 依赖注册，生产镜像没有），拉最新代码重建镜像即可 |
| 启动/登录报 `DomainException: Provided key is too short` | `JWT_SECRET` 少于 32 字节，换成 `openssl rand -hex 32` 生成的强密钥 |

## 六、已知限制

- MySQL 5.7 的 server 字符集为 latin1（镜像限制），已通过建库时显式 `utf8mb4` 规避，业务表无影响
- 同一 refresh token 被两个客户端并发使用会触发令牌家族失效（防重放设计，安全侧取舍），需要多端同时在线时注意
- Swoole 常驻进程模型：**修改代码/配置后必须重建或重启容器**，改完配置文件不会自动生效
