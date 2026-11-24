# 企业 CRM 与客户服务管理系统｜MySQL 数据与集成

## 持久化边界

MySQL 8.4 InnoDB 是本项目业务状态与授权关系的权威存储。租户身份进入业务唯一键与关联检查。媒体、文件、备份或模型大对象允许使用文件或对象存储，库内保存版本化引用、长度、摘要及所有者。派生缓存可从持久记录重新构建。

## 逻辑实体与事实来源

| 业务域 | 核心实体与必要字段 | 写入时必须维持的规则 |
|---|---|---|
| 客户主档 | account(org,parent,reg_ref), contact(account,role), alias(account,external_key) | 合并保留别名、外部来源和历史责任人，集团层级与服务数据授权分别维护。 |
| 营销与线索 | campaign(id,channel), lead(org,match_key,owner), touch(lead,campaign,time) | 保留首次与最近触点；手机号等标识须归一并按授权显示；认领竞争只产生一个有效负责人。 |
| 商机与预测 | opportunity(account,stage,currency,value), forecast(snapshot,opp,probability) | 阶段推进校验决策人等必备信息，按货币与胜率生成时点预测，后续变化形成差异。 |
| 报价与合同 | quote(opp,rev,expiry), agreement(account,quote_rev), amendment(contract,rev) | 报价注明有效期及产品版本，超授权折扣须复核；已签版本归档并通过补充协议调整。 |
| 交付交接 | handover(contract,owner), milestone(contract,due,status) | 销售与实施共同确认范围，延期说明影响并登记待确认项目，验收记录绑定合同版本。 |
| 服务台 | ticket(account,contract,queue), sla_clock(ticket,calendar,elapsed) | 按合同服务级别计算响应及解决时限，等待客户时按规则暂停，超时只升级一次。 |
| 客户成功 | health_snapshot(account,date,signals), renewal(contract,cycle,opp) | 健康度来源可回看，续约窗口生成唯一续约商机，负责人说明续约或流失的原因。 |
| 经营分析 | stage_history(opp,time,stage), report_snapshot(org,as_of,fx_rev) | 按阶段、组织和时点汇总，币种折算记录汇率，导出复用请求者可见范围。 |

## 事务、索引与对账

- **身份与约束**　外部线索号限定在来源内唯一，每份合同与周期只有一个续约记录。
- **业务索引**　按负责人及预计成交日生成预测，按队列和 SLA 截止筛查工单。
- **数据生命周期**　联系人邮箱与电话按用途授权，汇总报表下钻复用相同的数据边界。

归并客户时锁双方主档并记录来源别名；按合同与续约周期唯一创建机会。索引按负责人、阶段、下次跟进日组织。

列表使用租户、业务状态、更新时间和稳定主键分页。外部事件保留来源、外部号、内容摘要、处理结果和业务对象引用。同键不同内容产生显式冲突；网络超时后按原键查单。跨存储发布应先确认文件持久，再发布其数据库引用；回收须验证没有有效引用。

## 集成契约

活动表单、邮件与服务台导入使用来源号；导出时复核客户范围，上传签收回执须指明合同版本。

线索输入来源号与身份线索，输出命中候选和归属；确定合并须有权限并可追踪原身份。

服务事件输入合同、优先级及时间，输出受理队列、SLA 剩余量、暂停原因与升级级别。

## 数据恢复与核验

在隔离环境恢复 MySQL 和引用清单，校验样例的源对象、派生关系及最终结果。恢复后应能回答以下问题。A 的 60% 加权快照为 60 万；B 生成唯一续约任务；C 的工单按自身 SLA 计时，三个子公司互不可见。

[MySQL 事务模型](https://dev.mysql.com/doc/refman/8.4/en/innodb-transaction-model.html)

[产品](01-产品总览-prd.md) · [功能](02-业务与交互-prd.md) · [MySQL](03-MySQL与集成-prd.md) · [验收](04-验收与运营-prd.md) · [开源](05-开源与发布-prd.md) · [状态与操作](06-状态与操作契约-prd.md) · [公开案例](07-开源项目说明与使用案例-prd.md)
