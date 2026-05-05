-- Table for available drinks
CREATE TABLE IF NOT EXISTS drinks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    image VARCHAR(255) DEFAULT NULL,
    category INT DEFAULT NULL,
    FOREIGN KEY (category) REFERENCES drink_categories(id)
);

-- Insert money transfer category (negative ID to exclude from normal ordering)
INSERT IGNORE INTO drinks (id, name, price, image, category) VALUES (-1, 'Geld senden', 1.00, NULL, 0);

-- Team events for team-mode accounting/statistics
CREATE TABLE IF NOT EXISTS drinks_teamevents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    team_admin_user_id INT UNSIGNED NOT NULL,
    comment VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (team_admin_user_id) REFERENCES bs_users(uid),
    INDEX idx_drinks_teamevents_admin (team_admin_user_id),
    INDEX idx_drinks_teamevents_comment (comment)
);

-- Team event members (participants assigned to a Spieltag)
CREATE TABLE IF NOT EXISTS drinks_teamevent_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    team_event_id INT NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    added_by_user_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_drinks_teamevent_members (team_event_id, user_id),
    INDEX idx_drinks_teamevent_members_event (team_event_id),
    INDEX idx_drinks_teamevent_members_user (user_id),
    FOREIGN KEY (team_event_id) REFERENCES drinks_teamevents(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES bs_users(uid),
    FOREIGN KEY (added_by_user_id) REFERENCES bs_users(uid)
);

-- To update existing databases, run:
-- CREATE TABLE drinks_teamevent_members (
--     id INT AUTO_INCREMENT PRIMARY KEY,
--     team_event_id INT NOT NULL,
--     user_id INT UNSIGNED NOT NULL,
--     added_by_user_id INT UNSIGNED DEFAULT NULL,
--     created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
--     UNIQUE KEY uniq_drinks_teamevent_members (team_event_id, user_id),
--     INDEX idx_drinks_teamevent_members_event (team_event_id),
--     INDEX idx_drinks_teamevent_members_user (user_id),
--     FOREIGN KEY (team_event_id) REFERENCES drinks_teamevents(id) ON DELETE CASCADE,
--     FOREIGN KEY (user_id) REFERENCES bs_users(uid),
--     FOREIGN KEY (added_by_user_id) REFERENCES bs_users(uid)
-- );

-- Table for drink orders per user
CREATE TABLE IF NOT EXISTS drink_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    drink_id INT NOT NULL,
    quantity INT NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    comment VARCHAR(255) DEFAULT NULL,
    transfer_reference VARCHAR(64) DEFAULT NULL,
    teamevent_id INT DEFAULT NULL,
    order_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted TINYINT(1) NOT NULL DEFAULT 0,
    user_id_added INT UNSIGNED NOT NULL DEFAULT NULL,
    user_id_deleted INT UNSIGNED NOT NULL DEFAULT NULL,
    is_auto_order TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES bs_users(uid),
    FOREIGN KEY (drink_id) REFERENCES drinks(id),
    FOREIGN KEY (teamevent_id) REFERENCES drinks_teamevents(id),
    FOREIGN KEY (user_id_added) REFERENCES bs_users(uid),
    FOREIGN KEY (user_id_deleted) REFERENCES bs_users(uid)
    ,INDEX idx_drink_orders_transfer_reference (transfer_reference)
);

-- Table for user balance deposits
CREATE TABLE IF NOT EXISTS drink_deposits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    comment VARCHAR(255) DEFAULT NULL,
    transfer_reference VARCHAR(64) DEFAULT NULL,
    teamevent_id INT DEFAULT NULL,
    deposit_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    createdbyuserid INT UNSIGNED DEFAULT NULL,
    deleted TINYINT(1) DEFAULT 0,
    user_id_deleted INT UNSIGNED DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES bs_users(uid),
    FOREIGN KEY (teamevent_id) REFERENCES drinks_teamevents(id),
    FOREIGN KEY (createdbyuserid) REFERENCES bs_users(uid),
    FOREIGN KEY (user_id_deleted) REFERENCES bs_users(uid),
    INDEX idx_drink_deposits_transfer_reference (transfer_reference)
);

-- Create drink_barcodes table for mapping barcode to drink_id
CREATE TABLE IF NOT EXISTS drink_barcodes (
    drink_id INT NOT NULL,
    barcode VARCHAR(64) PRIMARY KEY,
    FOREIGN KEY (drink_id) REFERENCES drinks(id) ON DELETE CASCADE
);

-- Table for drink categories
CREATE TABLE IF NOT EXISTS drink_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    sort_priority INT NOT NULL DEFAULT 0
);

-- Table for user drinks aliases
CREATE TABLE IF NOT EXISTS drink_aliases (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    alias VARCHAR(50) UNIQUE DEFAULT '',
    enabled TINYINT(1) DEFAULT 0,
    thekenadmin TINYINT(1) DEFAULT 0,
    is_team TINYINT(1) DEFAULT 0,
    order_email_option VARCHAR(20) DEFAULT 'order',
    teamlead_email VARCHAR(255) DEFAULT '',
-- To update existing databases, run:
-- ALTER TABLE drink_aliases MODIFY order_email_option VARCHAR(20) DEFAULT 'order';
-- UPDATE drink_aliases SET order_email_option = 'order' WHERE order_email_option IS NULL OR order_email_option = '';
-- ALTER TABLE drink_aliases ADD COLUMN is_team TINYINT(1) DEFAULT 0;
-- ALTER TABLE drink_aliases ADD COLUMN teamlead_email VARCHAR(255) DEFAULT '';
-- CREATE TABLE drinks_teamevents (
--   id INT AUTO_INCREMENT PRIMARY KEY,
--   team_admin_user_id INT UNSIGNED NOT NULL,
--   comment VARCHAR(255) NOT NULL,
--   created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
--   FOREIGN KEY (team_admin_user_id) REFERENCES bs_users(uid)
-- );
-- ALTER TABLE drink_orders ADD COLUMN teamevent_id INT DEFAULT NULL;
-- ALTER TABLE drink_orders ADD CONSTRAINT fk_drink_orders_teamevent_id FOREIGN KEY (teamevent_id) REFERENCES drinks_teamevents(id);
-- ALTER TABLE drink_deposits ADD COLUMN teamevent_id INT DEFAULT NULL;
-- ALTER TABLE drink_deposits ADD CONSTRAINT fk_drink_deposits_teamevent_id FOREIGN KEY (teamevent_id) REFERENCES drinks_teamevents(id);
-- ALTER TABLE drink_orders ADD COLUMN transfer_reference VARCHAR(64) DEFAULT NULL;
-- ALTER TABLE drink_orders ADD INDEX idx_drink_orders_transfer_reference (transfer_reference);
-- ALTER TABLE drink_deposits ADD COLUMN transfer_reference VARCHAR(64) DEFAULT NULL;
-- ALTER TABLE drink_deposits ADD INDEX idx_drink_deposits_transfer_reference (transfer_reference);
    FOREIGN KEY (user_id) REFERENCES bs_users(uid) ON DELETE CASCADE
);

-- Table for recording drink check events (replaces theke.last.check.date option)
CREATE TABLE IF NOT EXISTS drink_checks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    check_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES bs_users(uid) ON DELETE CASCADE,
    INDEX idx_drink_checks_time (check_time),
    INDEX idx_drink_checks_user_time (user_id, check_time)
);
