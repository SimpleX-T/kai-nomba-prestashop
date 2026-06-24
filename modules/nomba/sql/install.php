<?php
/**
 * Maps our order reference to Nomba's transaction, tracks status and
 * refunds, and gives webhook handling an idempotency anchor.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

return 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'nomba_transaction` (
    `id_nomba_transaction` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_reference` VARCHAR(64) NOT NULL,
    `id_cart` INT(11) UNSIGNED DEFAULT NULL,
    `id_order` INT(11) UNSIGNED DEFAULT NULL,
    `transaction_id` VARCHAR(128) DEFAULT NULL,
    `status` VARCHAR(32) NOT NULL DEFAULT \'PENDING\',
    `amount` DECIMAL(20,2) NOT NULL DEFAULT 0,
    `currency` VARCHAR(8) NOT NULL DEFAULT \'NGN\',
    `refunded_amount` DECIMAL(20,2) NOT NULL DEFAULT 0,
    `date_add` DATETIME NOT NULL,
    `date_upd` DATETIME NOT NULL,
    PRIMARY KEY (`id_nomba_transaction`),
    UNIQUE KEY `order_reference` (`order_reference`),
    KEY `transaction_id` (`transaction_id`),
    KEY `id_order` (`id_order`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';
