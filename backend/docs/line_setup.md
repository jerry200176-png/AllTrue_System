# LINE Developers Console Setup Guide

> Historical backend setup note. For the current AllTrue launch checklist and environment-variable source of truth, use `docs/LINE_LIFF_CHECKLIST.md`. Some sample domains in this file are generic or old examples and should not override production runbooks.

This guide covers how to configure a LINE Messaging API channel and LIFF app for the parent portal.

---

## Prerequisites

- A LINE account (personal or business)
- An existing LINE Official Account (or create one at https://www.linebiz.com/)
- Your backend is deployed and accessible at a public HTTPS URL (e.g. `https://cram.teacher-check.com`)

---

## Part 1: Messaging API Channel

### Step 1 — Create a Provider and Channel

1. Go to [LINE Developers Console](https://developers.line.biz/)
2. Log in with your LINE account
3. Click **Create a new provider** (or select an existing one)
4. Click **Create a new channel** → choose **Messaging API**
5. Fill in:
   - **Channel name**: e.g. "全真一對一補習班"
   - **Channel description**: 家長堂數查詢與繳費通知
   - **Category / Subcategory**: Education
6. Click **Create**

### Step 2 — Get Channel Credentials

In the channel settings:

1. Go to the **Basic settings** tab
   - Copy the **Channel secret** → paste into `.env` as `LINE_CHANNEL_SECRET`

2. Go to the **Messaging API** tab
   - Under **Channel access token**, click **Issue** to generate a long-lived token
   - Copy the token → paste into `.env` as `LINE_CHANNEL_ACCESS_TOKEN`

### Step 3 — Set the Webhook URL

1. Still on the **Messaging API** tab, find **Webhook settings**
2. Set the Webhook URL to:
   ```
   https://cram.teacher-check.com/api/v1/line/webhook
   ```
3. Click **Verify** — you should see "Success"
4. Toggle **Use webhook** to **ON**
5. (Optional) Disable **Auto-reply messages** and **Greeting messages** to prevent LINE's default replies interfering with the bot

### Step 4 — Test the Webhook

Send a message to the LINE Official Account. The bot should respond:
- First-time users: prompted to bind their student account (`綁定 學生代號 手機號碼`)
- Already-bound users: receive a link to the parent portal

---

## Part 2: LIFF App

LIFF (LINE Front-end Framework) allows the parent portal web page to open inside the LINE app and auto-authenticate via LINE profile.

### Step 1 — Create a LIFF App

1. In the [LINE Developers Console](https://developers.line.biz/), navigate to your Messaging API channel
2. Click the **LIFF** tab
3. Click **Add** to create a new LIFF app
4. Fill in:
   - **LIFF app name**: 家長入口
   - **Size**: Full (recommended for mobile-first experience)
   - **Endpoint URL**: `https://cram.teacher-check.com/#/parent`
     *(This is your parent portal standalone URL)*
   - **Scope**: `profile` (required to get the LINE user ID for auto-login)
   - **Bot link feature**: On (Aggressive) — so the Official Account is linked when LIFF opens
5. Click **Add**

### Step 2 — Copy the LIFF ID

After creating the LIFF app:

1. You will see the **LIFF ID** in the format `1234567890-xxxxxxxx`
2. Copy it → paste into `.env` as `LINE_LIFF_ID`
3. Also paste it into `frontend/.env` (or `frontend/.env.production`) as `VITE_LIFF_ID=<your-liff-id>`
4. Rebuild and redeploy the frontend: `cd frontend && npm run deploy`

---

## Part 3: Rich Menu (Optional but Recommended)

A Rich Menu is a persistent button panel shown at the bottom of the LINE chat. It lets parents tap a button to open the LIFF parent portal directly.

### Step 1 — Create a Rich Menu

Using the [LINE Official Account Manager](https://manager.line.biz/):

1. Log in → select your Official Account
2. Go to **Chat screen** → **Rich menu** → **Create rich menu**
3. Set:
   - **Title**: 家長入口選單
   - **Display period**: Always display
4. Upload a background image (e.g. a button that says "查詢堂數 / 繳費通知")
5. Set the tap action for the button area:
   - **Action type**: Open link
   - **URL**: `https://liff.line.me/<your-liff-id>`
6. Save and publish

---

## Part 4: Environment Variables Summary

Add these to `/home/admin/backend/.env`:

```env
LINE_CHANNEL_ACCESS_TOKEN=<long-lived-channel-access-token>
LINE_CHANNEL_SECRET=<channel-secret>
LINE_LIFF_ID=<liff-id-e.g.-1234567890-xxxxxxxx>
```

Add these to `/home/admin/frontend/.env` (or `.env.production`):

```env
VITE_LIFF_ID=<same-liff-id>
```

---

## Part 5: Parent Binding Flow

1. Parent adds the LINE Official Account
2. Bot sends a welcome message asking the parent to bind: `綁定 學生代號 手機號碼`
3. Parent sends: e.g. `綁定 12345 0912345678`
4. Bot verifies the student ID and phone against the database
5. On success: `Student.LineID` is updated, parent receives a portal link
6. Next time the parent opens the LIFF URL, they are auto-authenticated via LINE user ID

## Part 6: Standalone Parent Portal URL

Parents can also access the portal via a direct browser link (without LINE):

```
https://cram.teacher-check.com/#/parent
https://cram.teacher-check.com/?parent=1
```

These URLs bypass the director login and show the parent portal directly.
