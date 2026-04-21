"""
task_builder.py — 動態任務產生器

根據 ProjectSpec 與已實例化的 agents，
為每個執行階段產生對應的 Task 物件。
"""
from __future__ import annotations

from crewai import Agent, Task

from models import ProjectSpec


# ─── 每個工作階段的任務模板 ────────────────────────────────────────
# key = 階段名稱（與 ProjectSpec.execution_phases 對齊）
# 每個模板是一個 callable，接受 (spec, agent, context_tasks) 回傳 Task

def _architecture_task(spec: ProjectSpec, agent: Agent, context: list[Task]) -> Task:
    return Task(
        description=(
            f"為專案「{spec.project_name}」設計完整的系統架構。\n\n"
            f"專案類型：{spec.project_type}，複雜度：{spec.complexity}\n"
            f"技術棧提示：{', '.join(spec.tech_stack_hints) or '無特定限制'}\n\n"
            "請輸出：\n"
            "1. 系統元件圖說明（用文字描述各模組與關係）\n"
            "2. 資料庫 schema 草稿（主要資料表與欄位）\n"
            "3. API 端點契約摘要（方法、路徑、Request/Response 結構）\n"
            "4. 技術棧最終選型與選擇理由\n"
            "5. 非功能需求建議（效能目標、SLA、擴展策略）\n\n"
            f"需求清單：\n" + "\n".join(f"- {r}" for r in spec.requirements_breakdown)
        ),
        expected_output=(
            "結構化的架構設計文件，含元件圖說明、DB schema、API 契約摘要、"
            "技術棧選型說明，格式清晰，供後續 agent 直接使用。"
        ),
        agent=agent,
        context=context,
    )


def _backend_task(spec: ProjectSpec, agent: Agent, context: list[Task]) -> Task:
    return Task(
        description=(
            f"根據架構師的設計，為「{spec.project_name}」實作後端核心邏輯。\n\n"
            "請輸出：\n"
            "1. 核心業務邏輯的虛擬碼（Pseudocode）或程式骨架\n"
            "2. 資料層設計（Repository pattern / ORM 設計）\n"
            "3. 錯誤處理策略（HTTP 狀態碼、錯誤回應格式）\n"
            "4. 關鍵效能考量（快取策略、N+1 查詢防範、索引建議）\n"
            "5. 單元測試重點清單（哪些路徑最需要測試）\n\n"
            f"子需求：\n" + "\n".join(f"- {r}" for r in spec.requirements_breakdown)
        ),
        expected_output=(
            "後端實作規格文件，含核心邏輯骨架、資料層設計、"
            "錯誤處理策略、效能建議，可直接交給工程師執行。"
        ),
        agent=agent,
        context=context,
    )


def _frontend_task(spec: ProjectSpec, agent: Agent, context: list[Task]) -> Task:
    return Task(
        description=(
            f"根據架構師的 API 契約，為「{spec.project_name}」設計前端實作規格。\n\n"
            "請輸出：\n"
            "1. 頁面/元件樹結構（路由規劃與元件分層）\n"
            "2. 狀態管理策略（全域狀態 vs 本地狀態的邊界）\n"
            "3. API 串接層設計（封裝方式、錯誤處理、Loading 狀態）\n"
            "4. UI/UX 精緻化規格（空狀態、Loading、Toast、無障礙要求）\n"
            "5. 效能優化策略（Lazy loading、Code splitting、圖片優化）\n\n"
            "確保 WCAG 2.1 AA 合規與 Core Web Vitals 達標。"
        ),
        expected_output=(
            "前端實作規格，含元件樹、狀態管理策略、API 串接層設計、"
            "UI/UX 規格與效能優化策略。"
        ),
        agent=agent,
        context=context,
    )


def _security_task(spec: ProjectSpec, agent: Agent, context: list[Task]) -> Task:
    return Task(
        description=(
            f"對「{spec.project_name}」執行完整的安全審計。\n\n"
            "請依序完成：\n"
            "1. STRIDE 威脅模型分析（六維度快評）\n"
            "2. OWASP Top 10 逐項檢查（適用本專案的項目）\n"
            "3. 存取控制設計審查（最小權限原則、RBAC/ABAC 建議）\n"
            "4. 敏感資料處理建議（加密、脫敏、PII 保護）\n"
            "5. 依賴套件風險評估（已知 CVE 類別提醒）\n\n"
            f"安全關鍵等級：{'高（含敏感資料或高權限操作）' if spec.security_critical else '中'}"
        ),
        expected_output=(
            "安全審計報告，含 STRIDE 快評、OWASP 檢查結果、"
            "存取控制建議與高優先級修正清單（HIGH / MEDIUM / LOW）。"
        ),
        agent=agent,
        context=context,
    )


def _qa_task(spec: ProjectSpec, agent: Agent, context: list[Task]) -> Task:
    return Task(
        description=(
            f"為「{spec.project_name}」設計完整的測試策略。\n\n"
            "請輸出：\n"
            "1. 測試金字塔規劃（單元/整合/E2E 比例建議）\n"
            "2. 關鍵測試案例清單（Happy Path / Edge Case / Error Case）\n"
            "3. 每條 FR 的驗收標準（Gherkin 格式 Given/When/Then）\n"
            "4. 效能測試基準（API 回應時間閾值、並發用戶數目標）\n"
            "5. 測試環境與資料準備策略（測試資料工廠、Mock 策略）\n\n"
            f"需求清單：\n" + "\n".join(f"- {r}" for r in spec.requirements_breakdown)
        ),
        expected_output=(
            "完整測試策略文件，含測試金字塔規劃、關鍵測試案例（Gherkin 格式）、"
            "效能基準與測試環境策略。"
        ),
        agent=agent,
        context=context,
    )


def _docs_task(spec: ProjectSpec, agent: Agent, context: list[Task]) -> Task:
    return Task(
        description=(
            f"為「{spec.project_name}」撰寫完整的技術文件。\n\n"
            "請輸出：\n"
            "1. README.md 骨架（專案簡介、快速開始、環境需求、貢獻指南）\n"
            "2. API 文件大綱（OpenAPI 3.0 格式的端點說明）\n"
            "3. 架構決策紀錄（ADR）：記錄本次重大架構選擇\n"
            "4. 部署指南摘要（環境變數清單、部署步驟）\n"
            "5. 開發者體驗（DX）優化建議（本地開發設定、常見問題）\n\n"
            "採用 Diátaxis 框架（教程/How-to/參考/解釋）組織文件結構。"
        ),
        expected_output=(
            "技術文件套件，含 README 骨架、API 文件大綱（OpenAPI 格式）、"
            "ADR、部署指南與 DX 建議。"
        ),
        agent=agent,
        context=context,
    )


def _devops_task(spec: ProjectSpec, agent: Agent, context: list[Task]) -> Task:
    return Task(
        description=(
            f"為「{spec.project_name}」設計 DevOps 策略。\n\n"
            "請輸出：\n"
            "1. CI/CD Pipeline 設計（GitHub Actions workflow 結構）\n"
            "2. 容器化策略（Dockerfile 最佳實踐、multi-stage build）\n"
            "3. 環境管理（dev / staging / prod 差異化配置）\n"
            "4. 可觀測性棧設計（logging / metrics / tracing 三支柱）\n"
            "5. 告警規則建議（SLI/SLO 定義、告警閾值）\n"
            "6. 回滾策略（Blue/Green 或 Canary Release 建議）"
        ),
        expected_output=(
            "DevOps 設計文件，含 CI/CD pipeline 結構、容器化策略、"
            "可觀測性設計與告警規則。"
        ),
        agent=agent,
        context=context,
    )


def _data_task(spec: ProjectSpec, agent: Agent, context: list[Task]) -> Task:
    return Task(
        description=(
            f"為「{spec.project_name}」設計資料工程架構。\n\n"
            "請輸出：\n"
            "1. 資料流向圖說明（Source → Ingestion → Transform → Serving）\n"
            "2. 資料倉儲 schema 設計（事實表 / 維度表）\n"
            "3. ETL/ELT 管道設計（工具選型、排程策略）\n"
            "4. 資料品質規則（NULL 檢查、一致性規則、SLA 定義）\n"
            "5. 資料血緣追蹤策略"
        ),
        expected_output=(
            "資料工程架構文件，含資料流向、倉儲 schema、"
            "管道設計與資料品質規則。"
        ),
        agent=agent,
        context=context,
    )


# ─── 階段 → 任務建立函式 對照表 ─────────────────────────────────
_TASK_BUILDERS = {
    "architecture": _architecture_task,
    "backend": _backend_task,
    "frontend": _frontend_task,
    "security": _security_task,
    "qa": _qa_task,
    "docs": _docs_task,
    "devops": _devops_task,
    "data": _data_task,
}

# 階段名稱 → 最適合的 agent 角色
_PHASE_TO_ROLE: dict[str, str] = {
    "architecture": "system_architect",
    "backend": "backend_developer",
    "frontend": "frontend_developer",
    "security": "security_auditor",
    "qa": "qa_engineer",
    "docs": "technical_writer",
    "devops": "devops_engineer",
    "data": "data_engineer",
}


class TaskBuilder:
    """
    根據 ProjectSpec 動態組裝 Task 清單。

    每個 Task 都持有前一個 Task 的 context，
    實現資訊在 agent 之間的流動。
    """

    @staticmethod
    def build(
        spec: ProjectSpec,
        agents: dict[str, "Agent"],
    ) -> list[Task]:
        """
        agents: {role_key: Agent instance}
        回傳依 spec.execution_phases 排序的 Task 清單
        """
        tasks: list[Task] = []

        for phase in spec.execution_phases:
            role_key = _PHASE_TO_ROLE.get(phase)
            builder_fn = _TASK_BUILDERS.get(phase)

            if role_key is None or builder_fn is None:
                continue  # 未知階段，跳過
            if role_key not in agents:
                continue  # 此次未選入此角色，跳過

            agent = agents[role_key]
            # 每個 task 能讀到之前所有 task 的輸出
            context = list(tasks)
            task = builder_fn(spec, agent, context)
            tasks.append(task)

        return tasks

    @staticmethod
    def phase_to_role(phase: str) -> str | None:
        return _PHASE_TO_ROLE.get(phase)
