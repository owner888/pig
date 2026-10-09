---
name: computer-use
description: 通过 100% 纯 PHP 原生 CoreGraphics 与 AppleScript 操控真实的 macOS 桌面应用与浏览器；新任务先通过 computer_ready 取得桌面通道与当前屏幕画面，支持 Retina 自适应点击、窗口聚焦、中文剪贴板输入、滚轮滚动与快捷键模拟。
---

# 用 Computer Use 完成电脑桌面与应用任务

通过原生 `computer_*` 工具高效操作 Mac 真实桌面系统（包括浏览器、IDE、聊天软件与日常办公应用）。

先确定用户要交付的结果和完成条件。默认把一次正常返回的动作当作已按计划执行，继续准备下一步；不要为每次点击或输入专门空调用一次观察。下一步需要页面信息时，在动作中直接设置 `observe="screenshot"` 顺带返回新画面。

## 1. 初始化与就绪检查 (READY)

在开始任何电脑任务前，先调用一次：
```json
computer_ready()
```
- 返回当前主屏幕逻辑尺寸、活动窗口前台 App（如 Chrome、Slack、Finder）、`pixel_to_point` 比率及首张真实屏幕截图。
- 如果目标 App 未在前台，调用 `computer_launch_app(target="应用名")` 将其激活并置顶。

## 2. 视觉定位与鼠标点击 (Click)

- **Retina 坐标自适应**：
  模型在截图中看到的像素点坐标 `(x, y)` 可直接传给 `computer_click(x=..., y=...)`，工具内部会自动根据返回的 `pixel_to_point` 比率精确还原为系统逻辑点，绝不点偏。
- **点击类型**：
  - 单击：`computer_click(x=..., y=..., button="left")`
  - 双击（打开文件或选词）：`computer_click(x=..., y=..., click_count=2)`
  - 右键菜单：`computer_click(x=..., y=..., button="right")`
- **合并观察**：
  点击如果会触发弹窗或页面切换，带上 `observe="screenshot"`，在单次回合中同时完成“点击 + 观测新画面”。

## 3. 拖拽与滚轮滚动 (Drag & Scroll)

- **窗口拖动或选区**：
  `computer_drag(from_x=..., from_y=..., to_x=..., to_y=..., duration_ms=400)`
- **页面平滑滚动**：
  `computer_scroll(delta_y=15)`（向下滚动浏览内容；向上滚动为负值）。

## 4. 中文与文本输入 (Type Text)

- 点击目标输入框后，调用：
  `computer_type_text(text="想要输入的内容")`
- 工具原生集成系统剪贴板通道，支持任意中文、换行和特殊标点符号，零乱码、不丢字。

## 5. 键盘按键与快捷键 (Press Key)

- **单个特殊键**：
  `computer_press_key(key="enter")`、`computer_press_key(key="tab")`、`computer_press_key(key="escape")`
- **组合快捷键**：
  - 复制：`computer_press_key(key="cmd+c")`
  - 粘贴：`computer_press_key(key="cmd+v")`
  - 全选：`computer_press_key(key="cmd+a")`
  - 保存：`computer_press_key(key="cmd+s")`
  - 切换应用：`computer_press_key(key="cmd+tab")`
  - 聚焦搜索：`computer_press_key(key="cmd+space")`

## 6. 批处理 (Batch)

已知的一系列连贯操作（如“点击搜索栏 -> 输入关键词 -> 回车”），使用：
```json
computer_batch(
  steps=[
    {"op": "click", "args": {"x": 240, "y": 180}},
    {"op": "type_text", "args": {"text": "最新财报"}},
    {"op": "press_key", "args": {"key": "enter"}}
  ],
  observe="screenshot"
)
```
工具自动注入防风控拟人微停顿与像素微抖动。
