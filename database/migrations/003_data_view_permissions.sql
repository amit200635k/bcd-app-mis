-- ============================================================
-- 003 — Data-view permissions for hierarchy portal users
-- Portal users (district → block → panchayat → village → surveyor)
-- can view survey data; the data itself is scope-filtered
-- server-side (own + sub-users) by RecordService/ReportService/GIS.
-- ============================================================

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id`
FROM `roles` r JOIN `permissions` p ON p.`code` IN
  ('monitoring.view','reports.view','reports.export','gis.view')
WHERE r.`code` IN ('district','block','panchayat','village');

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id`
FROM `roles` r JOIN `permissions` p ON p.`code` IN
  ('monitoring.view','reports.view','gis.view')
WHERE r.`code` = 'surveyor';
