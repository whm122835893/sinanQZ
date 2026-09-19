-- 20260919_lucky_prize_name_image.sql
-- 修复 C 端抽奖 5001：lucky_draw_prizes 缺 prize_name/prize_image 列
-- （LuckyDraw activity()/draw() 读取 $p['prize_name']，键不存在触发 PHP8 Undefined array key）

ALTER TABLE `nft_lucky_draw_prizes`
    ADD COLUMN `prize_name` VARCHAR(100) NULL DEFAULT NULL COMMENT '奖品名称（空则展示档位名 tier_name）' AFTER `tier_name`,
    ADD COLUMN `prize_image` VARCHAR(255) NULL DEFAULT NULL COMMENT '奖品图（空则回退藏品图）' AFTER `prize_name`;
