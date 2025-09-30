CREATE TABLE IF NOT EXISTS Users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    phone VARCHAR(20),
    role ENUM('customer', 'owner', 'admin') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS Barbershops (
    shop_id INT AUTO_INCREMENT PRIMARY KEY,
    owner_id INT NOT NULL,
    shop_name VARCHAR(150) NOT NULL,
    description TEXT,
    address VARCHAR(255),
    latitude DECIMAL(9,6),
    longitude DECIMAL(9,6),
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_id) REFERENCES Users(user_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS Shop_Subscriptions(
    subscription_id INT AUTO_INCREMENT PRIMARY KEY,
    shop_id INT NOT NULL,
    plan_type ENUM('monthly','yearly') NOT NULL DEFAULT 'yearly',
    annual_fee DECIMAL(10,2) NOT NULL, -- Base amount before tax (for monthly we still store in this column)
    tax_rate DECIMAL(5,2) NOT NULL DEFAULT 12.00, -- Example: 12% VAT
    payment_status ENUM('pending','paid','expired') DEFAULT 'pending',
    valid_from DATE NOT NULL,
    valid_to DATE NOT NULL,
    auto_renew TINYINT(1) NOT NULL DEFAULT 1,
    renewal_parent_id INT NULL,
    renewal_generated_at DATETIME NULL,
    payment_id INT NULL, -- back reference to Payments once created
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (shop_id) REFERENCES Barbershops(shop_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS Services (
    service_id INT AUTO_INCREMENT PRIMARY KEY,
    shop_id INT NOT NULL,
    service_name VARCHAR(100) NOT NULL,
    duration_minutes INT NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (shop_id) REFERENCES Barbershops(shop_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS Appointments (
    appointment_id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    shop_id INT NOT NULL,
    service_id INT NOT NULL,
    appointment_date DATETIME NOT NULL,
    status ENUM('pending','confirmed','cancelled','completed') DEFAULT 'pending',
    payment_option ENUM('cash','online') DEFAULT 'cash',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES Users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (shop_id) REFERENCES Barbershops(shop_id) ON DELETE CASCADE,
    FOREIGN KEY (service_id) REFERENCES Services(service_id) ON DELETE CASCADE
);

CREATE TABLE Payments (
    payment_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    shop_id INT,
    appointment_id INT,
    subscription_id INT,
    amount DECIMAL(10,2) NOT NULL,
    tax_amount DECIMAL(10,2) DEFAULT 0.00,
    payment_method ENUM('cash','online') NOT NULL,
    payment_status ENUM('pending','completed','failed') DEFAULT 'pending',
    paid_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (shop_id) REFERENCES Barbershops(shop_id) ON DELETE CASCADE,
    FOREIGN KEY (appointment_id) REFERENCES Appointments(appointment_id) ON DELETE CASCADE,
    FOREIGN KEY (subscription_id) REFERENCES Shop_Subscriptions(subscription_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS Reviews (
    review_id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    shop_id INT NOT NULL,
    rating INT CHECK (rating BETWEEN 1 AND 5),
    comment TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES Users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (shop_id) REFERENCES Barbershops(shop_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS Notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    message TEXT NOT NULL,
    type ENUM('email','sms','system') NOT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE
);

ALTER TABLE Users 
ADD COLUMN username VARCHAR(50) UNIQUE AFTER full_name,
ADD COLUMN is_verified TINYINT(1) DEFAULT 0 AFTER role;

ALTER TABLE Barbershops
ADD COLUMN city VARCHAR(100) AFTER address;

-- Add contact and hours to Barbershops
ALTER TABLE Barbershops
ADD COLUMN shop_phone VARCHAR(30) NULL AFTER city,
ADD COLUMN open_time TIME NULL AFTER shop_phone,
ADD COLUMN close_time TIME NULL AFTER open_time;

ALTER TABLE Shop_Subscriptions
-- DROP COLUMN total_amount,
ADD FOREIGN KEY (payment_id) REFERENCES Payments(payment_id) ON DELETE SET NULL;

-- Ensure plan_type column exists for instances created before this update
ALTER TABLE Shop_Subscriptions
ADD COLUMN IF NOT EXISTS plan_type ENUM('monthly','yearly') NOT NULL DEFAULT 'yearly' AFTER shop_id;
ALTER TABLE Shop_Subscriptions
ADD COLUMN IF NOT EXISTS auto_renew TINYINT(1) NOT NULL DEFAULT 1 AFTER valid_to,
ADD COLUMN IF NOT EXISTS renewal_parent_id INT NULL AFTER auto_renew,
ADD COLUMN IF NOT EXISTS renewal_generated_at DATETIME NULL AFTER renewal_parent_id;

ALTER TABLE Appointments 
ADD COLUMN notes TEXT NULL AFTER payment_option,
ADD COLUMN is_paid TINYINT(1) DEFAULT 0 AFTER status;

ALTER TABLE Payments 
ADD COLUMN transaction_type ENUM('appointment','subscription') AFTER amount;

ALTER TABLE Reviews 
ADD COLUMN appointment_id INT NULL,
ADD FOREIGN KEY (appointment_id) REFERENCES Appointments(appointment_id) ON DELETE SET NULL;

ALTER TABLE Notifications 
ADD COLUMN title VARCHAR(150) NOT NULL AFTER user_id,
ADD COLUMN is_read TINYINT(1) DEFAULT 0 AFTER sent_at;

-- Additional columns to support admin user management (suspension & soft delete)
ALTER TABLE Users 
ADD COLUMN is_suspended TINYINT(1) DEFAULT 0 AFTER is_verified,
ADD COLUMN deleted_at DATETIME NULL AFTER is_suspended;

-- Documents table to support structured owner verification prerequisites
CREATE TABLE IF NOT EXISTS Documents (
    document_id INT AUTO_INCREMENT PRIMARY KEY,
    owner_id INT NOT NULL,
    shop_id INT NULL,
    doc_type ENUM(
        'personal_id_front',
        'personal_id_back',
        'selfie',
        'business_permit',
        'sanitation_certificate',
        'tax_certificate',
        'shop_photo',
        'other'
    ) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP NULL,
    reviewer_id INT NULL,
    notes TEXT NULL,
    FOREIGN KEY (owner_id) REFERENCES Users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (shop_id) REFERENCES Barbershops(shop_id) ON DELETE CASCADE,
    FOREIGN KEY (reviewer_id) REFERENCES Users(user_id) ON DELETE SET NULL,
    INDEX(owner_id),
    INDEX(shop_id),
    INDEX(doc_type),
    INDEX(status)
);

-- Dispute tracking for payments (subscription or appointment)
CREATE TABLE IF NOT EXISTS Payment_Disputes (
    dispute_id INT AUTO_INCREMENT PRIMARY KEY,
    payment_id INT NOT NULL,
    opened_by INT NOT NULL, -- user (owner/admin)
    reason VARCHAR(255) NOT NULL,
    description TEXT NULL,
    status ENUM('open','in_review','resolved','rejected') NOT NULL DEFAULT 'open',
    resolution_notes TEXT NULL,
    resolved_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (payment_id) REFERENCES Payments(payment_id) ON DELETE CASCADE,
    FOREIGN KEY (opened_by) REFERENCES Users(user_id) ON DELETE CASCADE,
    INDEX(payment_id),
    INDEX(status)
);

-- Scheduled & on-demand report metadata
CREATE TABLE IF NOT EXISTS Report_Schedules (
    schedule_id INT AUTO_INCREMENT PRIMARY KEY,
    report_key VARCHAR(50) NOT NULL, -- e.g. monthly_summary, revenue_breakdown
    name VARCHAR(120) NOT NULL,
    frequency ENUM('daily','weekly','monthly','custom') NOT NULL,
    custom_cron VARCHAR(100) NULL, -- for 'custom' frequency (cron-like expression placeholder)
    last_run_at DATETIME NULL,
    next_run_at DATETIME NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    recipients TEXT NOT NULL, -- comma-separated emails
    range_preset ENUM('today','yesterday','last_7_days','last_30_days','this_month','last_month','this_year','last_year','custom') NOT NULL DEFAULT 'last_30_days',
    custom_start DATE NULL,
    custom_end DATE NULL,
    format ENUM('html','csv','both') NOT NULL DEFAULT 'html',
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES Users(user_id) ON DELETE CASCADE,
    INDEX(report_key),
    INDEX(active),
    INDEX(next_run_at)
);

CREATE TABLE IF NOT EXISTS Report_Logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    schedule_id INT NULL,
    report_key VARCHAR(50) NOT NULL,
    range_start DATE NULL,
    range_end DATE NULL,
    status ENUM('started','success','failed') NOT NULL DEFAULT 'started',
    message TEXT NULL,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    output_path VARCHAR(255) NULL, -- if file created (csv)
    recipients TEXT NULL,
    FOREIGN KEY (schedule_id) REFERENCES Report_Schedules(schedule_id) ON DELETE SET NULL,
    INDEX(report_key),
    INDEX(status),
    INDEX(generated_at)
);

-- Broadcast notifications (admin initiated) with multi-channel and scheduling support
CREATE TABLE IF NOT EXISTS Notification_Broadcasts (
    broadcast_id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    channels SET('email','sms','system') NOT NULL DEFAULT 'system',
    audience ENUM('all','owners','customers') NOT NULL DEFAULT 'all',
    status ENUM('draft','queued','sending','completed','failed') NOT NULL DEFAULT 'queued',
    scheduled_at DATETIME NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    total_targets INT DEFAULT 0,
    sent_count INT DEFAULT 0,
    fail_count INT DEFAULT 0,
    link1_label VARCHAR(80) NULL,
    link1_url VARCHAR(255) NULL,
    link2_label VARCHAR(80) NULL,
    link2_url VARCHAR(255) NULL,
    link3_label VARCHAR(80) NULL,
    link3_url VARCHAR(255) NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES Users(user_id) ON DELETE CASCADE,
    INDEX(status),
    INDEX(scheduled_at)
);

-- Individual per-user delivery queue
CREATE TABLE IF NOT EXISTS Notification_Queue (
    queue_id INT AUTO_INCREMENT PRIMARY KEY,
    broadcast_id INT NOT NULL,
    user_id INT NOT NULL,
    channel ENUM('email','sms','system') NOT NULL,
    status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    attempts INT NOT NULL DEFAULT 0,
    last_attempt_at DATETIME NULL,
    sent_at DATETIME NULL,
    error_message VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (broadcast_id) REFERENCES Notification_Broadcasts(broadcast_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE,
    INDEX(status),
    INDEX(channel)
);

-- Key-value application settings
CREATE TABLE IF NOT EXISTS Settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Service categories for classification / filtering
CREATE TABLE IF NOT EXISTS Service_Categories (
    category_id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(80) UNIQUE NOT NULL,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(255) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(active)
);

