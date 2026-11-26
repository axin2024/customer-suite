# PRD 功能核对

编号来自 [业务与交互 PRD](requirements/02-业务与交互-prd.md)。[发行验收](acceptance.md) 记录逐项用例、运行环境及容量结果。

| 编号 | 功能及实现 | 验证 |
| --- | --- | --- |
| F01 | 公司关系、集团主档、独立法定主体 [主档](../src/Accounts.php) · [签约](../src/Agreements.php) | [AC-01](acceptance.md) |
| F02 | 联系人职位、渠道与用途授权 [联系人](../src/Contacts.php) | [主档和营销](../tests/accounts-marketing.php) · [HTTP](../tests/http-workbench.php) |
| F03 | 身份证据、归并、来源别名及原责任人 [主档](../src/Accounts.php) | [AC-02](acceptance.md) |
| F04 | 活动触点与首次、最近归因 [活动](../src/Campaigns.php) · [线索接入](../src/LeadIntake.php) | [AC-03](acceptance.md) · [集团报表](../tests/management-reports.php) |
| F05 | 归一化标识去重、联系方式按角色显示 [线索接入](../src/LeadIntake.php) · [线索](../src/Leads.php) | [AC-03](acceptance.md) |
| F06 | 认领、分配和回收 [线索](../src/Leads.php) · [批量任务](../src/BatchJobs.php) | [AC-04](acceptance.md) · [批量](../tests/batch-jobs.php) |
| F07 | 商机阶段准入、决策人和回退原因 [商机](../src/Opportunities.php) | [商机](../tests/sales.php) |
| F08 | 产品版本、金额和币种 [商机产品](../src/OpportunityLines.php) · [金额](../src/Money.php) | [商机](../tests/sales.php) · [合同](../tests/agreements.php) |
| F09 | 时点快照、加权和版本对比 [预测](../src/Forecasts.php) | [AC-05、06](acceptance.md) |
| F10 | 报价产品快照、有效期和新修订 [报价](../src/Quotes.php) | [AC-08](acceptance.md) |
| F11 | 折扣阈值、独立审批和限时代办 [报价](../src/Quotes.php) · [委托](../src/ReviewDelegations.php) | [合同](../tests/agreements.php) |
| F12 | 集团分签、签署原版及补充协议 [合同](../src/Agreements.php) | [AC-07](acceptance.md) · [HTTP](../tests/http-workbench.php) |
| F13 | 范围双确认、里程碑、延期与待确认项 [交付](../src/Handovers.php) · [里程碑](../src/DeliveryMilestones.php) | [交付](../tests/handovers.php) |
| F14 | 客户代表和实施负责人交接 [交付](../src/Handovers.php) | [AC-10](acceptance.md) |
| F15 | 关联原合同版次的客户签收、附件校验 [交付](../src/Handovers.php) · [签收附件](../src/Evidence.php) | [AC-09](acceptance.md) · [HTTP](../tests/http-workbench.php) |
| F16 | 受理保留、分类、队列和受理权限 [服务工单](../src/ServiceTickets.php) · [服务设置](../src/ServiceSettings.php) | [AC-12](acceptance.md) |
| F17 | 版本化工作日历、响应与解决时钟 [日历](../src/BusinessCalendar.php) · [SLA](../src/SlaClocks.php) | [AC-11](acceptance.md) · [节假日与时区](../tests/calendar.php) |
| F18 | 客户等待时暂停、超时升级和关闭 [服务工单](../src/ServiceTickets.php) · [SLA](../src/SlaClocks.php) | [服务台](../tests/service-desk.php) |
| F19 | 健康度来源、规则和风险历史 [健康度](../src/HealthSnapshots.php) | [AC-14](acceptance.md) |
| F20 | 续约窗口、唯一商机、渠道门禁 [续约](../src/Renewals.php) · [触达](../src/Outreach.php) | [AC-13、14](acceptance.md) |
| F21 | 续约结果、流失原因和依据 [续约](../src/Renewals.php) | [客户成功](../tests/customer-success.php) |
| F22 | 时点漏斗、转化与阶段回溯 [报表快照](../src/ReportCapture.php) | [集团报表](../tests/management-reports.php) |
| F23 | 渠道贡献和版本化汇率 [报表](../src/Reports.php) · [汇率](../src/ReportRates.php) | [集团报表](../tests/management-reports.php) |
| F24 | 跨团队汇总、下钻与授权导出 [报表](../src/Reports.php) · [CSV 入口](../public/export.php) | [AC-15、16](acceptance.md) |

批量任务保留原选集、业务版本和执行原因，并可跳到原记录。只重新排队选中的可重试条目。[任务用法](batch-jobs.md) · [中断与恢复测试](../tests/batch-jobs.php)

审批代办绑定公司、单据修订及委托版本。审批权限和独立复核规则在执行时重新校验。补充协议复核表单随待复核条款显示。原请求回执继续用于中断恢复。[工作台用法](sales-delivery.md) · [审批测试](../tests/agreements.php) · [HTTP 回归](../tests/http-workbench.php)
