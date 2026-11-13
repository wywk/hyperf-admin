<div align="center">

# Hyperf Admin

**开箱即用的中后台管理系统** — Hyperf 3 (PHP 8.1 / Swoole) + Vue 3 + Element Plus + TypeScript

树形 RBAC（菜单权限 + 按钮权限） · JWT 双 Token 认证 · 登录审计 · 表单设计器 · 全栈 CRUD 代码生成器

</div>

---

## 功能特性

| 模块 | 说明 |
|---|---|
| 🔐 认证 | JWT 双 Token（access 2h / refresh 7d 自动旋转）、Token 家族吊销、`jti` 黑名单、登录失败限流（Redis）、多端登录失效 |
| 🌳 树形 RBAC | 权限是一棵树：`menu`（菜单/目录，支持多级）+ `button`（按钮）。角色授权直接勾选权限树；登录后 `/auth/me` 返回**菜单树**（驱动侧边栏）+ **权限码**（驱动 `v-permission` 与后端接口校验） |
| 🛡️ 接口权限 | 路由层 `AuthMiddleware` 管登录，接口内 `authorize('xxx.yyy')` 管授权；权限解析带 Redis 缓存，写操作即时失效 |
| 📋 登录审计 | 登录/登出/失败全记录（IP、UA、事件），分页查询接口 + 管理页，保留期可配置、自带清理命令 |
| 🧾 表单设计器 | 可视化设计表单（输入/数字/下拉/单选/多选/开关/日期），导出 **JSON Schema**；内置渲染引擎复用；发布后通过公开接口收集提交数据 |
| ⚙️ 代码生成器 | 选一张表 + 配字段 → 一键生成 **后端**（Model / Service / Controller / 路由块 / 校验）+ **前端**（api 模块 / 列表页 / 表单对话框）+ 自动注册菜单与按钮权限；重复生成覆盖旧代码，不破坏手写代码 |
| 📖 接口文档 | 零依赖 OpenAPI 3.0 文档页（`/api/docs`），随代码维护 |
| 🐳 部署 | 生产 Dockerfile（分层缓存 / 健康检查 / 启动自动迁移）+ `docker-compose.prod.yml` 一键拉起整套服务 |

**技术栈**：Hyperf 3.1 · Swoole · MySQL 5.7+ · Redis · Vue 3.5 · Vite 6 · Pinia · Vue Router 4 · Element Plus 2.11 · TypeScript

## 快速开始

### Docker Compose（推荐）

```bash
git clone https://github.com/hyperf-admin/hyperf-admin.git
cd hyperf-admin

# 配置环境变量（JWT_SECRET 必填）
cp .env.example .env.prod
# 生成密钥：openssl rand -base64 48

docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build
# 看到 app 容器 healthy 即就绪，首次启动自动建表 + 填充基础数据
```

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
├─ 角色管理 (menu)
│   └─ 查看/新增/编辑/删除角色 + 分配权限 (button)
└─ 登录审计 (menu)
    └─ 查看审计日志 (button)
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
