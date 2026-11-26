# 发行验收

[验收需求](requirements/04-验收与运营-prd.md) 的 16 个编号都对应到实际业务入口与测试脚本。2026 年 11 月 26 日在 Windows、原生 PHP 8.2.30 和 MySQL 8.4.13 的空库重新执行。渠道测试使用合成地址与本机 TLS。

| 验收编号 | 实测行为 | 复核入口 |
| --- | --- | --- |
| AC-01 | 三家直属子公司各有一个独立签约主体和合同编号。 | [合同](../tests/agreements.php) |
| AC-02 | 同名但登记号不同的主档合并被拒，返回识别证据不足。 | [主档和营销](../tests/accounts-marketing.php) |
| AC-03 | 两个活动导入同一归一化身份，保留原有来源及两个触点。 | [主档和营销](../tests/accounts-marketing.php) |
| AC-04 | 两个进程争抢同一线索，一方成功、一方收到冲突及有效负责人，只留一条认领记录。 | [主档和营销](../tests/accounts-marketing.php) |
| AC-05 | 100 万元乘 60% 得到 60 万元的快照加权金额。 | [商机与预测](../tests/sales.php) |
| AC-06 | 商机阶段回退后新快照为 40 万元，前期快照保持 60 万元。 | [商机与预测](../tests/sales.php) |
| AC-07 | 同一份集团报价建立三份合同，分签金额合计等于报价实收。 | [合同](../tests/agreements.php) |
| AC-08 | 已批准报价过期后拒绝签约，创建并批准新修订后可继续。 | [合同](../tests/agreements.php) |
| AC-09 | 甲公司验收后，乙公司仍有独立执行中的交付待办。 | [交接与验收](../tests/handovers.php) |
| AC-10 | 改派后旧负责人使用当前版本提交仍被拒绝。 | [交接与验收](../tests/handovers.php) |
| AC-11 | 周五 17:50 到达的工单首次响应截止在周一 09:20，暂停恢复继续计算剩余工作时间。 | [服务台](../tests/service-desk.php) |
| AC-12 | 留存过期合同的原来单，进入待复核并标明过期原因。 | [服务台](../tests/service-desk.php) |
| AC-13 | 并发扫描只创建一份续约及其商机，再扫无新增结果。 | [续约与触达](../tests/customer-success.php) |
| AC-14 | 冻结客户生成风险快照，已排队的自动触达执行次数为零。 | [续约与触达](../tests/customer-success.php) |
| AC-15 | 集团经理看到三个已授权公司及其明细，汇总可追到明细的原时点。 | [集团报表](../tests/management-reports.php) |
| AC-16 | 分公司销售导出的合同只含所在公司和授权对象，姐妹公司的合同编号不出现。 | [集团报表](../tests/management-reports.php) · [HTTP 工作台](../tests/http-workbench.php) |

## 独立启动、升级与恢复

- 用仅授权到独立库的 MySQL 应用账号完成空库迁移、创建管理员和样例导入。第二次导入返回同一回执。演示集成测试通过 30 项断言，五个示例岗位通过本机 HTTP 登录，并核对业务页面、所属范围 CSV、签收摘要和跨公司权限。见 [三公司演示](demo.md)。
- 从 `v1.0.0` 原地应用 `011_review_delegations`。三公司合同、甲公司预测、乙公司唯一续约、丙公司 SLA、报告来源和旧签收文件的回读结果保持一致。升级后录入两条委托审批，再备份恢复到空库，两个代办人及其委托方均可回读。[本次恢复记录](upgrade-restore-result.json)
- 全量回归含 510 项 MySQL 与 HTTP 断言，其中 HTTP 107 项。另有演示 30 项断言和 3 个 TLS 场景。工作台测试在操作回执返回前主动断开客户端，之后用相同标识恢复原回执。双执行器验证选集和业务版本在中断后的行为。见 [批量测试](../tests/batch-jobs.php) 与 [HTTP 测试](../tests/http-workbench.php)。
- 折扣和补充协议的代办回归检查独立审批资格、过期、撤回、跨公司限制及旧委托表单。补充协议的 HTTP 测试打开新版条款、提交委托和代办，并核对审计原始操作者和委托人。见 [审批](../tests/agreements.php) 和 [HTTP 工作台](../tests/http-workbench.php)。
- 合成库的备份到空库过程逐字节核对了测试业务清单。源合同、历史报表和签收附件的验证步骤见 [备份恢复](backup-and-restore.md)。

## 客户与服务工单容量

在隔离 MySQL 演示库增加 10,000 个客户和 50,000 个工单，甲、丙各占一半。业务列表覆盖活动客户、已分队列的受理工单、服务岗位权限和游标翻页。8 个客户端各有独立会话，轮流请求 JSON、HTML 和四种跨公司访问。热身 8.248 秒，测量 30.186 秒。每个 HTTP 请求的测量时间包含连接与响应正文读取。

| 工作台入口 | 样本数 | p95（毫秒） |
| --- | ---: | ---: |
| 甲客户 JSON 列表 | 101 | 297.989 |
| 甲客户 HTML 列表 | 101 | 296.964 |
| 甲工单 JSON 列表 | 101 | 282.413 |
| 丙工单 JSON 列表 | 101 | 302.578 |
| 丙工单第二页 JSON | 101 | 297.060 |
| 丙工单 HTML 列表 | 102 | 296.789 |

共 1,015 次测量请求。四种越权输入共 408 次，均返回 404。客户端逐个核对返回记录或 HTML 标记，热身与测量窗口均未发现异常。环境为 Windows、原生 PHP 8.2.30 单个 HTTP 进程和同机 MySQL 8.4.13，InnoDB 缓冲池为 128 MiB，MySQL 最大连接数为 151。工单使用合成合同与队列。本次负载限于同机工作列表和权限判定。

按 [演示教程](demo.md) 初始化名为 `crm_demo_capacity` 的隔离库。先为该环境导入样例，设置 `CRM_TEST_DATABASE=1`，启动连接该库的本机 Web 服务。将导入时使用的 `CRM_DEMO_PASSWORD` 提供给压测进程，再运行下面两条命令。

```sh
php tests/capacity-seed.php demo-admin@example.test > capacity-fixture.json
python3 -B tests/capacity-http.py capacity-fixture.json --url http://127.0.0.1:8081 --clients 8 --warmup 8 --seconds 30
```

负载脚本在每组至少取得 20 个有效样本、p95 不超过 2 秒且各窗口没有错误时返回成功。[原始测量 JSON](capacity-result.json)
