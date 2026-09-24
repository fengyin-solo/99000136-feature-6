-- 多图与草稿功能迁移脚本
-- 执行此 SQL 来添加多图（有序）与提交幂等所需的表结构
--
-- 说明：
-- 1. message_images 存储每条留言的多张图片，sort_order 决定展示顺序；
--    旧的 messages.image 字段保留作为兼容（旧单图留言仍可正常查看）。
-- 2. messages.submit_token 用于同一草稿的幂等提交，
--    同一 token 只允许生成一条留言（含待审核留言）。

USE `community_board`;

-- 留言图片表（一对多，按 sort_order 排序）
CREATE TABLE IF NOT EXISTS `message_images` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `message_id` INT UNSIGNED NOT NULL COMMENT '留言ID',
    `image` VARCHAR(255) NOT NULL COMMENT '图片相对路径',
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '排序序号，从0开始',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    INDEX `idx_message_sort` (`message_id`, `sort_order`),
    FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='留言图片表';

-- 留言表增加提交幂等令牌
ALTER TABLE `messages`
    ADD COLUMN `submit_token` VARCHAR(64) DEFAULT NULL COMMENT '草稿提交令牌，用于幂等提交' AFTER `image`;

-- 同一令牌只允许一条留言（重复提交返回同一条，不重复入库）
ALTER TABLE `messages`
    ADD UNIQUE INDEX `uk_submit_token` (`submit_token`);

-- 执行完成后，可以通过以下命令验证：
-- SHOW TABLES LIKE 'message_images';
-- DESCRIBE message_images;
-- DESCRIBE messages;
