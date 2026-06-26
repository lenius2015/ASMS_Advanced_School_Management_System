-- =====================================================================
-- ASMS Migration 009: Full Class Levels (Pre-Primary to Advanced)
-- Adds all class levels from Pre-Primary through Advanced Level (Form 6)
-- =====================================================================

USE asms_db;

-- First, update existing sort orders and add all missing levels
-- Strategy: INSERT IGNORE - will not duplicate existing level names

-- Pre-Primary Level
INSERT IGNORE INTO class_levels (level_name, sort_order) VALUES ('Baby Class', 1);
INSERT IGNORE INTO class_levels (level_name, sort_order) VALUES ('Nursery', 2);
INSERT IGNORE INTO class_levels (level_name, sort_order) VALUES ('KG1 / Pre-Unit', 3);
INSERT IGNORE INTO class_levels (level_name, sort_order) VALUES ('KG2', 4);

-- Primary Level
INSERT IGNORE INTO class_levels (level_name, sort_order) VALUES ('Standard 1', 5);
INSERT IGNORE INTO class_levels (level_name, sort_order) VALUES ('Standard 2', 6);
INSERT IGNORE INTO class_levels (level_name, sort_order) VALUES ('Standard 3', 7);
INSERT IGNORE INTO class_levels (level_name, sort_order) VALUES ('Standard 4', 8);
INSERT IGNORE INTO class_levels (level_name, sort_order) VALUES ('Standard 5', 9);
INSERT IGNORE INTO class_levels (level_name, sort_order) VALUES ('Standard 6', 10);
INSERT IGNORE INTO class_levels (level_name, sort_order) VALUES ('Standard 7', 11);

-- Ordinary Secondary Level
INSERT IGNORE INTO class_levels (level_name, sort_order) VALUES ('Form 1', 12);
INSERT IGNORE INTO class_levels (level_name, sort_order) VALUES ('Form 2', 13);
INSERT IGNORE INTO class_levels (level_name, sort_order) VALUES ('Form 3', 14);
INSERT IGNORE INTO class_levels (level_name, sort_order) VALUES ('Form 4', 15);

-- Advanced Secondary Level
INSERT IGNORE INTO class_levels (level_name, sort_order) VALUES ('Form 5', 16);
INSERT IGNORE INTO class_levels (level_name, sort_order) VALUES ('Form 6', 17);

-- Fix sort_order for any existing entries (in case they were already seeded with wrong order)
UPDATE class_levels SET sort_order = 1  WHERE level_name = 'Baby Class' AND sort_order != 1;
UPDATE class_levels SET sort_order = 2  WHERE level_name = 'Nursery' AND sort_order != 2;
UPDATE class_levels SET sort_order = 3  WHERE level_name = 'KG1 / Pre-Unit' AND sort_order != 3;
UPDATE class_levels SET sort_order = 4  WHERE level_name = 'KG2' AND sort_order != 4;
UPDATE class_levels SET sort_order = 5  WHERE level_name = 'Standard 1' AND sort_order != 5;
UPDATE class_levels SET sort_order = 6  WHERE level_name = 'Standard 2' AND sort_order != 6;
UPDATE class_levels SET sort_order = 7  WHERE level_name = 'Standard 3' AND sort_order != 7;
UPDATE class_levels SET sort_order = 8  WHERE level_name = 'Standard 4' AND sort_order != 8;
UPDATE class_levels SET sort_order = 9  WHERE level_name = 'Standard 5' AND sort_order != 9;
UPDATE class_levels SET sort_order = 10 WHERE level_name = 'Standard 6' AND sort_order != 10;
UPDATE class_levels SET sort_order = 11 WHERE level_name = 'Standard 7' AND sort_order != 11;
UPDATE class_levels SET sort_order = 12 WHERE level_name = 'Form 1' AND sort_order != 12;
UPDATE class_levels SET sort_order = 13 WHERE level_name = 'Form 2' AND sort_order != 13;
UPDATE class_levels SET sort_order = 14 WHERE level_name = 'Form 3' AND sort_order != 14;
UPDATE class_levels SET sort_order = 15 WHERE level_name = 'Form 4' AND sort_order != 15;
UPDATE class_levels SET sort_order = 16 WHERE level_name = 'Form 5' AND sort_order != 16;
UPDATE class_levels SET sort_order = 17 WHERE level_name = 'Form 6' AND sort_order != 17;