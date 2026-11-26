<div align="center">

# Hyperf Admin

**开箱即用的中后台管理系统** — Hyperf 3 (PHP 8.1 / Swoole) + Vue 3 + Element Plus + TypeScript

树形 RBAC（菜单权限 + 按钮权限） · JWT 双 Token 认证 · 登录审计 · 操作日志 · 定时任务 · 表单设计器 · Excel 导入导出 · 全栈 CRUD 代码生成器

[![CI](https://github.com/wywk/hyperf-admin/actions/workflows/ci.yml/badge.svg)](https://github.com/wywk/hyperf-admin/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4.svg)](https://www.php.net/)
[![Hyperf](https://img.shields.io/badge/Hyperf-3.1-3B82F6.svg)](https://www.hyperf.io/)
[![Vue](https://img.shields.io/badge/Vue-3.5-42B883.svg)](https://vuejs.org/)

[English](README_EN.md) | 简体中文

</div>

---

## 功能特性

| 模块 | 说明 |
|---|---|
| 🔐 认证 | JWT 双 Token（access 2h / refresh 7d 自动旋转）、Token 家族吊销、`jti` 黑名单、登录失败限流（Redis）、多端登录失效 |
| 🛡️ 登录安全 | 图形验证码（失败达阈值自动要求，SVG 自绘不依赖 GD）、密码策略（长度/字母+数字/不含用户名/弱密码黑名单，全部在线可配）、密码有效期提醒、TOTP 两步验证（自研 RFC 6238，支持绑定/关闭/登录二次校验与重放保护） |
| 🌳 树形 RBAC | 权限是一棵树：`menu`（菜单/目录，支持多级）+ `button`（按钮）。角色授权直接勾选权限树；登录后 `/auth/me` 返回**菜单树**（驱动侧边栏）+ **权限码**（驱动 `v-permission` 与后端接口校验） |
| 🛡️ 接口权限 | 路由层 `AuthMiddleware` 管登录，接口内 `authorize('xxx.yyy')` 管授权；权限解析带 Redis 缓存，写操作即时失效 |
| 👥 用户管理 | 账号 CRUD、分页搜索、角色分配、启停用、管理员重置密码；三道保护规则（不能停用/删除自己、不能动最后一个可用管理员、改角色/停用/重置密码自动踢下线） |
| 🏢 部门与数据权限 | 部门树（物化路径，移动自动重写子树）+ 角色的四种数据范围（全部 / 本部门 / 本部门及下级 / 仅本人）：用户列表按范围过滤，用户写操作越权直接 403，登录日志与操作日志（含导出）同样受范围约束 |
| 🖥️ 在线会话 | 登录即登记会话（IP / 浏览器 / 登录时间 / 最后活跃），支持单会话踢下线与按用户全部踢下线；改密码保留当前设备、踢掉其他设备 |
| 🔔 消息中心 | 站内消息（一人一条）：顶栏铃铛未读角标 + 最近消息面板 + 消息页（分类型/已读筛选、标记已读、清空已读）。任务连续失败、密码被重置、账号被停用、角色变更、新建账号都会自动推送，推送失败不影响主流程 |
| 📋 登录审计 | 登录/登出/失败全记录（IP、UA、事件），分页查询接口 + 管理页，保留期可配置、自带清理命令 |
| 📝 操作日志 | 登录用户写操作（POST/PUT/DELETE/PATCH）审计流水：用户、路径、参数（敏感字段自动脱敏）、状态码、耗时、IP；分页过滤 + Excel 导出（最多 5000 条） |
| ⏰ 定时任务 | cron 表达式调度（每秒扫描），任务类实现 `TaskInterface` 即接入；执行日志、手动触发、连续失败告警（stdout + 可选 webhook）、多 worker 窗口锁防重复执行 |
| 🧾 表单设计器 | 可视化设计表单（输入/数字/下拉/单选/多选/开关/日期），导出 **JSON Schema**；内置渲染引擎复用；发布后通过公开接口收集提交数据 |
| ⚙️ 代码生成器 | 选一张表 + 配字段 → 一键生成 **后端**（Model / Service / Controller / 路由块 / 校验）+ **前端**（api 模块 / 列表页 / 表单对话框）+ 自动注册菜单与按钮权限；重复生成覆盖旧代码，不破坏手写代码 |
| 📁 文件管理 | 存储驱动抽象（本地驱动开箱即用，实现 `DriverInterface` 即可接 OSS/S3/COS），上传白名单 + 大小限制 + 真实 MIME 探测，公开访问 URL、缩略图预览 |
| 📚 数据字典 | 字典类型 + 字典项两级管理，业务侧 `/api/dict/{code}` / `/api/dict/batch` 取下拉数据源（Redis 缓存，写操作即时失效） |
| 🎛️ 系统配置 | 配置项分组管理（基础 / 安全 / 审计），按 `type` 渲染动态表单，DB + Redis 缓存，保存即时生效；登录限流阈值、任务告警 webhook、日志开关与保留天数都已接入，不用改代码重启 |
| 📊 Excel 导入导出 | 通用服务（openspout 流式读写）；代码生成器为每个模块自动生成 `/export` + `/import` 端点与前端按钮：按筛选导出全量、回导模板文件，导入逐行校验并返回失败行明细 |
| 📖 接口文档 | 零依赖 OpenAPI 3.0 文档页（`/api/docs` 内置离线视图 + `/api/docs/redoc` Redoc 视图），随代码维护 |
| 🎨 界面 | 仪表盘系统统计（用户/登录/操作/任务卡片 + 近 7 日操作趋势）、深色模式（跟随系统 + 手动切换，偏好持久化） |
| 🐳 部署 | 一键部署：`docker compose` 拉起 前端(Nginx 反代) + 后端(Swoole) + MySQL + Redis，启动自动迁移/填充，镜像分层缓存 + 健康检查 |

**技术栈**：Hyperf 3.1 · Swoole · MySQL 5.7+ · Redis · Vue 3.5 · Vite 6 · Pinia · Vue Router 4 · Element Plus 2.11 · TypeScript

## 快速开始

### Docker Compose 一键开发环境（推荐）

```bash
git clone https://github.com/wywk/hyperf-admin.git
cd hyperf-admin
cp .env.example .env

# 一条命令拉起 后端 + MySQL + Redis + 前端（代码热更新）
docker compose -f docker-compose.dev.yml up -d

# 首次启动：建表 + 基础数据（等 app 容器就绪后）
docker compose -f docker-compose.dev.yml exec app php bin/hyperf.php migrate
docker compose -f docker-compose.dev.yml exec app php bin/hyperf.php db:seed
```

- 后端 API：http://127.0.0.1:9501 · 前端页面：http://127.0.0.1:5173

### 生产部署

```bash
git clone https://github.com/wywk/hyperf-admin.git
cd hyperf-admin

# 配置环境变量：填 JWT_SECRET（openssl rand -base64 48 生成）与数据库密码
cp .env.prod.example .env.prod

# 一条命令拉起 前端(Nginx) + 后端 + MySQL + Redis
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build

# 首次启动自动建表 + 填充基础数据；等 web 容器就绪后访问 http://<主机>:8000
```

- 访问入口：`http://<主机>:${WEB_PORT:-8000}`（Nginx 托管前端并反代 `/api`，无需跨域配置）
- 后端 API 直连：`http://<主机>:${APP_PORT:-9501}`（文档 `/api/docs`，可按需关闭映射）
- 默认账号 `admin`，密码为 `.env.prod` 里的 `SEED_ADMIN_PASSWORD`（请部署后立即修改）

### 本地开发

```bash
# 后端（需 PHP 8.1 + Swoole 5 + MySQL + Redis）
composer install
cp .env.example .env          # 修改数据库/Redis 连接
php bin/hyperf.php migrate    # 建表
php bin/hyperf.php db:seed    # 基础数据（权限树 / 角色 / 测试账号）
php bin/hyperf.php start      # 服务跑在 :9501

# 前端
cd frontend
npm install
npm run dev                   # 服务跑在 :5173，/api 自动代理到 :9501
```

访问 `http://127.0.0.1:5173`，默认账号：

| 账号 | 密码 | 说明 |
|---|---|---|
| `admin` | `admin123456` | 全部菜单与权限 |
| `demo` | `demo123456` | 仅仪表盘、个人中心（演示权限过滤） |

> ⚠️ 上线前请务必修改默认密码、删除 demo 账号、更换 JWT_SECRET。

## 数据库约定（统一表前缀）

所有数据表统一使用 `ha_` 前缀（`hyperf-admin` 缩写），由 `.env` 的 `DB_PREFIX` 控制：

```dotenv
DB_PREFIX=ha_
```

实际表名为 `ha_users`、`ha_permissions`、`ha_role_permissions`、`ha_login_logs`、`ha_forms`、`ha_form_submissions`、`ha_migrations`。

约定很简单，写代码时**一律不写前缀**：

| 场景 | 写法 | 实际访问 |
|---|---|---|
| 模型 | `protected ?string $table = 'users';` | `ha_users` |
| 查询构造器 | `Db::table('role_permissions')` | `ha_role_permissions` |
| 迁移 | `Schema::create('forms', ...)` | `ha_forms` |
| 原生 SQL | `SELECT ... FROM ha_users` | 手写完整表名（不走前缀） |

`config/autoload/databases.php` 里 `prefix_indexes => true` 让 Schema Builder 也应用前缀，所以迁移建表、改表都会自动带前缀。**只有原生 SQL（如 `information_schema`、`SHOW TABLES`）需要自己拼前缀**，代码生成器已内置 `bare` / `stripPrefix` 处理：列表里展示物理表名 `ha_xxx`，生成代码时自动去掉前缀，不会出现 `ha_ha_xxx`。

## 目录结构

```
├── app/
│   ├── Common/          # ApiCode / ApiResponse 统一响应
│   ├── Controller/Api/  # Auth / Permission / Role / Form / Gen 控制器
│   ├── Middleware/      # RequestId / Cors / Auth
│   ├── Model/           # User / Permission / Role / Form ...
│   ├── Service/         # 业务服务（RbacService / CodeGeneratorService ...）
│   ├── Support/         # Jwt / ClientIp 工具
│   └── Command/         # php bin/hyperf.php 命令（审计日志清理等）
├── config/
│   ├── routes.php       # 路由（代码生成器写入 [gen:xxx] 标记块）
│   └── autoload/        # jwt / cors / rbac / 数据库等配置
├── migrations/          # 数据库迁移
├── seeders/             # 种子数据（权限树 / 测试账号）
├── docs/                # OpenAPI 文档 + 部署文档
├── docker/              # entrypoint.sh
├── Dockerfile           # 生产镜像
├── docker-compose.prod.yml
└── frontend/            # Vue 3 + Element Plus 前端工程
    └── src/
        ├── api/         # 请求层（401 自动刷新重放）
        ├── components/  # FormRenderer（JSON Schema 渲染引擎）
        ├── layout/      # 后端菜单树驱动的侧边栏
        ├── router/      # 路由 + 权限守卫
        ├── stores/      # Pinia（登录态 / 权限）
        └── views/
            ├── system/  # 权限管理（树形表格）/ 角色管理（授权树）
            ├── tool/    # 表单设计器 / 代码生成器
            └── gen/     # 代码生成器产出的业务页面
```

## 权限模型

```
仪表盘 (menu)
个人中心 (menu)
系统管理 (menu 目录)
├─ 权限管理 (menu)
│   └─ 查看/新增/编辑/删除权限 (button)
├─ 用户管理 (menu)
│   └─ 查看/新增/编辑/删除/重置密码 (button)
├─ 部门管理 (menu)
│   └─ 查看/新增/编辑/删除部门 (button)
├─ 角色管理 (menu)
│   └─ 查看/新增/编辑/删除角色 + 分配权限 + 数据范围 (button)
├─ 在线会话 (menu)
│   └─ 查看会话 / 踢下线 (button)
├─ 登录审计 (menu)
│   └─ 查看审计日志 (button)
├─ 操作日志 (menu)
│   └─ 查看操作日志 (button)
├─ 数据字典 (menu)
│   └─ 查看/新增/编辑/删除字典 (button)
├─ 系统配置 (menu)
│   └─ 查看 / 修改配置 (button)
└─ 定时任务 (menu)
    └─ 查看/新增/编辑/删除/手动执行任务 (button)
```

- **菜单节点**决定「能看到哪些页面」：侧边栏由后端返回的菜单树渲染，路由守卫按菜单码拦截
- **按钮节点**决定「页面里能做什么」：前端 `v-permission` 隐藏按钮（UI 层），后端 `authorize()` 强制校验（安全层）
- 系统角色的内置权限不可被取消，防止把管理入口全部关死

## 接口文档

启动后访问 `http://127.0.0.1:9501/api/docs`（OpenAPI 3.0，定义见 `docs/openapi.json`）。

## 部署

详见 [docs/DEPLOY.md](docs/DEPLOY.md)：Compose 一键部署、镜像离线分发、外接云数据库、Nginx 配置、环境变量速查、运维命令与排障。

## 开发

```bash
# 后端语法检查
find app -name "*.php" | xargs -n1 php -l

# 测试（使用独立的 hyperf_admin_test 库，见 phpunit.xml.dist）
mysql -h <host> -u root -p -e "CREATE DATABASE IF NOT EXISTS hyperf_admin_test DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
DB_DATABASE=hyperf_admin_test php bin/hyperf.php migrate --force
DB_DATABASE=hyperf_admin_test php bin/hyperf.php db:seed --force
php test/run.php

# 前端类型检查 + 构建
cd frontend
npx vue-tsc --noEmit
npm run build
```

注意事项：

- Hyperf 常驻内存，**改完 PHP 代码需要重启进程**（开发容器 `docker restart hyperf`）才生效
- 代码生成器会把文件写入当前项目，只在开发环境使用
- 新增 Element Plus 组件需在 `frontend/src/plugins/element.ts` 注册（按需引入）

## 贡献

欢迎 Issue / PR，见 [CONTRIBUTING.md](CONTRIBUTING.md)。

## License

[MIT](LICENSE)
