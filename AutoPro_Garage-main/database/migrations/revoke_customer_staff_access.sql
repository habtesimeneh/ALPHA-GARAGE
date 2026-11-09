-- Customer accounts have no customer-scoped vehicle or appointment views.
-- Revoke the unscoped staff permissions until a properly scoped portal exists.
DELETE rp
FROM role_permissions AS rp
JOIN roles AS r ON r.id = rp.role_id
JOIN permissions AS p ON p.id = rp.permission_id
WHERE r.name = 'customer'
  AND p.code IN ('dashboard.view', 'vehicles.view', 'appointments.view', 'appointments.manage');

UPDATE roles
SET description = 'Public website account without staff dashboard access'
WHERE name = 'customer';
