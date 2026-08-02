-- ============================================================
-- BCD Survey Platform — Core seed data
-- Roles, permissions, hierarchy codes, default state admin.
-- ============================================================

USE `bcd_survey`;

INSERT INTO `roles` (`code`, `name`, `description`, `is_system`) VALUES
('state_admin',     'State Admin',     'Full platform access at state level', 1),
('department_admin','Department Admin','Manages one department', 1),
('district',        'District',        'District-level verification & management', 1),
('block',           'Block',           'Block-level verification', 1),
('panchayat',       'Panchayat',       'Panchayat-level management', 1),
('village',         'Village',         'Village-level management', 1),
('surveyor',        'Surveyor',        'Field data collector via mobile app', 1);

INSERT INTO `permissions` (`code`, `name`, `module`, `guard`) VALUES
-- MIS portal
('dashboard.view',      'View Dashboard',         'mis', 'mis'),
('users.manage',        'Manage Users',           'mis', 'mis'),
('users.view',          'View Users',             'mis', 'mis'),
('roles.manage',        'Manage Roles',           'mis', 'mis'),
('survey_builder.view', 'View Survey Builder',    'mis', 'mis'),
('survey_builder.manage','Manage Survey Builder', 'mis', 'mis'),
('survey_builder.publish','Publish Surveys',      'mis', 'mis'),
('masters.manage',      'Manage Masters',         'mis', 'mis'),
('masters.view',        'View Masters',           'mis', 'mis'),
('monitoring.view',     'Survey Monitoring',      'mis', 'mis'),
('approval.verify',     'Verify Records',         'mis', 'mis'),
('approval.approve',    'Approve Records',        'mis', 'mis'),
('approval.publish',    'Publish Records',        'mis', 'mis'),
('gis.view',            'View GIS Dashboard',     'mis', 'gis'),
('reports.view',        'View Reports',           'mis', 'reports'),
('reports.export',      'Export Reports',         'mis', 'reports'),
('notifications.manage','Manage Notifications',   'mis', 'notifications'),
('settings.manage',     'Manage Settings',        'mis', 'settings'),
('audit.view',          'View Audit Logs',        'mis', 'audit'),
('replication.view',    'Replication Monitoring', 'mis', 'replication'),
-- Mobile
('mobile.login',        'Mobile Login',           'mobile', 'mobile'),
('mobile.sync',         'Mobile Sync',            'mobile', 'mobile'),
('mobile.photo',        'Photo Capture',          'mobile', 'mobile'),
('mobile.gps',          'GPS Capture',            'mobile', 'mobile'),
('mobile.offline',      'Offline Mode',           'mobile', 'mobile'),
('mobile.download',     'Download Masters/Forms', 'mobile', 'mobile');

-- state_admin gets everything
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id`
FROM `roles` r CROSS JOIN `permissions` p
WHERE r.`code` = 'state_admin';

-- surveyor gets mobile + monitoring read
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id`
FROM `roles` r JOIN `permissions` p ON p.`code` IN
  ('mobile.login','mobile.sync','mobile.photo','mobile.gps','mobile.offline','mobile.download','dashboard.view')
WHERE r.`code` = 'surveyor';

-- Hierarchy admins (district/block/panchayat/village) manage users within their scope
-- and view survey data (own + sub-users; scope-filtered server-side)
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id`
FROM `roles` r JOIN `permissions` p ON p.`code` IN
  ('dashboard.view','users.view','users.manage',
   'monitoring.view','reports.view','reports.export','gis.view')
WHERE r.`code` IN ('district','block','panchayat','village');

-- surveyor additionally reads survey data within their scope
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id`
FROM `roles` r JOIN `permissions` p ON p.`code` IN
  ('monitoring.view','reports.view','gis.view')
WHERE r.`code` = 'surveyor';

-- A default state (used by deployments within a state)
INSERT INTO `states` (`code`, `name`) VALUES
('ST', 'State Placeholder');

-- Default admin: username "admin", password "Admin@12345"
INSERT INTO `users`
  (`username`, `password_hash`, `plain_password`, `full_name`, `email`, `mobile`, `status`)
VALUES
  ('admin', '$2y$10$CmPU4TPejPX5v8WUgonxF.rqFtv96VU8A1AWym82fuKlLnChS3mPu', 'Admin@12345', 'State Administrator', 'admin@example.com', '9000000000', 'active');

INSERT INTO `user_roles` (`user_id`, `role_id`)
SELECT u.`id`, r.`id`
FROM `users` u CROSS JOIN `roles` r
WHERE u.`username` = 'admin' AND r.`code` = 'state_admin';
