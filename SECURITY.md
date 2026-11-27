# 安全策略 / Security Policy

## 受支持版本 / Supported Versions

| 版本 / Version | 支持 / Supported |
| --- | --- |
| main 分支最新代码 | ✅ |
| 其他 tagged 版本 | ⚠️ 尽力而为 / best effort |

## 报告漏洞 / Reporting a Vulnerability

**请不要在公开 Issue、PR 或讨论区中披露安全问题。**

请通过以下方式私下报告：

1. 使用 GitHub 的 [Private vulnerability reporting](https://docs.github.com/en/code-security/security-advisories/guidance-for-reporting-security-vulnerabilities/privately-reporting-a-security-vulnerability)（仓库 Security 标签页 → Report a vulnerability）
2. 或在 Issue 中仅描述「存在安全问题，请求私下联系」，附上你的联系方式

报告时请尽量包含：

- 漏洞类型与影响范围（如：未授权访问 / SQL 注入 / XSS）
- 复现步骤或 PoC
- 影响的版本 / 提交号

## 响应承诺 / Response Commitment

- **48 小时内**确认收到
- **7 天内**给出初步评估（是否成立、严重程度）
- 修复发布前会与你同步时间线；高严重度问题将尽快出补丁版本
- 修复后在 Release Notes 中致谢（除非你希望匿名）

## 安全设计基线 / Security Baseline

本项目内置的安全机制（可作为审计参考）：

- 密码哈希（bcrypt）+ 可配置密码策略（长度/复杂度/弱口令黑名单）
- TOTP 两步验证（RFC 6238，±1 时间窗 + 90 秒重放保护）
- 登录图形验证码（一次性、Redis 故障降级放行）
- 登录限流（连续失败锁定，阈值可配置）
- 登录风控（新 IP / 陌生网段 / 异常时段评估）
- JWT 鉴权 + Redis 会话管理（支持单点踢下线）
- RBAC + 角色数据范围（all/dept/dept_and_children/self）
