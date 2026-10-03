FROM node:20-slim

ENV DEBIAN_FRONTEND=noninteractive
ENV PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true
ENV DISPLAY=:99
ENV DATA_DIR=/data
ENV PORT=9523
ENV HOST=0.0.0.0

# 1. 安装 Chromium 依赖、Xvfb 虚拟屏幕及中文字体库（文泉驿微米黑/正黑，避免中文乱码与方块）
RUN apt-get update && apt-get install -y --no-install-recommends \
    chromium \
    xvfb \
    fonts-wqy-microhei \
    fonts-wqy-zenhei \
    fonts-noto-cjk \
    fonts-noto-color-emoji \
    ca-certificates \
    libnss3 \
    libatk1.0-0 \
    libatk-bridge2.0-0 \
    libcups2 \
    libdrm2 \
    libxkbcommon0 \
    libxcomposite1 \
    libxdamage1 \
    libxfixes3 \
    libxrandr2 \
    libgbm1 \
    libpango-1.0-0 \
    libcairo2 \
    libasound2 \
    procps \
    curl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# 2. 复制依赖清单并安装
COPY package.json ./
RUN npm install --omit=dev

# 3. 复制服务代码与启动脚本
COPY server.js entrypoint.sh ./
RUN chmod +x entrypoint.sh

# 4. 创建持久化数据目录（挂载 cookies.json 与 browser_profile）
RUN mkdir -p /data/browser_profile

EXPOSE 9523

ENTRYPOINT ["./entrypoint.sh"]
