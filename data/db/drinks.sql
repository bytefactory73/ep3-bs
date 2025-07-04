-- Table for available drinks
CREATE TABLE IF NOT EXISTS drinks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    image VARCHAR(255) DEFAULT NULL,
    category INT DEFAULT NULL,
    FOREIGN KEY (category) REFERENCES drink_categories(id)
);

-- Table for drink orders per user
CREATE TABLE IF NOT EXISTS drink_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    drink_id INT NOT NULL,
    quantity INT NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    order_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES bs_users(uid),
    FOREIGN KEY (drink_id) REFERENCES drinks(id)
);

-- Table for user balance deposits
CREATE TABLE IF NOT EXISTS drink_deposits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    deposit_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES bs_users(uid)
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
