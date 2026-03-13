-- 【僅供系統「第一次」建立超級管理員使用】
-- 正常流程：主任在登入頁點「主任申請」→ 填寫送出 → 由超級管理員在系統內「主任審核」通過。
-- 不得用此 script  bypass 審核流程；僅在系統尚無任何 super_admin 時，手動建立第一位。

-- 將指定帳號設為超級管理員（請替換為實際登入 Email）
UPDATE "User" SET type = 'S' WHERE "LoginName" = 'jerry200176@gmail.com';
