import { escapeHtml, openModal } from "./utils.js";
import { applyTheme, currentTheme } from "./theme.js";

    const I18N = {
      "zh-CN": {
        "workspaces": "工作区",
        "directories": "项目目录",
        "sessions": "历史会话",
        "new_session": "新建会话",
        "search_sessions": "搜索",
        "search_sessions_title": "搜索会话 (⌘K)",
        "search_sessions_placeholder": "搜索会话标题或内容...",
        "scheduled_tasks": "定时任务",
        "scheduled_tasks_title": "定时任务",
        "sched_card_declined": "已取消",
        "sched_card_done": "已批准",
        "sched_card_confirm_btn": "确认",
        "sched_card_schedule_btn": "安排",
        "sched_card_truncated": "输入内容过长，未完整显示 — 批准前请仔细检查。",
        "sched_card_details": "详情",
        "sched_card_instructions": "指令",
        "sched_card_your_request": "您的请求",
        "sched_card_source": "来源",
        "sched_card_your_computer": "您的电脑",
        "sched_card_where": "运行位置",
        "sched_card_schedule": "时间表",
        "sched_card_task": "任务",
        "sched_card_run": "pig 想要立即运行一个计划任务",
        "sched_card_disable": "pig 想要停用一个计划任务",
        "sched_card_enable": "pig 想要启用一个计划任务",
        "sched_card_delete": "pig 想要删除一个计划任务",
        "sched_card_update": "pig 想要修改一个计划任务",
        "sched_card_create": "pig 想要创建一个计划任务",
        "sched_and": "；",
        "sched_create": "创建",
        "sched_trigger_incomplete": "有一个触发方式还没填完整。",
        "sched_advanced": "高级设置",
        "sched_minute_suffix": "分",
        "sched_minute_prefix": "第",
        "sched_remove_trigger": "移除这个触发方式",
        "sched_add_trigger": "添加触发方式",
        "sched_kind_manual_hint": "只在你点「立即运行」时运行",
        "sched_kind_once_hint": "在指定的时间运行一次",
        "sched_kind_cron_hint": "按固定的时间表重复运行",
        "sched_kind_manual": "仅手动",
        "sched_kind_once": "单次",
        "sched_kind_cron": "定时",
        "sched_trigger": "触发方式",
        "sched_use_chat_instead": "改用 pig 设置",
        "sched_edit_title": "编辑定时任务",
        "sched_new_title": "新建定时任务",
        "sched_delete_body": "任务和它的运行记录会被删除，运行写下的会话会保留。",
        "sched_delete_title": "删除定时任务「{name}」？",
        "sched_task_gone": "这个任务已经不在了。",
        "sched_banner_ask": "在此任务中，pig 遇到需要批准的操作会暂停，等你确认后再继续。",
        "sched_banner_auto": "在此任务中，pig 将直接工作，不会暂停等待批准。",
        "sched_perm_ask_hint": "需要批准时暂停，等你确认。",
        "sched_perm_auto_hint": "无需询问即可运行。",
        "sched_perm_ask": "手动",
        "sched_perm_auto": "自动",
        "sched_permission": "权限",
        "sched_repeat": "重复频率",
        "sched_instructions": "指令",
        "sched_history": "历史记录",
        "sched_off": "已停用",
        "sched_on": "已启用",
        "sched_yesterday_at": "昨天 {time}",
        "sched_tomorrow_at": "明天 {time}",
        "sched_today_at": "今天 {time}",
        "sched_next_run_label": "下次运行：{when}",
        "sched_computer_ready": "这台电脑上的 pig web 正在运行，任务会按时执行",
        "sched_needs_computer": "需要您的电脑",
        "sched_chat_prompt": "帮我设置一个定时任务。问我它应该做什么、多久运行一次以及在什么时间运行，然后创建它。",
        "sched_create_manually": "手动设置",
        "sched_create_with_chat": "与 pig 一起创建",
        "sched_no_match": "没有符合条件的任务。",
        "sched_sort_created": "创建时间",
        "sched_sort_name": "名称",
        "sched_sort_next": "下次运行",
        "sched_sort": "排序",
        "sched_filter_paused": "已停用",
        "sched_filter_enabled": "已启用",
        "sched_filter_all": "全部任务",
        "sched_filter": "筛选",
        "sched_search_placeholder": "搜索任务",
        "sched_search": "搜索",
        "sched_intro": "按时间表自动运行的提示词。每个任务有自己的会话，每次运行都接着在里面进行。",
        "sched_open_task_conversation": "打开会话",
        "sched_new": "新建任务",
        "sched_not_owner": "定时任务由同一主目录下的另一个 pig web 服务运行，这里只能查看。",
        "sched_empty": "还没有定时任务。",
        "sched_next": "下次：{when}",
        "sched_paused": "已暂停",
        "sched_enabled": "启用",
        "sched_back": "‹ 全部任务",
        "sched_run_now": "立即运行",
        "sched_edit": "编辑",
        "sched_delete": "删除",
        "sched_delete_confirm": "再点一次删除",
        "sched_frequency": "频率",
        "sched_next_run": "下次运行",
        "sched_folder": "工作目录",
        "sched_model": "模型",
        "sched_default_model": "默认模型",
        "sched_prompt": "提示词",
        "sched_runs": "运行记录",
        "sched_runs_failed": "加载运行记录失败",
        "sched_no_runs": "暂无运行记录",
        "sched_trigger_manual": "手动",
        "sched_open_conversation": "打开这次运行所在的会话",
        "sched_name": "名称",
        "sched_name_placeholder": "每日简报",
        "sched_prompt_placeholder": "提供指令，说明您希望如何完成此任务",
        "sched_thinking": "思考强度",
        "sched_default_thinking": "默认",
        "sched_freq_manual": "仅手动",
        "sched_freq_hourly": "每小时",
        "sched_freq_daily": "每天",
        "sched_freq_weekdays": "工作日",
        "sched_freq_weekly": "每周",
        "sched_freq_once": "仅一次",
        "sched_freq_custom": "自定义 (cron)",
        "sched_time": "时间",
        "sched_weekday": "星期",
        "sched_minute": "第几分钟",
        "sched_when": "日期和时间",
        "sched_cron": "cron 表达式",
        "sched_timezone": "按 {tz} 时间运行；只在 pig web 服务运行时执行，错过的运行会在恢复后补跑一次。",
        "sched_save": "保存",
        "sched_manual": "仅手动运行",
        "sched_once_at": "仅一次，{when}",
        "sched_hourly_at": "每小时第 {minute} 分",
        "sched_daily_at": "每天 {time}",
        "sched_weekdays_at": "工作日 {time}",
        "sched_weekly_at": "每{day} {time}",
        "sched_custom_desc": "cron {cron}",
        "sched_status_running": "运行中",
        "sched_status_needs_input": "等待你的回答",
        "sched_status_done": "已完成",
        "sched_status_error": "失败",
        "sched_status_skipped": "已跳过",
        "sched_started_by": "由定时任务「{name}」运行",
        "sched_unread": "有新的运行结果",
        "all_sessions": "全部会话",
        "today": "今天",
        "yesterday": "昨天",
        "view_all": "查看全部 ({count})",
        "load_more": "加载更多（剩余 {count} 条）",
        "no_results": "没有匹配的会话",
        "new_session_title": "创建新会话",
        "more_options": "更多操作",
        "menu_theme": "主题",
        "menu_lang": "语言",
        "back_workspaces": "‹ 返回工作区",
        "sessions_count": "{count} 个会话",
        "loading_folders": "正在加载工作区...",
        "failed_folders": "加载工作区失败",
        "loading_sessions": "正在加载会话...",
        "failed_sessions": "加载会话失败",
        "no_sessions": "暂无会话",
        "rename": "重命名",
        "rename_session": "重命名会话",
        "delete_session": "删除会话",
        "delete_confirm": "确定要将此会话移至废纸篓吗？",
        "delete_hint": "此操作会将对应的 .jsonl 会话文件移入系统回收站（废纸篓），以便安全恢复。",
        "rename_label": "请输入此会话的新标题：",
        "rename_placeholder": "会话标题...",
        "file_label": "文件：",
        "save": "保存",
        "cancel": "取消",
        "confirm": "确定",
        "delete": "删除",
        "terminal": "终端",
        "terminal_title": "网页终端 (Ctrl+`)",
        "toggle_sidebar": "展开/折叠侧边栏",
        "click_to_copy": "点击复制会话文件路径",
        "loading_session": "正在加载会话...",
        "copy": "复制",
        "copied": "已复制",
        "nodes": "SSH 节点",
        "nodes_title": "SSH 节点工作台",
        "add_node": "添加节点",
        "edit_node": "编辑节点",
        "node_name": "名称",
        "node_group": "分组",
        "node_host": "主机地址",
        "node_port": "端口",
        "node_user": "用户名",
        "node_auth": "认证方式",
        "node_key_path": "私钥路径",
        "node_password": "密码 / 口令",
        "node_default_dir": "默认目录",
        "node_connect": "连接",
        "node_disconnect": "断开",
        "node_connected": "已连接",
        "node_connecting": "连接中...",
        "node_trust": "信任并连接",
        "node_fingerprint_prompt": "检测到未信任的主机密钥指纹，请核对：",
        "node_sftp": "SFTP 文件",
        "node_browse": "浏览",
        "node_terminal": "SSH 终端",
        "node_import_ssh": "从 ~/.ssh/config 导入",
        "scroll_bottom": "↓ 查看新消息",
        "provider": "服务商",
        "model": "模型",
        "thinking_level": "思考等级",
        "placeholder": "向 pig 提问...",
        "attach_title": "添加图片（或直接粘贴剪贴板）",
        "send_title": "发送消息（Enter）",
        "stop_title": "停止生成（Escape）",
        "hud_title": "⌘ 快捷键速查",
        "hud_hint": "松开 ⌘ 键关闭",
        "hud_send": "发送消息 / 加入跟进队列",
        "hud_newline": "输入换行",
        "hud_enter": "发送消息 / 提交提示词",
        "hud_stop": "停止生成 / 关闭弹窗",
        "hud_clear": "清空输入 / 打断",
        "hud_tools": "展开 / 折叠工具详情",
        "hud_model": "切换模型",
        "tool_executing": "... 正在执行命令",
        "tool_reading": "正在读取文件...",
        "tool_writing": "正在写入文件...",
        "tool_searching": "正在搜索...",
        "tool_finding": "正在查找文件...",
        "tool_listing": "正在列出目录...",
        "tool_web_searching": "正在进行网络搜索...",
        "tool_fetching_page": "正在获取网页正文...",
        "tool_rendering_dom": "正在浏览器中渲染网页 DOM...",
        "tool_computer_action": "正在执行浏览器自动化操作...",
        "tool_running": "⏳ 执行中...",
        "tool_done": "✔ 完成",
        "tool_failed": "✖ 失败",
        "thinking": "思考过程...",
        "thinking_done": "已深度思考",
        "thinking_duration": "{seconds} 秒",
        "words_count": "{count} 字",
        "tool_output_copied": "已复制输出",
        "empty_welcome_title": "今天有什么我可以帮您的？",
        "empty_welcome_subtitle": "在下方输入任务，或点击快捷指令快速开始",
        "empty_chip_git": "查看 Git 状态与最近提交",
        "empty_chip_tests": "运行单元测试套件",
        "empty_chip_files": "查看项目核心文件与架构",
        "empty_chip_doctor": "诊断系统与环境状态 (/doctor)",
        "retrying_banner": "⟳ 将在 {delay} 秒后重试（第 {attempt}/{maxAttempts} 次）：{error}",
        "compacting_banner": "上下文已满 — 正在生成会话历史摘要...",
        "summarising_branch_banner": "正在生成分支摘要...",
        "model_fallback_notice": "{from} 配额已用尽 — 已切换到 {to}",
        "stopped_mid_run": "这次运行在中途停了。",
        "continue_run": "继续",
        "continue_finished": "上次运行已经结束，没有需要继续的内容。",
        "compacted_from": "已压缩原有 {count} tokens 的上下文",
        "compacted_done": "上下文已压缩",
        "branch_summary_done": "分支已生成摘要",
        "branch_summary_hook": "分支摘要由 hook 提供",
        "more_lines": "… 还有 {count} 行",
        "command_cancelled": "（已取消）",
        "command_exit": "（退出码 {code}）",
        "output_truncated": "输出已截断。完整输出：{path}",
        "operation_aborted": "操作已中止",
        "connecting": "正在连接服务器...",
        "disconnected": "与服务器断开连接，正在重连...",
        "unreachable": "无法连接到 pig：{error} — 正在重连…",
        "sidebar_extensions": "扩展",
        "panel_refresh": "刷新",
        "panel_unreachable": "无法连接 pig。",
        "panels_title": "扩展面板",
        "logout": "退出登录",
        "queue_dequeue": "撤回到输入框",
        "api_keys": "API Key",
        "what_keys": "API Key 列表",
        "keys_none_hint": "还没有可用的模型。选一个服务商，填入它的 API Key。",
        "key_placeholder": "粘贴 API Key",
        "keys_configured": "已配置",
        "keys_empty": "还没有配置任何 Key",
        "key_stored": "已保存 Key",
        "key_oauth": "已用订阅登录（在终端用 /logout 退出）",
        "key_env": "来自环境变量 {env}",
        "key_none": "未配置",
        "loading": "加载中…",
        "node_search_placeholder": "搜索节点…",
        "node_disconnected": "未连接",
        "node_new_terminal": "新建 SSH 终端",
        "node_empty_desc": "在左侧选择或添加远程 SSH 节点，连接终端或管理 SFTP 文件。",
        "node_up": "上一级",
        "node_ssh_error": "SSH 错误：{message}",
        "node_operation_failed": "操作失败",
        "node_empty_first": "点击顶部 + 添加第一台 SSH 节点",
        "node_no_match": "未找到匹配节点",
        "node_delete_confirm": "确定删除该节点吗？",
        "node_empty_dir": "空目录",
        "node_size": "大小",
        "node_save_remote": "保存写回远端",
        "node_verify_fp": "验证主机密钥指纹",
        "node_name_placeholder": "生产服务器",
        "node_auth_key": "私钥",
        "node_auth_password": "密码",
        "node_ssh_password": "SSH 密码",
        "node_passphrase": "密钥口令（可选）",
        "node_secret_keep": "（保持不变请留空）",
        "node_imported": "已发起导入 {count} 台 SSH 节点！",
        "agent_exited": "这个会话的 agent 已退出{reason}。发一条消息即可重新启动。",
        "what_command_list": "命令列表",
        "what_session_state": "会话状态",
        "what_conversation": "会话内容",
        "what_model_list": "模型列表",
        "what_server": "服务器",
        "remove_image": "移除",
        "clipboard_image": "剪贴板图片：{path}",
        "open_full_image": "点击查看大图",
        "generation_error": "生成时出错。",
        "bug_include_transcript": "在问题报告里附上会话记录吗？\n\n其中包含你的消息、模型的输出，以及本次会话读取过的所有文件和命令结果。",
        "export_pr_generating": "正在根据会话生成 Pull Request 描述…",
        "exporting": "正在导出…",
        "doctor_status": "诊断",
        "diff_status": "工作区",
        "diff_empty": "工作区没有改动。",
        "send_failed": "消息发送失败。",
        "command_running": "已有命令在运行，请先点停止。",
        "agent_unreachable": "连不上这个会话的 agent。",
        "bang_not_added": "!! — 不加入会话",
        "hook_yes": "是",
        "hook_no": "否",
        "hook_ok": "确定",
        "hook_submit": "提交",
        "hook_asks": "扩展提问",
        "hook_hint_confirm": "回车 是 · Esc 否",
        "hook_hint_select": "↑↓ 移动 · 回车 选择 · Esc 取消",
        "hook_hint_input": "回车 提交 · Esc 取消",
        "hook_hint_editor": "Ctrl+回车 提交 · Esc 取消",
        "close_tab": "关闭标签页",
        "rename_failed": "重命名失败",
        "delete_failed": "删除失败",
        "thinking_level_suffix": "思考 {level}",
        "term_new_tab": "新建终端标签页",
        "term_copy_path": "点击复制路径",
        "term_clear": "清屏 (Ctrl+L)",
        "term_fullscreen": "切换全屏",
        "close_esc": "关闭 (Esc)",
        "image_preview": "预览",
        "cwd_pick_title": "选择工作目录",
        "cwd_use": "在此目录开始",
        "cwd_path_placeholder": "输入路径，回车使用",
        "cwd_recent": "最近用过的目录",
        "cwd_no_recent": "还没有用过的目录。",
        "cwd_sessions": "{n} 个会话",
        "cwd_chip_title": "新会话的工作目录，发出第一条消息前可以更换",
        "panel_open": "打开",
        "panel_in_header": "在顶部 ⋯ 菜单",
        "panel_in_sidebar": "在侧边栏",
        "panel_move_header": "放到 ⋯ 菜单",
        "panel_move_sidebar": "放到侧边栏",
        "switch_lang": "语言",
        "switch_lang_title": "语言与语言包设置",
        "tab_new_session": "新会话",
        "in_memory": "（内存暂存）",
        "in_memory_hint": "会话尚未保存至磁盘（首轮回复后自动写入）",
        "delete_permanently": "永久删除",
        "delete_warning": "此操作无法撤销。该文件中的所有历史对话记录将被永久删除。",
        "lang_settings_title": "语言与语言包设置",
        "lang_select_label": "界面显示语言",
        "lang_zh_cn": "简体中文 (Simplified Chinese)",
        "lang_en": "English (英语)",
        "lang_custom_badge": "自定义",
        "lang_ext_badge": "扩展提供",
        "import_pack_btn": "📥 导入语言包 (.json)",
        "export_pack_btn": "📤 导出当前语言包 (.json)",
        "import_pack_hint": "支持导入 Telegram 风格的 JSON 键值语言包文件。",
        "import_success": "成功导入语言包：{name}！",
        "import_error": "导入失败：文件必须是包含有效词条的 JSON 字典对象",
        "delete_custom_lang": "删除此自定义语言包",
        "delete_custom_confirm": "确定删除自定义语言包「{name}」吗？",
        "translate_section": "翻译消息",
        "translate_do_not": "不翻译",
        "translate_entire": "翻译整个对话",
        "translate_hint": "用当前模型把回答翻译成界面语言；关闭时每条回答下有「翻译」按钮，「不翻译」里的语言原样显示。",
        "translate_btn": "翻译",
        "translate_original": "显示原文",
        "translating": "翻译中…",
        "translate_same": "已是你的语言",
        "close": "关闭",
        "theme_settings": "界面主题设置",
        "theme_select_label": "选择界面配色方案：",
        "theme_dark": "暗黑",
        "theme_dark_desc": "默认暗色主题，低对比度护眼，紫色与青色高光",
        "theme_labra": "墨绿",
        "theme_labra_desc": "来自 omarchy-labra 的极简墨绿配洋红粉高光",
        "theme_light": "浅色",
        "theme_light_desc": "优雅明亮主题，适合日间采光与高清晰度阅读",
        "theme_dark_short": "暗黑",
        "theme_labra_short": "墨绿",
        "theme_light_short": "浅色"
      },
      "en": {
        "workspaces": "Workspaces",
        "directories": "Directories",
        "sessions": "Sessions",
        "new_session": "New session",
        "search_sessions": "Search",
        "search_sessions_title": "Search sessions (⌘K)",
        "search_sessions_placeholder": "Search titles and contents...",
        "scheduled_tasks": "Scheduled",
        "scheduled_tasks_title": "Scheduled tasks",
        "sched_card_declined": "Cancelled",
        "sched_card_done": "Approved",
        "sched_card_confirm_btn": "Confirm",
        "sched_card_schedule_btn": "Schedule",
        "sched_card_truncated": "Input truncated — the full tool arguments are too long to display. Review carefully before approving.",
        "sched_card_details": "Details",
        "sched_card_instructions": "Instructions",
        "sched_card_your_request": "Your request",
        "sched_card_source": "Source",
        "sched_card_your_computer": "Your computer",
        "sched_card_where": "Runs on",
        "sched_card_schedule": "Schedule",
        "sched_card_task": "Task",
        "sched_card_run": "pig wants to run a scheduled task now",
        "sched_card_disable": "pig wants to disable a scheduled task",
        "sched_card_enable": "pig wants to enable a scheduled task",
        "sched_card_delete": "pig wants to delete a scheduled task",
        "sched_card_update": "pig wants to change a scheduled task",
        "sched_card_create": "pig wants to create a scheduled task",
        "sched_and": "; ",
        "sched_create": "Create",
        "sched_trigger_incomplete": "A trigger is not filled in yet.",
        "sched_advanced": "Advanced settings",
        "sched_minute_suffix": "",
        "sched_minute_prefix": "at minute",
        "sched_remove_trigger": "Remove this trigger",
        "sched_add_trigger": "Add a trigger",
        "sched_kind_manual_hint": "Runs only when you press Run now",
        "sched_kind_once_hint": "Runs once at a given time",
        "sched_kind_cron_hint": "Repeats on a fixed schedule",
        "sched_kind_manual": "Manual only",
        "sched_kind_once": "Once",
        "sched_kind_cron": "Schedule",
        "sched_trigger": "Trigger",
        "sched_use_chat_instead": "Set up with pig instead",
        "sched_edit_title": "Edit scheduled task",
        "sched_new_title": "New scheduled task",
        "sched_delete_body": "The task and the record of its runs are removed. Conversations its runs wrote are kept.",
        "sched_delete_title": "Delete scheduled task “{name}”?",
        "sched_task_gone": "This task is no longer there.",
        "sched_banner_ask": "In this task, pig pauses at anything that needs approval and carries on once you answer.",
        "sched_banner_auto": "In this task, pig works directly and does not pause to wait for approval.",
        "sched_perm_ask_hint": "Pauses for approval until you answer.",
        "sched_perm_auto_hint": "Runs without asking.",
        "sched_perm_ask": "Manual",
        "sched_perm_auto": "Auto",
        "sched_permission": "Permissions",
        "sched_repeat": "Repeats",
        "sched_instructions": "Instructions",
        "sched_history": "History",
        "sched_off": "Disabled",
        "sched_on": "Enabled",
        "sched_yesterday_at": "Yesterday {time}",
        "sched_tomorrow_at": "Tomorrow {time}",
        "sched_today_at": "Today {time}",
        "sched_next_run_label": "Next run: {when}",
        "sched_computer_ready": "pig web is running on this computer; tasks run on time",
        "sched_needs_computer": "Needs your computer",
        "sched_chat_prompt": "Help me set up a scheduled task. Ask me what it should do, how often it should run and at what time, then create it.",
        "sched_create_manually": "Set up manually",
        "sched_create_with_chat": "Create with pig",
        "sched_no_match": "No tasks match.",
        "sched_sort_created": "Date created",
        "sched_sort_name": "Name",
        "sched_sort_next": "Next run",
        "sched_sort": "Sort",
        "sched_filter_paused": "Disabled",
        "sched_filter_enabled": "Enabled",
        "sched_filter_all": "All tasks",
        "sched_filter": "Filter",
        "sched_search_placeholder": "Search tasks",
        "sched_search": "Search",
        "sched_intro": "Prompts that run on a schedule. Each task has a conversation of its own, and every run carries on in it.",
        "sched_open_task_conversation": "Open conversation",
        "sched_new": "New task",
        "sched_not_owner": "Another pig web server in this home runs the scheduled tasks; they can only be viewed here.",
        "sched_empty": "No scheduled tasks yet.",
        "sched_next": "Next: {when}",
        "sched_paused": "Paused",
        "sched_enabled": "Enabled",
        "sched_back": "‹ All tasks",
        "sched_run_now": "Run now",
        "sched_edit": "Edit",
        "sched_delete": "Delete",
        "sched_delete_confirm": "Click again to delete",
        "sched_frequency": "Frequency",
        "sched_next_run": "Next run",
        "sched_folder": "Folder",
        "sched_model": "Model",
        "sched_default_model": "Default model",
        "sched_prompt": "Prompt",
        "sched_runs": "Runs",
        "sched_runs_failed": "Failed to load the runs",
        "sched_no_runs": "No runs yet",
        "sched_trigger_manual": "manual",
        "sched_open_conversation": "Open the conversation this run is in",
        "sched_name": "Name",
        "sched_name_placeholder": "Daily brief",
        "sched_prompt_placeholder": "Give instructions for how this task should be done",
        "sched_thinking": "Thinking",
        "sched_default_thinking": "Default",
        "sched_freq_manual": "Manual only",
        "sched_freq_hourly": "Hourly",
        "sched_freq_daily": "Daily",
        "sched_freq_weekdays": "Weekdays",
        "sched_freq_weekly": "Weekly",
        "sched_freq_once": "Once",
        "sched_freq_custom": "Custom (cron)",
        "sched_time": "Time",
        "sched_weekday": "Day",
        "sched_minute": "Minute",
        "sched_when": "Date and time",
        "sched_cron": "Cron expression",
        "sched_timezone": "Runs in {tz} time, only while the pig web server is running. A missed run happens once when it is back.",
        "sched_save": "Save",
        "sched_manual": "Runs only when asked",
        "sched_once_at": "Once, {when}",
        "sched_hourly_at": "Every hour at :{minute}",
        "sched_daily_at": "Every day at {time}",
        "sched_weekdays_at": "Weekdays at {time}",
        "sched_weekly_at": "Every {day} at {time}",
        "sched_custom_desc": "cron {cron}",
        "sched_status_running": "Running",
        "sched_status_needs_input": "Waiting for your answer",
        "sched_status_done": "Done",
        "sched_status_error": "Failed",
        "sched_status_skipped": "Skipped",
        "sched_started_by": "Started by the scheduled task “{name}”",
        "sched_unread": "New results",
        "all_sessions": "All sessions",
        "today": "Today",
        "yesterday": "Yesterday",
        "view_all": "View all ({count})",
        "load_more": "Load more ({count} left)",
        "no_results": "No matching sessions",
        "new_session_title": "Create a new session",
        "more_options": "More options",
        "menu_theme": "Theme",
        "menu_lang": "Language",
        "back_workspaces": "‹ Workspaces",
        "sessions_count": "{count} sessions",
        "loading_folders": "Loading workspaces...",
        "failed_folders": "Failed to load folders",
        "loading_sessions": "Loading sessions...",
        "failed_sessions": "Failed to load sessions",
        "no_sessions": "No sessions yet",
        "rename": "Rename",
        "rename_session": "Rename Session",
        "delete_session": "Delete Session",
        "delete_confirm": "Are you sure you want to move this session to Trash?",
        "delete_hint": "This will move the session file to your system Trash / Recycle Bin for safe recovery.",
        "rename_label": "Enter a new title for this session:",
        "rename_placeholder": "Session title...",
        "file_label": "File: ",
        "save": "Save",
        "cancel": "Cancel",
        "confirm": "Confirm",
        "delete": "Delete",
        "terminal": "Terminal",
        "terminal_title": "Web Terminal (Ctrl+`)",
        "toggle_sidebar": "Toggle Sidebar",
        "click_to_copy": "Click to copy session file path",
        "loading_session": "Loading session...",
        "copy": "Copy",
        "copied": "Copied!",
        "nodes": "SSH Nodes",
        "nodes_title": "SSH Node Workbench",
        "add_node": "Add Node",
        "edit_node": "Edit Node",
        "node_name": "Name",
        "node_group": "Group",
        "node_host": "Host",
        "node_port": "Port",
        "node_user": "Username",
        "node_auth": "Auth Method",
        "node_key_path": "Private Key Path",
        "node_password": "Password / Passphrase",
        "node_default_dir": "Default Directory",
        "node_connect": "Connect",
        "node_disconnect": "Disconnect",
        "node_connected": "Connected",
        "node_connecting": "Connecting...",
        "node_trust": "Trust & Connect",
        "node_fingerprint_prompt": "Untrusted host key fingerprint detected, please verify:",
        "node_sftp": "SFTP Files",
        "node_browse": "Browse",
        "node_terminal": "SSH Terminal",
        "node_import_ssh": "Import from ~/.ssh/config",
        "scroll_bottom": "↓ New messages",
        "provider": "Provider",
        "model": "Model",
        "thinking_level": "Thinking Level",
        "placeholder": "Ask pig a question",
        "attach_title": "Attach image (or paste clipboard)",
        "send_title": "Send message (Enter)",
        "stop_title": "Stop (Escape)",
        "hud_title": "⌘ Shortcuts",
        "hud_hint": "Release ⌘ to close",
        "hud_send": "Send message / Follow-up queue",
        "hud_newline": "Insert newline",
        "hud_enter": "Send message / Submit prompt",
        "hud_stop": "Stop turn / Close dialog",
        "hud_clear": "Clear prompt / Interrupt",
        "hud_tools": "Expand / collapse tools",
        "hud_model": "Model selector",
        "tool_executing": "... executing command",
        "tool_reading": "Reading file...",
        "tool_writing": "Writing to file...",
        "tool_searching": "Searching...",
        "tool_finding": "Finding files...",
        "tool_listing": "Listing directory...",
        "tool_web_searching": "Searching web...",
        "tool_fetching_page": "Fetching web page...",
        "tool_rendering_dom": "Rendering live DOM in browser...",
        "tool_computer_action": "Executing browser action...",
        "tool_running": "⏳ Running...",
        "tool_done": "✔ Done",
        "tool_failed": "✖ Failed",
        "thinking": "Thinking...",
        "thinking_done": "Thought",
        "thinking_duration": "{seconds}s",
        "words_count": "{count} words",
        "tool_output_copied": "Output copied",
        "empty_welcome_title": "How can I help you today?",
        "empty_welcome_subtitle": "Type a prompt below or pick a quick start option",
        "empty_chip_git": "Check Git status and recent commits",
        "empty_chip_tests": "Run test suite",
        "empty_chip_files": "Review project structure and core files",
        "empty_chip_doctor": "Diagnose system status (/doctor)",
        "retrying_banner": "⟳ Retrying in {delay}s (attempt {attempt}/{maxAttempts}): {error}",
        "compacting_banner": "Context is full — compacting conversation summary...",
        "summarising_branch_banner": "Summarizing branch...",
        "model_fallback_notice": "Quota reached on {from} — model: {to}",
        "stopped_mid_run": "This conversation stopped in the middle of a run.",
        "continue_run": "Continue",
        "continue_finished": "The last run had finished; there is nothing to carry on.",
        "compacted_from": "Compacted from {count} tokens",
        "compacted_done": "Context compacted",
        "branch_summary_done": "Branch summarised",
        "branch_summary_hook": "Branch summary provided by a hook",
        "more_lines": "… {count} more lines",
        "command_cancelled": "(cancelled)",
        "command_exit": "(exit {code})",
        "output_truncated": "Output truncated. Full output: {path}",
        "operation_aborted": "Operation aborted",
        "connecting": "Connecting to server...",
        "disconnected": "Disconnected from server. Retrying...",
        "unreachable": "Cannot reach pig: {error} — retrying…",
        "sidebar_extensions": "Extensions",
        "panel_refresh": "Refresh",
        "panel_unreachable": "Could not reach pig.",
        "panels_title": "Extension panels",
        "logout": "Log out",
        "queue_dequeue": "Edit queued messages",
        "api_keys": "API Keys",
        "what_keys": "the API key list",
        "keys_none_hint": "No model is available yet. Pick a provider and paste its API key.",
        "key_placeholder": "Paste the API key",
        "keys_configured": "Configured",
        "keys_empty": "No key is configured yet",
        "key_stored": "Key saved",
        "key_oauth": "Signed in with a subscription (/logout in the terminal to sign out)",
        "key_env": "From the environment: {env}",
        "key_none": "Not configured",
        "loading": "Loading…",
        "node_search_placeholder": "Search nodes…",
        "node_disconnected": "Not connected",
        "node_new_terminal": "New SSH terminal",
        "node_empty_desc": "Pick or add a remote SSH node on the left to open a terminal or manage files over SFTP.",
        "node_up": "Up one level",
        "node_ssh_error": "SSH error: {message}",
        "node_operation_failed": "Operation failed",
        "node_empty_first": "Click + above to add your first SSH node",
        "node_no_match": "No matching nodes",
        "node_delete_confirm": "Delete this node?",
        "node_empty_dir": "Empty directory",
        "node_size": "Size",
        "node_save_remote": "Save to remote",
        "node_verify_fp": "Verify the host key fingerprint",
        "node_name_placeholder": "Production server",
        "node_auth_key": "Private key",
        "node_auth_password": "Password",
        "node_ssh_password": "SSH password",
        "node_passphrase": "Key passphrase (optional)",
        "node_secret_keep": "(leave empty to keep it)",
        "node_imported": "Importing {count} SSH nodes.",
        "agent_exited": "The agent for this conversation exited{reason}. Send a message to start it again.",
        "what_command_list": "the command list",
        "what_session_state": "the session state",
        "what_conversation": "the conversation",
        "what_model_list": "the model list",
        "what_server": "the server",
        "remove_image": "Remove",
        "clipboard_image": "Clipboard image: {path}",
        "open_full_image": "Click to view full size",
        "generation_error": "An error occurred during generation.",
        "bug_include_transcript": "Include the session transcript in the bug report?\n\nIt holds your messages, the model's output, and every file and command result read this session.",
        "export_pr_generating": "Generating Pull Request description from session…",
        "exporting": "Exporting…",
        "doctor_status": "diagnostics",
        "diff_status": "working tree",
        "diff_empty": "No git changes in working tree.",
        "send_failed": "Failed to send message.",
        "command_running": "A command is already running. Press Stop first.",
        "agent_unreachable": "Could not reach the agent for this conversation.",
        "bang_not_added": "!! — not added to the conversation",
        "hook_yes": "Yes",
        "hook_no": "No",
        "hook_ok": "OK",
        "hook_submit": "Submit",
        "hook_asks": "A hook asks",
        "hook_hint_confirm": "enter yes · esc no",
        "hook_hint_select": "↑↓ move · enter choose · esc cancel",
        "hook_hint_input": "enter submit · esc cancel",
        "hook_hint_editor": "ctrl+enter submit · esc cancel",
        "close_tab": "Close tab",
        "rename_failed": "Failed to rename session",
        "delete_failed": "Failed to delete session",
        "thinking_level_suffix": "thinking {level}",
        "term_new_tab": "New terminal tab",
        "term_copy_path": "Click to copy path",
        "term_clear": "Clear (Ctrl+L)",
        "term_fullscreen": "Toggle fullscreen",
        "close_esc": "Close (Esc)",
        "image_preview": "Preview",
        "cwd_pick_title": "Choose a working directory",
        "cwd_use": "Start here",
        "cwd_path_placeholder": "Type a path, Enter to use it",
        "cwd_recent": "Recent folders",
        "cwd_no_recent": "No folders used yet.",
        "cwd_sessions": "{n} sessions",
        "cwd_chip_title": "Where this new conversation runs — change it before the first message",
        "panel_open": "Open",
        "panel_in_header": "In the ⋯ menu",
        "panel_in_sidebar": "In the sidebar",
        "panel_move_header": "Move to ⋯ menu",
        "panel_move_sidebar": "Move to sidebar",
        "switch_lang": "Language",
        "switch_lang_title": "Language & Language Pack Settings",
        "tab_new_session": "new session",
        "in_memory": "(in-memory)",
        "in_memory_hint": "Session not yet saved to disk (will save on first turn)",
        "delete_permanently": "Delete Permanently",
        "delete_warning": "This action cannot be undone. All conversation records in this file will be permanently removed.",
        "lang_settings_title": "Language & Language Packs",
        "lang_select_label": "Display Language",
        "lang_zh_cn": "简体中文 (Simplified Chinese)",
        "lang_en": "English",
        "lang_custom_badge": "Custom",
        "lang_ext_badge": "Extension",
        "import_pack_btn": "📥 Import Language Pack (.json)",
        "export_pack_btn": "📤 Export Current Pack (.json)",
        "import_pack_hint": "Supports importing Telegram-style key-value JSON language packs.",
        "import_success": "Successfully imported language pack: {name}!",
        "import_error": "Failed to import: file is not a valid JSON dictionary",
        "delete_custom_lang": "Delete this custom language pack",
        "delete_custom_confirm": "Delete custom language pack '{name}'?",
        "translate_section": "Translate messages",
        "translate_do_not": "Do not translate",
        "translate_entire": "Translate entire chats",
        "translate_hint": "Answers are translated into the display language by the current model; off, each answer has a Translate button. A language under \"Do not translate\" is shown as it is.",
        "translate_btn": "Translate",
        "translate_original": "Show original",
        "translating": "Translating…",
        "translate_same": "Already in your language",
        "close": "Close",
        "theme_settings": "Theme Settings",
        "theme_select_label": "Select interface color scheme:",
        "theme_dark": "Dark",
        "theme_dark_desc": "Default dark theme with balanced contrast, violet & teal accents",
        "theme_labra": "Labra",
        "theme_labra_desc": "Minimalist deep green-black background with vibrant hot pink highlights",
        "theme_light": "Light",
        "theme_light_desc": "Clean and bright theme for daylight and high-contrast reading",
        "theme_dark_short": "Dark",
        "theme_labra_short": "Labra",
        "theme_light_short": "Light"
      }
    };

    // ---- Locale & Language Pack Management (Telegram-inspired) -----------------------------
    let customLocales = {};
    try {
      customLocales = JSON.parse(localStorage.getItem("pig_web_custom_locales") || "{}");
      for (const [loc, pack] of Object.entries(customLocales)) {
        if (pack && pack.translations) {
          I18N[loc] = { ...(I18N["en"] || {}), ...pack.translations };
        }
      }
    } catch (e) { customLocales = {}; }

    let extensionLocales = {};
    async function loadExtensionLocales() {
      try {
        const res = await fetch("/api/locales");
        const data = await res.json();
        if (data.success && data.locales) {
          extensionLocales = data.locales;
          for (const [loc, pack] of Object.entries(extensionLocales)) {
            if (pack && pack.translations) {
              I18N[loc] = { ...(I18N["en"] || {}), ...pack.translations };
            }
          }
          applyI18n();
        }
      } catch (e) {}
    }

    let currentLocale = localStorage.getItem("pig_web_locale");
    if (!currentLocale) {
      currentLocale = (navigator.language && navigator.language.startsWith("zh")) ? "zh-CN" : "en";
    }

    // ---- Translate messages (Telegram's setting) ---------------------------------------------
    //
    // The target is the display language; `doNotTranslate` are the languages the reader does not
    // need translated — the display language unless they chose otherwise. The model decides what
    // language a message is in (`Translation.php`), so these are names it reads, not codes.
    const TRANSLATE_LANGUAGES = [
      ["en", "English"], ["zh-CN", "简体中文"], ["zh-TW", "繁體中文"], ["ja", "日本語"], ["ko", "한국어"],
      ["fr", "Français"], ["de", "Deutsch"], ["es", "Español"], ["pt", "Português"], ["ru", "Русский"],
      ["it", "Italiano"], ["vi", "Tiếng Việt"], ["th", "ไทย"], ["id", "Bahasa Indonesia"], ["ar", "العربية"],
    ];
    const translateSettings = { entireChats: false, doNotTranslate: null };
    try { Object.assign(translateSettings, JSON.parse(localStorage.getItem("pig_web_translate") || "{}")); } catch (e) {}
    function saveTranslateSettings() {
      localStorage.setItem("pig_web_translate", JSON.stringify(translateSettings));
    }
    /** The language's name as the model reads it: the list's, a custom pack's label, else the code. */
    function languageName(code) {
      return TRANSLATE_LANGUAGES.find(([c]) => c === code)?.[1] ?? customLocales[code]?.label ?? extensionLocales[code]?.label ?? code;
    }
    /**
     * The script a language is written in, for `needsTranslation()`. Latin covers more than
     * English, so English text in French clothing is taken for English; a wrong guess there costs
     * one untranslated message, the right one saves a model call for every message in the
     * reader's own language, which is most of them.
     */
    const SCRIPTS = { han: /\p{Script=Han}/u, kana: /[\p{Script=Hiragana}\p{Script=Katakana}]/u, hangul: /\p{Script=Hangul}/u, cyrillic: /\p{Script=Cyrillic}/u, thai: /\p{Script=Thai}/u, arabic: /\p{Script=Arabic}/u, latin: /\p{Script=Latin}/u };
    const SCRIPT_OF = { zh: "han", ja: "kana", ko: "hangul", ru: "cyrillic", uk: "cyrillic", th: "thai", ar: "arabic", fa: "arabic", en: "latin", fr: "latin", de: "latin", es: "latin", pt: "latin", it: "latin", vi: "latin", id: "latin", nl: "latin", pl: "latin", tr: "latin" };
    /** By the code's language subtag, so a language pack's `ja-JP` or `zh-HK` is placed too. */
    const scriptOf = (code) => SCRIPT_OF[code] ?? SCRIPT_OF[code.split("-")[0].toLowerCase()];
    /** The languages "Do not translate" offers: the list, then any language pack not already in it. */
    function translateChoices() {
      const choices = [...TRANSLATE_LANGUAGES];
      for (const code of [...Object.keys(extensionLocales), ...Object.keys(customLocales)]) {
        if (!choices.some(([c]) => c === code)) choices.push([code, languageName(code)]);
      }
      return choices;
    }
    /**
     * Whether `text` is in a language the reader needs translated, as far as its script says —
     * false when most of its letters are in the script of a "Do not translate" language (Japanese
     * is kana over han, since kana are its own). True when the script decides nothing, and the
     * model answers `SAME` or not.
     */
    function needsTranslation(text) {
      const skip = new Set((translateSettings.doNotTranslate ?? [currentLocale]).map(scriptOf).filter(Boolean));
      if (skip.size === 0) return true;
      const counts = {};
      // Code is not prose: an identifier-heavy Chinese answer is still Chinese.
      for (const ch of text.replace(/```[\s\S]*?```|`[^`\n]*`/g, "")) {
        for (const [script, re] of Object.entries(SCRIPTS)) {
          if (re.test(ch)) { counts[script] = (counts[script] || 0) + 1; break; }
        }
      }
      if (counts.kana) { counts.kana += counts.han || 0; delete counts.han; }
      const dominant = Object.entries(counts).sort((a, b) => b[1] - a[1])[0]?.[0];
      return dominant === undefined || !skip.has(dominant);
    }
    /** `{language, doNotTranslate}` for a `translate` command, in the display language of the moment. */
    function translateRequest() {
      const codes = translateSettings.doNotTranslate ?? [currentLocale];
      return { language: languageName(currentLocale), doNotTranslate: codes.map(languageName) };
    }

    function __t(key, params = {}) {
      const dict = I18N[currentLocale] || I18N["zh-CN"] || {};
      let str = dict[key] ?? (I18N["en"]?.[key] ?? key);
      for (const [k, v] of Object.entries(params)) {
        str = str.replaceAll(`{${k}}`, v);
      }
      return str;
    }
    const t = __t;

    const localeListeners = new Set();
    function onLocaleChanged(cb) {
      localeListeners.add(cb);
      return () => localeListeners.delete(cb);
    }

    function setLocale(lang) {
      if (!I18N[lang]) lang = "zh-CN";
      currentLocale = lang;
      localStorage.setItem("pig_web_locale", lang);
      document.documentElement.lang = lang;
      applyI18n();
      for (const cb of localeListeners) {
        try { cb(lang); } catch (e) {}
      }
    }

    /**
     * Translate the `data-i18n` / `data-i18n-attr` elements under `root` — the whole page, or a
     * component's own markup once it has built it (`NodeWorkbench`), which the page-wide pass at
     * startup ran before.
     */
    /** One element's `data-i18n` text and `data-i18n-attr` attributes. */
    function translateElement(el) {
      if (el.dataset?.i18n) el.textContent = t(el.dataset.i18n);
      if (el.dataset?.i18nAttr) {
        for (const pair of el.dataset.i18nAttr.split(",")) {
          const [attr, key] = pair.split(":");
          if (attr && key) el.setAttribute(attr.trim(), t(key.trim()));
        }
      }
    }

    function applyI18n(root = document) {
      if (root !== document) {
        for (const el of [root, ...root.querySelectorAll("[data-i18n], [data-i18n-attr]")]) translateElement(el);
        return;
      }
      // 1. Text content
      document.querySelectorAll("[data-i18n]").forEach(el => {
        const key = el.dataset.i18n;
        if (key) el.textContent = t(key);
      });

      // 2. Attributes
      document.querySelectorAll("[data-i18n-attr]").forEach(el => {
        const pairs = el.dataset.i18nAttr.split(",");
        for (const pair of pairs) {
          const [attr, key] = pair.split(":");
          if (attr && key) {
            el.setAttribute(attr.trim(), t(key.trim()));
          }
        }
      });

      // 3. Update lang switch button
      const langBtn = document.getElementById("lang-btn");
      if (langBtn) {
        langBtn.textContent = `🌐 ${t("switch_lang")}`;
        langBtn.title = t("switch_lang_title");
      }

      // 4. Update sidebar subtitle
      const sub = document.getElementById("sidebar-subtitle");
      if (sub && (typeof window !== "undefined" && (window.__currentView === "workspaces" || !window.__currentView))) {
        sub.textContent = t("directories");
      }

      // 5. Update theme switch button label
      try {
        applyTheme(currentTheme);
      } catch (e) {}
    }

    /** Export active locale translations as a Telegram-style JSON language pack */
    function exportLanguagePack() {
      const activeDict = I18N[currentLocale] || I18N["zh-CN"];
      const label = currentLocale === "zh-CN"
        ? "简体中文"
        : (currentLocale === "en" ? "English" : (customLocales[currentLocale]?.label || currentLocale));

      const payload = {
        _meta: {
          locale: currentLocale,
          label: label,
          author: "pig user",
          version: "1.0.0",
          exported_at: new Date().toISOString()
        },
        ...activeDict
      };

      const blob = new Blob([JSON.stringify(payload, null, 2)], { type: "application/json" });
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = `pig-locale-${currentLocale}.json`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
    }

    /** Import a Telegram-style JSON language pack file and apply immediately */
    function importLanguagePackFile(file) {
      if (!file) return;
      const reader = new FileReader();
      reader.onload = (e) => {
        try {
          const data = JSON.parse(e.target.result);
          if (!data || typeof data !== "object" || Array.isArray(data)) {
            alert(t("import_error"));
            return;
          }

          let locale = data._meta?.locale;
          let label = data._meta?.label;

          // If no _meta, derive from filename e.g. pig-locale-ja.json -> ja
          if (!locale) {
            const m = file.name.match(/(?:locale-)?([a-zA-Z0-9_-]+)\.json$/i);
            locale = m ? m[1] : "custom-" + Date.now();
          }
          if (!label) label = locale;

          const translations = {};
          for (const [k, v] of Object.entries(data)) {
            if (k !== "_meta" && typeof v === "string") {
              translations[k] = v;
            }
          }

          if (Object.keys(translations).length === 0) {
            alert(t("import_error"));
            return;
          }

          customLocales[locale] = { label, translations };
          localStorage.setItem("pig_web_custom_locales", JSON.stringify(customLocales));

          // Merge into live I18N
          I18N[locale] = { ...(I18N["en"] || {}), ...translations };

          setLocale(locale);
          alert(t("import_success", { name: label }));
        } catch (err) {
          alert(t("import_error") + ": " + err.message);
        }
      };
      reader.readAsText(file);
    }

    /** Telegram-style Language & Language Packs Management Modal */
    function openLanguageModal() {
      const renderModalBody = () => {
        const doNot = translateSettings.doNotTranslate ?? [currentLocale];
        let html = `
          <div style="font-size:12px; color:var(--color-text-secondary); margin-bottom:10px; font-weight:600;">
            ${escapeHtml(t("translate_section"))}
          </div>
          <div id="translate-do-not" class="lang-item-row">
            <span>${escapeHtml(t("translate_do_not"))}</span>
            <span style="color:var(--color-dim); font-size:12px;">${escapeHtml(doNot.map(languageName).join(", "))} ›</span>
          </div>
          <div id="translate-do-not-list" style="display:none; flex-wrap:wrap; gap:4px 14px; padding:4px 14px 12px;">
            ${translateChoices().map(([code, name]) => `
              <label style="display:flex; align-items:center; gap:6px; font-size:13px; width:46%; cursor:pointer;">
                <input type="checkbox" class="sched-toggle" data-code="${escapeHtml(code)}" ${doNot.includes(code) ? "checked" : ""}>${escapeHtml(name)}
              </label>`).join("")}
          </div>
          <label class="lang-item-row" style="cursor:pointer;">
            <span>${escapeHtml(t("translate_entire"))}</span>
            <input type="checkbox" class="sched-toggle" id="translate-entire" ${translateSettings.entireChats ? "checked" : ""}>
          </label>
          <div style="font-size:11px; color:var(--color-dim); line-height:1.4; margin-bottom:18px;">
            ${escapeHtml(t("translate_hint"))}
          </div>
          <div style="font-size:12px; color:var(--color-text-secondary); margin-bottom:10px; font-weight:600;">
            ${escapeHtml(t("lang_select_label"))}
          </div>
          <div id="lang-list-container" style="display:flex; flex-direction:column; gap:6px; margin-bottom:18px;">
            <div class="lang-item-row ${currentLocale === 'zh-CN' ? 'active' : ''}" data-loc="zh-CN">
              <span>${escapeHtml(t("lang_zh_cn"))}</span>
              ${currentLocale === 'zh-CN' ? '<span>✓</span>' : ''}
            </div>
            <div class="lang-item-row ${currentLocale === 'en' ? 'active' : ''}" data-loc="en">
              <span>${escapeHtml(t("lang_en"))}</span>
              ${currentLocale === 'en' ? '<span>✓</span>' : ''}
            </div>
        `;

        // Render extension locales
        for (const [loc, pack] of Object.entries(extensionLocales)) {
          if (loc === "zh-CN" || loc === "en") continue;
          const isActive = currentLocale === loc;
          html += `
            <div class="lang-item-row ${isActive ? 'active' : ''}" data-loc="${escapeHtml(loc)}">
              <span>${escapeHtml(pack.label || loc)}</span>
              <div style="display:flex; gap:6px; align-items:center;">
                <span class="lang-badge">${escapeHtml(t("lang_ext_badge"))}</span>
                ${isActive ? '<span>✓</span>' : ''}
              </div>
            </div>
          `;
        }

        // Render custom imported locales
        for (const [loc, pack] of Object.entries(customLocales)) {
          if (loc === "zh-CN" || loc === "en") continue;
          const isActive = currentLocale === loc;
          html += `
            <div class="lang-item-row ${isActive ? 'active' : ''}" data-loc="${escapeHtml(loc)}">
              <span>${escapeHtml(pack.label || loc)}</span>
              <div style="display:flex; gap:6px; align-items:center;">
                <span class="lang-badge">${escapeHtml(t("lang_custom_badge"))}</span>
                <button class="mini-btn danger del-custom-lang" data-del="${escapeHtml(loc)}" title="${escapeHtml(t("delete_custom_lang"))}" style="padding:1px 5px; font-size:11px;">×</button>
                ${isActive ? '<span>✓</span>' : ''}
              </div>
            </div>
          `;
        }

        html += `
          </div>
          <div style="border-top:1px solid var(--color-border); padding-top:14px; display:flex; flex-direction:column; gap:10px;">
            <div style="font-size:11px; color:var(--color-dim); line-height:1.4;">
              ${escapeHtml(t("import_pack_hint"))}
            </div>
            <div style="display:flex; gap:8px;">
              <input type="file" id="lang-pack-input" accept=".json" style="display:none;">
              <button id="import-pack-btn" class="mini-btn" style="flex:1; padding:8px 12px; font-size:12px; font-weight:600;">
                ${escapeHtml(t("import_pack_btn"))}
              </button>
              <button id="export-pack-btn" class="mini-btn" style="flex:1; padding:8px 12px; font-size:12px; font-weight:600;">
                ${escapeHtml(t("export_pack_btn"))}
              </button>
            </div>
          </div>
        `;
        return html;
      };

      const modalInstance = openModal({
        title: t("lang_settings_title"),
        bodyHtml: renderModalBody(),
        hideFooter: true,
        width: "420px",
        onOpen: (modalEl, closeFn) => {
          const bindEvents = () => {
            const doNotRow = modalEl.querySelector("#translate-do-not");
            const doNotList = modalEl.querySelector("#translate-do-not-list");
            doNotRow.addEventListener("click", () => {
              const open = doNotList.style.display === "none";
              doNotList.style.display = open ? "flex" : "none";
            });
            doNotList.querySelectorAll("input[data-code]").forEach(box => {
              box.addEventListener("change", () => {
                translateSettings.doNotTranslate = [...doNotList.querySelectorAll("input[data-code]:checked")].map(b => b.dataset.code);
                saveTranslateSettings();
                doNotRow.lastElementChild.textContent = translateSettings.doNotTranslate.map(languageName).join(", ") + " ›";
              });
            });
            modalEl.querySelector("#translate-entire").addEventListener("change", (e) => {
              translateSettings.entireChats = e.target.checked;
              saveTranslateSettings();
            });

            modalEl.querySelectorAll(".lang-item-row[data-loc]").forEach(row => {
              row.addEventListener("click", (e) => {
                if (e.target.closest(".del-custom-lang")) return;
                const loc = row.dataset.loc;
                if (loc) {
                  setLocale(loc);
                  modalEl.querySelector(".modal-body").innerHTML = renderModalBody();
                  bindEvents();
                }
              });
            });

            modalEl.querySelectorAll(".del-custom-lang").forEach(btn => {
              btn.addEventListener("click", (e) => {
                e.stopPropagation();
                const loc = btn.dataset.del;
                const name = customLocales[loc]?.label || loc;
                if (confirm(t("delete_custom_confirm", { name }))) {
                  delete customLocales[loc];
                  delete I18N[loc];
                  localStorage.setItem("pig_web_custom_locales", JSON.stringify(customLocales));
                  if (currentLocale === loc) setLocale("zh-CN");
                  modalEl.querySelector(".modal-body").innerHTML = renderModalBody();
                  bindEvents();
                }
              });
            });

            const importBtn = modalEl.querySelector("#import-pack-btn");
            const packInput = modalEl.querySelector("#lang-pack-input");
            const exportBtn = modalEl.querySelector("#export-pack-btn");

            if (importBtn && packInput) {
              importBtn.addEventListener("click", () => packInput.click());
              packInput.addEventListener("change", () => {
                if (packInput.files && packInput.files[0]) {
                  importLanguagePackFile(packInput.files[0]);
                  closeFn();
                }
              });
            }

            if (exportBtn) {
              exportBtn.addEventListener("click", () => exportLanguagePack());
            }
          };

          bindEvents();
        }
      });
    }

export { I18N, currentLocale, t, __t, setLocale, applyI18n, onLocaleChanged, loadExtensionLocales, openLanguageModal as openLangModal, openLanguageModal, customLocales, extensionLocales, translateSettings, translateRequest, needsTranslation };
