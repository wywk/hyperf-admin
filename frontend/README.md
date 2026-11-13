# Hyperf Admin（前端脚手架）

Vue 3 + Element Plus 的管理后台脚手架，对接同项目下的 Hyperf 认证接口，已打通**登录态、自动续期、路由/菜单/按钮三级权限**。

## 快速开始

```bash
cd frontend
npm install
npm run dev          # http://127.0.0.1:5173
```

后端需要先跑起来（默认 `http://127.0.0.1:9501`）：

```bash
# 在项目根目录（hyperf-skeleton），若容器未启动
docker compose -f docker-compose.dnmp.yml up -d
```

开发环境由 Vite 代理 `/api` → `http://127.0.0.1:9501`，浏览器侧始终同源，不涉及跨域。

### 测试账号

| 账号 | 密码 | 角色 | 权限 |
|---|---|---|---|
| `admin` | `admin123456` | admin | 全部（仪表盘 / 审计 / 个人中心 / user.manage） |
| `demo` | `demo123456` | user | dashboard.view、profile.view |
| `disabled` | `disabled123456` | user | 账号禁用，登录返回 403（用于验证错误分支） |

用 `demo` 登录后，菜单里没有「登录审计」，直接访问 `/audit` 会被守卫拦到 403 —— 这就是权限链路。

## 目录结构

```
frontend/
├── src/
│   ├── api/
│   │   ├── http.ts          # axios 实例：统一解包 {code,message,data}、401 自动刷新
│   │   ├── auth.ts          # 登录 / 当前用户 / 登出
│   │   └── audit.ts         # 登录审计日志
│   ├── stores/auth.ts       # Pinia：token 之外的 user / permissions / 登录登出
│   ├── router/
│   │   ├── index.ts         # 路由实例 + 全局守卫
│   │   └── routes.ts        # 路由表（菜单也由它派生）
│   ├── layout/AppLayout.vue # 侧边菜单 + 顶栏 + 内容区
│   ├── views/               # login / dashboard / audit / profile / error
│   ├── directives/          # v-permission
│   ├── utils/               # token 存取、401 钩子
│   ├── types/               # 接口类型 + vue-router meta 扩展
│   └── styles/index.css     # 全局变量与基础样式
├── .env.development         # VITE_API_BASE_URL=/api
└── vite.config.ts           # 代理配置
```

## 登录态与自动续期

- token 存在 `localStorage`：`hf_access_token` / `hf_refresh_token` / `hf_token_expires_at`
- 请求拦截器自动加 `Authorization: Bearer`
- 响应 401（且不是登录/刷新接口）→ 用 refresh token 换新 token 并**重放原请求**
- 并发多个 401 只会发一次刷新请求（单飞队列），避免后端把 refresh 旋转作废后互相踩踏
- 刷新失败 → 清空登录态、回登录页并带 `?redirect=`，登录后跳回原页面
- 后端 `refresh` 是旋转式的：旧 refresh 用完即废；若被重复使用，后端会吊销整条凭证链，前端表现就是被踢回登录页（这是预期行为）

## 权限模型

后端 `/api/auth/me` 返回：

```json
{
  "role": "admin",
  "roles": ["admin"],
  "permissions": ["dashboard.view", "profile.view", "audit.view", "user.manage"]
}
```

前端三处使用同一个权限码：

1. **路由**：`meta.permission`，守卫里不满足就跳 `/403`
2. **菜单**：`layoutRoutes` 里 `meta.menu` + 权限过滤，没权限的菜单不渲染
3. **按钮**：`v-permission="'user.manage'"`，无权限直接移除节点

新增页面：在 `src/router/routes.ts` 的 `layoutRoutes` 加一条，写上 `meta.title / icon / permission / menu` 即可，菜单会自动出现。记得同时在后端 `config/autoload/rbac.php` 里补权限码并分配给角色。

> 前端权限只是"界面控制"，**接口必须由后端校验**，不要依赖前端隐藏来保证安全。

## 常用命令

```bash
npm run dev        # 开发
npm run typecheck  # vue-tsc 类型检查
npm run build      # 类型检查 + 产物到 dist/
npm run preview    # 预览构建产物
```

## 部署提示

`npm run build` 产物在 `frontend/dist`，是纯静态文件。两种接法：

1. nginx 托管静态文件，并 `location /api { proxy_pass http://127.0.0.1:9501; }`
2. 丢给 Hyperf 的静态目录（不推荐，Swoole 不适合发静态资源）

如果前后端不同域，改 `.env.production` 的 `VITE_API_BASE_URL`，同时把后端 `.env` 的 `CORS_ALLOW_ORIGINS` 收窄到实际域名。

## 与后端接口的对应关系

| 前端调用 | 后端接口 | 说明 |
|---|---|---|
| `loginApi` | `POST /api/auth/login` | 返回双 token + user |
| `meApi` | `GET /api/auth/me` | 刷新页面时恢复登录态 |
| `logoutApi` | `POST /api/auth/logout` | 吊销整条凭证链 |
| `fetchLoginLogs` | `GET /api/auth/login-logs` | 审计分页，需 audit.view |
| 自动刷新 | `POST /api/auth/refresh` | 401 时静默调用 |

接口文档：<http://127.0.0.1:9501/api/docs>
