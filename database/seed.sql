-- BarberSure Seed Data
-- ---------------------------------------------------------------------------
-- PURPOSE:
--   Provides realistic dummy data for local development & manual testing.
--   Safe to run on a fresh database. If re-running, consider TRUNCATE section.
-- USAGE (MySQL CLI):
--   mysql -u root -p barbersure < database/seed.sql
-- NOTES:
--   Password hashes below correspond to the plaintext: pass1234
--   Adjust emails if collisions occur. All timestamps use NOW() where relevant.
-- ---------------------------------------------------------------------------

SET FOREIGN_KEY_CHECKS=0;

-- ---------------------------------------------------------------------------
-- Date Anchoring NOTE (2025 requirement)
-- Instead of using NOW()/CURDATE() which produce shifting dates, we anchor all
-- relative date math to a fixed base datetime in the year 2025 so the admin UI
-- shows a clear spread of historical, current, and future records while staying
-- within (or beyond) 2025. Adjust @SEED_BASE_DATETIME below if you want a
-- different reference point.
-- Chosen base gives ranges roughly mid-June 2025 (oldest) through mid-Aug 2025
-- (future) for appointment/payment activity; subscriptions may extend into 2026.
-- ---------------------------------------------------------------------------
SET @SEED_BASE_DATETIME = '2025-07-30 10:00:00';
SET @SEED_BASE_DATE = DATE(@SEED_BASE_DATETIME);

-- Optional cleanup (uncomment to reset tables)
-- TRUNCATE Documents;
-- TRUNCATE Notifications;
-- TRUNCATE Reviews;
-- TRUNCATE Payments;
-- TRUNCATE Appointments;
-- TRUNCATE Services;
-- TRUNCATE Shop_Subscriptions;
-- TRUNCATE Barbershops;
-- TRUNCATE Users;

SET FOREIGN_KEY_CHECKS=1;

-- ===========================================================================
-- Users
-- role mix: 1 admin, 3 owners, 6 customers
-- password_hash produced via PHP password_hash('pass1234', PASSWORD_DEFAULT)
-- All users now have valid Philippines mobile phone numbers (+639xxxxxxxxx format)
-- (Hash may vary depending on PHP version; using a generic bcrypt style example)
-- ===========================================================================
-- Distribute created_at for user growth across Jan-Sep 2025
INSERT INTO Users (full_name, username, email, password_hash, phone, role, is_verified, is_suspended, created_at)
VALUES
('System Administrator', 'adminsys', 'admin@example.com', '$2y$10$H7P5R41hK2vRcANdEiXQ.erEiTT3t4I3voo37I1n9wVPTa7sU72XK', '+63 9000000000', 'admin', 1, 0, '2025-01-08 09:10:00'),
('Juan Dela Cruz', 'juanshave', 'owner1@example.com', '$2y$10$H7P5R41hK2vRcANdEiXQ.erEiTT3t4I3voo37I1n9wVPTa7sU72XK', '+63 9171111111', 'owner', 1, 0, '2025-02-12 10:05:00'),
('Maria Reyes', 'mariafade', 'owner2@example.com', '$2y$10$H7P5R41hK2vRcANdEiXQ.erEiTT3t4I3voo37I1n9wVPTa7sU72XK', '+63 9172222222', 'owner', 1, 0, '2025-03-15 11:22:00'),
('Pedro Santos', 'pedrocut', 'owner3@example.com', '$2y$10$H7P5R41hK2vRcANdEiXQ.erEiTT3t4I3voo37I1n9wVPTa7sU72XK', '+63 9173333333', 'owner', 0, 0, '2025-04-09 13:40:00'),
('Arvin Lopez', 'arvinc', 'cust1@example.com', '$2y$10$H7P5R41hK2vRcANdEiXQ.erEiTT3t4I3voo37I1n9wVPTa7sU72XK', '+63 9154441111', 'customer', 1, 0, '2025-05-18 09:55:00'),
('Bianca Cruz', 'biancac', 'cust2@example.com', '$2y$10$H7P5R41hK2vRcANdEiXQ.erEiTT3t4I3voo37I1n9wVPTa7sU72XK', '+63 9155552222', 'customer', 1, 0, '2025-06-07 16:05:00'),
('Carlos Tan', 'carlost', 'cust3@example.com', '$2y$10$H7P5R41hK2vRcANdEiXQ.erEiTT3t4I3voo37I1n9wVPTa7sU72XK', '+63 9166663333', 'customer', 0, 0, '2025-07-11 08:25:00'),
('Dianne Uy', 'diannes', 'cust4@example.com', '$2y$10$H7P5R41hK2vRcANdEiXQ.erEiTT3t4I3voo37I1n9wVPTa7sU72XK', '+63 9177774444', 'customer', 0, 0, '2025-08-14 15:30:00'),
('Edward Lim', 'edlim', 'cust5@example.com', '$2y$10$H7P5R41hK2vRcANdEiXQ.erEiTT3t4I3voo37I1n9wVPTa7sU72XK', '+63 9188885555', 'customer', 1, 0, '2025-09-03 12:45:00'),
('Fatima Dee', 'fatidad', 'cust6@example.com', '$2y$10$H7P5R41hK2vRcANdEiXQ.erEiTT3t4I3voo37I1n9wVPTa7sU72XK', '+63 9199996666', 'customer', 1, 0, '2025-09-22 10:18:00');

-- Additional owners for expanded shop coverage
INSERT INTO Users (full_name, username, email, password_hash, phone, role, is_verified, is_suspended) VALUES
('Gerry Valdez', 'gerrycut', 'owner4@example.com', '$2y$10$H7P5R41hK2vRcANdEiXQ.erEiTT3t4I3voo37I1n9wVPTa7sU72XK', '+63 9201110001', 'owner', 1, 0),
('Hector Ramos', 'hectorblend', 'owner5@example.com', '$2y$10$H7P5R41hK2vRcANdEiXQ.erEiTT3t4I3voo37I1n9wVPTa7sU72XK', '+63 9202220002', 'owner', 0, 0),
('Iris Molina', 'irism', 'owner6@example.com', '$2y$10$H7P5R41hK2vRcANdEiXQ.erEiTT3t4I3voo37I1n9wVPTa7sU72XK', '+63 9203330003', 'owner', 1, 0);

-- Capture generated IDs (assumes empty table before insert)
-- Admin: 1
-- Owners: 2,3,4
-- Customers: 5..10

-- Include contact phone and hours now that columns exist
-- 18 Batangas province cities/municipalities (sample selection) each with 2 shops
-- Owners rotated among existing owner IDs: 2,3,4,11,12,13
INSERT INTO Barbershops (owner_id, shop_name, description, address, city, shop_phone, open_time, close_time, status) VALUES
(2,'Prime Cuts Downtown','Modern grooming studio specializing in precision fades and beard sculpting.','Purok 2, Brgy. San Isidro','Batangas City','+63 917 555 1111','09:00:00','19:00:00','approved'),
(3,'Harbor Edge Clippers','Coastal themed barbershop with premium beard care.','Harbor Road, Pier Area','Batangas City','+63 917 555 1112','09:30:00','19:30:00','approved'),
(4,'Lipa Elite Lounge','Premium lounge ambience with espresso & classic razor shaves.','Gov. Laurel Hwy, Brgy. Sabang','Lipa','+63 917 555 1121','10:00:00','20:00:00','approved'),
(11,'Sabang Style Forge','Creative style hub focusing on experimental fades & color.','Sabang Extension','Lipa','+63 917 555 1122','11:00:00','20:00:00','approved'),
(12,'Tanauan Classic Barbers','Traditional Filipino barbershop family-friendly vibe.','Market Road, Brgy. Poblacion','Tanauan','+63 917 555 1131','08:30:00','18:00:00','pending'),
(13,'Tanauan Gentlemen Studio','Lounge studio offering premium beard & scalp treatments.','Jose Rizal St., Brgy. Wawa','Tanauan','+63 917 555 1132','09:30:00','19:30:00','approved'),
(2,'Bauan Heritage Cuts','Classic cuts with heritage interior & hot towel shaves.','Capitol Site','Bauan','+63 917 555 1141','09:00:00','19:00:00','approved'),
(3,'Bauan Fade Lab','Modern fade laboratory & training hub.','National Hwy, Brgy. Aplaya','Bauan','+63 917 555 1142','10:00:00','20:00:00','approved'),
(4,'Calaca Blade Works','Performance-driven grooming focused on speed & quality.','Rizal St.','Calaca','+63 917 555 1151','09:00:00','19:00:00','approved'),
(11,'Calaca Urban Clippers','Urban-inspired cuts for professionals.','Poblacion West','Calaca','+63 917 555 1152','10:00:00','21:00:00','approved'),
(12,'Calatagan Coast Cuts','Relaxed coastal cuts & beard therapy.','Lighthouse Road','Calatagan','+63 917 555 1161','09:00:00','18:30:00','approved'),
(13,'Calatagan Shoreline Barbers','Open-air concept grooming.','Resort Drive','Calatagan','+63 917 555 1162','08:30:00','18:00:00','pending'),
(2,'Laurel Peak Groomers','Scenic highland grooming experience.','Tagaytay Ridge','Laurel','+63 917 555 1171','09:00:00','19:00:00','approved'),
(3,'Laurel Summit Blades','Precision cuts w/ scalp spa add-ons.','Summit View Rd','Laurel','+63 917 555 1172','09:30:00','19:30:00','approved'),
(4,'Lemery Central Barbers','Community-focused classic barbershop.','Public Market Area','Lemery','+63 917 555 1181','08:30:00','18:30:00','approved'),
(11,'Lemery Modern Fade Hub','Contemporary trims & styling lab.','Diversion Road','Lemery','+63 917 555 1182','10:00:00','20:00:00','approved'),
(12,'Lian Coastal Clippers','Fresh seaside theme with salt scalp treatment.','Beachfront Road','Lian','+63 917 555 1191','09:00:00','19:00:00','approved'),
(13,'Lian Dockside Groom','Marine-inspired beard & style lounge.','Dock District','Lian','+63 917 555 1192','09:30:00','19:30:00','approved'),
(2,'Mabini Reef Cuts','Dive-community specialist grooming.','Dive Hub Lane','Mabini','+63 917 555 1201','09:00:00','19:00:00','approved'),
(3,'Mabini Coral Blades','Eco-friendly themed barbershop.','Eco Park Rd','Mabini','+63 917 555 1202','09:30:00','19:30:00','approved'),
(4,'Nasugbu Bay Masters','Premium tourist-side grooming lounge.','Beach Avenue','Nasugbu','+63 917 555 1211','10:00:00','21:00:00','approved'),
(11,'Nasugbu Shore Fades','Relaxed tropical ambience & beard oils.','Cove Strip','Nasugbu','+63 917 555 1212','09:30:00','20:00:00','approved'),
(12,'Rosario Plaza Cuts','Central plaza classic services.','Plaza Wing','Rosario','+63 917 555 1221','09:00:00','19:00:00','approved'),
(13,'Rosario Rustic Groom','Rustic Filipino heritage styling.','Old Town Road','Rosario','+63 917 555 1222','09:30:00','19:30:00','pending'),
(2,'San Jose Agri Blades','Agro-inspired minimalist space.','Farm to Market Rd','San Jose','+63 917 555 1231','09:00:00','19:00:00','approved'),
(3,'San Jose Precision Cuts','Efficient grooming for commuters.','Transit Terminal','San Jose','+63 917 555 1232','09:00:00','20:00:00','approved'),
(4,'San Juan Surfside Barbers','Surf culture driven grooming.','Coastal Highway','San Juan','+63 917 555 1241','09:30:00','19:30:00','approved'),
(11,'San Juan Heritage Lounge','Blend of classic + modern lounge.','Old Stone Walk','San Juan','+63 917 555 1242','10:00:00','20:00:00','approved'),
(12,'Santo Tomas Tech Cuts','Modern tech-driven booking & styling.','Tech Park Blvd','Santo Tomas','+63 917 555 1251','09:00:00','19:00:00','approved'),
(13,'Santo Tomas Express Groom','Fast service unit near transit hub.','Expressway Exit','Santo Tomas','+63 917 555 1252','08:30:00','18:30:00','approved'),
(2,'Taal Heritage Blades','Cultural heritage setting & themed cuts.','Heritage Lane','Taal','+63 917 555 1261','09:00:00','19:00:00','approved'),
(3,'Taal Basilica Groom','Classic styles near Basilica area.','Basilica Circle','Taal','+63 917 555 1262','09:30:00','19:30:00','approved'),
(4,'Tuy Highlands Cuts','Hillside airy concept shop.','Highland Drive','Tuy','+63 917 555 1271','09:00:00','19:00:00','approved'),
(11,'Tuy Ridge Groomers','Ridge ambient modern styling.','Ridge View','Tuy','+63 917 555 1272','09:30:00','19:30:00','approved'),
(12,'Balayan Ancestral Cuts','Colonial-inspired interior & service.','Ancestral Row','Balayan','+63 917 555 1281','09:00:00','19:00:00','approved'),
(13,'Balayan Plaza Groom','Central plaza modern lounge.','Plaza Core','Balayan','+63 917 555 1282','09:30:00','19:30:00','approved');

-- Shops IDs now assumed sequential: 1..36

-- ===========================================================================
-- Services (multiple per shop)
-- ===========================================================================
INSERT INTO Services (shop_id, service_name, duration_minutes, price) VALUES
(1,'Standard Haircut',30,150.00),(1,'Skin Fade',40,220.00),(1,'Beard Trim',20,120.00),(2,'Signature Fade',45,260.00),(2,'Kids Cut',25,130.00),
(3,'Signature Fade',45,260.00),(3,'Hot Towel Shave',25,190.00),(4,'Modern Restyle',50,300.00),(4,'Color Accent',55,550.00),(5,'Classic Haircut',30,150.00),
(6,'Beard Sculpt',30,180.00),(7,'Express Fade',25,160.00),(8,'Quick Trim',15,90.00),(9,'Hybrid Classic',40,250.00),(10,'Precision Line',20,140.00),
(11,'Coastal Cut',30,170.00),(12,'Shoreline Wash',20,120.00),(13,'Highland Fade',35,200.00),(14,'Summit Classic',30,160.00),(15,'Community Cut',30,140.00),
(16,'Modern Fade',35,210.00),(17,'Seaside Classic',30,150.00),(18,'Dockside Beard',25,130.00),(19,'Dive Groom',30,180.00),(20,'Eco Trim',25,140.00),
(21,'Tourist Lounge Cut',35,240.00),(22,'Tropical Fade',30,200.00),(23,'Plaza Classic',30,150.00),(24,'Rustic Groom',35,190.00),(25,'Agri Cut',30,145.00),
(26,'Precision Commuter',25,135.00),(27,'Surfside Fade',35,230.00),(28,'Heritage Lounge Cut',30,210.00),(29,'Tech Modern Cut',30,220.00),(30,'Express Trim',20,120.00),
(31,'Heritage Blade',35,240.00),(32,'Basilica Classic',30,180.00),(33,'Highlands Hybrid',40,250.00),(34,'Ridge Groom',30,175.00),(35,'Ancestral Classic',30,190.00),
(36,'Plaza Elite Cut',35,230.00);

-- ===========================================================================
-- Appointments (varied statuses, future & past)
-- ===========================================================================
INSERT INTO Appointments (customer_id, shop_id, service_id, appointment_date, status, payment_option, notes, is_paid)
VALUES
(5, 1, 1, DATE_SUB(@SEED_BASE_DATETIME, INTERVAL 5 DAY), 'completed', 'cash', 'Requested low fade.', 1),
(6, 1, 2, DATE_SUB(@SEED_BASE_DATETIME, INTERVAL 2 DAY), 'completed', 'online', 'Beard alignment included.', 1),
(7, 2, 5, DATE_ADD(@SEED_BASE_DATETIME, INTERVAL 1 DAY), 'confirmed', 'cash', 'First visit.', 0),
(8, 2, 6, DATE_ADD(@SEED_BASE_DATETIME, INTERVAL 3 DAY), 'pending', 'online', NULL, 0);
-- Removed appointments for shop_id 3 (Tanauan Classic Barbers) since owner is unverified and shop status is pending

-- Appointment IDs assumed sequential 1..4 (appointments for unverified shops removed)

-- ===========================================================================
-- Payments (mix of appointment & subscription related)
-- ===========================================================================
INSERT INTO Payments (user_id, shop_id, appointment_id, subscription_id, amount, tax_amount, payment_method, payment_status, paid_at, transaction_type)
VALUES
(5, 1, 1, NULL, 150.00, 18.00, 'cash', 'completed', DATE_SUB(@SEED_BASE_DATETIME, INTERVAL 5 DAY), 'appointment'),
(6, 1, 2, NULL, 220.00, 26.40, 'online', 'completed', DATE_SUB(@SEED_BASE_DATETIME, INTERVAL 2 DAY), 'appointment');

-- ===========================================================================
-- Subscriptions (annual) referencing payments later (payment_id FK added via ALTER)
--  Note: Insert first with NULL payment_id then update once payment record exists
-- ===========================================================================
-- Updated to include plan_type column (monthly|yearly)
-- Subscriptions with updated pricing (yearly 3999, monthly 399)
INSERT INTO Shop_Subscriptions (shop_id, plan_type, annual_fee, tax_rate, payment_status, valid_from, valid_to) VALUES
(1,'yearly',3999.00,12.00,'paid',DATE_SUB(@SEED_BASE_DATE,INTERVAL 20 DAY),DATE_ADD(@SEED_BASE_DATE,INTERVAL 345 DAY)),
(2,'yearly',3999.00,12.00,'pending',@SEED_BASE_DATE,DATE_ADD(@SEED_BASE_DATE,INTERVAL 365 DAY)),
(3,'monthly',399.00,12.00,'paid',DATE_SUB(@SEED_BASE_DATE,INTERVAL 5 DAY),DATE_ADD(@SEED_BASE_DATE,INTERVAL 25 DAY)),
(4,'monthly',399.00,12.00,'pending',@SEED_BASE_DATE,DATE_ADD(@SEED_BASE_DATE,INTERVAL 30 DAY)),
(5,'yearly',3999.00,12.00,'paid',DATE_SUB(@SEED_BASE_DATE,INTERVAL 10 DAY),DATE_ADD(@SEED_BASE_DATE,INTERVAL 355 DAY)),
(7,'monthly',399.00,12.00,'pending',@SEED_BASE_DATE,DATE_ADD(@SEED_BASE_DATE,INTERVAL 30 DAY)),
(9,'yearly',3999.00,12.00,'paid',DATE_SUB(@SEED_BASE_DATE,INTERVAL 15 DAY),DATE_ADD(@SEED_BASE_DATE,INTERVAL 350 DAY)),
(11,'monthly',399.00,12.00,'paid',DATE_SUB(@SEED_BASE_DATE,INTERVAL 12 DAY),DATE_ADD(@SEED_BASE_DATE,INTERVAL 18 DAY)),
(13,'yearly',3999.00,12.00,'pending',@SEED_BASE_DATE,DATE_ADD(@SEED_BASE_DATE,INTERVAL 365 DAY)),
(15,'yearly',3999.00,12.00,'paid',DATE_SUB(@SEED_BASE_DATE,INTERVAL 30 DAY),DATE_ADD(@SEED_BASE_DATE,INTERVAL 335 DAY)),
(17,'monthly',399.00,12.00,'paid',DATE_SUB(@SEED_BASE_DATE,INTERVAL 3 DAY),DATE_ADD(@SEED_BASE_DATE,INTERVAL 27 DAY)),
(19,'yearly',3999.00,12.00,'pending',@SEED_BASE_DATE,DATE_ADD(@SEED_BASE_DATE,INTERVAL 365 DAY)),
(21,'yearly',3999.00,12.00,'paid',DATE_SUB(@SEED_BASE_DATE,INTERVAL 40 DAY),DATE_ADD(@SEED_BASE_DATE,INTERVAL 325 DAY)),
(23,'monthly',399.00,12.00,'pending',@SEED_BASE_DATE,DATE_ADD(@SEED_BASE_DATE,INTERVAL 30 DAY)),
(25,'yearly',3999.00,12.00,'paid',DATE_SUB(@SEED_BASE_DATE,INTERVAL 8 DAY),DATE_ADD(@SEED_BASE_DATE,INTERVAL 357 DAY)),
(27,'yearly',3999.00,12.00,'pending',@SEED_BASE_DATE,DATE_ADD(@SEED_BASE_DATE,INTERVAL 365 DAY)),
(29,'monthly',399.00,12.00,'paid',DATE_SUB(@SEED_BASE_DATE,INTERVAL 6 DAY),DATE_ADD(@SEED_BASE_DATE,INTERVAL 24 DAY)),
(31,'yearly',3999.00,12.00,'paid',DATE_SUB(@SEED_BASE_DATE,INTERVAL 18 DAY),DATE_ADD(@SEED_BASE_DATE,INTERVAL 347 DAY)),
(33,'monthly',399.00,12.00,'pending',@SEED_BASE_DATE,DATE_ADD(@SEED_BASE_DATE,INTERVAL 30 DAY)),
(35,'yearly',3999.00,12.00,'paid',DATE_SUB(@SEED_BASE_DATE,INTERVAL 5 DAY),DATE_ADD(@SEED_BASE_DATE,INTERVAL 360 DAY));

-- Simulate linking first payment to first subscription
-- Add subscription payments for PAID rows (compute tax 12%)
-- yearly 3999 => tax 479.88 ; monthly 399 => tax 47.88
INSERT INTO Payments (user_id, shop_id, appointment_id, subscription_id, amount, tax_amount, payment_method, payment_status, paid_at, transaction_type) VALUES
((SELECT owner_id FROM Barbershops WHERE shop_id=1),1,NULL,1,3999.00,479.88,'cash','completed',DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 19 DAY),'subscription'),
((SELECT owner_id FROM Barbershops WHERE shop_id=3),3,NULL,3,399.00,47.88,'cash','completed',DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 4 DAY),'subscription'),
((SELECT owner_id FROM Barbershops WHERE shop_id=5),5,NULL,5,3999.00,479.88,'cash','completed',DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 9 DAY),'subscription'),
((SELECT owner_id FROM Barbershops WHERE shop_id=9),9,NULL,7,3999.00,479.88,'cash','completed',DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 14 DAY),'subscription'),
((SELECT owner_id FROM Barbershops WHERE shop_id=11),11,NULL,8,399.00,47.88,'cash','completed',DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 11 DAY),'subscription'),
((SELECT owner_id FROM Barbershops WHERE shop_id=15),15,NULL,10,3999.00,479.88,'cash','completed',DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 29 DAY),'subscription'),
((SELECT owner_id FROM Barbershops WHERE shop_id=17),17,NULL,11,399.00,47.88,'cash','completed',DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 2 DAY),'subscription'),
((SELECT owner_id FROM Barbershops WHERE shop_id=21),21,NULL,13,3999.00,479.88,'cash','completed',DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 39 DAY),'subscription'),
((SELECT owner_id FROM Barbershops WHERE shop_id=25),25,NULL,15,3999.00,479.88,'cash','completed',DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 7 DAY),'subscription'),
((SELECT owner_id FROM Barbershops WHERE shop_id=29),29,NULL,17,399.00,47.88,'cash','completed',DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 5 DAY),'subscription'),
((SELECT owner_id FROM Barbershops WHERE shop_id=31),31,NULL,18,3999.00,479.88,'cash','completed',DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 17 DAY),'subscription'),
((SELECT owner_id FROM Barbershops WHERE shop_id=35),35,NULL,20,3999.00,479.88,'cash','completed',DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 4 DAY),'subscription');

-- Link payments to subscriptions (assuming payment IDs immediately follow earlier appointment payments)
-- Appointment payments inserted earlier = 2 rows, so first subscription payment will be id=3, etc.
UPDATE Shop_Subscriptions SET payment_id = 3 WHERE subscription_id = 1;
UPDATE Shop_Subscriptions SET payment_id = 4 WHERE subscription_id = 3;
UPDATE Shop_Subscriptions SET payment_id = 5 WHERE subscription_id = 5;
UPDATE Shop_Subscriptions SET payment_id = 6 WHERE subscription_id = 7;
UPDATE Shop_Subscriptions SET payment_id = 7 WHERE subscription_id = 8;
UPDATE Shop_Subscriptions SET payment_id = 8 WHERE subscription_id = 10;
UPDATE Shop_Subscriptions SET payment_id = 9 WHERE subscription_id = 11;
UPDATE Shop_Subscriptions SET payment_id = 10 WHERE subscription_id = 13;
UPDATE Shop_Subscriptions SET payment_id = 11 WHERE subscription_id = 15;
UPDATE Shop_Subscriptions SET payment_id = 12 WHERE subscription_id = 17;
UPDATE Shop_Subscriptions SET payment_id = 13 WHERE subscription_id = 18;
UPDATE Shop_Subscriptions SET payment_id = 14 WHERE subscription_id = 20;

-- ---------------------------------------------------------------------------
-- Additional Historical Subscriptions & Payments (Jan - Jun 2025)
-- Purpose: Broaden revenue distribution across more 2025 months for admin charts
-- Using shops that previously had no subscription records (6,8,10,12,14,16)
-- Assumed existing subscriptions count = 20 (IDs 1..20) so new IDs start at 21.
-- Existing payment rows count = 14; new payments will become IDs 15..20.
-- ---------------------------------------------------------------------------
INSERT INTO Shop_Subscriptions (shop_id, plan_type, annual_fee, tax_rate, payment_status, valid_from, valid_to) VALUES
(6,'monthly',399.00,12.00,'paid','2025-01-05','2025-02-04'),
(8,'monthly',399.00,12.00,'paid','2025-02-05','2025-03-07'),
(10,'monthly',399.00,12.00,'paid','2025-03-05','2025-04-04'),
(12,'monthly',399.00,12.00,'paid','2025-04-05','2025-05-05'),
(14,'monthly',399.00,12.00,'paid','2025-05-05','2025-06-04'),
(16,'monthly',399.00,12.00,'paid','2025-06-05','2025-07-05');

-- Insert corresponding subscription payments across earlier months
INSERT INTO Payments (user_id, shop_id, appointment_id, subscription_id, amount, tax_amount, payment_method, payment_status, paid_at, transaction_type) VALUES
((SELECT owner_id FROM Barbershops WHERE shop_id=6),6,NULL,21,399.00,47.88,'online','completed','2025-01-05 10:15:00','subscription'),
((SELECT owner_id FROM Barbershops WHERE shop_id=8),8,NULL,22,399.00,47.88,'online','completed','2025-02-05 09:40:00','subscription'),
((SELECT owner_id FROM Barbershops WHERE shop_id=10),10,NULL,23,399.00,47.88,'cash','completed','2025-03-05 11:05:00','subscription'),
((SELECT owner_id FROM Barbershops WHERE shop_id=12),12,NULL,24,399.00,47.88,'cash','completed','2025-04-05 14:20:00','subscription'),
((SELECT owner_id FROM Barbershops WHERE shop_id=14),14,NULL,25,399.00,47.88,'online','completed','2025-05-05 13:10:00','subscription'),
((SELECT owner_id FROM Barbershops WHERE shop_id=16),16,NULL,26,399.00,47.88,'cash','completed','2025-06-05 15:45:00','subscription');

-- Extend revenue into July, August, September (new subscriptions for shops 18,20,22)
INSERT INTO Shop_Subscriptions (shop_id, plan_type, annual_fee, tax_rate, payment_status, valid_from, valid_to) VALUES
(18,'monthly',399.00,12.00,'paid','2025-07-06','2025-08-05'),
(20,'monthly',399.00,12.00,'paid','2025-08-07','2025-09-06'),
(22,'monthly',399.00,12.00,'paid','2025-09-08','2025-10-07');

INSERT INTO Payments (user_id, shop_id, appointment_id, subscription_id, amount, tax_amount, payment_method, payment_status, paid_at, transaction_type) VALUES
((SELECT owner_id FROM Barbershops WHERE shop_id=18),18,NULL,(SELECT subscription_id FROM Shop_Subscriptions WHERE shop_id=18 AND valid_from='2025-07-06'),399.00,47.88,'cash','completed','2025-07-06 09:12:00','subscription'),
((SELECT owner_id FROM Barbershops WHERE shop_id=20),20,NULL,(SELECT subscription_id FROM Shop_Subscriptions WHERE shop_id=20 AND valid_from='2025-08-07'),399.00,47.88,'online','completed','2025-08-07 10:33:00','subscription'),
((SELECT owner_id FROM Barbershops WHERE shop_id=22),22,NULL,(SELECT subscription_id FROM Shop_Subscriptions WHERE shop_id=22 AND valid_from='2025-09-08'),399.00,47.88,'cash','completed','2025-09-08 11:27:00','subscription');

-- Backfill created_at for new Jul-Sep payments
UPDATE Payments SET created_at='2025-07-06 09:12:05' WHERE paid_at='2025-07-06 09:12:00' AND transaction_type='subscription';
UPDATE Payments SET created_at='2025-08-07 10:33:05' WHERE paid_at='2025-08-07 10:33:00' AND transaction_type='subscription';
UPDATE Payments SET created_at='2025-09-08 11:27:05' WHERE paid_at='2025-09-08 11:27:00' AND transaction_type='subscription';

-- Backfill created_at to align with paid_at for historical revenue accuracy
UPDATE Payments SET created_at='2025-01-05 10:15:05' WHERE subscription_id=21 AND transaction_type='subscription';
UPDATE Payments SET created_at='2025-02-05 09:40:05' WHERE subscription_id=22 AND transaction_type='subscription';
UPDATE Payments SET created_at='2025-03-05 11:05:05' WHERE subscription_id=23 AND transaction_type='subscription';
UPDATE Payments SET created_at='2025-04-05 14:20:05' WHERE subscription_id=24 AND transaction_type='subscription';
UPDATE Payments SET created_at='2025-05-05 13:10:05' WHERE subscription_id=25 AND transaction_type='subscription';
UPDATE Payments SET created_at='2025-06-05 15:45:05' WHERE subscription_id=26 AND transaction_type='subscription';

-- Link new payments to their subscriptions
UPDATE Shop_Subscriptions SET payment_id = 15 WHERE subscription_id = 21;
UPDATE Shop_Subscriptions SET payment_id = 16 WHERE subscription_id = 22;
UPDATE Shop_Subscriptions SET payment_id = 17 WHERE subscription_id = 23;
UPDATE Shop_Subscriptions SET payment_id = 18 WHERE subscription_id = 24;
UPDATE Shop_Subscriptions SET payment_id = 19 WHERE subscription_id = 25;
UPDATE Shop_Subscriptions SET payment_id = 20 WHERE subscription_id = 26;

-- ===========================================================================
-- Reviews (only approved shops for now)
-- ===========================================================================
INSERT INTO Reviews (customer_id, shop_id, rating, comment, appointment_id) VALUES
(5, 1, 5, 'Excellent fade and clean environment.', 1),
(6, 1, 4, 'Great service; a bit of wait.', 2),
(7, 2, 5, 'Loved the lounge experience.', NULL);

-- ===========================================================================
-- Additional Bulk Appointments (load testing & analytics realism)
-- Target: >= 270 total appointments (we already inserted 4 above)
-- Strategy: Distribute across 36 shops, 10 services pattern, customers 5..10, last 60 days to next 15 days
-- Status ratio approx: completed 55%, confirmed 20%, pending 15%, cancelled 10%
-- ===========================================================================
-- NOTE: Using relative date math so data stays fresh relative to run date.
-- We break into chunks to avoid overly long single INSERT line.

-- Batch 1 (Appointments 5 - ~120)
INSERT INTO Appointments (customer_id, shop_id, service_id, appointment_date, status, payment_option, notes, is_paid) VALUES
-- Loop concept expanded manually: (day offsets negative = past, positive = future)
(5,1,1,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 30 DAY),'completed','cash','Auto-seed batch',1),
(6,1,2,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 29 DAY),'completed','online',NULL,1),
(7,2,5,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 28 DAY),'completed','cash',NULL,1),
(8,2,6,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 28 DAY),'cancelled','online','No show',0),
(9,3,7,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 27 DAY),'completed','cash',NULL,1),
(10,3,8,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 27 DAY),'confirmed','online',NULL,0),
(5,4,9,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 26 DAY),'completed','cash',NULL,1),
(6,4,10,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 26 DAY),'pending','online',NULL,0),
(7,5,11,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 25 DAY),'completed','cash',NULL,1),
(8,5,12,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 25 DAY),'completed','cash',NULL,1),
(9,6,13,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 24 DAY),'completed','cash',NULL,1),
(10,6,14,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 24 DAY),'cancelled','cash','Client cancelled',0),
(5,7,15,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 23 DAY),'completed','online',NULL,1),
(6,7,16,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 23 DAY),'completed','cash',NULL,1),
(7,8,17,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 22 DAY),'completed','cash',NULL,1),
(8,8,18,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 22 DAY),'pending','online',NULL,0),
(9,9,19,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 21 DAY),'completed','cash',NULL,1),
(10,9,20,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 21 DAY),'completed','online',NULL,1),
(5,10,21,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 20 DAY),'confirmed','cash',NULL,0),
(6,10,22,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 20 DAY),'completed','cash',NULL,1),
(7,11,23,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 19 DAY),'completed','cash',NULL,1),
(8,11,24,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 19 DAY),'completed','online',NULL,1),
(9,12,25,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 18 DAY),'completed','cash',NULL,1),
(10,12,26,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 18 DAY),'cancelled','cash','Late arrival',0),
(5,13,27,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 17 DAY),'completed','online',NULL,1),
(6,13,28,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 17 DAY),'completed','cash',NULL,1),
(7,14,29,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 16 DAY),'completed','cash',NULL,1),
(8,14,30,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 16 DAY),'pending','online',NULL,0),
(9,15,31,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 15 DAY),'completed','cash',NULL,1),
(10,15,32,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 15 DAY),'completed','cash',NULL,1),
(5,16,33,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 14 DAY),'completed','cash',NULL,1),
(6,16,34,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 14 DAY),'confirmed','online',NULL,0),
(7,17,35,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 13 DAY),'completed','cash',NULL,1),
(8,17,36,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 13 DAY),'completed','cash',NULL,1),
(9,18,1,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 12 DAY),'completed','cash',NULL,1),
(10,18,2,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 12 DAY),'cancelled','online','Weather issues',0),
(5,19,3,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 11 DAY),'completed','cash',NULL,1),
(6,19,4,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 11 DAY),'completed','online',NULL,1),
(7,20,5,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 10 DAY),'completed','cash',NULL,1),
(8,20,6,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 10 DAY),'pending','cash',NULL,0),
(9,21,7,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 9 DAY),'completed','cash',NULL,1),
(10,21,8,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 9 DAY),'completed','online',NULL,1),
(5,22,9,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 8 DAY),'completed','cash',NULL,1),
(6,22,10,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 8 DAY),'confirmed','cash',NULL,0),
(7,23,11,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 7 DAY),'completed','cash',NULL,1),
(8,23,12,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 7 DAY),'completed','online',NULL,1),
(9,24,13,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 6 DAY),'completed','cash',NULL,1),
(10,24,14,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 6 DAY),'cancelled','cash','Machine issue',0),
(5,25,15,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 5 DAY),'completed','online',NULL,1),
(6,25,16,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 5 DAY),'completed','cash',NULL,1),
(7,26,17,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 4 DAY),'completed','cash',NULL,1),
(8,26,18,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 4 DAY),'pending','online',NULL,0),
(9,27,19,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 3 DAY),'completed','cash',NULL,1),
(10,27,20,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 3 DAY),'completed','cash',NULL,1),
(5,28,21,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 2 DAY),'completed','cash',NULL,1),
(6,28,22,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 2 DAY),'confirmed','online',NULL,0),
(7,29,23,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 1 DAY),'completed','cash',NULL,1),
(8,29,24,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 1 DAY),'completed','cash',NULL,1),
(9,30,25,@SEED_BASE_DATETIME,'completed','cash',NULL,1),
(10,30,26,@SEED_BASE_DATETIME,'cancelled','online','Changed mind',0),
(5,31,27,DATE_ADD(@SEED_BASE_DATETIME,INTERVAL 1 DAY),'confirmed','cash',NULL,0),
(6,31,28,DATE_ADD(@SEED_BASE_DATETIME,INTERVAL 1 DAY),'pending','online',NULL,0),
(7,32,29,DATE_ADD(@SEED_BASE_DATETIME,INTERVAL 2 DAY),'confirmed','cash',NULL,0),
(8,32,30,DATE_ADD(@SEED_BASE_DATETIME,INTERVAL 2 DAY),'pending','online',NULL,0),
(9,33,31,DATE_ADD(@SEED_BASE_DATETIME,INTERVAL 3 DAY),'confirmed','cash',NULL,0),
(10,33,32,DATE_ADD(@SEED_BASE_DATETIME,INTERVAL 3 DAY),'pending','cash',NULL,0),
(5,34,33,DATE_ADD(@SEED_BASE_DATETIME,INTERVAL 4 DAY),'confirmed','online',NULL,0),
(6,34,34,DATE_ADD(@SEED_BASE_DATETIME,INTERVAL 4 DAY),'pending','cash',NULL,0),
(7,35,35,DATE_ADD(@SEED_BASE_DATETIME,INTERVAL 5 DAY),'confirmed','cash',NULL,0),
(8,35,36,DATE_ADD(@SEED_BASE_DATETIME,INTERVAL 5 DAY),'pending','online',NULL,0),
(9,36,1,DATE_ADD(@SEED_BASE_DATETIME,INTERVAL 6 DAY),'confirmed','cash',NULL,0),
(10,36,2,DATE_ADD(@SEED_BASE_DATETIME,INTERVAL 6 DAY),'pending','cash',NULL,0);

-- Batch 2 (Appointments ~121 - ~240)
INSERT INTO Appointments (customer_id, shop_id, service_id, appointment_date, status, payment_option, notes, is_paid) VALUES
(5,1,3,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 32 DAY),'completed','cash',NULL,1),
(6,2,4,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 31 DAY),'completed','cash',NULL,1),
(7,3,5,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 30 DAY),'completed','cash',NULL,1),
(8,4,6,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 29 DAY),'cancelled','online','Delayed arrival',0),
(9,5,7,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 28 DAY),'completed','online',NULL,1),
(10,6,8,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 27 DAY),'completed','cash',NULL,1),
(5,7,9,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 26 DAY),'completed','cash',NULL,1),
(6,8,10,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 25 DAY),'completed','online',NULL,1),
(7,9,11,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 24 DAY),'completed','cash',NULL,1),
(8,10,12,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 23 DAY),'completed','cash',NULL,1),
(9,11,13,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 22 DAY),'completed','online',NULL,1),
(10,12,14,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 21 DAY),'completed','cash',NULL,1),
(5,13,15,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 20 DAY),'completed','cash',NULL,1),
(6,14,16,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 19 DAY),'completed','cash',NULL,1),
(7,15,17,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 18 DAY),'cancelled','cash','Client sick',0),
(8,16,18,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 17 DAY),'completed','online',NULL,1),
(9,17,19,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 16 DAY),'completed','cash',NULL,1),
(10,18,20,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 15 DAY),'completed','cash',NULL,1),
(5,19,21,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 14 DAY),'completed','online',NULL,1),
(6,20,22,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 13 DAY),'completed','cash',NULL,1),
(7,21,23,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 12 DAY),'completed','cash',NULL,1),
(8,22,24,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 11 DAY),'completed','cash',NULL,1),
(9,23,25,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 10 DAY),'completed','cash',NULL,1),
(10,24,26,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 9 DAY),'completed','cash',NULL,1),
(5,25,27,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 8 DAY),'completed','online',NULL,1),
(6,26,28,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 7 DAY),'completed','cash',NULL,1),
(7,27,29,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 6 DAY),'completed','cash',NULL,1),
(8,28,30,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 5 DAY),'cancelled','online','Rescheduled',0),
(9,29,31,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 4 DAY),'completed','cash',NULL,1),
(10,30,32,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 3 DAY),'completed','cash',NULL,1),
(5,31,33,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 2 DAY),'completed','online',NULL,1),
(6,32,34,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 1 DAY),'completed','cash',NULL,1),
(7,33,35,@SEED_BASE_DATETIME,'completed','cash',NULL,1),
(8,34,36,@SEED_BASE_DATETIME,'confirmed','cash',NULL,0),
(9,35,1,DATE_ADD(@SEED_BASE_DATETIME,INTERVAL 1 DAY),'confirmed','cash',NULL,0),
(10,36,2,DATE_ADD(@SEED_BASE_DATETIME,INTERVAL 2 DAY),'pending','online',NULL,0),
(5,1,3,DATE_ADD(@SEED_BASE_DATETIME,INTERVAL 3 DAY),'pending','cash',NULL,0),
(6,2,4,DATE_ADD(@SEED_BASE_DATETIME,INTERVAL 4 DAY),'pending','cash',NULL,0),
(7,3,5,DATE_ADD(@SEED_BASE_DATETIME,INTERVAL 5 DAY),'pending','online',NULL,0),
(8,4,6,DATE_ADD(@SEED_BASE_DATETIME,INTERVAL 6 DAY),'pending','cash',NULL,0),
(9,5,7,DATE_ADD(@SEED_BASE_DATETIME,INTERVAL 7 DAY),'pending','online',NULL,0),
(10,6,8,DATE_ADD(@SEED_BASE_DATETIME,INTERVAL 8 DAY),'pending','cash',NULL,0),
(5,7,9,DATE_ADD(@SEED_BASE_DATETIME,INTERVAL 9 DAY),'pending','cash',NULL,0),
(6,8,10,DATE_ADD(@SEED_BASE_DATETIME,INTERVAL 10 DAY),'pending','online',NULL,0),
(7,9,11,DATE_ADD(@SEED_BASE_DATETIME,INTERVAL 11 DAY),'pending','cash',NULL,0),
(8,10,12,DATE_ADD(@SEED_BASE_DATETIME,INTERVAL 12 DAY),'pending','cash',NULL,0),
(9,11,13,DATE_ADD(@SEED_BASE_DATETIME,INTERVAL 13 DAY),'pending','cash',NULL,0),
(10,12,14,DATE_ADD(@SEED_BASE_DATETIME,INTERVAL 14 DAY),'pending','online',NULL,0),
(5,13,15,DATE_ADD(@SEED_BASE_DATETIME,INTERVAL 15 DAY),'pending','cash',NULL,0),
(6,14,16,DATE_ADD(@SEED_BASE_DATETIME,INTERVAL 1 DAY),'confirmed','cash',NULL,0),
(7,15,17,DATE_ADD(@SEED_BASE_DATETIME,INTERVAL 2 DAY),'confirmed','cash',NULL,0),
(8,16,18,DATE_ADD(@SEED_BASE_DATETIME,INTERVAL 3 DAY),'confirmed','online',NULL,0),
(9,17,19,DATE_ADD(@SEED_BASE_DATETIME,INTERVAL 4 DAY),'confirmed','cash',NULL,0),
(10,18,20,DATE_ADD(@SEED_BASE_DATETIME,INTERVAL 5 DAY),'confirmed','cash',NULL,0),
(5,19,21,DATE_ADD(@SEED_BASE_DATETIME,INTERVAL 6 DAY),'confirmed','online',NULL,0),
(6,20,22,DATE_ADD(@SEED_BASE_DATETIME,INTERVAL 7 DAY),'confirmed','cash',NULL,0),
(7,21,23,DATE_ADD(@SEED_BASE_DATETIME,INTERVAL 8 DAY),'confirmed','cash',NULL,0),
(8,22,24,DATE_ADD(@SEED_BASE_DATETIME,INTERVAL 9 DAY),'confirmed','cash',NULL,0),
(9,23,25,DATE_ADD(@SEED_BASE_DATETIME,INTERVAL 10 DAY),'confirmed','cash',NULL,0),
(10,24,26,DATE_ADD(@SEED_BASE_DATETIME,INTERVAL 11 DAY),'confirmed','cash',NULL,0),
(5,25,27,DATE_ADD(@SEED_BASE_DATETIME,INTERVAL 12 DAY),'confirmed','online',NULL,0),
(6,26,28,DATE_ADD(@SEED_BASE_DATETIME,INTERVAL 13 DAY),'confirmed','cash',NULL,0),
(7,27,29,DATE_ADD(@SEED_BASE_DATETIME,INTERVAL 14 DAY),'confirmed','cash',NULL,0),
(8,28,30,DATE_ADD(@SEED_BASE_DATETIME,INTERVAL 15 DAY),'confirmed','cash',NULL,0);

-- Batch 3 (Appointments ~241 - ~280+) filled with mixed historical completed records for density
INSERT INTO Appointments (customer_id, shop_id, service_id, appointment_date, status, payment_option, notes, is_paid) VALUES
(5,4,9,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 45 DAY),'completed','cash',NULL,1),
(6,8,18,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 44 DAY),'completed','cash',NULL,1),
(7,12,26,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 43 DAY),'completed','cash',NULL,1),
(8,16,34,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 42 DAY),'completed','cash',NULL,1),
(9,20,6,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 41 DAY),'completed','cash',NULL,1),
(10,24,14,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 40 DAY),'completed','cash',NULL,1),
(5,28,22,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 39 DAY),'completed','cash',NULL,1),
(6,32,30,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 38 DAY),'completed','cash',NULL,1),
(7,36,2,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 37 DAY),'completed','cash',NULL,1),
(8,3,7,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 36 DAY),'completed','cash',NULL,1),
(9,7,15,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 35 DAY),'completed','cash',NULL,1),
(10,11,23,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 34 DAY),'completed','cash',NULL,1),
(5,15,31,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 33 DAY),'completed','cash',NULL,1),
(6,19,3,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 32 DAY),'completed','cash',NULL,1),
(7,23,11,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 31 DAY),'completed','cash',NULL,1),
(8,27,19,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 30 DAY),'completed','cash',NULL,1),
(9,31,27,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 29 DAY),'completed','cash',NULL,1),
(10,35,35,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 28 DAY),'completed','cash',NULL,1),
(5,2,5,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 27 DAY),'completed','cash',NULL,1),
(6,6,13,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 26 DAY),'completed','cash',NULL,1),
(7,10,21,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 25 DAY),'completed','cash',NULL,1),
(8,14,29,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 24 DAY),'completed','cash',NULL,1),
(9,18,1,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 23 DAY),'completed','cash',NULL,1),
(10,22,9,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 22 DAY),'completed','cash',NULL,1),
(5,26,17,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 21 DAY),'completed','cash',NULL,1),
(6,30,25,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 20 DAY),'completed','cash',NULL,1),
(7,34,33,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 19 DAY),'completed','cash',NULL,1),
(8,1,2,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 18 DAY),'completed','cash',NULL,1),
(9,5,7,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 17 DAY),'completed','cash',NULL,1),
(10,9,19,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 16 DAY),'completed','cash',NULL,1),
(5,13,27,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 15 DAY),'completed','cash',NULL,1),
(6,17,35,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 14 DAY),'completed','cash',NULL,1),
(7,21,23,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 13 DAY),'completed','cash',NULL,1),
(8,25,15,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 12 DAY),'completed','cash',NULL,1),
(9,29,31,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 11 DAY),'completed','cash',NULL,1),
(10,33,32,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 10 DAY),'completed','cash',NULL,1),
(5,4,9,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 9 DAY),'completed','cash',NULL,1),
(6,8,18,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 8 DAY),'completed','cash',NULL,1),
(7,12,26,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 7 DAY),'completed','cash',NULL,1),
(8,16,34,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 6 DAY),'completed','cash',NULL,1),
(9,20,6,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 5 DAY),'completed','cash',NULL,1),
(10,24,14,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 4 DAY),'completed','cash',NULL,1),
(5,28,22,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 3 DAY),'completed','cash',NULL,1),
(6,32,30,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 2 DAY),'completed','cash',NULL,1),
(7,36,2,DATE_SUB(@SEED_BASE_DATETIME,INTERVAL 1 DAY),'completed','cash',NULL,1),
(8,3,7,@SEED_BASE_DATETIME,'completed','cash',NULL,1),
(9,7,15,@SEED_BASE_DATETIME,'completed','cash',NULL,1),
(10,11,23,@SEED_BASE_DATETIME,'completed','cash',NULL,1);


-- ===========================================================================
-- Notifications (system + email variants)
-- ===========================================================================
INSERT INTO Notifications (user_id, title, message, type, is_read)
VALUES
(2, 'Shop Approved', 'Your shop Prime Cuts Batangas has been approved.', 'system', 1),
(3, 'Subscription Reminder', 'Your annual subscription payment is pending.', 'email', 0),
(5, 'Appointment Completed', 'Hope you enjoyed your haircut at Prime Cuts!', 'system', 0);

-- ===========================================================================
-- Documents (owner verification placeholders)
-- ===========================================================================
-- Update file paths to match current storage directory usage (storage/documents)
INSERT INTO Documents (owner_id, shop_id, doc_type, file_path, status, notes)
VALUES
(2, 1, 'business_permit', 'storage/documents/permit_owner2.jpg', 'approved', 'Valid until next year'),
(2, 1, 'shop_photo', 'storage/documents/shop1.jpg', 'approved', NULL),
(3, 2, 'business_permit', 'storage/documents/permit_owner3.jpg', 'pending', NULL),
(4, 3, 'personal_id_front', 'storage/documents/id_owner4_front.jpg', 'pending', 'Awaiting back side');

-- ===========================================================================
-- Data sanity quick checks (optional SELECTs - comment out in production seed)
-- ===========================================================================
-- SELECT COUNT(*) UsersCount FROM Users;
-- SELECT COUNT(*) ShopsCount FROM Barbershops;
-- SELECT COUNT(*) ServicesCount FROM Services;
-- SELECT status, COUNT(*) FROM Appointments GROUP BY status;
-- SELECT role, COUNT(*) FROM Users GROUP BY role;

-- End of seed
