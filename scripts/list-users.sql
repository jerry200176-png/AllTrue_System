-- List all users so you can see the correct LoginName to use for promote-super-admin.sql
SELECT id, "LoginName", "Name", type FROM "User" ORDER BY id;
