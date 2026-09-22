-- 20260922_captcha_provider.sql
-- 图形验证码服务商开关 + 阿里云参数（后台「安全策略」可切换，实时生效）
--
-- captcha.provider ：local=本地图形码（GD 渲染） aliyun=阿里云验证码2.0
-- captcha.mode     ：slider=滑块行为验证  graphic=图形验证码
--                    local 只支持 graphic；aliyun 支持 slider/graphic 自由切换
-- captcha.aliyun.*：scene_id/prefix（明文，前端 SDK 初始化用）
--                    access_key_id/access_key_secret（AES 加密落库，永不回显明文）

INSERT INTO `nft_system_configs` (`config_key`, `config_value`, `description`) VALUES
('captcha.provider', 'local', '验证码服务商：local=本地图形码（默认） aliyun=阿里云验证码2.0'),
('captcha.mode',     'graphic', '验证模式：slider=滑块行为验证 graphic=图形验证码（local 固定 graphic）'),
('captcha.aliyun.scene_id', '', '阿里云验证码场景ID（SceneId，控制台场景列表获取）'),
('captcha.aliyun.prefix',   '', '阿里云验证码身份标（prefix，控制台概览页获取，前端初始化用）'),
('captcha.aliyun.access_key_id',     '', '阿里云 AccessKey ID（AES 加密落库，永不回显明文）'),
('captcha.aliyun.access_key_secret', '', '阿里云 AccessKey Secret（AES 加密落库，永不回显明文）')
ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);
