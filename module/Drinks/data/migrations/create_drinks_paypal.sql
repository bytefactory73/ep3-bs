-- Migration: create drinks_paypal table
CREATE TABLE IF NOT EXISTS `drinks_paypal` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `paypal_transaction_id` VARCHAR(64) NOT NULL,
  `state` ENUM('emailreceived','apifoundsynced','depositassigned','ignored') NOT NULL DEFAULT 'emailreceived',
  `account_id` VARCHAR(64) DEFAULT NULL,
  `payer_name` VARCHAR(255) DEFAULT NULL,
  `payer_email` VARCHAR(255) DEFAULT NULL,
  `amount` DECIMAL(10,2) DEFAULT NULL,
  `transaction_status` VARCHAR(32) DEFAULT NULL,
  `transaction_note` TEXT,
  `transaction_json` JSON DEFAULT NULL,
  `source_mail_id` VARCHAR(255) DEFAULT NULL,
  `received_at` DATETIME DEFAULT NULL,
  `processed_at` DATETIME DEFAULT NULL,
  `linked_user_id` INT UNSIGNED DEFAULT NULL,
  `linked_deposit_id` INT DEFAULT NULL,
  `auto_credited` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_drinks_paypal_txid` (`paypal_transaction_id`),
  KEY `idx_drinks_paypal_payer_email` (`payer_email`),
  KEY `idx_drinks_paypal_linked_user` (`linked_user_id`),
  UNIQUE KEY `ux_drinks_paypal_linked_deposit` (`linked_deposit_id`),
  CONSTRAINT `fk_drinks_paypal_user`
    FOREIGN KEY (`linked_user_id`)
    REFERENCES `bs_users` (`uid`)
    ON DELETE SET NULL
    ON UPDATE CASCADE,
  CONSTRAINT `fk_drinks_paypal_deposit`
    FOREIGN KEY (`linked_deposit_id`)
    REFERENCES `drink_deposits` (`id`)
    ON DELETE SET NULL
    ON UPDATE CASCADE
);
