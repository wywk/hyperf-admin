## 自查清单 / Checklist

- [ ] 提交信息符合 Conventional Commits（`feat:` / `fix:` / `docs:` / `refactor:` …）
- [ ] 后端改动已跑 `php test/run.php` 全量通过
- [ ] `composer analyse`（phpstan）无新增错误
- [ ] `vendor/bin/php-cs-fixer fix --dry-run` 通过
- [ ] 涉及前端时 `npx vue-tsc --noEmit` 与 `npm run build` 通过
- [ ] 新增接口已加 Swagger 注解并重导 `php bin/hyperf.php swagger:export`
- [ ] 新增表结构有 migration 与回滚；权限/菜单变更已同步 `RbacSeeder`
- [ ] 有测试覆盖新逻辑（Service 层单测或 HTTP 用例）

## 改动说明 / What does this PR do?

<!-- 简述改动内容、动机、影响面 -->

## 截图 / Screenshots

<!-- 涉及界面改动时贴前后对比图 -->
