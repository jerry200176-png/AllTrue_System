#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
GetRFID.py — 樹莓派 + RC522（SPI），以 IRQ 等待感應後讀取 UID，呼叫 AllTrue swipe-rfid API，
並依回傳的 student.TelegramID / TelegramID1 / TelegramID2 發送 Telegram 訊息。

硬體與函式庫參考：pi-rc522（Raspberry Pi SPI RFID RC522）
https://github.com/ondryaso/pi-rc522
需 pi-rc522>=2.3.0（RFID 建構子含 speed、antenna_gain）：pip install -r requirements-rfid.txt

接線（BOARD 腳位編號，與專案 README 一致）：
  SDA→24, SCK→23, MOSI→19, MISO→21, IRQ→18, GND→GND, RST→22, 3.3V→3.3V

環境變數（建議用 systemd EnvironmentFile 或 export）：
  SWIPE_RFID_URL   預設 https://YOUR_HOST/api/v1/swipe-rfid
  BRANCH_CODE      預設 daan（大安分校 Campus.code）
  CAMPUS_TOKEN     必填：資料庫 Campus.Token（Authorization Bearer）

範例：
  export SWIPE_RFID_URL="https://daan.lifenet.com.tw/api/v1/swipe-rfid"
  export BRANCH_CODE="daan"
  export CAMPUS_TOKEN="<大安分校的 Token>"

預設為輪詢（不依賴 IRQ，較不受 IRQ 誤觸發影響）。若 IRQ 已正確接線且想改用中斷等待：
  export RFID_USE_IRQ=1
  export RFID_POLL_INTERVAL_SEC=0.05   # 僅輪詢模式有效

  python3 GetRFID.py

部分卡片可試 WUPA（REQALL）：
  export RFID_REQUEST_MODE=all

天線較弱時可試（0–7，pi-rc522 預設 4）：
  export RC522_ANTENNA_GAIN=6

接線較長或通訊不穩可降 SPI 時脈（Hz，預設 1000000）：
  export RC522_SPI_SPEED_HZ=500000
"""

from __future__ import annotations

import json
import os
import sys
import time
from typing import Any, Optional

import requests

try:
    from pirc522 import RFID
except ImportError:
    print("請安裝 pi-rc522>=2.3.0：python3 -m pip install -U 'pi-rc522>=2.3.0'", file=sys.stderr)
    sys.exit(1)

# ── 設定（可改環境變數）────────────────────────────────────────────
# 可填完整 URL 或僅基底網址（自動補 /api/v1/swipe-rfid）
_RAW_API = os.environ.get("SWIPE_RFID_URL", "https://daan.lifenet.com.tw").strip().rstrip("/")
if "/api/v1/swipe-rfid" in _RAW_API or _RAW_API.endswith("swipe-rfid"):
    SWIPE_RFID_URL = _RAW_API
else:
    SWIPE_RFID_URL = _RAW_API + "/api/v1/swipe-rfid"

BRANCH_CODE = os.environ.get("BRANCH_CODE", "daan")
CAMPUS_TOKEN = os.environ.get("CAMPUS_TOKEN", "").strip()

# IRQ：BOARD 物理腳位 18（對應 RC522 的 IRQ，見 pi-rc522 README）
PIN_IRQ_BOARD = int(os.environ.get("RC522_PIN_IRQ", "18"))
# RST：預設 BOARD 22；若改線請設 RC522_PIN_RST
PIN_RST_BOARD = int(os.environ.get("RC522_PIN_RST", "22"))

# 防連抖：同一張卡兩次成功上傳的最短間隔（秒）
DEBOUNCE_SEC = float(os.environ.get("RFID_DEBOUNCE_SEC", "2.0"))

# SPI：預設 bus=0, device=0（CE0）；若接 CE1 則 device=1
SPI_BUS = int(os.environ.get("RC522_SPI_BUS", "0"))
SPI_DEVICE = int(os.environ.get("RC522_SPI_DEVICE", "0"))
SPI_SPEED_HZ = max(10_000, int(os.environ.get("RC522_SPI_SPEED_HZ", "1000000")))

# 寫入 NDJSON 與終端機標記，用於確認部署的腳本為最新版（非舊日誌）
GETRFID_SCRIPT_TAG = "GetRFID-2026-04-02-pirc522-2.3"

# 預設輪詢：實測 IRQ 模式若誤觸發會造成極短迴圈 + request 全失敗（見 debug NDJSON）。
# 改 IRQ 等待：RFID_USE_IRQ=1
_RFID_USE_IRQ_RAW = (os.environ.get("RFID_USE_IRQ", "0") or "0").strip().lower()
RFID_USE_IRQ = _RFID_USE_IRQ_RAW in ("1", "true", "yes", "on")
RFID_POLL_INTERVAL_SEC = float(os.environ.get("RFID_POLL_INTERVAL_SEC", "0.05"))

# request() 第二參數：0x26 REQIDL（預設）、0x52 WUPA/REQALL
_RFID_REQMODE = (os.environ.get("RFID_REQUEST_MODE", "idl") or "idl").strip().lower()
RFID_REQUEST_REQ_MODE = 0x52 if _RFID_REQMODE in ("all", "reqall", "wupa", "52") else 0x26

# MFRC522 VersionReg（SPI 正常時常為 0x88/0x90/0x91/0x92，視晶片/相容模組而定）
MFRC522_REG_VERSION = 0x37

# pi-rc522 天線增益 0–7；None 表示使用函式庫預設
_RC522_GAIN_RAW = (os.environ.get("RC522_ANTENNA_GAIN", "") or "").strip()
RC522_ANTENNA_GAIN: Optional[int] = None
if _RC522_GAIN_RAW:
    RC522_ANTENNA_GAIN = max(0, min(7, int(_RC522_GAIN_RAW)))

TELEGRAM_API = "https://api.telegram.org/bot{token}/sendMessage"

# #region agent log
_RFID_LOG_FALLBACK_WARNED = False
_RFID_LOG_PATH_USED_PRINTED = False
_RFID_LOG_PATH_USED = None


def _rfid_debug_log(message: str, data: dict, hypothesis_id: str) -> None:
    """NDJSON 除錯（不含 Token）。路徑：DEBUG_LOG_PATH → 腳本目錄 → /tmp；全失敗則印 stderr 一行方便複製。"""
    global _RFID_LOG_FALLBACK_WARNED, _RFID_LOG_PATH_USED_PRINTED, _RFID_LOG_PATH_USED
    line = json.dumps(
        {
            "sessionId": "afe6bc",
            "timestamp": int(time.time() * 1000),
            "location": "GetRFID.py",
            "message": message,
            "data": data,
            "hypothesisId": hypothesis_id,
        },
        ensure_ascii=False,
    )
    script_dir = os.path.dirname(os.path.abspath(__file__))
    paths: list[str] = []
    env_p = (os.environ.get("DEBUG_LOG_PATH") or "").strip()
    if env_p:
        paths.append(env_p)
    paths.append(os.path.join(script_dir, "debug-afe6bc.log"))
    paths.append("/tmp/debug-afe6bc.log")
    last_err: Optional[str] = None
    for path in paths:
        try:
            with open(path, "a", encoding="utf-8") as fp:
                fp.write(line + "\n")
            if not _RFID_LOG_PATH_USED_PRINTED:
                _RFID_LOG_PATH_USED_PRINTED = True
                _RFID_LOG_PATH_USED = path
                print(f"[RFID_DEBUG] log_path_used={_RFID_LOG_PATH_USED}", file=sys.stderr, flush=True)
            return
        except OSError as e:
            last_err = str(e)
            continue
    if not _RFID_LOG_FALLBACK_WARNED:
        _RFID_LOG_FALLBACK_WARNED = True
        print(
            f"[RFID_DEBUG] log_write_failed last_err={last_err!r} tried_paths={paths!r}",
            file=sys.stderr,
            flush=True,
        )
    print(f"[RFID_DEBUG_NDJSON]{line}", file=sys.stderr, flush=True)


# #endregion


def uid_bytes_to_hex(uid: list) -> str:
    """將 anticoll() 回傳轉成十六進位字串（大寫、無分隔符）。
    anticoll 成功時通常為 5 bytes：4 bytes UID + 1 byte XOR 校驗，只取前 4 bytes。
    """
    if not uid:
        return ""
    raw = [int(x) & 0xFF for x in uid]
    if len(raw) >= 5:
        raw = raw[:4]
    return "".join(f"{b:02X}" for b in raw)


def post_swipe(branch_code: str, rfid_hex: str, bearer: str) -> tuple[int, dict[str, Any]]:
    headers = {
        "Content-Type": "application/json",
        "Authorization": f"Bearer {bearer}",
    }
    body = {"branch_code": branch_code, "rfid": rfid_hex}
    r = requests.post(SWIPE_RFID_URL, headers=headers, json=body, timeout=30)
    ct = (r.headers.get("Content-Type") or "").lower()
    preview = (r.text or "").lstrip()[:200]
    if r.status_code == 404 and (
        "text/html" in ct
        or preview.upper().startswith("<!DOCTYPE")
        or preview.lower().startswith("<html")
    ):
        return r.status_code, {
            "ok": False,
            "error": "html_response",
            "message": (
                "伺服器回傳 HTML 404，請求未進入 Laravel。請確認此網域的 Apache DocumentRoot 指向 "
                "backend/public，且 SWIPE_RFID_URL 為正確主機（例如已部署 API 的分校網域）。"
            ),
        }
    try:
        data = r.json()
    except Exception:
        data = {"ok": False, "error": "invalid_json", "message": "回應不是 JSON", "_raw": r.text[:500]}
    return r.status_code, data


def send_telegram(bot_token: str, chat_id: Any, text: str) -> bool:
    if not bot_token or chat_id is None:
        return False
    s = str(chat_id).strip()
    if not s:
        return False
    url = TELEGRAM_API.format(token=bot_token)
    try:
        resp = requests.post(
            url,
            json={"chat_id": s, "text": text},
            timeout=15,
        )
        return resp.ok
    except requests.RequestException:
        return False


def notify_student_telegram(payload: dict[str, Any], rfid_hex: str) -> None:
    """依 API 回傳的 student Telegram 欄位與 campus.TelegramToken 發送訊息。"""
    if payload.get("type") != "student" or not payload.get("ok"):
        return
    campus = payload.get("campus") or {}
    bot_token = campus.get("TelegramToken") or ""
    if not bot_token:
        print("[Telegram] 回應無 campus.TelegramToken，略過發送")
        return
    student = payload.get("student") or {}
    name = student.get("name") or ""
    action = payload.get("action") or ""
    action_label = "到班" if action == "sign_in" else "離班" if action == "sign_out" else action
    text = f"[{BRANCH_CODE}] {name or '學生'} {action_label}\nRFID: {rfid_hex}"
    for key in ("TelegramID", "TelegramID1", "TelegramID2"):
        cid = student.get(key)
        if send_telegram(bot_token, cid, text):
            print(f"[Telegram] 已傳送至 {key}={cid}")
        elif cid:
            print(f"[Telegram] 傳送失敗 {key}={cid}")


def main() -> None:
    if not CAMPUS_TOKEN:
        print(
            "請設定環境變數 CAMPUS_TOKEN（大安分校範例：資料庫 Campus 表中該分校的 Token）",
            file=sys.stderr,
        )
        sys.exit(1)

    _bc = BRANCH_CODE.strip()
    if _bc == CAMPUS_TOKEN:
        print(
            "錯誤：BRANCH_CODE 與 CAMPUS_TOKEN 相同。API 要求："
            "JSON 的 branch_code 為分校代碼（如 daan），Bearer 才放 Campus.Token。",
            file=sys.stderr,
        )
        sys.exit(1)
    if len(_bc) >= 48:
        print(
            "錯誤：BRANCH_CODE 過長，疑似把 Token 設在 BRANCH_CODE。"
            "請設 BRANCH_CODE=分校 code（短字串），CAMPUS_TOKEN=資料庫 Token。",
            file=sys.stderr,
        )
        sys.exit(1)

    print(f"API: {SWIPE_RFID_URL}")
    pin_irq_effective: Optional[int] = PIN_IRQ_BOARD if RFID_USE_IRQ else None
    mode_line = f"分校: {BRANCH_CODE} | RST BOARD: {PIN_RST_BOARD} | SPI {SPI_BUS},{SPI_DEVICE}"
    if RFID_USE_IRQ:
        print(
            f"{mode_line} | IRQ BOARD: {PIN_IRQ_BOARD} | 模式: IRQ 等待 | REQ 0x{RFID_REQUEST_REQ_MODE:02X} | SPI {SPI_SPEED_HZ}Hz",
        )
    else:
        print(
            f"{mode_line} | 模式: 輪詢（無 IRQ）間隔 {RFID_POLL_INTERVAL_SEC}s | REQ 0x{RFID_REQUEST_REQ_MODE:02X} | SPI {SPI_SPEED_HZ}Hz",
        )

    # pirc522：pin_irq=None 時不註冊 IRQ，可改由下方迴圈輪詢 request()
    try:
        import RPi.GPIO as GPIO  # noqa: F401 — pi-rc522 依賴；若缺代表非樹莓派環境
    except ImportError:
        print("請在樹莓派上安裝 RPi.GPIO：pip install RPi.GPIO", file=sys.stderr)
        sys.exit(1)

    # pi-rc522>=2.3：建構子支援 speed、antenna_gain（見 requirements-rfid.txt）
    rdr = RFID(
        bus=SPI_BUS,
        device=SPI_DEVICE,
        speed=SPI_SPEED_HZ,
        pin_rst=PIN_RST_BOARD,
        pin_irq=pin_irq_effective,
        pin_mode=GPIO.BOARD,
        antenna_gain=RC522_ANTENNA_GAIN,
    )
    # #region agent log
    try:
        _mfrc522_ver = int(rdr.dev_read(MFRC522_REG_VERSION)) & 0xFF
    except Exception:
        _mfrc522_ver = None

    # #region agent log
    # 在真正進入 request()/anticoll() 之前，補讀一些關鍵暫存器確認 Tx/RF 設定狀態
    post_regs: dict[str, Any] = {}
    try:
        for reg_name, reg_addr in (
            ("RFCfgReg_0x13", 0x13),
            ("TxControlReg_0x14", 0x14),
            ("ModeReg_0x11", 0x11),
            ("CommandReg_0x01", 0x01),
        ):
            post_regs[reg_name] = int(rdr.dev_read(reg_addr)) & 0xFF
    except Exception:
        post_regs = {"_read_failed": True}
    _rfid_debug_log("post_init_regs", post_regs, "H3")
    # #endregion

    # #region agent log
    # 強制確保天線 Tx 已啟用：若 TxControlReg bit0/bit1 未開，request() 可能一直 timeout（Error E2）。
    antenna_before = None
    antenna_after = None
    try:
        antenna_before = int(rdr.dev_read(0x14)) & 0xFF
    except Exception:
        antenna_before = None

    did_set_antenna = False
    try:
        if hasattr(rdr, "set_antenna"):
            rdr.set_antenna(True)
            did_set_antenna = True
    except Exception:
        pass

    # antenna_gain 可能在 init 後被覆蓋/未落地：再補一次設定
    if RC522_ANTENNA_GAIN is not None and hasattr(rdr, "set_antenna_gain"):
        try:
            rdr.set_antenna_gain(RC522_ANTENNA_GAIN)
        except Exception:
            pass

    try:
        antenna_after = int(rdr.dev_read(0x14)) & 0xFF
    except Exception:
        antenna_after = None

    _rfid_debug_log(
        "antenna_force",
        {
            "antenna_before_txcontrol_0x14": antenna_before,
            "antenna_before_txcontrol_lsbs": (antenna_before & 0x03) if antenna_before is not None else None,
            "did_set_antenna": did_set_antenna,
            "antenna_gain": RC522_ANTENNA_GAIN,
            "antenna_after_txcontrol_0x14": antenna_after,
            "antenna_after_txcontrol_lsbs": (antenna_after & 0x03) if antenna_after is not None else None,
        },
        "H-ANT",
    )
    # #endregion

    # #region agent log
    # 再用最小「硬編碼」方式強制開啟 Tx bits（0x14 的 bit0/bit1）
    txcontrol_force_before = None
    txcontrol_force_after = None
    try:
        txcontrol_force_before = int(rdr.dev_read(0x14)) & 0xFF
        rdr.dev_write(0x14, txcontrol_force_before | 0x03)
        txcontrol_force_after = int(rdr.dev_read(0x14)) & 0xFF
    except Exception:
        pass
    _rfid_debug_log(
        "txcontrol_force",
        {
            "txcontrol_before_0x14": txcontrol_force_before,
            "txcontrol_before_lsbs": (txcontrol_force_before & 0x03) if txcontrol_force_before is not None else None,
            "txcontrol_after_0x14": txcontrol_force_after,
            "txcontrol_after_lsbs": (txcontrol_force_after & 0x03) if txcontrol_force_after is not None else None,
        },
        "H-ANT",
    )
    # #endregion

    # #region agent log
    # 若仍無法啟用 Tx bits（lsbs=0），嘗試 re-init 一次（reset + set_antenna）再驗證。
    tx_lsbs_now = None
    try:
        tx_lsbs_now = (int(rdr.dev_read(0x14)) & 0x03) if rdr is not None else None
    except Exception:
        tx_lsbs_now = None

    reinit_used = False
    post_reinit_txcontrol = None
    post_reinit_tx_lsbs = None
    if tx_lsbs_now == 0:
        try:
            if hasattr(rdr, "init"):
                rdr.init()
                reinit_used = True
                post_reinit_txcontrol = int(rdr.dev_read(0x14)) & 0xFF
                post_reinit_tx_lsbs = post_reinit_txcontrol & 0x03
        except Exception:
            pass

    _rfid_debug_log(
        "reinit_after_txcontrol_force",
        {
            "tx_lsbs_now": tx_lsbs_now,
            "reinit_used": reinit_used,
            "post_reinit_txcontrol_0x14": post_reinit_txcontrol,
            "post_reinit_tx_lsbs": post_reinit_tx_lsbs,
        },
        "H-ANT",
    )
    # #endregion
    _ver_disp = f"0x{_mfrc522_ver:02X}" if _mfrc522_ver is not None else "讀取失敗"
    print(
        f"[RFID] 建置 {GETRFID_SCRIPT_TAG} | VersionReg(0x37)={_ver_disp} | use_irq={RFID_USE_IRQ} | spi={SPI_BUS},{SPI_DEVICE}@{SPI_SPEED_HZ}Hz",
        flush=True,
    )
    if _mfrc522_ver is None or _mfrc522_ver in (0x00, 0xFF):
        print(
            "[RFID] 警告：Version 暫存器異常，請檢查 SPI 接線、3.3V、RST、CE0/CE1（RC522_SPI_DEVICE）、"
            "以及是否啟用 SPI（raspi-config）。可試 RC522_SPI_DEVICE=1 或 RC522_SPI_SPEED_HZ=500000。",
            file=sys.stderr,
            flush=True,
        )
    _rfid_debug_log(
        "rfid_init",
        {
            "script_tag": GETRFID_SCRIPT_TAG,
            "spi_bus": SPI_BUS,
            "spi_device": SPI_DEVICE,
            "spi_speed_hz": SPI_SPEED_HZ,
            "pirc522_api": "RFID(bus,device,speed,pin_rst,pin_irq,pin_mode,antenna_gain)>=2.3",
            "pin_irq": pin_irq_effective,
            "pin_rst": PIN_RST_BOARD,
            "use_irq": RFID_USE_IRQ,
            "poll_interval_sec": RFID_POLL_INTERVAL_SEC,
            "antenna_gain": RC522_ANTENNA_GAIN,
            "request_req_mode": RFID_REQUEST_REQ_MODE,
            "mfrc522_version_0x37": _mfrc522_ver,
        },
        "H4",
    )
    _rfid_debug_log(
        "mfrc522_version",
        {"reg_0x37": _mfrc522_ver},
        "H-SPI",
    )
    # #endregion

    last_uid_hex: Optional[str] = None
    last_ok_time = 0.0
    poll_fail_n = 0

    try:
        while True:
            if RFID_USE_IRQ:
                # wait_for_tag() 依 IRQ 腳 FALLING；IRQ 未接或接錯會永遠等不到或反覆失敗
                t0 = time.time()
                rdr.wait_for_tag()
                wait_ms = int((time.time() - t0) * 1000)
                # #region agent log
                _rfid_debug_log("after_wait_for_tag", {"wait_for_tag_ms": wait_ms}, "H3")
                # #endregion
                # #region agent log
                # IRQ 模式下 wait_for_tag() 內部會呼叫 init()，可能改寫 Tx/RF 設定；記錄 request() 前的狀態
                try:
                    ver_now = int(rdr.dev_read(MFRC522_REG_VERSION)) & 0xFF
                except Exception:
                    ver_now = None
                try:
                    tx_ctrl_0x14 = int(rdr.dev_read(0x14)) & 0xFF
                except Exception:
                    tx_ctrl_0x14 = None
                try:
                    rf_cfg_0x13 = int(rdr.dev_read(0x13)) & 0xFF
                except Exception:
                    rf_cfg_0x13 = None
                _rfid_debug_log(
                    "pre_request_regs_after_wait_for_tag",
                    {
                        "version_now_0x37": ver_now,
                        "TxControlReg_0x14": tx_ctrl_0x14,
                        "TxControlReg_lsbs": (tx_ctrl_0x14 & 0x03) if tx_ctrl_0x14 is not None else None,
                        "RFCfgReg_0x13": rf_cfg_0x13,
                    },
                    "H-ANT",
                )
                # #endregion
            (err_req, tag_bits) = rdr.request(RFID_REQUEST_REQ_MODE)
            if err_req:
                poll_fail_n += 1
                # #region agent log
                if RFID_USE_IRQ or poll_fail_n == 1 or poll_fail_n % 50 == 0:
                    # 失敗當下再讀一次 VersionReg，判斷是「真的沒 Tag」還是「SPI 瞬斷」
                    ver_now = None
                    try:
                        ver_now = int(rdr.dev_read(MFRC522_REG_VERSION)) & 0xFF
                    except Exception:
                        ver_now = None
                    # 失敗當下的「原因指標」暫存器：request 超時/錯誤/沒收到回應時常會有差異
                    error_reg_0x06 = None
                    com_irq_reg_0x04 = None
                    fifo_level_reg_0x0A = None
                    status_1_reg_0x07 = None
                    status_2_reg_0x08 = None
                    tx_ask_reg_0x15 = None
                    mod_width_reg_0x24 = None
                    try:
                        error_reg_0x06 = int(rdr.dev_read(0x06)) & 0xFF
                    except Exception:
                        pass
                    try:
                        com_irq_reg_0x04 = int(rdr.dev_read(0x04)) & 0xFF
                    except Exception:
                        pass
                    try:
                        fifo_level_reg_0x0A = int(rdr.dev_read(0x0A)) & 0xFF
                    except Exception:
                        pass
                    try:
                        status_1_reg_0x07 = int(rdr.dev_read(0x07)) & 0xFF
                    except Exception:
                        pass
                    try:
                        status_2_reg_0x08 = int(rdr.dev_read(0x08)) & 0xFF
                    except Exception:
                        pass
                    try:
                        tx_ask_reg_0x15 = int(rdr.dev_read(0x15)) & 0xFF
                    except Exception:
                        pass
                    try:
                        mod_width_reg_0x24 = int(rdr.dev_read(0x24)) & 0xFF
                    except Exception:
                        pass
                    _rfid_debug_log(
                        "after_request",
                        {
                            "err": True,
                            "tag_type": tag_bits,
                            "poll_fail_n": poll_fail_n,
                            "request_req_mode": RFID_REQUEST_REQ_MODE,
                            "version_now_0x37": ver_now,
                            "ErrorReg_0x06": error_reg_0x06,
                            "ComIrqReg_0x04": com_irq_reg_0x04,
                            "FIFOLevelReg_0x0A": fifo_level_reg_0x0A,
                            "Status1Reg_0x07": status_1_reg_0x07,
                            "Status2Reg_0x08": status_2_reg_0x08,
                            "TxASKReg_0x15": tx_ask_reg_0x15,
                            "ModWidthReg_0x24": mod_width_reg_0x24,
                        },
                        "H1",
                    )
                # #endregion
                if not RFID_USE_IRQ:
                    time.sleep(RFID_POLL_INTERVAL_SEC)
                continue
            poll_fail_n = 0
            # #region agent log
            _rfid_debug_log(
                "after_request",
                {"err": False, "tag_type": tag_bits},
                "H1",
            )
            # #endregion
            (err_ac, uid) = rdr.anticoll()
            # #region agent log
            uid_safe = None
            if uid and isinstance(uid, (list, tuple)):
                uid_safe = {"len": len(uid), "head_hex": "".join(f"{int(x) & 0xFF:02X}" for x in uid[:4])}
            _rfid_debug_log(
                "after_anticoll",
                {"err": err_ac, "uid": uid_safe},
                "H2",
            )
            # #endregion
            if err_ac or not uid:
                if not RFID_USE_IRQ:
                    time.sleep(RFID_POLL_INTERVAL_SEC)
                continue
            rfid_hex = uid_bytes_to_hex(uid)
            now = time.time()
            if rfid_hex == last_uid_hex and (now - last_ok_time) < DEBOUNCE_SEC:
                continue
            print(f"[RFID] 讀取 UID: {rfid_hex}")

            status, body = post_swipe(BRANCH_CODE, rfid_hex, CAMPUS_TOKEN)
            print(f"[API] HTTP {status} {json.dumps(body, ensure_ascii=False)[:800]}")

            if status == 401:
                print("[API] 401：請檢查 CAMPUS_TOKEN 是否與該分校 Campus.Token 一致")
            elif body.get("error") == "html_response":
                print("[API] 收到 HTML 404：請檢查 SWIPE_RFID_URL 主機是否已把 /api 導向 Laravel public。")
            elif body.get("error") == "invalid_json":
                print("[API] 回應非 JSON：可能仍為 HTML 或其它錯誤頁，請檢查網址與伺服器設定。")
            elif status in (200, 201) and body.get("ok"):
                last_uid_hex = rfid_hex
                last_ok_time = now
                notify_student_telegram(body, rfid_hex)
            # 未知卡仍可能回 404 + campus.TelegramToken；可依需求在此也發管理員通知

            try:
                rdr.stop_crypto()
            except Exception:
                pass

    except KeyboardInterrupt:
        print("\n結束")
    finally:
        rdr.cleanup()


if __name__ == "__main__":
    main()
