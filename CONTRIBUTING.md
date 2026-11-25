# 贡献指南

感谢关注本项目！欢迎通过 Issue 反馈问题、通过 PR 提交改进。

## 环境要求

- PHP >= 8.1，Swoole >= 5.0（`swoole.use_shortname=Off`）
- MySQL >= 5.7、Redis >= 6
- Node.js >= 18（前端）

## 开发流程

1. Fork 仓库，从 `main` 拉出功能分支：`feat/xxx` 或 `fix/xxx`
2. 改动后确保以下检查通过：

```bash
# 后端：语法 + 编码规范（php-cs-fixer 配置见 .php-cs-fixer.php）
find app -name "*.php" | xargs -n1 php -l
vendor/bin/php-cs-fixer fix --dry-run --diff

# 后端：功能测试（使用独立的 hyperf_admin_test 库）
#   首次准备：
#   mysql -e "CREATE DATABASE IF NOT EXISTS hyperf_admin_test DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
#   DB_DATABASE=hyperf_admin_test php bin/hyperf.php migrate --force
#   DB_DATABASE=hyperf_admin_test php bin/hyperf.php db:seed --force
php test/run.php

# 前端：类型检查 + 生产构建
cd frontend
npx vue-tsc --noEmit
npm run build
```

3. 涉及数据库结构的改动请写成 migration（`php bin/hyperf.php gen:migration`），不要直接改库
4. 新增接口请同步更新 `docs/openapi.json`，并挂对应的权限码（`authorize()`）
5. 提交 PR，描述清楚「改了什么 / 为什么 / 怎么验证」

## 约定

- **Hyperf 常驻内存**：改完 PHP 代码必须重启进程才能生效，本地调试注意
- **权限**：新接口一律走 `AuthMiddleware` + 控制器内 `authorize('模块.动作')`；新页面在权限树里注册对应 `menu` / `button` 节点（种子数据或代码生成器）
- **响应结构**：统一 `{code, message, data}`，业务错误用 `BusinessException` + `ApiCode`，不要直接返回裸数组
- **前端组件**：Element Plus 按需引入，新组件需在 `frontend/src/plugins/element.ts` 注册；业务代码从 `@/plugins/element` 取 `ElMessage` 等
- **生成代码**：`config/routes.php` 与 `frontend/src/router/routes.ts` 里的 `[gen:xxx]` 标记块由代码生成器维护，请勿手改

## 提交信息

使用语义化前缀：`feat:` / `fix:` / `docs:` / `refactor:` / `chore:`，例如 `feat: 表单设计器支持日期范围控件`。
