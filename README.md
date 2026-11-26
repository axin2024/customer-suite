# Customer Suite · 企业 CRM 与客户服务管理系统

自托管的 PHP 与 MySQL 应用。按公司和岗位管理客户主档、销售、合同、交付、服务与续约。

## 本地启动

需要 Docker Compose。将 `.env.example` 复制为 `.env`，为 `CRM_DB_PASSWORD` 和 `CRM_MYSQL_ROOT_PASSWORD` 设置不同的长密码。

```sh
docker compose up --build --wait
docker compose exec -T web php bin/crm.php health
```

从宿主机传入 `CRM_ADMIN_PASSWORD`，初始化管理员。密码至少 12 字节。

```sh
docker compose exec -T -e CRM_ADMIN_PASSWORD web php bin/crm.php init-admin admin@example.test 管理员
```

访问 `http://127.0.0.1:8080`。进入「组织与权限」新增公司，建立人员并分配公司岗位。用不同人员登录可以核对各自可见的公司和业务记录。

容器默认只对本机开放。原生运行需要 PHP 8.2+、`mbstring`、`pdo_mysql`、MySQL 8.4，设置 `CRM_DSN`（含 `charset=utf8mb4`）、`CRM_DB_USER` 和 `CRM_DB_PASSWORD` 后运行 `php bin/crm.php migrate`。Web 根目录指向 `public/`。

从空库重现集团案例请看 [三公司演示](docs/demo.md)。演示使用单独的数据库和本机端口，包含五个岗位、三家子公司的合同、交付签收、续约、服务工单和集团报表。

## 从一张账单体验订阅业务

在三公司演示库运行 `php bin/crm.php demo-subscriptions demo-admin@example.test`，使用集团经理或丙公司客服登录并打开返回的 `workbench_path`。原月账单保留 40 元超量费，次月账单冲减 10 元，两份账单可以追到同一笔用量和客服争议。[演示安装](docs/demo.md) · [接口样例、升级与验收](docs/subscription-operations.md)

## 业务流程

| 业务域 | 可验证流程 |
| --- | --- |
| 客户档案 | 独立公司、集团关系、主体归并、档案来源、联系人职位及按用途撤回的联络授权 |
| 营销与线索 | 多活动归因、按邮箱和电话去重、冲突线索复核、并发认领、归属回收和资格确认 |
| 商机与预测 | 线索转商机、阶段校验、产品版本与金额、加权预测、只读历史快照与差异 |
| 报价与合同 | 报价修订、折扣审批、集团分签、签署凭据及保留原件的补充协议 |
| 订阅与用量 | 已签行项目分配席位、试用转付费、冻结价目、按服务时区归期、重复事件与差异复核 |
| 订阅财务与续约 | 自然日分摊、账单锁期、跨期贷项、到账登记、按币种查看续约预测与签约金额 |
| 交付交接 | 双岗位确认范围、变更客户代表与实施人员、跟踪延期和待确认事项、上传并核验合同版本的签收附件 |
| 服务台 | 按合同授权、分类派发、并行响应和解决时钟、等待客户暂停、到期升级与资格复核 |
| 客户成功 | 合同负责人、可解释健康度快照、唯一续约商机、流失原因、触达队列与授权复核 |
| 经营分析 | 历史阶段与转化漏斗、首次和最近渠道归因、按汇率版本折算、多公司下钻与授权 CSV |

[报价、签约与交付](docs/sales-delivery.md) · [服务台与 SLA](docs/service-desk.md) · [客户成功与续约](docs/customer-success.md) · [经营分析](docs/management-reports.md)

线索分配和续约触达支持 [可恢复批量任务](docs/batch-jobs.md)。工作台保存选集，处理器逐条提交，页面显示原记录、结果及可重试项。

## 兼容与验收

- 七份 [产品需求](docs/requirements/01-产品总览-prd.md) 和 [验收标准](docs/requirements/04-验收与运营-prd.md) 随仓库分发。
- [24 项业务功能核对](docs/prd-verification.md) 逐项列出业务实现和测试入口。
- 集成测试使用名字以 `crm_test` 开头的独立 MySQL 数据库。显式设置 `CRM_TEST_DATABASE=1`，按 [CI 工作流](.github/workflows/mysql.yml) 执行 MySQL 和 HTTP 测试。
- [发行验收](docs/acceptance.md) 列出 16 个业务场景及复核脚本。[版本变更](CHANGELOG.md) 记录迁移范围。
- 自托管备份及三公司联测的恢复核验见 [备份、恢复与校验](docs/backup-and-restore.md)。
- 授权和业务记录以 MySQL 为准。管理入口和 JSON API 复用同一权限检查及恢复回执。
- [部署配置](docs/configuration.md) · [贡献指南](CONTRIBUTING.md) · [安全反馈](SECURITY.md) · [依赖与素材](docs/third-party.md)

Apache-2.0 开源许可。参见 [LICENSE](LICENSE) 和 [NOTICE](NOTICE)。
