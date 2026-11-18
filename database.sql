-- ==============================================================================
-- PLEZZY PRODUCTION DATABASE SCHEMA (HOSTINGER MYSQL / MARIADB COMPATIBLE)
-- Character Set: utf8mb4 (Supports Emojis, Currency Signs & Full International Text)
-- Collation: utf8mb4_unicode_ci
-- ==============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------------------------
-- 1. TABLE: products
-- Stores the complete product catalog, pricing, availability, and media
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `products` (
  `id` VARCHAR(64) NOT NULL,
  `slug` VARCHAR(128) NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `subtitle` TEXT DEFAULT NULL,
  `category` VARCHAR(64) DEFAULT 'STANDARD',
  `badge` VARCHAR(64) DEFAULT NULL,
  `role` VARCHAR(191) DEFAULT NULL,
  `tagline` TEXT DEFAULT NULL,
  `price_inr` INT(11) NOT NULL DEFAULT 0,
  `original_inr` INT(11) NOT NULL DEFAULT 0,
  `price_usd` VARCHAR(32) DEFAULT '$0.00',
  `rating` DECIMAL(3,1) DEFAULT 5.0,
  `reviews_count` INT(11) DEFAULT 0,
  `image` VARCHAR(255) NOT NULL,
  `images_json` LONGTEXT DEFAULT NULL,
  `shades_json` LONGTEXT DEFAULT NULL,
  `categories_json` LONGTEXT DEFAULT NULL,
  `desc1` LONGTEXT DEFAULT NULL,
  `desc2` LONGTEXT DEFAULT NULL,
  `accordions_json` LONGTEXT DEFAULT NULL,
  `availability` ENUM('in_stock', 'low_stock', 'pre_order', 'out_of_stock') DEFAULT 'in_stock',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_product_slug` (`slug`),
  KEY `idx_product_availability` (`availability`),
  KEY `idx_product_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 2. TABLE: orders
-- Stores discreet orders, customer delivery info, line items, and tracking
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `orders` (
  `id` VARCHAR(64) NOT NULL,
  `order_number` INT(11) NOT NULL AUTO_INCREMENT,
  `customer_name` VARCHAR(191) NOT NULL,
  `customer_email` VARCHAR(191) NOT NULL,
  `customer_phone` VARCHAR(64) NOT NULL,
  `address` TEXT NOT NULL,
  `apartment` VARCHAR(128) DEFAULT NULL,
  `city` VARCHAR(100) NOT NULL,
  `state` VARCHAR(100) NOT NULL,
  `pincode` VARCHAR(32) NOT NULL,
  `country` VARCHAR(64) NOT NULL DEFAULT 'India',
  `stealth_instructions` TEXT DEFAULT NULL,
  `items_json` LONGTEXT NOT NULL,
  `subtotal` INT(11) NOT NULL DEFAULT 0,
  `discount` INT(11) NOT NULL DEFAULT 0,
  `discount_code` VARCHAR(64) DEFAULT NULL,
  `shipping_cost` INT(11) NOT NULL DEFAULT 0,
  `total_amount` INT(11) NOT NULL DEFAULT 0,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'INR',
  `payment_method` VARCHAR(32) NOT NULL DEFAULT 'Card',
  `payment_status` VARCHAR(64) NOT NULL DEFAULT 'Paid',
  `order_status` ENUM('processing', 'confirmed', 'shipped', 'delivered', 'cancelled') NOT NULL DEFAULT 'processing',
  `carrier` VARCHAR(128) NOT NULL DEFAULT 'BlueDart Discreet Express',
  `tracking_number` VARCHAR(128) DEFAULT NULL,
  `estimated_delivery` VARCHAR(64) DEFAULT '2-3 Business Days',
  `packaging_type` VARCHAR(128) DEFAULT '100% Plain Unmarked Eco-Kraft Box',
  `billing_descriptor` VARCHAR(128) DEFAULT 'PLZ-WLNS CO',
  `admin_notes` TEXT DEFAULT NULL,
  `origin_ip` VARCHAR(64) DEFAULT NULL,
  `origin_city` VARCHAR(100) DEFAULT NULL,
  `origin_region` VARCHAR(100) DEFAULT NULL,
  `origin_country` VARCHAR(64) DEFAULT 'India',
  `device` VARCHAR(128) DEFAULT NULL,
  `user_agent` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_order_number` (`order_number`),
  KEY `idx_customer_email` (`customer_email`),
  KEY `idx_customer_phone` (`customer_phone`),
  KEY `idx_order_status` (`order_status`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=1001 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 3. TABLE: customers
-- Aggregate customer profiles, lifetime spend, order count, and addresses
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `customers` (
  `id` VARCHAR(64) NOT NULL,
  `full_name` VARCHAR(191) NOT NULL,
  `email` VARCHAR(191) NOT NULL,
  `phone` VARCHAR(64) DEFAULT NULL,
  `city` VARCHAR(100) DEFAULT NULL,
  `state` VARCHAR(100) DEFAULT NULL,
  `country` VARCHAR(64) NOT NULL DEFAULT 'India',
  `total_orders` INT(11) NOT NULL DEFAULT 1,
  `total_spent` INT(11) NOT NULL DEFAULT 0,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'INR',
  `last_order_id` VARCHAR(64) DEFAULT NULL,
  `last_order_date` DATETIME DEFAULT NULL,
  `addresses_json` LONGTEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_customer_email_uniq` (`email`),
  KEY `idx_customer_phone_key` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 4. TABLE: otp_verifications
-- Passwordless authentication & verification codes with expiry
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `otp_verifications` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(191) NOT NULL,
  `name` VARCHAR(191) DEFAULT NULL,
  `otp_code` VARCHAR(10) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `is_verified` TINYINT(1) NOT NULL DEFAULT 0,
  `ip_address` VARCHAR(64) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_otp_lookup` (`email`, `otp_code`),
  KEY `idx_otp_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 5. TABLE: admin_settings
-- Key-value settings store for promo codes, notifications, and metadata
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_settings` (
  `setting_key` VARCHAR(64) NOT NULL,
  `setting_value` LONGTEXT NOT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default promo code & brand settings
INSERT INTO `admin_settings` (`setting_key`, `setting_value`) VALUES
('promo_codes', '[{"code":"PLEZZY10","discountPercent":10,"active":true},{"code":"VIP20","discountPercent":20,"active":true}]'),
('store_contact_email', 'support@plezzy.in'),
('discreet_carrier_default', 'BlueDart Discreet Express')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------------------------
-- SEED DATA: 10 Core Plezzy Product Catalog Editions
-- ------------------------------------------------------------------------------
INSERT INTO `products` (
  `id`, `slug`, `name`, `subtitle`, `category`, `badge`, `role`, `tagline`,
  `price_inr`, `original_inr`, `price_usd`, `rating`, `reviews_count`, `image`,
  `images_json`, `shades_json`, `categories_json`, `desc1`, `desc2`, `accordions_json`, `availability`
) VALUES (
  'plz-pebble', 'pocket-muse', 'Pocket Muse', 'Ultra-smooth palm-sculpted pebble with whisper acoustic dampening',
  'STANDARD', '30% OFF', 'palm acoustic resonance & velvety tactile wellness', 'An ultra-smooth, palm-sculpted tactile pebble with whisper acoustic dampening and water-resistant finish.',
  2499, 3499, '$30.00',
  4.9, 348, '/assets/products/pocket-pebble-main.jpg?v=20261104_bento_new_images_v31',
  '[{"src":"/assets/products/pocket-pebble-main.jpg?v=20261104_bento_new_images_v31","label":"Studio Portrait with Hibiscus"},{"src":"/assets/products/pocket-pebble-macro.jpg","label":"Sculptural Form & Specular Finish"},{"src":"/assets/products/pocket-pebble-detail.jpg","label":"Tactile Texture & Droplet Detail"}]', '[{"name":"sunburst yellow","color":"#F7C948","desc":"warm velvety saffron","badge":"signature"},{"name":"obsidian noir","color":"#1A1A1A","desc":"deep matte obsidian","badge":"core"}]', '["STANDARD","HIM","HER"]',
  'The pocket muse is an intentional sensory wellness object designed to rest naturally within the palm. Encased in velvety, hypoallergenic medical-grade silicone with water-resistant acoustic dampening, it delivers deeply calming acoustic frequencies for discreet daily wellness.', 'Engineered with magnetic rapid charging and an intuitive one-touch control interface. Crafted to elevate intimate sensory rituals with quiet confidence.', '[{"title":"Benefits & Sensory Ritual","content":"Ultra-quiet acoustic dampening (<28dB) delivers deep physical relaxation and sensory harmony. Waterproof construction allows for warm bath relaxation and effortless cleansing."},{"title":"Application & Care","content":"Press and hold power button for 2 seconds to awaken. Cycle through 8 calibrated harmonic wave patterns. Clean with warm water and mild soap after each use."},{"title":"Materials & Engineering Specs","content":"100% body-safe hypoallergenic liquid silicone unibody. IPX7 waterproof submersible seal. Magnetic USB rapid charging with 80+ minutes of continuous ritual time."},{"title":"Stealth Delivery & 1-Year Guarantee","content":"Dispatched in plain, unbranded protective packaging with no identifiable exterior markings. Backed by an unconditional 1-year replacement warranty."}]',
  'in_stock'
) ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `price_inr` = VALUES(`price_inr`),
  `original_inr` = VALUES(`original_inr`),
  `image` = VALUES(`image`),
  `availability` = VALUES(`availability`);

INSERT INTO `products` (
  `id`, `slug`, `name`, `subtitle`, `category`, `badge`, `role`, `tagline`,
  `price_inr`, `original_inr`, `price_usd`, `rating`, `reviews_count`, `image`,
  `images_json`, `shades_json`, `categories_json`, `desc1`, `desc2`, `accordions_json`, `availability`
) VALUES (
  'plz-dual-wand', 'cloud-kiss', 'Cloud Kiss', 'Frosted silicone body with floating celestial hearts & dual harmonic motors',
  'LUX', '30% OFF', 'dual-point harmonic resonance with floating celestial hearts', 'Translucent frosted silicone body infused with floating celestial hearts and dual harmonic motors.',
  3999, 4999, '$48.00',
  5, 412, '/assets/products/astral-wave-wand-main.jpg?v=20261104_bento_new_images_v31',
  '[{"src":"/assets/products/astral-wave-wand-main.jpg?v=20261104_bento_new_images_v31","label":"Translucent Dual Silhouette"},{"src":"/assets/products/astral-wave-wand-macro.jpg","label":"Frosted Silicone & Heart Particles"},{"src":"/assets/products/astral-wave-wand-detail.jpg","label":"Precision Wave Nodes & Contour"}]', '[{"name":"celestial frost","color":"#E0DBE8","desc":"frosted lavender with hearts","badge":"signature"},{"name":"amethyst mist","color":"#7B5E8C","desc":"deep violet shimmer","badge":"limited"}]', '["LUX","HER"]',
  'The cloud kiss represents the pinnacle of sculptural intimacy design. Molded from frosted translucent medical silicone and hand-infused with iridescent micro-hearts, it houses dual synchronized acoustic motors that generate deep resonant frequencies across both contact nodes.', 'Features independent multi-speed harmonic wave modes and ergonomic dual-end curvature tailored for both focused acupressure and expansive resonance.', '[{"title":"Benefits & Sensory Ritual","content":"Dual independent resonance motors deliver simultaneous dual-zone acoustic stimulation. Ergonomically balanced to adapt gracefully to body contours."},{"title":"Application & Care","content":"Intuitive dual-button interface enables individual or synchronized control of both resonance points. Wash thoroughly with mild cleanser and store dry."},{"title":"Materials & Engineering Specs","content":"Medical-grade frosted liquid silicone with suspended non-toxic micro-elements. Dual low-frequency core motors (<29dB). Magnetic hermetic fast charge. IPX7 100% waterproof."},{"title":"Stealth Delivery & 1-Year Guarantee","content":"Shipped in discreet matte black eco-conscious packaging with complete confidentiality. Comprehensive 1-year guarantee included."}]',
  'in_stock'
) ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `price_inr` = VALUES(`price_inr`),
  `original_inr` = VALUES(`original_inr`),
  `image` = VALUES(`image`),
  `availability` = VALUES(`availability`);

INSERT INTO `products` (
  `id`, `slug`, `name`, `subtitle`, `category`, `badge`, `role`, `tagline`,
  `price_inr`, `original_inr`, `price_usd`, `rating`, `reviews_count`, `image`,
  `images_json`, `shades_json`, `categories_json`, `desc1`, `desc2`, `accordions_json`, `availability`
) VALUES (
  'plz-elixir', 'banana-espresso', 'Banana Espresso', 'pH-balanced organic botanical intimacy serum for silky hydration',
  'LUBES', 'BEST SELLER', 'ultra-glide clean moisture & botanical nourishment', 'pH-balanced organic botanical intimacy serum formulated for silky, long-lasting hydration.',
  1299, 1699, '$16.00',
  4.9, 520, '/assets/products/nectar-elixir-main.jpg?v=20261104_bento_new_images_v31',
  '[{"src":"/assets/products/nectar-elixir-main.jpg?v=20261104_bento_new_images_v31","label":"Canister with Dripping Viscous Drop"},{"src":"/assets/products/nectar-elixir-macro.jpg","label":"Graphic Halftone Banana Label Artwork"},{"src":"/assets/products/nectar-elixir-detail.jpg","label":"Dispenser Nozzle & Viscosity Closeup"}]', '[{"name":"pure botanical","color":"#F0E130","desc":"canary yellow dispenser","badge":"essential"}]', '["STANDARD","HIM","HER","LUBES"]',
  'Banana Espresso is a water-soluble, clean botanical formulation that provides an effortless, frictionless glide with skin-soothing hydration. Packaged in a contemporary airtight pump dispenser featuring signature graphic artwork.', 'Formulated to match the body\'s natural chemistry with a balanced pH of 3.9–4.2. Safe with natural rubber latex and all silicone wellness objects.', '[{"title":"Benefits & Sensory Ritual","content":"Ultra-slippery, non-sticky texture that never gets tacky or leaves stains. Provides restorative moisture to delicate skin barriers."},{"title":"Application & Care","content":"Dispense 2–3 pumps into palms and warm gently before applying. Compatible with all silicone devices, condoms, and natural touch rituals."},{"title":"Materials & Ingredients","content":"Purified Aqua, Plant-Derived Hyaluronic Acid, Organic Aloe Barbadensis Leaf Juice, Vegetable Glycerin, Chamomile Extract, Citric Acid. 100% Vegan, cruelty-free, paraben-free."},{"title":"Stealth Delivery & 1-Year Guarantee","content":"Shipped with neutral return address and zero product descriptions on exterior packing. 100% satisfaction guarantee."}]',
  'in_stock'
) ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `price_inr` = VALUES(`price_inr`),
  `original_inr` = VALUES(`original_inr`),
  `image` = VALUES(`image`),
  `availability` = VALUES(`availability`);

INSERT INTO `products` (
  `id`, `slug`, `name`, `subtitle`, `category`, `badge`, `role`, `tagline`,
  `price_inr`, `original_inr`, `price_usd`, `rating`, `reviews_count`, `image`,
  `images_json`, `shades_json`, `categories_json`, `desc1`, `desc2`, `accordions_json`, `availability`
) VALUES (
  'plz-cherry-wand', 'rose-rush', 'Rose Rush', 'Sculptural flexible wand in frosted silicone with deep rumbly vibrations',
  'LUX', '30% OFF', 'targeted acupressure & whisper-quiet sonic depth', 'Sculptural flexible wand in frosted silicone with deep rumbly vibrations for pinpoint release.',
  3499, 4299, '$42.00',
  4.9, 289, '/assets/products/carmine-resonance-wand-main.jpg?v=20261104_bento_new_images_v31',
  '[{"src":"/assets/products/carmine-resonance-wand-main.jpg?v=20261104_bento_new_images_v31","label":"Sculptural Silhouette & Dripping Carmine Glaze"},{"src":"/assets/products/carmine-resonance-wand-macro.jpg","label":"Contoured Flexible Tip on Violet Velvet"},{"src":"/assets/products/carmine-resonance-wand-detail.jpg","label":"Ergonomic Neck & Glaze Texture Detail"}]', '[{"name":"carmine glaze","color":"#B82E3E","desc":"translucent cherry rose","badge":"limited"},{"name":"noir shadow","color":"#2B2B2B","desc":"smoky matte shadow","badge":"core"}]', '["LUX","HER"]',
  'The rose rush is an ergonomically sculpted resonance instrument engineered with a flexible contoured head to deliver focused, deep-tissue acoustic resonance exactly where required. Its frosted, velvet-soft silicone surface glides weightlessly.', 'Equipped with an ultra-quiet high-torque magnetic motor operating below 26dB, offering 10 discrete rhythmic frequencies ranging from gentle continuous murmurs to powerful pulse waves.', '[{"title":"Benefits & Sensory Ritual","content":"Flexible ergonomic head pivots seamlessly to adapt to personal contours. Low-frequency motor produces deep sensations without irritating high-pitched buzzes."},{"title":"Application & Care","content":"Long-press the concave touch key to activate. Tap rhythm key to cycle through frequency curves. Completely submersible for bath relaxation."},{"title":"Materials & Engineering Specs","content":"100% body-safe medical silicone unibody. IPX7 waterproof. High-torque acoustic motor (<26dB). Rapid magnetic charging with up to 2 hours of ritual time."},{"title":"Stealth Delivery & 1-Year Guarantee","content":"Packaged in discreet luxury matte casing. Backed by 1-year replacement warranty."}]',
  'in_stock'
) ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `price_inr` = VALUES(`price_inr`),
  `original_inr` = VALUES(`original_inr`),
  `image` = VALUES(`image`),
  `availability` = VALUES(`availability`);

INSERT INTO `products` (
  `id`, `slug`, `name`, `subtitle`, `category`, `badge`, `role`, `tagline`,
  `price_inr`, `original_inr`, `price_usd`, `rating`, `reviews_count`, `image`,
  `images_json`, `shades_json`, `categories_json`, `desc1`, `desc2`, `accordions_json`, `availability`
) VALUES (
  'plz-velvet-solstice', 'velvet-solstice', 'Velvet Solstice', 'Dual-ended wave sculpture engineered with whisper-quiet low-frequency resonance',
  'LUX', 'NEW LAUNCH', 'dual-ended wave sculpture & whisper-quiet low-frequency resonance', 'Dual-ended wave sculpture engineered with whisper-quiet low-frequency resonance motors.',
  3799, 4999, '$46.00',
  4.8, 194, '/assets/products/custom-1793272920071-velvet-solstice-wand-jpg.jpg',
  '[{"src":"/assets/products/custom-1793272920071-velvet-solstice-wand-jpg.jpg","label":"Front Sculpture"},{"src":"/assets/plezzy_blush_stack.jpg","label":"Studio Detail"}]', '[{"name":"amethyst aura","color":"#8B5CF6","desc":"deep velvety violet","badge":"signature"},{"name":"obsidian noir","color":"#1E1B24","desc":"smoky matte shadow","badge":"core"}]', '["LUX","HER"]',
  'Velvet Solstice is an architectural dual-ended intimacy sculpture engineered for immersive resonance. Cast in medical hypoallergenic liquid silicone, it features calibrated dual core motors with whisper acoustic dampening.', 'Features 10 harmonic frequency curves and hermetic magnetic charging.', '[{"title":"Benefits & Sensory Ritual","content":"Dual-ended acoustic resonance targets both broad contouring and pinpoint acupressure simultaneously."},{"title":"Application & Care","content":"Long press power button for 2 seconds. Clean with warm water and gentle foam cleanser."},{"title":"Materials & Engineering Specs","content":"100% body-safe medical silicone. IPX7 waterproof. Dual motors <28dB. Magnetic USB rapid charging."},{"title":"Stealth Delivery & 1-Year Guarantee","content":"Delivered in plain, unbranded protective packaging. Unconditional 1-year guarantee."}]',
  'in_stock'
) ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `price_inr` = VALUES(`price_inr`),
  `original_inr` = VALUES(`original_inr`),
  `image` = VALUES(`image`),
  `availability` = VALUES(`availability`);

INSERT INTO `products` (
  `id`, `slug`, `name`, `subtitle`, `category`, `badge`, `role`, `tagline`,
  `price_inr`, `original_inr`, `price_usd`, `rating`, `reviews_count`, `image`,
  `images_json`, `shades_json`, `categories_json`, `desc1`, `desc2`, `accordions_json`, `availability`
) VALUES (
  'plz-rose-petal', 'rose-petal-touch', 'Rose Petal Touch', 'Ergonomic ribbed contouring with 10 calibrated wave rhythms for external stimulation',
  'LUX', 'NEW LAUNCH', 'ribbed wave contouring & pinpoint acoustic release', 'Ergonomic ribbed contouring with 10 calibrated wave rhythms for gentle external stimulation.',
  2899, 3899, '$35.00',
  4.9, 165, '/assets/PLEZZY_Rose_Petal_Front_Controls_View.png',
  '[{"src":"/assets/PLEZZY_Rose_Petal_Front_Controls_View.png","label":"Front Controls View"},{"src":"/assets/products/pocket-pebble-macro.jpg","label":"Silicone Texture"}]', '[{"name":"blush petal","color":"#F472B6","desc":"soft rose petal","badge":"signature"}]', '["LUX","HER"]',
  'The Rose Petal Touch combines delicate ribbed wave ridges with deeply penetrating sonic resonance. Sculpted to cup natural body curves with zero pinch.', 'IPX7 waterproof and submersible for bath and shower sensory rituals.', '[{"title":"Benefits & Sensory Ritual","content":"Ribbed contact surfaces amplify tactile sensations while gentle acoustic motors preserve sensitivity."},{"title":"Application & Care","content":"Simple one-touch cycle through 10 calibrated rhythm curves. Store dry."},{"title":"Materials & Specs","content":"100% hypoallergenic silicone unibody. Magnetic rapid dock. Submersible seal."},{"title":"Stealth Delivery","content":"100% plain packaging with no external branding."}]',
  'in_stock'
) ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `price_inr` = VALUES(`price_inr`),
  `original_inr` = VALUES(`original_inr`),
  `image` = VALUES(`image`),
  `availability` = VALUES(`availability`);

INSERT INTO `products` (
  `id`, `slug`, `name`, `subtitle`, `category`, `badge`, `role`, `tagline`,
  `price_inr`, `original_inr`, `price_usd`, `rating`, `reviews_count`, `image`,
  `images_json`, `shades_json`, `categories_json`, `desc1`, `desc2`, `accordions_json`, `availability`
) VALUES (
  'plz-suede-pouch', 'suede-travel-pouch', 'Suede Travel Pouch', 'Ultra-soft antimicrobial vegan suede pouch with hidden zip for confidential travel',
  'STANDARD', 'ESSENTIAL', 'antimicrobial discreet protection & confidential storage', 'Ultra-soft antimicrobial vegan suede pouch with hidden zip for confidential travel.',
  899, 1299, '$12.00',
  5, 210, '/assets/photoshoot-pouch-discrete.jpg',
  '[{"src":"/assets/photoshoot-pouch-discrete.jpg","label":"Discrete Pouch Portrait"},{"src":"/assets/lifestyle-flatlay.jpg","label":"Lifestyle Flatlay"}]', '[{"name":"burgundy suede","color":"#4A0E17","desc":"deep wine velvet","badge":"signature"},{"name":"matte obsidian","color":"#1A1A1A","desc":"discreet black","badge":"core"}]', '["STANDARD","HIM","HER"]',
  'Crafted from double-brushed antimicrobial vegan suede to protect your Plezzy wellness instruments from dust, lint, and curious eyes.', 'Features an acoustic dampening inner lining and hidden silent-glide zipper.', '[{"title":"Protection & Care","content":"Dustproof, water-resistant exterior. Hand wash cold and line dry."},{"title":"Discreet Travel","content":"Looks like a luxury cosmetic or sunglasses case. Zero logos on outer faces."}]',
  'in_stock'
) ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `price_inr` = VALUES(`price_inr`),
  `original_inr` = VALUES(`original_inr`),
  `image` = VALUES(`image`),
  `availability` = VALUES(`availability`);

INSERT INTO `products` (
  `id`, `slug`, `name`, `subtitle`, `category`, `badge`, `role`, `tagline`,
  `price_inr`, `original_inr`, `price_usd`, `rating`, `reviews_count`, `image`,
  `images_json`, `shades_json`, `categories_json`, `desc1`, `desc2`, `accordions_json`, `availability`
) VALUES (
  'plz-ritual-set', 'the-sensory-ritual-set', 'The Sensory Ritual Set', 'Complete wellness ritual: Pocket Muse, Banana Espresso serum & travel pouch',
  'LUX', 'SAVE 35%', 'complete 3-piece introductory wellness suite', 'Complete wellness ritual including Pocket Muse, Banana Espresso serum & travel pouch.',
  3999, 5499, '$49.00',
  5, 142, '/assets/lifestyle-flatlay.jpg',
  '[{"src":"/assets/lifestyle-flatlay.jpg","label":"Complete Ritual Flatlay"},{"src":"/assets/photoshoot-pouch-discrete.jpg","label":"With Travel Pouch"}]', '[{"name":"curated ritual edition","color":"#F7C948","desc":"gold & saffron edition","badge":"curated"}]', '["LUX","HER","HIM","LUBES"]',
  'The complete Plezzy ritual experience bundled together in a keepsake gift box: our flagship Pocket Muse, full-size Banana Espresso organic elixir, and antimicrobial vegan suede pouch.', 'Enjoy a 35% savings compared to purchasing each item individually.', '[{"title":"Included in Ritual Set","content":"1x Pocket Muse pebble, 1x Banana Espresso botanical serum (100ml), 1x Suede Travel Pouch, 1x Magnetic Charging Cable."},{"title":"Stealth Gift Box","content":"Packed in a neutral luxury gift box with plain outer mailer."}]',
  'in_stock'
) ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `price_inr` = VALUES(`price_inr`),
  `original_inr` = VALUES(`original_inr`),
  `image` = VALUES(`image`),
  `availability` = VALUES(`availability`);

INSERT INTO `products` (
  `id`, `slug`, `name`, `subtitle`, `category`, `badge`, `role`, `tagline`,
  `price_inr`, `original_inr`, `price_usd`, `rating`, `reviews_count`, `image`,
  `images_json`, `shades_json`, `categories_json`, `desc1`, `desc2`, `accordions_json`, `availability`
) VALUES (
  'plz-lovers-suite', 'the-lovers-suite', 'The Lovers Suite', 'The ultimate couples gift box: Cloud Kiss, Rose Rush & botanical elixir',
  'LUX', 'LIMITED EDITION', 'luxury couples dual-resonance intimate box set', 'The ultimate couples gift box: Cloud Kiss, Rose Rush & botanical elixir in keepsake box.',
  6999, 9999, '$85.00',
  5, 88, '/assets/plezzy_blush_stack.jpg',
  '[{"src":"/assets/plezzy_blush_stack.jpg","label":"Luxury Gift Suite"},{"src":"/assets/products/astral-wave-wand-main.jpg","label":"Cloud Kiss"},{"src":"/assets/products/carmine-resonance-wand-main.jpg","label":"Rose Rush"}]', '[{"name":"midnight luxury suite","color":"#B82E3E","desc":"carmine & amethyst","badge":"limited"}]', '["LUX","HER","HIM","LUBES"]',
  'The premier couples collection featuring both signature wands: Cloud Kiss with translucent heart inclusions, Rose Rush with targeted flex-tip resonance, and full-size Banana Espresso elixir.', 'Presented in an embossed magnetic-closure keepsake box.', '[{"title":"Suite Contents","content":"1x Cloud Kiss dual wand, 1x Rose Rush precision wand, 1x Banana Espresso elixir (100ml), 2x Magnetic fast-chargers, 2x Travel pouches."}]',
  'in_stock'
) ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `price_inr` = VALUES(`price_inr`),
  `original_inr` = VALUES(`original_inr`),
  `image` = VALUES(`image`),
  `availability` = VALUES(`availability`);

INSERT INTO `products` (
  `id`, `slug`, `name`, `subtitle`, `category`, `badge`, `role`, `tagline`,
  `price_inr`, `original_inr`, `price_usd`, `rating`, `reviews_count`, `image`,
  `images_json`, `shades_json`, `categories_json`, `desc1`, `desc2`, `accordions_json`, `availability`
) VALUES (
  'plz-cards-guide', 'midnight-play-sensory-cards', 'Extremely Naughty Cards', 'Intimate conversation cards & sensory game prompts to elevate touch and connection',
  'STANDARD', 'NEW LAUNCH', 'kinky card game & intimate sensory play prompt deck', 'Intimate conversation cards & sensory game prompts to elevate touch and connection.',
  499, 799, '$7.00',
  4.9, 318, '/assets/plezzy-energy-journal-banner.png',
  '[{"src":"/assets/plezzy-energy-journal-banner.png","label":"Deck Box & Artwork"}]', '[{"name":"standard deck","color":"#E85A71","desc":"52 curated cards","badge":"core"}]', '["STANDARD","HIM","HER"]',
  '52 beautifully designed intimacy prompts, tactile challenges, and deep conversation starters designed to unlock fantasy, vulnerability, and playful passion between partners.', 'Features 3 progressive levels of play: Warm Touch, Deep Truth, and Midnight Play.', '[{"title":"Game Structure","content":"52 cards printed on linen-finish 350gsm cardstock with hot-stamped gold foil accents."},{"title":"Stealth Delivery","content":"Discreet packaging with neutral external shipping labels."}]',
  'in_stock'
) ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `price_inr` = VALUES(`price_inr`),
  `original_inr` = VALUES(`original_inr`),
  `image` = VALUES(`image`),
  `availability` = VALUES(`availability`);

