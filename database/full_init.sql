-- ============================================================================
-- 司南数字藏品平台 - 全量数据库基线 (full_init.sql)
-- 基线版本 : v3.0
-- 生成日期 : 2026-09-21
-- 来源     : init.sql + admin_init.sql + migrations/*.sql (15 个增量) 合并快照
-- 表数量   : 80 张（业务表 + 后台 RBAC 表，统一 nft_ 前缀）
--
-- 【全新部署用法】（一条命令建库）:
--   mysql -e "CREATE DATABASE sinan_nft CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
--   mysql sinan_nft < full_init.sql
--
-- 【默认管理员】admin / admin123 —— 上线后请立即修改密码！
--
-- ⚠️ 上线前建议执行（恢复图形验证码，当前为开发调试关闭状态）:
--   UPDATE nft_system_configs SET config_value='1' WHERE config_key='captcha.enable';
--   UPDATE nft_system_configs SET config_value='{"admin_login":1}' WHERE config_key='captcha.scenes';
--
-- 【说明】
--   - 重建库只需本文件，无需再按顺序执行 init.sql / admin_init.sql / migrations
--   - migrations/*.sql 保留作为变更历史存档，方便追溯每个字段的来源
--   - 线上已运行的环境升级时，只执行新增的增量 SQL，切勿用本文件覆盖线上库
-- ============================================================================
-- MySQL dump 10.13  Distrib 8.0.46, for Linux (x86_64)
--
-- Host: 127.0.0.1    Database: sinan_nft
-- ------------------------------------------------------
-- Server version	8.0.46-0ubuntu0.24.04.4

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `nft_activity_reward_records`
--

DROP TABLE IF EXISTS `nft_activity_reward_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_activity_reward_records` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `activity_type` enum('synthesis','lucky_draw','checkin','invite','register') NOT NULL COMMENT '活动类型',
  `activity_id` int unsigned NOT NULL COMMENT '活动ID（对应各活动表主键）',
  `activity_title` varchar(100) NOT NULL DEFAULT '' COMMENT '活动名称快照',
  `user_id` bigint unsigned NOT NULL COMMENT '用户ID，FK→nft_users.id',
  `phone` varchar(11) NOT NULL DEFAULT '' COMMENT '用户手机号快照',
  `reward_type` enum('collectible','points','draw_chance','priority_qualification','eligibility_qualification','blindbox','none') NOT NULL COMMENT '奖励类型',
  `reward_config` json DEFAULT NULL COMMENT '奖励配置快照（collectibleId/quantity/amount/expiresAt 等）',
  `reward_label` varchar(255) NOT NULL DEFAULT '' COMMENT '奖励描述（导出名单展示）',
  `status` enum('pending','issued','failed','cancelled') NOT NULL DEFAULT 'pending' COMMENT '状态：pending待发放/issued已发放/failed发放失败/cancelled已取消',
  `issue_result` varchar(255) DEFAULT NULL COMMENT '统一发放结果（成功/失败原因）',
  `issued_at` datetime(3) DEFAULT NULL COMMENT '实际发放时间',
  `dedupe_key` varchar(128) DEFAULT NULL COMMENT '去重键（活动+用户+奖励位，防重复入账；uk）',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_dedupe_key` (`dedupe_key`),
  KEY `idx_activity` (`activity_type`,`activity_id`,`status`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='活动奖励名单：manual 模式记录→导出→统一发放';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_activity_reward_records`
--

LOCK TABLES `nft_activity_reward_records` WRITE;
/*!40000 ALTER TABLE `nft_activity_reward_records` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_activity_reward_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_admin_login_logs`
--

DROP TABLE IF EXISTS `nft_admin_login_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_admin_login_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `admin_id` int unsigned DEFAULT NULL COMMENT '管理员ID（登录失败可为NULL）',
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '尝试登录账号',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '结果：1成功 2失败',
  `reason` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '失败原因：密码错误/账号锁定/账号禁用',
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '登录IP',
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'User-Agent',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_admin_created` (`admin_id`,`created_at`),
  KEY `idx_username` (`username`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理后台登录日志';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_admin_login_logs`
--

LOCK TABLES `nft_admin_login_logs` WRITE;
/*!40000 ALTER TABLE `nft_admin_login_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_admin_login_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_admin_operation_logs`
--

DROP TABLE IF EXISTS `nft_admin_operation_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_admin_operation_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `admin_id` int unsigned NOT NULL COMMENT '操作管理员ID',
  `admin_name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '操作管理员姓名/账号',
  `module` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '业务模块：user/collectible/order...',
  `action` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '操作动作：如 freeze/airdrop/destroy',
  `action_desc` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '操作描述（人类可读）',
  `target_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '操作对象类型：user/collectible/order',
  `target_id` bigint unsigned DEFAULT NULL COMMENT '操作对象ID',
  `detail` text COLLATE utf8mb4_unicode_ci COMMENT '操作明细JSON（敏感字段脱敏）',
  `method` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '请求方法：GET/POST/PUT/DELETE',
  `path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '请求路径',
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '操作IP',
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'User-Agent',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_admin_created` (`admin_id`,`created_at`),
  KEY `idx_module` (`module`),
  KEY `idx_target` (`target_type`,`target_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理后台操作审计日志';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_admin_operation_logs`
--

LOCK TABLES `nft_admin_operation_logs` WRITE;
/*!40000 ALTER TABLE `nft_admin_operation_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_admin_operation_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_admin_permissions`
--

DROP TABLE IF EXISTS `nft_admin_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_admin_permissions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '权限/菜单名称',
  `code` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '权限标识：如 user:list / collectible:create',
  `module` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '所属模块',
  `type` tinyint NOT NULL DEFAULT '1' COMMENT '类型：1菜单 2按钮',
  `parent_id` int unsigned NOT NULL DEFAULT '0' COMMENT '父级ID，0为顶级',
  `path` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '前端路由路径（菜单用）',
  `icon` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '菜单图标（菜单用）',
  `sort_order` int NOT NULL DEFAULT '0' COMMENT '排序（升序）',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '状态：1启用 0禁用',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_module` (`module`),
  KEY `idx_parent` (`parent_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2013 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理后台权限表（菜单+按钮）';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_admin_permissions`
--

LOCK TABLES `nft_admin_permissions` WRITE;
/*!40000 ALTER TABLE `nft_admin_permissions` DISABLE KEYS */;
INSERT INTO `nft_admin_permissions` VALUES (100,'数据大盘','dashboard:view','dashboard',1,0,'/dashboard','Odometer',1,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(200,'用户管理','user:list','user',1,0,'/user','User',2,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(201,'用户详情','user:detail','user',1,200,'/user/:id','',1,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(202,'冻结/解冻用户','user:freeze','user',2,200,'','',2,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(203,'重置交易密码/强制登出','user:manage','user',2,200,'','',3,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(204,'加入/移出黑名单','user:blacklist','user',2,200,'','',4,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(205,'强制回收藏品/盲盒','user:recover','user',2,200,'','',5,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(300,'实名认证','realname:list','realname',1,0,'/realname','UserFilled',3,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(301,'查看完整实名信息','realname:full','realname',2,300,'','',1,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(302,'实名审核操作','realname:audit','realname',2,300,'','',2,1,'2026-09-21 15:27:47.035','2026-09-21 15:27:47.035'),(400,'藏品管理','collectible:list','collectible',1,0,'/collectible','Collection',4,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(401,'藏品详情','collectible:detail','collectible',1,400,'/collectible/:id','',1,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(402,'创建藏品','collectible:create','collectible',2,400,'','',2,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(403,'编辑藏品','collectible:edit','collectible',2,400,'','',3,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(404,'发售配置/重新上架','collectible:release','collectible',2,400,'','',4,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(405,'配额配置','collectible:quota','collectible',2,400,'','',5,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(406,'强制售罄/下架','collectible:manage','collectible',2,400,'','',6,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(407,'销毁库存','collectible:destroy','collectible',2,400,'','',7,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(408,'删除藏品','collectible:delete','collectible',2,400,'','',8,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(409,'独立空投','collectible:airdrop','collectible',2,400,'','',9,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(410,'寄售开关/价格管控','collectible:market','collectible',2,400,'','',10,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(411,'资格购配置','collectible:qualification','collectible',2,400,'','',11,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(412,'库存审计','collectible:audit','collectible',2,400,'','',12,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(413,'全体回收','collectible:batch-recover','collectible',2,400,'','',14,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(414,'批量回收','collectible:phone-recover','collectible',2,400,'','',15,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(500,'盲盒管理','blindbox:list','blindbox',1,0,'/blindbox','Box',5,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(501,'盲盒详情','blindbox:detail','blindbox',1,500,'/blindbox/:id','',1,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(502,'创建盲盒','blindbox:create','blindbox',2,500,'','',2,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(503,'编辑盲盒','blindbox:edit','blindbox',2,500,'','',3,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(504,'子藏品/概率配置','blindbox:config','blindbox',2,500,'','',4,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(505,'发售配置','blindbox:release','blindbox',2,500,'','',5,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(506,'强制售罄/下架','blindbox:manage','blindbox',2,500,'','',6,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(507,'销毁库存','blindbox:destroy','blindbox',2,500,'','',7,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(508,'独立空投','blindbox:airdrop','blindbox',2,500,'','',8,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(509,'盲盒库存审计','blindbox:audit','blindbox',2,500,'','',9,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(600,'订单管理','order:list','order',1,0,'/order','Document',6,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(601,'订单详情','order:detail','order',1,600,'/order/:id','',1,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(602,'取消/标记支付','order:manage','order',2,600,'','',2,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(603,'退款操作','order:refund','order',2,600,'','',3,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(604,'异常订单/导出','order:audit','order',2,600,'','',4,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(700,'退款管理','refund:list','refund',1,0,'/refund','Money',7,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(701,'退款审批','refund:approve','refund',2,700,'','',1,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(800,'市场寄售','market:list','market',1,0,'/market','Shop',8,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(801,'挂单冻结/强制下架','market:manage','market',2,800,'','',1,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(802,'手续费配置','market:config','market',2,800,'','',2,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(900,'转赠管理','transfer:list','transfer',1,0,'/transfer','Share',9,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(901,'撤销/强制取消转赠','transfer:manage','transfer',2,900,'','',1,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(1000,'营销活动','marketing:priority:list','marketing',1,0,'/marketing/priority','Present',10,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(1001,'优先购活动管理','marketing:priority:manage','marketing',2,1000,'','',1,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(1002,'签到活动配置','marketing:checkin:config','marketing',1,1000,'/marketing/checkin','Calendar',2,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(1003,'邀请活动配置','marketing:invite:config','marketing',1,1000,'/marketing/invite','Promotion',3,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(1004,'抽奖活动管理','marketing:lucky:list','marketing',1,1000,'/marketing/lucky-draw','Trophy',4,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(1005,'抽奖奖项配置','marketing:lucky:manage','marketing',2,1004,'','',1,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(1006,'合成活动管理','marketing:synthesis:list','marketing',1,1000,'/marketing/synthesis','Connection',5,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(1007,'合成活动编辑','marketing:synthesis:manage','marketing',2,1006,'','',1,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(1008,'活动空投发放','marketing:airdrop','marketing',1,1000,'/marketing/airdrop','Promotion',6,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(1009,'注册福利配置','marketing:register:config','marketing',1,1000,'/marketing/register','Gift',7,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(1100,'钱包财务','wallet:transaction','wallet',1,0,'/wallet/transaction','Wallet',11,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(1101,'充值记录','wallet:recharge','wallet',1,1100,'/wallet/recharge','',1,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(1102,'手续费统计','wallet:fee','wallet',1,1100,'/wallet/fee','',2,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(1103,'资金守恒校验','wallet:audit','wallet',2,1100,'','',3,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(1104,'异常资金监控','wallet:monitor','wallet',1,1100,'/wallet/abnormal','',4,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(1200,'内容管理','cms:banner','cms',1,0,'/cms/banner','Picture',12,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(1201,'公告管理','cms:announcement','cms',1,1200,'/cms/announcement','',1,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(1202,'协议管理','cms:agreement','cms',1,1200,'/cms/agreement','',2,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(1203,'文物展馆','cms:artifact','cms',1,1200,'/cms/artifact','',3,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(1204,'站点装修','cms:decoration','cms',1,1200,'/cms/decoration','',4,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(1205,'社区管理','cms:community','cms',1,1200,'/cms/community','',5,1,'2026-09-21 15:27:47.035','2026-09-21 15:27:47.035'),(1300,'系统配置','system:config','system',1,0,'/system/global','Setting',13,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(1301,'支付渠道配置','system:payment','system',1,1300,'/system/payment','',1,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(1302,'短信配置','system:sms','system',1,1300,'/system/sms','',2,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(1303,'安全策略配置','system:security','system',1,1300,'/system/security','',3,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(1304,'图片上传','system:upload','system',2,1300,'','',4,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(1400,'权限管理','permission:admin','permission',1,0,'/permission/admin','Key',14,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(1401,'角色管理','permission:role','permission',1,1400,'/permission/role','',1,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(1402,'操作/登录日志','permission:log','permission',1,1400,'/permission/operation-log','',2,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(1500,'风控安全','security:blacklist','security',1,0,'/security/blacklist','Warning',15,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(1501,'风控告警','security:alert','security',1,1500,'/security/risk-alert','',1,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(1502,'安全事件','security:event','security',1,1500,'/security/event','',2,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(1600,'客服工单','ticket:list','ticket',1,0,'/ticket','Service',16,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(1601,'工单处理','ticket:manage','ticket',2,1600,'','',1,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(1700,'数据报表','report:sales','report',1,0,'/report/sales','TrendCharts',17,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(1701,'用户报表','report:user','report',1,1700,'/report/user','',1,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(1702,'藏品报表','report:collectible','report',1,1700,'/report/collectible','',2,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(1703,'盲盒报表','report:blindbox','report',1,1700,'/report/blindbox','',3,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(1704,'财务对账','report:finance','report',1,1700,'/report/finance','',4,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(1800,'平台运维','platform:log','platform',1,0,'/platform/logs','Delete',18,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(1801,'一键清库','platform:cleanup','platform',2,1800,'','',1,1,'2026-09-21 15:27:46.776','2026-09-21 15:27:46.776'),(1900,'区块链管理','chain:config','chain',1,0,'/chain','Link',19,1,'2026-09-21 15:27:47.035','2026-09-21 15:27:47.035'),(1901,'链上交易查询','chain:transaction','chain',1,1900,'/chain/transactions','',1,1,'2026-09-21 15:27:47.035','2026-09-21 15:27:47.035'),(1902,'合约登记管理','chain:contract','chain',2,1900,'','',2,1,'2026-09-21 15:27:47.035','2026-09-21 15:27:47.035'),(1903,'上链铸造','chain:mint','chain',2,1900,'','',3,1,'2026-09-21 15:27:47.035','2026-09-21 15:27:47.035'),(2000,'审批中心','approval:list','approval',1,0,'/approval','Stamp',20,1,'2026-09-21 15:27:47.035','2026-09-21 15:27:47.035'),(2001,'审批处理','approval:manage','approval',2,2000,'','',1,1,'2026-09-21 15:27:47.035','2026-09-21 15:27:47.035'),(2002,'求购挂单','market:buyrequest:list','market',1,800,'/market/buy-request','',3,1,'2026-09-21 15:27:47.586','2026-09-21 15:27:47.586'),(2003,'置换市场','market:swap:list','market',1,800,'/market/swap','',4,1,'2026-09-21 15:27:47.586','2026-09-21 15:27:47.586'),(2004,'藏品置换','collectible:swap','collectible',2,400,'','',13,1,'2026-09-21 15:27:47.586','2026-09-21 15:27:47.586'),(2005,'抽签发售','marketing:raffle:list','marketing',1,1000,'/marketing/raffle','SetUp',8,1,'2026-09-21 15:27:47.586','2026-09-21 15:27:47.586'),(2006,'分解/熔炼','marketing:decompose:list','marketing',1,1000,'/marketing/decompose','',9,1,'2026-09-21 15:27:47.586','2026-09-21 15:27:47.586'),(2007,'数据快照','report:snapshot','report',1,1700,'/report/snapshot','',5,1,'2026-09-21 15:27:47.586','2026-09-21 15:27:47.586'),(2008,'回收站','platform:trash:list','platform',1,1800,'/platform/trash','DeleteFilled',2,1,'2026-09-21 15:27:47.586','2026-09-21 15:27:47.586'),(2009,'抽签管理','marketing:raffle:manage','marketing',2,1000,'','',8,1,'2026-09-21 15:27:47.588','2026-09-21 15:27:47.588'),(2010,'分类管理','cms:category','cms',1,1200,'/cms/category','',5,1,'2026-09-21 15:27:49.397','2026-09-21 15:27:49.397');
/*!40000 ALTER TABLE `nft_admin_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_admin_role_permissions`
--

DROP TABLE IF EXISTS `nft_admin_role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_admin_role_permissions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `role_id` int unsigned NOT NULL COMMENT '角色ID',
  `permission_id` int unsigned NOT NULL COMMENT '权限ID',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_role_permission` (`role_id`,`permission_id`),
  KEY `idx_role` (`role_id`),
  KEY `idx_permission` (`permission_id`),
  CONSTRAINT `fk_rp_permission` FOREIGN KEY (`permission_id`) REFERENCES `nft_admin_permissions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `nft_admin_roles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=291 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='角色权限关联表';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_admin_role_permissions`
--

LOCK TABLES `nft_admin_role_permissions` WRITE;
/*!40000 ALTER TABLE `nft_admin_role_permissions` DISABLE KEYS */;
INSERT INTO `nft_admin_role_permissions` VALUES (1,1,100,'2026-09-21 15:27:46.784'),(2,1,200,'2026-09-21 15:27:46.784'),(3,1,300,'2026-09-21 15:27:46.784'),(4,1,400,'2026-09-21 15:27:46.784'),(5,1,500,'2026-09-21 15:27:46.784'),(6,1,600,'2026-09-21 15:27:46.784'),(7,1,700,'2026-09-21 15:27:46.784'),(8,1,800,'2026-09-21 15:27:46.784'),(9,1,900,'2026-09-21 15:27:46.784'),(10,1,1000,'2026-09-21 15:27:46.784'),(11,1,1100,'2026-09-21 15:27:46.784'),(12,1,1200,'2026-09-21 15:27:46.784'),(13,1,1300,'2026-09-21 15:27:46.784'),(14,1,1400,'2026-09-21 15:27:46.784'),(15,1,1500,'2026-09-21 15:27:46.784'),(16,1,1600,'2026-09-21 15:27:46.784'),(17,1,1700,'2026-09-21 15:27:46.784'),(18,1,1800,'2026-09-21 15:27:46.784'),(19,1,201,'2026-09-21 15:27:46.784'),(20,1,202,'2026-09-21 15:27:46.784'),(21,1,203,'2026-09-21 15:27:46.784'),(22,1,204,'2026-09-21 15:27:46.784'),(23,1,205,'2026-09-21 15:27:46.784'),(24,1,301,'2026-09-21 15:27:46.784'),(25,1,401,'2026-09-21 15:27:46.784'),(26,1,402,'2026-09-21 15:27:46.784'),(27,1,403,'2026-09-21 15:27:46.784'),(28,1,404,'2026-09-21 15:27:46.784'),(29,1,405,'2026-09-21 15:27:46.784'),(30,1,406,'2026-09-21 15:27:46.784'),(31,1,407,'2026-09-21 15:27:46.784'),(32,1,408,'2026-09-21 15:27:46.784'),(33,1,409,'2026-09-21 15:27:46.784'),(34,1,410,'2026-09-21 15:27:46.784'),(35,1,411,'2026-09-21 15:27:46.784'),(36,1,412,'2026-09-21 15:27:46.784'),(37,1,413,'2026-09-21 15:27:46.784'),(38,1,414,'2026-09-21 15:27:46.784'),(39,1,501,'2026-09-21 15:27:46.784'),(40,1,502,'2026-09-21 15:27:46.784'),(41,1,503,'2026-09-21 15:27:46.784'),(42,1,504,'2026-09-21 15:27:46.784'),(43,1,505,'2026-09-21 15:27:46.784'),(44,1,506,'2026-09-21 15:27:46.784'),(45,1,507,'2026-09-21 15:27:46.784'),(46,1,508,'2026-09-21 15:27:46.784'),(47,1,509,'2026-09-21 15:27:46.784'),(48,1,601,'2026-09-21 15:27:46.784'),(49,1,602,'2026-09-21 15:27:46.784'),(50,1,603,'2026-09-21 15:27:46.784'),(51,1,604,'2026-09-21 15:27:46.784'),(52,1,701,'2026-09-21 15:27:46.784'),(53,1,801,'2026-09-21 15:27:46.784'),(54,1,802,'2026-09-21 15:27:46.784'),(55,1,901,'2026-09-21 15:27:46.784'),(56,1,1001,'2026-09-21 15:27:46.784'),(57,1,1002,'2026-09-21 15:27:46.784'),(58,1,1003,'2026-09-21 15:27:46.784'),(59,1,1004,'2026-09-21 15:27:46.784'),(60,1,1006,'2026-09-21 15:27:46.784'),(61,1,1008,'2026-09-21 15:27:46.784'),(62,1,1009,'2026-09-21 15:27:46.784'),(63,1,1005,'2026-09-21 15:27:46.784'),(64,1,1007,'2026-09-21 15:27:46.784'),(65,1,1101,'2026-09-21 15:27:46.784'),(66,1,1102,'2026-09-21 15:27:46.784'),(67,1,1103,'2026-09-21 15:27:46.784'),(68,1,1104,'2026-09-21 15:27:46.784'),(69,1,1201,'2026-09-21 15:27:46.784'),(70,1,1202,'2026-09-21 15:27:46.784'),(71,1,1203,'2026-09-21 15:27:46.784'),(72,1,1204,'2026-09-21 15:27:46.784'),(73,1,1301,'2026-09-21 15:27:46.784'),(74,1,1302,'2026-09-21 15:27:46.784'),(75,1,1303,'2026-09-21 15:27:46.784'),(76,1,1304,'2026-09-21 15:27:46.784'),(77,1,1401,'2026-09-21 15:27:46.784'),(78,1,1402,'2026-09-21 15:27:46.784'),(79,1,1501,'2026-09-21 15:27:46.784'),(80,1,1502,'2026-09-21 15:27:46.784'),(81,1,1601,'2026-09-21 15:27:46.784'),(82,1,1701,'2026-09-21 15:27:46.784'),(83,1,1702,'2026-09-21 15:27:46.784'),(84,1,1703,'2026-09-21 15:27:46.784'),(85,1,1704,'2026-09-21 15:27:46.784'),(86,1,1801,'2026-09-21 15:27:46.784'),(128,2,100,'2026-09-21 15:27:46.789'),(129,2,200,'2026-09-21 15:27:46.789'),(130,2,201,'2026-09-21 15:27:46.789'),(131,2,202,'2026-09-21 15:27:46.789'),(132,2,203,'2026-09-21 15:27:46.789'),(133,2,204,'2026-09-21 15:27:46.789'),(134,2,205,'2026-09-21 15:27:46.789'),(135,2,300,'2026-09-21 15:27:46.789'),(136,2,400,'2026-09-21 15:27:46.789'),(137,2,401,'2026-09-21 15:27:46.789'),(138,2,402,'2026-09-21 15:27:46.789'),(139,2,403,'2026-09-21 15:27:46.789'),(140,2,404,'2026-09-21 15:27:46.789'),(141,2,405,'2026-09-21 15:27:46.789'),(142,2,406,'2026-09-21 15:27:46.789'),(143,2,407,'2026-09-21 15:27:46.789'),(144,2,408,'2026-09-21 15:27:46.789'),(145,2,409,'2026-09-21 15:27:46.789'),(146,2,410,'2026-09-21 15:27:46.789'),(147,2,411,'2026-09-21 15:27:46.789'),(148,2,412,'2026-09-21 15:27:46.789'),(149,2,413,'2026-09-21 15:27:46.789'),(150,2,414,'2026-09-21 15:27:46.789'),(151,2,500,'2026-09-21 15:27:46.789'),(152,2,501,'2026-09-21 15:27:46.789'),(153,2,502,'2026-09-21 15:27:46.789'),(154,2,503,'2026-09-21 15:27:46.789'),(155,2,504,'2026-09-21 15:27:46.789'),(156,2,505,'2026-09-21 15:27:46.789'),(157,2,506,'2026-09-21 15:27:46.789'),(158,2,507,'2026-09-21 15:27:46.789'),(159,2,508,'2026-09-21 15:27:46.789'),(160,2,509,'2026-09-21 15:27:46.789'),(161,2,600,'2026-09-21 15:27:46.789'),(162,2,601,'2026-09-21 15:27:46.789'),(163,2,800,'2026-09-21 15:27:46.789'),(164,2,900,'2026-09-21 15:27:46.789'),(165,2,1000,'2026-09-21 15:27:46.789'),(166,2,1001,'2026-09-21 15:27:46.789'),(167,2,1002,'2026-09-21 15:27:46.789'),(168,2,1003,'2026-09-21 15:27:46.789'),(169,2,1004,'2026-09-21 15:27:46.789'),(170,2,1005,'2026-09-21 15:27:46.789'),(171,2,1006,'2026-09-21 15:27:46.789'),(172,2,1007,'2026-09-21 15:27:46.789'),(173,2,1008,'2026-09-21 15:27:46.789'),(174,2,1009,'2026-09-21 15:27:46.789'),(175,2,1200,'2026-09-21 15:27:46.789'),(176,2,1201,'2026-09-21 15:27:46.789'),(177,2,1202,'2026-09-21 15:27:46.789'),(178,2,1203,'2026-09-21 15:27:46.789'),(179,2,1204,'2026-09-21 15:27:46.789'),(180,2,1304,'2026-09-21 15:27:46.789'),(181,2,1600,'2026-09-21 15:27:46.789'),(182,2,1601,'2026-09-21 15:27:46.789'),(183,2,1700,'2026-09-21 15:27:46.789'),(184,2,1701,'2026-09-21 15:27:46.789'),(185,2,1702,'2026-09-21 15:27:46.789'),(186,2,1703,'2026-09-21 15:27:46.789'),(187,3,100,'2026-09-21 15:27:46.792'),(188,3,600,'2026-09-21 15:27:46.792'),(189,3,601,'2026-09-21 15:27:46.792'),(190,3,602,'2026-09-21 15:27:46.792'),(191,3,603,'2026-09-21 15:27:46.792'),(192,3,604,'2026-09-21 15:27:46.792'),(193,3,700,'2026-09-21 15:27:46.792'),(194,3,701,'2026-09-21 15:27:46.792'),(195,3,800,'2026-09-21 15:27:46.792'),(196,3,802,'2026-09-21 15:27:46.792'),(197,3,900,'2026-09-21 15:27:46.792'),(198,3,1100,'2026-09-21 15:27:46.792'),(199,3,1101,'2026-09-21 15:27:46.792'),(200,3,1102,'2026-09-21 15:27:46.792'),(201,3,1103,'2026-09-21 15:27:46.792'),(202,3,1104,'2026-09-21 15:27:46.792'),(203,3,1700,'2026-09-21 15:27:46.792'),(204,3,1704,'2026-09-21 15:27:46.792'),(205,4,100,'2026-09-21 15:27:46.795'),(206,4,200,'2026-09-21 15:27:46.795'),(207,4,201,'2026-09-21 15:27:46.795'),(208,4,204,'2026-09-21 15:27:46.795'),(209,4,205,'2026-09-21 15:27:46.795'),(210,4,300,'2026-09-21 15:27:46.795'),(211,4,301,'2026-09-21 15:27:46.795'),(212,4,400,'2026-09-21 15:27:46.795'),(213,4,401,'2026-09-21 15:27:46.795'),(214,4,500,'2026-09-21 15:27:46.795'),(215,4,501,'2026-09-21 15:27:46.795'),(216,4,600,'2026-09-21 15:27:46.795'),(217,4,601,'2026-09-21 15:27:46.795'),(218,4,604,'2026-09-21 15:27:46.795'),(219,4,800,'2026-09-21 15:27:46.795'),(220,4,801,'2026-09-21 15:27:46.795'),(221,4,900,'2026-09-21 15:27:46.795'),(222,4,901,'2026-09-21 15:27:46.795'),(223,4,1500,'2026-09-21 15:27:46.795'),(224,4,1501,'2026-09-21 15:27:46.795'),(225,4,1502,'2026-09-21 15:27:46.795'),(226,4,1700,'2026-09-21 15:27:46.795'),(227,4,1701,'2026-09-21 15:27:46.795'),(228,4,1702,'2026-09-21 15:27:46.795'),(229,4,1703,'2026-09-21 15:27:46.795'),(230,4,1704,'2026-09-21 15:27:46.795'),(231,5,100,'2026-09-21 15:27:46.797'),(232,5,200,'2026-09-21 15:27:46.797'),(233,5,201,'2026-09-21 15:27:46.797'),(234,5,300,'2026-09-21 15:27:46.797'),(235,5,400,'2026-09-21 15:27:46.797'),(236,5,401,'2026-09-21 15:27:46.797'),(237,5,500,'2026-09-21 15:27:46.797'),(238,5,501,'2026-09-21 15:27:46.797'),(239,5,600,'2026-09-21 15:27:46.797'),(240,5,601,'2026-09-21 15:27:46.797'),(241,5,1600,'2026-09-21 15:27:46.797'),(242,5,1601,'2026-09-21 15:27:46.797'),(243,1,1900,'2026-09-21 15:27:47.037'),(244,1,2000,'2026-09-21 15:27:47.037'),(245,1,302,'2026-09-21 15:27:47.037'),(246,1,1205,'2026-09-21 15:27:47.037'),(247,1,1901,'2026-09-21 15:27:47.037'),(248,1,1902,'2026-09-21 15:27:47.037'),(249,1,1903,'2026-09-21 15:27:47.037'),(250,1,2001,'2026-09-21 15:27:47.037'),(258,2,302,'2026-09-21 15:27:47.043'),(259,2,1205,'2026-09-21 15:27:47.043'),(260,3,2000,'2026-09-21 15:27:47.043'),(261,4,302,'2026-09-21 15:27:47.043'),(262,4,2000,'2026-09-21 15:27:47.043'),(263,4,2001,'2026-09-21 15:27:47.043'),(264,2,2009,'2026-09-21 15:27:47.590'),(265,1,2009,'2026-09-21 15:27:47.590'),(267,1,2004,'2026-09-21 15:27:47.592'),(268,1,2002,'2026-09-21 15:27:47.592'),(269,1,2003,'2026-09-21 15:27:47.592'),(270,1,2006,'2026-09-21 15:27:47.592'),(271,1,2005,'2026-09-21 15:27:47.592'),(272,1,2008,'2026-09-21 15:27:47.592'),(273,1,2007,'2026-09-21 15:27:47.592'),(274,2,2004,'2026-09-21 15:27:47.594'),(275,2,2002,'2026-09-21 15:27:47.594'),(276,2,2003,'2026-09-21 15:27:47.594'),(277,2,2006,'2026-09-21 15:27:47.594'),(278,2,2005,'2026-09-21 15:27:47.594'),(281,3,2007,'2026-09-21 15:27:47.596'),(282,4,2007,'2026-09-21 15:27:47.598'),(285,1,2010,'2026-09-21 15:27:49.583');
/*!40000 ALTER TABLE `nft_admin_role_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_admin_roles`
--

DROP TABLE IF EXISTS `nft_admin_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_admin_roles` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '角色名称',
  `code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '角色标识：super_admin/operator/finance/risk/support',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '角色描述',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '状态：1启用 0禁用',
  `is_builtin` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否内置角色：1内置（不可删除）',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理后台角色表';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_admin_roles`
--

LOCK TABLES `nft_admin_roles` WRITE;
/*!40000 ALTER TABLE `nft_admin_roles` DISABLE KEYS */;
INSERT INTO `nft_admin_roles` VALUES (1,'超级管理员','super_admin','全部权限，含平台清库、完整实名查看、所有高风险操作',1,1,'2026-09-21 15:27:46.774','2026-09-21 15:27:46.774'),(2,'运营','operator','藏品/盲盒管理、活动配置、CMS、基础用户管理、订单查看',1,1,'2026-09-21 15:27:46.774','2026-09-21 15:27:46.774'),(3,'财务','finance','订单管理、退款审批、钱包流水、财务报表',1,1,'2026-09-21 15:27:46.774','2026-09-21 15:27:46.774'),(4,'风控','risk','黑名单、风控告警、实名完整查看、异常交易处理',1,1,'2026-09-21 15:27:46.774','2026-09-21 15:27:46.774'),(5,'客服','support','工单处理、基础用户查询（仅脱敏信息）',1,1,'2026-09-21 15:27:46.774','2026-09-21 15:27:46.774');
/*!40000 ALTER TABLE `nft_admin_roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_admin_users`
--

DROP TABLE IF EXISTS `nft_admin_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_admin_users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '登录账号，唯一',
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '密码 bcrypt 哈希',
  `real_name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '真实姓名',
  `role_id` int unsigned NOT NULL COMMENT '角色ID，FK→nft_admin_roles.id',
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '绑定手机号（清库短信验证接收号）',
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '邮箱',
  `avatar` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '头像URL',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '状态：1启用 0禁用',
  `last_login_at` datetime DEFAULT NULL COMMENT '最后登录时间',
  `last_login_ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '最后登录IP',
  `login_fail_count` int unsigned NOT NULL DEFAULT '0' COMMENT '连续登录失败次数',
  `locked_until` datetime DEFAULT NULL COMMENT '锁定截止时间，NULL未锁定',
  `last_action_at` datetime DEFAULT NULL COMMENT '最后操作时间',
  `deleted_at` datetime DEFAULT NULL COMMENT '软删除时间',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  KEY `idx_role` (`role_id`),
  CONSTRAINT `fk_admin_role` FOREIGN KEY (`role_id`) REFERENCES `nft_admin_roles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理后台管理员账号表';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_admin_users`
--

LOCK TABLES `nft_admin_users` WRITE;
/*!40000 ALTER TABLE `nft_admin_users` DISABLE KEYS */;
INSERT INTO `nft_admin_users` VALUES (1,'admin','$2y$10$MZezM8D3P/6A97GsugEux.HuOiICuhcmmrwHGigMpjehdeCOnQwiG','超级管理员',1,'13800000000',NULL,NULL,1,'2026-09-22 00:15:55','127.0.0.1',0,NULL,'2026-09-22 00:27:04',NULL,'2026-09-21 15:27:46.782','2026-09-21 16:27:04.577');
/*!40000 ALTER TABLE `nft_admin_users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_airdrop_activities`
--

DROP TABLE IF EXISTS `nft_airdrop_activities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_airdrop_activities` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '活动名称',
  `type` enum('direct','hold','checkin','register','login','invite','condition') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '资格来源：direct=全部用户 hold=持有快照藏品 checkin=连续签到 register=注册 login=登录 invite=邀请 condition=条件筛选',
  `status` enum('draft','active','paused','ended') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft' COMMENT '活动状态：draft草稿/active进行中/paused暂停/ended结束',
  `airdrop_mode` enum('realtime','batch') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'realtime' COMMENT '发放模式：realtime实时发放/batch记资格后台统一发',
  `collectible_id` int unsigned NOT NULL COMMENT '空投目标藏品ID，FK→nft_collectibles.id',
  `quantity_per_user` int unsigned NOT NULL DEFAULT '1' COMMENT '每人发放数量',
  `total_limit` int unsigned DEFAULT NULL COMMENT '总限量（NULL不限）',
  `issued_count` int unsigned NOT NULL DEFAULT '0' COMMENT '已发放数量',
  `start_time` datetime(3) DEFAULT NULL COMMENT '开始时间（注册/登录空投须落在窗口内）',
  `end_time` datetime(3) DEFAULT NULL COMMENT '结束时间',
  `snapshot_at` datetime(3) DEFAULT NULL COMMENT '持有快照时间（仅 type=hold）',
  `snapshot_collectible_id` int unsigned DEFAULT NULL COMMENT '持有快照目标藏品ID，FK→nft_collectibles.id（仅 type=hold）',
  `checkin_days` int unsigned DEFAULT NULL COMMENT '要求连续签到天数（仅 type=checkin，如 1/3/7）',
  `condition_config` json DEFAULT NULL COMMENT '扩展条件配置（JSON）',
  `description` text COLLATE utf8mb4_unicode_ci COMMENT '活动说明文案',
  `deleted_at` datetime DEFAULT NULL COMMENT '软删除时间（禁止物理删除，保证发放历史可审计）',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_type_status` (`type`,`status`),
  KEY `idx_start_end` (`start_time`,`end_time`),
  KEY `fk_air_act_collectible` (`collectible_id`),
  KEY `fk_air_act_snapshot_collectible` (`snapshot_collectible_id`),
  CONSTRAINT `fk_air_act_collectible` FOREIGN KEY (`collectible_id`) REFERENCES `nft_collectibles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_air_act_snapshot_collectible` FOREIGN KEY (`snapshot_collectible_id`) REFERENCES `nft_collectibles` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_air_act_quantity` CHECK (((`quantity_per_user` >= 1) and (`issued_count` >= 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='空投活动表：六类型双模式';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_airdrop_activities`
--

LOCK TABLES `nft_airdrop_activities` WRITE;
/*!40000 ALTER TABLE `nft_airdrop_activities` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_airdrop_activities` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_airdrop_eligibilities`
--

DROP TABLE IF EXISTS `nft_airdrop_eligibilities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_airdrop_eligibilities` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `activity_id` int unsigned NOT NULL COMMENT '空投活动ID，FK→nft_airdrop_activities.id',
  `user_id` bigint unsigned DEFAULT NULL COMMENT '完成任务用户ID，FK→nft_users.id',
  `phone` varchar(11) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '完成任务时手机号',
  `task_type` enum('hold','checkin','register','login','invite','condition','direct') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '资格产生方式：hold=持有 checkin=签到 register=注册 login=登录 invite=邀请 condition=条件筛选 direct=全量名单',
  `task_completed_at` datetime(3) NOT NULL COMMENT '任务完成时间',
  `status` enum('eligible','issued') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'eligible' COMMENT '资格状态：eligible待发放/issued已发放',
  `airdrop_record_id` bigint unsigned DEFAULT NULL COMMENT '关联空投记录ID，FK→nft_airdrop_records.id',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_activity_phone` (`activity_id`,`phone`),
  KEY `idx_activity_status` (`activity_id`,`status`),
  KEY `fk_ae_user` (`user_id`),
  KEY `fk_ae_airdrop_record` (`airdrop_record_id`),
  CONSTRAINT `fk_ae_activity` FOREIGN KEY (`activity_id`) REFERENCES `nft_airdrop_activities` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ae_airdrop_record` FOREIGN KEY (`airdrop_record_id`) REFERENCES `nft_airdrop_records` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ae_user` FOREIGN KEY (`user_id`) REFERENCES `nft_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='空投资格记录表：batch模式';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_airdrop_eligibilities`
--

LOCK TABLES `nft_airdrop_eligibilities` WRITE;
/*!40000 ALTER TABLE `nft_airdrop_eligibilities` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_airdrop_eligibilities` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_airdrop_records`
--

DROP TABLE IF EXISTS `nft_airdrop_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_airdrop_records` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `activity_id` int unsigned DEFAULT NULL COMMENT '空投活动ID，FK→nft_airdrop_activities.id（独立空投任务发放时为NULL）',
  `task_id` bigint unsigned DEFAULT NULL COMMENT '独立空投任务ID，FK→nft_airdrop_tasks.id（泛关联不设外键）',
  `user_id` bigint unsigned DEFAULT NULL COMMENT '接收用户ID，FK→nft_users.id（直投可先手机号后注册）',
  `phone` varchar(11) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '接收手机号',
  `collectible_id` int unsigned NOT NULL COMMENT '空投藏品ID，FK→nft_collectibles.id',
  `user_collectible_id` bigint unsigned DEFAULT NULL COMMENT '发放后回填的资产ID，FK→nft_user_collectibles.id（source=airdrop）',
  `quantity` int unsigned NOT NULL DEFAULT '1' COMMENT '发放数量',
  `status` enum('pending','issued','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT '发放状态：pending待发放/issued已发放/failed失败',
  `issued_at` datetime(3) DEFAULT NULL COMMENT '实际发放时间',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_activity_phone` (`activity_id`,`phone`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `fk_ar_collectible` (`collectible_id`),
  KEY `fk_ar_user_collectible` (`user_collectible_id`),
  CONSTRAINT `fk_ar_activity` FOREIGN KEY (`activity_id`) REFERENCES `nft_airdrop_activities` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_ar_collectible` FOREIGN KEY (`collectible_id`) REFERENCES `nft_collectibles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_ar_user` FOREIGN KEY (`user_id`) REFERENCES `nft_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ar_user_collectible` FOREIGN KEY (`user_collectible_id`) REFERENCES `nft_user_collectibles` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_ar_quantity` CHECK ((`quantity` >= 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='空投记录表：发放流水';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_airdrop_records`
--

LOCK TABLES `nft_airdrop_records` WRITE;
/*!40000 ALTER TABLE `nft_airdrop_records` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_airdrop_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_airdrop_snapshots`
--

DROP TABLE IF EXISTS `nft_airdrop_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_airdrop_snapshots` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `activity_id` int unsigned NOT NULL COMMENT '空投活动ID，FK→nft_airdrop_activities.id',
  `user_id` bigint unsigned NOT NULL COMMENT '持有人ID，FK→nft_users.id',
  `collectible_id` int unsigned NOT NULL COMMENT '快照时持有的藏品ID',
  `user_collectible_id` bigint unsigned NOT NULL COMMENT '具体资产实例ID，FK→nft_user_collectibles.id',
  `snapshot_at` datetime(3) NOT NULL COMMENT '快照时间',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_activity_user_collectible` (`activity_id`,`user_id`,`user_collectible_id`),
  KEY `idx_activity` (`activity_id`),
  KEY `fk_as_user` (`user_id`),
  KEY `fk_as_user_collectible` (`user_collectible_id`),
  CONSTRAINT `fk_as_activity` FOREIGN KEY (`activity_id`) REFERENCES `nft_airdrop_activities` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_as_user` FOREIGN KEY (`user_id`) REFERENCES `nft_users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_as_user_collectible` FOREIGN KEY (`user_collectible_id`) REFERENCES `nft_user_collectibles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='持有藏品快照表：hold空投';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_airdrop_snapshots`
--

LOCK TABLES `nft_airdrop_snapshots` WRITE;
/*!40000 ALTER TABLE `nft_airdrop_snapshots` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_airdrop_snapshots` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_airdrop_tasks`
--

DROP TABLE IF EXISTS `nft_airdrop_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_airdrop_tasks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `task_no` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '任务编号：AP+时间+随机',
  `target_type` tinyint NOT NULL DEFAULT '1' COMMENT '空投对象：1藏品 2盲盒（盲盒为其关联藏品）',
  `target_id` int unsigned NOT NULL COMMENT '目标藏品ID',
  `target_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '目标名称快照',
  `total_quantity` int unsigned NOT NULL DEFAULT '0' COMMENT '空投总份数',
  `user_count` int unsigned NOT NULL DEFAULT '0' COMMENT '接收用户数',
  `success_count` int unsigned NOT NULL DEFAULT '0' COMMENT '成功发放数',
  `fail_count` int unsigned NOT NULL DEFAULT '0' COMMENT '失败数（未注册等）',
  `fail_list` json DEFAULT NULL COMMENT '失败明细：[{phone,reason}]',
  `admin_id` int unsigned NOT NULL COMMENT '操作管理员ID',
  `admin_name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '操作管理员姓名',
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '操作IP',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_task_no` (`task_no`),
  KEY `idx_target` (`target_type`,`target_id`),
  KEY `idx_admin` (`admin_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='独立空投任务表（从库存池扣减，任意阶段可执行）';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_airdrop_tasks`
--

LOCK TABLES `nft_airdrop_tasks` WRITE;
/*!40000 ALTER TABLE `nft_airdrop_tasks` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_airdrop_tasks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_announcements`
--

DROP TABLE IF EXISTS `nft_announcements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_announcements` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '标题',
  `summary` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '摘要（首页轮播同源）',
  `content` text COLLATE utf8mb4_unicode_ci COMMENT '正文/富文本HTML（含图片）',
  `cover_image` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '封面图URL',
  `type` enum('notice','news') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '类型（决策5）：notice公告/news新闻',
  `subtype` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '子分类：activity活动/compose合成/operation运营',
  `tag_color` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '标签色（前端展示）',
  `status` enum('draft','published') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'published' COMMENT '状态：draft草稿/published已发布（含定时待生效）',
  `publish_time` datetime(3) DEFAULT NULL COMMENT '定时发布时间（NULL=创建即生效）',
  `is_top` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否置顶：1是 0否',
  `deleted_at` datetime DEFAULT NULL COMMENT '软删除时间，NULL未删除',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '发布时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_type_created` (`type`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='公告/新闻合并表';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_announcements`
--

LOCK TABLES `nft_announcements` WRITE;
/*!40000 ALTER TABLE `nft_announcements` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_announcements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_approval_requests`
--

DROP TABLE IF EXISTS `nft_approval_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_approval_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `approval_no` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '审批单号：AP+时间+随机',
  `type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '类型：large_refund大额退款/asset_modify资产变更/config_modify配置变更/platform_cleanup平台清库',
  `title` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '审批标题',
  `detail` json DEFAULT NULL COMMENT '业务快照（订单号/金额/理由等，JSON）',
  `amount` decimal(10,2) DEFAULT NULL COMMENT '涉及金额（元），可空',
  `target_type` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '关联目标类型：refund/order/collectible',
  `target_id` bigint unsigned DEFAULT NULL COMMENT '关联目标ID',
  `applicant_id` int unsigned NOT NULL COMMENT '申请人（管理员）ID',
  `applicant_name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '申请人姓名',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '状态：1待审批 2已通过 3已驳回',
  `handler_id` int unsigned DEFAULT NULL COMMENT '处理人（管理员）ID',
  `handler_name` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '处理人姓名',
  `handle_time` datetime DEFAULT NULL COMMENT '处理时间',
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '审批意见（驳回必填）',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_approval_no` (`approval_no`),
  KEY `idx_type` (`type`),
  KEY `idx_status` (`status`),
  KEY `idx_target` (`target_type`,`target_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='审批中心（大额退款等高风险操作工作流）';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_approval_requests`
--

LOCK TABLES `nft_approval_requests` WRITE;
/*!40000 ALTER TABLE `nft_approval_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_approval_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_artifacts`
--

DROP TABLE IF EXISTS `nft_artifacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_artifacts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '文物名称',
  `dynasty` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '朝代（如：西周）',
  `image` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '文物图URL',
  `img_height` int NOT NULL DEFAULT '150' COMMENT '瀑布流图片高度（px）',
  `material` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '材质（如：青铜）',
  `period` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '年代（如：约公元前1046年－前771年）',
  `size` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '尺寸概述',
  `origin` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '出土/来源地',
  `museum` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '馆藏地点',
  `level` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '文物等级（如：国家一级文物）',
  `specs` json DEFAULT NULL COMMENT '规格档案键值对（如：{"器型":"鼎","通高":"53cm"}）',
  `story` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '详细介绍（分段文本）',
  `tags` json DEFAULT NULL COMMENT '标签数组（如：["青铜","礼器"]，分类筛选依据）',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '展示状态：1展示中 0已隐藏',
  `deleted_at` datetime DEFAULT NULL COMMENT '软删除时间，NULL未删除',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_dynasty` (`dynasty`),
  KEY `idx_deleted` (`deleted_at`),
  CONSTRAINT `chk_artifacts_height` CHECK ((`img_height` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文物表：展览区主数据';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_artifacts`
--

LOCK TABLES `nft_artifacts` WRITE;
/*!40000 ALTER TABLE `nft_artifacts` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_artifacts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_banners`
--

DROP TABLE IF EXISTS `nft_banners`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_banners` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `image` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '轮播图URL',
  `description` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '描述/alt',
  `sort_order` int NOT NULL DEFAULT '0' COMMENT '排序（升序）',
  `is_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否启用：1是 0否',
  `deleted_at` datetime DEFAULT NULL COMMENT '软删除时间，NULL未删除',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_sort_active` (`sort_order`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='轮播图表：首页背景';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_banners`
--

LOCK TABLES `nft_banners` WRITE;
/*!40000 ALTER TABLE `nft_banners` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_banners` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_blacklist`
--

DROP TABLE IF EXISTS `nft_blacklist`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_blacklist` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `user_id` bigint unsigned NOT NULL COMMENT '用户ID，FK→nft_users.id',
  `blacklist_type` tinyint NOT NULL DEFAULT '1' COMMENT '类型：1用户级 2IP级 3设备级',
  `target_value` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '目标值：用户UID/IP/设备号',
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '拉黑原因',
  `evidence` text COLLATE utf8mb4_unicode_ci COMMENT '证据描述',
  `admin_id` int unsigned NOT NULL COMMENT '操作人ID',
  `admin_name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '操作人姓名',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '状态：1生效中 0已解除',
  `lifted_at` datetime DEFAULT NULL COMMENT '解除时间',
  `lifted_by` int unsigned DEFAULT NULL COMMENT '解除操作人ID',
  `lifted_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '解除原因',
  `expires_at` datetime DEFAULT NULL COMMENT '自动过期时间，NULL=永久',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_type` (`user_id`,`blacklist_type`),
  KEY `idx_status` (`status`),
  KEY `idx_target` (`target_value`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `fk_bl_user` FOREIGN KEY (`user_id`) REFERENCES `nft_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='黑名单表（用户/IP/设备级）';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_blacklist`
--

LOCK TABLES `nft_blacklist` WRITE;
/*!40000 ALTER TABLE `nft_blacklist` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_blacklist` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_blind_box_items`
--

DROP TABLE IF EXISTS `nft_blind_box_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_blind_box_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `blind_box_id` int unsigned NOT NULL COMMENT '盲盒ID，FK→nft_blind_boxes.id',
  `prize_collectible_id` int unsigned NOT NULL COMMENT '奖品藏品ID，FK→nft_collectibles.id',
  `probability` decimal(5,4) NOT NULL DEFAULT '0.0000' COMMENT '出货概率（同一盲盒合计=1）',
  `quantity_limit` int unsigned DEFAULT NULL COMMENT '限量份数（NULL不限量）',
  `quantity_distributed` int unsigned NOT NULL DEFAULT '0' COMMENT '已发放数量',
  `deleted_at` datetime DEFAULT NULL COMMENT '软删除时间（禁止物理删除，保证历史开盒可审计）',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_blind_box` (`blind_box_id`),
  KEY `fk_bb_items_prize` (`prize_collectible_id`),
  CONSTRAINT `fk_bb_items_blind_box` FOREIGN KEY (`blind_box_id`) REFERENCES `nft_blind_boxes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bb_items_prize` FOREIGN KEY (`prize_collectible_id`) REFERENCES `nft_collectibles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `chk_bbi_probability` CHECK (((`probability` >= 0) and (`probability` <= 1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='盲盒奖品池配置表：概率与限量';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_blind_box_items`
--

LOCK TABLES `nft_blind_box_items` WRITE;
/*!40000 ALTER TABLE `nft_blind_box_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_blind_box_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_blind_boxes`
--

DROP TABLE IF EXISTS `nft_blind_boxes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_blind_boxes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `collectible_id` int unsigned NOT NULL COMMENT '藏品ID，FK→nft_collectibles.id，唯一（1:1）',
  `description` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '盲盒说明文案',
  `is_openable` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否可开启：1可开启 0已关闭（下架开启入口）',
  `opened_count` int unsigned NOT NULL DEFAULT '0' COMMENT '累计开启数（统计用）',
  `schedule_time` datetime DEFAULT NULL COMMENT '定时上架时间',
  `is_scheduled` tinyint NOT NULL DEFAULT '0' COMMENT '是否定时发售',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_collectible` (`collectible_id`),
  CONSTRAINT `fk_blind_boxes_collectible` FOREIGN KEY (`collectible_id`) REFERENCES `nft_collectibles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='盲盒表：与藏品1:1扩展';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_blind_boxes`
--

LOCK TABLES `nft_blind_boxes` WRITE;
/*!40000 ALTER TABLE `nft_blind_boxes` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_blind_boxes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_buy_requests`
--

DROP TABLE IF EXISTS `nft_buy_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_buy_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `collectible_id` bigint unsigned NOT NULL COMMENT '目标藏品 ID',
  `user_id` bigint unsigned NOT NULL COMMENT '求购发起者',
  `price` decimal(10,2) NOT NULL COMMENT '求购单价',
  `quantity` int unsigned NOT NULL DEFAULT '1' COMMENT '求购数量',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '1求购中 2已接单 3已取消 4已成交 5已过期',
  `accepted_by` bigint unsigned DEFAULT NULL COMMENT '接单用户（卖家）',
  `accepted_at` datetime DEFAULT NULL,
  `order_no` varchar(64) DEFAULT NULL COMMENT '成交后关联订单号',
  `remark` varchar(500) DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL COMMENT '求购有效期',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_collectible` (`collectible_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='求购挂单';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_buy_requests`
--

LOCK TABLES `nft_buy_requests` WRITE;
/*!40000 ALTER TABLE `nft_buy_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_buy_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_categories`
--

DROP TABLE IF EXISTS `nft_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_categories` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `name` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '分类名（如：水墨/国潮/盲盒/实物/联名）',
  `code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '分类编码（英文标识），唯一',
  `scene` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'market' COMMENT '分类场景：market市场二级分类 / artifact文物展览分类',
  `sort_order` int NOT NULL DEFAULT '0' COMMENT '排序（升序）',
  `icon` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '分类图标',
  `deleted_at` datetime DEFAULT NULL COMMENT '软删除时间，NULL未删除',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='藏品分类表：市场tab筛选';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_categories`
--

LOCK TABLES `nft_categories` WRITE;
/*!40000 ALTER TABLE `nft_categories` DISABLE KEYS */;
INSERT INTO `nft_categories` VALUES (1,'青铜','bronze','artifact',1,NULL,NULL,'2026-09-21 15:27:49.381','2026-09-21 15:27:49.381'),(2,'陶瓷','ceramics','artifact',2,NULL,NULL,'2026-09-21 15:27:49.381','2026-09-21 15:27:49.381'),(3,'书画','calligraphy','artifact',3,NULL,NULL,'2026-09-21 15:27:49.381','2026-09-21 15:27:49.381'),(4,'玉器','jade','artifact',4,NULL,NULL,'2026-09-21 15:27:49.381','2026-09-21 15:27:49.381'),(5,'水墨','ink','market',1,NULL,NULL,'2026-09-21 15:27:49.395','2026-09-21 15:27:49.395'),(6,'国潮','guochao','market',2,NULL,NULL,'2026-09-21 15:27:49.395','2026-09-21 15:27:49.395'),(7,'盲盒','blindbox','market',3,NULL,NULL,'2026-09-21 15:27:49.395','2026-09-21 15:27:49.395'),(8,'实物','physical','market',4,NULL,NULL,'2026-09-21 15:27:49.395','2026-09-21 15:27:49.395'),(9,'联名','crossover','market',5,NULL,NULL,'2026-09-21 15:27:49.395','2026-09-21 15:27:49.395');
/*!40000 ALTER TABLE `nft_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_chain_contracts`
--

DROP TABLE IF EXISTS `nft_chain_contracts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_chain_contracts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `network_id` int unsigned NOT NULL COMMENT '链网络ID，FK→nft_chain_networks.id',
  `contract_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '合约名称（业务命名）',
  `contract_address` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '链上合约地址',
  `contract_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'erc721' COMMENT '合约类型：erc721/erc1155/ddc721/ddc1155',
  `token_standard` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '代币标准（展示）：ERC-721/ERC-1155/DDC',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '用途说明',
  `tx_count` int unsigned NOT NULL DEFAULT '0' COMMENT '累计链上交易笔数（铸造时累加）',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '状态：1启用（监听/可上链） 0停用',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_network` (`network_id`),
  KEY `idx_address` (`contract_address`),
  CONSTRAINT `fk_contract_network` FOREIGN KEY (`network_id`) REFERENCES `nft_chain_networks` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='链上合约登记（藏品铸造目标合约）';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_chain_contracts`
--

LOCK TABLES `nft_chain_contracts` WRITE;
/*!40000 ALTER TABLE `nft_chain_contracts` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_chain_contracts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_chain_networks`
--

DROP TABLE IF EXISTS `nft_chain_networks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_chain_networks` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `chain_code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '链标识：wenchang文昌链/consortium联盟链/antchain蚂蚁链',
  `chain_name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '链名称（展示用）',
  `env` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'main' COMMENT '网络环境：main主网/test测试网',
  `rpc_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'RPC/网关地址',
  `chain_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '链ID/网络标识',
  `api_key` text COLLATE utf8mb4_unicode_ci COMMENT '接入密钥（AES 加密存储）',
  `api_secret` text COLLATE utf8mb4_unicode_ci COMMENT '接入密钥密码（AES 加密存储）',
  `explorer_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '区块浏览器地址前缀',
  `gas_strategy` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'medium' COMMENT '上链费率策略：low/medium/high',
  `status` tinyint NOT NULL DEFAULT '0' COMMENT '状态：1启用 0停用',
  `is_default` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否默认链：1是 0否（全局仅一条）',
  `remark` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '备注',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_chain_code` (`chain_code`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='区块链网络配置（藏品上链）';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_chain_networks`
--

LOCK TABLES `nft_chain_networks` WRITE;
/*!40000 ALTER TABLE `nft_chain_networks` DISABLE KEYS */;
INSERT INTO `nft_chain_networks` VALUES (1,'wenchang','文昌链（BSN-DDC）','main',NULL,NULL,NULL,NULL,'https://www.wenchangchain.com','medium',0,1,'国家信息中心 BSN 文昌链 DDC 业务，国内合规数字藏品首选','2026-09-21 15:27:47.047','2026-09-21 15:27:47.047'),(2,'consortium','联盟链（自建）','main',NULL,NULL,NULL,NULL,'','medium',0,0,'平台自建联盟链（FISCO BCOS 等），需配置节点 RPC','2026-09-21 15:27:47.047','2026-09-21 15:27:47.047'),(3,'antchain','蚂蚁链（AntChain）','main',NULL,NULL,NULL,NULL,'https://antchain.antgroup.com','medium',0,0,'蚂蚁链数字藏品服务（蚂蚁链开放联盟线），需配置 AccessKey','2026-09-21 15:27:47.047','2026-09-21 15:27:47.047');
/*!40000 ALTER TABLE `nft_chain_networks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_check_in_activities`
--

DROP TABLE IF EXISTS `nft_check_in_activities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_check_in_activities` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '活动名称',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'enabled' COMMENT '状态：enabled 启用 / disabled 停用',
  `start_time` datetime NOT NULL COMMENT '开始时间',
  `end_time` datetime DEFAULT NULL COMMENT '结束时间（NULL=长期有效）',
  `reward_config` text COLLATE utf8mb4_unicode_ci COMMENT '奖励JSON {day:[六类奖励]}',
  `eligibility_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'all' COMMENT '参与资格类型',
  `eligibility_config` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '参与资格配置JSON',
  `grant_mode` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'realtime' COMMENT '发放方式：realtime 实时 / manual 名单统一发放',
  `signin_count` int unsigned NOT NULL DEFAULT '0' COMMENT '累计签到人次',
  `deleted_at` datetime DEFAULT NULL COMMENT '软删除时间',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status_time` (`status`,`start_time`,`end_time`),
  KEY `idx_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='签到活动表';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_check_in_activities`
--

LOCK TABLES `nft_check_in_activities` WRITE;
/*!40000 ALTER TABLE `nft_check_in_activities` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_check_in_activities` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_check_in_records`
--

DROP TABLE IF EXISTS `nft_check_in_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_check_in_records` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `user_id` bigint unsigned NOT NULL COMMENT '签到用户ID，FK→nft_users.id',
  `activity_id` bigint unsigned NOT NULL DEFAULT '0' COMMENT '签到活动ID',
  `check_in_date` date NOT NULL COMMENT '签到日期（每人每天一次）',
  `consecutive_days` int unsigned NOT NULL DEFAULT '1' COMMENT '签到时连续签到天数',
  `reward_type` enum('none','collectible','points','draw_chance','priority_qualification','eligibility_qualification','blindbox') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none' COMMENT '奖励类型',
  `reward_amount` int NOT NULL DEFAULT '0' COMMENT '奖励数量',
  `reward_related_id` bigint unsigned DEFAULT NULL COMMENT '关联业务ID（藏品ID等，泛关联不设外键；BIGINT 以容纳各表id）',
  `reward_description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '奖励描述',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '签到时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_date` (`user_id`,`check_in_date`),
  KEY `idx_user_created` (`user_id`,`created_at`),
  KEY `idx_activity` (`activity_id`),
  CONSTRAINT `fk_checkin_user` FOREIGN KEY (`user_id`) REFERENCES `nft_users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `chk_checkin_domain` CHECK (((`consecutive_days` >= 1) and (`reward_amount` >= 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='签到记录表：每天一次';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_check_in_records`
--

LOCK TABLES `nft_check_in_records` WRITE;
/*!40000 ALTER TABLE `nft_check_in_records` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_check_in_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_collectibles`
--

DROP TABLE IF EXISTS `nft_collectibles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_collectibles` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `category_id` int unsigned NOT NULL COMMENT '分类ID，FK→nft_categories.id',
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '藏品名称',
  `subtitle` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '副标题/系列名',
  `image` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '封面图URL',
  `gradient` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '卡片渐变兜底色（CSS渐变描述）',
  `icon` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '列表小图标',
  `price` decimal(10,2) NOT NULL COMMENT '发售价（元）',
  `edition` int unsigned NOT NULL DEFAULT '0' COMMENT '发行总量（份）',
  `release_quantity` int unsigned DEFAULT NULL COMMENT '当前上架份数（分批发售用；NULL/0=全部上架）',
  `circulate` int unsigned NOT NULL DEFAULT '0' COMMENT '流通量（支付成功累加）',
  `sold` int unsigned NOT NULL DEFAULT '0' COMMENT '累计已售数量',
  `locked_quantity` int unsigned NOT NULL DEFAULT '0' COMMENT '待支付锁定数量（下单+qty/支付成功-qty并转sold/取消-qty，数据库原子操作）',
  `per_user_limit` int unsigned NOT NULL DEFAULT '0' COMMENT '每人限购数量，0=不限购',
  `is_transferable` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否可转赠：1可 0不可（与寄售开关独立）',
  `is_resaleable` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否可寄售：1可 0不可（关闭时在售挂单全部系统下架）',
  `is_buy_request_enabled` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否允许求购：1可 0不可',
  `resale_price_mode` tinyint NOT NULL DEFAULT '0' COMMENT '寄售价格管控：0不限价 1限价（挂单价须在闭区间内）',
  `resale_price_min` decimal(10,2) DEFAULT NULL COMMENT '寄售限价下限（元），限价模式必填',
  `resale_price_max` decimal(10,2) DEFAULT NULL COMMENT '寄售限价上限（元），限价模式必填',
  `is_qualification_enabled` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否开启资格购：1开启 0关闭（购买门槛，不冻结库存）',
  `reserved_count` int unsigned NOT NULL DEFAULT '0' COMMENT '已配置配额预留总数（冻结自库存池）',
  `airdropped_count` int unsigned NOT NULL DEFAULT '0' COMMENT '已独立空投数量',
  `destroyed_count` int unsigned NOT NULL DEFAULT '0' COMMENT '已销毁数量',
  `vol` int unsigned NOT NULL DEFAULT '0' COMMENT '今日成交量（每日零点业务层重置）',
  `status` enum('upcoming','onsale','soldout','off') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'upcoming' COMMENT '发售状态：upcoming未发售 onsale发售中 soldout已售罄 off已下架',
  `issuer` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '发行方（运营后台创建藏品时必填）',
  `creator` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '创作方（运营后台创建藏品时必填）',
  `brand` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '品牌（运营后台创建藏品时必填）',
  `album` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '所属系列/专辑',
  `contract` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '链上合约地址',
  `chain_type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '链类型：wenchang文昌链/consortium联盟链/antchain蚂蚁链',
  `token_standard` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '代币标准：ERC-721/ERC-1155',
  `onchain_status` tinyint NOT NULL DEFAULT '0' COMMENT '上链状态：0未上链 1上链中 2已上链 3上链失败',
  `minted_at` datetime DEFAULT NULL COMMENT '最近一次上链铸造时间',
  `cert_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '认证证书编号',
  `serial_no` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '编号规则模板（如 SN-{id}-{seq}）',
  `release_date` datetime(3) DEFAULT NULL COMMENT '发售日期（展示用）',
  `onsale_at` datetime(3) DEFAULT NULL COMMENT '开售时间',
  `schedule_time` datetime DEFAULT NULL COMMENT '定时上架时间（到点自动从 upcoming→onsale）',
  `is_scheduled` tinyint NOT NULL DEFAULT '0' COMMENT '是否定时发售（1=启用）',
  `off_sale_at` datetime(3) DEFAULT NULL COMMENT '发售结束时间',
  `tag` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '发售方式标签：首发/优先购/资格购/盲盒',
  `is_release` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否首页发售位：1是 0否',
  `featured` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否推荐位：1是 0否',
  `market_tag` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '市场标签（如：寄售）',
  `description` text COLLATE utf8mb4_unicode_ci COMMENT '藏品故事/购买须知（合并存储）',
  `deleted_at` datetime DEFAULT NULL COMMENT '软删除时间，NULL未删除',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_status_release` (`status`,`is_release`),
  KEY `idx_category` (`category_id`),
  CONSTRAINT `fk_collectibles_category` FOREIGN KEY (`category_id`) REFERENCES `nft_categories` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `chk_collectibles_price` CHECK ((`price` >= 0)),
  CONSTRAINT `chk_collectibles_stock_v2` CHECK (((((((`sold` + `locked_quantity`) + `reserved_count`) + `airdropped_count`) + `destroyed_count`) <= `edition`) and (`circulate` <= `edition`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='藏品主表：发售/盲盒/市场/合成产物统一';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_collectibles`
--

LOCK TABLES `nft_collectibles` WRITE;
/*!40000 ALTER TABLE `nft_collectibles` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_collectibles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_community_groups`
--

DROP TABLE IF EXISTS `nft_community_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_community_groups` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `icon` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '群图标URL',
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '群名称',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '群简介',
  `qr_code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '二维码图URL',
  `members` int unsigned NOT NULL DEFAULT '0' COMMENT '成员数',
  `qq_group` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QQ群号（配置后「加入社群」跳转QQ入群）',
  `sort_order` int NOT NULL DEFAULT '0' COMMENT '排序（升序）',
  `is_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否启用：1是 0否',
  `deleted_at` datetime DEFAULT NULL COMMENT '软删除时间，NULL未删除',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='社区群表：交流入口';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_community_groups`
--

LOCK TABLES `nft_community_groups` WRITE;
/*!40000 ALTER TABLE `nft_community_groups` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_community_groups` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_decompose_items`
--

DROP TABLE IF EXISTS `nft_decompose_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_decompose_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `rule_id` bigint unsigned NOT NULL,
  `result_collectible_id` bigint unsigned NOT NULL COMMENT '分解后产出的藏品',
  `quantity_per` int unsigned NOT NULL DEFAULT '1' COMMENT '每分解 1 件源藏品产出的数量',
  PRIMARY KEY (`id`),
  KEY `idx_rule` (`rule_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='分解产出明细';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_decompose_items`
--

LOCK TABLES `nft_decompose_items` WRITE;
/*!40000 ALTER TABLE `nft_decompose_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_decompose_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_decompose_records`
--

DROP TABLE IF EXISTS `nft_decompose_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_decompose_records` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `rule_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `source_collectible_id` bigint unsigned NOT NULL,
  `source_serial` varchar(64) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_rule` (`rule_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='分解执行记录';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_decompose_records`
--

LOCK TABLES `nft_decompose_records` WRITE;
/*!40000 ALTER TABLE `nft_decompose_records` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_decompose_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_decompose_rules`
--

DROP TABLE IF EXISTS `nft_decompose_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_decompose_rules` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL COMMENT '分解规则名称',
  `source_collectible_id` bigint unsigned NOT NULL COMMENT '分解的源藏品',
  `enabled` tinyint NOT NULL DEFAULT '1',
  `per_user_limit` int unsigned NOT NULL DEFAULT '0' COMMENT '每人限分解次数（0=不限）',
  `daily_limit` int unsigned NOT NULL DEFAULT '0' COMMENT '平台每日上限（0=不限）',
  `start_time` datetime DEFAULT NULL,
  `end_time` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_source` (`source_collectible_id`),
  KEY `idx_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='分解规则';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_decompose_rules`
--

LOCK TABLES `nft_decompose_rules` WRITE;
/*!40000 ALTER TABLE `nft_decompose_rules` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_decompose_rules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_destroy_records`
--

DROP TABLE IF EXISTS `nft_destroy_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_destroy_records` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `target_type` tinyint NOT NULL COMMENT '销毁对象：1藏品 2盲盒',
  `target_id` int unsigned NOT NULL COMMENT '对象ID（盲盒为其关联藏品ID）',
  `target_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '对象名称快照',
  `quantity` int unsigned NOT NULL DEFAULT '0' COMMENT '销毁数量',
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '销毁原因',
  `admin_id` int unsigned NOT NULL COMMENT '操作管理员ID',
  `admin_name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '操作管理员姓名',
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '操作IP',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_target` (`target_type`,`target_id`),
  KEY `idx_admin` (`admin_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='库存销毁记录表（不可逆，需密码+二次确认）';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_destroy_records`
--

LOCK TABLES `nft_destroy_records` WRITE;
/*!40000 ALTER TABLE `nft_destroy_records` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_destroy_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_holdings_snapshots`
--

DROP TABLE IF EXISTS `nft_holdings_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_holdings_snapshots` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `snapshot_date` date NOT NULL COMMENT '快照基准日期',
  `user_id` bigint unsigned NOT NULL COMMENT '用户ID',
  `collectible_id` int unsigned NOT NULL COMMENT '藏品ID',
  `collectible_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '藏品名称快照',
  `total_count` int unsigned NOT NULL DEFAULT '0' COMMENT '持仓总数',
  `held_count` int unsigned NOT NULL DEFAULT '0' COMMENT '持有中数量',
  `consigned_count` int unsigned NOT NULL DEFAULT '0' COMMENT '寄售中数量',
  `frozen_count` int unsigned NOT NULL DEFAULT '0' COMMENT '冻结数量',
  `avg_cost` decimal(10,2) DEFAULT NULL COMMENT '平均获取成本',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_date_user_collectible` (`snapshot_date`,`user_id`,`collectible_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_collectible` (`collectible_id`),
  KEY `idx_date` (`snapshot_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户持仓快照表';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_holdings_snapshots`
--

LOCK TABLES `nft_holdings_snapshots` WRITE;
/*!40000 ALTER TABLE `nft_holdings_snapshots` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_holdings_snapshots` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_inbox`
--

DROP TABLE IF EXISTS `nft_inbox`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_inbox` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `user_id` bigint unsigned NOT NULL COMMENT '收件用户ID，FK→nft_users.id',
  `type` enum('airdrop','transfer') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '通知类型：airdrop空投/transfer转赠',
  `ref_id` bigint unsigned NOT NULL DEFAULT '0' COMMENT '关联业务ID（airdrop_task.id 或 transfers.id）',
  `title` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '通知标题，如"恭喜你收到空投藏品"',
  `collectible_id` int unsigned NOT NULL COMMENT '藏品ID，FK→nft_collectibles.id',
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '藏品名称快照',
  `image` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '藏品图片URL快照',
  `extra` json DEFAULT NULL COMMENT '扩展字段：空投含{quantity,reason,adminName}；转赠含{fromNickname}',
  `status` tinyint unsigned NOT NULL DEFAULT '0' COMMENT '状态：0未读/1已读/2已确认',
  `read_at` datetime(3) DEFAULT NULL COMMENT '已读时间',
  `confirmed_at` datetime(3) DEFAULT NULL COMMENT '确认时间',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_user_status` (`user_id`,`status`),
  KEY `idx_user_created` (`user_id`,`created_at`),
  KEY `idx_type_ref` (`type`,`ref_id`),
  KEY `idx_collectible` (`collectible_id`),
  CONSTRAINT `fk_inbox_collectible` FOREIGN KEY (`collectible_id`) REFERENCES `nft_collectibles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_inbox_user` FOREIGN KEY (`user_id`) REFERENCES `nft_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='收件箱：空投/转赠到达通知，支持未读弹窗与已读标记';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_inbox`
--

LOCK TABLES `nft_inbox` WRITE;
/*!40000 ALTER TABLE `nft_inbox` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_inbox` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_inventory_quotas`
--

DROP TABLE IF EXISTS `nft_inventory_quotas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_inventory_quotas` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `collectible_id` int unsigned NOT NULL COMMENT '藏品ID（含盲盒对应藏品），FK→nft_collectibles.id',
  `quota_type` tinyint NOT NULL COMMENT '配额类型：1优先购 2活动空投 3签到 4注册 5邀请 6抽奖 7其他',
  `quota_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '配额名称',
  `planned_quantity` int unsigned NOT NULL DEFAULT '0' COMMENT '计划配额数量',
  `used_quantity` int unsigned NOT NULL DEFAULT '0' COMMENT '已使用数量',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '状态：1生效 0停用（停用未使用部分释放回库存池）',
  `activity_id` int unsigned DEFAULT NULL COMMENT '关联活动ID（泛关联）',
  `activity_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '关联活动类型：priority/checkin/invite/lucky_draw/register',
  `remark` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '备注',
  `created_by` int unsigned NOT NULL COMMENT '创建管理员ID',
  `created_by_name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '创建管理员姓名',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_collectible` (`collectible_id`),
  KEY `idx_type` (`quota_type`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_quota_collectible` FOREIGN KEY (`collectible_id`) REFERENCES `nft_collectibles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_quota_used` CHECK ((`used_quantity` <= `planned_quantity`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='库存配额预留表（配置时从库存池冻结）';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_inventory_quotas`
--

LOCK TABLES `nft_inventory_quotas` WRITE;
/*!40000 ALTER TABLE `nft_inventory_quotas` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_inventory_quotas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_invite_activities`
--

DROP TABLE IF EXISTS `nft_invite_activities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_invite_activities` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '活动名称',
  `status` enum('disabled','enabled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'disabled' COMMENT '总开关：disabled关闭/enabled开启（关闭时禁止产生新邀请关系与奖励）',
  `start_time` datetime(3) DEFAULT NULL COMMENT '开始时间',
  `end_time` datetime(3) DEFAULT NULL COMMENT '结束时间',
  `inviter_collectible_id` int unsigned DEFAULT NULL COMMENT '邀请人奖励藏品ID，FK→nft_collectibles.id',
  `inviter_quantity` int unsigned NOT NULL DEFAULT '1' COMMENT '邀请人奖励数量',
  `invitee_collectible_id` int unsigned DEFAULT NULL COMMENT '被邀请人奖励藏品ID，FK→nft_collectibles.id',
  `invitee_quantity` int unsigned NOT NULL DEFAULT '1' COMMENT '被邀请人奖励数量',
  `tiers` json DEFAULT NULL COMMENT '邀请档位奖励 JSON：[{inviteCount, rewards:[{type,...}]}] 1-50人',
  `invitee_reward_config` json DEFAULT NULL COMMENT '被邀请人奖励配置 JSON（单奖励项）',
  `invitee_conditions` json DEFAULT NULL COMMENT '被邀请人完成条件 JSON 数组：realname实名/wallet开通第三方钱包/checkin签到/consume消费',
  `grant_mode` enum('realtime','manual') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'realtime' COMMENT '奖励发放方式：realtime实时到账/manual记录名单统一发放',
  `airdrop_mode` enum('realtime','batch') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'realtime' COMMENT '奖励发放模式：realtime实时/batch批量',
  `total_limit` int unsigned DEFAULT NULL COMMENT '总邀请奖励上限（NULL不限）',
  `used_count` int unsigned NOT NULL DEFAULT '0' COMMENT '已发放奖励数量',
  `description` text COLLATE utf8mb4_unicode_ci COMMENT '活动说明文案',
  `deleted_at` datetime DEFAULT NULL COMMENT '软删除时间，NULL未删除',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `fk_inv_act_inviter_collectible` (`inviter_collectible_id`),
  KEY `fk_inv_act_invitee_collectible` (`invitee_collectible_id`),
  CONSTRAINT `fk_inv_act_invitee_collectible` FOREIGN KEY (`invitee_collectible_id`) REFERENCES `nft_collectibles` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_inv_act_inviter_collectible` FOREIGN KEY (`inviter_collectible_id`) REFERENCES `nft_collectibles` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_inv_act_quantity` CHECK (((`inviter_quantity` >= 1) and (`invitee_quantity` >= 1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='邀请活动配置表：双方奖励';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_invite_activities`
--

LOCK TABLES `nft_invite_activities` WRITE;
/*!40000 ALTER TABLE `nft_invite_activities` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_invite_activities` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_invite_records`
--

DROP TABLE IF EXISTS `nft_invite_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_invite_records` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `inviter_id` bigint unsigned NOT NULL COMMENT '邀请人用户ID，FK→nft_users.id',
  `invitee_id` bigint unsigned NOT NULL COMMENT '被邀请人用户ID，FK→nft_users.id，唯一（一人仅能被邀请一次）',
  `invite_code` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '注册时使用的邀请码',
  `status` enum('pending','registered') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT '绑定状态：pending已邀请未注册/registered已注册',
  `inviter_airdrop_record_id` bigint unsigned DEFAULT NULL COMMENT '邀请人奖励空投记录ID，FK→nft_airdrop_records.id',
  `invitee_airdrop_record_id` bigint unsigned DEFAULT NULL COMMENT '被邀请人奖励空投记录ID，FK→nft_airdrop_records.id',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_invitee` (`invitee_id`),
  KEY `idx_inviter` (`inviter_id`),
  KEY `fk_invite_inviter_airdrop` (`inviter_airdrop_record_id`),
  KEY `fk_invite_invitee_airdrop` (`invitee_airdrop_record_id`),
  CONSTRAINT `fk_invite_invitee` FOREIGN KEY (`invitee_id`) REFERENCES `nft_users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_invite_invitee_airdrop` FOREIGN KEY (`invitee_airdrop_record_id`) REFERENCES `nft_airdrop_records` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_invite_inviter` FOREIGN KEY (`inviter_id`) REFERENCES `nft_users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_invite_inviter_airdrop` FOREIGN KEY (`inviter_airdrop_record_id`) REFERENCES `nft_airdrop_records` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='邀请关系记录表：一人仅能被邀请一次';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_invite_records`
--

LOCK TABLES `nft_invite_records` WRITE;
/*!40000 ALTER TABLE `nft_invite_records` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_invite_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_lucky_draw_activities`
--

DROP TABLE IF EXISTS `nft_lucky_draw_activities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_lucky_draw_activities` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL COMMENT '活动名称',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态：0停用 1启用',
  `start_time` datetime(3) DEFAULT NULL COMMENT '开始时间',
  `end_time` datetime(3) DEFAULT NULL COMMENT '结束时间',
  `eligibility_type` varchar(20) NOT NULL DEFAULT 'all' COMMENT '参与资格类型：all/realname/checkin/invite/hold/checkin_rank',
  `eligibility_config` json DEFAULT NULL COMMENT '参与资格配置 JSON',
  `grant_mode` enum('realtime','manual') NOT NULL DEFAULT 'realtime' COMMENT '奖励发放方式：realtime实时到账/manual记录名单统一发放',
  `deleted_at` datetime DEFAULT NULL,
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='抽奖活动（奖项挂在 activity_id 下）';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_lucky_draw_activities`
--

LOCK TABLES `nft_lucky_draw_activities` WRITE;
/*!40000 ALTER TABLE `nft_lucky_draw_activities` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_lucky_draw_activities` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_lucky_draw_chances`
--

DROP TABLE IF EXISTS `nft_lucky_draw_chances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_lucky_draw_chances` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `user_id` bigint unsigned NOT NULL COMMENT '用户ID，FK→nft_users.id',
  `activity_id` int unsigned NOT NULL COMMENT '抽奖活动ID',
  `source` varchar(20) NOT NULL DEFAULT 'free' COMMENT '来源：checkin/invite/register/airdrop/free',
  `total_quantity` int unsigned NOT NULL DEFAULT '1' COMMENT '发放次数',
  `used_quantity` int unsigned NOT NULL DEFAULT '0' COMMENT '已使用次数',
  `related_id` bigint unsigned DEFAULT NULL COMMENT '关联业务ID',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_user_activity` (`user_id`,`activity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='抽奖次数台账表';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_lucky_draw_chances`
--

LOCK TABLES `nft_lucky_draw_chances` WRITE;
/*!40000 ALTER TABLE `nft_lucky_draw_chances` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_lucky_draw_chances` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_lucky_draw_prizes`
--

DROP TABLE IF EXISTS `nft_lucky_draw_prizes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_lucky_draw_prizes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `activity_id` int unsigned NOT NULL COMMENT '抽奖活动/期数ID（运营配置标识，暂无独立活动表，仅建索引）',
  `tier_name` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '奖档名（如：普通/稀有/史诗/传说）',
  `prize_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '奖项名称（管理员填写，空则取奖档名）',
  `prize_image` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '奖项图片URL（管理员上传）',
  `reward_config` json DEFAULT NULL COMMENT '奖励配置 JSON（按 prize_type：collectibleId/blindboxId/amount/quantity/expiresAt）',
  `prize_type` enum('collectible','points','draw_chance','priority_qualification','eligibility_qualification','blindbox','none') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'collectible' COMMENT '奖品类型：collectible藏品/points司南币/draw_chance抽奖次数/priority_qualification优先购资格/eligibility_qualification资格购资格/blindbox盲盒/none谢谢参与',
  `collectible_id` int unsigned DEFAULT NULL COMMENT '藏品奖ID，FK→nft_collectibles.id（prize_type=collectible 时必填）',
  `coin_amount` decimal(12,2) DEFAULT NULL COMMENT '司南币奖金额（prize_type=points 时必填）',
  `total` int unsigned NOT NULL COMMENT '奖品总量',
  `won` int unsigned NOT NULL DEFAULT '0' COMMENT '已被抽中数量',
  `sort_order` int NOT NULL DEFAULT '0' COMMENT '转盘展示顺序',
  `probability` decimal(5,4) NOT NULL DEFAULT '0.0000' COMMENT '中奖概率（同一活动合计=1，业务层兜底归一化；v2.2.3 新增）',
  `deleted_at` datetime DEFAULT NULL COMMENT '软删除时间（禁止物理删除，保证历史抽奖可审计）',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_activity` (`activity_id`),
  KEY `fk_prizes_collectible` (`collectible_id`),
  CONSTRAINT `fk_prizes_collectible` FOREIGN KEY (`collectible_id`) REFERENCES `nft_collectibles` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_prizes_domain` CHECK (((`total` >= 0) and (`won` >= 0) and (`won` <= `total`) and ((`coin_amount` is null) or (`coin_amount` >= 0)) and (`probability` >= 0) and (`probability` <= 1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='抽奖奖品池表：概率与限量';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_lucky_draw_prizes`
--

LOCK TABLES `nft_lucky_draw_prizes` WRITE;
/*!40000 ALTER TABLE `nft_lucky_draw_prizes` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_lucky_draw_prizes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_lucky_draw_records`
--

DROP TABLE IF EXISTS `nft_lucky_draw_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_lucky_draw_records` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `user_id` bigint unsigned NOT NULL COMMENT '抽奖用户ID，FK→nft_users.id',
  `prize_id` int unsigned NOT NULL COMMENT '奖品ID，FK→nft_lucky_draw_prizes.id',
  `user_collectible_id` bigint unsigned DEFAULT NULL COMMENT '获得资产ID，FK→nft_user_collectibles.id（藏品奖回填，points/none 为空）',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '抽奖时间',
  PRIMARY KEY (`id`),
  KEY `idx_user_created` (`user_id`,`created_at`),
  KEY `fk_ld_prize` (`prize_id`),
  KEY `fk_ld_user_collectible` (`user_collectible_id`),
  CONSTRAINT `fk_ld_prize` FOREIGN KEY (`prize_id`) REFERENCES `nft_lucky_draw_prizes` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_ld_user` FOREIGN KEY (`user_id`) REFERENCES `nft_users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_ld_user_collectible` FOREIGN KEY (`user_collectible_id`) REFERENCES `nft_user_collectibles` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='抽奖记录表：抽奖流水';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_lucky_draw_records`
--

LOCK TABLES `nft_lucky_draw_records` WRITE;
/*!40000 ALTER TABLE `nft_lucky_draw_records` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_lucky_draw_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_orders`
--

DROP TABLE IF EXISTS `nft_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_orders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `order_no` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '订单号，唯一（如 JC+日期+随机）',
  `user_id` bigint unsigned NOT NULL COMMENT '买家用户ID，FK→nft_users.id',
  `collectible_id` int unsigned NOT NULL COMMENT '藏品ID，FK→nft_collectibles.id',
  `resale_listing_id` bigint unsigned DEFAULT NULL COMMENT '市场挂单ID，FK→nft_resale_listings.id（市场单关联；循环外键，脚本末尾ALTER补挂）',
  `batch_listing_ids` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '批量市场单关联的挂单ID列表（逗号分隔），非空表示批量购买订单',
  `unit_price` decimal(10,2) NOT NULL COMMENT '单价（元）',
  `quantity` int unsigned NOT NULL DEFAULT '1' COMMENT '数量',
  `total_price` decimal(10,2) NOT NULL COMMENT '总金额（元）',
  `status` enum('pending','completed','cancelled','refunding','refunded') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT '订单状态：pending待支付 completed已完成 cancelled已取消 refunding退款中 refunded已退款',
  `source` enum('release','market','priority','eligibility','raffle') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'release' COMMENT '订单来源：release 公售 / market 市场寄售 / priority 优先购 / eligibility 资格购 / raffle 抽签购',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '下单时间',
  `paid_at` datetime(3) DEFAULT NULL COMMENT '支付时间',
  `completed_at` datetime(3) DEFAULT NULL COMMENT '完成时间',
  `cancelled_at` datetime(3) DEFAULT NULL COMMENT '取消时间',
  `cancel_reason` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '取消原因（超时/手动）',
  `expires_at` datetime(3) NOT NULL COMMENT '待支付截止时间（下单+超时秒数，超时自动取消并释放库存）',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  `deleted_at` datetime DEFAULT NULL COMMENT '删除时间（软删除回收站，null=未删除）',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_no` (`order_no`),
  KEY `idx_user_status` (`user_id`,`status`),
  KEY `idx_user_created` (`user_id`,`created_at`),
  KEY `idx_resale_listing` (`resale_listing_id`),
  KEY `idx_expires` (`expires_at`),
  KEY `fk_orders_collectible` (`collectible_id`),
  CONSTRAINT `fk_orders_collectible` FOREIGN KEY (`collectible_id`) REFERENCES `nft_collectibles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_orders_resale_listing` FOREIGN KEY (`resale_listing_id`) REFERENCES `nft_resale_listings` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`) REFERENCES `nft_users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `chk_orders_amount` CHECK (((`quantity` >= 1) and (`unit_price` >= 0) and (`total_price` >= 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='订单表：发售与市场购买统一';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_orders`
--

LOCK TABLES `nft_orders` WRITE;
/*!40000 ALTER TABLE `nft_orders` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_payment_channels`
--

DROP TABLE IF EXISTS `nft_payment_channels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_payment_channels` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `channel_code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '渠道编码：balance/alipay/wechat/huifu/unionpay/yeepay',
  `channel_name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '渠道名称：余额/支付宝/微信支付/汇付天下/银联/易宝支付',
  `fee_rate` decimal(5,2) NOT NULL DEFAULT '0.00' COMMENT '渠道手续费率（%）',
  `status` tinyint NOT NULL DEFAULT '0' COMMENT '状态：1启用 0停用',
  `is_recommended` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否推荐展示：1是',
  `sort_order` int NOT NULL DEFAULT '0' COMMENT '排序（升序）',
  `config` text COLLATE utf8mb4_unicode_ci COMMENT '渠道配置JSON（应用层AES加密存储：app_id/mch_id/私钥/网关等）',
  `remark` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '备注',
  `updated_by` int unsigned DEFAULT NULL COMMENT '最后修改人ID',
  `updated_by_name` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '最后修改人姓名',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_channel_code` (`channel_code`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='第三方支付渠道配置表（钱包支付通道）';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_payment_channels`
--

LOCK TABLES `nft_payment_channels` WRITE;
/*!40000 ALTER TABLE `nft_payment_channels` DISABLE KEYS */;
INSERT INTO `nft_payment_channels` VALUES (1,'balance','余额支付',0.00,1,1,1,NULL,'平台钱包余额支付（司南币/现金余额），无需第三方配置',NULL,NULL,'2026-09-21 15:27:46.801','2026-09-21 15:27:46.801'),(2,'alipay','支付宝',0.60,0,0,2,NULL,'支付宝当面付/APP支付，需配置应用ID与私钥',NULL,NULL,'2026-09-21 15:27:46.801','2026-09-21 15:27:46.801'),(3,'wechat','微信支付',0.60,0,0,3,NULL,'微信Native/JSAPI支付，需配置商户号与API密钥',NULL,NULL,'2026-09-21 15:27:46.801','2026-09-21 15:27:46.801'),(4,'huifu','汇付天下',0.38,0,0,4,NULL,'汇付天下聚合支付（钱包brand默认汇付），需配置商户号',NULL,NULL,'2026-09-21 15:27:46.801','2026-09-21 15:27:46.801'),(5,'unionpay','银联支付',0.50,0,0,5,NULL,'银联在线支付，需配置商户号与证书',NULL,NULL,'2026-09-21 15:27:46.801','2026-09-21 15:27:46.801'),(6,'yeepay','易宝支付',0.50,0,0,6,NULL,'易宝支付聚合支付，需配置商户号与密钥',NULL,NULL,'2026-09-21 15:27:46.801','2026-09-21 15:27:46.801');
/*!40000 ALTER TABLE `nft_payment_channels` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_payments`
--

DROP TABLE IF EXISTS `nft_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_payments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `order_id` bigint unsigned NOT NULL COMMENT '订单ID，FK→nft_orders.id，唯一（1:1）',
  `user_id` bigint unsigned NOT NULL COMMENT '支付用户ID，FK→nft_users.id',
  `amount` decimal(10,2) NOT NULL COMMENT '实付金额（元）',
  `payment_method` enum('balance','alipay','wechat') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '支付方式：balance余额（依赖钱包）/alipay支付宝/wechat微信',
  `transaction_no` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '第三方支付流水号',
  `status` enum('pending','success','failed','refunded') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT '支付状态：pending待支付/success成功/failed失败/refunded已退款',
  `paid_at` datetime(3) DEFAULT NULL COMMENT '支付成功时间',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order` (`order_id`),
  KEY `idx_user_status` (`user_id`,`status`),
  CONSTRAINT `fk_payments_order` FOREIGN KEY (`order_id`) REFERENCES `nft_orders` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_payments_user` FOREIGN KEY (`user_id`) REFERENCES `nft_users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `chk_payments_amount` CHECK ((`amount` >= 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='支付记录表：与订单1:1';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_payments`
--

LOCK TABLES `nft_payments` WRITE;
/*!40000 ALTER TABLE `nft_payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_platform_cleanup_logs`
--

DROP TABLE IF EXISTS `nft_platform_cleanup_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_platform_cleanup_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `admin_id` int unsigned NOT NULL COMMENT '操作人ID',
  `admin_name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '操作人姓名',
  `admin_phone` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '操作人绑定手机（短信验证接收号）',
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '操作IP',
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '清库原因',
  `backup_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '备份文件路径',
  `affected_users` int unsigned NOT NULL DEFAULT '0' COMMENT '影响用户数量',
  `affected_orders` int unsigned NOT NULL DEFAULT '0' COMMENT '影响订单数量',
  `execution_time` int unsigned NOT NULL DEFAULT '0' COMMENT '执行耗时（秒）',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '状态：1成功 2失败',
  `error_message` text COLLATE utf8mb4_unicode_ci COMMENT '错误信息',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_admin` (`admin_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='平台清库操作日志表（执行前自动备份）';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_platform_cleanup_logs`
--

LOCK TABLES `nft_platform_cleanup_logs` WRITE;
/*!40000 ALTER TABLE `nft_platform_cleanup_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_platform_cleanup_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_priority_activities`
--

DROP TABLE IF EXISTS `nft_priority_activities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_priority_activities` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `collectible_id` int unsigned NOT NULL COMMENT '藏品ID，FK→nft_collectibles.id',
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '活动名称',
  `start_time` datetime DEFAULT NULL COMMENT '优先购开始时间',
  `end_time` datetime DEFAULT NULL COMMENT '优先购结束时间',
  `status` enum('disabled','enabled','ended') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'disabled' COMMENT '状态：disabled停用 enabled启用 ended结束',
  `remark` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '备注',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_collectible` (`collectible_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_pri_collectible` FOREIGN KEY (`collectible_id`) REFERENCES `nft_collectibles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='优先购活动表（时间优先通道，独立于资格购）';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_priority_activities`
--

LOCK TABLES `nft_priority_activities` WRITE;
/*!40000 ALTER TABLE `nft_priority_activities` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_priority_activities` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_priority_sale_whitelists`
--

DROP TABLE IF EXISTS `nft_priority_sale_whitelists`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_priority_sale_whitelists` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `priority_sale_id` int unsigned NOT NULL COMMENT '优先购活动ID，FK→nft_priority_sales.id',
  `user_id` bigint unsigned NOT NULL COMMENT '用户ID，FK→nft_users.id',
  `phone` varchar(11) NOT NULL COMMENT '手机号',
  `max_quantity` int unsigned NOT NULL DEFAULT '1' COMMENT '可购上限',
  `used_quantity` int unsigned NOT NULL DEFAULT '0' COMMENT '已购数量',
  `expires_at` datetime NOT NULL COMMENT '资格过期时间',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态：0失效 1有效',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sale_user` (`priority_sale_id`,`user_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='优先购白名单表';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_priority_sale_whitelists`
--

LOCK TABLES `nft_priority_sale_whitelists` WRITE;
/*!40000 ALTER TABLE `nft_priority_sale_whitelists` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_priority_sale_whitelists` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_priority_sales`
--

DROP TABLE IF EXISTS `nft_priority_sales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_priority_sales` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `collectible_id` int unsigned NOT NULL COMMENT '目标藏品ID，FK→nft_collectibles.id',
  `name` varchar(100) NOT NULL COMMENT '活动名称',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态：0停用 1启用',
  `start_time` datetime NOT NULL COMMENT '开始时间',
  `end_time` datetime NOT NULL COMMENT '结束时间',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_collectible_status` (`collectible_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='优先购活动表（奖励发放自动落位）';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_priority_sales`
--

LOCK TABLES `nft_priority_sales` WRITE;
/*!40000 ALTER TABLE `nft_priority_sales` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_priority_sales` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_priority_whitelists`
--

DROP TABLE IF EXISTS `nft_priority_whitelists`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_priority_whitelists` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `activity_id` int unsigned NOT NULL COMMENT '优先购活动ID，FK→nft_priority_activities.id',
  `user_id` bigint unsigned NOT NULL COMMENT '用户ID，FK→nft_users.id',
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '手机号（快照）',
  `max_quantity` int unsigned NOT NULL DEFAULT '1' COMMENT '最大购买量',
  `used_quantity` int unsigned NOT NULL DEFAULT '0' COMMENT '已用配额',
  `expires_at` datetime DEFAULT NULL COMMENT '资格有效期，NULL=跟随活动',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '状态：1有效 0停用',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_activity_user` (`activity_id`,`user_id`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `fk_pw_activity` FOREIGN KEY (`activity_id`) REFERENCES `nft_priority_activities` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pw_user` FOREIGN KEY (`user_id`) REFERENCES `nft_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_pri_used` CHECK ((`used_quantity` <= `max_quantity`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='优先购白名单表';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_priority_whitelists`
--

LOCK TABLES `nft_priority_whitelists` WRITE;
/*!40000 ALTER TABLE `nft_priority_whitelists` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_priority_whitelists` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_qualification_configs`
--

DROP TABLE IF EXISTS `nft_qualification_configs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_qualification_configs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `collectible_id` int unsigned NOT NULL COMMENT '藏品ID，FK→nft_collectibles.id',
  `is_enabled` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否开启：1开启 0关闭',
  `required_collectible_ids` json DEFAULT NULL COMMENT '资格藏品ID数组：持有任一即满足（条件之一）',
  `required_checkin_days` int unsigned NOT NULL DEFAULT '0' COMMENT '要求累计签到天数，0=不限',
  `required_invite_count` int unsigned NOT NULL DEFAULT '0' COMMENT '要求累计邀请人数，0=不限',
  `condition_type` tinyint NOT NULL DEFAULT '1' COMMENT '组合方式：1满足任一 2满足全部',
  `valid_start_at` datetime DEFAULT NULL COMMENT '资格有效期开始',
  `valid_end_at` datetime DEFAULT NULL COMMENT '资格有效期结束，NULL=不限',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_collectible` (`collectible_id`),
  CONSTRAINT `fk_qual_collectible` FOREIGN KEY (`collectible_id`) REFERENCES `nft_collectibles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_qual_checkin` CHECK (((`required_checkin_days` >= 0) and (`required_invite_count` >= 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='资格购配置表（购买门槛，不冻结库存）';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_qualification_configs`
--

LOCK TABLES `nft_qualification_configs` WRITE;
/*!40000 ALTER TABLE `nft_qualification_configs` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_qualification_configs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_qualification_whitelists`
--

DROP TABLE IF EXISTS `nft_qualification_whitelists`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_qualification_whitelists` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `config_id` int unsigned NOT NULL COMMENT '资格购配置ID，FK→nft_qualification_configs.id',
  `user_id` bigint unsigned NOT NULL COMMENT '用户ID，FK→nft_users.id',
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '手机号（快照）',
  `expires_at` datetime DEFAULT NULL COMMENT '资格有效期，NULL=永久',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_config_user` (`config_id`,`user_id`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `fk_qw_config` FOREIGN KEY (`config_id`) REFERENCES `nft_qualification_configs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_qw_user` FOREIGN KEY (`user_id`) REFERENCES `nft_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='资格购白名单（白名单用户无需满足条件）';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_qualification_whitelists`
--

LOCK TABLES `nft_qualification_whitelists` WRITE;
/*!40000 ALTER TABLE `nft_qualification_whitelists` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_qualification_whitelists` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_raffle_activities`
--

DROP TABLE IF EXISTS `nft_raffle_activities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_raffle_activities` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `collectible_id` bigint unsigned NOT NULL COMMENT '藏品 ID',
  `name` varchar(120) NOT NULL COMMENT '活动名称',
  `description` text COMMENT '活动规则说明',
  `ticket_price` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '抽签报名费（0=免费）',
  `total_supply` int unsigned NOT NULL DEFAULT '0' COMMENT '藏品总发行量',
  `draw_code_enabled` tinyint NOT NULL DEFAULT '0' COMMENT '是否开放购买抽签码 0关 1开',
  `draw_code_price` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '抽签码购买单价（与 sale_price 分离）',
  `winner_count` int unsigned NOT NULL COMMENT '中签名额数',
  `draw_win_count` int unsigned NOT NULL DEFAULT '0' COMMENT '本次抽签中签名额（开奖后锁定）',
  `buy_code_limit` int unsigned NOT NULL DEFAULT '5' COMMENT '购买抽签码上限（draw_code_enabled=1 时生效，须>0）',
  `invite_enabled` tinyint(1) NOT NULL DEFAULT '0' COMMENT '邀请好友参与抽签得码开关 0关 1开',
  `invite_code_limit` int unsigned NOT NULL DEFAULT '5' COMMENT '邀请好友最多可得抽签码数（invite_enabled=1 时生效）',
  `invite_user_needed` int unsigned NOT NULL DEFAULT '1' COMMENT '每邀请 N 名好友参与抽签得 1 个码',
  `max_wins_per_user` int unsigned NOT NULL DEFAULT '1' COMMENT '单用户最大中签数（每个码最多中1次）0不限-按码数封顶 默认1每人1签',
  `win_locked` tinyint(1) NOT NULL DEFAULT '0' COMMENT '开奖后名额锁定 0未锁 1已锁',
  `drawn_at` datetime DEFAULT NULL COMMENT '实际开奖时间',
  `sale_quantity` int unsigned NOT NULL COMMENT '中签用户每人可购买数量',
  `sale_price` decimal(10,2) NOT NULL COMMENT '中签后购买价',
  `registration_start` datetime NOT NULL COMMENT '报名开始时间',
  `registration_end` datetime NOT NULL COMMENT '报名截止时间',
  `draw_time` datetime NOT NULL COMMENT '抽签时间（自动执行）',
  `purchase_start` datetime DEFAULT NULL COMMENT '中签购买有效期开始',
  `purchase_end` datetime DEFAULT NULL COMMENT '中签购买有效期结束',
  `status` tinyint NOT NULL DEFAULT '0' COMMENT '0草稿 1报名中 2抽签中 3已结束 4已取消',
  `draw_result` json DEFAULT NULL COMMENT '抽签结果快照 [{user_id, user_name, phone}]',
  `extra` json DEFAULT NULL COMMENT '扩展字段',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_collectible` (`collectible_id`),
  KEY `idx_status` (`status`),
  KEY `idx_draw_time` (`draw_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='抽签发售活动';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_raffle_activities`
--

LOCK TABLES `nft_raffle_activities` WRITE;
/*!40000 ALTER TABLE `nft_raffle_activities` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_raffle_activities` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_raffle_operation_logs`
--

DROP TABLE IF EXISTS `nft_raffle_operation_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_raffle_operation_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` bigint unsigned DEFAULT NULL COMMENT '操作管理员 ID',
  `admin_name` varchar(64) DEFAULT NULL COMMENT '操作管理员名称',
  `activity_id` bigint unsigned DEFAULT NULL COMMENT '关联抽签活动 ID',
  `action` varchar(50) NOT NULL COMMENT '动作标识 set_force_win/cancel_force_win/change_quota/change_buy_limit/change_total_supply/draw/code_import/... ',
  `action_desc` varchar(255) NOT NULL COMMENT '动作中文描述',
  `detail` json DEFAULT NULL COMMENT '变更明细（前后值/名单）',
  `ip` varchar(45) DEFAULT NULL COMMENT '操作 IP',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_activity` (`activity_id`),
  KEY `idx_admin` (`admin_id`),
  KEY `idx_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='抽签独立操作日志（不可删除）';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_raffle_operation_logs`
--

LOCK TABLES `nft_raffle_operation_logs` WRITE;
/*!40000 ALTER TABLE `nft_raffle_operation_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_raffle_operation_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_raffle_registrations`
--

DROP TABLE IF EXISTS `nft_raffle_registrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_raffle_registrations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `activity_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `ticket_count` int unsigned NOT NULL DEFAULT '1' COMMENT '报名票数',
  `pay_amount` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '报名费合计',
  `pay_status` tinyint NOT NULL DEFAULT '0' COMMENT '0未支付 1已支付 2已退款',
  `draw_status` tinyint NOT NULL DEFAULT '0' COMMENT '0未抽签 1中签 2未中',
  `is_force_win` tinyint(1) NOT NULL DEFAULT '0' COMMENT '强制必中标记 0否 1是',
  `force_set_by` bigint unsigned DEFAULT NULL COMMENT '设置必中的管理员ID',
  `force_set_at` datetime DEFAULT NULL COMMENT '设置必中时间',
  `win_count` int unsigned NOT NULL DEFAULT '0' COMMENT '中签次数（每次中签可购中签后限购数量件）',
  `purchased_quantity` int unsigned NOT NULL DEFAULT '0' COMMENT '中签后已购买数量（限购 sale_quantity）',
  `win_paid` tinyint(1) NOT NULL DEFAULT '0' COMMENT '管理员标记已付款 0否 1是',
  `win_paid_at` datetime DEFAULT NULL COMMENT '标记付款时间',
  `win_verified` tinyint(1) NOT NULL DEFAULT '0' COMMENT '已核销 0否 1是',
  `win_verified_at` datetime DEFAULT NULL COMMENT '核销时间',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_activity_user` (`activity_id`,`user_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_draw_status` (`draw_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='抽签发售报名记录';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_raffle_registrations`
--

LOCK TABLES `nft_raffle_registrations` WRITE;
/*!40000 ALTER TABLE `nft_raffle_registrations` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_raffle_registrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_refunds`
--

DROP TABLE IF EXISTS `nft_refunds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_refunds` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `refund_no` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '退款单号：RF+时间+随机',
  `order_id` bigint unsigned NOT NULL COMMENT '关联订单ID，FK→nft_orders.id',
  `payment_id` bigint unsigned NOT NULL COMMENT '关联支付记录ID，FK→nft_payments.id',
  `user_id` bigint unsigned NOT NULL COMMENT '用户ID，FK→nft_users.id',
  `amount` decimal(10,2) NOT NULL COMMENT '退款金额',
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '退款原因',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '状态：1待审批 2已批准 3已拒绝 4已退款',
  `applicant_id` int unsigned NOT NULL COMMENT '申请人（管理员）ID',
  `applicant_name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '申请人姓名',
  `approver_id` int unsigned DEFAULT NULL COMMENT '审批人ID',
  `approver_name` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '审批人姓名',
  `approved_at` datetime DEFAULT NULL COMMENT '审批时间',
  `refunded_at` datetime DEFAULT NULL COMMENT '实际退款时间',
  `refund_channel` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '退款渠道：balance/alipay/wechat',
  `comment` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '审批意见',
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '操作IP',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_refund_no` (`refund_no`),
  KEY `idx_order` (`order_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created` (`created_at`),
  KEY `fk_refund_payment` (`payment_id`),
  CONSTRAINT `fk_refund_order` FOREIGN KEY (`order_id`) REFERENCES `nft_orders` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_refund_payment` FOREIGN KEY (`payment_id`) REFERENCES `nft_payments` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_refund_user` FOREIGN KEY (`user_id`) REFERENCES `nft_users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `chk_refund_amount` CHECK ((`amount` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='退款记录表（大额退款需审批）';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_refunds`
--

LOCK TABLES `nft_refunds` WRITE;
/*!40000 ALTER TABLE `nft_refunds` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_refunds` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_register_activities`
--

DROP TABLE IF EXISTS `nft_register_activities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_register_activities` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `name` varchar(100) NOT NULL COMMENT '活动名称',
  `status` enum('disabled','enabled') NOT NULL DEFAULT 'disabled' COMMENT '总开关：disabled关闭/enabled开启',
  `start_time` datetime(3) DEFAULT NULL COMMENT '开始时间',
  `end_time` datetime(3) DEFAULT NULL COMMENT '结束时间',
  `tiers` json DEFAULT NULL COMMENT '实名前N名档位奖励 JSON：[{rankLimit, rewards:[{type,...}]}]',
  `grant_mode` enum('realtime','manual') NOT NULL DEFAULT 'realtime' COMMENT '奖励发放方式：realtime实时到账/manual记录名单统一发放',
  `used_count` int unsigned NOT NULL DEFAULT '0' COMMENT '已发放人数',
  `description` text COMMENT '活动说明文案',
  `deleted_at` datetime DEFAULT NULL COMMENT '软删除时间，NULL未删除',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='注册活动表：实名前N名档位奖励';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_register_activities`
--

LOCK TABLES `nft_register_activities` WRITE;
/*!40000 ALTER TABLE `nft_register_activities` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_register_activities` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_resale_listings`
--

DROP TABLE IF EXISTS `nft_resale_listings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_resale_listings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `seller_id` bigint unsigned NOT NULL COMMENT '卖家用户ID，FK→nft_users.id',
  `collectible_id` int unsigned NOT NULL COMMENT '藏品ID，FK→nft_collectibles.id（不存快照，决策4）',
  `user_collectible_id` bigint unsigned NOT NULL COMMENT '挂单资产ID，FK→nft_user_collectibles.id（在售期间唯一，见 selling_ucid）',
  `price` decimal(10,2) NOT NULL COMMENT '寄售价（元）',
  `fee_rate` decimal(5,2) DEFAULT NULL COMMENT '手续费率（%，默认取系统配置 resale_fee_rate）',
  `fee_amount` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '手续费（元，=price×fee_rate/100）',
  `actual_amount` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '预计到账（元，=price−fee_amount）',
  `status` enum('selling','sold','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'selling' COMMENT '挂单状态：selling在售/sold已售/cancelled已取消',
  `is_system_delisted` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否被系统强制下架：1是（寄售开关关闭触发）',
  `system_delisted_at` datetime(3) DEFAULT NULL COMMENT '系统下架时间',
  `delist_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '下架原因',
  `listed_at` datetime(3) NOT NULL COMMENT '挂单时间',
  `cooldown_until` datetime(3) DEFAULT NULL COMMENT '取消后冷却截止（=取消时刻+resale_cooldown_seconds秒，冷却期内禁止重新挂单）',
  `selling_ucid` bigint unsigned GENERATED ALWAYS AS ((case when (`status` = _utf8mb4'selling') then `user_collectible_id` else NULL end)) STORED COMMENT '在售唯一辅助列（生成列）：selling 时取资产ID，否则 NULL',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_selling_ucid` (`selling_ucid`),
  KEY `idx_collectible_status` (`collectible_id`,`status`),
  KEY `idx_seller_status` (`seller_id`,`status`),
  KEY `fk_resale_user_collectible` (`user_collectible_id`),
  CONSTRAINT `fk_resale_collectible` FOREIGN KEY (`collectible_id`) REFERENCES `nft_collectibles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_resale_seller` FOREIGN KEY (`seller_id`) REFERENCES `nft_users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_resale_user_collectible` FOREIGN KEY (`user_collectible_id`) REFERENCES `nft_user_collectibles` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_resale_amount` CHECK (((`price` >= 0) and (`fee_amount` >= 0) and (`actual_amount` >= 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='寄售挂单表：市场寄售，同一资产在售唯一';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_resale_listings`
--

LOCK TABLES `nft_resale_listings` WRITE;
/*!40000 ALTER TABLE `nft_resale_listings` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_resale_listings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_risk_alerts`
--

DROP TABLE IF EXISTS `nft_risk_alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_risk_alerts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `alert_type` tinyint NOT NULL COMMENT '类型：1大额充值 2频繁小额充值 3余额突变 4高频API 5异常时间操作 6异地登录 7批量注册 8异常价格 9其他',
  `alert_level` tinyint NOT NULL DEFAULT '1' COMMENT '等级：1低 2中 3高 4紧急',
  `user_id` bigint unsigned DEFAULT NULL COMMENT '关联用户ID',
  `target_id` bigint unsigned DEFAULT NULL COMMENT '关联业务ID',
  `target_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '关联业务类型',
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '告警标题',
  `description` text COLLATE utf8mb4_unicode_ci COMMENT '告警详情',
  `evidence` json DEFAULT NULL COMMENT '证据数据',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '状态：1未处理 2处理中 3已处理 4已忽略',
  `handler_id` int unsigned DEFAULT NULL COMMENT '处理人ID',
  `handler_name` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '处理人姓名',
  `handled_at` datetime DEFAULT NULL COMMENT '处理时间',
  `handle_comment` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '处理意见',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_type` (`alert_type`),
  KEY `idx_level` (`alert_level`),
  KEY `idx_status` (`status`),
  KEY `idx_user` (`user_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='风控告警表';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_risk_alerts`
--

LOCK TABLES `nft_risk_alerts` WRITE;
/*!40000 ALTER TABLE `nft_risk_alerts` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_risk_alerts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_security_events`
--

DROP TABLE IF EXISTS `nft_security_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_security_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `event_type` tinyint NOT NULL COMMENT '类型：1越权尝试 2支付回调异常 3Token异常 4暴力破解 5其他',
  `event_level` tinyint NOT NULL DEFAULT '1' COMMENT '等级：1低 2中 3高 4紧急',
  `admin_id` int unsigned DEFAULT NULL COMMENT '关联管理员ID',
  `user_id` bigint unsigned DEFAULT NULL COMMENT '关联用户ID',
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'IP地址',
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'User-Agent',
  `request_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '请求路径',
  `request_method` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '请求方法',
  `request_params` text COLLATE utf8mb4_unicode_ci COMMENT '请求参数（脱敏）',
  `response_status` int DEFAULT NULL COMMENT '响应状态码',
  `description` text COLLATE utf8mb4_unicode_ci COMMENT '事件描述',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '状态：1未处理 2已确认 3已处理 4误报',
  `handle_comment` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '处理意见',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_type` (`event_type`),
  KEY `idx_level` (`event_level`),
  KEY `idx_status` (`status`),
  KEY `idx_ip` (`ip`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='安全事件审计表';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_security_events`
--

LOCK TABLES `nft_security_events` WRITE;
/*!40000 ALTER TABLE `nft_security_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_security_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_site_settings`
--

DROP TABLE IF EXISTS `nft_site_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_site_settings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `setting_key` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '配置键，唯一',
  `setting_value` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '配置值',
  `setting_group` enum('basic','theme','button','seo') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'basic' COMMENT '配置分组：basic基础/theme主题/button按钮色/seo搜索优化',
  `description` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '配置说明',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_setting_key` (`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='网站全局配置表：分组KV';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_site_settings`
--

LOCK TABLES `nft_site_settings` WRITE;
/*!40000 ALTER TABLE `nft_site_settings` DISABLE KEYS */;
INSERT INTO `nft_site_settings` VALUES (1,'site_name','司南数字藏品','basic','站点名称','2026-09-21 15:27:44.859','2026-09-21 15:27:44.859');
/*!40000 ALTER TABLE `nft_site_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_sms_configs`
--

DROP TABLE IF EXISTS `nft_sms_configs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_sms_configs` (
  `id` tinyint unsigned NOT NULL DEFAULT '1' COMMENT '固定为1，单行配置',
  `provider` enum('mock','aliyun','tencent') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'mock' COMMENT '短信服务商：mock模拟 aliyun阿里云 tencent腾讯云',
  `is_enabled` tinyint(1) NOT NULL DEFAULT '1' COMMENT '短信总开关：1开 0关（关闭时全部走mock）',
  `access_key` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'AccessKey ID（应用层AES加密存储）',
  `access_secret` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'AccessKey Secret（应用层AES加密存储）',
  `signature` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '短信签名（如：司南数字藏品）',
  `template_register` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '注册验证码模板Code',
  `template_login` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '登录验证码模板Code',
  `template_reset` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '重置密码验证码模板Code',
  `daily_limit` int unsigned NOT NULL DEFAULT '0' COMMENT '每日发送上限，0=不限',
  `last_test_at` datetime DEFAULT NULL COMMENT '最后测试发送时间',
  `last_test_status` tinyint DEFAULT NULL COMMENT '最后测试结果：1成功 2失败',
  `last_test_message` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '最后测试结果信息',
  `updated_by` int unsigned DEFAULT NULL COMMENT '最后修改人ID',
  `updated_by_name` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '最后修改人姓名',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='短信服务配置表（单行，密钥加密存储）';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_sms_configs`
--

LOCK TABLES `nft_sms_configs` WRITE;
/*!40000 ALTER TABLE `nft_sms_configs` DISABLE KEYS */;
INSERT INTO `nft_sms_configs` VALUES (1,'mock',1,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,'2026-09-21 15:27:46.799','2026-09-21 15:27:46.799');
/*!40000 ALTER TABLE `nft_sms_configs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_support_tickets`
--

DROP TABLE IF EXISTS `nft_support_tickets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_support_tickets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `ticket_no` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '工单编号：TK+时间+随机',
  `user_id` bigint unsigned NOT NULL COMMENT '用户ID，FK→nft_users.id',
  `user_phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '用户手机号（快照）',
  `ticket_type` tinyint NOT NULL COMMENT '类型：1支付异常 2藏品丢失 3盲盒问题 4转赠纠纷 5账号问题 6其他',
  `priority` tinyint NOT NULL DEFAULT '3' COMMENT '优先级：1紧急 2高 3中 4低',
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '工单标题',
  `description` text COLLATE utf8mb4_unicode_ci COMMENT '工单描述',
  `related_order_id` bigint unsigned DEFAULT NULL COMMENT '关联订单ID（泛关联）',
  `related_collectible_id` int unsigned DEFAULT NULL COMMENT '关联藏品ID（泛关联）',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '状态：1待处理 2处理中 3待用户确认 4已解决 5已关闭',
  `assignee_id` int unsigned DEFAULT NULL COMMENT '分配处理人（管理员）ID',
  `assignee_name` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '处理人姓名',
  `solved_at` datetime DEFAULT NULL COMMENT '解决时间',
  `closed_at` datetime DEFAULT NULL COMMENT '关闭时间',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ticket_no` (`ticket_no`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_priority` (`priority`),
  KEY `idx_assignee` (`assignee_id`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `fk_ticket_user` FOREIGN KEY (`user_id`) REFERENCES `nft_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='客服工单表';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_support_tickets`
--

LOCK TABLES `nft_support_tickets` WRITE;
/*!40000 ALTER TABLE `nft_support_tickets` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_support_tickets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_swap_plan_details`
--

DROP TABLE IF EXISTS `nft_swap_plan_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_swap_plan_details` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `plan_id` bigint unsigned NOT NULL COMMENT '计划ID，FK→nft_swap_plans.id',
  `user_id` bigint unsigned NOT NULL COMMENT '用户ID，FK→nft_users.id',
  `action` tinyint NOT NULL COMMENT '动作：1回收源藏品 2空投新藏品',
  `collectible_id` int unsigned NOT NULL COMMENT '藏品ID（源或目标）',
  `collectible_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '藏品名称快照',
  `serial` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '资产编号',
  `user_collectible_id` bigint unsigned NOT NULL COMMENT '资产行ID，FK→nft_user_collectibles.id',
  `ratio` int unsigned DEFAULT NULL COMMENT '比例（仅回收行：该藏品每份对应空投数）',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_plan` (`plan_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_plan_action` (`plan_id`,`action`),
  KEY `idx_user_collectible` (`user_collectible_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='置换计划资产明细（回收+空投）';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_swap_plan_details`
--

LOCK TABLES `nft_swap_plan_details` WRITE;
/*!40000 ALTER TABLE `nft_swap_plan_details` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_swap_plan_details` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_swap_plan_items`
--

DROP TABLE IF EXISTS `nft_swap_plan_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_swap_plan_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `plan_id` bigint unsigned NOT NULL COMMENT '计划ID，FK→nft_swap_plans.id',
  `old_collectible_id` int unsigned NOT NULL COMMENT '源（旧）藏品ID',
  `old_collectible_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '源藏品名称快照',
  `ratio` int unsigned NOT NULL DEFAULT '1' COMMENT '置换比例：每持有 1 份源藏品空投 N 份新藏品',
  `recovered_count` int unsigned NOT NULL DEFAULT '0' COMMENT '该藏品实际回收份数',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_plan` (`plan_id`),
  KEY `idx_old_collectible` (`old_collectible_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='置换计划源藏品配置';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_swap_plan_items`
--

LOCK TABLES `nft_swap_plan_items` WRITE;
/*!40000 ALTER TABLE `nft_swap_plan_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_swap_plan_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_swap_plan_users`
--

DROP TABLE IF EXISTS `nft_swap_plan_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_swap_plan_users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `plan_id` bigint unsigned NOT NULL COMMENT '计划ID，FK→nft_swap_plans.id',
  `user_id` bigint unsigned NOT NULL COMMENT '用户ID，FK→nft_users.id',
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '用户手机号',
  `uid` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '用户UID',
  `holdings` json DEFAULT NULL COMMENT '持有明细快照：[{collectibleId,name,quantity,ratio,newQuantity}]',
  `recovered_total` int unsigned NOT NULL DEFAULT '0' COMMENT '该用户被回收总份数',
  `airdrop_quantity` int unsigned NOT NULL DEFAULT '0' COMMENT '该用户空投新藏品份数',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_plan` (`plan_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_plan_user` (`plan_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='置换计划用户名单';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_swap_plan_users`
--

LOCK TABLES `nft_swap_plan_users` WRITE;
/*!40000 ALTER TABLE `nft_swap_plan_users` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_swap_plan_users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_swap_plans`
--

DROP TABLE IF EXISTS `nft_swap_plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_swap_plans` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `plan_no` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '计划编号：SP+时间+随机',
  `new_collectible_id` int unsigned NOT NULL COMMENT '置换目标（新）藏品ID',
  `new_collectible_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '新藏品名称快照',
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '置换原因',
  `user_count` int unsigned NOT NULL DEFAULT '0' COMMENT '受影响用户数（名单人数）',
  `total_recovered` int unsigned NOT NULL DEFAULT '0' COMMENT '回收资产总份数',
  `total_airdropped` int unsigned NOT NULL DEFAULT '0' COMMENT '空投新藏品总份数',
  `admin_id` int unsigned NOT NULL COMMENT '操作管理员ID',
  `admin_name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '操作管理员姓名',
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '操作IP',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '执行时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_plan_no` (`plan_no`),
  KEY `idx_new_collectible` (`new_collectible_id`),
  KEY `idx_admin` (`admin_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='置换计划（统一回收+按比例空投）';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_swap_plans`
--

LOCK TABLES `nft_swap_plans` WRITE;
/*!40000 ALTER TABLE `nft_swap_plans` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_swap_plans` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_synthesis_activities`
--

DROP TABLE IF EXISTS `nft_synthesis_activities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_synthesis_activities` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `type` enum('limit','permanent') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '活动类型：limit限时/permanent永久',
  `title` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '活动名称',
  `start_time` datetime(3) DEFAULT NULL COMMENT '开始时间（限时活动必填）',
  `end_time` datetime(3) DEFAULT NULL COMMENT '结束时间（限时活动必填）',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态：0停用 1启用',
  `rules` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '活动规则文案',
  `result_collectible_id` int unsigned NOT NULL COMMENT '产物藏品ID，FK→nft_collectibles.id',
  `result_quantity` int unsigned NOT NULL DEFAULT '1' COMMENT '每次合成产出数量',
  `eligibility_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'all' COMMENT '参与资格类型：all/realname/checkin/invite/hold/checkin_rank',
  `eligibility_config` json DEFAULT NULL COMMENT '参与资格配置 JSON',
  `grant_mode` enum('realtime','manual') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'realtime' COMMENT '奖励发放方式：realtime实时到账/manual记录名单统一发放',
  `per_user_limit` int NOT NULL DEFAULT '0' COMMENT '每人限合成次数（0不限）',
  `total_limit` int unsigned DEFAULT NULL COMMENT '总份数限制（NULL不限）',
  `used_count` int unsigned NOT NULL DEFAULT '0' COMMENT '已合成次数',
  `image` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '活动封面图URL',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  `deleted_at` datetime DEFAULT NULL COMMENT '软删除时间',
  PRIMARY KEY (`id`),
  KEY `idx_status_time` (`start_time`,`end_time`),
  KEY `fk_syn_act_result` (`result_collectible_id`),
  CONSTRAINT `fk_syn_act_result` FOREIGN KEY (`result_collectible_id`) REFERENCES `nft_collectibles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='合成活动表：限时/永久';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_synthesis_activities`
--

LOCK TABLES `nft_synthesis_activities` WRITE;
/*!40000 ALTER TABLE `nft_synthesis_activities` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_synthesis_activities` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_synthesis_materials`
--

DROP TABLE IF EXISTS `nft_synthesis_materials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_synthesis_materials` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `activity_id` int unsigned NOT NULL COMMENT '合成活动ID，FK→nft_synthesis_activities.id',
  `collectible_id` int unsigned NOT NULL COMMENT '材料藏品ID，FK→nft_collectibles.id',
  `count` int unsigned NOT NULL DEFAULT '1' COMMENT '需要数量',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_activity_id` (`activity_id`),
  KEY `fk_syn_mat_collectible` (`collectible_id`),
  CONSTRAINT `fk_syn_mat_activity` FOREIGN KEY (`activity_id`) REFERENCES `nft_synthesis_activities` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_syn_mat_collectible` FOREIGN KEY (`collectible_id`) REFERENCES `nft_collectibles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `chk_syn_mat_count` CHECK ((`count` >= 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='合成材料子表：材料M:N';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_synthesis_materials`
--

LOCK TABLES `nft_synthesis_materials` WRITE;
/*!40000 ALTER TABLE `nft_synthesis_materials` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_synthesis_materials` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_synthesis_record_items`
--

DROP TABLE IF EXISTS `nft_synthesis_record_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_synthesis_record_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `synthesis_record_id` bigint unsigned NOT NULL COMMENT '合成记录ID，FK→nft_synthesis_records.id',
  `user_collectible_id` bigint unsigned NOT NULL COMMENT '被消耗资产ID，FK→nft_user_collectibles.id（该资产置 consumed）',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_record` (`synthesis_record_id`),
  KEY `idx_user_collectible` (`user_collectible_id`),
  CONSTRAINT `fk_sri_record` FOREIGN KEY (`synthesis_record_id`) REFERENCES `nft_synthesis_records` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_sri_user_collectible` FOREIGN KEY (`user_collectible_id`) REFERENCES `nft_user_collectibles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='合成消耗明细表：消耗可审计';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_synthesis_record_items`
--

LOCK TABLES `nft_synthesis_record_items` WRITE;
/*!40000 ALTER TABLE `nft_synthesis_record_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_synthesis_record_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_synthesis_records`
--

DROP TABLE IF EXISTS `nft_synthesis_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_synthesis_records` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `user_id` bigint unsigned NOT NULL COMMENT '合成用户ID，FK→nft_users.id',
  `activity_id` int unsigned NOT NULL COMMENT '合成活动ID，FK→nft_synthesis_activities.id',
  `result_user_collectible_id` bigint unsigned NOT NULL COMMENT '产物资产ID，FK→nft_user_collectibles.id（source=synthesis）',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '合成时间',
  PRIMARY KEY (`id`),
  KEY `idx_user_activity` (`user_id`,`activity_id`),
  KEY `fk_syn_rec_activity` (`activity_id`),
  KEY `fk_syn_rec_result` (`result_user_collectible_id`),
  CONSTRAINT `fk_syn_rec_activity` FOREIGN KEY (`activity_id`) REFERENCES `nft_synthesis_activities` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_syn_rec_result` FOREIGN KEY (`result_user_collectible_id`) REFERENCES `nft_user_collectibles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_syn_rec_user` FOREIGN KEY (`user_id`) REFERENCES `nft_users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='合成记录表：一次合成一条';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_synthesis_records`
--

LOCK TABLES `nft_synthesis_records` WRITE;
/*!40000 ALTER TABLE `nft_synthesis_records` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_synthesis_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_system_configs`
--

DROP TABLE IF EXISTS `nft_system_configs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_system_configs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `config_key` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '参数键，唯一',
  `config_value` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '参数值',
  `description` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '参数说明',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_config_key` (`config_key`)
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统参数表：运营参数KV';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_system_configs`
--

LOCK TABLES `nft_system_configs` WRITE;
/*!40000 ALTER TABLE `nft_system_configs` DISABLE KEYS */;
INSERT INTO `nft_system_configs` VALUES (1,'purchase_limit_per_user','5','每藏品每用户限购数量','2026-09-21 15:27:44.857','2026-09-21 15:27:44.857'),(2,'order_pay_timeout_seconds','300','订单待支付超时秒数（超时自动取消并释放库存）','2026-09-21 15:27:44.857','2026-09-21 15:27:44.857'),(3,'resale_cooldown_seconds','180','取消寄售后重新挂单冷却秒数','2026-09-21 15:27:44.857','2026-09-21 15:27:44.857'),(4,'resale_fee_rate','1.00','寄售手续费率（百分比，如 1.00 表示 1%）','2026-09-21 15:27:44.857','2026-09-21 15:27:44.857'),(5,'checkin_rewards','{\"1\":5,\"2\":5,\"3\":10,\"4\":10,\"5\":15,\"6\":15,\"7\":30}','连续签到奖励配置（JSON：天数→司南币数量）','2026-09-21 15:27:44.857','2026-09-21 15:27:44.857'),(6,'service_hotline','400-888-0000','客服热线电话（C 端客服页展示）','2026-09-21 15:27:44.857','2026-09-21 15:27:44.857'),(7,'service_hours','9:00 - 22:00','客服在线时间（C 端客服页展示）','2026-09-21 15:27:44.857','2026-09-21 15:27:44.857'),(8,'service_online_url','','在线客服跳转链接（可空）','2026-09-21 15:27:44.857','2026-09-21 15:27:46.805'),(9,'admin_login_fail_limit','5','管理后台连续登录失败锁定阈值（次）','2026-09-21 15:27:46.805','2026-09-21 15:27:46.805'),(10,'admin_lock_minutes','15','管理后台账号锁定时长（分钟）','2026-09-21 15:27:46.805','2026-09-21 15:27:46.805'),(11,'resale_price_global_max','100000','寄售挂单全局最高价（元，不限价模式仍受此约束）','2026-09-21 15:27:46.805','2026-09-21 15:27:46.805'),(12,'large_recharge_alert','10000','单笔充值风控告警阈值（元）','2026-09-21 15:27:46.805','2026-09-21 15:27:46.805'),(13,'cleanup_sms_required','1','平台清库是否需要短信验证码二次确认（1=是）','2026-09-21 15:27:46.805','2026-09-21 15:27:46.805'),(17,'large_refund_approval_threshold','1000','大额退款审批阈值（元），超过后退款审批需审批中心复核','2026-09-21 15:27:47.045','2026-09-21 15:27:47.045'),(18,'chain_mint_batch_limit','500','单次上链铸造最大持仓条数（防长事务）','2026-09-21 15:27:47.045','2026-09-21 15:27:47.045'),(19,'checkin_enabled','0','签到活动开关（0停用 1启用）','2026-09-21 15:27:47.789','2026-09-21 15:27:47.789'),(20,'checkin_activity_name','每日签到','签到活动名称','2026-09-21 15:27:47.789','2026-09-21 15:27:47.789'),(21,'checkin_start_time','','签到活动开始时间（空=长期）','2026-09-21 15:27:47.789','2026-09-21 15:27:47.789'),(22,'checkin_end_time','','签到活动结束时间（空=长期）','2026-09-21 15:27:47.789','2026-09-21 15:27:47.789'),(23,'checkin_eligibility_type','all','签到参与资格类型：all/realname/checkin/invite/hold/checkin_rank','2026-09-21 15:27:48.134','2026-09-21 15:27:48.134'),(24,'checkin_eligibility_config','','签到参与资格配置 JSON','2026-09-21 15:27:48.134','2026-09-21 15:27:48.134'),(25,'checkin_grant_mode','realtime','签到奖励发放方式：realtime实时到账/manual记录名单统一发放','2026-09-21 15:27:48.134','2026-09-21 15:27:48.134'),(26,'checkin_reward_config','','签到奖励配置 JSON：{天: [六类奖励列表]}，空则回退 checkin_rewards 旧版司南币配置','2026-09-21 15:27:48.134','2026-09-21 15:27:48.134'),(27,'batch_buy_enabled','0','批量购买开关（1=开启 0=关闭）','2026-09-21 15:27:49.272','2026-09-21 15:27:49.272'),(28,'batch_buy_scope','all','批量购买适用范围（all=全体用户 specific=指定用户）','2026-09-21 15:27:49.272','2026-09-21 15:27:49.272'),(29,'batch_buy_limit','1','批量购买单次最大数量（全体用户或指定用户共用）','2026-09-21 15:27:49.272','2026-09-21 15:27:49.272'),(30,'batch_buy_users','','指定用户手机号列表（换行分隔，仅 batch_buy_scope=specific 时生效）','2026-09-21 15:27:49.272','2026-09-21 15:27:49.272'),(31,'captcha.enable','0','图形验证码总开关（0=关闭）','2026-09-21 16:02:04.405','2026-09-22 00:27:32.000'),(32,'captcha.scenes','{\"admin_login\":0}','图形验证码场景开关（admin_login=0表示登录页关闭）','2026-09-21 16:02:04.405','2026-09-21 16:02:04.405'),(33,'realname_audit_mode','manual','实名审核模式：manual=人工审核（默认） auto=自动通过（提交即认证成功）','2026-09-21 18:00:00.000','2026-09-21 18:00:00.000'),(34,'captcha.provider','local','验证码服务商：local=本地图形码（默认） aliyun=阿里云验证码2.0（参数由安全策略页配置）','2026-09-22 00:00:00.000','2026-09-22 00:00:00.000'),(35,'captcha.mode','graphic','验证模式：slider=滑块行为验证 graphic=图形验证码（local 固定 graphic）','2026-09-22 00:00:00.000','2026-09-22 00:00:00.000'),(36,'captcha.aliyun.scene_id','','阿里云验证码场景ID（SceneId，控制台场景列表获取）','2026-09-22 00:00:00.000','2026-09-22 00:00:00.000'),(37,'captcha.aliyun.prefix','','阿里云验证码身份标（prefix，控制台概览页获取，前端初始化用）','2026-09-22 00:00:00.000','2026-09-22 00:00:00.000'),(38,'captcha.aliyun.access_key_id','','阿里云 AccessKey ID（AES 加密落库，永不回显明文）','2026-09-22 00:00:00.000','2026-09-22 00:00:00.000'),(39,'captcha.aliyun.access_key_secret','','阿里云 AccessKey Secret（AES 加密落库，永不回显明文）','2026-09-22 00:00:00.000','2026-09-22 00:00:00.000');
/*!40000 ALTER TABLE `nft_system_configs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_ticket_replies`
--

DROP TABLE IF EXISTS `nft_ticket_replies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_ticket_replies` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `ticket_id` bigint unsigned NOT NULL COMMENT '工单ID，FK→nft_support_tickets.id',
  `sender_type` tinyint NOT NULL COMMENT '发送者：1用户 2客服 3系统',
  `sender_id` bigint unsigned NOT NULL COMMENT '发送者ID',
  `sender_name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '发送者名称',
  `content` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '回复内容',
  `is_internal` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否内部备注：1是（用户不可见）',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_ticket` (`ticket_id`),
  KEY `idx_sender` (`sender_id`),
  CONSTRAINT `fk_reply_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `nft_support_tickets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='工单回复表';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_ticket_replies`
--

LOCK TABLES `nft_ticket_replies` WRITE;
/*!40000 ALTER TABLE `nft_ticket_replies` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_ticket_replies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_trade_snapshots`
--

DROP TABLE IF EXISTS `nft_trade_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_trade_snapshots` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `snapshot_date` date NOT NULL COMMENT '快照基准日期',
  `user_id` bigint unsigned NOT NULL COMMENT '用户ID',
  `buy_count` int unsigned NOT NULL DEFAULT '0' COMMENT '买入笔数',
  `buy_amount` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '买入总金额',
  `sell_count` int unsigned NOT NULL DEFAULT '0' COMMENT '卖出笔数',
  `sell_amount` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '卖出总金额',
  `transfer_in_count` int unsigned NOT NULL DEFAULT '0' COMMENT '受赠笔数',
  `transfer_out_count` int unsigned NOT NULL DEFAULT '0' COMMENT '赠出笔数',
  `blindbox_open_count` int unsigned NOT NULL DEFAULT '0' COMMENT '开盒次数',
  `consume_count` int unsigned NOT NULL DEFAULT '0' COMMENT '合成/分解消耗次数',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_date_user` (`snapshot_date`,`user_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_date` (`snapshot_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户交易快照表';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_trade_snapshots`
--

LOCK TABLES `nft_trade_snapshots` WRITE;
/*!40000 ALTER TABLE `nft_trade_snapshots` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_trade_snapshots` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_transfers`
--

DROP TABLE IF EXISTS `nft_transfers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_transfers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `from_user_id` bigint unsigned NOT NULL COMMENT '转出方用户ID，FK→nft_users.id',
  `to_user_id` bigint unsigned NOT NULL COMMENT '受赠方用户ID，FK→nft_users.id（决策11）',
  `to_phone` varchar(11) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '受赠方手机号',
  `to_nickname` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '受赠人昵称快照',
  `collectible_id` int unsigned NOT NULL COMMENT '藏品ID，FK→nft_collectibles.id',
  `user_collectible_id` bigint unsigned NOT NULL COMMENT '转赠资产ID，FK→nft_user_collectibles.id',
  `status` enum('pending','accepted','rejected','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT '转赠状态：pending待确认（资产frozen）/accepted已接受/rejected已拒绝/cancelled已取消',
  `pending_ucid` bigint unsigned GENERATED ALWAYS AS ((case when (`status` = _utf8mb4'pending') then `user_collectible_id` else NULL end)) STORED COMMENT '待确认唯一辅助列（生成列）：pending 时取资产ID，否则 NULL',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '发起时间',
  `confirmed_at` datetime(3) DEFAULT NULL COMMENT '对方确认时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pending_ucid` (`pending_ucid`),
  KEY `idx_from_user` (`from_user_id`),
  KEY `idx_to_user` (`to_user_id`),
  KEY `idx_status_created` (`status`,`created_at`),
  KEY `fk_transfer_collectible` (`collectible_id`),
  KEY `fk_transfer_user_collectible` (`user_collectible_id`),
  CONSTRAINT `fk_transfer_collectible` FOREIGN KEY (`collectible_id`) REFERENCES `nft_collectibles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_transfer_from_user` FOREIGN KEY (`from_user_id`) REFERENCES `nft_users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_transfer_to_user` FOREIGN KEY (`to_user_id`) REFERENCES `nft_users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_transfer_user_collectible` FOREIGN KEY (`user_collectible_id`) REFERENCES `nft_user_collectibles` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='转赠记录表：状态机冻结-确认，同一资产仅一笔待确认';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_transfers`
--

LOCK TABLES `nft_transfers` WRITE;
/*!40000 ALTER TABLE `nft_transfers` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_transfers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_user_collectibles`
--

DROP TABLE IF EXISTS `nft_user_collectibles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_user_collectibles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `user_id` bigint unsigned NOT NULL COMMENT '持有用户ID，FK→nft_users.id',
  `collectible_id` int unsigned NOT NULL COMMENT '藏品ID，FK→nft_collectibles.id',
  `order_id` bigint unsigned DEFAULT NULL COMMENT '来源订单ID，FK→nft_orders.id（purchase来源）',
  `blind_box_item_id` int unsigned DEFAULT NULL COMMENT '盲盒奖品配置ID，FK→nft_blind_box_items.id（blindbox来源）',
  `airdrop_record_id` bigint unsigned DEFAULT NULL COMMENT '空投记录ID，FK→nft_airdrop_records.id（airdrop来源溯源，决策13；循环外键，脚本末尾ALTER补挂）',
  `serial` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '藏品编号（如 SN-1-0001，藏品维度唯一）',
  `source` enum('purchase','blindbox','transfer','airdrop','synthesis','lucky_draw') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '来源（决策2，替代is_lucky）：purchase购买/blindbox开盲盒/transfer受赠/airdrop空投/synthesis合成/lucky_draw抽奖',
  `acquired_price` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '入手价（决策8：非购买来源存0）',
  `acquired_at` datetime(3) NOT NULL COMMENT '入库时间',
  `is_consigned` tinyint(1) NOT NULL DEFAULT '0' COMMENT '寄售反规范化标记（决策7）：1寄售中 0否',
  `status` enum('held','consigned','frozen','transferred','consumed','recovered') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'held' COMMENT '资产状态：held持有 consigned寄售中 frozen转赠冻结 transferred已转赠 consumed已消耗（开盒/合成） recovered已回收',
  `tx_hash` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '链上交易哈希',
  `block_number` bigint unsigned DEFAULT NULL COMMENT '区块高度',
  `token_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '链上token ID',
  `mint_status` enum('pending','minting','minted','failed') COLLATE utf8mb4_unicode_ci DEFAULT 'pending' COMMENT '铸造状态：pending待铸造/minting铸造中/minted已上链/failed失败',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_collectible_serial` (`collectible_id`,`serial`),
  KEY `idx_user_status` (`user_id`,`status`),
  KEY `idx_user_collectible` (`user_id`,`collectible_id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_airdrop_record` (`airdrop_record_id`),
  KEY `fk_uc_blind_box_item` (`blind_box_item_id`),
  CONSTRAINT `fk_uc_airdrop_record` FOREIGN KEY (`airdrop_record_id`) REFERENCES `nft_airdrop_records` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_uc_blind_box_item` FOREIGN KEY (`blind_box_item_id`) REFERENCES `nft_blind_box_items` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_uc_collectible` FOREIGN KEY (`collectible_id`) REFERENCES `nft_collectibles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_uc_order` FOREIGN KEY (`order_id`) REFERENCES `nft_orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_uc_user` FOREIGN KEY (`user_id`) REFERENCES `nft_users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `chk_uc_price` CHECK ((`acquired_price` >= 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户藏品表：每份一行';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_user_collectibles`
--

LOCK TABLES `nft_user_collectibles` WRITE;
/*!40000 ALTER TABLE `nft_user_collectibles` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_user_collectibles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_user_draw_codes`
--

DROP TABLE IF EXISTS `nft_user_draw_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_user_draw_codes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL COMMENT '所属用户',
  `code` varchar(20) NOT NULL COMMENT '抽签码展示串（S+日期6+随机6）',
  `source` tinyint NOT NULL DEFAULT '1' COMMENT '来源：1报名 2邀请 3购买 4后台手动',
  `activity_id` bigint unsigned DEFAULT NULL COMMENT '来源活动 id（报名发放时非空；NULL 为通用码）',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '状态：1未使用 2已报名 3已失效 4已中签',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注（作废原因等）',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_user` (`user_id`),
  KEY `idx_activity_user` (`activity_id`,`user_id`),
  KEY `idx_activity_status` (`activity_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户抽签码（报名/邀请/购买发放）';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_user_draw_codes`
--

LOCK TABLES `nft_user_draw_codes` WRITE;
/*!40000 ALTER TABLE `nft_user_draw_codes` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_user_draw_codes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_user_favorites`
--

DROP TABLE IF EXISTS `nft_user_favorites`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_user_favorites` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `user_id` bigint unsigned NOT NULL COMMENT '用户ID，FK→nft_users.id',
  `collectible_id` int unsigned NOT NULL COMMENT '藏品ID，FK→nft_collectibles.id',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '关注时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_collectible` (`user_id`,`collectible_id`),
  KEY `fk_favorites_collectible` (`collectible_id`),
  CONSTRAINT `fk_favorites_collectible` FOREIGN KEY (`collectible_id`) REFERENCES `nft_collectibles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_favorites_user` FOREIGN KEY (`user_id`) REFERENCES `nft_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户关注表：市场「关注」视图';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_user_favorites`
--

LOCK TABLES `nft_user_favorites` WRITE;
/*!40000 ALTER TABLE `nft_user_favorites` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_user_favorites` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_users`
--

DROP TABLE IF EXISTS `nft_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `phone` varchar(11) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '手机号，登录账号',
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '用户名/昵称',
  `avatar` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '头像URL（空串由应用层兜底默认头像，避免NULL判断）',
  `uid` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '站内展示UID',
  `invite_code` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '我的邀请码，注册链接 ?code= 绑定',
  `is_realname` tinyint(1) NOT NULL DEFAULT '0' COMMENT '实名标志：1已实名 0未实名（购买/寄售/转赠前置校验）',
  `realname_status` tinyint NOT NULL DEFAULT '0' COMMENT '实名审核状态：0未提交 1待审核 2已通过 3已驳回',
  `realname_submitted_at` datetime DEFAULT NULL COMMENT '最近提交实名时间',
  `realname_reject_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '最近驳回原因',
  `realname_verified_at` datetime(3) DEFAULT NULL COMMENT '实名审核通过时间（注册活动排位依据）',
  `real_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '真实姓名，AES-256/SM4 加密存储',
  `id_card` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '身份证号，加密存储',
  `password` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '登录密码，bcrypt/scrypt 哈希（注册时设置，可密码登录）',
  `transaction_password` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '交易密码，bcrypt/scrypt 哈希（禁止明文）',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '账户状态：1正常 0禁用',
  `is_blacklisted` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否黑名单：1是 0否',
  `logout_before` datetime DEFAULT NULL COMMENT '强制登出时间（早于该时间的token失效）',
  `blacklist_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '拉黑原因',
  `blacklist_at` datetime DEFAULT NULL COMMENT '拉黑时间',
  `last_login_at` datetime(3) DEFAULT NULL COMMENT '最后登录时间（登录空投依赖）',
  `login_count` int unsigned NOT NULL DEFAULT '0' COMMENT '累计登录次数',
  `deleted_at` datetime DEFAULT NULL COMMENT '软删除时间，NULL未删除',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_phone` (`phone`),
  UNIQUE KEY `uk_uid` (`uid`),
  UNIQUE KEY `uk_invite_code` (`invite_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户表：平台账户主数据';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_users`
--

LOCK TABLES `nft_users` WRITE;
/*!40000 ALTER TABLE `nft_users` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_verification_codes`
--

DROP TABLE IF EXISTS `nft_verification_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_verification_codes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `phone` varchar(11) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '接收手机号',
  `scene` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '使用场景：C端 register/login/reset_password；管理端敏感操作（platform_cleanup 等）',
  `code` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '验证码（哈希存储，bcrypt/scrypt 输出≥60字符）',
  `expires_at` datetime(3) NOT NULL COMMENT '过期时间（发送时刻+5分钟）',
  `used_at` datetime(3) DEFAULT NULL COMMENT '核销时间（一次性，用过即失效）',
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '请求IP（风控/频控，兼容IPv6）',
  `sent_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '发送时间（60s内禁止重发）',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_phone_scene` (`phone`,`scene`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='短信验证码表：一次性核销，哈希存储';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_verification_codes`
--

LOCK TABLES `nft_verification_codes` WRITE;
/*!40000 ALTER TABLE `nft_verification_codes` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_verification_codes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_wallet_transactions`
--

DROP TABLE IF EXISTS `nft_wallet_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_wallet_transactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `user_id` bigint unsigned NOT NULL COMMENT '用户ID，FK→nft_users.id',
  `trans_type` enum('recharge','buy','withdraw','reward') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '交易类型：recharge充值/buy消费/withdraw提现/reward奖励',
  `title` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '明细标题',
  `direction` tinyint NOT NULL COMMENT '资金方向：1收入 2支出',
  `amount` decimal(12,2) NOT NULL COMMENT '金额（绝对值）',
  `balance_after` decimal(12,2) DEFAULT NULL COMMENT '交易后余额快照（对账依据）',
  `biz_no` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '关联业务单号（订单号/空投记录ID等）',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_user_created` (`user_id`,`created_at`),
  KEY `idx_biz_no` (`biz_no`),
  CONSTRAINT `fk_wallet_trans_user` FOREIGN KEY (`user_id`) REFERENCES `nft_users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `chk_wt_amount` CHECK ((`amount` >= 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='钱包流水表：纯日志，记录余额快照';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_wallet_transactions`
--

LOCK TABLES `nft_wallet_transactions` WRITE;
/*!40000 ALTER TABLE `nft_wallet_transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_wallet_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nft_wallets`
--

DROP TABLE IF EXISTS `nft_wallets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nft_wallets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `user_id` bigint unsigned NOT NULL COMMENT '用户ID，FK→nft_users.id，唯一',
  `balance` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '总资产（元）',
  `available` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '可用余额（元）',
  `frozen` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '冻结金额（待支付占用，元）',
  `points` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '司南币余额',
  `brand` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '汇付' COMMENT '第三方支付品牌',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user` (`user_id`),
  CONSTRAINT `fk_wallets_user` FOREIGN KEY (`user_id`) REFERENCES `nft_users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `chk_wallets_nonneg` CHECK (((`balance` >= 0) and (`available` >= 0) and (`frozen` >= 0) and (`points` >= 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='钱包表：与用户1:1，余额/冻结/司南币';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nft_wallets`
--

LOCK TABLES `nft_wallets` WRITE;
/*!40000 ALTER TABLE `nft_wallets` DISABLE KEYS */;
/*!40000 ALTER TABLE `nft_wallets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'sinan_nft'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-09-21 16:35:12
