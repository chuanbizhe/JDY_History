# 历史版本控制插件

## 关于

本项目是原版“历史版本控制”简道云插件及 PHP 后端的脱敏副本。原版业务逻辑未改动，仅替换了生产域名、服务器路径、数据库密码、应用密钥和插件标识等环境相关配置。

项目包含：

- `plugin/`：简道云前端插件安装包；
- `backend/`：PHP 后端，负责接收推送、保存历史快照、查询版本、展示差异和执行回滚；
- 历史数据存储：MySQL 的 `records` 与 `record_versions` 表。

示例历史地址：

```text
https://jdy.dingdanduo.net/history.php?token=example-plugin-token&serial_no=0000
```

示例推送地址：

```text
https://jdy.dingdanduo.net/receive.php?token=example-plugin-token
```

以上地址仅为脱敏示例，部署时必须替换为实际域名、插件 Token 和流水号。

> 安全提示：不要把生产密码、API Key、应用密钥、插件 Token、服务器 IP、数据库导出文件或日志提交到 GitHub。

## 版权、许可证与授权

本项目原创代码、插件及配套文档版权归 `dingdanduo.net` 所有。本项目免费提供使用，但不保证使用效果；使用者应自行承担部署、配置、备份、安全维护及使用后果。

本项目采用 Apache-2.0，详见 [LICENSE](LICENSE)；中文参考说明见 [LICENSE.zh-CN.md](LICENSE.zh-CN.md)。Apache-2.0 允许使用、修改和再发布，并不要求使用前单独取得授权。如需部署授权、技术支持或商业合作，请联系 `boss@elohumo.com`。

简道云商标、第三方服务、外部 CDN 依赖和未确认授权的图片素材不属于本项目许可证授权范围。

## 1. 程序组成与环境

- `plugin/`：简道云前端插件安装包；
- `backend/`：PHP 后端，负责接收推送、保存历史快照、查询版本、展示差异和执行回滚；
- 历史数据存储：MySQL 的 `records` 与 `record_versions` 表。

环境要求：PHP 8.1+、MySQL 5.7/8.0、PDO_MySQL/cURL/OpenSSL/JSON/Session 扩展，以及 HTTPS 域名。简道云 API 和数据推送需使用具备对应能力的版本。

## 2. 后端部署

将 `backend/` 上传到 PHP 站点目录，并通过环境变量配置：

```text
HISTORY_DB_HOST=127.0.0.1
HISTORY_DB_PORT=3306
HISTORY_DB_NAME=your_database
HISTORY_DB_USER=your_database_user
HISTORY_DB_PASS=your_database_password
HISTORY_BASE_URL=https://your-domain.example
HISTORY_ROLLBACK_URL=https://your-domain.example/rollback.php
HISTORY_APP_KEY=随机生成的至少32字节密钥
HISTORY_CRON_TOKEN=随机生成的定时任务令牌
```

数据库账号按最小权限授权。禁止 Web 直接下载 `config.php`、日志、备份和环境文件。生产环境使用 HTTPS，并用 SFTP/FTPS 替代明文 FTP。首次访问 `index.php` 注册后端管理账号。

## 3. 简道云准备与首次匹配

1. 在目标业务表单增加稳定且唯一的流水号字段，后续不要修改它。
2. 在后端创建插件，填写简道云 API Key。
3. 复制插件详情页的“首次匹配推送地址”。
4. 简道云进入「扩展功能 → 数据推送」，新建推送并暂时使用该地址。
5. 新增测试数据，流水号建议填 `0000`，再修改保存一次以触发推送。
6. 在简道云推送日志确认成功。
7. 返回后端确认应用 ID、表单 ID、流水号字段已匹配；必要时手动填写字段 ID。
8. 将推送地址切换为插件生成的“日常推送地址”。

日常地址只能接收 JSON POST，不要用浏览器直接打开测试。

## 4. 字段映射与查看

进入插件「设置 → 字段映射」：读取表单结构、选择展示字段、自定义名称、确认流水号字段，并设置子表单列顺序。没有 API Key 时可手动填写 `_widget_xxx` 字段 ID。

历史链接格式：

```text
https://your-domain.example/history.php?token={插件Token}&serial_no={流水号}
```

页面展示当前版本、子表单内容、字段差异和系统字段变化。Token 与流水号属于敏感参数，不要公开发布或写入日志。可在简道云按钮/前端扩展中传入这两个值，在弹窗内嵌查看。

## 5. 回滚

在「设置 → 基础设置 → 版本回滚」开启功能，并填写具有目标表单写权限的 API Key。建议先读取表单结构。

回滚会写回选定版本的整条业务数据，包括子表单；人员、部门、关联数据等复杂字段必须使用简道云 API 所需格式。简道云确认成功后，后端会保存一条 `fillback` 版本；失败时不会保存成功回滚版本。

## 6. 定时同步

启用定时同步后，由 Cron 或计划任务调用：

```text
https://your-domain.example/poll.php?token={HISTORY_CRON_TOKEN}
```

不要把定时任务 Token 写入公开页面或脚本仓库。

## 7. 故障排查

### 推送成功但没有版本

检查是否已切换日常地址、请求体是否为 JSON、流水号是否为空，以及 PHP 和简道云推送日志。

### 历史页暂无数据

确认 `token`、`serial_no` 同时存在，Token 属于当前插件，并且该流水号至少成功推送过一次。

### 字段显示为 `_widget_xxx`

配置 API Key/MCP URL 后点击“读取表单结构”，或手动设置展示名称。

### 回滚失败

确认回滚已开启、API Key 有编辑权限、表单结构已读取，并检查人员、部门、关联数据和子表单字段格式。
