# 前端生产镜像：多阶段构建（Node 打包 → Nginx 托管静态文件并反代 API）
#
# 由根目录 docker-compose.yml 的 web 服务引用，一般无需手动构建。
# 手动构建：docker build -t hyperf-admin-web:latest ./frontend

# ---------- 阶段一：打包 ----------
FROM node:20-alpine AS build

WORKDIR /app

# 先装依赖再拷源码：lock 文件不变时这一层走缓存
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY . .
# 默认 API 基址是相对路径 /api，由 Nginx 反代到后端，无需额外环境变量
RUN npm run build

# ---------- 阶段二：运行 ----------
FROM nginx:1.27-alpine

LABEL maintainer="hyperf-admin" app.name="hyperf-admin-web"

COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html

EXPOSE 80

HEALTHCHECK --interval=15s --timeout=5s --start-period=5s --retries=3 \
    CMD wget -q -O /dev/null http://127.0.0.1/ || exit 1
