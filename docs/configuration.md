# 部署配置

## 运行参数

| 参数 | 作用与默认值 |
| --- | --- |
| `CRM_DB_NAME` | Compose 初始化库名，默认为 `customer_suite`。演示单独使用 `crm_demo`。已有 MySQL 卷的初始化变量只在数据目录为空时应用。 |
| `CRM_DB_PASSWORD` | 应用数据库密码。Compose 必填。 |
| `CRM_MYSQL_ROOT_PASSWORD` | Compose 中的 MySQL 管理密码。用另一份密码，由部署者保管。 |
| `CRM_DSN` | 原生运行的 DSN，例如 `mysql:host=127.0.0.1;port=3306;dbname=customer_suite;charset=utf8mb4`。Compose 自动设置。 |
| `CRM_DB_USER` | 原生运行的 MySQL 帐号。Compose 默认使用 `customer_suite`。 |
| `CRM_HTTP_PORT` | Compose 映射到本机回环地址的端口，默认 `8080`。 |
| `CRM_SECURE_COOKIES` | 默认 `0`，用于本机 HTTP。使用 HTTPS 部署时设为 `1`。 |
| `CRM_ADMIN_PASSWORD` | 初始化命令的一次性输入。安装后从进程环境移除。 |
| `CRM_DEMO_PASSWORD` | 安装演示岗位的一次性输入。只用于隔离库。 |
| `CRM_OUTREACH_MODE` | 默认为 `replay`。实发配置改为 `webhook`，已有队列记录继续执行其入队时的模式。 |
| `CRM_OUTREACH_WEBHOOK` | 运维控制的 HTTPS 渠道入口。生产启用前由部署方验证渠道的重复提交语义。 |
| `CRM_OUTREACH_SECRET` | 渠道签名密钥，至少 32 字节。由部署侧注入。 |

## 运行与升级

- 将站点根目录配置为 `public/`。会话、数据库令牌与回执附件留在应用与 MySQL 管理范围内。多实例部署需配置共享会话存储。
- `health.php` 与 `php bin/crm.php health` 核对当前应用所需的迁移。部署新版本前备份并在隔离库验证恢复与迁移。历史保存方式见 [备份与恢复](backup-and-restore.md)。
- 根据营业时间与重试策略安排 `sla-sweep`、`renewal-scan`、`outreach-due` 与 `batch-run`。参数使用已授权公司的经理编号。样例与各操作条件见 [服务台](service-desk.md)、[续约](customer-success.md) 和 [批量任务](batch-jobs.md)。
- 首版使用本机磁盘会话和 MySQL 中的签收正文。每份附件最多 2 MiB，当前允许 PDF 和 UTF-8 文本。列表用游标分页，报表 CSV 按授权范围流式导出。
- 接入表单、客服台和邮件时，由部署方维护源系统身份与外部编号。接入后可用同一来源标识重放并核对回执。本地合成案例如需查看外部发送流程，可使用 [签名 webhook 协议](customer-success.md)。
