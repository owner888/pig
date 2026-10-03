#!/usr/bin/env bash
set -e

# 启动虚拟 X 显示器 Xvfb (1920x1080x24 深度)，提供与真实桌面完全一致的显示环境
export DISPLAY=:99
Xvfb :99 -screen 0 1920x1080x24 -ac +extension GLX +render -noreset &
XVFB_PID=$!

echo "[Xvfb] Virtual display started on :99 (PID: $XVFB_PID)"

# 捕获退出信号，优雅退出
trap 'kill $XVFB_PID; exit' SIGINT SIGTERM EXIT

# 启动 Node.js 浏览器控制服务
exec node server.js
