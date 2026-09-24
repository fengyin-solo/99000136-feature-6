-- 多图与草稿幂等迁移脚本
-- 执行此 SQL 来添加多图排序与草稿防重复提交所需的表结构
-- 兼容历史数据：旧的单图仍保存在 messages.image 字段中

USE `community_board`;

-- 留言多图表（按 sort_order 升序展示）
CREATE TABLE IF NOT EXISTS `message_images` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `message_id` INT UNSIGNED NOT NULL COMMENT '留言ID',
    `image` VARCHAR(255) NOT NULL COMMENT '图片路径',
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '排序序号，从0开始',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_message_order` (`message_id`, `sort_order`),
    FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='留言多图表';

-- 草稿唯一标识：同一草稿重复提交只生成一条留言
-- 历史留言 draft_key 为 NULL，UNIQUE 索引允许多个 NULL
ALTER TABLE `messages`
    ADD COLUMN `visitor_id` VARCHAR(64) DEFAULT NULL COMMENT '提交者访客标识' AFTER `image`,
    ADD COLUMN `draft_key` VARCHAR(64) DEFAULT NULL COMMENT '草稿唯一键，用于防重复提交' AFTER `visitor_id`,
    ADD UNIQUE KEY `uk_draft_key` (`draft_key`);

-- 将历史单图回填到多图表（首图），保证各端展示一致
INSERT INTO `message_images` (`message_id`, `image`, `sort_order`)
SELECT `id`, `image`, 0 FROM `messages`
WHERE `image` IS NOT NULL AND `image` <> ''
  AND `id` NOT IN (SELECT `message_id` FROM `message_images`);

-- 执行完成后，可以通过以下命令验证：
-- SHOW TABLES LIKE 'message_images';
-- DESCRIBE message_images;
