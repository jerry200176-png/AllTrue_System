"""
main.py — 入口點

使用方式：
    python main.py                    # 互動模式（輸入需求）
    python main.py --demo             # 執行內建範例需求
    python main.py --req "你的需求"   # 直接傳入需求字串
"""
from __future__ import annotations

import argparse
import json
import os
import sys
from pathlib import Path

from dotenv import load_dotenv
from rich.console import Console
from rich.prompt import Prompt

# 載入環境變數（.env 優先）
load_dotenv()

console = Console()

# ─── 內建示範需求 ────────────────────────────────────────────────
DEMO_REQUIREMENTS = [
    {
        "name": "SaaS 訂閱管理平台",
        "requirement": (
            "我需要建立一個 B2B SaaS 訂閱管理平台，供中小企業管理軟體授權。"
            "功能包含：多層級定價方案、信用卡自動扣款、用量計費、"
            "Invoice 自動產生、管理後台（查看訂閱者、帳單、退款）。"
            "預計 3 個月內上線，技術棧偏好 Python FastAPI + PostgreSQL + React。"
            "需要嚴格符合 PCI DSS 合規（處理信用卡資料）。"
        ),
    },
    {
        "name": "即時協作白板應用",
        "requirement": (
            "開發一個類似 Figma 的即時協作白板工具（MVP 版本）。"
            "核心功能：多人同時編輯（WebSocket）、"
            "基本繪圖工具（矩形/圓形/箭頭/文字）、圖層管理、"
            "歷史紀錄（Undo/Redo）、分享連結。"
            "目標 2 個月完成，技術棧：Vue 3 + Node.js + Redis。"
        ),
    },
    {
        "name": "電商資料分析管道",
        "requirement": (
            "為電商平台建立資料分析管道，整合多個資料來源（MySQL 交易資料、"
            "GA4 行為數據、廣告平台 API）。"
            "需要每日自動 ETL、KPI 儀表板（GMV、轉換率、CAC/LTV）、"
            "異常告警（銷售額驟降、退款率升高）。"
            "技術棧偏好 Python + dbt + BigQuery + Looker Studio。"
        ),
    },
]


def _check_env() -> bool:
    """確認必要環境變數存在"""
    missing = []
    if not os.getenv("OPENAI_API_KEY"):
        missing.append("OPENAI_API_KEY")
    if not os.getenv("SERPER_API_KEY"):
        missing.append("SERPER_API_KEY")

    if missing:
        for key in missing:
            console.print(
                f"[bold red]❌ 缺少 {key}。請複製 .env.example 為 .env 並填入 API Key。[/bold red]"
            )
        return False
    return True


def _save_report(report: "FinalReport", output_dir: Path = Path("outputs")) -> Path:
    """將最終報告儲存為 JSON"""
    output_dir.mkdir(exist_ok=True)
    filename = output_dir / f"{report.project_name.replace(' ', '_')}_report.json"
    filename.write_text(
        json.dumps(report.model_dump(), ensure_ascii=False, indent=2),
        encoding="utf-8",
    )
    console.print(f"\n[dim]📄 完整報告已儲存至：{filename}[/dim]")
    return filename


def run_interactive() -> None:
    """互動模式：讓使用者輸入需求"""
    console.print("\n[bold cyan]💬 請輸入你的業務需求（輸入後按 Enter）：[/bold cyan]")
    console.print("[dim]（可以是任何規模的軟體需求，支援中文）[/dim]\n")
    requirement = Prompt.ask("[bold]需求")

    if not requirement.strip():
        console.print("[red]需求不能為空。[/red]")
        sys.exit(1)

    _execute(requirement)


def run_demo(index: int = 0) -> None:
    """執行內建示範需求"""
    demo = DEMO_REQUIREMENTS[index % len(DEMO_REQUIREMENTS)]
    console.print(
        f"\n[bold cyan]🎬 示範模式：{demo['name']}[/bold cyan]\n"
        f"[dim]{demo['requirement']}[/dim]\n"
    )
    _execute(demo["requirement"])


def _execute(requirement: str) -> None:
    # 延遲 import（避免在 env check 前載入 CrewAI）
    from director import Director

    director = Director(
        model=os.getenv("OPENAI_MODEL_NAME", "gpt-4o"),
        verbose=True,
    )

    try:
        report = director.kickoff(requirement)
        _save_report(report)
    except KeyboardInterrupt:
        console.print("\n[yellow]使用者中斷執行。[/yellow]")
        sys.exit(0)
    except Exception as e:
        console.print(f"\n[bold red]執行錯誤：{e}[/bold red]")
        raise


def main() -> None:
    parser = argparse.ArgumentParser(
        description="企業級 CrewAI 開發總監",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=(
            "範例：\n"
            "  python main.py --demo\n"
            "  python main.py --demo --demo-index 1\n"
            '  python main.py --req "我需要一個線上問卷系統，支援多種題型..."'
        ),
    )
    parser.add_argument(
        "--demo",
        action="store_true",
        help="執行內建示範需求",
    )
    parser.add_argument(
        "--demo-index",
        type=int,
        default=0,
        help="示範需求編號（0–2，預設 0）",
    )
    parser.add_argument(
        "--req",
        type=str,
        default=None,
        help="直接傳入需求字串",
    )
    parser.add_argument(
        "--list-demos",
        action="store_true",
        help="列出所有示範需求",
    )

    args = parser.parse_args()

    if args.list_demos:
        for i, d in enumerate(DEMO_REQUIREMENTS):
            console.print(f"[cyan]{i}[/cyan]: {d['name']}")
        sys.exit(0)

    if not _check_env():
        sys.exit(1)

    if args.req:
        _execute(args.req)
    elif args.demo:
        run_demo(args.demo_index)
    else:
        run_interactive()


if __name__ == "__main__":
    main()
